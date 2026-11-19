<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * Kosten-Folge nach einer Preis-, Lead-, Pin- oder Lieferanten-Freigabe-Änderung (Befund Achenbachglas/Cocos-Vanille-Bliss 18.11.2026, Dominique:
 * „das müsste ja durch alle Kaskaden sein“). Gezielt, nie Vollrecompute, über die Queue (Sync-Driver in Sandbox/Tests ⇒ inline):
 *
 *  1. Team-Kosten der Rezepte mit diesen GPs (inkl. Eltern) veraltet (lazy, {@see KostenCacheService::markiereVeraltet}) — wenn angefordert;
 *  2. gespeicherte Rezept-Aggregate: Rezepte mit diesen GPs als Zutat, topologisch samt Eltern ({@see RecipeRecomputeService::recomputeMany}) —
 *     darin je Rezept die Darreichungen (ek_portion, Auto-VK) und danach Pakete, Konzepte, offene Auto-Angebote ({@see \Platform\FoodAlchemist\Services\PricingCascadeService});
 *  3. Darreichungen, die einen dieser GPs als Gefäß oder Zubehör führen und in Schritt 2 nicht schon dran waren, samt Paket/Konzept/Angebot darüber.
 *
 * GPs kommen direkt oder über Lieferanten (alle Artikel dieser Lieferanten, Freigabe im Stamm-Paket). `$nurTeamKette`: nur Rezepte/Darreichungen des
 * Auftragsteams und seiner Unter-Teams (Teampreis, Pin, Freigabe) — sonst alle (Listenpreis, globaler Lead). Versand-Snapshots bleiben unberührt.
 *
 * Code-Qualität 1.1b (19.11.2026): Scheitert ein Rezept in `recomputeMany` (je Rezept abgefangen, I8), endet der Job NICHT mehr still „erfolgreich“,
 * sondern wirft am Ende {@see \Platform\FoodAlchemist\Exceptions\KostenFolgeFehlgeschlagenException} → die Queue wiederholt ($tries/$backoff), danach
 * failed_jobs. Gleiche Aufträge (Team + GP-/Lieferanten-Satz + Schalter) liegen höchstens einmal in der Warteschlange (ShouldBeUniqueUntilProcessing:
 * die Sperre fällt mit Laufbeginn — eine Preisänderung WÄHREND eines Laufs stellt einen neuen Lauf ein, statt verloren zu gehen).
 */
class KostenFolgeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, \Platform\FoodAlchemist\Support\MitKiVerursacher;   // reine Berechnung, läuft im Auftragsteam

    public int $timeout = 1800;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    /**
     * @param  list<int>  $gpIds
     * @param  list<int>  $supplierIds
     */
    public function __construct(
        public int $teamId,
        public array $gpIds = [],
        public array $supplierIds = [],
        public bool $nurTeamKette = true,
        public bool $mitRezepten = true,
        public bool $kostenVeraltet = false,
    ) {}

    /** Eindeutig je Team, GP-/Lieferanten-Satz (Reihenfolge egal) und Schaltern. */
    public function uniqueId(): string
    {
        $gp = $this->gpIds;
        $lief = $this->supplierIds;
        sort($gp);
        sort($lief);

        return $this->teamId . ':' . md5(json_encode([$gp, $lief, $this->nurTeamKette, $this->mitRezepten, $this->kostenVeraltet]));
    }

    /**
     * Versand nach dem Commit. Mit echter Queue (io: database) als Job; mit dem Sync-Treiber (Sandbox/Tests) direkt im Auftragsteam statt über
     * die Sync-Queue — die hydratisiert den Log-Kontext neu und vergisst scoped Instanzen, mitten im Request ginge danach Team/Nutzer verloren.
     *
     * @param  list<int>  $gpIds
     * @param  list<int>  $supplierIds
     */
    public static function versenden(int $teamId, array $gpIds = [], array $supplierIds = [], bool $nurTeamKette = true, bool $mitRezepten = true, bool $kostenVeraltet = false): void
    {
        $job = new self($teamId, array_values(array_unique(array_map('intval', $gpIds))), array_values(array_unique(array_map('intval', $supplierIds))),
            $nurTeamKette, $mitRezepten, $kostenVeraltet);
        if (app('queue') instanceof \Illuminate\Support\Testing\Fakes\QueueFake || config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
            dispatch($job)->afterCommit();

            return;
        }
        // Ohne Queue gibt es keine Wiederholung: ein Fehlschlag (1.1b) wird gemeldet, bricht aber den schon committeten Request nicht ab.
        DB::afterCommit(fn () => \Platform\FoodAlchemist\Support\FaTeam::imTeam($teamId, function () use ($job) {
            try {
                app()->call([$job, 'handle']);
            } catch (\Platform\FoodAlchemist\Exceptions\KostenFolgeFehlgeschlagenException $e) {
                report($e);
            }
        }));
    }

    public function handle(RecipeRecomputeService $recompute, KostenCacheService $kosten, DarreichungService $darreichungen): void
    {
        $gpIds = self::gpIds($this->gpIds, $this->supplierIds);
        if ($gpIds === []) {
            return;
        }
        $teamIds = $this->nurTeamKette ? RecomputeTeamRecipesJob::teamUndNachfahren($this->teamId) : null;
        if ($this->kostenVeraltet) {
            $kosten->markiereVeraltet($kosten->rezepteMitGps($gpIds), $teamIds);
        }
        $erledigt = [];
        if ($this->mitRezepten) {
            $start = [];
            foreach (array_chunk($gpIds, 500) as $teil) {
                $q = DB::table('foodalchemist_recipe_ingredients as ri')->join('foodalchemist_recipes as r', 'r.id', '=', 'ri.recipe_id')
                    ->whereIn('ri.gp_id', $teil)->whereNull('ri.deleted_at')->whereNull('r.deleted_at');
                if ($teamIds !== null) {
                    $q->whereIn('r.team_id', $teamIds);
                }
                $start = array_merge($start, $q->distinct()->pluck('ri.recipe_id')->map(fn ($v) => (int) $v)->all());
            }
            $start = array_values(array_unique($start));
            $fehler = [];
            $erledigt = $start !== [] ? $recompute->recomputeMany($start, $fehler) : [];
        }
        $darreichungen->recomputeFuerZubehoerGps($gpIds, $teamIds, $erledigt);
        if (($fehler ?? []) !== []) {                              // 1.1b: erst nach dem Rest (Zubehör), dann fehlschlagen → Wiederholung
            throw new \Platform\FoodAlchemist\Exceptions\KostenFolgeFehlgeschlagenException($this->teamId, $fehler);
        }
    }

    /**
     * GPs direkt plus die GPs aller Artikel der Lieferanten (Struktur-Verknüpfung).
     *
     * @param  list<int>  $gpIds
     * @param  list<int>  $supplierIds
     * @return list<int>
     */
    public static function gpIds(array $gpIds, array $supplierIds): array
    {
        $alle = array_map('intval', $gpIds);
        foreach (array_chunk(array_values(array_unique(array_map('intval', $supplierIds))), 200) as $teil) {
            $alle = array_merge($alle, DB::table('foodalchemist_supplier_item_structures as s')->join('foodalchemist_supplier_items as i', 'i.id', '=', 's.supplier_item_id')
                ->whereIn('i.supplier_id', $teil)->whereNull('s.deleted_at')->whereNull('i.deleted_at')->whereNotNull('s.gp_id')
                ->distinct()->pluck('s.gp_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique(array_filter($alle)));
    }
}
