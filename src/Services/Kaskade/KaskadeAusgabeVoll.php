<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeisekartePositionJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Planung\PlanningFrameService;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Services\Rezept\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\Rezept\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\Ausgabe\SpeisekarteService;
use Platform\FoodAlchemist\Support\Warteschlange;
use RuntimeException;

/**
 * Plan 98 · 3.3 S1 · P3/P4 Voll-Kaskade einer Ausgabe, verschoben aus {@see PlanningCascadeService} (reine Verschiebung):
 * Ausgabe-Frame (Foodbook, Speisekarte, Angebot, Format) → ein Concept je Slot ({@see starteVollkaskade}, {@see starteKapitelKaskade}),
 * Struktur-Anlage ({@see legeStrukturAn}), Speisekarte-Gerichte je Rubrik ({@see dispatchRubrikGerichte}, Worker
 * {@see materialisiereSpeisekartePosition}). Step-Status über {@see KaskadenSchritt}, gemeinsame Helfer über {@see KaskadeKontext}.
 * Jobs, Tools und Tests rufen weiter die Fassade.
 */
class KaskadeAusgabeVoll
{
    /** Runaway-/Kosten-Deckel: max. VK-Gericht-Positionen einer Speisekarte-Vollkaskade (Gerichte-Füllung). */
    private const SPEISEKARTE_MAX_POSITIONEN = 40;

    /**
     * Voll-Kaskade aus einem Ausgabe-Frame (P3 Foodbook, P4 Speisekarte): je Frame-Slot ein Concept-Step
     * + eigener {@see GenerateConceptJob} (der ans Ausgabe-Kapitel/-Rubrik hängt und danach in Gerichte
     * fächert). Owner + ID kommen über `$optionen['owner_type']`/`['owner_id']`; die Slots werden owner-
     * spezifisch in Container (Kapitel/Rubrik) materialisiert. Ohne Frame/Slots → ehrlicher Fehler.
     *
     * @param  array{owner_type?:string, owner_id?:int, created_via?:string}  $optionen
     */
    public function starteVollkaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, array $optionen): FoodAlchemistCascadeRun
    {
        $ownerType = (string) ($optionen['owner_type'] ?? '');
        $ownerId = (int) ($optionen['owner_id'] ?? 0);
        // P3 foodbook, P4 speisekarte (je 1 Concept/Slot). E2 (Spec 40): offer — je Slot ein Concept, ans
        // Angebot referenziert (Pivot). format — je Slot ein Concept-Baustein, ins gebrandete Foodkonzept
        // referenziert (format_slot type=concept, wie offer). Der Speiseplan (P5) läuft über einen eigenen Zell-Pfad.
        if (! in_array($ownerType, ['foodbook', 'speisekarte', 'offer', 'format'], true) || $ownerId <= 0) {
            throw new RuntimeException('Die Planung braucht ein Ziel: Foodbook, Speisekarte, Angebot oder Format.');
        }

        $frame = app(PlanningFrameService::class)->find($ownerType, $ownerId);
        if ($frame === null || $frame->slots()->count() === 0) {
            throw new RuntimeException('Es gibt noch kein Planungs-Gerüst. Bitte zuerst die Struktur anlegen.');
        }

        // FOODBOOK läuft GESTUFT (Kapitel-Gate): erst das Kapitel-Grundgerüst als geplante Concept-Steps
        // (Run=prüfen), dann Kapitel für Kapitel die Concept-Erzeugung + Freigabe (Nutzer-Wunsch: Kapitel
        // erst prüfen/korrigieren). Speisekarte/Angebot bleiben EAGER (staged=false) — sie haben keine
        // vorab materialisierte Container-/Kapitel-Ebene (Rubriken/Angebot entstehen erst im Fan-out),
        // ein Kapitel-Gate ist dort nicht sinnvoll. Der Concept→Gericht-Fan-out wird bei staged automatisch
        // mitgestuft (GenerateConceptJob deferrt ihn), Gate 2 (Concept-Review) läuft über die bestehende
        // gibStepFrei/starteFolgestufe-Maschinerie.
        $staged = $ownerType === 'foodbook';
        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => 'vollkaskade',
            'creative_mode' => $creativeMode,
            'brief' => 'Voll-Kaskade ' . $ownerType . ' #' . $ownerId,
            'status' => 'running',
            'staged' => $staged,
            'source_owner_type' => $ownerType,
            'source_owner_id' => $ownerId,
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
        ]);

        // Speisekarte-Füllung (Nutzer: „in der Regel mit Gerichten befüllt"): Standard = je Rubrik N einzelne
        // VK-Gerichte (gericht_ref); 'concepte' = altes Verhalten (je Rubrik 1 Concept/Fix-Menü). Nur speisekarte.
        // FRISCH aus der DB lesen — das übergebene Session-Objekt ist nach setGenerationParams veraltet.
        $freshSession = $session !== null ? app(PlanningSessionService::class)->get($team, (int) $session->id) : null;
        $sessionParams = is_array($freshSession?->generation_params) ? $freshSession->generation_params : [];
        $speisekarteGerichte = $ownerType === 'speisekarte' && ($sessionParams['speisekarte_fuellung'] ?? 'gerichte') !== 'concepte';

        $slots = $this->vollkaskadeSlots($team, $ownerType, $ownerId, $frame);
        // Spec 80 F: Struktur-Elemente des Rahmens direkt in die Ausgabe (nach den Kapiteln, vor den Konzepten).
        try {
            $strukturMap = $this->legeStrukturAn($team, $ownerType, $ownerId, $frame);
            if ($strukturMap !== []) {
                $run->update(['params' => [...(is_array($run->params) ? $run->params : []), 'struktur_map' => $strukturMap]]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Kaskade] Struktur-Elemente nicht angelegt', ['run' => $run->id, 'error' => $e->getMessage()]);
        }
        $idx = 0;
        // Sammler für den Positions-Deckel: EIN Befund für die ganze Karte statt eines Vermerks
        // je Rubrik. `$idx` ist ein durchlaufender Zähler über alle Rubriken — der Deckel greift
        // deshalb genau einmal mitten in einer Rubrik, und alle folgenden bekommen null. Ohne
        // diesen Sammler wüsste die Meldung nicht, WELCHE Rubriken leer geblieben sind, und
        // „8 Positionen fehlen" ist für einen Koch nutzlos, wenn er nicht weiss, ob ihm die
        // Vorspeisen oder die Desserts fehlen.
        $deckel = [];
        foreach ($slots as [$slot, $containerId]) {
            if ($staged) {
                // Kapitel-Gate: nur PLANEN (geplant), NICHT dispatchen — die Concept-Erzeugung startet erst
                // die Kapitel-Freigabe (gibStepFrei/FREIGABE auf den geplanten Kapitel-Step).
                $this->planeSlotConcept($team, $run, $slot, $ownerType, (int) $containerId, $idx, 'geplant');
                $idx++;
            } elseif ($speisekarteGerichte) {
                // Standard-Speisekarte: die Rubrik mit N einzelnen VK-Gerichten füllen (statt 1 Concept).
                $idx = $this->dispatchRubrikGerichte($team, $run, $slot, (int) $containerId, $idx, $session?->id, $deckel);
            } else {
                $this->dispatchSlotConcept($team, $run, $slot, $ownerType, (int) $containerId, $idx, $creativeMode, $session?->id);
                $idx++;
            }
        }
        if (($deckel['rubriken'] ?? []) !== []) {
            $offen = (int) array_sum(array_column($deckel['rubriken'], 'offen'));
            $run->vermerkeDeckel(
                'speisekarte_positionen',
                self::SPEISEKARTE_MAX_POSITIONEN,
                (int) ($deckel['verlangt'] ?? 0),
                $offen,
                $this->speisekarteDeckelText($offen, (int) ($deckel['verlangt'] ?? 0), $deckel['rubriken']),
            );
        }
        if ($idx === 0) {
            $run->update(['status' => 'failed']);   // Frame ohne verwertbare Slots
        } elseif ($staged) {
            // geplante Kapitel-Steps → Run auf „prüfen" (review) heben, damit der Kapitel-Gate sichtbar ist.
            $this->schritt()->recomputeRunStatus((int) $run->id);
        }

        return $run;
    }

    /**
     * Spec-42-Vollzug S3a — gezielter Teil-Lauf für GENAU EIN Foodbook-Kapitel (statt der ganzen
     * Voll-Kaskade). Nutzt denselben Attach-je-Kapitel-Pfad wie {@see starteVollkaskade} (ein
     * {@see GenerateConceptJob} mit `attachOwnerType='foodbook'` + `attachContainerId=chapter_id`), nur
     * für das eine gekoppelte Slot↔Kapitel-Paar. Das „Kapitel-Go" der Leitstelle ersetzt damit den alten
     * kaskaden-fremden `FoodbookService::kapitelFreigeben`-Bypass — jede Erzeugung läuft über den Motor.
     *
     * Jeder Aufruf = ein NEUER Run mit einem Step (kein Anhängen an fremde Runs → keine Race). Nicht
     * idempotent — Doppel-Klick-Schutz macht die UI (Button-Disable am jüngsten „läuft"-Step des Kapitels).
     */
    public function starteKapitelKaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, int $foodbookId, int $chapterId, array $optionen = []): FoodAlchemistCascadeRun
    {
        if (! in_array($creativeMode, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $creativeMode = 'voll_kreativ';
        }
        $frame = app(PlanningFrameService::class)->find('foodbook', $foodbookId);
        if ($frame === null || $frame->slots()->count() === 0) {
            throw new RuntimeException('Das Foodbook hat noch kein Planungs-Gerüst. Bitte zuerst die Struktur anlegen.');
        }
        // Genau das gekoppelte Slot↔Kapitel-Paar suchen (vollkaskadeSlots stellt strukturAusGeruest-Idempotenz sicher).
        $treffer = null;
        foreach ($this->vollkaskadeSlots($team, 'foodbook', $foodbookId, $frame) as [$slot, $containerId]) {
            if ((int) $containerId === $chapterId) {
                $treffer = [$slot, (int) $containerId];
                break;
            }
        }
        if ($treffer === null) {
            throw new RuntimeException('Dieses Kapitel ist nicht aus dem Planungs-Gerüst entstanden. Mit KI füllen lassen sich nur Kapitel aus dem Gerüst.');
        }

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => 'vollkaskade',   // gleiche Scope-Semantik wie die Voll-Kaskade (nur 1 Slot)
            'creative_mode' => $creativeMode,
            'brief' => 'Kapitel-Kaskade foodbook #' . $foodbookId . ' — Kapitel #' . $chapterId,
            'status' => 'running',
            'staged' => false,
            'source_owner_type' => 'foodbook',
            'source_owner_id' => $foodbookId,
            'created_via' => (string) ($optionen['created_via'] ?? 'leitstelle_kapitel_go'),
        ]);
        [$slot, $containerId] = $treffer;
        $this->dispatchSlotConcept($team, $run, $slot, 'foodbook', $containerId, 0, $creativeMode, $session?->id);

        return $run;
    }

    /**
     * Ein Slot → ein Concept-Step + {@see GenerateConceptJob} (Attach an den Owner-Container). Der EINE
     * Ort, an dem `chapter_id`/`slot_id` am Step gesetzt werden — genutzt von {@see starteVollkaskade}
     * (Schleife) UND {@see starteKapitelKaskade} (einmal). Für foodbook trägt der Brief die editierten
     * Kapitel-Ziele ({@see kapitelBrief}, Kapitel schlägt Slot), sonst der reine {@see slotBrief}.
     */
    private function dispatchSlotConcept(Team $team, FoodAlchemistCascadeRun $run, $slot, string $ownerType, int $containerId, int $idx, string $creativeMode, ?int $sessionId): FoodAlchemistCascadeRunStep
    {
        $step = $this->planeSlotConcept($team, $run, $slot, $ownerType, $containerId, $idx, 'running');
        $this->dispatcheConceptStep($team, $run, $step, $slot, $ownerType, $containerId, $creativeMode, $sessionId);

        return $step;
    }

    /**
     * Slot → Concept-Step ANLEGEN (ohne Dispatch). $status='running' (eager, direkt danach dispatcht) oder
     * 'geplant' (gestufte Foodbook-Vollkaskade: Kapitel-Gate — der Step wartet auf die Kapitel-Freigabe,
     * bevor der GenerateConceptJob läuft). Der EINE Ort, an dem chapter_id/slot_id am Step gesetzt werden.
     */
    public function planeSlotConcept(Team $team, FoodAlchemistCascadeRun $run, $slot, string $ownerType, int $containerId, int $idx, string $status): FoodAlchemistCascadeRunStep
    {
        $chapterId = $ownerType === 'foodbook' ? $containerId : null;

        return FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => 'concept',
            'label' => Str::limit((string) ($slot->label ?: 'Konzept'), 120),
            'status' => $status,
            'sort' => $idx,
            'chapter_id' => $chapterId,
            'slot_id' => (int) $slot->id,
        ]);
    }

    /**
     * Den {@see GenerateConceptJob} für einen bereits angelegten Concept-Step dispatchen (Step → running).
     * Getrennt von der Step-Anlage, damit der gestufte Foodbook-Gate den Step erst PLANT (geplant) und
     * erst bei der Kapitel-Freigabe hier dispatcht ({@see PlanningCascadeService::erzeugeGeplantesConcept}). Für foodbook trägt der
     * Brief die editierten Kapitel-Ziele ({@see kapitelBrief}), sonst der reine {@see slotBrief}.
     */
    public function dispatcheConceptStep(Team $team, FoodAlchemistCascadeRun $run, FoodAlchemistCascadeRunStep $step, $slot, string $ownerType, int $containerId, string $creativeMode, ?int $sessionId): void
    {
        $chapterId = $ownerType === 'foodbook' ? $containerId : null;
        $brief = $chapterId !== null
            ? $this->kapitelBrief($chapterId, $slot)
            : $this->slotBrief($ownerType, (int) $run->source_owner_id, $slot);
        $runId = (string) Str::uuid();
        $step->update(['generator_run_id' => $runId, 'status' => 'running']);
        Cache::put(GenerateConceptJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(KaskadeKontext::RESULT_TTL_MIN));
        // Kompositions-Fix: die Menü-Leitplanken der Session (menue_*: Gänge/Preis-Korridor/Diät-Quoten/
        // Balance) an die Concept-Erzeugung reichen — sonst steuert die Menü-/Kapitel-Zusammenstellung nichts.
        $menueAchsen = $this->kontext()->sessionMenueAchsen($team, $sessionId);
        // Je Frame-Slot ein Concept — bei Foodbook/Angebot/Format also so viele, wie das
        // Gerüst Slots hat (beim Angebot ungedeckelt). Eigene Schlange.
        GenerateConceptJob::dispatch(
            $runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
            $brief, (string) ($slot->label ?: null),
            $sessionId, $step->id, $creativeMode, false, false, $ownerType, $containerId, $menueAchsen
        )->onQueue(Warteschlange::kaskade());
    }

    /**
     * Slots eines Ausgabe-Frames in Container materialisieren + als [slot, containerId] zurückgeben.
     * foodbook: `strukturAusGeruest` legt je Slot ein Kapitel an (chapter_id). speisekarte: je Slot eine
     * Rubrik (idempotent per Titel).
     *
     * @return list<array{0: \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot, 1: int}>
     */
    private function vollkaskadeSlots(Team $team, string $ownerType, int $ownerId, $frame): array
    {
        $out = [];
        // Spec 80 F: Struktur-Elemente erzeugen kein Concept — sie legt legeStrukturAn() direkt in die Ausgabe.
        $frame->setRelation('slots', $frame->slots()->orderBy('position')->get()->reject(fn ($s) => $s->istStruktur())->values());
        if ($ownerType === 'foodbook') {
            app(FoodbookService::class)->strukturAusGeruest($team, $ownerId);   // Slots → Kapitel (idempotent)
            $frame->setRelation('slots', $frame->slots()->orderBy('position')->get()->reject(fn ($s) => $s->istStruktur())->values());
            foreach ($frame->slots as $slot) {
                if ($slot->chapter_id !== null) {
                    $out[] = [$slot, (int) $slot->chapter_id];
                }
            }

            return $out;
        }
        if ($ownerType === 'speisekarte') {
            $svc = app(SpeisekarteService::class);
            foreach ($frame->slots as $slot) {
                $out[] = [$slot, $svc->rubrikFuerSlot($team, $ownerId, (string) ($slot->label ?: 'Rubrik'))];
            }

            return $out;
        }
        if ($ownerType === 'offer' || $ownerType === 'format') {
            // E2 (Spec 40): der „Container" IST das Angebot selbst — jedes erzeugte Konzept wird ans Angebot
            // referenziert (Pivot foodalchemist_offer_concept, {@see AngebotService::referenziereConcept}); es gibt
            // keinen Zwischen-Container wie Kapitel/Rubrik. Darum ist die containerId überall die Angebots-ID.
            // format läuft identisch: der Container IST das Format, jedes Concept wird als format_slot
            // (type=concept) referenziert ({@see FormatService::slotConceptEinfuegen}) — containerId = format_id.
            foreach ($frame->slots as $slot) {
                $out[] = [$slot, $ownerId];
            }

            return $out;
        }

        return $out;
    }

    /**
     * Spec 80 Teil F: Struktur-Elemente des Rahmens (Titel, Titel mit Preis, Freitext, Leerzeile) 1:1 in die
     * Ausgabe legen — ohne KI, ohne Step. Foodbook/Speisekarte: an den ANFANG des folgenden Abschnitts
     * (Entscheid Dominique 2026-10-09; ohne folgenden Abschnitt ans Ende des letzten). Format: an ihre Stelle,
     * die Reihenfolge hält {@see ordneFormatNachRahmen}. Gibt die Zuordnung Rahmen-Slot → Ausgabe-Element zurück.
     *
     * @return array<int, int>
     */
    private function legeStrukturAn(Team $team, string $ownerType, int $ownerId, $frame): array
    {
        $alle = $frame->slots()->orderBy('position')->get()->values();
        if ($alle->first(fn ($s) => $s->istStruktur()) === null) {
            return [];
        }
        $map = [];
        $titel = fn ($s) => trim((string) $s->label);
        if ($ownerType === 'format') {
            $fmt = app(\Platform\FoodAlchemist\Services\Concepter\FormatService::class);
            foreach ($alle as $s) {
                if (! $s->istStruktur()) {
                    continue;
                }
                $typ = ['titel' => 'header', 'titel_preis' => 'header', 'freitext' => 'text', 'leerzeile' => 'spacer'][$s->slot_type];
                $neu = $fmt->slotBlockEinfuegen($team, $ownerId, $typ, ['title' => $typ === 'header' ? $titel($s) : null, 'text_content' => $typ === 'text' ? ($s->note ?: $titel($s)) : null]);
                $map[(int) $s->id] = (int) $neu->id;
            }

            return $map;
        }
        // Foodbook/Speisekarte: Gruppen aufeinanderfolgender Struktur-Elemente → Anfang des folgenden Abschnitts.
        $gruppe = [];
        $ziele = [];
        foreach ($alle as $i => $s) {
            if ($s->istStruktur()) {
                $gruppe[] = $s;

                continue;
            }
            if ($gruppe !== []) {
                $ziele[] = [$gruppe, $s, 'anfang'];
                $gruppe = [];
            }
        }
        if ($gruppe !== []) {
            $letzter = $alle->reverse()->first(fn ($s) => ! $s->istStruktur());
            if ($letzter !== null) {
                $ziele[] = [$gruppe, $letzter, 'ende'];
            }
        }
        foreach ($ziele as [$elemente, $abschnitt, $wo]) {
            if ($ownerType === 'foodbook') {
                $kapitelId = (int) ($abschnitt->fresh()->chapter_id ?? 0);
                if ($kapitelId <= 0) {
                    continue;
                }
                $fb = app(FoodbookService::class);
                $neue = [];
                foreach ($elemente as $s) {
                    $typ = ['titel' => 'header_frei', 'titel_preis' => 'header_frei_preis', 'freitext' => 'text', 'leerzeile' => 'spacer'][$s->slot_type];
                    $block = $fb->addBlock($team, $kapitelId, array_filter([
                        'type' => $typ, 'label' => $typ !== 'spacer' ? $titel($s) : null,
                        'customer_text' => $typ === 'text' ? ($s->note ?: $titel($s)) : null,
                        'price_value' => $typ === 'header_frei_preis' ? $s->price_anchor : null,
                        'price_basis' => $typ === 'header_frei_preis' ? 'person' : null,
                    ], fn ($v) => $v !== null));
                    $neue[] = $block;
                    $map[(int) $s->id] = (int) $block->id;
                }
                if ($wo === 'anfang') {
                    $min = (int) \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::where('chapter_id', $kapitelId)
                        ->whereNotIn('id', array_map(fn ($b) => $b->id, $neue))->min('position');
                    foreach ($neue as $k => $b) {
                        $b->update(['position' => $min - count($neue) + $k]);
                    }
                }
            } elseif ($ownerType === 'speisekarte') {
                $sk = app(SpeisekarteService::class);
                $rubrikId = $sk->rubrikFuerSlot($team, $ownerId, (string) ($abschnitt->label ?: 'Rubrik'));
                $neue = [];
                foreach ($elemente as $s) {
                    $typ = ['titel' => 'header', 'titel_preis' => 'header', 'freitext' => 'text', 'leerzeile' => 'spacer'][$s->slot_type];
                    $pos = $sk->addPosition($team, $rubrikId, array_filter([
                        'type' => $typ, 'label' => $typ !== 'spacer' ? $titel($s) : null,
                        'consumer_text' => $typ === 'text' ? ($s->note ?: $titel($s)) : null,
                        'price_value' => $s->slot_type === 'titel_preis' ? $s->price_anchor : null,
                    ], fn ($v) => $v !== null));
                    $neue[] = $pos;
                    $map[(int) $s->id] = (int) $pos->id;
                }
                if ($wo === 'anfang') {
                    $min = (int) \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $rubrikId)
                        ->whereNotIn('id', array_map(fn ($p) => $p->id, $neue))->min('position');
                    foreach ($neue as $k => $p) {
                        $p->update(['position' => $min - count($neue) + $k]);
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Spec 80 F2: Format-Reihenfolge = Rahmen-Reihenfolge. Konzepte hängen sich asynchron ans Ende; danach ordnet
     * diese Funktion alle vom Lauf erzeugten Elemente (Struktur + Konzepte) nach der Position ihres Rahmen-Slots.
     * Elemente, die schon vorher im Format standen, bleiben vorne in ihrer Reihenfolge. Idempotent.
     */
    public function ordneFormatNachRahmen(Team $team, int $runId): void
    {
        $run = FoodAlchemistCascadeRun::find($runId);
        if ($run === null || $run->source_owner_type !== 'format') {
            return;
        }
        $formatId = (int) $run->source_owner_id;
        $frame = app(PlanningFrameService::class)->find('format', $formatId);
        if ($frame === null) {
            return;
        }
        $map = (array) (($run->params ?? [])['struktur_map'] ?? []);
        $conceptJeSlot = $run->steps()->where('kind', 'concept')->whereNotNull('slot_id')->whereNotNull('ref_id')->pluck('ref_id', 'slot_id');
        $formatSlots = \Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot::where('format_id', $formatId)->orderBy('position')->get();
        $gewuenscht = [];
        foreach ($frame->slots()->orderBy('position')->get() as $s) {
            if (isset($map[$s->id])) {
                $gewuenscht[] = (int) $map[$s->id];
            } elseif (($cid = $conceptJeSlot->get($s->id)) !== null) {
                $fs = $formatSlots->first(fn ($f) => $f->type === 'concept' && (int) $f->concept_id === (int) $cid);
                if ($fs !== null) {
                    $gewuenscht[] = (int) $fs->id;
                }
            }
        }
        $rest = $formatSlots->pluck('id')->map(fn ($i) => (int) $i)->reject(fn ($i) => in_array($i, $gewuenscht, true))->values()->all();
        foreach ([...$rest, ...$gewuenscht] as $pos => $id) {
            \Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot::whereKey($id)->update(['position' => $pos + 1]);
        }
    }

    /** Kompakter Brief je Slot für die Concept-Erzeugung (Rolle/Label + Ziele + Preis-Anker). */
    private function slotBrief(string $ownerType, int $ownerId, $slot): string
    {
        $teile = ['[' . ($slot->label ?: 'Gang') . ']'];
        if ((int) $slot->target_count > 0) {
            $teile[] = 'Zielanzahl Gerichte: ' . (int) $slot->target_count;
        }
        if ($slot->price_anchor !== null) {
            $teile[] = 'Preis-Anker p.P.: ' . $slot->price_anchor . ' €';
        }
        if ($slot->note !== null && trim((string) $slot->note) !== '') {
            $teile[] = trim((string) $slot->note);
        }

        return 'Konzept für die Rolle ' . implode(' — ', $teile) . '.';
    }

    /**
     * Wie {@see slotBrief}, aber die editierten M3-Kapitel-Ziele schlagen den Slot (Spec-42-Vollzug S3a):
     * Zielanzahl + Preis-Anker kommen aus dem Kapitel, wenn dort gesetzt; das Kapitel-Niveau kommt additiv
     * dazu. So wirkt die Kapitel-Steuerung der Leitstelle tatsächlich auf die frische Generierung.
     */
    private function kapitelBrief(int $chapterId, $slot): string
    {
        $kap = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($chapterId);
        $teile = ['[' . ($slot->label ?: ($kap?->title ?: 'Gang')) . ']'];
        $targetCount = ($kap && $kap->target_count !== null) ? (int) $kap->target_count : (int) $slot->target_count;
        if ($targetCount > 0) {
            $teile[] = 'Zielanzahl Gerichte: ' . $targetCount;
        }
        $priceAnchor = ($kap && $kap->price_anchor !== null) ? $kap->price_anchor : $slot->price_anchor;
        if ($priceAnchor !== null) {
            $teile[] = 'Preis-Anker p.P.: ' . $priceAnchor . ' €';
        }
        if ($kap && $kap->niveau !== null && trim((string) $kap->niveau) !== '') {
            $teile[] = 'Niveau: ' . $kap->niveau;
        }
        if ($slot->note !== null && trim((string) $slot->note) !== '') {
            $teile[] = trim((string) $slot->note);
        }

        return 'Konzept für die Rolle ' . implode(' — ', $teile) . '.';
    }

    /**
     * Der Satz für den Positions-Deckel der Speisekarte. Nennt die RUBRIKEN, nicht nur die Zahl:
     * „8 Positionen fehlen" ist für einen Koch nutzlos, wenn er nicht weiss, ob ihm die
     * Vorspeisen oder die Desserts fehlen.
     *
     * Höchstens EINE Rubrik ist angefangen — `$idx` läuft über alle Rubriken durch, der Deckel
     * greift also genau einmal mitten in einer und alle folgenden bekommen null. Diese eine wird
     * mit „x von y" genannt, die leeren gesammelt.
     *
     * KEIN „zweiter Lauf" als Handlung, anders als beim Speiseplan: dort baut der Lauf `$belegt`
     * aus den Einträgen und nimmt den Rest. Hier gibt es keinen Bestands-Abgleich und `$idx`
     * startet wieder bei 0 — ein zweiter Voll-Lauf bestückt also die VORDEREN Rubriken ein
     * zweites Mal und lässt die leeren weiter leer. Genau die falsche Empfehlung hätte doppelte
     * Kosten und doppelte Entwürfe erzeugt.
     *
     * @param  list<array{rubrik:string, verlangt:int, erzeugt:int, offen:int}>  $rubriken
     */
    private function speisekarteDeckelText(int $offen, int $verlangt, array $rubriken): string
    {
        $angefangen = array_values(array_filter($rubriken, static fn (array $r): bool => $r['erzeugt'] > 0));
        $leer = array_values(array_filter($rubriken, static fn (array $r): bool => $r['erzeugt'] === 0));

        $teile = [];
        foreach ($angefangen as $r) {
            $teile[] = sprintf('„%s" %d von %d', $r['rubrik'], $r['erzeugt'], $r['verlangt']);
        }
        if ($leer !== []) {
            $namen = array_map(static fn (array $r): string => '„' . $r['rubrik'] . '"', array_slice($leer, 0, 3));
            $rest = count($leer) - count($namen);
            $teile[] = implode(' und ', $namen)
                . ($rest > 0 ? sprintf(' und %d weitere Rubriken', $rest) : '')
                . ' ganz leer';
        }

        return sprintf(
            '%d von %d Gerichten fehlen (je KI-Lauf höchstens %d): %s. Diese Rubriken im Karten-Editor '
            . 'über „+ Gericht" füllen. Ein zweiter KI-Lauf würde die vorderen Rubriken doppelt bestücken.',
            $offen,
            $verlangt,
            self::SPEISEKARTE_MAX_POSITIONEN,
            implode(', ', $teile),
        );
    }

    /**
     * Standard-Speisekarte (Gerichte-Füllung): eine Rubrik mit N einzelnen VK-Gerichten füllen. Je Slot
     * `target_count` (KI-Vorgabe aus dem Brief) Gericht-Steps + je Step ein {@see MaterializeSpeisekartePositionJob}
     * (erdet ein VK-Gericht, hängt es als gericht_ref-Position an die Rubrik). Spiegelt den Speiseplan-Zell-
     * Fan-out. Gedeckelt ({@see SPEISEKARTE_MAX_POSITIONEN}) gegen Runaway-Kosten; der Rest wird im
     * Sammler `$deckel` gemerkt und nach der Slot-Schleife als EIN Vermerk in `deckel_hinweise`
     * geschrieben (vorher: ein `params`-UPDATE je Rubrik, das niemand las — die Status-DTO filtert
     * `params` gegen ALLOWED_GENERATION_PARAMS).
     *
     * @return int der nächste freie sort-Index
     */
    private function dispatchRubrikGerichte(Team $team, FoodAlchemistCascadeRun $run, $slot, int $rubrikId, int $idx, ?int $sessionId, array &$deckel = []): int
    {
        $anzahl = max(1, (int) ($slot->target_count ?? 1));
        // Nur Rubriken zählen, die wirklich durch DIESEN Pfad laufen — die 'concepte'-Füllung
        // hat ihren eigenen Zweig und darf die Positions-Rechnung nicht verfälschen.
        $deckel['verlangt'] = (int) ($deckel['verlangt'] ?? 0) + $anzahl;
        $brief = $this->slotBrief('speisekarte', (int) $run->source_owner_id, $slot);
        $userId = (int) (\Illuminate\Support\Facades\Auth::id() ?? 0);
        for ($i = 0; $i < $anzahl; $i++) {
            if ($idx >= self::SPEISEKARTE_MAX_POSITIONEN) {
                // Merken, WIE WEIT diese Rubrik kam — der Name ist die halbe Meldung.
                $deckel['rubriken'][] = [
                    'rubrik' => trim((string) ($slot->label ?: 'Rubrik')),
                    'verlangt' => $anzahl,
                    'erzeugt' => $i,
                    'offen' => $anzahl - $i,
                ];

                break;
            }
            $step = FoodAlchemistCascadeRunStep::create([
                'team_id' => $team->id,
                'cascade_run_id' => $run->id,
                'parent_step_id' => null,
                'kind' => 'gericht',
                'label' => Str::limit((string) ($slot->label ?: 'Gericht') . ' #' . ($i + 1), 120),
                'status' => 'running',
                'sort' => $idx,
                'slot_id' => (int) $slot->id,
            ]);
            // Bis 40 Positionen je Karte, in einer Schleife — dieselbe Schlange wie die
            // Speiseplan-Zellen: es ist dasselbe Artefakt (erzeugtes Gericht).
            MaterializeSpeisekartePositionJob::dispatch($team->id, $userId, $rubrikId, $brief, (int) $step->id, $sessionId)
                ->onQueue(Warteschlange::gerichte());
            $idx++;
        }

        return $idx;
    }

    /**
     * Worker (Standard-Speisekarte): erdet EIN VK-Gericht aus dem Rubrik-Brief und hängt es als
     * gericht_ref-Position an die Rubrik ({@see SpeisekarteService::addPosition}). 1:1 zu
     * {@see PlanningCascadeService::materialisiereSpeiseplanZelle}, nur der Attach-Endpunkt ändert sich (addPosition statt addEintrag).
     */
    public function materialisiereSpeisekartePosition(Team $team, int $rubrikId, string $brief, int $stepId, ?int $planningSessionId = null): void
    {
        try {
            $params = array_merge($this->kontext()->sessionGenerationParams($team, $planningSessionId), ['auto_dependencies' => true, 'cascade_step_id' => $stepId]);
            $workflow = app(RecipeDependencyWorkflowService::class);
            $context = $workflow->prepare($team, $stepId, $brief, $params, true);
            // Spec 53 / Paket C-Nachtrag: derselbe Fortschritts-Callback wie GenerateRecipeJob —
            // generiere() meldet dieselben Stufen (Kontext & Wissen/KI schreibt/Zutaten/Kohärenz),
            // hier bisher ungenutzt. Phase am Step statt am Cache (kein eigener Cache-Key hier).
            $gen = app(RecipeGeneratorService::class)->generiere(
                $team, $brief, $params, null, true, 'plan_go', $context,
                fn (string $stufe) => $this->schritt()->setzePhase($stepId, $stufe),
            );
            $recipe = $gen['recipe'] ?? null;
            if ($recipe === null) {
                throw new RuntimeException('Die KI hat kein Rezept geliefert. Bitte neu erzeugen.');
            }
            app(SpeisekarteService::class)->addPosition($team, $rubrikId, [
                'type' => 'gericht_ref', 'sales_recipe_id' => (int) $recipe->id,
            ]);
            if ($planningSessionId !== null) {
                $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
                if ($sess !== null) {
                    app(PlanningSessionService::class)->verknuepfeArtefakt($sess, 'recipe', (int) $recipe->id);
                }
            }
            $workflow->afterGenerated($team, $stepId, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $recipe, $gen['offene'] ?? [], $params);
            $this->schritt()->markStepDone($stepId, 'recipe', (int) $recipe->id);
        } catch (\Throwable $e) {
            $this->schritt()->markStepFailed($stepId, $e->getMessage());
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
}
