<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\ConceptVorgabenService;
use Platform\FoodAlchemist\Services\GerichtHuelleService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use RuntimeException;

/**
 * Plan 98 · 3.3 S1 · P1b Erfinden, verschoben aus {@see PlanningCascadeService} (reine Verschiebung): Fan-out eines Concepts in
 * erfundene Gerichte ({@see fanoutConceptInvention}, Worker {@see materialisiereConceptGericht}) und das Kohärenz-Gate danach
 * ({@see scoreConceptCohesionIfComplete}). Step-Status über {@see KaskadenSchritt}, gemeinsame Helfer über {@see KaskadeKontext}.
 * Jobs, Tools und Tests rufen weiter die Fassade.
 */
class KaskadeErfinden
{
    /** Deckel gegen Runaway-Kosten: max. leere Slots (= erfundene KI-Gerichte) je Concept-Fan-out. */
    private const CONCEPT_MAX_SLOTS = 30;

    /**
     * Fächert ein frisch erzeugtes Konzept in erfundene Gerichte auf (Erfinden-Modus). Je LEEREM Slot
     * (kein Gericht, kein Paket) lässt die KI eine Gericht-Idee erfinden ({@see IdeenService::kiDivergenzConcept},
     * EIN Call für alle Slots), ordnet Ideen den Slots der Reihe nach zu, legt je Idee einen Kind-Step
     * (kind=gericht, parent=Concept-Step) im Status `geplant` an.
     *
     * VORSCHLAG-GATE (2026-09-01): hier wird NICHTS mehr dispatcht. {@see MaterializeConceptIdeaJob}
     * (erdet + verdrahtet) startet erst der Mensch über {@see PlanningCascadeService::erzeugeGeplantenStep}. Dieser Satz stand
     * bis 2026-09-03 im Gegenteil hier und hat eine Fehldiagnose erzeugt („die Paket-Slot-Erkennung ist
     * verloren gegangen") — ein Docblock, der dem Code 60 Zeilen darunter widerspricht, ist Teil des Bugs.
     *
     * ZWEI Grenzen wirken hier hintereinander, und die zweite ist meist die WIRKSAME:
     * {@see CONCEPT_MAX_SLOTS} (30) bestimmt, nach wie vielen Positionen überhaupt gefragt wird,
     * und {@see IdeenService::IDEEN_MAX} (12), wie viele Skizzen eine Antwort liefert. Bei 25
     * leeren Positionen greift also nicht der 30er, sondern die 12er-Klemme — eine Meldung über
     * den 30er wäre irreführend. Beide werden darum als EIN Befund vermerkt
     * ({@see vermerkeConceptLuecke}), dessen Satz sich in jedem Fall aufrechnet.
     *
     * Graceful: ohne LLM (Sandbox/Kill-Switch) wirft die Divergenz → 0 Ideen, 0 Kind-Steps; der Run geht
     * mit dem Konzept allein auf review. Wirft NIE (der Concept-Job fängt zusätzlich ab).
     */
    public function fanoutConceptInvention(Team $team, int $conceptStepId, int $conceptId, string $mode, ?int $planningSessionId = null, array $seen = []): void
    {
        $conceptStep = FoodAlchemistCascadeRunStep::find($conceptStepId);
        if ($conceptStep === null) {
            return;
        }
        if (in_array($conceptId, $seen, true)) {
            return;   // A1: Zyklus-Schutz für die Paket-Rekursion (bei generierten Pakets nie erreicht)
        }
        $seen[] = $conceptId;
        $runId = (int) $conceptStep->cascade_run_id;

        // Leere Gericht-Slots (Reuse hat noch nicht gefüllt) — OHNE die eingebetteten Paket-Slots
        // (type=paket, embedded_concept_id): ein Paket-Slot ist kein Gericht zum Erfinden, sondern ein
        // Bündel (Station), dessen INNERE Gerichte weiter unten rekursiv gefächert werden. (Früher
        // Latenz-Bug: embed-Paket-Slots galten fälschlich als leer.)
        // Gericht-Vorgaben (13.11.2026): Positionen MIT Vorgabe bekommen ihre Skizze 1:1, erfunden wird nur für den Rest.
        $leere = app(ConceptVorgabenService::class)->planeVorgaben($team, $conceptStep, $conceptId, FoodAlchemistConceptSlot::where('concept_id', $conceptId)
            ->whereNull('sales_recipe_id')
            ->whereNull('package_id')
            ->whereNull('embedded_concept_id')
            ->whereNotIn('type', ['text', 'spacer', 'header', 'header_preis'])
            ->orderBy('position')->orderBy('id')
            ->get());

        if ($leere->isNotEmpty()) {
            // Deckel gegen Runaway-/Kosten-Risiko bei großem Menü-Brief (analog SPEISEPLAN_MAX_WOCHEN):
            // wir fragen die KI gar nicht erst nach mehr als N Ideen. Der Vermerk geht unten in
            // `deckel_hinweise` — vorher stand er in `params.gedeckelt_slots_offen`, wo ihn niemand
            // las (die Status-DTO filtert `params` gegen ALLOWED_GENERATION_PARAMS).
            // `$slotsLeer` VOR dem Deckel merken — das ist die Zahl, die der Mensch verlangt hat.
            $slotsLeer = $leere->count();
            if ($slotsLeer > self::CONCEPT_MAX_SLOTS) {
                $leere = $leere->take(self::CONCEPT_MAX_SLOTS);
            }

            $ideen = [];
            $div = null;   // muss VOR dem try stehen — im Fehlerfall wäre es sonst undefiniert
            // Anlass (Lauf 72, 2026-09-17): ein gescheiterter kiDivergenzConcept-Call verschwand
            // hier bisher spurlos (nackter catch, kein Log) — der Run meldete "die KI hat keine
            // geliefert", obwohl sie nie gefragt wurde (z. B. KnowledgeBudgetExceeded). $ideenFehler
            // trägt den ECHTEN Grund an vermerkeConceptLuecke weiter, geloggt wird IMMER.
            $ideenFehler = null;
            try {
                // Wissen+Trend fließen in die Divergenz (voller Stack + generischer Trend + Ursprungs-Trend der Planung).
                $div = app(IdeenService::class)->kiDivergenzConcept($team, $conceptId, $leere->count(), null, $planningSessionId);
                $ideen = is_array($div['angelegt'] ?? null) ? $div['angelegt'] : [];
            } catch (\Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded $e) {
                $ideen = [];
                $ideenFehler = 'Wissensbudget überschritten (' . $e->getMessage() . ')';
                \Illuminate\Support\Facades\Log::warning('[fanoutConceptInvention] kiDivergenzConcept: Wissensbudget überschritten', [
                    'concept_id' => $conceptId, 'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // KI nicht verfügbar/Fehler → keine Erfindung für die direkten Slots (graceful); Pakete
                // werden dennoch versucht. Graceful heißt aber nicht stumm — geloggt wird trotzdem.
                $ideen = [];
                $ideenFehler = 'Ideen-Aufruf fehlgeschlagen (' . get_class($e) . ': ' . $e->getMessage() . ')';
                \Illuminate\Support\Facades\Log::warning('[fanoutConceptInvention] kiDivergenzConcept fehlgeschlagen', [
                    'concept_id' => $conceptId, 'exception' => get_class($e), 'error' => $e->getMessage(),
                ]);
            }

            // EIN Befund für diesen Konzept-Durchgang, statt getrennter Vermerke für den
            // Slot-Deckel und die Ideen-Klemme. Grund: die beiden greifen nacheinander an
            // derselben Zahl, und zwei Meldungen über dieselbe Lücke widersprechen sich
            // rechnerisch. Der Schlüssel trägt die Konzept-ID, weil `vermerkeDeckel` je Schlüssel
            // ERSETZT — die Paket-Rekursion unten würde den äußeren Eintrag sonst löschen.
            $this->vermerkeConceptLuecke(
                $runId,
                $conceptId,
                $slotsLeer,
                $leere->count(),          // tatsächlich ANGEFRAGT (nach dem Slot-Deckel)
                count($ideen),
                (int) ($div['grenze'] ?? IdeenService::IDEEN_MAX),
                $seen,
                $ideenFehler,
            );

            foreach (array_values($ideen) as $idx => $idee) {
                $slot = $leere[$idx] ?? null;
                if ($slot === null) {
                    break;   // mehr Ideen als leere Slots — Rest ignorieren
                }
                $idee->update([
                    // Vorschlag-Gate: noch KEIN Materialisierungs-Job. Erst der Mensch startet ihn
                    // über den geplanten Gericht-Step; bis dahin existiert nur diese Text-Skizze.
                    'generation_status' => null,
                    'source_meta' => array_merge($idee->source_meta ?? [], ['target_concept_slot_id' => (int) $slot->id]),
                ]);
                $step = FoodAlchemistCascadeRunStep::create([
                    'team_id' => $team->id,
                    'cascade_run_id' => $runId,
                    'parent_step_id' => $conceptStepId,
                    'kind' => 'gericht',
                    'label' => Str::limit((string) $idee->title, 120),
                    'status' => 'geplant',
                    'sort' => $idx + 1,
                    'context_snapshot' => [
                        'dish_idea_id' => (int) $idee->id,
                        'beschreibung' => (string) ($idee->description ?? ''),
                        'komponenten' => array_values((array) (($idee->source_meta ?? [])['komponenten'] ?? [])),
                    ],
                ]);
            }
        }

        // A1: eingebettete Pakete (Buffet-Stationen) rekursiv befüllen — je Paket seine INNEREN Gerichte
        // erfinden. Der Paket-Auto-Preis (Σ) zieht nach, sobald die inneren Slots gefüllt sind — was
        // seit dem Vorschlag-Gate NICHT automatisch passiert, sondern je innerem Slot erst nach dem
        // menschlichen Klick (erzeugeGeplantenStep → MaterializeConceptIdeaJob → fillSlot →
        // refreshCache auf dem Paket-Concept). 1 Ebene tief
        // (generierte Pakete verschachteln nicht), $seen schützt gegen zyklische Bestands-Embeds.
        $paketIds = FoodAlchemistConceptSlot::where('concept_id', $conceptId)
            ->whereNotNull('embedded_concept_id')
            ->pluck('embedded_concept_id')->unique()->values();
        foreach ($paketIds as $paketId) {
            $this->fanoutConceptInvention($team, $conceptStepId, (int) $paketId, $mode, $planningSessionId, $seen);
        }
    }

    /**
     * Vermerkt die Lücke EINES Konzept-Durchgangs — und nennt den Grund, der wirklich gegriffen hat.
     *
     * Drei Ursachen, drei Sätze, und der entscheidende Punkt: die Arithmetik muss immer schließen.
     * Ein Satz wie »18 von 25 Positionen ohne Skizze — die KI liefert höchstens 12« widerspricht
     * sich selbst (25 − 18 = 7, die Grenze hat also NICHT gebunden) und ist damit genau der
     * Fehler, den dieser Umbau beheben soll. Der Koch kann nur eine Sache selbst prüfen: ob die
     * Zahlen aufgehen.
     *
     * Die Reihenfolge der Prüfung folgt der DOMINANZ, nicht der Code-Reihenfolge — das war mein
     * erster Fehler hier. Bei 33 leeren Positionen, 30 angefragten und 2 gelieferten fehlen 31;
     * der 30er-Deckel erklärt davon aber nur 3. »Der Lauf fragt höchstens 30 ab« wäre dann
     * formal richtig und trotzdem die falsche Auskunft. Also:
     *
     *   1. Modell hat unterliefert (erzeugt < min(angefragt, grenze)) → »die KI hat nur N geliefert«
     *   2. Ideen-Klemme gegriffen (erzeugt === grenze < angefragt) → »die KI liefert höchstens N auf einmal«
     *   3. Slot-Deckel gegriffen (leer > CONCEPT_MAX_SLOTS) → »der Lauf fragt höchstens N Positionen auf einmal ab«
     *
     * Stapeln sich 2 und 3, wird 2 genannt: die Klemme ist die pro Lauf bindende Grenze, der
     * 30er würde erst wirken, wenn man sie hebt.
     *
     * »auf einmal« statt »je Konzept«: dass eine Paket-Station intern selbst ein Konzept ist, ist
     * Code-Wissen. Der Leser bezöge die Zahl sonst auf das ganze Konzept statt auf diesen Durchgang.
     *
     * KEIN »zweiter Lauf für den Rest«: der Slot-Filter prüft nur auf NULL und sortiert nach
     * `position, id` — ein zweiter Lauf nimmt real wieder die Positionen 1–12 und doppelt.
     *
     * @param  list<int>  $seen  Rekursions-Pfad; ab Tiefe 1 ist dieser Durchgang eine Paket-Station
     */
    private function vermerkeConceptLuecke(int $runId, int $conceptId, int $slotsLeer, int $angefragt, int $erzeugt, int $grenze, array $seen, ?string $ideenFehler = null): void
    {
        $offen = $slotsLeer - $erzeugt;
        if ($offen < 1) {
            return;
        }

        $run = FoodAlchemistCascadeRun::find($runId);
        if ($run === null) {
            return;
        }

        if ($erzeugt < min($angefragt, $grenze)) {
            // Ein gescheiterter Ideen-Aufruf (Budget/Provider/…) ist ein ANDERER Befund als „die KI
            // hat 0 geliefert" — der Mensch braucht den echten Grund, nicht eine Vermutung über die
            // KI-Antwort, wenn sie nie gefragt wurde.
            $grund = $erzeugt === 0
                ? ($ideenFehler ?? 'die KI hat keine geliefert')
                : sprintf('die KI hat nur %d geliefert', $erzeugt);
        } elseif ($angefragt > $grenze) {
            $grund = sprintf('die KI liefert höchstens %d auf einmal', $grenze);
        } else {
            $grund = sprintf('der Lauf fragt höchstens %d Positionen auf einmal ab', self::CONCEPT_MAX_SLOTS);
        }

        // Ab Tiefe 1 ist der Durchgang eine eingebettete Station — der Ort gehört vorn hin, sonst
        // weiß der Leser nicht, WELCHE Station Lücken hat.
        $station = count($seen) > 1
            ? trim((string) (FoodAlchemistConcept::find($conceptId)?->name ?? '')) : '';
        $ort = $station !== '' ? sprintf('Station „%s": ', $station) : '';

        $run->vermerkeDeckel(
            'concept_luecke:' . $conceptId,
            $grenze,
            $slotsLeer,
            $offen,
            sprintf(
                '%s%d von %d Positionen ohne Skizze. Grund: %s. Den Rest im Konzept von Hand besetzen, '
                . 'ein zweiter KI-Lauf würde wieder vorne beginnen.',
                $ort,
                $offen,
                $slotsLeer,
                $grund,
            ),
        );
    }

    /**
     * Worker-Logik (aus {@see MaterializeConceptIdeaJob}): erdet EINE erfundene Concept-Idee zu einem
     * echten VK-Gericht ({@see RecipeGeneratorService::generiere}, vkModus) und verdrahtet es in den
     * vorgemerkten leeren Slot ({@see ConceptService::fillSlot}); Lineage an die Idee, Rückmeldung an
     * den Kind-Step. Fehler (inkl. KI-Ausfall) → Step failed, Idee markiert — kein „halbes Wrack".
     */
    public function materialisiereConceptGericht(Team $team, int $ideaId, int $stepId, ?int $planningSessionId = null): void
    {
        $idee = FoodAlchemistDishIdea::where('team_id', $team->id)->find($ideaId);
        if ($idee === null) {
            $this->schritt()->markStepFailed($stepId, 'Die Gericht-Skizze gibt es nicht mehr.');

            return;
        }
        $slotId = (int) ($idee->source_meta['target_concept_slot_id'] ?? 0);
        $beschreibung = trim(implode(' — ', array_filter([(string) $idee->title, (string) $idee->description]))) ?: (string) $idee->title;
        // Der geprüfte Bauplan ersetzt eine erneute blinde Zerlegung: Der Generator erhält Namen,
        // Funktion und technisches Ziel der Komponenten und kann damit zuerst Bestands-Basisrezepte matchen.
        $komponenten = array_values((array) (($idee->source_meta ?? [])['komponenten'] ?? []));
        if ($komponenten !== []) {
            $zeilen = collect($komponenten)->filter(fn ($k) => is_array($k))->map(function (array $k): string {
                return trim(implode(' — ', array_filter([
                    (string) ($k['name'] ?? ''),
                    isset($k['funktion']) ? 'Funktion: ' . $k['funktion'] : null,
                    isset($k['herstellung']) ? 'Ziel: ' . $k['herstellung'] : null,
                ])));
            })->filter()->values()->all();
            if ($zeilen !== []) {
                $beschreibung .= "\nKomponentenplan:\n- " . implode("\n- ", $zeilen);
            }
        }

        // Gestuft (Gate pro Ebene): das Gericht schiebt seine Basisrezepte auf bis zu seiner Freigabe.
        $run = FoodAlchemistCascadeRunStep::find($stepId)?->run;
        $staged = (bool) ($run?->staged ?? false);
        try {
            // Fan-out erbt die Leitplanken der Session (Regler am Planung-Go); Steuer-Keys gewinnen.
            $params = array_merge($this->kontext()->sessionGenerationParams($team, $planningSessionId), [
                'auto_dependencies' => ! $staged,
                '_defer_children' => $staged,
                'cascade_step_id' => $stepId,
            ]);
            // Die Suchbegriffe des Laufs (Chips der Session, #227) liegen nur in den Lauf-Params — vorher erreichten
            // sie den Gericht-Bau nie (demo Lauf 86, Step 556: 18 Suchbegriffe gesetzt, Snapshot `suchbegriffe = []`).
            $laufSuchbegriffe = RecipeGenerationContextService::suchbegriffeAus(is_array($run?->params) ? $run->params : []);
            if ($laufSuchbegriffe !== [] && RecipeGenerationContextService::suchbegriffeAus($params) === []) {
                $params['suchbegriffe'] = $laufSuchbegriffe;
            }
            // Et.6 (Roadmap Z.205): Zielpreis-Korridor Concept → Gericht — je erfundenem Gericht einen
            // Ziel-VK aus dem Concept-Frame ableiten (aus dem Fan-out des Concepts, nicht aus dem Rezept-Regler).
            // Ein bereits gesetzter ziel_vk_eur (explizite Rezept-Leitplanke) gewinnt und wird nicht überschrieben.
            if (! isset($params['ziel_vk_eur'])) {
                $zielVk = $this->conceptGerichtZielVk($team, $slotId);
                if ($zielVk !== null) {
                    $params['ziel_vk_eur'] = $zielVk;
                }
            }
            $workflow = app(RecipeDependencyWorkflowService::class);
            $context = $workflow->prepare($team, $stepId, $beschreibung, $params, true);
            // Spec 53 / Paket C-Nachtrag: derselbe Fortschritts-Callback wie materialisiereSpeisekarte-
            // Position/-SpeiseplanZelle — dies ist der häufigste Gericht-Pfad live (jedes erfundene
            // Gericht aus Concept-/Foodbook-Fan-out, MaterializeConceptIdeaJob), bisher ohne Phase.
            $gen = app(GerichtHuelleService::class)->generiere(   // baut in die Gericht-Hülle, wenn der Schritt eine hat, sonst neu
                $team, $stepId, $beschreibung, $params, $context,
                fn (string $stufe) => $this->schritt()->setzePhase($stepId, $stufe),
            );
            $recipe = $gen['recipe'] ?? null;
            if ($recipe === null) {
                throw new RuntimeException('Die KI hat kein Rezept geliefert. Bitte neu erzeugen.');
            }
            app(ConceptVorgabenService::class)->setzeGericht($team, $slotId, (int) $recipe->id, $idee);   // füllt die Position; Wortlaut einer Vorgabe bleibt Kundenwording
            $idee->update([
                'generation_status' => 'erstellt',
                'status' => 'freigegeben',
                'generated_recipe_id' => (int) $recipe->id,
                'materialized_at' => now(),
                'materialized_ref' => ['concept_slot_id' => $slotId, 'recipe_id' => (int) $recipe->id],
                'source_meta' => array_merge($idee->source_meta ?? [], ['erdung' => 'ki_generiert', 'original_titel' => (string) $idee->title]),
            ]);
            // Lineage aufs erfundene Rezept (created_via=plan_go); die Trendradar-Herkunft steht an der Session.
            if ($planningSessionId !== null) {
                $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
                if ($sess !== null) {
                    app(PlanningSessionService::class)->verknuepfeArtefakt($sess, 'recipe', (int) $recipe->id);
                }
            }
            $workflow->afterGenerated($team, $stepId, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $recipe, $gen['offene'] ?? [], $params);
            $this->schritt()->markStepDone($stepId, 'recipe', (int) $recipe->id);
        } catch (\Throwable $e) {
            $idee->update(['generation_status' => 'fehlgeschlagen', 'source_meta' => array_merge($idee->source_meta ?? [], ['generation_fehler' => mb_substr($e->getMessage(), 0, 500)])]);
            $this->schritt()->markStepFailed($stepId, $e->getMessage());
        }
    }

    /**
     * Et.6 (Roadmap Z.205): **Zielpreis-Korridor Concept → Gericht (aus Frame).** Leitet für ein
     * erfundenes Concept-Gericht einen Ziel-VK (netto je Portion) aus dem Concept-Frame ab, damit
     * das Wirtschaftlichkeits-Glied der Anreicherung ({@see RecipeOneShotService::wirtschaftlichkeitsGlied}
     * via {@see EnrichRecipeJob}) das Gericht gegen ein echtes Ziel misst statt gegen null.
     *
     * Quelle in Reihenfolge (keine Erfindung, „aus Frame"):
     *   1. **Preis-Anker des passenden Frame-Slots** (per Gericht, via Slot-Rolle = Frame-Slot-Label
     *      gematcht) — die verlustfreie per-Gericht-Angabe, die der Frame direkt trägt;
     *   2. sonst der **Frame-Kopf-Zielpreis je Person** (`target_price_pp`) gleichmäßig auf die Positionen
     *      verteilt (`target_price_pp / Σ target_count`). Ein Menü-Zielpreis je Person wird nur durch die
     *      Zahl der Gänge zu einem per-Gericht-Wert — die Gleichverteilung ist eine bewusste, dokumentierte
     *      Allokations-Annahme (kein erfundener Datenwert), greift nur, wenn kein Slot-Anker vorliegt.
     * Kein Ziel-Slot / kein Frame / kein Preis / Wert ≤ 0 → null (kein `ziel_vk_eur`, Bestandsverhalten).
     */
    private function conceptGerichtZielVk(Team $team, int $slotId): ?float
    {
        if ($slotId <= 0) {
            return null;
        }
        $slot = FoodAlchemistConceptSlot::find($slotId);
        if ($slot === null) {
            return null;
        }
        $frame = app(PlanningFrameService::class)->find('concept', (int) $slot->concept_id);
        if ($frame === null) {
            return null;
        }
        $frame->loadMissing('slots');

        // 1) Preis-Anker des Frame-Slots mit gleicher Rolle (Label) — per Gericht, verlustfrei.
        $rolle = trim((string) ($slot->role ?? ''));
        if ($rolle !== '') {
            $treffer = $frame->slots->first(
                fn ($fs) => trim((string) ($fs->label ?? '')) === $rolle && $fs->price_anchor !== null
            );
            if ($treffer !== null) {
                $anker = round((float) $treffer->price_anchor, 2);
                if ($anker > 0) {
                    return $anker;
                }
            }
        }

        // 2) Kopf-Zielpreis je Person, gleichmäßig auf die Positionen (Σ target_count) verteilt.
        if ($frame->target_price_pp !== null) {
            $positionen = (int) $frame->slots->sum(fn ($fs) => max(1, (int) ($fs->target_count ?? 1)));
            if ($positionen > 0) {
                $jeGericht = round((float) $frame->target_price_pp / $positionen, 2);
                if ($jeGericht > 0) {
                    return $jeGericht;
                }
            }
        }

        return null;
    }

    /**
     * Feuert das Menü-Folge-Kohärenz-Gate automatisch, sobald die per Fan-out ERFUNDENE Menüfolge
     * eines Concept-Steps vollständig geerdet ist — statt auf den manuellen „Kohäsion prüfen"-Klick
     * im Conceptor zu warten. Die erfundene Folge wird erst NACH dem Grounding scorebar (Skizzen /
     * laufende Steps tragen noch keine Anker), deshalb hier am Fan-out-Abschluss und nicht schon bei
     * der Erfindung ({@see fanoutConceptInvention}).
     *
     * Der Abschluss-Haken sitzt am Job-Rückkanal ({@see PlanningCascadeService::markStepDone}/{@see PlanningCascadeService::markStepFailed}): jeder
     * {@see \Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob} meldet EINEN Gericht-Step; der
     * Handler bestimmt den zugehörigen Concept-Step (der meldende Step selbst = eager-Pfad, wo die
     * Kinder inline liefen; oder dessen Concept-Eltern = async-Pfad, wo der LETZTE Kind-Job die
     * Erfindung abschließt) und scored erst, wenn KEIN erfundenes Gericht mehr offen ist.
     *
     * Fail-soft: eine nicht-scorebare Folge (Provider-los, ungemappt, zu wenig Gerichte) persistiert
     * `null` und darf den Rückkanal nie kippen.
     */
    public function scoreConceptCohesionIfComplete(?FoodAlchemistCascadeRunStep $step): void
    {
        if ($step === null) {
            return;
        }
        // Den zugehörigen Concept-Step bestimmen: DIESER Step ist es selbst (eager: der Concept meldet
        // sich fertig, seine erfundenen Gerichte liefen schon inline), oder ein erfundener Gericht-Step
        // meldet sich (async: der letzte Kind-Job schließt die Fan-out-Erfindung ab).
        if ($step->kind === 'concept') {
            $conceptStep = $step;
        } elseif ($step->kind === 'gericht' && $step->parent_step_id !== null) {
            $conceptStep = FoodAlchemistCascadeRunStep::find($step->parent_step_id);
        } else {
            return;   // Rezept/GP-Steps oder freie Gericht-Läufe (kein Concept-Eltern) → kein Menü-Gate
        }
        if ($conceptStep === null || $conceptStep->kind !== 'concept' || $conceptStep->ref_id === null) {
            return;
        }

        // Scorebar erst, wenn ALLE erfundenen Gericht-Geschwister durch sind (kein Job/Platzhalter mehr
        // offen) UND überhaupt ein erfundenes Gericht existiert (ohne Fan-out gibt es keine Folge).
        $gerichte = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)
            ->where('kind', 'gericht')->get(['status']);
        if ($gerichte->isEmpty()
            || $gerichte->whereIn('status', ['geplant', 'queued', 'running'])->isNotEmpty()) {
            return;
        }

        $team = Team::find($conceptStep->team_id);
        if ($team === null) {
            return;
        }
        // Spec 50 C-2: alle erfundenen Gerichte stehen → Wording-Pass (Header-Titel, Positions-Wording,
        // Intro, consumer_name/claim), nur Lücken, fail-soft — VOR dem Kohäsions-Score, damit das
        // Konzept beim Review komplett ist.
        app(ConceptService::class)->wordingPassFailSoft($team, (int) $conceptStep->ref_id);
        try {
            $this->persistConceptCohesion((int) $conceptStep->cascade_run_id, $team, (int) $conceptStep->ref_id);
        } catch (\Throwable) {
            // fail-soft: eine nicht-scorebare Folge darf den Job-Rückkanal nie kippen
        }
    }

    /**
     * Berechnet die Aroma-Kohäsion der Gerichte eines Concepts ({@see PairingService::menuCohesion} →
     * {@see PairingService::menuKohaesionWarnung}) und persistiert die abgestufte Warnung am Run
     * (`cohesion_warning`). Gleiche Auffaltung der Gerichte wie {@see \Platform\FoodAlchemist\Livewire\Concepter\Editor::kohaesionPruefen}
     * (Slot-Gericht + Paket-Gerichte, dubletten-frei). `null`, wenn nichts zu beurteilen ist.
     */
    private function persistConceptCohesion(int $runId, Team $team, int $conceptId): void
    {
        $concept = app(ConceptService::class)->detail($team, $conceptId);
        if ($concept === null) {
            return;
        }
        $dishes = [];
        foreach ($concept->slots as $slot) {
            if ($slot->dish) {
                $dishes[$slot->dish->id] = $slot->dish;
            }
        }
        $pairing = app(PairingService::class);
        $kohaesion = count($dishes) >= 2
            ? $pairing->menuCohesion(array_values($dishes))
            : ['zu_wenig' => true];
        $warnung = $pairing->menuKohaesionWarnung($kohaesion);

        FoodAlchemistCascadeRun::find($runId)?->update(['cohesion_warning' => $warnung]);
    }

    private function schritt(): KaskadenSchritt
    {
        return app(KaskadenSchritt::class);
    }

    private function kontext(): KaskadeKontext
    {
        return app(KaskadeKontext::class);
    }
}
