<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Rezept\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (KI-Strang b) PR0 — Charakterisierung der drei Nach-Prüfungen von RecipeGeneratorService::generiere() vor dem
 * Zerlegen: Kohärenz-Gate (Regel süß/herzhaft, Kritiker ohne Befund), Diät-/Allergen-Gate (vegan, glutenfrei per Override,
 * Sesam-No-Go, unbewertet bleibt), Dedup-Gate (Kollision je Modus). Festgehalten werden `statistik` (Zähler und die drei
 * Gate-Blöcke) und `offene` (in Reihenfolge, mit Shortlist), dazu die Verdrahtung der Zeilen nach dem Lauf.
 *
 * Ist-Stand, keine Fachaussage. Neu schreiben nur mit Begründung: RECIPE_GENERATOR_GATE_GOLDEN_SCHREIBEN=1.
 */
const RECIPE_GENERATOR_GATE_GOLDEN = __DIR__.'/../Fixtures/golden/recipe_generator_gate_charakter.json';

function recipeGeneratorGateGolden(string $schluessel, mixed $ist): void
{
    $ist = json_decode(json_encode($ist, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
    $alle = is_file(RECIPE_GENERATOR_GATE_GOLDEN) ? json_decode((string) file_get_contents(RECIPE_GENERATOR_GATE_GOLDEN), true) : [];
    if (getenv('RECIPE_GENERATOR_GATE_GOLDEN_SCHREIBEN')) {
        $alle[$schluessel] = $ist;
        ksort($alle);
        file_put_contents(RECIPE_GENERATOR_GATE_GOLDEN, json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        test()->markTestSkipped('Golden neu geschrieben: '.$schluessel);
    }
    expect($alle)->toHaveKey($schluessel);
    expect($ist)->toBe($alle[$schluessel]);
}

/** Gate-relevanter Teil eines generiere()-Ergebnisses, ohne Zeit- und Kontext-Rauschen. */
function recipeGeneratorGateAusschnitt(array $res): array
{
    $s = $res['statistik'];
    $zeilen = $res['recipe']->ingredients()->orderBy('position')->get()
        ->map(fn ($z) => [$z->position, $z->raw_text, $z->gp_id !== null, $z->referenced_recipe_id !== null])->all();

    return [
        'zaehler' => array_intersect_key($s, array_flip(['bestand_gp', 'bestand_sub', 'offen'])),
        'kritiker' => $s['kritiker'] ?? null,
        'diaet' => $s['diaet'] ?? null,
        'dedup' => isset($s['dedup']) ? array_diff_key($s['dedup'], ['existing_id' => 1]) : null,
        'offene' => array_map(fn ($o) => array_diff_key($o, ['shortlist' => 1]) + ['shortlist_n' => count($o['shortlist'] ?? [])], $res['offene']),
        'zeilen' => $zeilen,
        'name' => $res['recipe']->name,
    ];
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    CopilotStub::bind([]);                                       // Kritiker ohne Befund: deterministisch
    $this->svc = app(RecipeGeneratorService::class);

    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkGp = function (string $name, string $slug, array $attr = []) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['main_ingredient_slug' => $slug, 'status' => 'approved', ...$attr]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 5.0, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
});

it('alle drei Gates in einem Basis-Lauf: Regel-Entdrahtung, Diät/Allergen, Dedup-Kollision', function () {
    ($this->mkGp)('Tomaten: frisch, geachtelt', 'tomaten', ['tag_is_vegan' => true]);
    ($this->mkGp)('Butter', 'butter', ['tag_is_vegan' => false]);
    ($this->mkGp)('Sesampaste', 'sesampaste', ['allergen_sesame' => 'enthalten', 'tag_is_vegan' => true]);
    ($this->mkGp)('Weizenmehl', 'weizenmehl', ['allergen_gluten' => 'enthalten']);   // vegan unbewertet → bleibt
    $this->makeRecipe($this->rootTeam, 'Rahmeis: Balsamico', ['taste_direction' => 'suess']);
    $this->makeRecipe($this->rootTeam, 'Suppe: Tomate');                              // Dublette für das Dedup-Gate

    $res = $this->svc->generiere($this->rootTeam, 'Tomatensuppe', [
        'convenience' => 'from_scratch', 'bestand' => 'komplett_neu',
        'diaet_hart' => ['vegan', 'low_carb'], 'allergen_nogo' => ['sesame'],
    ], kiRezeptOverride: [
        'name' => 'Suppe: Tomate',
        'taste_direction' => 'herzhaft',
        'zutaten' => [
            ['text' => 'Tomaten: frisch, geachtelt', 'slug' => 'tomaten', 'quantity' => 500, 'unit' => 'g'],
            ['text' => 'Butter', 'slug' => 'butter', 'quantity' => 30, 'unit' => 'g'],
            ['text' => 'Sesampaste', 'slug' => 'sesampaste', 'quantity' => 20, 'unit' => 'g'],
            ['text' => 'Weizenmehl', 'slug' => 'weizenmehl', 'quantity' => 10, 'unit' => 'g'],
            ['text' => 'Rahmeis: Balsamico', 'quantity' => 100, 'unit' => 'ml'],
        ],
    ]);

    recipeGeneratorGateGolden('basis_alle_gates', recipeGeneratorGateAusschnitt($res));
});

it('Gates übersprungen: ohne Diät-Vorgabe, ohne Sub-Rezept, ohne Dublette; hybrid empfiehlt Bestand', function () {
    ($this->mkGp)('Butter', 'butter', ['tag_is_vegan' => false]);
    $frei = $this->svc->generiere($this->rootTeam, 'Butterbrot', ['bestand' => 'hybrid'], kiRezeptOverride: [
        'name' => 'Basis: Butterprobe',
        'zutaten' => [['text' => 'Butter', 'slug' => 'butter', 'quantity' => 50, 'unit' => 'g']],
    ]);
    $zweit = $this->svc->generiere($this->rootTeam, 'Butterbrot', ['bestand' => 'hybrid', 'diaet_hart' => ['glutenfrei']], kiRezeptOverride: [
        'name' => 'Basis: Butterprobe',
        'zutaten' => [['text' => 'Butter', 'slug' => 'butter', 'quantity' => 50, 'unit' => 'g']],
    ]);

    recipeGeneratorGateGolden('uebersprungen_und_hybrid', [
        'frei' => recipeGeneratorGateAusschnitt($frei),
        'zweit' => recipeGeneratorGateAusschnitt($zweit),
    ]);
});

it('VK-Modus: kein Kohärenz-Gate, Diät-Gate läuft', function () {
    ($this->mkGp)('Butter', 'butter', ['tag_is_vegan' => false]);
    $res = $this->svc->generiere($this->rootTeam, 'Gericht mit Butter', ['diaet_hart' => ['vegan']], kiRezeptOverride: [
        'name' => '[HG] Butterteller',
        'zutaten' => [['text' => 'Butter', 'slug' => 'butter', 'quantity' => 50, 'unit' => 'g']],
    ], vkModus: true);

    recipeGeneratorGateGolden('vk_modus', recipeGeneratorGateAusschnitt($res));
});
