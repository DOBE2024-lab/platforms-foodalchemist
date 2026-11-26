<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Jobs\FanoutConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateDishProposalJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Jobs\ReviseDishProposalJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Planung\PlanningFrameService;
use Platform\FoodAlchemist\Services\Rezept\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\Rezept\RecipeImageService;
use Platform\FoodAlchemist\Services\Rezept\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Services\Rezept;   // für Rezept\FreigabeSperre (teilqualifiziert im verschobenen Rumpf)
use Platform\FoodAlchemist\Support\Warteschlange;
use RuntimeException;

/**
 * Plan 98 · 3.3 S1 · Gate 2 der Kaskade, verschoben aus {@see PlanningCascadeService} (reine Verschiebung): Freigeben
 * ({@see gibStepFrei}, {@see gibStufeFrei}, {@see gibNeueFrei}) mit Folgestufe und Anreicherung, Verwerfen ({@see verwirfStep},
 * {@see verwirfRun}), geplante Schritte erzeugen, Neu-Generieren ({@see regeneriereStep}), Plan ändern, Fotos und Kapitel-Abgleich.
 * Step-Status über {@see KaskadenSchritt}, gemeinsame Helfer über {@see KaskadeKontext}, Concept-Dispatch der Ausgabe über
 * {@see KaskadeAusgabeVoll}. Livewire (Planung\KaskadenLauf), Tools und Tests rufen weiter die Fassade mit gleicher Signatur.
 */
class KaskadeFreigabe
{
    /**
     * Step freigeben: das Draft-Artefakt live setzen (Rezept → approved, Concept → active) über die
     * sanktionierten Services, Step → `freigegeben`, Run neu bewerten. Nur `done`-Steps sind freigebbar.
     *
     * Ein `geplant`-Step (egal welcher `kind`) wird durch dieselbe FREIGABE-Aktion stattdessen ERZEUGT
     * — Gate 1 (Kapitel → Concept, Sub-Rezept/erfundenes Gericht → Materialisierung) statt Gate 2
     * (Draft → live). Vorher lief das nur für `kind=concept`; ein geplanter Gericht-/Rezept-Step war
     * über `gibStepFrei`/MCP-FREIGABE ein stiller No-op (Anlass: Lauf 74, Step 513) — die UI bedient
     * denselben Fall längst über `erzeugeGeplant`.
     *
     * @return string Aktions-Tag für den Aufrufer (MCP-Tool zeigt es an, UI ignoriert es):
     *                 `freigegeben` | `geplant_erzeugt` | `no_op_status_<status>`
     */
    public function gibStepFrei(Team $team, int $stepId): string
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->status === 'geplant') {
            if ($step->kind === 'concept') {
                $this->erzeugeGeplantesConcept($team, $stepId);
            } else {
                $this->erzeugeGeplantenStep($team, $stepId);
            }

            return 'geplant_erzeugt';
        }
        if ($step->status !== 'done') {
            return 'no_op_status_' . $step->status;
        }
        // Spec 80 H3: im gestuften Lauf IST die Freigabe die Anreicherung — grün (approved) erst, wenn sie geklappt hat (EnrichRecipeJob);
        // bis dahin `review` + Marker. 1.1f (Kurator 19.11.): offene Komponenten → Gericht nur `review`, Concept bleibt; Hinweis am Step.
        $sperre = app(Rezept\FreigabeSperre::class);
        $offen = match ($step->ref_id !== null ? $step->ref_type : null) { 'recipe' => $sperre->offenFuerRezept((int) $step->ref_id), 'concept' => $sperre->offenFuerConcept((int) $step->ref_id), default => [] };
        $nachAnreicherung = (bool) ($step->run?->staged) && $step->ref_type === 'recipe' && $step->ref_id !== null;
        if ($step->ref_id !== null && $step->ref_type === 'recipe') {
            app(RecipeService::class)->setStatus($team, (int) $step->ref_id, $nachAnreicherung || $offen !== [] ? 'review' : 'approved');
        } elseif ($step->ref_id !== null && $step->ref_type === 'concept' && $offen === []) {
            app(ConceptService::class)->setStatus($team, (int) $step->ref_id, 'active');
        }
        $deferred = array_diff_key(is_array($step->deferred) ? $step->deferred : [], ['freigabe_gesperrt' => 1])
            + ($offen !== [] ? ['freigabe_gesperrt' => array_map(fn ($k) => $k['name'] . ' (' . \Platform\FoodAlchemist\Exceptions\FreigabeGesperrtException::statusLabel($k) . ')', $offen)] : []);
        if ($nachAnreicherung) {
            $deferred['freigabe_nach_anreicherung'] = true;
        }
        $step->update(['status' => 'freigegeben', 'deferred' => $deferred ?: null]);

        // Gestuft (Gate pro Ebene): die Freigabe startet die nächste Stufe UND reichert das Artefakt
        // komplett an (beides als Queue-Job). Bei nicht-gestuften Läufen bleibt es bei der Live-Setzung.
        // Beim async Concept-Fan-out (Job legt die Gericht-Steps erst später an) darf der recompute den
        // Run NICHT auf „done" zurückfallen lassen — starteFolgestufe hält ihn dann selbst auf „running".
        $asyncFolgestufe = $step->run?->staged ? $this->starteFolgestufe($team, $step->fresh()) : false;

        if (! $asyncFolgestufe) {
            $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);
        }

        return 'freigegeben';
    }

    /**
     * Freigabe einer ganzen Stufe: gibt alle noch offenen (`done`) Steps einer `kind` frei — der
     * Stufen-Knopf im Cockpit. Jede Einzel-Freigabe startet die Kinder des Steps (siehe gibStepFrei).
     */
    public function gibStufeFrei(Team $team, int $runId, string $kind): void
    {
        $run = $this->kontext()->lauf($team, $runId);
        // D1-Slice 3: ein SICHTBARER, aber nicht besessener Lauf (childA sieht den vererbten
        // Root-Lauf) ist hier ein sauberer No-op — nicht ein lauter Wurf im ersten `gibStepFrei`.
        // Der per-Step `ownedStep`-Guard fängt es zwar korrekt (kein Write), aber laut statt leise;
        // ein Fremd-Lauf hat für dieses Team schlicht nichts freizugeben. Deckungsgleich mit `lauf()===null`.
        if ($run === null || ! $run->isOwnedBy($team)) {
            return;
        }
        foreach ($run->steps->where('status', 'done')->where('kind', $kind) as $s) {
            $this->gibStepFrei($team, (int) $s->id);
        }
    }

    /**
     * Fortsetzung nach der Freigabe eines gestuften Steps: Concept → Gericht-Fan-out ({@see FanoutConceptJob});
     * Rezept/Gericht → aufgeschobene Sub-Rezepte erzeugen + volle Anreicherung (+ optional KI-Fotos, `ki_bilder`).
     * Alles als Queue-Job (kein LLM/Anreicherung inline im Web-Request der Freigabe).
     *
     * @return bool true, wenn eine ASYNCHRONE Folgestufe läuft, die den Run-Status selbst neu bestimmt
     *              (Concept-Fan-out) — dann darf der Aufrufer NICHT recomputen (sonst „done"-Rückfall).
     */
    private function starteFolgestufe(Team $team, FoodAlchemistCascadeRunStep $step): bool
    {
        $userId = (int) (Auth::id() ?? 0);

        if ($step->kind === 'concept') {
            if (is_array($step->deferred['fanout'] ?? null)) {
                // Run auf running halten, bis der Job die Gerichte erzeugt (er recomputet danach selbst).
                $step->run?->update(['status' => 'running']);
                FanoutConceptJob::dispatch($team->id, $userId, (int) $step->id);

                return true;
            }

            return false;
        }

        if ($step->ref_type === 'recipe' && $step->ref_id !== null) {
            $recipe = FoodAlchemistRecipe::where('team_id', $team->id)->find((int) $step->ref_id);
            if ($recipe !== null && is_array($step->deferred['children'] ?? null)) {
                // dispatchChildren legt die Kind-Steps SYNCHRON als running an → recompute sieht sie korrekt.
                app(RecipeDependencyWorkflowService::class)->resumeDeferredChildren($team, $step, $recipe);
            }
            $params = is_array($step->run?->params) ? $step->run->params : [];
            $zielVk = isset($params['ziel_vk_eur']) ? (float) $params['ziel_vk_eur'] : null;
            $kiBilder = (bool) ($params['ki_bilder'] ?? false);
            // Anreicherungs-Tiefe (Step-by-Step/Sensorik/…): Default an = Bestandsverhalten; per Leitplanke
            // (generation_params.complete_coverage=false) auf „leichte" Anreicherung stellbar. GP-Mint bleibt
            // im Job unabhängig davon an (EK).
            $completeCoverage = array_key_exists('complete_coverage', $params) ? (bool) $params['complete_coverage'] : true;
            // Anreicherung SYNCHRON als `queued` markieren → die Planung pollt sichtbar durch (der Run
            // ist bei einem flachen Gericht sofort „done", der Job läuft aber async danach).
            $this->markEnrichQueued($step);
            EnrichRecipeJob::dispatch($team->id, $userId, (int) $step->ref_id, $zielVk, $kiBilder, (int) $step->id, false, false, $completeCoverage);
            $this->anreichereUnreifeUebernahmen($team, $step, $userId, $zielVk, $kiBilder, $completeCoverage);
        }

        return false;
    }

    /**
     * Die UNREIFEN, TEAM-EIGENEN Übernahmen unter diesem Step mit anreichern.
     *
     * WARUM (Befund 2026-09-07, Lauf 65): `starteFolgestufe` reicherte ausschließlich das
     * freigegebene Rezept selbst an — eine Schleife über die Kinder gibt es nur in
     * `enrichBestehendesRezept`, `RecipeModal::allesAnreichern` und
     * `VkModal::allesAnreichern`, also genau nicht im Pfad der Kaskaden-Freigabe. Step 460
     * („Basisrezept: Gemüsebrühe") blieb deshalb ein `draft` mit 0 Schritten, und der Lauf
     * meldete trotzdem „abgeschlossen".
     *
     * Die Regel des Nutzers bleibt gewahrt — „nur die neuen": ein REIFES Bestands-Rezept
     * wird nicht angefasst, und ein FREMDES (Referenz-Rezept eines übergeordneten Teams,
     * oder team-eigen und schon `approved`) auch nicht, selbst wenn es Lücken hat. Dort
     * gehört die Entscheidung dem Menschen; die Zeile zeigt sie an und der Lauf gilt nicht
     * als fertig. Angereichert wird nur, was ohnehin ein unfertiges Stück dieses Hauses ist.
     *
     * Fail-soft: eine fehlende Übernahme kippt die Freigabe nie.
     */
    private function anreichereUnreifeUebernahmen(
        Team $team,
        FoodAlchemistCascadeRunStep $step,
        int $userId,
        ?float $zielVk,
        bool $kiBilder,
        bool $completeCoverage,
    ): void {
        try {
            $kinder = FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
                ->where('parent_step_id', $step->id)
                ->where('status', 'skipped')
                ->where('ref_type', 'recipe')
                ->whereNotNull('ref_id')
                ->get();
            foreach ($kinder as $kind) {
                $reuse = is_array($kind->deferred['reuse'] ?? null) ? $kind->deferred['reuse'] : null;
                if ($reuse === null || ($reuse['reif'] ?? false) || ! ($reuse['eigen'] ?? false)) {
                    continue;   // reif = fertig · fremd = nicht unsere Baustelle
                }
                // Nur eigene ENTWÜRFE: an einem freigegebenen Rezept hängen möglicherweise
                // Gerichte, Foodbooks und Speisepläne — das wird nicht im Vorbeigehen neu
                // geschrieben, auch wenn es dem eigenen Team gehört.
                if (! in_array((string) ($reuse['status'] ?? ''), ['stub', 'draft'], true)) {
                    continue;
                }
                $this->markEnrichQueued($kind);
                EnrichRecipeJob::dispatch(
                    $team->id, $userId, (int) $kind->ref_id, $zielVk, $kiBilder,
                    (int) $kind->id, false, false, $completeCoverage,
                )->onQueue(Warteschlange::anreichern());
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[PlanungKaskade] Anreicherung übernommener Sub-Rezepte fehlgeschlagen', [
                'step' => $step->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Anreicherungs-Status eines Rezept-/Gericht-Steps synchron auf `queued` setzen (Sicht-Signal fürs Polling). */
    private function markEnrichQueued(FoodAlchemistCascadeRunStep $step): void
    {
        $fresh = $step->fresh() ?? $step;
        $deferred = is_array($fresh->deferred) ? $fresh->deferred : [];
        $deferred['enrich'] = ['status' => 'queued', 'at' => now()->toIso8601String()];
        $fresh->update(['deferred' => $deferred]);
        $this->schritt()->recomputeRunStatus((int) $fresh->cascade_run_id);
    }

    /** Bild-Erzeugungs-Status eines Rezept-/Gericht-Steps synchron auf `queued` setzen (Sicht-Signal fürs Polling). */
    private function markBilderQueued(FoodAlchemistCascadeRunStep $step): void
    {
        $fresh = $step->fresh() ?? $step;
        $deferred = is_array($fresh->deferred) ? $fresh->deferred : [];
        $deferred['bilder'] = ['status' => 'queued', 'at' => now()->toIso8601String()];
        $fresh->update(['deferred' => $deferred]);
    }

    /**
     * „Neu erzeugen" (Cockpit, Etappe 7 Teil 2b): stößt AUSSCHLIESSLICH die KI-Fotos eines bereits
     * freigegebenen Rezept-/Gericht-Steps erneut an — ohne Voll-Anreicherung — z. B. nachdem die
     * Bild-Erzeugung fehlschlug (deferred.bilder=failed). Der EnrichRecipeJob läuft im `nurBilder`-
     * Modus: er ersetzt die alten KI-Fotos und lässt deferred.enrich unangetastet.
     */
    public function reBilder(Team $team, int $stepId): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return;
        }
        // Server-Guard gegen Doppel-Enqueue (Spec 53 / Paket C): kein zweiter Foto-Job, während der
        // erste noch läuft — sonst überschneiden sich zwei RecipeImageService-Läufe am selben Rezept.
        $bilderStatus = is_array($step->deferred) ? ($step->deferred['bilder']['status'] ?? null) : null;
        if (in_array($bilderStatus, ['queued', 'running'], true)) {
            throw new \Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException('Das Bild wird bereits erzeugt. Bitte kurz warten.');
        }
        $this->markBilderQueued($step);
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->ref_id, null, false, (int) $step->id, true);
    }

    /**
     * Manuelles Foto (Cockpit, Etappe 7 Teil 2): ein hochgeladenes Bild als Rezept-Foto eines
     * Rezept-/Gericht-Steps übernehmen — die NICHT-KI-Alternative zur Foto-Erzeugung, OHNE KI-Call.
     * Owner-Guard über {@see ownedStep} (Freigabe/Schreiben nur durchs Besitzer-Team, D1); das Rezept
     * wird team-scoped aufgelöst. Delegiert an {@see RecipeImageService::uebernimmManuellesFoto}
     * (`$istErgebnis` → Hero statt Pool). Gibt das angelegte Foto zurück (oder null bei fremdem Step-Typ).
     */
    public function uebernimmManuellesFotoFuerStep(Team $team, int $stepId, \Illuminate\Http\UploadedFile $datei, bool $istErgebnis = false, ?string $caption = null): ?FoodAlchemistRecipeStepPhoto
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return null;
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail((int) $step->ref_id);

        return app(RecipeImageService::class)->uebernimmManuellesFoto($team, $recipe, $datei, $caption, $istErgebnis);
    }

    /**
     * Foto-Wiederverwendung (Cockpit, Etappe 7 Teil 3b): ein bereits vorhandenes Team-Foto als
     * Rezept-Foto eines Rezept-/Gericht-Steps übernehmen — der Reuse-Picker-Endpunkt hinter dem
     * Service-Primitive {@see RecipeImageService::uebernimmVorhandenesFoto} (Teil 3a). Owner-Guard
     * über {@see ownedStep} (Schreiben nur durchs Besitzer-Team, D1); Ziel-Rezept UND Quell-Foto
     * werden team-scoped aufgelöst ({@see FoodAlchemistRecipeStepPhoto::visibleToTeam}) — ein fremdes
     * Quell-Foto findet der findOrFail gar nicht erst (zusätzlich zum Team-Guard im Primitive).
     * COPY-ON-REUSE (Design-Entscheid #105): die Quell-Bytes werden physisch in eine frische
     * ContextFile am Ziel kopiert, KEIN geteilter context_file_id → kein Lösch-Hazard; die Kopie
     * trägt keinen Kosten-Call-Log (überlebt den KI-Re-Trigger-Purge). `$istErgebnis` → Hero (max. 1).
     * Gibt das angelegte Foto zurück (oder null bei fremdem Step-Typ).
     */
    public function uebernimmVorhandenesFotoFuerStep(Team $team, int $stepId, int $quelleFotoId, bool $istErgebnis = false, ?string $caption = null): ?FoodAlchemistRecipeStepPhoto
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return null;
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail((int) $step->ref_id);
        $quelle = FoodAlchemistRecipeStepPhoto::visibleToTeam($team)->findOrFail($quelleFotoId);

        return app(RecipeImageService::class)->uebernimmVorhandenesFoto($team, $recipe, $quelle, $caption, $istErgebnis);
    }

    /**
     * „Neu anreichern" (Cockpit): stößt die Anreicherung eines bereits freigegebenen Rezept-/Gericht-Steps
     * erneut an — z. B. nachdem der erste EnrichRecipeJob-Lauf fehlschlug (deferred.enrich=failed).
     */
    public function reAnreichern(Team $team, int $stepId, ?bool $completeCoverageErzwingen = null): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return;
        }
        // Server-Guard gegen Doppel-Enqueue (Spec 53 / Paket C): kein zweiter Anreicherungs-Job,
        // während der erste noch läuft (sonst laufen zwei RecipeOneShotService-Pässe gegeneinander).
        $enrichStatus = is_array($step->deferred) ? ($step->deferred['enrich']['status'] ?? null) : null;
        if (in_array($enrichStatus, ['queued', 'running'], true)) {
            throw new \Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException('Anreicherung läuft bereits.');
        }
        $params = is_array($step->run?->params) ? $step->run->params : [];
        $zielVk = isset($params['ziel_vk_eur']) ? (float) $params['ziel_vk_eur'] : null;
        $kiBilder = (bool) ($params['ki_bilder'] ?? false);
        // Die Lauf-Leitplanke ist der Default — aber der Knopf „voll anreichern" an einer
        // leicht angereicherten Zeile muss sie ueberstimmen koennen. Sonst faehrt er denselben
        // flachen Pass erneut, und die Zeile bleibt ohne Schritte und Zeiten.
        $completeCoverage = $completeCoverageErzwingen
            ?? (array_key_exists('complete_coverage', $params) ? (bool) $params['complete_coverage'] : true);
        $this->markEnrichQueued($step);
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->ref_id, $zielVk, $kiBilder, (int) $step->id, false, false, $completeCoverage);
    }

    /**
     * #6/Import (Dominique 2026-08-28): ein BEREITS bestehendes (importiertes) Rezept an den Worker
     * übergeben — Voll-Anreicherung inkl. LA-First-GP-Mint der offenen Zutaten, async via {@see EnrichRecipeJob}.
     * Das Artefakt existiert schon → Run+Step werden direkt als `done` angelegt (wie ein freigegebener Draft),
     * der Enrich-Fortschritt läuft über `deferred.enrich` (queued→running→done), das die Planung schon pollt.
     * So erscheint der Import als Worker-Lauf statt synchron im Request zu minten. Sub-Rezepte laufen im
     * Hintergrund mit (stepId=null, refresh — wie {@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::allesAnreichern}).
     */
    public function enrichBestehendesRezept(
        Team $team,
        int $recipeId,
        bool $vkModus,
        ?int $planningSessionId = null,
        // Spec 50 · E-1: die zwei Regler, die der MCP-Weg braucht (Defaults = bisheriges Verhalten). `kiBilder` bleibt AUS — Bilder sind teuer.
        bool $completeCoverage = true,
        bool $kiBilder = false,
        array $leitplanken = [], bool $extern = false,   // 27.10.: Rezept-Leitplanken des Imports → Bestandssuche vor jedem GP-Mint · #1002 P2b: extern = per MCP, Jobs legen KI-Aufträge an (KI-Bilder bleiben Plattform: aus)
    ): FoodAlchemistCascadeRun {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::wennNichtEigen($recipe, $team, 'Rezept');   // Spec 84 B2: Anreicherung schreibt KI-Felder
        $kind = $vkModus ? 'gericht' : 'rezept';

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $planningSessionId,
            'scope' => $kind,
            'creative_mode' => 'voll_kreativ',
            'brief' => 'Import: ' . $recipe->name,
            'status' => 'done',                       // Artefakt existiert bereits — kein Generierungs-Schritt
            'staged' => false,
            'created_via' => 'import',
        ]);
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => $kind,
            'ref_type' => 'recipe',
            'ref_id' => $recipe->id,
            'label' => Str::limit($recipe->name, 120),
            'status' => 'done',
            'sort' => 0,
        ]);

        $this->markEnrichQueued($step);
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $recipe->id, null, $kiBilder && ! $extern, (int) $step->id, false, false, $completeCoverage, false, $leitplanken, $extern);

        // Sub-Rezepte im Hintergrund mit-anreichern (nicht am Worker-Step sichtbar, wie allesAnreichern).
        // Alle Sub-Rezepte eines Gerichts (2.1c: alle Ebenen, jedes einmal, wartende übersprungen) — viele kleine Läufe hinter EINEM
        // Klick. Eigene Schlange, damit sie den interaktiven Einzel-Anreicherungs-Klick (Rezept-/VK-Modal) nicht blockieren.
        EnrichRecipeJob::komponentenEinreihen((int) $team->id, app(\Platform\FoodAlchemist\Services\Rezept\RecipeOneShotService::class)->subRezeptIds((int) $recipe->id), true,
            fn (int $subId) => EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), $subId, null, false, null, false, true, $completeCoverage, false, $leitplanken, $extern)
                ->onQueue(Warteschlange::anreichern()));

        return $run;
    }

    /**
     * „Neu generieren" (per-Step-KI im Cockpit): verwirft das aktuelle Draft-Artefakt und stößt die
     * Generierung dieses Steps erneut an (Brief = Step-Label, Params/Session/Staged vom Lauf). Nur
     * rezept|gericht|concept. Der Step geht zurück auf `running`; die Fläche pollt wie beim Go.
     */
    public function regeneriereStep(Team $team, int $stepId, ?string $kommentar = null): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if (! in_array($step->kind, ['rezept', 'gericht', 'concept'], true)) {
            return;
        }
        // Server-Guard gegen Doppel-Enqueue (Spec 53 / Paket C): ein zweiter Klick auf „neu generieren"
        // während der erste Versuch noch rechnet, darf keinen zweiten GenerateRecipeJob einreihen.
        if ($step->status === 'running') {
            throw new \Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException('Läuft bereits. Bitte warten, bis der aktuelle Versuch fertig ist.');
        }
        // Spec 80 C1: auch die NACHPHASE zählt als „läuft". Der Step steht schon auf `done`, während
        // Regelwerk-Prüfung, Selbstheilung oder Anreicherung noch am Entwurf arbeiten. Ein Neu-Generieren
        // in diesem Fenster löschte den Entwurf unter der laufenden Prüfung weg (demo Lauf #79: 40 s
        // Prüfung auf dem gelöschten Rezept, danach löschte sie die Phase des neuen Versuchs).
        $enrich = is_array($step->deferred) ? ($step->deferred['enrich']['status'] ?? null) : null;
        if ($step->phase !== null || in_array($enrich, ['queued', 'running'], true)) {
            throw new \Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException('Prüfung oder Anreicherung läuft noch. Bitte warten, bis sie fertig ist.');
        }
        // Spec 80 C2: nur Entwürfe (done) und gescheiterte Schritte. Ein übernommenes Bestandsrezept
        // (skipped) oder ein freigegebenes Rezept darf dieser Pfad nie löschen.
        if (! in_array($step->status, ['done', 'failed'], true)) {
            return;
        }
        // L4: Regenerieren eines KIND-Basisrezepts — die Eltern-Zutat zeigt noch auf den gleich
        // gelöschten Draft. VOR dem Löschen die Bindung lösen (referenced_recipe_id NULL, unmatched),
        // die Dependency aber BEHALTEN, damit der neue Lauf via bindCompletedChild sauber neu bindet.
        // Sonst bliebe die Eltern-Zutat auf ein gelöschtes Rezept verdrahtet (tote Referenz).
        $elternRezeptIds = [];
        if ($step->ref_type === 'recipe' && $step->ref_id !== null) {
            $deps = FoodAlchemistCascadeRecipeDependency::where('cascade_run_id', $step->cascade_run_id)
                ->where('child_step_id', (int) $step->id)->get();
            foreach ($deps as $dep) {
                $zutat = FoodAlchemistRecipeIngredient::where('team_id', $team->id)->find((int) $dep->ingredient_id);
                if ($zutat !== null && (int) $zutat->referenced_recipe_id === (int) $step->ref_id) {
                    $elternRezeptIds[(int) $zutat->recipe_id] = true;
                    $zutat->update(['referenced_recipe_id' => null, 'match_method' => 'unmatched', 'match_confidence' => null]);
                }
            }
        }
        if ($step->ref_id !== null) {
            if ($step->ref_type === 'recipe') {
                FoodAlchemistRecipe::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            } elseif ($step->ref_type === 'concept') {
                FoodAlchemistConcept::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            }
        }
        // Eltern-Rezepte nach dem Lösen neu rechnen (EK/Allergene ohne die tote Zeile).
        foreach (array_keys($elternRezeptIds) as $rid) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate((int) $rid);
        }
        // Die geplanten/übernommenen Sub-Rezepte beschreiben die Zerlegung des ALTEN Entwurfs — der
        // neue Lauf plant seine eigenen (sonst bleiben Zeilen stehen, die zu nichts mehr gehören).
        $this->raeumeGeplanteKinder($step);
        $step->update(['status' => 'running', 'ref_type' => null, 'ref_id' => null, 'error' => null, 'deferred' => null, 'phase' => null, 'phase_at' => null]);
        $run = $step->run;
        // L5: Wurzel-Step (Gericht/Concept) neu erzeugen aus dem VOLLEN Run-Brief — nicht aus dem Label,
        // das markStepDone inzwischen auf den (kurzen) Artefakt-Namen gezogen hat (sonst schrumpfte das
        // Briefing bei jedem „neu erzeugen"). Kind-Steps behalten ihr Label (= der Sub-Rezept-Name/Brief).
        $brief = $step->parent_step_id === null && trim((string) ($run?->brief ?? '')) !== ''
            ? (string) $run->brief
            : (string) ($step->label ?? '');
        // A2 (per-Speise-Feedback): ein gezielter Nutzer-Kommentar zu GENAU dieser Position wird als
        // Zusatz-Direktive an den Brief gehängt — die Regeneration baut nur diesen einen Entwurf nach
        // dem Feedback neu (die Nachbar-Positionen bleiben unberührt, weil regeneriereStep step-lokal ist).
        $kommentar = trim((string) $kommentar);
        if ($kommentar !== '') {
            $brief = rtrim($brief) . "\n\nGezielte Anpassung (Nutzer-Feedback zu dieser Position): " . $kommentar;
        }
        $params = is_array($run?->params) ? $run->params : [];
        $sessionId = $run?->planning_session_id !== null ? (int) $run->planning_session_id : null;
        $staged = (bool) ($run?->staged ?? false);
        if ($step->kind === 'gericht' && $step->parent_step_id === null && $staged && $sessionId !== null
            && (bool) ($params['proposal_first'] ?? false)) {
            // Ein fehlgeschlagener direkter Gerichtsvorschlag muss beim Resume wieder auf dieselbe
            // textliche Gate-Stufe gehen. Der generische Rezeptpfad wuerde sonst das Proposal-Gate
            // umgehen und sofort einen echten Gericht-Draft erzeugen.
            //
            // Die vierte Klausel (`params.proposal_first`) ist 2026-09-03 nachgezogen: vorher hatte
            // dieser Zweig nur DREI Klauseln, der Go-Pfad aber VIER — er konnte den Entscheid nicht
            // rekonstruieren, weil er nirgends persistiert war. Der zu breite Proxy leitete jeden
            // gestuften Wurzel-gericht-Lauf mit Session ins Text-Gate um, auch Batch-Skizze und
            // MCP-Start. Jetzt ist genau EIN Gate je Lauf gesetzt, egal wie oft man fortsetzt.
            GenerateDishProposalJob::dispatch(
                $team->id,
                (int) (Auth::id() ?? 0),
                $sessionId,
                (int) $step->id,
                $brief,
                (string) ($run?->creative_mode ?? 'voll_kreativ'),
            );
        } elseif ($step->kind === 'concept') {
            // $params durchreichen — sonst verliert das „neu generieren" eines Concept-Steps SÄMTLICHE
            // Menü-Leitplanken (Gänge/Preis-Korridor/Quoten/Balance/Buffet), weil dispatchConceptStep
            // die menue_*-Teilmenge aus den Run-Params filtert (Default [] = kein Leitplanken-Erbe).
            // S3a: Attach-Args mitgeben, sonst dockt ein neu generiertes Kapitel-Concept NICHT mehr ans
            // Kapitel (stiller Datenverlust) — der Kapitel-Bezug steckt am Step (chapter_id).
            $attachOwnerType = ($step->chapter_id !== null && $run?->source_owner_type === 'foodbook') ? 'foodbook' : null;
            $attachContainerId = $attachOwnerType !== null ? (int) $step->chapter_id : null;
            $this->kontext()->dispatchConceptStep($team, $step, $brief, $sessionId, (string) ($run?->creative_mode ?? 'voll_kreativ'), $params, $attachOwnerType, $attachContainerId);
        } elseif ($step->kind === 'rezept' && $step->parent_step_id !== null) {
            // Kind-Basisrezept: wie beim ersten Start (eigene Vorgaben, eigener Plan) — nicht mit den Lauf-Params
            // der Wurzel, sonst kam z. B. das Aroma des Gerichts zurück. Ein Nutzer-Kommentar reist im Brief mit.
            app(RecipeDependencyWorkflowService::class)->starteKindNeu($team, $step->fresh(), $kommentar);
        } elseif ($step->kind === 'rezept' && $step->parent_step_id === null && (bool) ($params['plan_first'] ?? false)
            && empty(($step->context_snapshot ?? [])['plan'])) {
            // Spec 80 B1: scheiterte schon der Plan, wird neu geplant, nicht ohne Plan gebaut.
            \Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->id, $brief, $params);
        } else {
            // Spec 80 B5: ein bestätigter Plan gilt auch für den neuen Versuch.
            $planKomponenten = ($step->context_snapshot ?? [])['komponenten'] ?? null;
            if ($step->kind === 'rezept' && ! empty(($step->context_snapshot ?? [])['plan']) && is_array($planKomponenten)) {
                $params['plan_komponenten'] = $planKomponenten;
            }
            $this->kontext()->dispatchRezeptStep($team, $step, $brief, $params, $step->kind === 'gericht', false, $sessionId, $staged);
        }
        $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Spec 80 D7: Plan-Karte — den Komponenten-Plan eines noch offenen Basisrezept-Steps ändern, bevor gebaut
     * wird. Aktionen: entfernen · menge · bestand_ablehnen · hinzufuegen. Nur team-eigen, nur Status `geplant`.
     *
     * @param  array<string, mixed>  $daten
     */
    public function aenderePlan(Team $team, int $stepId, string $aktion, array $daten = []): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        $snap = is_array($step->context_snapshot) ? $step->context_snapshot : [];
        if ($step->status !== 'geplant' || empty($snap['plan'])) {
            throw new RuntimeException('Der Plan kann nur vor dem Bau geändert werden.');
        }
        $k = array_values((array) ($snap['komponenten'] ?? []));
        $i = (int) ($daten['index'] ?? -1);
        switch ($aktion) {
            case 'entfernen':
                if (isset($k[$i])) {
                    array_splice($k, $i, 1);
                }
                break;
            case 'menge':
                $menge = str_replace(',', '.', trim((string) ($daten['menge'] ?? '')));
                if (isset($k[$i]) && is_numeric($menge) && (float) $menge > 0) {
                    $k[$i]['menge'] = (float) $menge;
                }
                break;
            case 'bestand_ablehnen':
                if (isset($k[$i]) && ! empty($k[$i]['bestand'])) {
                    $k[$i]['abgelehnt'] = [...(array) ($k[$i]['abgelehnt'] ?? []),
                        ['recipe_id' => (int) $k[$i]['bestand']['recipe_id'], 'name' => (string) $k[$i]['bestand']['name'], 'grund' => 'vom Menschen abgelehnt']];
                    $k[$i]['bestand'] = null;
                    $k[$i]['neu'] = true;
                }
                break;
            case 'hinzufuegen':
                $name = trim((string) ($daten['name'] ?? ''));
                if ($name === '' || count($k) >= RecipeKomponentenPlanService::MAX_KOMPONENTEN + 2) {
                    break;
                }
                $menge = str_replace(',', '.', trim((string) ($daten['menge'] ?? '')));
                $abgelehnt = [];
                $params = is_array($step->run?->params) ? $step->run->params : [];
                $bestand = ($params['bestand'] ?? 'hybrid') !== 'komplett_neu'
                    ? app(RecipeKomponentenPlanService::class)->bestandFuer($team, $name,
                        array_values(array_filter((array) ($params['diaet_hart'] ?? []), 'is_string')), $abgelehnt, $params['convenience'] ?? null)
                    : null;
                $k[] = ['name' => $name, 'funktion' => null, 'menge' => is_numeric($menge) ? (float) $menge : null,
                    'einheit' => 'g', 'suchbegriffe' => [], 'bestand' => $bestand, 'abgelehnt' => $abgelehnt, 'neu' => $bestand === null];
                break;
            default:
                throw new RuntimeException("Unbekannte Plan-Aktion [{$aktion}].");
        }
        $step->update(['context_snapshot' => [...$snap, 'komponenten' => $k]]);
    }

    /**
     * Spec 80 B1: nach bestätigtem (oder entfallenem) Komponenten-Plan das Basisrezept bauen. Leere Liste =
     * ein Baustein, Bau wie bisher.
     *
     * @param  list<array<string, mixed>>  $komponenten
     */
    public function starteRezeptNachPlan(Team $team, int $stepId, array $komponenten): void
    {
        $step = FoodAlchemistCascadeRunStep::where('team_id', $team->id)->find($stepId);
        $run = $step?->run;
        if ($step === null || $run === null || $this->schritt()->istAbgebrochen((int) $run->id)) {
            return;
        }
        $params = is_array($run->params) ? $run->params : [];
        if ($komponenten !== []) {
            $params['plan_komponenten'] = array_values($komponenten);
        }
        $step->update(['status' => 'running', 'error' => null]);
        $run->update(['status' => 'running']);
        $this->kontext()->dispatchRezeptStep($team, $step, (string) $run->brief, $params, false, false,
            $run->planning_session_id !== null ? (int) $run->planning_session_id : null, (bool) $run->staged);
        $this->schritt()->recomputeRunStatus((int) $run->id);
    }

    /**
     * Step verwerfen: das Draft-Artefakt soft-deleten (kein DB-Müll), Step → `verworfen`, Run neu
     * bewerten. Greift bei `done` (generiert) und `failed` (Teil-Wrack aufräumen).
     */
    public function verwirfStep(Team $team, int $stepId): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if (! in_array($step->status, ['done', 'failed'], true)) {
            return;
        }
        if ($step->ref_id !== null) {
            if ($step->ref_type === 'recipe') {
                FoodAlchemistRecipe::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            } elseif ($step->ref_type === 'concept') {
                FoodAlchemistConcept::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            }
        }
        $this->raeumeGeplanteKinder($step);
        $step->update(['status' => 'verworfen']);
        $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Räumt die `geplant`/`skipped`-Kinder eines Steps weg (Verwerfen / Neu-Generieren): sie
     * beschreiben die Zerlegung eines Entwurfs, den es nicht mehr gibt. Es werden AUSSCHLIESSLICH
     * die Step-Zeilen entfernt — das bei `skipped` referenzierte Bestands-Rezept bleibt unangetastet
     * (es ist fremdes, lebendes Artefakt, kein Draft dieses Laufs).
     *
     * Hart gelöscht (nicht soft), weil der Unique-Index (cascade_run_id, dedupe_key) soft-gelöschte
     * Zeilen mitzählt — eine soft-gelöschte Planung würde die Neu-Planung desselben Sub-Rezepts
     * blockieren. Verlorene Historie: keine, diese Zeilen haben nie etwas erzeugt.
     */
    private function raeumeGeplanteKinder(FoodAlchemistCascadeRunStep $step): void
    {
        $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)
            ->whereIn('status', ['geplant', 'skipped'])->get(['id']);
        if ($kinder->isEmpty()) {
            return;
        }
        $ids = $kinder->pluck('id')->all();
        FoodAlchemistCascadeRecipeDependency::whereIn('child_step_id', $ids)->delete();
        FoodAlchemistCascadeRunStep::whereIn('id', $ids)->forceDelete();
    }

    /**
     * „Jetzt erzeugen" je Zeile (Etappe 1, Teil 2): schaltet EINEN geplanten Sub-Rezept-Step scharf,
     * OHNE auf die Freigabe der Stufe darüber zu warten — der Mensch kann ein einzelnes Sub-Rezept
     * vorziehen. Nur `geplant`-Steps; die spätere Stufen-Freigabe dispatcht ihn nicht doppelt
     * ({@see RecipeDependencyWorkflowService::dispatchGeplantesKind}).
     */
    public function erzeugeGeplantenStep(Team $team, int $stepId): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        // Spec 80 B1: bestätigter Komponenten-Plan eines Basisrezepts → bauen.
        if ($step->kind === 'rezept' && $step->parent_step_id === null && $step->status === 'geplant'
            && ! empty(($step->context_snapshot ?? [])['plan'])) {
            $this->starteRezeptNachPlan($team, $stepId, (array) (($step->context_snapshot ?? [])['komponenten'] ?? []));

            return;
        }
        $ideeId = (int) (($step->context_snapshot ?? [])['dish_idea_id'] ?? 0);
        if ($step->kind === 'gericht' && $step->status === 'geplant' && $ideeId > 0) {
            if ($this->schritt()->istAbgebrochen((int) $step->cascade_run_id)) {
                return;
            }
            $idee = FoodAlchemistDishIdea::where('team_id', $team->id)->find($ideeId);
            if ($idee === null) {
                $this->schritt()->markStepFailed($stepId, 'Gerichtsvorschlag nicht gefunden.');
                return;
            }
            $idee->update(['generation_status' => 'queued']);
            $step->update(['status' => 'running', 'error' => null]);
            MaterializeConceptIdeaJob::dispatch(
                $team->id,
                (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
                $ideeId,
                (int) $step->id,
                $step->run?->planning_session_id !== null ? (int) $step->run->planning_session_id : null,
            );
            $step->run?->update(['status' => 'running']);
            return;
        }
        if (app(RecipeDependencyWorkflowService::class)->dispatchGeplantesKind($team, $step)) {
            $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);
        }
    }

    /** Überarbeitet einen geplanten Gericht-Bauplan, ohne ihn zu materialisieren. */
    public function ueberarbeiteGerichtVorschlag(Team $team, int $stepId, string $feedback): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        $ideeId = (int) (($step->context_snapshot ?? [])['dish_idea_id'] ?? 0);
        $feedback = trim($feedback);
        if ($step->kind !== 'gericht' || $step->status !== 'geplant' || $ideeId <= 0 || $feedback === '') {
            throw new RuntimeException('Überarbeiten geht nur bei einem noch offenen Gerichtsvorschlag und mit einer Rückmeldung. Bitte eine Rückmeldung eingeben.');
        }
        $step->update(['status' => 'running', 'error' => null]);
        $step->run?->update(['status' => 'running']);
        ReviseDishProposalJob::dispatch($team->id, (int) (Auth::id() ?? 0), $stepId, $ideeId, $feedback);
    }

    /**
     * Kapitel-Gate (gestufte Foodbook-Vollkaskade): einen GEPLANTEN Kapitel-Concept-Step scharfschalten —
     * die Kapitel-Freigabe startet die Concept-Erzeugung (dispatcht den {@see GenerateConceptJob}). Analog
     * {@see erzeugeGeplantenStep} für Rezepte. Rekonstruiert Owner/Container/Modus/Session aus dem Lauf und
     * den Slot aus `slot_id`. Fehlt der Slot (Kapitel gelöscht) → Step verwerfen statt ins Leere dispatchen.
     * Wird auch von {@see gibStepFrei} aufgerufen (FREIGABE eines geplanten Kapitels = erzeugen).
     */
    public function erzeugeGeplantesConcept(Team $team, int $stepId): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->kind !== 'concept' || $step->status !== 'geplant') {
            return;
        }
        $run = $step->run;
        if ($run === null) {
            return;
        }
        $ownerType = (string) $run->source_owner_type;
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::find((int) $step->slot_id);
        if ($slot === null) {
            // Kapitel/Slot entfernt → kein Concept erzeugen, Step verwerfen (Tombstone).
            $step->update(['status' => 'verworfen']);
            $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);

            return;
        }
        $containerId = $ownerType === 'foodbook' ? (int) $step->chapter_id : (int) $run->source_owner_id;
        $this->ausgabe()->dispatcheConceptStep(
            $team, $run, $step, $slot, $ownerType, $containerId,
            (string) $run->creative_mode,
            $run->planning_session_id !== null ? (int) $run->planning_session_id : null
        );
        $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Kapitel-Reconcile (Refinement zum Kapitel-Gate): gleicht die GEPLANTEN Kapitel-Concept-Steps eines
     * gestuften Foodbook-Laufs mit der AKTUELLEN Kapitel-Struktur ab — nachdem der Mensch VOR der Freigabe
     * Kapitel editiert hat (foodbook_kapitel.POST/PUT/DELETE). Ohne diesen Abgleich sind Slot/Kapitel-CRUD
     * und die geplanten Steps entkoppelt (ein gelöschtes Kapitel würde trotzdem generiert, ein neues gar nicht).
     *
     * Drei Pässe, nur bestehende Bausteine: (1) ADD — jedes aktive Kapitel OHNE gekoppelten Frame-Slot bekommt
     * einen kapitel-Slot (so wird ein von Hand angelegtes Kapitel generierbar); (2) je Slot mit aktivem Kapitel
     * einen geplanten Step sicherstellen + Label auf den Kapitel-Titel ziehen (Rename); (3) REMOVE — geplanter
     * Step, dessen Slot weg ODER Kapitel soft-deleted ist → `verworfen` (Tombstone, kein Hard-Delete). Rührt NUR
     * `geplant`-Steps an (bereits erzeugte Concepts bleiben unberührt — die verwirft der Mensch separat).
     * Nur gestufte, team-eigene Foodbook-Läufe. Idempotent.
     *
     * @return array{ok: bool, grund?: string, ergaenzt?: int, verworfen?: int, umbenannt?: int}
     */
    public function synchronisiereKapitelSteps(Team $team, int $runId): array
    {
        $run = $this->kontext()->lauf($team, $runId);
        if ($run === null || ! $run->isOwnedBy($team) || (string) $run->source_owner_type !== 'foodbook' || ! $run->staged) {
            return ['ok' => false, 'grund' => 'Nur gestufte, team-eigene Foodbook-Läufe.'];
        }
        $foodbookId = (int) $run->source_owner_id;
        $fb = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->find($foodbookId);
        $frame = app(PlanningFrameService::class)->find('foodbook', $foodbookId);
        if ($fb === null || $frame === null) {
            return ['ok' => false, 'grund' => 'Foodbook/Gerüst nicht gefunden.'];
        }
        $frame->load('slots');
        $kapitel = $fb->chapters()->get();   // aktive (nicht soft-deleted), position-sortiert
        $aktiveKapitelIds = $kapitel->pluck('id')->map(fn ($v) => (int) $v)->all();

        // 1. ADD (Hand-Kapitel generierbar machen): jedes aktive Kapitel ohne gekoppelten Slot bekommt einen kapitel-Slot.
        $gekoppelt = $frame->slots->pluck('chapter_id')->filter()->map(fn ($v) => (int) $v)->all();
        foreach ($kapitel as $k) {
            if (! in_array((int) $k->id, $gekoppelt, true)) {
                app(PlanningFrameService::class)->addSlot($team, $frame, ['label' => (string) $k->title, 'slot_type' => 'kapitel', 'chapter_id' => (int) $k->id]);
            }
        }
        $frame->load('slots');

        $steps = $run->steps()->where('kind', 'concept')->get();
        $stepBySlot = $steps->keyBy(fn ($s) => (int) $s->slot_id);
        $ergaenzt = 0;
        $verworfen = 0;
        $umbenannt = 0;
        $idx = (int) ($steps->max('sort') ?? -1);

        // 2. je Slot mit aktivem Kapitel einen geplanten Step sicherstellen + Label pflegen (Rename).
        foreach ($frame->slots as $slot) {
            $chapterId = $slot->chapter_id !== null ? (int) $slot->chapter_id : null;
            if ($chapterId === null || ! in_array($chapterId, $aktiveKapitelIds, true)) {
                continue;   // Slot ohne aktives Kapitel → REMOVE-Pass
            }
            $titel = (string) ($kapitel->firstWhere('id', $chapterId)?->title ?: $slot->label);
            $st = $stepBySlot->get((int) $slot->id);
            if ($st === null) {
                $this->ausgabe()->planeSlotConcept($team, $run, $slot, 'foodbook', $chapterId, ++$idx, 'geplant');
                $ergaenzt++;
            } elseif ($st->status === 'geplant') {
                $neuLabel = Str::limit($titel, 120);
                if ((string) $st->label !== $neuLabel) {
                    $st->update(['label' => $neuLabel]);
                    $umbenannt++;
                }
            }
        }

        // 3. REMOVE: geplanter Step, dessen Slot weg ODER Kapitel soft-deleted → verworfen (Tombstone).
        $slotIds = $frame->slots->pluck('id')->map(fn ($v) => (int) $v)->all();
        foreach ($steps as $st) {
            if ($st->status !== 'geplant') {
                continue;
            }
            $slotWeg = ! in_array((int) $st->slot_id, $slotIds, true);
            $kapitelWeg = $st->chapter_id !== null && ! in_array((int) $st->chapter_id, $aktiveKapitelIds, true);
            if ($slotWeg || $kapitelWeg) {
                $st->update(['status' => 'verworfen']);
                $verworfen++;
            }
        }

        $this->schritt()->recomputeRunStatus((int) $run->id);

        return ['ok' => true, 'ergaenzt' => $ergaenzt, 'verworfen' => $verworfen, 'umbenannt' => $umbenannt];
    }

    /**
     * „Brauche ich nicht" je Zeile (Etappe 1, Teil 2): verwirft EINEN geplanten Sub-Rezept-Step vor
     * seiner Erzeugung. Der Step wird als `verworfen` behalten (Tombstone), NICHT hart gelöscht — so
     * schaltet die spätere Stufen-Freigabe ({@see RecipeDependencyWorkflowService::dispatchChildren})
     * ihn über den `dedupe_key`-Treffer bewusst nicht scharf (Status ≠ `geplant` → übersprungen);
     * die Eltern-Zutat bleibt als flache Zeile stehen. Die Dependency wird entfernt (kein Late-Bind).
     */
    public function verwirfGeplantenStep(Team $team, int $stepId): void
    {
        $step = $this->kontext()->ownedStep($team, $stepId);
        if ($step->status !== 'geplant') {
            return;
        }
        FoodAlchemistCascadeRecipeDependency::where('child_step_id', $step->id)->delete();
        $ideeId = (int) (($step->context_snapshot ?? [])['dish_idea_id'] ?? 0);
        if ($ideeId > 0) {
            FoodAlchemistDishIdea::where('team_id', $team->id)->whereKey($ideeId)->update(['status' => 'verworfen']);
        }
        $step->update(['status' => 'verworfen']);
        $this->schritt()->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Manuell ein Basisrezept in die Basisrezepte-Stufe ergänzen (T2, Real-Abnahme): ein Sub-Rezept,
     * das die KI nicht als Komponente erkannt hat (z. B. ein fehlender Jus), wird von Hand nachgezogen.
     * Legt einen `geplant`-Sub-Step (kind=rezept) unter dem Wurzel-Step des Laufs an — genau die Form,
     * die {@see erzeugeGeplantenStep}/{@see RecipeDependencyWorkflowService::dispatchGeplantesKind} kennt
     * (Brief=label, Params aus dem Eltern-`deferred.children` bzw. den Lauf-Params). Der Nutzer erzeugt
     * ihn danach je Zeile mit „jetzt erzeugen". Team-owned (D1). Idempotent über `dedupe_key` (`manual:`+
     * Name) — dasselbe Basisrezept doppelt ergänzt teilt sich einen Step.
     */
    public function ergaenzeManuellenSubStep(Team $team, int $runId, string $name, ?int $parentStepId = null): ?FoodAlchemistCascadeRunStep
    {
        $run = $this->kontext()->lauf($team, $runId);
        if ($run === null || ! $run->isOwnedBy($team)) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        // Anker-Step: explizit gewählt (Gericht/Rezept-Step des Laufs) oder der Wurzel-Step als Default.
        // So kann ein manuelles Basisrezept gezielt UNTER dem richtigen Gericht hängen (Concept-Fan-out).
        $anker = null;
        if ($parentStepId !== null) {
            $anker = $run->steps->first(fn ($s) => (int) $s->id === $parentStepId
                && in_array($s->kind, ['gericht', 'rezept'], true));
        }
        $anker ??= $run->steps->first(fn ($s) => $s->parent_step_id === null) ?? $run->steps->first();
        if ($anker === null) {
            return null;
        }
        $dedupe = 'manual:' . mb_strtolower($name);
        $existing = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('dedupe_key', $dedupe)->first();
        if ($existing !== null) {
            return $existing;   // schon ergänzt → idempotent
        }
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => (int) $run->id,
            'parent_step_id' => (int) $anker->id,
            'depth' => ((int) $anker->depth) + 1,
            'kind' => 'rezept',
            'label' => Str::limit($name, 120, ''),
            'dedupe_key' => $dedupe,
            'status' => 'geplant',
            'sort' => (int) ($run->steps->max('sort') ?? 0) + 1,
        ]);
        // L4: Rückbindung herstellen — eine Eltern-Zutatenzeile (unmatched, Platzhalter) am Anker-Rezept
        // + die Dependency auf den neuen Kind-Step. OHNE das wird das später erzeugte Basisrezept NIE ans
        // Elterngericht gebunden (bekannte Wunde: referenced_recipe_id blieb NULL). Fail-soft: steht das
        // Anker-Rezept noch nicht (ref_id NULL), bleibt es beim reinen Step (alt), keine Rückbindung möglich.
        if ($anker->ref_type === 'recipe' && $anker->ref_id !== null) {
            $parentRecipe = FoodAlchemistRecipe::where('team_id', $team->id)->find((int) $anker->ref_id);
            if ($parentRecipe !== null) {
                try {
                    $einheitId = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->value('id')
                        ?? FoodAlchemistVocabEinheit::visibleToTeam($team)->orderBy('id')->value('id');
                    $zutat = $parentRecipe->ingredients()->create([
                        'team_id' => $team->id,
                        'raw_text' => $name,
                        'display_name' => $name,
                        'quantity' => 0,   // Platzhalter — der Mensch trägt die Menge nach
                        'unit_vocab_id' => $einheitId,
                        'position' => (int) ($parentRecipe->ingredients()->max('position') ?? 0) + 1,
                        'match_method' => 'unmatched',
                        'auto_ground' => false,
                    ]);
                    FoodAlchemistCascadeRecipeDependency::firstOrCreate([
                        'team_id' => $team->id,
                        'cascade_run_id' => (int) $run->id,
                        'parent_step_id' => (int) $anker->id,
                        'ingredient_id' => (int) $zutat->id,
                    ], ['child_step_id' => (int) $step->id]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[Planung] manuelles Sub-Rezept ohne Rückbindung angelegt (Zutat/Dependency übersprungen)', ['error' => $e->getMessage()]);
                    // D2 2026-08-18: nicht mehr NUR ins Log — sichtbar am Step markieren, damit der Mensch
                    // die fehlende Rückbindung sieht (sonst hängt das Sub-Rezept unbemerkt lose am Lauf,
                    // referenced_recipe_id bleibt NULL). Spiegelt den E-P0-Härtungsgrundsatz „nichts still schlucken".
                    $step->update(['error' => 'Nicht automatisch ins Gericht übernommen. Zutat und Menge bitte im übergeordneten Gericht von Hand ergänzen.']);
                }
            }
        }
        $this->schritt()->recomputeRunStatus((int) $run->id);

        return $step;
    }

    /**
     * Spec 80 H3: „Alle neu gebauten anreichern und freigeben" — nur fertige Entwürfe (done) ohne offenen harten
     * Regelwerk-Befund, optional nur in einer Auswahl (Baum-Zweig). Übernahmen aus dem Bestand sind schon
     * freigegeben und zählen nicht.
     *
     * @param  list<int>|null  $nurSteps
     * @return array{freigegeben: int, uebersprungen: int}
     */
    public function gibNeueFrei(Team $team, int $runId, ?array $nurSteps = null): array
    {
        $run = $this->kontext()->lauf($team, $runId);
        if ($run === null || ! $run->isOwnedBy($team)) {
            return ['freigegeben' => 0, 'uebersprungen' => 0];
        }
        $kandidaten = $run->steps->where('status', 'done')
            ->filter(fn ($s) => $nurSteps === null || in_array((int) $s->id, $nurSteps, true));
        $rezeptIds = $kandidaten->where('ref_type', 'recipe')->pluck('ref_id')->filter()->map(fn ($i) => (int) $i)->all();
        $hart = $rezeptIds === [] ? [] : array_flip(\Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding::query()
            ->where('team_id', $team->id)->where('artifact_type', 'recipe')->whereIn('artifact_id', $rezeptIds)
            ->where('status', 'offen')->where('schweregrad', 'hart')->pluck('artifact_id')->map(fn ($i) => (int) $i)->all());
        $frei = 0;
        $weg = 0;
        foreach ($kandidaten as $s) {
            if ($s->ref_type === 'recipe' && isset($hart[(int) $s->ref_id])) {
                $weg++;

                continue;
            }
            $this->gibStepFrei($team, (int) $s->id);
            $frei++;
        }

        return ['freigegeben' => $frei, 'uebersprungen' => $weg];
    }

    /** Bulk-Verwerfen aller noch offenen (done|failed) Steps eines Laufs. */
    public function verwirfRun(Team $team, int $runId): void
    {
        $run = $this->kontext()->lauf($team, $runId);
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op (siehe gibStufeFrei).
        if ($run === null || ! $run->isOwnedBy($team)) {
            return;
        }
        foreach ($run->steps->whereIn('status', ['done', 'failed']) as $s) {
            $this->verwirfStep($team, (int) $s->id);
        }
    }

    private function schritt(): KaskadenSchritt
    {
        return app(KaskadenSchritt::class);
    }

    private function kontext(): KaskadeKontext
    {
        return app(KaskadeKontext::class);
    }

    private function ausgabe(): KaskadeAusgabeVoll
    {
        return app(KaskadeAusgabeVoll::class);
    }
}
