<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\KostenFolgeFehlgeschlagenException;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;

/**
 * Code-Qualität 2.1b · Eltern-Neuberechnung nach dem Speichern eines Rezepts (ARCHITEKTUR §8): Das gespeicherte Rezept rechnet der Request
 * synchron ({@see RecipeRecomputeService::recomputeNachSpeichern}); alle Rezepte, die es (transitiv) verwenden, rechnet dieser Job — topologisch,
 * jedes genau einmal ({@see RecipeRecomputeService::recomputeMany}). Ein Fond in 100 Gerichten hält das Speichern damit nicht mehr auf.
 *
 * Eindeutig je Rezept und entprellt: mehrere Speichervorgänge kurz hintereinander landen in EINEM Lauf (er liest den Stand bei Ausführung).
 * ShouldBeUniqueUntilProcessing: die Sperre fällt mit Laufbeginn — ein Speichern WÄHREND des Laufs stellt einen neuen Lauf ein.
 * Scheitert ein Eltern-Rezept, wirft der Job am Ende (Wiederholung über die Queue, danach failed_jobs) — wie der KostenFolgeJob (1.1b).
 */
class ElternNeuberechnenJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, \Platform\FoodAlchemist\Support\MitKiVerursacher;   // reine Berechnung, läuft im Team des Rezepts

    /** Sekunden, die der Job wartet, damit weitere Speichervorgänge im selben Lauf landen. */
    public const ENTPRELLEN = 5;

    public int $timeout = 1800;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct(public int $teamId, public int $recipeId) {}

    public function uniqueId(): string
    {
        return (string) $this->recipeId;
    }

    /**
     * Nach dem Commit einreihen — nur, wenn das Rezept überhaupt Eltern hat. Mit echter Queue verzögert um {@see ENTPRELLEN}; mit dem Sync-Treiber
     * (Sandbox/Tests) direkt im Team des Rezepts wie beim KostenFolgeJob (die Sync-Queue vergäße mitten im Request Team/Nutzer).
     */
    public static function versenden(int $recipeId): void
    {
        $hatEltern = DB::table('foodalchemist_recipe_ingredients')->where('referenced_recipe_id', $recipeId)->whereNull('deleted_at')->exists();
        $teamId = $hatEltern ? (int) DB::table('foodalchemist_recipes')->where('id', $recipeId)->value('team_id') : 0;
        if (! $hatEltern || $teamId === 0) {
            return;
        }
        $job = new self($teamId, $recipeId);
        if (app('queue') instanceof \Illuminate\Support\Testing\Fakes\QueueFake || config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
            dispatch($job)->delay(now()->addSeconds(self::ENTPRELLEN))->afterCommit();

            return;
        }
        DB::afterCommit(fn () => \Platform\FoodAlchemist\Support\FaTeam::imTeam($teamId, function () use ($job) {
            try {
                app()->call([$job, 'handle']);
            } catch (KostenFolgeFehlgeschlagenException $e) {
                report($e);
            }
        }));
    }

    public function handle(RecipeRecomputeService $recompute): void
    {
        $eltern = array_values(array_diff($recompute->betroffeneRezepte($this->recipeId), [$this->recipeId]));
        if ($eltern === []) {
            return;
        }
        $fehler = [];
        $recompute->recomputeMany($eltern, $fehler);
        if ($fehler !== []) {
            throw new KostenFolgeFehlgeschlagenException($this->teamId, $fehler);
        }
    }
}
