<?php

use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\Core\Models\Team;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · Grammatur „immer automatisch“ (Entscheid Dominique 22.11., Option 2: Varianten über Deltas je Komponente), Schritt 1:
 * Charakterisierung VOR jeder Änderung. Hält je Darreichung fest: Grammatur, EK je Portion, Auto-VK, Produktionsanteil und
 * Planungsblatt-Bedarf — für alle Arten, die die Umstellung berührt:
 *  A  Ansatz mit mehreren Portionen (Bratkartoffeln 1,2 kg, Ertrag = Komponentensumme): Standard frei 300 g, Kinder frei 150 g,
 *     „ohne Speck“ (Delta weggelassen), „Doppel“ frei 150 g × 2 Einheiten, „wie Standard“ (menge_vom_standard)
 *  B  Gericht als Komponentenliste (Roulade, Ansatz = 1 Portion): Standard menge_vom_standard (Σ Komponenten), Variante mit
 *     Gramm-Override-Delta
 *  C  fester Ertrag ≠ Komponentensumme (Schmorbraten, yield_kg_manual 0,8 kg bei 1 kg netto): Variante frei 200 g —
 *     der Fall, den die verlustfreie Umstellung NICHT schafft (EK über Ertrag vs. über Bestandteile)
 *  D  unbepreiste Komponente (Eintopf): Variante frei
 * Golden: tests/Fixtures/golden/grammatur_charakter.json. Nach der Umstellung müssen A, B und D unverändert bleiben;
 * C landet auf der Liste „nicht verlustfrei“ (Entscheid je Fall durch Dominique).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $t = $this->rootTeam->id;
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->ak = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'class_factor_pct' => 100,
        'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Großhandel']);
    // GP mit 1-kg-LA und Preis je kg; ohne Preis → unbepreist
    $this->gp = function (string $name, ?float $eurJeKg) use ($t, $lieferant) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lieferant->id, 'designation' => $name.' 1kg',
            'article_number' => 'A-'.$gp->id, 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($eurJeKg !== null) {
            FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => $eurJeKg, 'status' => '0']);
        }
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
    $this->rezept = function (string $key, string $name, array $zutaten, array $attrs = []) use ($t) {
        $r = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved', 'is_sales_recipe' => true] + $attrs);
        $zeilen = [];
        foreach (array_values($zutaten) as $i => [$gp, $gramm]) {
            $zeilen[$gp->name] = $r->ingredients()->create(['team_id' => $t, 'position' => $i, 'gp_id' => $gp->id, 'raw_text' => $gp->name,
                'quantity' => $gramm, 'unit_vocab_id' => $this->g->id, 'match_method' => 'manual']);
        }
        app(RecipeRecomputeService::class)->recomputePipeline($r->id);

        return [$r->refresh(), $zeilen];
    };
    $this->form = fn (string $code) => FoodAlchemistServierform::firstOrCreate(['team_id' => $t, 'code' => $code], ['label' => ucfirst($code)]);
    $this->darreichung = fn (FoodAlchemistRecipe $r, string $code, array $a) => FoodAlchemistRecipeDarreichung::create(['team_id' => $t, 'recipe_id' => $r->id,
        'serving_form_id' => ($this->form)($code)->id] + $a + ['unit_count' => 1, 'price_mode' => 'auto', 'markup_class_id' => $this->ak->id]);

    // A · Bratkartoffeln: Ansatz 1,2 kg = Komponentensumme, 4 Teller à 300 g
    $kartoffel = ($this->gp)('Kartoffeln', 1.20);
    $speck = ($this->gp)('Speck', 9.00);
    [$this->a, $za] = ($this->rezept)('bratkartoffeln', 'Bratkartoffeln', [[$kartoffel, 1000], [$speck, 200]]);
    $this->a->update(['yield_kg_manual' => 1.2]);
    ($this->darreichung)($this->a, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 300]);
    ($this->darreichung)($this->a, 'kinder', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 150]);
    $ohneSpeck = ($this->darreichung)($this->a, 'ohne-speck', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 250]);
    FoodAlchemistRecipeDarreichungDelta::create(['team_id' => $t, 'presentation_id' => $ohneSpeck->id, 'recipe_ingredient_id' => $za['Speck']->id, 'omitted' => true]);
    ($this->darreichung)($this->a, 'doppel', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 2]);
    ($this->darreichung)($this->a, 'wie-standard', ['is_standard' => false, 'menge_vom_standard' => true]);

    // B · Roulade: Ansatz = eine Portion, Standard aus den Komponenten
    $rind = ($this->gp)('Rinderoberschale', 22.00);
    $jus = ($this->gp)('Jus', 6.00);
    [$this->b, $zb] = ($this->rezept)('roulade', '[HG] Rinderroulade', [[$rind, 180], [$jus, 80]]);
    ($this->darreichung)($this->b, 'teller', ['is_standard' => true, 'menge_vom_standard' => true]);
    $klein = ($this->darreichung)($this->b, 'klein', ['is_standard' => false, 'menge_vom_standard' => false]);
    FoodAlchemistRecipeDarreichungDelta::create(['team_id' => $t, 'presentation_id' => $klein->id, 'recipe_ingredient_id' => $zb['Rinderoberschale']->id, 'quantity_override_g' => 120]);

    // C · Schmorbraten: fester Ertrag 0,8 kg bei 1 kg netto Komponenten
    $schulter = ($this->gp)('Rinderschulter', 14.00);
    [$this->c] = ($this->rezept)('schmorbraten', 'Schmorbraten', [[$schulter, 1000]]);
    $this->c->update(['yield_kg_manual' => 0.8]);
    ($this->darreichung)($this->c, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 200]);
    ($this->darreichung)($this->c, 'kinder', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 100]);

    // D · Eintopf mit unbepreister Komponente
    $linsen = ($this->gp)('Linsen', 3.00);
    $kraut = ($this->gp)('Kräuter', null);
    [$this->d] = ($this->rezept)('eintopf', 'Linseneintopf', [[$linsen, 900], [$kraut, 100]]);
    ($this->darreichung)($this->d, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 250]);
    ($this->darreichung)($this->d, 'kinder', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 125]);

    foreach ([$this->a, $this->b, $this->c, $this->d] as $r) {
        app(RecipeRecomputeService::class)->recomputePipeline($r->id);
        app(DarreichungService::class)->recomputeFuerRezept($r->id);
    }
});

/** Normalisierter Ist-Zustand eines Rezepts samt aller Darreichungen (Werte, die die Umstellung nicht ändern darf). */
function grammaturZustand(FoodAlchemistRecipe $r, Team $team): array
{
    $r = $r->fresh();
    $dar = app(DarreichungService::class);
    $zahl = fn ($v, int $d = 4) => $v === null ? null : round((float) $v, $d);
    $zeilen = app(RecipeRecomputeService::class)->zeilenKostenUndMassen($r, $team);
    $namen = $r->ingredients()->pluck('raw_text', 'id');
    $blatt = app(PlanungsblattService::class);

    return [
        'rezept' => ['yield_kg' => $zahl($r->yield_kg), 'yield_kg_manual' => $zahl($r->yield_kg_manual), 'ek_per_kg' => $zahl($r->ek_per_kg_eur),
            'sales_unit_count' => $r->sales_unit_count, 'sales_quantity_per_unit_g' => $zahl($r->sales_quantity_per_unit_g),
            'komponenten_netto_g' => $zahl(array_sum(array_column($zeilen, 'masse_g')), 1)],
        'darreichungen' => $r->presentations()->with('servingForm')->orderBy('id')->get()->map(function ($d) use ($r, $team, $dar, $zahl, $namen, $blatt) {
            $anteil = $dar->produktionsAnteil($r, $d, 10, $team);
            $b = $blatt->produktionsblattFuerZiele($team, [['recipe_id' => $r->id, 'portions' => 40, 'presentation_id' => $d->id]]);

            return [
                'form' => $d->servingForm?->code, 'standard' => (bool) $d->is_standard, 'menge_vom_standard' => (bool) $d->menge_vom_standard,
                'unit_count' => $zahl($d->unit_count), 'deltas' => $d->deltas()->count(),
                'gramm' => $zahl($dar->grammJeEinheit($d, $r), 1), 'quantity_per_unit_g' => $zahl($d->quantity_per_unit_g, 1),
                'ek_portion' => $zahl($d->ek_portion), 'calculated_sales_net' => $zahl($d->calculated_sales_net, 2), 'sales_net' => $zahl($d->sales_net, 2),
                'produktion_10' => ['ansaetze' => $zahl($anteil['ansaetze']), 'gramm_je_portion' => $zahl($anteil['gramm_je_portion'], 1),
                    'je_zutat' => $anteil['je_zutat'] === null ? null : collect($anteil['je_zutat'])->mapWithKeys(fn ($v, $id) => [$namen[$id] ?? $id => $zahl($v)])->sortKeys()->all()],
                'planungsblatt_40_kg' => collect($b['gp_bedarf'])->mapWithKeys(fn ($g) => [$g['name'] => $zahl($g['menge_kg'], 3)])->sortKeys()->all(),
            ];
        })->all(),
    ];
}

function grammaturGolden(string $schluessel, array $ist): void
{
    $g = \Platform\FoodAlchemist\Tests\Support\Golden::vergleich('grammatur_charakter', $schluessel, $ist);
    expect($g['ist'])->toBe($g['soll'], "Golden {$schluessel}");
}

it('A · Ansatz mit mehreren Portionen: Standard frei, Kinder frei, ohne Speck (Delta), Doppel (×2), wie Standard', function () {
    $z = grammaturZustand($this->a, $this->rootTeam);
    $kinder = collect($z['darreichungen'])->firstWhere('form', 'kinder');
    expect(collect($z['darreichungen'])->firstWhere('form', 'doppel')['unit_count'])->toBe(2.0)
        ->and($kinder['gramm'])->toBe(150.0)
        ->and($kinder['planungsblatt_40_kg'])->toBe(['Kartoffeln' => 5.0, 'Speck' => 1.0]);   // wie ProduktionDarreichungTest
    grammaturGolden('a_bratkartoffeln', $z);
});

it('B · Gericht als Komponentenliste: Standard = Σ Komponenten, Variante mit Gramm-Override', function () {
    $z = grammaturZustand($this->b, $this->rootTeam);
    expect(collect($z['darreichungen'])->firstWhere('form', 'teller')['gramm'])->toBe(260.0)
        ->and(collect($z['darreichungen'])->firstWhere('form', 'klein')['gramm'])->toBe(200.0);
    grammaturGolden('b_roulade', $z);
});

it('C · fester Ertrag ≠ Komponentensumme: Variante frei (verlustfreie Umstellung nicht möglich)', function () {
    $z = grammaturZustand($this->c, $this->rootTeam);
    expect($z['rezept']['komponenten_netto_g'])->not->toBe(800.0);   // genau der Unterschied, der die Umstellung verhindert
    grammaturGolden('c_schmorbraten', $z);
});

it('D · unbepreiste Komponente: Variante frei', function () {
    grammaturGolden('d_eintopf', grammaturZustand($this->d, $this->rootTeam));
});
