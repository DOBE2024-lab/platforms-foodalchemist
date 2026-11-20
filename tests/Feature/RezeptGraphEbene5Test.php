<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\Rezept\RezeptGraphService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.1c · Mess-Test 7 (Bericht 97): ein Allergen auf Ebene 5 kommt oben an. A-5 hat das 3-Ebenen-Limit aufgehoben; seit 2.1c
 * folgen auch die lesenden Dienste (Graph-Dienst mit Besucht-Liste statt Tiefen-Deckel) — Eltern-Suche, Komponenten, Anreicherung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Molkerei']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Butter', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $la->id, 'allergen_milk' => 'enthalten']);
    $butter = $this->makeGp($this->rootTeam, 'Butter');
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $butter->id]);

    // Ebene 1 (Gericht) → 2 → 3 → 4 → 5 (Butter)
    $this->kette = [];
    for ($i = 1; $i <= 5; $i++) {
        $this->kette[$i] = $this->makeRecipe($this->rootTeam, "Ebene {$i}", $i === 1 ? ['is_sales_recipe' => true] : []);
    }
    $this->makeIngredient($this->kette[5], 'Butter', $butter->refresh(), '100');
    for ($i = 1; $i < 5; $i++) {
        FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->kette[$i]->id, 'referenced_recipe_id' => $this->kette[$i + 1]->id,
            'raw_text' => "Ebene " . ($i + 1), 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1, 'match_method' => 'recipe_ref']);
    }
});

it('Mess-Test 7: Allergen auf Ebene 5 kommt im Gericht an', function () {
    app(RecipeRecomputeService::class)->recomputeAndPropagate($this->kette[5]->id);

    foreach ($this->kette as $r) {
        expect(FoodAlchemistRecipe::find($r->id)->allergen_milk)->toBe('enthalten');
    }
});

it('Graph-Dienst: Vorfahren und Nachfahren über alle Ebenen, Komponenten und Anreicherung inklusive Ebene 5', function () {
    $ids = array_map(fn ($r) => $r->id, $this->kette);
    $graph = app(RezeptGraphService::class);

    expect($graph->vorfahren([$ids[5]]))->toBe([$ids[5], $ids[4], $ids[3], $ids[2], $ids[1]])
        ->and($graph->nachfahren([$ids[1]]))->toBe([$ids[2], $ids[3], $ids[4], $ids[5]])
        ->and(app(RecipeOneShotService::class)->subRezeptIds($ids[1]))->toContain($ids[5]);
});

it('Graph-Dienst: eingeschleuster Zyklus terminiert (Besucht-Liste)', function () {
    DB::table('foodalchemist_recipe_ingredients')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $this->kette[5]->id,
        'referenced_recipe_id' => $this->kette[2]->id, 'raw_text' => 'Kreis', 'quantity' => 1, 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 2, 'match_method' => 'recipe_ref', 'created_at' => now(), 'updated_at' => now()]);
    $graph = app(RezeptGraphService::class);

    expect($graph->nachfahren([$this->kette[1]->id]))->toHaveCount(4)
        ->and($graph->vorfahren([$this->kette[5]->id]))->toHaveCount(5)
        ->and($graph->pruefeVerknuepfung($this->kette[5]->id, $this->kette[1]->id))->toBe(['erlaubt' => false, 'grund' => 'Zyklus']);
});
