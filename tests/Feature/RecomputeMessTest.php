<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Preis\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Vor dem Umbau (origin/main 0f51e162) gemessen. */
const MESS_ERWARTET = [
    'gericht' => [14.16, 12.31, 'mixed', 12, 'medium'],
    'allergene' => ['gluten' => 'enthalten', 'crustaceans' => 'unbekannt', 'eggs' => 'enthalten', 'fish' => 'unbekannt', 'peanuts' => 'unbekannt',
        'soy' => 'unbekannt', 'milk' => 'enthalten', 'tree_nuts' => 'unbekannt', 'celery' => 'enthalten', 'mustard' => 'enthalten', 'sesame' => 'unbekannt',
        'sulphites' => 'unbekannt', 'lupin' => 'unbekannt', 'molluscs' => 'unbekannt'],
    'subs' => [3.0, 3.85],
];

/**
 * Code-Qualität 2.1a · Mess-Test 1 (Bericht 97): Abfragen je Gericht mit 12 Zeilen. Vorher 82 gemessen (5–6 je GP-Zeile, laProfile doppelt,
 * LA-Cache je Rezept), nach dem Umbau 18. Abnahme: < 20. Dazu Ergebnisgleichheit: EK, Basis und Allergene sind die vor dem Umbau gemessenen Werte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel']);
    $allergene = ['gluten', 'milk', 'eggs', 'celery', 'mustard'];
    $this->gps = [];
    for ($i = 1; $i <= 10; $i++) {
        $gp = $this->makeGp($this->rootTeam, "Zutat {$i}");
        $las = [];
        foreach (['A' => 2.0 * $i, 'B' => 3.0 * $i] as $suffix => $preis) {
            $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id,
                'designation' => "Zutat {$i} {$suffix}", 'qty' => 1.0, 'unit_code' => 'kg']);
            app(PriceService::class)->createFor($this->rootTeam, $la, $preis);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
            FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $la->id,
                'allergen_' . $allergene[$i % 5] => $suffix === 'A' ? 'enthalten' : 'spuren']);
            $las[] = $la;
        }
        $gp->update(['lead_la_supplier_item_id' => $i % 3 === 0 ? null : $las[0]->id]);   // jede dritte Zeile ohne Lead → Durchschnitt
        $this->gps[] = $gp->refresh();
    }
    // zwei Unterrezepte (Fond, Sauce) aus je zwei GPs
    $this->fond = $this->makeRecipe($this->rootTeam, 'Fond: Kalb');
    $this->makeIngredient($this->fond, 'Zutat 1', $this->gps[0], '500', 1);
    $this->makeIngredient($this->fond, 'Zutat 2', $this->gps[1], '500', 2);
    $this->sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Jus');
    $this->makeIngredient($this->sauce, 'Zutat 3', $this->gps[2], '300', 1);
    $this->makeIngredient($this->sauce, 'Zutat 4', $this->gps[3], '200', 2);

    $this->gericht = $this->makeRecipe($this->rootTeam, 'Kalbsrücken', ['is_sales_recipe' => true]);
    foreach ($this->gps as $n => $gp) {
        $this->makeIngredient($this->gericht, $gp->name, $gp, (string) (50 + 10 * $n), $n + 1);
    }
    foreach ([[$this->fond, 11], [$this->sauce, 12]] as [$sub, $pos]) {
        FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'referenced_recipe_id' => $sub->id,
            'raw_text' => $sub->name, 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $pos, 'match_method' => 'recipe_ref']);
    }
    $this->rc = app(RecipeRecomputeService::class);
    $this->rc->recomputePipeline($this->fond->id, false);
    $this->rc->recomputePipeline($this->sauce->id, false);
});

it('Mess-Test 1: Gericht mit 12 Zeilen braucht weniger als 20 Abfragen (Kern-Pipeline ohne Preis-Kaskade)', function () {
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id, false);

    expect($n)->toBeLessThan(20);
});

it('Ergebnisgleichheit: EK, Basis, Zähler und Allergene wie vor dem Umbau', function () {
    $this->rc->recomputePipeline($this->gericht->id, false);
    $g = FoodAlchemistRecipe::find($this->gericht->id);
    $allergene = collect(\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS)->mapWithKeys(fn ($f) => [$f => $g->{"allergen_{$f}"}])->all();

    expect([(float) $g->ek_total_eur, (float) $g->ek_per_kg_eur, $g->ek_price_basis?->value, (int) $g->ek_n_ingredients_priced, $g->allergens_confidence])
        ->toBe(MESS_ERWARTET['gericht'])
        ->and($allergene)->toBe(MESS_ERWARTET['allergene'])
        ->and([(float) FoodAlchemistRecipe::find($this->fond->id)->ek_total_eur, (float) FoodAlchemistRecipe::find($this->sauce->id)->ek_total_eur])
        ->toBe(MESS_ERWARTET['subs']);
});
