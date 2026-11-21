<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Concepter\Editor as ConcepterEditor;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Livewire\Produktion\Editor as ProduktionEditor;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3d — Paritäts-Tests je Kernablauf: dieselbe Eingabe über die Oberfläche (Livewire) und über das MCP-Tool
 * ergibt dasselbe Ergebnis. Verglichen wird ein Abbild ohne IDs, Zeiten und Herkunft (`created_via`, Name/Schlüssel,
 * wo beide Wege einen eigenen Datensatz brauchen). Abläufe: Rezept anlegen, Gericht kalkulieren, Concept für eine
 * Gästezahl kalkulieren, Foodbook duplizieren, Produktion (Ziel + freie Position).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Paritaet');
    $this->actingAs($this->user);
    $this->tool = function (string $name, array $args) {
        $r = app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($this->user, $this->rootTeam));
        expect($r->success)->toBeTrue("{$name}: " . ($r->errorCode ?? 'fehlgeschlagen'));

        return $r;
    };
});

/** Vergleichbares Abbild eines Rezepts (ohne ID, Name, Schlüssel und Herkunft). */
function rezeptAbbild(FoodAlchemistRecipe $r): array
{
    $r = $r->fresh();

    return [
        'status' => $r->status->value, 'sales' => (bool) $r->is_sales_recipe, 'description' => $r->description,
        'preparation' => $r->preparation, 'work_time_min' => (int) $r->work_time_min,
        'temperature' => $r->temperature, 'function' => $r->function, 'notes_manual' => $r->notes_manual,
        'yield_pieces' => $r->yield_pieces === null ? null : (float) $r->yield_pieces,
        'geraete' => $r->equipment()->orderBy('slug')->pluck('slug')->all(),
        'schritte' => FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->pluck('text')->all(),
    ];
}

it('Rezept anlegen: Modal und recipes.POST ergeben dasselbe Rezept samt Fachfeldern und Schritten', function () {
    $kombi = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);
    $eingabe = [
        'description' => 'Kräftiger Rinderfond.', 'preparation' => "1. Knochen rösten\n2. Ansetzen und reduzieren", 'work_time_min' => 45,
        'temperature' => 'warm', 'function' => 'Saucenbasis', 'notes_manual' => 'Am Vortag ansetzen', 'yield_pieces' => 24,
    ];

    $lw = Livewire::test(RecipeModal::class)->call('oeffnen')->set('form.name', 'Fond: Paritaet Oberflaeche');
    foreach ($eingabe as $feld => $wert) {
        $lw->set("form.{$feld}", $wert);
    }
    $lw->set('form.equipment_ids', [(string) $kombi->id])->call('speichern')->assertSet('fehler', null);
    $ui = FoodAlchemistRecipe::where('name', 'Fond: Paritaet Oberflaeche')->firstOrFail();

    $res = ($this->tool)('foodalchemist.recipes.POST', ['name' => 'Fond: Paritaet Tool', 'equipment_ids' => [$kombi->id]] + $eingabe);
    $mcp = FoodAlchemistRecipe::findOrFail($res->data['recipe']['id']);

    expect(rezeptAbbild($mcp))->toBe(rezeptAbbild($ui))
        ->and(rezeptAbbild($ui)['schritte'])->toHaveCount(2)
        ->and(rezeptAbbild($ui)['geraete'])->toBe(['kombi']);
});

it('Rezept bearbeiten: Modal und recipes.PUT ändern die Fachfelder eines Entwurfs gleich', function () {
    $kombi = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);
    $herd = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'herd', 'name' => 'Herd']);
    $anlegen = fn (string $name) => FoodAlchemistRecipe::findOrFail(($this->tool)('foodalchemist.recipes.POST', [
        'name' => $name, 'temperature' => 'warm', 'equipment_ids' => [$kombi->id],
    ])->data['recipe']['id']);
    $ui = $anlegen('Fond: Bearbeiten Oberflaeche');
    $mcp = $anlegen('Fond: Bearbeiten Tool');
    $aenderung = ['temperature' => 'kalt', 'function' => 'Bindung', 'notes_manual' => 'Neu', 'yield_pieces' => 12];

    $lw = Livewire::test(RecipeModal::class)->call('oeffnen', $ui->id);
    foreach ($aenderung as $feld => $wert) {
        $lw->set("form.{$feld}", $wert);
    }
    $lw->set('form.equipment_ids', [(string) $herd->id])->call('speichern')->assertSet('fehler', null);

    ($this->tool)('foodalchemist.recipes.PUT', ['recipe_id' => $mcp->id, 'equipment_ids' => [$herd->id]] + $aenderung);

    expect(rezeptAbbild($mcp))->toBe(rezeptAbbild($ui))
        ->and(rezeptAbbild($ui)['temperature'])->toBe('kalt')
        ->and(rezeptAbbild($ui)['geraete'])->toBe(['herd']);
});

it('recipes.POST/PUT: unbekannte Geräte und negative Stückzahl lehnt das Tool ab, ohne zu schreiben', function () {
    $vorher = FoodAlchemistRecipe::count();
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.POST')
        ->execute(['name' => 'Fond: Fremdgeraet', 'equipment_ids' => [999999]], new ToolContext($this->user, $this->rootTeam));
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and(FoodAlchemistRecipe::count())->toBe($vorher);

    $id = ($this->tool)('foodalchemist.recipes.POST', ['name' => 'Fond: Stueck'])->data['recipe']['id'];
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.PUT')
        ->execute(['recipe_id' => $id, 'yield_pieces' => -3], new ToolContext($this->user, $this->rootTeam));
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and(FoodAlchemistRecipe::find($id)->yield_pieces)->toBeNull();
});

it('Gericht kalkulieren: Darreichung im VK-Modal und recipe_darreichung.PUT ergeben denselben Preis', function () {
    $ak = FoodAlchemistMarkupClass::create([
        'code' => 'PAR', 'label' => 'Paritaet', 'class_factor_pct' => 100,
        'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $gericht = fn (string $name) => $this->makeRecipe($this->rootTeam, $name, [
        'is_sales_recipe' => true, 'yield_kg' => 2.0, 'ek_per_kg_eur' => 12.0, 'ek_total_eur' => 24.0,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 3,
    ]);
    $dar = app(DarreichungService::class);
    $dUi = $dar->ensureStandard($this->rootTeam, $gericht('[HG] Rinderrücken | Oberfläche')->id, 'test');
    $dMcp = $dar->ensureStandard($this->rootTeam, $gericht('[HG] Rinderrücken | Tool')->id, 'test');
    $abbild = function (FoodAlchemistRecipeDarreichung $d) {
        $d = $d->fresh();

        return [
            'price_mode' => $d->price_mode, 'markup_class_id' => (int) $d->markup_class_id,
            'quantity_per_unit_g' => (float) $d->quantity_per_unit_g, 'ek_portion' => round((float) $d->ek_portion, 4),
            'calculated_sales_net' => round((float) $d->calculated_sales_net, 2), 'sales_net' => round((float) $d->sales_net, 2),
            'sales_gross' => round((float) $d->sales_gross, 2),
        ];
    };

    // Die Standard-Darreichung hat keine eigene Grammatur-Eingabe: die Portionsmenge kommt aus der Rezeptur
    // („Standardmenge verwenden"). Beide Wege setzen deshalb nur Aufschlagsklasse und Preismodus.
    Livewire::test(VkModal::class)
        ->call('oeffnen', $dUi->recipe_id)
        ->set("darForm.{$dUi->id}.markup_class_id", $ak->id)
        ->set("darForm.{$dUi->id}.price_mode", 'auto')
        ->call('darreichungSpeichern', $dUi->id)
        ->assertSet('fehler', null);

    ($this->tool)('foodalchemist.recipe_darreichung.PUT', [
        'presentation_id' => $dMcp->id, 'attrs' => ['markup_class_id' => $ak->id, 'price_mode' => 'auto'],
    ]);

    expect($abbild($dMcp))->toBe($abbild($dUi))
        ->and($abbild($dUi)['sales_net'])->toBeGreaterThan(0.0);
});

it('Concept kalkulieren: Kalkulations-Tab und concepts.KALKULATION rechnen für dieselbe Gästezahl dasselbe', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Sommerbuffet');
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Lachs | Paritaet', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($gericht, 12.0);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $gericht->id]);

    $ui = Livewire::test(ConcepterEditor::class)
        ->call('oeffnen', 'concepts', $concept->id, 'kalkulation')
        ->set('simulationPax', 40)
        ->viewData('auftragsSimulation');

    $mcp = ($this->tool)('foodalchemist.concepts.KALKULATION', ['concept_id' => $concept->id, 'pax' => 40])->data['kalkulation'];

    expect($ui)->not->toBeNull()
        ->and($mcp)->toEqual($ui);
});

/*
 * Benannte Abweichung (Kurator-Entscheid 2026-11-21, 98 · 2.3d): foodbook_kapitel.POST legt Kapitel nur in Foodbooks im
 * Entwurf an (sonst ACCESS_DENIED), die Oberfläche (Foodbooks\Index::kapitelNeu) hat diese Sperre nicht. Gewollt: ein
 * Mensch darf ein freigegebenes Foodbook bewusst ändern, ein Agent nicht ungefragt. Darum vergleicht die Foodbook-Parität
 * das Duplizieren, nicht das Kapitel-Anlegen.
 */
it('Foodbook duplizieren: Index-Aktion und foodbooks.DUPLICATE ergeben dieselbe Kopie', function () {
    $quelle = $this->makeFoodbook($this->rootTeam, 'Sommerkatalog', ['personen' => 60, 'description' => 'Fürs Sommerfest']);
    $vor = $this->makeChapter($quelle, ['title' => 'Vorspeisen', 'position' => 1]);
    $haupt = $this->makeChapter($quelle, ['title' => 'Hauptgänge', 'position' => 2]);
    $this->makeChapter($quelle, ['title' => 'Fisch', 'position' => 1, 'parent_id' => $haupt->id]);
    $this->makeFoodbookBlock($vor, ['position' => 1]);
    $this->makeFoodbookBlock($vor, ['type' => 'header', 'position' => 2]);
    $abbild = function (FoodAlchemistFoodbook $fb) {
        $kapitel = FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('position')->orderBy('title')->get();
        $titel = $kapitel->pluck('title', 'id');

        return [
            'label' => $fb->label, 'status' => $fb->statusWert()->value, 'team_id' => (int) $fb->team_id,
            'personen' => $fb->personen, 'description' => $fb->description,
            'kapitel' => $kapitel->map(fn ($k) => [
                'title' => $k->title, 'position' => (int) $k->position, 'status' => (string) ($k->status?->value ?? $k->status),
                'parent' => $k->parent_id ? $titel[$k->parent_id] : null,
                'bloecke' => FoodAlchemistFoodbookBlock::where('chapter_id', $k->id)->orderBy('position')->pluck('type')->all(),
            ])->all(),
        ];
    };

    $lw = Livewire::test(FoodbooksIndex::class)->call('waehle', $quelle->id)->call('duplizieren');
    $ui = FoodAlchemistFoodbook::findOrFail($lw->get('selectedId'));

    $res = ($this->tool)('foodalchemist.foodbooks.DUPLICATE', ['id' => $quelle->id]);
    $mcp = FoodAlchemistFoodbook::findOrFail($res->data['foodbook']['id']);

    expect($ui->id)->not->toBe($quelle->id)->and($mcp->id)->not->toBe($ui->id)
        ->and($abbild($mcp))->toBe($abbild($ui))
        ->and($abbild($ui)['kapitel'])->toHaveCount(3);
});

it('Produktion: Editor und production_orders.ADD_TARGET/LINE_ADD ergeben dieselben Positionen', function () {
    $fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond-paritaet', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);
    $abbild = fn (int $orderId) => FoodAlchemistProductionOrderLine::where('production_order_id', $orderId)
        ->orderBy('origin')->orderBy('position')->get()
        ->map(fn ($l) => [
            'origin' => $l->origin, 'titel' => $l->titel, 'recipe_id' => $l->recipe_id, 'ansaetze' => (float) $l->ansaetze,
            'benoetigt_ansaetze' => (float) $l->benoetigt_ansaetze, 'arbeitszeit_min' => (int) $l->arbeitszeit_min,
            'position' => (int) $l->position, 'plan_date' => (string) $l->plan_date,
        ])->all();

    $lw = Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('name', 'Paritaet Oberfläche')
        ->set('productionDate', '2026-08-20')
        ->call('zielEinfuegen', 'basisrezept', $fond->id, 6, 'kg')
        ->call('speichern')
        ->assertSet('fehler', null);
    $uiId = (int) $lw->get('orderId');
    $lw->set('freiTitel', 'Brot beim Bäcker abholen')->set('freiZeit', '25')
        ->call('freiePositionAnlegen')
        ->assertSet('fehler', null);

    $add = ($this->tool)('foodalchemist.production_orders.ADD_TARGET', [
        'production_date' => '2026-08-20', 'name' => 'Paritaet Tool', 'recipe_id' => $fond->id, 'amount_kg' => 6, 'source_ref' => 'recipe:' . $fond->id,
    ]);
    $mcpId = (int) $add->data['order_id'];
    ($this->tool)('foodalchemist.production_orders.LINE_ADD', ['order_id' => $mcpId, 'titel' => 'Brot beim Bäcker abholen', 'arbeitszeit_min' => 25]);

    $ziele = fn (int $id) => collect(FoodAlchemistProductionOrder::findOrFail($id)->targets)
        ->map(fn ($t) => array_diff_key((array) $t, array_flip(['label'])))->all();

    expect($mcpId)->not->toBe($uiId)
        ->and($ziele($mcpId))->toEqual($ziele($uiId))
        ->and($abbild($mcpId))->toBe($abbild($uiId))
        ->and(collect($abbild($uiId))->pluck('origin')->sort()->values()->all())->toBe(['computed', 'manual']);
});
