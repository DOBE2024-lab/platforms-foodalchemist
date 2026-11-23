<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use RuntimeException;

/**
 * Plan 98 · 3.3 S1 · Gemeinsame Helfer der Kaskade, verschoben aus {@see PlanningCascadeService} (reine Verschiebung):
 * Lauf lesen, Step-Eigentum prüfen, Leitplanken der Session (Rezept- und Menü-Teil) und der Dispatch der Rezept- bzw.
 * Konzept-Generierung für einen Step. Die Teile der Kaskade (Abfragen, Erfinden, Ausgabe, Speiseplan, Freigabe) nutzen
 * diese Klasse, damit sie sich nicht gegenseitig rufen. Zustandslos; die Fassade reicht ihre öffentlichen Methoden weiter.
 */
class KaskadeKontext
{
    /** Async-Result via Cache (Job-Vertrag) — Minuten, bis der Worker den Step abschließt. */
    public const RESULT_TTL_MIN = 15;

    /** Ein team-sichtbarer Lauf inkl. Steps (oder null). */
    public function lauf(Team $team, int $runId): ?FoodAlchemistCascadeRun
    {
        return FoodAlchemistCascadeRun::visibleToTeam($team)->with('steps')->find($runId);
    }

    public function ownedStep(Team $team, int $stepId): FoodAlchemistCascadeRunStep
    {
        $step = FoodAlchemistCascadeRunStep::visibleToTeam($team)->findOrFail($stepId);
        if (! $step->isOwnedBy($team)) {
            throw new RuntimeException('Dieser Schritt gehört einem anderen Team. Freigeben und ändern kann nur das Team, das die Planung angelegt hat.');
        }

        return $step;
    }

    /**
     * Die am Planung-Go gesetzten Richtungs-Regler (Leitplanken) der Session — leer, wenn keine
     * Session/keine Regler. Der Kaskaden-Fan-out erbt sie damit an die erzeugten Gerichte, sodass
     * Niveau/Convenience/Bio/Diät/… nicht nur beim Depth-1-Go greifen, sondern durch die ganze Kaskade.
     *
     * **Leitplanken-Trennung (Roadmap Et.2a):** dieser Helfer speist ausschliesslich die REZEPT-
     * Erzeugung ({@see PlanningCascadeService::materialisiereConceptGericht}, {@see PlanningCascadeService::materialisiereSpeiseplanZelle}). Nur die
     * REZEPT-Leitplanken (Niveau/Convenience/Frische/Bio/Diät/Aroma/…) propagieren an Gerichte/
     * Basisrezepte. Die MENÜ-Leitplanken (`menue_*`: Anzahl Gänge · Preis-Korridor je Person · Diät-
     * Quoten · Portfolio-Balance) steuern die ZUSAMMENSTELLUNG des Menüs (Concept-Ebene, gelesen beim
     * Concept-Dispatch) und werden hier bewusst herausgefiltert — für ein einzelnes Gericht/Basisrezept
     * sind sie bedeutungslos und würden, in den Rezept-Prompt serialisiert, die Generierung verfälschen.
     *
     * @return array<string,mixed>
     */
    public function sessionGenerationParams(Team $team, ?int $planningSessionId): array
    {
        if ($planningSessionId === null) {
            return [];
        }
        $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
        $params = is_array($sess?->generation_params) ? $sess->generation_params : [];

        // Menü-Leitplanken bleiben auf der Concept-Ebene — nicht in die Rezept-Generierung durchreichen.
        return array_filter($params, fn ($k) => ! str_starts_with((string) $k, 'menue_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Die MENÜ-Leitplanken (`menue_*`) der Session — das Gegenstück zu {@see sessionGenerationParams}
     * (das genau diese herausfiltert). Sie steuern die ZUSAMMENSTELLUNG (Anzahl Gänge · Preis-Korridor je
     * Person · Diät-Quoten · Portfolio-Balance) und gehören an die Concept-Erzeugung des Vollkaskade-Slot-
     * Fan-outs ({@see PlanningCascadeService::dispatchSlotConcept}) — bis hier bekam der {@see GenerateConceptJob} dort mangels
     * durchgereichter Achsen den Default `[]`, sodass die Menü-Leitplanken die Komposition NIE erreichten
     * (der Cockpit-Concept-Pfad {@see dispatchConceptStep} reicht sie dagegen schon durch).
     *
     * @return array<string,mixed>
     */
    public function sessionMenueAchsen(Team $team, ?int $planningSessionId): array
    {
        if ($planningSessionId === null) {
            return [];
        }
        $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
        $params = is_array($sess?->generation_params) ? $sess->generation_params : [];

        return array_filter($params, fn ($k) => str_starts_with((string) $k, 'menue_'), ARRAY_FILTER_USE_KEY);
    }

    /** Dispatch der Rezept-/Gericht-Generierung für einen Step (spiegelt HatGeneratorLauf::starteLauf). */
    public function dispatchRezeptStep(
        Team $team,
        FoodAlchemistCascadeRunStep $step,
        string $brief,
        array $params,
        bool $vkModus,
        bool $vollAnreichern,
        ?int $planningSessionId,
        bool $staged = false,
    ): void {
        $runId = (string) Str::uuid();
        // Parameter-Bündel: Lineage (planning_session_id, vom Job an verknuepfeArtefakt) + der
        // Rückkanal an diesen Step (cascade_step_id → Job meldet Ergebnis/Fehler hierher zurück).
        $jobParams = $params;
        if ($planningSessionId !== null) {
            $jobParams['planning_session_id'] = $planningSessionId;
        }
        $jobParams['cascade_step_id'] = $step->id;
        // Gestuft: der Root-Step schiebt seine Sub-Rezepte auf (afterGenerated legt sie in `deferred` ab
        // statt zu dispatchen); freigegeben wird die nächste Ebene erst bei der Freigabe. Sonst eager.
        $jobParams['auto_dependencies'] = ! $staged;
        $jobParams['_defer_children'] = $staged;

        $step->update(['generator_run_id' => $runId]);
        Cache::put(GenerateRecipeJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
        // Doppel-Anreicherung vermeiden: im gestuften Lauf IST die Freigabe der Anreicherungs-Schritt
        // (starteFolgestufe → EnrichRecipeJob, completeCoverage). Eine Vor-Freigabe-Voll-Anreicherung am
        // noch nicht freigegebenen Draft liefe dieselbe teure Coverage-Kette ein zweites Mal — darum im
        // staged-Modus unterdrücken. Nicht-gestuft (Direkt-Materialisierung) behält das Alt-Verhalten.
        $vorFreigabeAnreichern = $vollAnreichern && ! $staged;   // Spec 80 H: nicht gestufte Läufe haben kein Freigabe-Tor — dort bleibt es bewusst so
        GenerateRecipeJob::dispatch($runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $brief, $jobParams, $vkModus, $vorFreigabeAnreichern, extern: (bool) ($jobParams['_extern'] ?? false));
    }

    /**
     * Dispatch der Konzept-Generierung für einen Step (Reuse-Assembler; im Erfinden-Modus fächert der Job auf).
     *
     * `$attachOwnerType`/`$attachContainerId` (Spec-42-Vollzug S3a): das erzeugte Konzept dockt an einen
     * Ausgabe-Container (foodbook→Kapitel). Bei Cockpit-Concepts (frei) bleiben beide null. Wichtig, damit
     * ein NEU-Generieren eines Kapitel-Concepts ({@see PlanningCascadeService::regeneriereStep}) den Kapitel-Attach nicht verliert.
     */
    public function dispatchConceptStep(Team $team, FoodAlchemistCascadeRunStep $step, string $brief, ?int $planningSessionId, string $creativeMode, array $params = [], ?string $attachOwnerType = null, ?int $attachContainerId = null): void
    {
        $runId = (string) Str::uuid();
        $step->update(['generator_run_id' => $runId]);
        Cache::put(GenerateConceptJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
        // Menü-Leitplanken des Concept-Tabs (menue_*-Keys aus reglerParams) an die Konzept-Erzeugung
        // reichen — sie speisen den Gerüst-Kopf (Preis-Korridor je Person). Nur die menue_*-Teilmenge,
        // damit der Job-Payload schlank und die Absicht klar bleibt.
        $menueAchsen = array_filter($params, fn ($k) => str_starts_with((string) $k, 'menue_'), ARRAY_FILTER_USE_KEY);
        GenerateConceptJob::dispatch(
            $runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $brief,
            null, $planningSessionId, $step->id, $creativeMode, false, false, $attachOwnerType, $attachContainerId, $menueAchsen
        );
    }
}
