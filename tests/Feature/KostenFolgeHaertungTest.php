<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Exceptions\KostenFolgeFehlgeschlagenException;
use Platform\FoodAlchemist\Jobs\KostenFolgeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\KostenCacheService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1b · Kosten-Folge härten: ein Rezept-Fehler in recomputeMany lässt den Job fehlschlagen (Queue wiederholt) statt still
 * „erfolgreich“ zu enden; gleiche Aufträge liegen nur einmal in der Warteschlange.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->gp = $this->makeGp($this->rootTeam, 'Vanillemilch');
    $this->a = $this->makeRecipe($this->rootTeam, 'A');
    $this->makeIngredient($this->a, 'Vanillemilch', $this->gp, '100');
    $this->b = $this->makeRecipe($this->rootTeam, 'B');
    // Fehlerquelle: per DB eingeschleuster Zyklus A⇄B (recomputeMany meldet ihn je Rezept als Fehler, 1.1e)
    foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$von, $nach]) {
        FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $von->id, 'referenced_recipe_id' => $nach->id,
            'raw_text' => $nach->name, 'quantity' => '50', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 9]);
    }
    $this->handle = fn (KostenFolgeJob $job) => $job->handle(app(RecipeRecomputeService::class), app(KostenCacheService::class), app(DarreichungService::class));
});

it('Fehler in einem Rezept → der Job schlägt fehl (mit Rezept-IDs), statt still erfolgreich zu enden', function () {
    $job = new KostenFolgeJob((int) $this->rootTeam->id, [(int) $this->gp->id]);
    try {
        ($this->handle)($job);
        $this->fail('KostenFolgeJob hätte fehlschlagen müssen.');
    } catch (KostenFolgeFehlgeschlagenException $e) {
        expect(array_keys($e->fehler))->toEqualCanonicalizing([$this->a->id, $this->b->id])
            ->and($e->getMessage())->toContain('Zyklus');
    }
    expect($job->tries)->toBeGreaterThan(1)->and($job->backoff)->not->toBeEmpty();
});

it('ohne Fehler endet der Job normal', function () {
    FoodAlchemistRecipeIngredient::where('recipe_id', $this->b->id)->delete();                 // Kreis aufgelöst
    ($this->handle)(new KostenFolgeJob((int) $this->rootTeam->id, [(int) $this->gp->id]));
    expect(true)->toBeTrue();
});

it('Sync-Treiber (Sandbox): ein Fehlschlag wird gemeldet, bricht den schon committeten Request aber nicht ab', function () {
    Exceptions::fake();
    DB::transaction(fn () => KostenFolgeJob::versenden((int) $this->rootTeam->id, [(int) $this->gp->id]));
    Exceptions::assertReported(KostenFolgeFehlgeschlagenException::class);
});

it('Eindeutigkeit: zwei gleiche Aufträge (GP-Reihenfolge egal) → ein Job; anderer Satz oder Schalter → eigener Job', function () {
    Queue::fake();
    $gp2 = $this->makeGp($this->rootTeam, 'Kokosmilch');
    $t = (int) $this->rootTeam->id;
    KostenFolgeJob::versenden($t, [(int) $this->gp->id, (int) $gp2->id]);
    KostenFolgeJob::versenden($t, [(int) $gp2->id, (int) $this->gp->id]);
    Queue::assertPushed(KostenFolgeJob::class, 1);

    KostenFolgeJob::versenden($t, [(int) $this->gp->id]);                                       // anderer GP-Satz
    KostenFolgeJob::versenden($t, [(int) $this->gp->id, (int) $gp2->id], [], true, false);    // anderer Schalter
    KostenFolgeJob::versenden((int) $this->childA->id, [(int) $this->gp->id, (int) $gp2->id]);  // anderes Team
    Queue::assertPushed(KostenFolgeJob::class, 4);
});
