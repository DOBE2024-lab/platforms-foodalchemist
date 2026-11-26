<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Support\FaTeam;

/**
 * Hängende Kaskaden-Läufe finden und heilen, unabhängig davon, ob gerade jemand das Board offen hat (io 26.11., Master-Rundgang:
 * Läufe 38, 61, 62, 66 und 69 blieben trotz #993 auf `running`).
 *
 * Warum #993 allein nicht reichte: Der Poll ({@see \Platform\FoodAlchemist\Livewire\Planung\LaufStatus::heilen}) heilt nur die Läufe,
 * die das Board gerade beobachtet. Das Board beobachtet aber nur den JÜNGSTEN Lauf je sichtbarer Planungs-Session
 * ({@see \Platform\FoodAlchemist\Support\Planung\KaskadenAnsicht::landingKaskadenMap}) und davon nur die des eigenen Teams. Ein älterer
 * Lauf einer Session mit späterem Neustart, ein Lauf ohne Session (z. B. aus MCP) und ein Lauf eines anderen Teams erreichen den
 * Reaper also nie. Läuft kein jüngster Lauf mehr, gibt es nicht einmal einen Poll.
 *
 * Dieser Sweep sucht die Kandidaten direkt in der Tabelle: Lauf `running` oder mit liegengebliebener Phase, seit
 * {@see PlanningCascadeService::VERWAIST_NACH_MINUTEN} ohne Fortschritt an Lauf und Schritten. Jeden Kandidaten gibt er dem
 * bestehenden Reaper ({@see PlanningCascadeService::reapeVerwaisteSteps}) im Besitzer-Team. Der Reaper entscheidet wie bisher
 * (offene KI-Aufträge, junge Schritte und Phasen bleiben); der Sweep selbst schreibt nichts.
 *
 * Aufrufer: der Zeitplan (`foodalchemist:kaskade-reaper`, alle 15 min, Schalter `foodalchemist.scheduler.kaskade_reaper_aktiv`) und
 * der Board-Poll für das eigene Team (gedrosselt).
 */
final class KaskadenReaperSweep
{
    /** Obergrenze je Durchlauf: ein Rückstau wird über mehrere Läufe abgebaut, statt einen Lauf lange zu blockieren. */
    public const MAX_JE_DURCHLAUF = 50;

    /**
     * Kandidaten (älteste zuerst): Läufe ohne Fortschritt seit der Verwaist-Grenze, die noch `running` sind oder eine Phase tragen.
     *
     * @param  list<int>  $ausser  schon geprüfte Läufe (Board-Poll)
     * @return Collection<int, FoodAlchemistCascadeRun>
     */
    public function kandidaten(?Team $team = null, array $ausser = []): Collection
    {
        $grenze = now()->subMinutes(PlanningCascadeService::VERWAIST_NACH_MINUTEN);
        $schritte = fn (QueryBuilder $q) => $q->selectRaw('1')->from('foodalchemist_cascade_run_steps as s')
            ->whereColumn('s.cascade_run_id', 'foodalchemist_cascade_runs.id')->whereNull('s.deleted_at');

        return FoodAlchemistCascadeRun::query()
            ->when($team !== null, fn ($q) => $q->where('team_id', $team->id))
            ->when($ausser !== [], fn ($q) => $q->whereNotIn('id', $ausser))
            ->where(fn ($w) => $w->where('status', 'running')->orWhereExists(fn (QueryBuilder $q) => $schritte($q)->whereNotNull('s.phase')))
            ->where('updated_at', '<', $grenze)
            ->whereNotExists(fn (QueryBuilder $q) => $schritte($q)->where(fn ($j) => $j->where('s.updated_at', '>=', $grenze)->orWhere('s.phase_at', '>=', $grenze)))
            ->orderBy('id')
            ->limit(self::MAX_JE_DURCHLAUF)
            ->get(['id', 'team_id', 'planning_session_id', 'status', 'updated_at']);
    }

    /**
     * Lesende Diagnose je Kandidat: warum er hängt (Schritt-Status, Anreicherung, Phasen, offene KI-Aufträge). Schreibt nichts.
     *
     * @return list<array{run_id: int, team_id: int, session_id: ?int, status: string, still_min: int, schritte: string, anreicherung: string, phasen: int, ki_offen: int}>
     */
    public function diagnose(?Team $team = null): array
    {
        $svc = app(PlanningCascadeService::class);

        return $this->kandidaten($team)->map(function (FoodAlchemistCascadeRun $r) use ($svc) {
            $steps = $r->steps()->get(['id', 'team_id', 'cascade_run_id', 'status', 'phase', 'phase_at', 'deferred', 'updated_at', 'context_snapshot', 'generator_run_id', 'ref_type', 'ref_id']);   // Spalten wie LaufStatus::stand (kiWartend)
            $anreicherung = $steps->map(fn ($s) => is_array($s->deferred['enrich'] ?? null) ? ($s->deferred['enrich']['status'] ?? null) : null)->filter()->countBy();

            return ['run_id' => (int) $r->id, 'team_id' => (int) $r->team_id, 'session_id' => $r->planning_session_id !== null ? (int) $r->planning_session_id : null,
                'status' => (string) $r->status, 'still_min' => (int) $r->updated_at?->diffInMinutes(now()),
                'schritte' => $steps->countBy('status')->map(fn ($n, $st) => "{$st}:{$n}")->implode(' '),
                'anreicherung' => $anreicherung->map(fn ($n, $st) => "{$st}:{$n}")->implode(' '),
                'phasen' => $steps->whereNotNull('phase')->count(), 'ki_offen' => count($svc->kiWartend($steps))];
        })->values()->all();
    }

    /**
     * Heilt alle Kandidaten (optional nur eines Teams) im jeweiligen Besitzer-Team. Fehler je Lauf werden geloggt und brechen den
     * Durchlauf nicht ab.
     *
     * @param  list<int>  $ausser  schon geprüfte Läufe (Board-Poll)
     * @return array{geprueft: int, geheilt: int, weiter_running: list<int>, fehler: list<int>}
     */
    public function heile(?Team $team = null, string $quelle = 'Zeitplan', array $ausser = []): array
    {
        $svc = app(PlanningCascadeService::class);
        $ergebnis = ['geprueft' => 0, 'geheilt' => 0, 'weiter_running' => [], 'fehler' => []];
        $teams = [];
        foreach ($this->kandidaten($team, $ausser) as $r) {
            $besitzer = $teams[(int) $r->team_id] ??= Team::find((int) $r->team_id);
            if ($besitzer === null) {
                continue;
            }
            $ergebnis['geprueft']++;
            try {
                $ergebnis['geheilt'] += (int) FaTeam::imTeam($besitzer, fn () => $svc->reapeVerwaisteSteps($besitzer, (int) $r->id));
            } catch (\Throwable $e) {
                $ergebnis['fehler'][] = (int) $r->id;
                Log::warning("Kaskade-Reaper ({$quelle}): Lauf {$r->id} fehlgeschlagen: {$e->getMessage()}");

                continue;
            }
            if (FoodAlchemistCascadeRun::query()->whereKey($r->id)->where('status', 'running')->exists()) {
                $ergebnis['weiter_running'][] = (int) $r->id;
            }
        }
        if ($ergebnis['geprueft'] > 0) {
            // Auch „nichts geheilt“ wird gemeldet: ohne diese Zeile ist ein Reaper, der läuft, nicht von einem zu unterscheiden, der nie läuft.
            Log::info("Kaskade-Reaper ({$quelle}): {$ergebnis['geprueft']} Läufe geprüft, {$ergebnis['geheilt']} Heilungen"
                . ($ergebnis['weiter_running'] !== [] ? ', weiter running: ' . implode(', ', $ergebnis['weiter_running']) : ''));
        }

        return $ergebnis;
    }
}
