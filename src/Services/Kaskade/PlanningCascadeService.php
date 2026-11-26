<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Jobs\FanoutConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateDishProposalJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeisekartePositionJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Jobs\ReviseDishProposalJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use RuntimeException;
use Platform\FoodAlchemist\Support\Warteschlange;
use Platform\FoodAlchemist\Services\Kaskade\KaskadenSchritt;
use Platform\FoodAlchemist\Services\Kaskade\KaskadeKontext;
use Platform\FoodAlchemist\Services\Kaskade\KaskadeAbfragen;
use Platform\FoodAlchemist\Services\Kaskade\KaskadeFreigabe;
use Platform\FoodAlchemist\Services\Kaskade\KaskadeSpeiseplanVoll;
use Platform\FoodAlchemist\Services\Kaskade\KaskadeAusgabeVoll;
use Platform\FoodAlchemist\Services\Kaskade\KaskadeErfinden;
use Platform\FoodAlchemist\Events\KaskadenSchrittAbgeschlossen;

/**
 * Der geteilte Kaskaden-Motor (Planungs-Kaskade). EIN Einstieg für alle Flächen: {@see starteKaskade}.
 *
 * Prinzip „generalisieren statt neu bauen": der Motor orchestriert die bestehenden Erzeugungs-Services
 * (P0: {@see GenerateRecipeJob} → {@see RecipeGeneratorService}) und trackt sie über
 * {@see FoodAlchemistCascadeRun}/{@see FoodAlchemistCascadeRunStep}. Er erzeugt NUR Drafts — die
 * Freigabe an eine Live-Ausgabe ist das zweite Gate (Sammel-Review, P2).
 *
 * Tiefen-Leiter (`scope`): `rezept` ⊂ `gericht` ⊂ `concept` ⊂ `vollkaskade`; der Motor läuft von der
 * gewählten Stufe abwärts. **P0/P1a orchestrieren `rezept`/`gericht`/`concept`** (je Depth-1, ein Step
 * je Go — Concept im Reuse-Assembler + Erfinden-Fan-out). Die `vollkaskade` (Etappe 5) braucht einen
 * Ausgabe-Owner (`owner_type` foodbook|speisekarte|speiseplan) und wird aus den Ausgabe-Modulen
 * getriggert, nicht frei im Cockpit — ohne Owner wirft {@see starteKaskade} bewusst.
 */
class PlanningCascadeService
{
    use \Platform\FoodAlchemist\Services\Concerns\KaskadeKiWarten;   // externer KI-Weg (Lauf 62) + Lauf-Status-Hinweis

    /**
     * Spec 53 / Paket C: Phasen-Texte (`cascade_run_steps.phase`) als Konstanten statt Magic-Strings —
     * Schreiber (Jobs) UND Leser (Index::konformitaetPruefen vergleicht gegen PHASE_KONFORMITAET, um
     * eine laufende Prüfung zu erkennen) referenzieren dieselbe Konstante, damit ein Textwechsel den
     * Vergleich nicht still bricht.
     */
    public const PHASE_ANREICHERUNG = 'Anreicherung läuft …';
    public const PHASE_BILDER = 'KI-Fotos werden erzeugt …';
    public const PHASE_KONFORMITAET = 'Konformität wird geprüft …';

    /**
     * Ab wann ein in-flight Step (`queued`/`running`) als VERWAIST (abgebrochen) gilt — Urteil beim
     * Lesen, kein Spalten-Zustand (Beweis-Philosophie wie {@see \Platform\FoodAlchemist\Models\FoodAlchemistBulkRun::istVerwaist}).
     * Bewusst weit über jeder realen Generierung (Rezept-/Gericht-Generierung dauert Minuten, nicht
     * eine halbe Stunde), damit ein noch lebender, langsamer Job NIE fälschlich für tot erklärt wird.
     * Der 90-s-Watchdog ({@see \Platform\FoodAlchemist\Livewire\Planung\KaskadenLauf::WATCHDOG_SEKUNDEN}, seit S2-E2)
     * WARNT viel früher; das Reapen ({@see reapeVerwaisteSteps}) GREIFT erst, wenn der Job praktisch
     * sicher tot ist.
     */
    public const VERWAIST_NACH_MINUTEN = 30;

    /** Marker in run.params für einen vom Menschen abgebrochenen Lauf. */
    public const ABBRUCH_KEY = KaskadenSchritt::ABBRUCH_KEY;

    /**
     * Startet einen Kaskaden-Lauf und gibt ihn zurück (Status `running`). Die eigentliche Generierung
     * läuft asynchron im Queue-Job; die Fläche pollt den Run/seine Steps.
     *
     * @param  array{brief?:string, params?:array<string,mixed>, voll_anreichern?:bool, created_via?:string, existing_concept_id?:int, origin_dish_idea_id?:int}  $optionen
     */
    public function starteKaskade(
        Team $team,
        string $scope,
        ?FoodAlchemistPlanningSession $session,
        string $creativeMode,
        array $optionen = [],
    ): FoodAlchemistCascadeRun {
        if (! in_array($scope, FoodAlchemistCascadeRun::SCOPES, true)) {
            throw new RuntimeException("Unbekannte Planungsart „{$scope}\".");
        }
        if (! in_array($creativeMode, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $creativeMode = 'voll_kreativ';
        }
        // Voll-Kaskade (P3+): Ausgabe → Gerichte/Concepts. foodbook|speisekarte = 1 Concept je Slot;
        // speiseplan (P5) = ein Gericht je leerer Zyklus-Zelle (Zeitachse, kein Concept-Zwischenschritt).
        if ($scope === 'vollkaskade') {
            return (string) ($optionen['owner_type'] ?? '') === 'speiseplan'
                ? $this->speiseplan()->starteSpeiseplanVollkaskade($team, $session, $creativeMode, $optionen) : $this->ausgabe()->starteVollkaskade($team, $session, $creativeMode, $optionen);
        }
        if ($scope === 'rezept' && $session?->source_gp_id !== null) {   // Zukauf-Planung am GP (13.11.): feste Ware, Zukauf-Weg statt Plan/Generator
            return app(\Platform\FoodAlchemist\Services\Rezept\ZukaufBasisrezeptService::class)->starteLauf($team, $session, $creativeMode, $optionen);
        }

        $brief = trim((string) ($optionen['brief'] ?? ''));
        if ($brief === '' && $session !== null) {
            $brief = $this->briefAusSession($session);
        }
        if ($brief === '') {
            throw new RuntimeException('Es fehlt der Auftrag. Bitte Titel oder Brief der Planung ausfüllen.');
        }

        $params = is_array($optionen['params'] ?? null) ? $optionen['params'] : [];
        $vollAnreichern = (bool) ($optionen['voll_anreichern'] ?? true);
        // Gate pro Ebene: die Cockpit-Scopes (rezept|gericht|concept) laufen gestuft — jede Ebene hält an,
        // bis sie freigegeben wird (dann startet die nächste). Opt-out via optionen['staged']=false.
        $staged = (bool) ($optionen['staged'] ?? true);

        // Der Proposal-Gate-Entscheid muss den START ÜBERLEBEN. `regeneriereStep` läuft in einem
        // späteren Request und sieht nur noch den Run — ohne persistierte Marke musste es raten
        // (staged + Session + Wurzel-gericht) und schickte damit auch Läufe ins Text-Gate, die nie
        // eins hatten: die Batch-Skizze (Planung\Index, created_via 'plan_batch_skizze') und der
        // MCP-Start (PlanungKaskadeStartPostTool) übergeben KEIN `proposal_first`, starten also auf
        // dem Rezeptpfad — und wurden beim Fortsetzen auf GenerateDishProposalJob umgeleitet.
        // Doppelt falsch bei der Skizze: das Text-Gate hat der Mensch mit der Auswahl der Skizze
        // schon passiert. Darum wandert der Entscheid in die ohnehin persistierten `params`.
        $proposalFirst = $scope === 'gericht' && $staged && $session !== null
            && (bool) ($optionen['proposal_first'] ?? false);
        if ($proposalFirst) {
            $params['proposal_first'] = true;
        }
        // Spec 80 B1: Basisrezept-Go plant zuerst die Komponenten (Gate wie beim Gerichtsvorschlag).
        // Persistiert, damit regeneriereStep den Entscheid kennt.
        $planFirst = $scope === 'rezept' && $staged && $session !== null && (bool) ($optionen['plan_first'] ?? false);
        if ($planFirst) {
            $params['plan_first'] = true;
        }

        // Geplanter Pfad (Etappe 2b, „KI-Kopf"): der Concept-Step referenziert ein SCHON geprüftes
        // Draft-Concept ({@see ConceptGeneratorService::planAusBrief}) statt eines neu zu generierenden.
        // Ownership VOR der Run-Anlage prüfen — ein Fremd-/Fehl-Concept darf keinen Rumpf-Lauf hinterlassen.
        $existingConceptId = $scope === 'concept' ? (int) ($optionen['existing_concept_id'] ?? 0) : 0;
        if ($existingConceptId > 0
            && ! FoodAlchemistConcept::where('team_id', $team->id)->whereKey($existingConceptId)->exists()) {
            throw new RuntimeException("Das gewählte Konzept (Nr. {$existingConceptId}) wurde nicht gefunden oder gehört zu einem anderen Team.");
        }

        // Lineage (Etappe 4, Teil 2a): startet der Lauf aus einer Divergenz-Board-Skizze (Skizze →
        // Gericht-Tab → „Go"), trägt er die Ursprungs-Skizze — loser Zeiger für die Status-Rückkopplung
        // auf die Skizzen-Karte. 0/fehlend = kein Skizzen-Ursprung (Bestandsverhalten).
        $originDishIdeaId = (int) ($optionen['origin_dish_idea_id'] ?? 0);

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => $scope,
            'creative_mode' => $creativeMode,
            'brief' => $brief,
            'params' => $params !== [] ? $params : null,
            'status' => 'running',
            'staged' => $staged,
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
            'origin_dish_idea_id' => $originDishIdeaId > 0 ? $originDishIdeaId : null,
        ]);

        // Depth-1: genau ein Step (rezept|gericht → GenerateRecipeJob, concept → GenerateConceptJob).
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => $scope,   // 'rezept' | 'gericht' | 'concept'
            'label' => Str::limit($brief, 120),
            'status' => 'running',
            'sort' => 0,
        ]);

        if ($scope === 'concept') {
            if ($existingConceptId > 0) {
                // Kein GenerateConceptJob — Step zeigt direkt auf den geprüften Draft; der Gericht-Fan-out
                // läuft über den bestehenden staged-Pfad (deferred.fanout → FanoutConceptJob).
                $this->referenziereConceptStep($team, $step, $existingConceptId, $creativeMode, $staged, $session?->id);
            } else {
                $this->kontext()->dispatchConceptStep($team, $step, $brief, $session?->id, $creativeMode, $params);
            }
        } elseif ($planFirst) {
            \Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->id, $brief, $params);
        } elseif ($proposalFirst) {
            // Direkter Gericht-Go: zuerst nur ein kompakter Bauplan. Noch kein Rezeptdatensatz,
            // keine Dependencies und keine Anreicherung; der geplante Step ist das Freigabe-Gate.
            GenerateDishProposalJob::dispatch(
                $team->id,
                (int) (Auth::id() ?? 0),
                (int) $session->id,
                (int) $step->id,
                $brief,
                $creativeMode,
            );
        } else {
            // Im gestuften Lauf schiebt der Root-Step (Basisrezept/Gericht) seine Kinder auf bis zur Freigabe.
            $this->kontext()->dispatchRezeptStep($team, $step, $brief, $params, $scope === 'gericht', $vollAnreichern, $session?->id, $staged);
        }

        return $run;
    }

    /** Plan 98 · 3.3 S1: gemeinsame Helfer (Lauf, Step-Eigentum, Leitplanken, Step-Dispatch) liegen in {@see KaskadeKontext}. */
    private function kontext(): KaskadeKontext
    {
        return app(KaskadeKontext::class);
    }

    private function freigabe(): KaskadeFreigabe
    {
        return app(KaskadeFreigabe::class);
    }

    private function speiseplan(): KaskadeSpeiseplanVoll
    {
        return app(KaskadeSpeiseplanVoll::class);
    }

    private function ausgabe(): KaskadeAusgabeVoll
    {
        return app(KaskadeAusgabeVoll::class);
    }

    private function erfinden(): KaskadeErfinden
    {
        return app(KaskadeErfinden::class);
    }

    /**
     * Geplanter Pfad (Etappe 2b, „KI-Kopf"): der Concept-Step zeigt auf ein SCHON existierendes, im
     * Conceptor geprüftes Draft-Concept ({@see ConceptGeneratorService::planAusBrief}) — es wird NICHT
     * neu generiert (kein {@see GenerateConceptJob}). Der Step wird direkt `done`; im Erfinden-Modus
     * werden die aufgeschobenen Fan-out-Args wie beim normalen Concept-Job am Step abgelegt
     * ({@see GenerateConceptJob} staged-Zweig, Zeile ~111), sodass die Freigabe den bestehenden
     * Gericht-Fan-out startet ({@see FanoutConceptJob} → {@see fanoutConceptInvention}) — KEIN neuer
     * Fan-out-Code.
     *
     * Ownership ist in {@see starteKaskade} vorab geprüft. Eager (nicht gestuft): der Fan-out läuft
     * sofort, aber im Worker (LLM raus aus dem Web-Request) — denn {@see gibStepFrei} ruft
     * {@see starteFolgestufe} nur bei `staged`.
     */
    private function referenziereConceptStep(
        Team $team,
        FoodAlchemistCascadeRunStep $step,
        int $conceptId,
        string $creativeMode,
        bool $staged,
        ?int $planningSessionId,
    ): void {
        // Nur die Erfinden-Modi fächern auf (Reuse/datenbank füllt keine leeren Slots) — spiegelt
        // GenerateConceptJob. Ohne Erfindung bleibt es beim geprüften Konzept (Slots wie geplant).
        $erfindet = in_array($creativeMode, ['voll_kreativ', 'hybrid'], true);
        if ($erfindet) {
            // Die Planung (und damit ihr Trendradar-Ursprung, Spec 79) fließt über planning_session_id in die
            // spätere Erfindungs-Divergenz ({@see IdeenService::kiDivergenzConcept}).
            $step->update(['deferred' => ['fanout' => [
                'mode' => $creativeMode,
                'planning_session_id' => $planningSessionId,
            ]]]);
        }

        // Step abschliessen: er zeigt auf das geprüfte Draft-Concept. recompute → gestuft: `review`
        // (der Mensch gibt die Stufe frei → FanoutConceptJob). Noch ist kein Gericht-Kind da, das
        // Kohäsion-Gate greift korrekt erst nach dem Fan-out.
        $this->markStepDone((int) $step->id, 'concept', $conceptId);

        // Eager (nicht gestuft): die Freigabe ruft starteFolgestufe NICHT (nur staged) — den Fan-out
        // darum hier direkt anstossen, aber als Queue-Job (kein LLM inline im Web-Request des Go).
        if ($erfindet && ! $staged) {
            $step->run?->update(['status' => 'running']);
            FanoutConceptJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->id);
        }
    }

    // ── P3/P4: Voll-Kaskade — Ausgabe-Frame → 1 Concept je Slot ───────────

    /** Voll-Kaskade für ein Foodbook-Kapitel. Liegt in {@see KaskadeAusgabeVoll::starteKapitelKaskade}. */
    public function starteKapitelKaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, int $foodbookId, int $chapterId, array $optionen = []): FoodAlchemistCascadeRun
    {
        return $this->ausgabe()->starteKapitelKaskade($team, $session, $creativeMode, $foodbookId, $chapterId, $optionen);
    }

    /** Format-Inhalte nach dem Rahmen ordnen. Liegt in {@see KaskadeAusgabeVoll::ordneFormatNachRahmen}. */
    public function ordneFormatNachRahmen(Team $team, int $runId): void
    {
        $this->ausgabe()->ordneFormatNachRahmen($team, $runId);
    }

    // ── P5: Speiseplan-Voll-Kaskade — ein Gericht je leerer Zyklus-Zelle ──

    /**
     * Vorschau der Speiseplan-Voll-Kaskade (Zellen, Deckel). Liegt in {@see KaskadeSpeiseplanVoll::speiseplanVorschau}.
     *
     * @return array{linien:int, leer:int, dieser_lauf:int, wochen:int, gedeckelt:bool}
     */
    public function speiseplanVorschau(Team $team, int $planId): array
    {
        return $this->speiseplan()->speiseplanVorschau($team, $planId);
    }

    /**
     * Die Rezept-Leitplanken der Session (ohne `menue_*`). Liegt in {@see KaskadeKontext::sessionGenerationParams}.
     *
     * @return array<string,mixed>
     */
    public function sessionGenerationParams(Team $team, ?int $planningSessionId): array
    {
        return $this->kontext()->sessionGenerationParams($team, $planningSessionId);
    }

    /** Worker-Logik für eine Speiseplan-Zelle. Liegt in {@see KaskadeSpeiseplanVoll::materialisiereSpeiseplanZelle}. */
    public function materialisiereSpeiseplanZelle(Team $team, int $planId, string $entryDate, string $meal, int $lineId, string $brief, int $stepId, ?int $planningSessionId = null): void
    {
        $this->speiseplan()->materialisiereSpeiseplanZelle($team, $planId, $entryDate, $meal, $lineId, $brief, $stepId, $planningSessionId);
    }

    /** Worker-Logik für eine Speisekarte-Position. Liegt in {@see KaskadeAusgabeVoll::materialisiereSpeisekartePosition}. */
    public function materialisiereSpeisekartePosition(Team $team, int $rubrikId, string $brief, int $stepId, ?int $planningSessionId = null): void
    {
        $this->ausgabe()->materialisiereSpeisekartePosition($team, $rubrikId, $brief, $stepId, $planningSessionId);
    }

    // ── P1b: Erfinden — Fan-out des Concepts in erfundene Gerichte ─────────

    /** P1b Fan-out des Concepts in erfundene Gerichte. Liegt in {@see KaskadeErfinden::fanoutConceptInvention}. */
    public function fanoutConceptInvention(Team $team, int $conceptStepId, int $conceptId, string $mode, ?int $planningSessionId = null, array $seen = []): void
    {
        $this->erfinden()->fanoutConceptInvention($team, $conceptStepId, $conceptId, $mode, $planningSessionId, $seen);
    }

    /** Worker-Logik für ein erfundenes Concept-Gericht. Liegt in {@see KaskadeErfinden::materialisiereConceptGericht}. */
    public function materialisiereConceptGericht(Team $team, int $ideaId, int $stepId, ?int $planningSessionId = null): void
    {
        $this->erfinden()->materialisiereConceptGericht($team, $ideaId, $stepId, $planningSessionId);
    }

    // ── Rückkanal aus dem Job (läuft im Queue-Worker) ──────────────────────

    // ── Step-Status (CQ 3.4: ausgelagert nach KaskadenSchritt, damit Rezept-/Concepter-Dienste nicht nach oben rufen) ──

    /** Step erfolgreich: erzeugtes Artefakt festhalten, dann Run-Status neu bestimmen. */
    public function markStepDone(int $stepId, string $refType, int $refId): void
    {
        app(KaskadenSchritt::class)->markStepDone($stepId, $refType, $refId);
    }

    /** Step fehlgeschlagen: Fehler festhalten, Run neu bewerten. */
    public function markStepFailed(int $stepId, string $error): void
    {
        app(KaskadenSchritt::class)->markStepFailed($stepId, $error);
    }

    /** Persistente Zwischen-Phase eines laufenden Steps (Beiwerk, nie blockierend). */
    public function setzePhase(int $stepId, ?string $label): void
    {
        app(KaskadenSchritt::class)->setzePhase($stepId, $label);
    }

    /**
     * Stoppt genau EINEN Planungs-Lauf, nicht den globalen Queue-Worker. Bereits laufende externe
     * Provider-Requests lassen sich nicht hart unterbrechen; ihre Rückmeldung wird aber ignoriert und
     * es werden keine Folgestufen mehr gestartet. Queued/geplante Steps werden sofort terminal.
     */
    public function brecheLaufAb(Team $team, int $runId): bool
    {
        $run = $this->kontext()->lauf($team, $runId);
        if ($run === null || ! $run->isOwnedBy($team) || $run->status !== 'running') {
            return false;
        }
        $params = is_array($run->params) ? $run->params : [];
        $params[self::ABBRUCH_KEY] = now()->toIso8601String();
        $run->update(['params' => $params, 'status' => 'failed']);
        $run->steps()->whereIn('status', ['geplant', 'queued', 'running'])->update([
            'status' => 'failed',
            'error' => 'Vom Benutzer abgebrochen. Weitere Schritte werden nicht gestartet.',
        ]);

        return true;
    }

    /**
     * Stoppt alle noch aktiven Kaskaden einer Planungs-Session, bevor die Session verworfen wird.
     * Ohne diesen Schritt bleibt ein bereits eingereihter Queue-Job lebendig und scheitert spaeter
     * beim Zugriff auf die inzwischen soft-geloeschte Session.
     */
    public function brecheSessionLaeufeAb(Team $team, int $sessionId): int
    {
        $runIds = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->where('planning_session_id', $sessionId)
            ->where('status', 'running')
            ->pluck('id');

        $abgebrochen = 0;
        foreach ($runIds as $runId) {
            if ($this->brecheLaufAb($team, (int) $runId)) {
                $abgebrochen++;
            }
        }

        return $abgebrochen;
    }

    /**
     * #124: Ein Fan-out-Abbruch ist NICHT „Concept kaputt". Das Concept-Rezept ist längst angelegt
     * und freigegeben (der Fan-out läuft NACH der Freigabe) — nur die automatische Gericht-Erfindung
     * crashte. Darum den Step-Status (`freigegeben`) LASSEN und den Fehler separat in
     * `deferred.fanout_error` festhalten → Cockpit zeigt „Auto-Gericht-Erfindung fehlgeschlagen",
     * nicht „Concept fehlgeschlagen". Ist der Step (wider Erwarten) noch nicht freigegeben, war das
     * Concept nie live → dann echter Step-Fehler ({@see markStepFailed}).
     */
    public function markFanoutFailed(int $stepId, string $error): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        if ($step->status !== 'freigegeben') {
            $this->markStepFailed($stepId, $error);   // Concept war nie live → echter Fehler

            return;
        }
        $deferred = is_array($step->deferred) ? $step->deferred : [];
        unset($deferred['fanout']);                   // Fan-out-Args sind verbraucht/tot
        $deferred['fanout_error'] = Str::limit($error, 500, '');
        // Spec 53 / Paket C-Nachtrag: Status bleibt `freigegeben` (kein markStepFailed-Pfad hier) —
        // ohne das explizite Löschen bliebe "Skizzen werden erfunden …" nach einem harten Job-Tod
        // (Timeout/OOM in FanoutConceptJob) für immer am Step stehen.
        $step->update(['deferred' => $deferred, 'phase' => null, 'phase_at' => null]);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
        $this->erfinden()->scoreConceptCohesionIfComplete($step);
    }

    /**
     * E-P0 (Spec 40): Der Attach des erzeugten Konzepts ans Ausgabe-Kapitel/die Rubrik ist fehlgeschlagen —
     * aber das Konzept IST erzeugt (der Concept-Step wird gleich regulär `done`, {@see markStepDone} läuft
     * NACH diesem Aufruf und lässt `deferred` unangetastet). Kein „Konzept kaputt": den Fehler + die Ziel-Info
     * separat in `deferred` festhalten (analog {@see markFanoutFailed}, #124), damit das Cockpit ein sichtbares,
     * behebbares Amber-Signal zeigt und die Recovery-Aktion {@see haengeKonzeptNach} den Attach nachholen kann.
     * Der Step-Status bleibt bewusst unberührt (das Konzept ist gültig). Wirft nie (Rückkanal aus dem Job).
     */
    public function markAttachFailed(int $stepId, string $ownerType, int $containerId, int $conceptId, string $error): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        $deferred = is_array($step->deferred) ? $step->deferred : [];
        $deferred['attach_error'] = Str::limit($error, 500, '');
        $deferred['pending_attach'] = [
            'owner_type' => $ownerType,
            'container_id' => $containerId,
            'concept_id' => $conceptId,
        ];
        $step->update(['deferred' => $deferred]);
    }

    /**
     * E-P0-Recovery (Spec 40): einen zuvor fehlgeschlagenen Attach nachholen — das (existierende) Konzept
     * ans in `deferred.pending_attach` gemerkte Ausgabe-Kapitel/die Rubrik hängen (reuse {@see FoodbookService::addBlock}
     * bzw. {@see SpeisekarteService::addPosition}). Gelingt es, wird das Signal (`attach_error`/`pending_attach`)
     * gelöscht. Scheitert es erneut, propagiert der Fehler an den Aufrufer (Livewire zeigt ihn als Toast) und das
     * Signal bleibt stehen. Team-scoped ({@see ownedStep}).
     */
    public function haengeKonzeptNach(Team $team, int $stepId): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        $deferred = is_array($step->deferred) ? $step->deferred : [];
        $pending = is_array($deferred['pending_attach'] ?? null) ? $deferred['pending_attach'] : null;
        if ($pending === null) {
            return;   // nichts nachzuholen
        }
        $ownerType = (string) ($pending['owner_type'] ?? '');
        $containerId = (int) ($pending['container_id'] ?? 0);
        $conceptId = (int) ($pending['concept_id'] ?? 0);
        if ($containerId <= 0 || $conceptId <= 0) {
            return;
        }
        if ($ownerType === 'foodbook') {
            app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookService::class)->addBlock($team, $containerId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        } elseif ($ownerType === 'speisekarte') {
            app(\Platform\FoodAlchemist\Services\Ausgabe\SpeisekarteService::class)->addPosition($team, $containerId, ['type' => 'menue_ref', 'concept_id' => $conceptId]);
        } elseif ($ownerType === 'offer') {
            app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotService::class)->referenziereConcept($team, $containerId, $conceptId);   // E2: containerId = Angebots-ID
        } elseif ($ownerType === 'format') {
            app(\Platform\FoodAlchemist\Services\Concepter\FormatService::class)->slotConceptEinfuegen($team, $containerId, $conceptId);   // containerId = format_id
            $this->ausgabe()->ordneFormatNachRahmen($team, (int) $step->cascade_run_id);   // Spec 80 F2
        } else {
            return;
        }
        unset($deferred['attach_error'], $deferred['pending_attach']);
        $step->update(['deferred' => $deferred]);
    }

    /**
     * E4 (Spec 40): Sourcing-Lücken eines erzeugten Rezept-Steps ins Signale-Cockpit melden — die im Step
     * verwendeten GPs OHNE beschaffbaren Lead-LA (verfuegbarkeit-Bucket `luecke`) je als Sortiments-Lücke
     * ({@see PairingInspirationService::meldeLuecke}, idempotent je GP-Name). Nordstern „Lücke ist Signal,
     * kein Fehler". Team-scoped ({@see ownedStep}). Gibt die gemeldeten GP-Namen zurück.
     *
     * @return list<string>
     */
    public function meldeSourcingLuecken(Team $team, int $stepId): array
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null) {
            return [];
        }
        $gpIds = FoodAlchemistRecipeIngredient::where('recipe_id', (int) $step->ref_id)
            ->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($gpIds === []) {
            return [];
        }
        $verf = app(\Platform\FoodAlchemist\Services\GpStamm\FavoriteGpService::class)->verfuegbarkeit($team, $gpIds);
        $luecken = array_keys(array_filter($verf, fn ($v) => ($v['bucket'] ?? null) === 'luecke'));
        if ($luecken === []) {
            return [];
        }
        $namen = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $luecken)->pluck('name', 'id');
        $inspiration = app(\Platform\FoodAlchemist\Services\Pairing\PairingInspirationService::class);
        $gemeldet = [];
        foreach ($namen as $gpId => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $inspiration->meldeLuecke($team, (string) $name, [
                'gp_id' => (int) $gpId,
                'quelle_step_id' => (int) $step->id,
                'quelle' => 'kaskade_step',
            ]);
            $gemeldet[] = (string) $name;
        }

        return $gemeldet;
    }

    /**
     * E4 (Spec 40): Favoriten-Kandidaten aus einem erzeugten Rezept-Step — die verwendeten GPs, die NOCH
     * NICHT als Favorit gepinnt sind. Reiner Vorschlag: das Pinnen bleibt mensch-gated (eigene Aktion,
     * {@see FavoriteGpService::pin}). Team-scoped.
     *
     * @return list<array{id:int, name:string}>
     */
    public function favoritKandidatenFuerStep(Team $team, int $stepId): array
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null) {
            return [];
        }
        $gpIds = FoodAlchemistRecipeIngredient::where('recipe_id', (int) $step->ref_id)
            ->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($gpIds === []) {
            return [];
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds)
            ->whereNotIn('id', \Platform\FoodAlchemist\Support\FavoritenOverlay::gpIds($team))   // Spec 85 FAV-1: Liste des Teams
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
    }

    /**
     * Idempotenz/Resume (Etappe 8) — eine abgebrochene Kaskade sauber fortsetzbar machen.
     *
     * Stirbt ein Generator-Job hart (OOM/Timeout/Worker-Kill), ohne seinen `failed()`-Haken zu feuern,
     * bleibt sein Step ewig `queued`/`running`; der Run verharrt in `running` ({@see recomputeRunStatus}),
     * der Cockpit-Spinner löst nie auf, und WEDER {@see verwirfStep}/{@see verwirfRun} (setzen `done`/
     * `failed` voraus) NOCH die Freigabe ({@see gibStufeFrei}) greifen — der Lauf ist eine Sackgasse.
     *
     * Dieser Reaper markiert die VERWAISTEN in-flight Steps (älter als {@see VERWAIST_NACH_MINUTEN})
     * als `failed` — über {@see markStepFailed}, damit der Run neu bewertet wird (raus aus dem ewigen
     * `running`) und bei Concept-Kindern das Kohärenz-Gate scoren kann. Danach ist der Lauf wieder
     * handlungsfähig: der Mensch generiert die verwaisten Steps einzeln neu ({@see regeneriereStep})
     * oder verwirft sie.
     *
     * Konservativ (Beweis-Philosophie): NICHT-verwaiste (junge) in-flight Steps bleiben unangetastet —
     * ein langsamer, noch lebender Job wird nie abgewürgt. Team-scoped über {@see lauf}.
     *
     * @return int Zahl der reapten Steps (0 = nichts verwaist)
     */
    public function reapeVerwaisteSteps(Team $team, int $runId): int
    {
        $run = $this->kontext()->lauf($team, $runId);
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op. Hier ist das KEINE Kosmetik,
        // sondern ein echter Cross-Tenant-Write-Riegel: markStepFailed(stepId) trägt (anders als
        // regeneriereStep) KEINEN ownedStep-Guard → ohne diese Zeile könnte ein Kind-Team die Steps
        // des vererbten Eltern-Laufs auf `failed` setzen. Deckungsgleich mit `lauf()===null`.
        if ($run === null || ! $run->isOwnedBy($team)) {
            return 0;
        }
        $grenze = now()->subMinutes(self::VERWAIST_NACH_MINUTEN);
        // Ein Schritt, der auf eine externe KI-Antwort wartet, ist nicht verwaist: sein Job ist sauber beendet und startet mit der
        // Antwort neu (Auftrag gilt 24 h). Ihn hier auf `failed` zu setzen, würfe einen gesunden MCP-Lauf nach 30 Minuten weg.
        // Gezählt wird nur ein OFFENER, nicht abgelaufener Auftrag (kiWartend); ein abgelaufener hält nichts mehr fest.
        $kiWartend = $this->kiWartend($run->steps);
        $frei = fn ($s) => ! isset($kiWartend[(int) $s->id]);
        $still = function ($s, mixed $zeit = null) use ($grenze): bool {
            $t = $zeit !== null ? \Illuminate\Support\Carbon::make($zeit) : $s->updated_at;

            return $t !== null && $t->lt($grenze);
        };
        $kiAbgelaufen = $this->kiAbgelaufen($run->steps);
        $geheilt = 0;
        $log = fn ($s, string $was) => \Illuminate\Support\Facades\Log::info("Kaskade-Reaper: Lauf {$run->id}, Schritt {$s->id} ({$s->label}): {$was}");

        // Fall 1 (io 23.11., Lauf 62): Schritt in-flight und still — auch wenn sein KI-Auftrag abgelaufen ist (dann mit diesem Grund)
        foreach ($run->steps->filter(fn ($s) => in_array($s->status, ['queued', 'running'], true) && $frei($s) && $still($s)) as $s) {
            $grund = isset($kiAbgelaufen[(int) $s->id])
                ? 'Abgebrochen: KI-Auftrag abgelaufen, die Antwort kam nicht. Neu erzeugen oder verwerfen.'
                : 'Abgebrochen: Die Erstellung hat sich nicht mehr gemeldet. Neu erzeugen oder verwerfen.';
            $this->markStepFailed((int) $s->id, $grund);
            $log($s, 'in-flight → failed' . (isset($kiAbgelaufen[(int) $s->id]) ? ' (KI-Auftrag abgelaufen)' : ''));
            $geheilt++;
        }
        // Fall 3 (Läufe 69, 61, 38): Anreicherung hängt auf queued/running, ohne Job und ohne offenen Auftrag → failed, „neu anreichern“
        foreach ($run->steps->fresh() as $s) {
            $enrich = is_array($s->deferred['enrich'] ?? null) ? $s->deferred['enrich'] : null;
            if ($enrich === null || ! in_array($enrich['status'] ?? null, ['queued', 'running'], true) || ! $frei($s) || ! $still($s, $enrich['at'] ?? null)) {
                continue;
            }
            $deferred = $s->deferred;
            $deferred['enrich'] = ['status' => 'failed', 'error' => 'Abgebrochen: Die Anreicherung hat sich nicht mehr gemeldet. Neu anreichern.',
                'at' => now()->toIso8601String()] + $enrich;
            $s->update(['deferred' => $deferred]);
            $log($s, 'Anreicherung ' . $enrich['status'] . ' → failed');
            $geheilt++;
        }
        // Liegengebliebene Phase (harter Job-Tod nach setzePhase): hält das Board auf „läuft“
        foreach ($run->steps->fresh() as $s) {
            if ($s->phase !== null && $frei($s) && $still($s, $s->phase_at) && ! in_array($s->status, ['queued', 'running'], true)) {
                FoodAlchemistCascadeRunStep::whereKey($s->id)->update(['phase' => null, 'phase_at' => null]);
                $log($s, "Phase „{$s->phase}“ geräumt");
                $geheilt++;
            }
        }
        // Fall 2 (Lauf 66): ohne offene Schritte bleibt ein Lauf sonst auf `running` stehen — einmal neu bewerten
        if ($run->fresh()?->status === 'running') {
            $this->recomputeRunStatus((int) $run->id);
            if ($run->fresh()?->status !== 'running') {
                \Illuminate\Support\Facades\Log::info("Kaskade-Reaper: Lauf {$run->id} neu bewertet → {$run->fresh()?->status}");
                $geheilt++;
            }
        }

        return $geheilt;
    }

    /**
     * Gebündeltes Resume (Idempotenz/Resume, Etappe 8 Teil 2): alle GESCHEITERTEN (`failed`)
     * generierbaren Steps eines Laufs auf einmal RE-DISPATCHEN — statt sie einzeln über
     * {@see regeneriereStep} neu zu generieren. Reust {@see regeneriereStep} je Step (verwirft das
     * Teil-Wrack, setzt den Step auf `running` und dispatcht den passenden Generator-Job neu).
     *
     * IDEMPOTENT gegen Doppel-Jobs: es werden AUSSCHLIESSLICH `failed`-Steps angefasst — in-flight
     * Steps (`queued`/`running`) bleiben unangetastet, ebenso `done`/`geplant`/`skipped`/`verworfen`.
     * Da {@see regeneriereStep} den Step SOFORT auf `running` flippt, sieht ein zweiter Aufruf (Doppel-
     * Klick, während der Resume-Lauf noch arbeitet) den Step nicht mehr als `failed` → kein Doppel-Job.
     * {@see lauf} lädt die Steps je Aufruf frisch aus der DB, sodass die Idempotenz auch über getrennte
     * Requests hält.
     *
     * Ergänzt den {@see reapeVerwaisteSteps}-Reaper (Teil 1): der macht harte Hänger erst zu `failed`,
     * dieser Resume greift dann alle `failed`-Steps (verwaist ODER regulär gescheitert) gebündelt.
     * Nur die generierbaren Kinds (rezept|gericht|concept); GP-/Referenz-Steps tragen keinen Generator.
     *
     * @return int Zahl der re-dispatchten Steps (0 = nichts Gescheitertes zum Fortsetzen)
     */
    public function setzeLaufFort(Team $team, int $runId): int
    {
        $run = $this->kontext()->lauf($team, $runId);
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op statt lautem Wurf im ersten
        // regeneriereStep (dessen ownedStep fängt es zwar, aber laut). Siehe reapeVerwaisteSteps/gibStufeFrei.
        if ($run === null || ! $run->isOwnedBy($team)) {
            return 0;
        }
        $gescheitert = $run->steps->filter(fn ($s) => $s->status === 'failed'
            && in_array($s->kind, ['rezept', 'gericht', 'concept'], true));
        foreach ($gescheitert as $s) {
            $this->freigabe()->regeneriereStep($team, (int) $s->id);
        }

        return $gescheitert->count();
    }

    // ── Auto-Trigger: Menü-Folge-Kohärenz-Gate nach der Fan-out-Erfindung ──

    /** Lauf-Status aus den Steps ableiten (atomar unter Lauf-Sperre), {@see KaskadenSchritt::recomputeRunStatus}. */
    public function recomputeRunStatus(int $runId): void
    {
        app(KaskadenSchritt::class)->recomputeRunStatus($runId);
    }

    public function istAbgebrochen(int $runId): bool
    {
        return app(KaskadenSchritt::class)->istAbgebrochen($runId);
    }

    /**
     * CQ 3.4: Folge eines abgeschlossenen Steps (Event {@see KaskadenSchrittAbgeschlossen}, synchron): Wording-Pass und
     * Kohäsions-Score, sobald alle erfundenen Gerichte eines Concepts durch sind. Vorher direkt in markStepDone/-Failed.
     */
    public function nachSchrittAbschluss(KaskadenSchrittAbgeschlossen $e): void
    {
        $this->erfinden()->scoreConceptCohesionIfComplete($e->step);
    }

    // ── Freigabe / Verwerfen (Gate 2 — inline im Editor) ───────────────────

    /**
     * Gate 2. Liegt in {@see KaskadeFreigabe::gibStepFrei}.
     *
     * @return string Aktions-Tag für den Aufrufer (MCP-Tool zeigt es an, UI ignoriert es)
     */
    public function gibStepFrei(Team $team, int $stepId): string
    {
        return $this->freigabe()->gibStepFrei($team, $stepId);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::gibStufeFrei}. */
    public function gibStufeFrei(Team $team, int $runId, string $kind): void
    {
        $this->freigabe()->gibStufeFrei($team, $runId, $kind);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::reBilder}. */
    public function reBilder(Team $team, int $stepId): void
    {
        $this->freigabe()->reBilder($team, $stepId);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::uebernimmManuellesFotoFuerStep}. */
    public function uebernimmManuellesFotoFuerStep(Team $team, int $stepId, \Illuminate\Http\UploadedFile $datei, bool $istErgebnis = false, ?string $caption = null): ?FoodAlchemistRecipeStepPhoto
    {
        return $this->freigabe()->uebernimmManuellesFotoFuerStep($team, $stepId, $datei, $istErgebnis, $caption);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::uebernimmVorhandenesFotoFuerStep}. */
    public function uebernimmVorhandenesFotoFuerStep(Team $team, int $stepId, int $quelleFotoId, bool $istErgebnis = false, ?string $caption = null): ?FoodAlchemistRecipeStepPhoto
    {
        return $this->freigabe()->uebernimmVorhandenesFotoFuerStep($team, $stepId, $quelleFotoId, $istErgebnis, $caption);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::reAnreichern}. */
    public function reAnreichern(Team $team, int $stepId, ?bool $completeCoverageErzwingen = null): void
    {
        $this->freigabe()->reAnreichern($team, $stepId, $completeCoverageErzwingen);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::enrichBestehendesRezept}. */
    public function enrichBestehendesRezept(
        Team $team,
        int $recipeId,
        bool $vkModus,
        ?int $planningSessionId = null,
        // Spec 50 · E-1: die zwei Regler, die der MCP-Weg braucht (Defaults = bisheriges Verhalten). `kiBilder` bleibt AUS — Bilder sind teuer.
        bool $completeCoverage = true,
        bool $kiBilder = false,
        array $leitplanken = [], bool $extern = false,   // 27.10.: Rezept-Leitplanken des Imports → Bestandssuche vor jedem GP-Mint · #1002 P2b: extern = per MCP, Jobs legen KI-Aufträge an (KI-Bilder bleiben Plattform: aus)
    ): FoodAlchemistCascadeRun
    {
        return $this->freigabe()->enrichBestehendesRezept($team, $recipeId, $vkModus, $planningSessionId, $completeCoverage, $kiBilder, $leitplanken, $extern);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::regeneriereStep}. */
    public function regeneriereStep(Team $team, int $stepId, ?string $kommentar = null): void
    {
        $this->freigabe()->regeneriereStep($team, $stepId, $kommentar);
    }

    /**
     * Gate 2. Liegt in {@see KaskadeFreigabe::aenderePlan}.
     *
     * @param  array<string, mixed>  $daten
     */
    public function aenderePlan(Team $team, int $stepId, string $aktion, array $daten = []): void
    {
        $this->freigabe()->aenderePlan($team, $stepId, $aktion, $daten);
    }

    /**
     * Gate 2. Liegt in {@see KaskadeFreigabe::starteRezeptNachPlan}.
     *
     * @param  list<array<string, mixed>>  $komponenten
     */
    public function starteRezeptNachPlan(Team $team, int $stepId, array $komponenten): void
    {
        $this->freigabe()->starteRezeptNachPlan($team, $stepId, $komponenten);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::verwirfStep}. */
    public function verwirfStep(Team $team, int $stepId): void
    {
        $this->freigabe()->verwirfStep($team, $stepId);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::erzeugeGeplantenStep}. */
    public function erzeugeGeplantenStep(Team $team, int $stepId): void
    {
        $this->freigabe()->erzeugeGeplantenStep($team, $stepId);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::ueberarbeiteGerichtVorschlag}. */
    public function ueberarbeiteGerichtVorschlag(Team $team, int $stepId, string $feedback): void
    {
        $this->freigabe()->ueberarbeiteGerichtVorschlag($team, $stepId, $feedback);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::erzeugeGeplantesConcept}. */
    public function erzeugeGeplantesConcept(Team $team, int $stepId): void
    {
        $this->freigabe()->erzeugeGeplantesConcept($team, $stepId);
    }

    /**
     * Gate 2. Liegt in {@see KaskadeFreigabe::synchronisiereKapitelSteps}.
     *
     * @return array{ok: bool, grund?: string, ergaenzt?: int, verworfen?: int, umbenannt?: int}
     */
    public function synchronisiereKapitelSteps(Team $team, int $runId): array
    {
        return $this->freigabe()->synchronisiereKapitelSteps($team, $runId);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::verwirfGeplantenStep}. */
    public function verwirfGeplantenStep(Team $team, int $stepId): void
    {
        $this->freigabe()->verwirfGeplantenStep($team, $stepId);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::ergaenzeManuellenSubStep}. */
    public function ergaenzeManuellenSubStep(Team $team, int $runId, string $name, ?int $parentStepId = null): ?FoodAlchemistCascadeRunStep
    {
        return $this->freigabe()->ergaenzeManuellenSubStep($team, $runId, $name, $parentStepId);
    }

    /**
     * Gate 2. Liegt in {@see KaskadeFreigabe::gibNeueFrei}.
     *
     * @param  list<int>|null  $nurSteps
     * @return array{freigegeben: int, uebersprungen: int}
     */
    public function gibNeueFrei(Team $team, int $runId, ?array $nurSteps = null): array
    {
        return $this->freigabe()->gibNeueFrei($team, $runId, $nurSteps);
    }

    /** Gate 2. Liegt in {@see KaskadeFreigabe::verwirfRun}. */
    public function verwirfRun(Team $team, int $runId): void
    {
        $this->freigabe()->verwirfRun($team, $runId);
    }

    // ── Abfragen (für die Fläche) ─────────────────────────────────────────

    /** Neuester Lauf einer Planung (für das Cockpit-Polling), null wenn keiner. Liegt in {@see KaskadeAbfragen}. */
    public function letzterLauf(Team $team, int $planningSessionId): ?FoodAlchemistCascadeRun
    {
        return $this->abfragen()->letzterLauf($team, $planningSessionId);
    }

    /**
     * Globaler KI-Status fürs Planung-Kopf. Liegt in {@see KaskadeAbfragen::kiStatusFuerTeam}.
     *
     * @return array{wartend:int, laufend:int, aktuelle_phase:?string, fehler_24h:int, queue:?int}
     */
    public function kiStatusFuerTeam(Team $team): array
    {
        return $this->abfragen()->kiStatusFuerTeam($team);
    }

    /**
     * Owner-Kontext der Session für die Leitstelle. Liegt in {@see KaskadeAbfragen::ownerKontext}.
     *
     * @return array{owner_type:string, owner_id:int, typ_label:string, name:string, route:string, route_param:array<string,int>}|null
     */
    public function ownerKontext(Team $team, int $sessionId): ?array
    {
        return $this->abfragen()->ownerKontext($team, $sessionId);
    }

    /** Ein team-sichtbarer Lauf inkl. Steps (oder null). Liegt in {@see KaskadeKontext}. */
    public function lauf(Team $team, int $runId): ?FoodAlchemistCascadeRun
    {
        return $this->kontext()->lauf($team, $runId);
    }

    /**
     * READ-ONLY Kaskaden-Status eines Laufs für MCP und Fläche. Liegt in {@see KaskadeAbfragen::laufStatus}.
     *
     * @return array<string, mixed>|null
     */
    public function laufStatus(Team $team, int $runId): ?array
    {
        return $this->abfragen()->laufStatus($team, $runId);
    }

    private function abfragen(): KaskadeAbfragen
    {
        return app(KaskadeAbfragen::class);
    }

    /** Brief für die Erzeugung: Session-Brief (Fallback Titel) + Analyse-Auszug (spiegelt Planung::goBrief). */
    private function briefAusSession(FoodAlchemistPlanningSession $session): string
    {
        $brief = trim((string) $session->brief);
        $analyse = trim((string) $session->analysis);
        $text = $brief !== '' ? $brief : (string) $session->title;
        if ($analyse !== '') {
            $text .= "\n\nKontext:\n" . mb_substr($analyse, 0, 800);
        }

        return trim($text);
    }
}
