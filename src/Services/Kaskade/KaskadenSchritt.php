<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Events\KaskadenSchrittAbgeschlossen;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * CQ 3.4 · Step- und Lauf-Status der Planungs-Kaskade (cascade_runs/-steps). Aus PlanningCascadeService ausgelagert, damit
 * Rezept- und Concepter-Dienste (RecipeDependencyWorkflowService, ConceptVorgabenService) ihren Fortschritt melden, ohne die
 * Orchestrierung aufzurufen: Das war der Kreis PlanningCascadeService ↔ RecipeDependencyWorkflowService. Synchroner
 * Statusschreiber mit Lauf-Sperre, bewusst kein Event. Die Folge eines abgeschlossenen Steps (Wording-Pass,
 * Kohäsions-Score) liegt in der Planung und hängt am synchronen Event {@see KaskadenSchrittAbgeschlossen}.
 * PlanningCascadeService behält gleichnamige Fassaden; Verhalten und Reihenfolge sind unverändert.
 */
final class KaskadenSchritt
{
    /** Marke am Lauf (params), dass er abgebrochen wurde (Spec 80 C5). */
    public const ABBRUCH_KEY = '_cancelled_at';

    /** Step erfolgreich: erzeugtes Artefakt festhalten, dann Run-Status neu bestimmen. */
    public function markStepDone(int $stepId, string $refType, int $refId): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        // Ein bereits abgebrochener Lauf darf durch eine verspätete Worker-Rückmeldung nicht wieder
        // auf review/done springen. Das Artefakt bleibt Draft und kann separat verworfen werden.
        if ($this->istAbgebrochen((int) $step->cascade_run_id)) {
            return;
        }
        // L5: das Step-Label auf den ECHTEN Artefakt-Namen nachziehen. Bei der Anlage war es der auf
        // 120 Zeichen geschnittene Brief — das Cockpit zeigte also den Briefing-Text statt des Rezept-/
        // Concept-Namens. Der Brief bleibt separat im Run-Kopf sichtbar. Fail-soft (Name-Auflösung optional).
        $artefaktName = $this->artefaktName($step->team_id ? (int) $step->team_id : null, $refType, $refId);
        $updates = ['status' => 'done', 'ref_type' => $refType, 'ref_id' => $refId, 'error' => null, 'phase' => null, 'phase_at' => null];
        if ($artefaktName !== null && $artefaktName !== '') {
            $updates['label'] = Str::limit($artefaktName, 120, '');
        }
        $step->update($updates);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
        KaskadenSchrittAbgeschlossen::dispatch($step);   // synchron: Wording-Pass + Kohäsions-Score in der Planung
    }

    /** L5: Anzeigename des erzeugten Artefakts (Recipe/Concept) — für das Step-Label. Fail-soft → null. */
    private function artefaktName(?int $teamId, string $refType, int $refId): ?string
    {
        if ($teamId === null || $refId <= 0) {
            return null;
        }
        try {
            return match ($refType) {
                'recipe' => FoodAlchemistRecipe::where('team_id', $teamId)->whereKey($refId)->value('name'),
                'concept' => FoodAlchemistConcept::where('team_id', $teamId)->whereKey($refId)->value('name'),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** Step fehlgeschlagen: Fehler festhalten (Artefakt bleibt ggf. teilweise erzeugt), Run neu bewerten. */
    public function markStepFailed(int $stepId, string $error): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        $step->update(['status' => 'failed', 'error' => Str::limit($error, 500, ''), 'phase' => null, 'phase_at' => null]);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
        KaskadenSchrittAbgeschlossen::dispatch($step);   // synchron: Wording-Pass + Kohäsions-Score in der Planung
    }

    /**
     * Persistente Zwischen-Phase eines laufenden Steps (Spec 53 / Paket C) — Cockpit-Wahrheit statt
     * Cache-only (der Cache `fa:recipe-gen:{runId}` bleibt zusätzlich für die Rezept-Modals). Ein-
     * Spalten-Update; Guard gegen tote/terminale Steps (verworfen/skipped haben nichts mehr zu melden).
     * Beiwerk — ein Tracking-Fehler darf den aufrufenden Job/Lauf nie kippen.
     */
    public function setzePhase(int $stepId, ?string $label): void
    {
        try {
            FoodAlchemistCascadeRunStep::whereKey($stepId)
                ->whereIn('status', ['queued', 'running', 'done', 'freigegeben'])
                ->update(['phase' => $label, 'phase_at' => $label !== null ? now() : null]);
        } catch (\Throwable) {
            // Tracking ist Beiwerk — nie blockierend.
        }
    }

    public function istAbgebrochen(int $runId): bool
    {
        $run = FoodAlchemistCascadeRun::find($runId);

        return $run !== null
            && is_array($run->params)
            && isset($run->params[self::ABBRUCH_KEY]);
    }

    /**
     * Run-Status aus den Steps ableiten:
     * - ein Step läuft (queued|running)                        → `running`
     * - ein Step ist erzeugt, aber unentschieden (done)        → `review` (Gate 2 offen)
     * - ein Step ist geplant (Sub-Rezept wartet auf Freigabe)  → `review` (der Mensch ist am Zug)
     * - alles entschieden, mind. ein freigegeben|skipped       → `done`
     * - alles entschieden, nur verworfen|failed                → `failed`
     */
    public function recomputeRunStatus(int $runId): void
    {
        // ATOMAR seit 2026-09-03, weil demo ab jetzt ZWEI Queue-Worker fährt.
        //
        // Das hier ist ein Lese-Ändern-Schreiben: alle Steps lesen, daraus den Lauf-Status
        // ableiten, schreiben. Jeder Kind-Job ruft es über markStepDone. Mit einem Worker war das
        // zwangsläufig seriell; mit zwei ist folgende Verschränkung möglich:
        //
        //   A: Step A = done
        //   A: liest → B läuft noch → entscheidet »running«
        //   B: Step B = done
        //   B: liest → alles fertig → schreibt »review«
        //   A: schreibt »running«        ← veraltet, überschreibt B
        //
        // Folge: der Lauf hängt dauerhaft auf »running«. Das ist der »ewige Spinner« — und
        // dieselbe Anzeige steht schon für einen ganz anderen Fehler (Generator-OOM), die
        // Diagnose wäre also doppelt verdeckt. Darum Transaktion + Sperre auf den Lauf und die
        // Steps INNERHALB der Sperre lesen: zwei Aufrufe serialisieren, der zweite sieht den
        // Schreibvorgang des ersten und rechnet mit dem gültigen Stand.
        DB::transaction(function () use ($runId): void {
            $this->recomputeRunStatusGesperrt($runId);
        });
    }

    /** Der eigentliche Rechenweg — läuft ausschließlich unter der Lauf-Sperre. */
    private function recomputeRunStatusGesperrt(int $runId): void
    {
        $run = FoodAlchemistCascadeRun::query()->whereKey($runId)->lockForUpdate()->first();
        if ($run === null) {
            return;
        }
        // Spec 80 C5: ein abgebrochener Lauf bleibt abgebrochen. Späte Rückmeldungen (ein Provider-Call
        // lässt sich nicht hart stoppen) dürfen ihn nicht auf „Zu prüfen" zurückholen.
        if (is_array($run->params) && isset($run->params[self::ABBRUCH_KEY])) {
            if ($run->status !== 'failed') {
                $run->update(['status' => 'failed']);
            }

            return;
        }
        $steps = $run->steps()->get(['status', 'deferred']);
        $anreicherungLaeuft = $steps->contains(static function ($step): bool {
            $status = is_array($step->deferred) ? ($step->deferred['enrich']['status'] ?? null) : null;

            return in_array($status, ['queued', 'running'], true);
        });
        if ($steps->whereIn('status', ['queued', 'running'])->count() > 0 || $anreicherungLaeuft) {
            if ($run->status !== 'running') {
                $run->update(['status' => 'running']);
            }

            return;
        }
        // `geplant` = benanntes, noch nicht erzeugtes Sub-Rezept: es wartet auf einen MENSCHEN
        // (Freigabe der Stufe darüber), nicht auf den Worker — also review, nicht running/failed.
        if ($steps->whereIn('status', ['done', 'geplant'])->count() > 0) {
            $run->update(['status' => 'review']);

            return;
        }
        $positiv = $steps->whereIn('status', ['freigegeben', 'skipped'])->count();
        $hatFehler = $steps->where('status', 'failed')->count() > 0;
        // 2026-09-07: eine UNREIFE Übernahme ist kein Abschluss. Vorher zählte jedes `skipped`
        // als positiv, und Lauf 65 meldete „abgeschlossen — alle Artefakte freigegeben oder als
        // Bestand übernommen" über einem `draft` mit 0 Schritten und ungemappter Zutat. Der Lauf
        // bleibt jetzt in `review`, bis die Lücke geschlossen ist — automatisch (eigener Entwurf)
        // oder durch eine menschliche Entscheidung (fremdes/lebendes Rezept).
        $unreifeUebernahme = $steps->where('status', 'skipped')->contains(function ($st) {
            $reuse = is_array($st->deferred['reuse'] ?? null) ? $st->deferred['reuse'] : null;

            return $reuse !== null && ! ($reuse['reif'] ?? false);
        });
        // L4: „done" darf nicht lügen. Ist ein Kind-Step gescheitert (und nichts mehr in-flight/geplant),
        // bleibt der Run in `review` statt `done` — der Mensch sieht den Fehler im Cockpit und kann den
        // Step neu erzeugen. Nur ein sauberer Abschluss ohne failed-Step meldet `done`.
        if ($positiv > 0) {
            $run->update(['status' => ($hatFehler || $unreifeUebernahme) ? 'review' : 'done']);

            return;
        }
        $run->update(['status' => 'failed']);
    }
}
