<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\ElternNeuberechnenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Preis\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.1b · Abnahme „Speichern am Fond antwortet ohne Eltern-Pipelines“: das gespeicherte Rezept rechnet synchron, seine Eltern ein
 * eindeutiger, entprellter Queue-Job; mehrere Speichervorgänge = ein Job.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Kalbsknochen', 'qty' => 1.0, 'unit_code' => 'kg']);
    app(PriceService::class)->createFor($this->rootTeam, $la, 10.0);
    $this->gp = $this->makeGp($this->rootTeam, 'Kalbsknochen');
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
    $this->gp->update(['lead_la_supplier_item_id' => $la->id]);

    $this->fond = $this->makeRecipe($this->rootTeam, 'Fond: Kalb', ['yield_kg_manual' => 1.0]);   // feste Ausbeute: €/kg folgt der Menge
    $this->makeIngredient($this->fond, 'Kalbsknochen', $this->gp->refresh(), '1000');
    $this->gerichte = [];
    foreach (['Kalbsrücken', 'Kalbsbäckchen'] as $name) {
        $g = $this->makeRecipe($this->rootTeam, $name, ['is_sales_recipe' => true]);
        FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $g->id, 'referenced_recipe_id' => $this->fond->id,
            'raw_text' => 'Fond', 'quantity' => '500', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1, 'match_method' => 'recipe_ref']);
        $this->gerichte[] = $g;
    }
    app(RecipeRecomputeService::class)->recomputeAndPropagate($this->fond->id);
    $this->ekVorher = fn () => array_map(fn ($g) => (float) FoodAlchemistRecipe::find($g->id)->ek_total_eur, $this->gerichte);
    $this->zeile = fn (string $menge) => [['raw_text' => 'Kalbsknochen', 'quantity' => $menge, 'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
        'gp_id' => $this->gp->id, 'auto_ground' => false]];
});

it('Speichern am Fond: Fond sofort neu, Gerichte unverändert, genau ein Eltern-Job', function () {
    $vorher = ($this->ekVorher)();
    Queue::fake();
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->fond->id, ($this->zeile)('2000'));
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->fond->id, ($this->zeile)('3000'));   // entprellt: kein zweiter Job

    expect((float) FoodAlchemistRecipe::find($this->fond->id)->ek_total_eur)->toBe(30.0)
        ->and(($this->ekVorher)())->toBe($vorher);
    Queue::assertPushed(ElternNeuberechnenJob::class, 1);
    Queue::assertPushed(ElternNeuberechnenJob::class, fn ($job) => $job->recipeId === $this->fond->id && $job->teamId === $this->rootTeam->id);

    // Der Job holt die Eltern nach (Stand bei Ausführung)
    app()->call([new ElternNeuberechnenJob($this->rootTeam->id, $this->fond->id), 'handle']);
    expect(($this->ekVorher)())->toBe([15.0, 15.0]);
});

it('Rezept ohne Eltern: kein Job; Sync-Treiber rechnet die Eltern weiter im Request', function () {
    Queue::fake();
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->gerichte[0]->id, [['raw_text' => 'Fond', 'quantity' => '500',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'referenced_recipe_id' => $this->fond->id, 'auto_ground' => false]]);
    Queue::assertNotPushed(ElternNeuberechnenJob::class);
});

it('ohne Fake (Sync-Treiber): Eltern im selben Request nachgezogen', function () {
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->fond->id, ($this->zeile)('2000'));
    expect(($this->ekVorher)())->toBe([10.0, 10.0]);
});
