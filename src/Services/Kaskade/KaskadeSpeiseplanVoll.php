<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Support\Warteschlange;
use RuntimeException;

/**
 * Plan 98 · 3.3 S1 · P5 Speiseplan-Voll-Kaskade, verschoben aus {@see PlanningCascadeService} (reine Verschiebung): ein Gericht je
 * leerer Zyklus-Zelle ({@see starteSpeiseplanVollkaskade}, Vorschau {@see speiseplanVorschau}, Worker
 * {@see materialisiereSpeiseplanZelle}), Deckel in Wochen und Zellen. Step-Status über {@see KaskadenSchritt}, gemeinsame Helfer über
 * {@see KaskadeKontext}. Jobs, Tools und Tests rufen weiter die Fassade.
 */
class KaskadeSpeiseplanVoll
{
    /** Deckel gegen Runaway-Kosten: max. Zellen (= KI-Gericht-Generierungen) je Speiseplan-Voll-Kaskade. */
    /**
     * Wie viele Zyklus-Wochen ein Speiseplan-Lauf auf einmal baut (Dominique 2026-09-03:
     * „Deckel 6 Wochenplan").
     *
     * WOCHEN, nicht Zellen — weil die Küche in Wochen plant und weil eine Zellenzahl die Absicht
     * nicht ausdrücken kann: die Linienzahl ist pro Plan frei (Starter sind drei, es können mehr
     * sein), 6 Wochen sind also 90 Zellen bei drei Linien und 150 bei fünf. Ein fester
     * Zellen-Deckel hätte je nach Plan 6 oder 3,6 Wochen bedeutet, ohne dass es jemand sieht.
     *
     * Gezählt werden nur Wochen, in denen der Lauf wirklich etwas gestartet hat. Damit arbeitet
     * ein zweiter Lauf von selbst weiter: die Zellen der ersten sechs Wochen sind dann belegt,
     * werden übersprungen, und der Deckel greift erst wieder ab Woche 13.
     */
    private const SPEISEPLAN_MAX_WOCHEN = 6;

    /**
     * Absolute Notbremse gegen einen entarteten Plan (z. B. 20 Linien) — NICHT der eigentliche
     * Deckel, das sind die Wochen oben. 6 Wochen × 6 Linien × 5 Werktage = 180, darum 200: ein
     * normal breiter Plan läuft nie hier hinein, ein pathologischer wird gestoppt und gemeldet.
     *
     * Vorher stand hier 30 — bei einem Standard-Zyklus (4 Wochen × 3 Linien × 5 Werktage = 60
     * Zellen) fiel damit die HÄLFTE jedes Auftrags weg, und zwar unsichtbar. Der Wert war als
     * Kosten-Schutz gedacht; gerechnet kostet ein 30-Zellen-Lauf 0,50–3 $, ein 60er also
     * einstellige Dollar. Er schützte Centbeträge und warf dafür die halbe Arbeit weg.
     */
    private const SPEISEPLAN_MAX_ZELLEN = 200;

    /** @var array<int, string> Spec 57 · 10.2: Anzeigename je Eintrag-ID (ein Lauf, keine N+1). */
    private array $zellNamenMemo = [];

    /**
     * Speiseplan-Voll-Kaskade (P5): füllt leere Zellen des Zyklus (cycle_weeks × Mo–Fr × Mittag × Linien) mit
     * erfundenen Gerichten. Anders als Foodbook/Speisekarte (Slot → Concept) hält eine Zelle EIN Gericht — je
     * leerer Zelle ein Gericht-Step + {@see MaterializeSpeiseplanCellJob} (generiert + trägt via addEintrag ein).
     * Gedeckelt ({@see SPEISEPLAN_MAX_ZELLEN}) gegen Runaway-Kosten. Der Vermerk liegt in
     * `deckel_hinweise` (Schlüssel `speiseplan_zellen`) und erreicht über die Status-DTO die
     * Leitstelle — erst DAMIT ist es kein stiller Deckel.
     *
     * Vorher stand hier „die Zahl der übersprungenen Zellen steht im Run
     * (`params.gedeckelt_zellen_offen`) — kein stiller Deckel". Das war doppelt falsch: der
     * Schlüssel wurde von niemandem gelesen (die Status-DTO filtert `params` gegen
     * ALLOWED_GENERATION_PARAMS, weil dieser Beutel die LEITPLANKEN zeigt), und der Schreibweg
     * überschrieb `params` KOMPLETT statt zu mergen — womit er zusätzlich den
     * Leitplanken-Fallback tötete, und zwar genau bei den gedeckelten Läufen, die man am
     * ehesten nachlesen will. Ein Standard-Zyklus sind 4 Wochen × 3 Linien × 5 Werktage
     * = 60 leere Zellen; die Hälfte fiel damit unsichtbar weg.
     */
    public function starteSpeiseplanVollkaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, array $optionen): FoodAlchemistCascadeRun
    {
        $planId = (int) ($optionen['owner_id'] ?? 0);
        $plan = $planId > 0 ? FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->find($planId) : null;
        if ($plan === null) {
            throw new RuntimeException('Speiseplan nicht gefunden.');
        }
        // 98 · 3.3 S7 P0: Ein geerbter Plan ist sichtbar, gehört aber dem Besitzer-Team. Früh abweisen, statt einen Lauf anzulegen,
        // dessen Zellen danach alle am Riegel in SpeiseplanService::addEintrag scheitern.
        if (! $plan->isOwnedBy($team)) {
            throw new RuntimeException('Dieser Speiseplan gehört einem anderen Team. Leere Zellen mit KI füllen kann nur das Team, dem der Plan gehört.');
        }
        if ($plan->lines->isEmpty()) {
            throw new RuntimeException('Der Speiseplan hat keine Menü-Linien. Bitte zuerst im Reiter „Menü-Linien" eine Linie anlegen.');
        }

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => 'vollkaskade',
            'creative_mode' => $creativeMode,
            'brief' => 'Voll-Kaskade speiseplan #' . $planId,
            'status' => 'running',
            // Wie Foodbook/Speisekarte: EAGER (staged=false) — je leerer Zelle sofort ein Gericht-Job,
            // Sammel-Review im Editor. Explizit gesetzt (nicht DB-Default), s. starteVollkaskade.
            'staged' => false,
            'source_owner_type' => 'speiseplan',
            'source_owner_id' => $planId,
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
        ]);

        $start = \Illuminate\Support\Carbon::parse($plan->start_date ?? now())->startOfWeek();   // Montag
        $weeks = max(1, (int) ($plan->cycle_weeks ?? 1));
        $belegt = $this->speiseplanBelegt($plan);

        // Spec-42-Vollzug S4: der Session-Brief (Anlass/Saison/Richtung) steuert jede Zell-Generierung mit
        // („Speiseplan aus Brief"). Leer = unverändertes Alt-Verhalten (nur Linien-Brief).
        $planKontext = trim((string) ($session?->brief ?? ''));
        $idx = 0;
        $offen = 0;
        // Wochen-Deckel: gezählt werden nur Wochen, in denen der Lauf wirklich etwas gestartet
        // hat. Eine Woche, deren Zellen schon belegt sind, verbraucht also KEIN Budget — genau
        // dadurch nimmt ein zweiter Lauf die nächsten sechs Wochen und nicht wieder die ersten.
        $wochenMitArbeit = 0;
        $wochenOffen = 0;
        $zellenInOffenenWochen = 0;
        // Spec 57 · 10.2: Namen/Diät der vorhandenen Einträge einmal laden (Leitplanken je Zelle).
        $plan->loadMissing(['entries.dish', 'entries.concept']);
        $this->zellNamenMemo = [];
        foreach (range(1, $weeks) as $week) {
            $vorWoche = $idx;
            $zellen = $this->leereSpeiseplanZellen($plan, $start, $week, $belegt);
            $veganZiel = $this->speiseplanVeganZiele($plan, $zellen);
            if ($wochenMitArbeit >= self::SPEISEPLAN_MAX_WOCHEN) {
                // Budget aufgebraucht: zählen, was diese Woche gekostet hätte, und weiter —
                // nicht abbrechen, sonst wüssten wir den Rest nicht.
                if ($zellen !== []) {
                    $wochenOffen++;
                    $zellenInOffenenWochen += count($zellen);
                }

                continue;
            }
            foreach ($zellen as [$datum, $linie, $meal]) {
                if ($idx >= self::SPEISEPLAN_MAX_ZELLEN) {
                    $offen++;
                    continue;
                }
                $brief = ($planKontext !== '' ? 'Rahmen: ' . $planKontext . ' — ' : '')
                    . $this->speiseplanZellBrief($linie, $meal)
                    . $this->speiseplanZellLeitplanken($plan, $datum, $linie, $meal, ($veganZiel[$datum] ?? null) === (int) $linie->id);
                $step = FoodAlchemistCascadeRunStep::create([
                    'team_id' => $team->id,
                    'cascade_run_id' => $run->id,
                    'parent_step_id' => null,
                    'kind' => 'gericht',
                    'label' => Str::limit($linie->name . ' · ' . $datum, 120),
                    'status' => 'running',
                    'sort' => $idx,
                ]);
                // Die größte Menge im System: bis 90 Zellen je Klick. Eigene Schlange,
                // damit sie nichts anderes verdrängt — und damit die Sub-Rezepte dieser
                // Zellen parallel auf `rezepte` weiterlaufen statt dahinter zu warten.
                MaterializeSpeiseplanCellJob::dispatch(
                    $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
                    $planId, $datum, $meal, (int) $linie->id, $brief, (int) $step->id, $session?->id
                )->onQueue(Warteschlange::gerichte());
                $idx++;
            }
            if ($idx > $vorWoche) {
                $wochenMitArbeit++;
            }
        }
        // `verlangt` = alle LEEREN Zellen. Belegte sind oben schon per `continue` raus, `$idx`
        // zählt nur die gestarteten — die Summe braucht also keinen zweiten Zähler.
        $run->vermerkeDeckel(
            'speiseplan_wochen',
            self::SPEISEPLAN_MAX_WOCHEN,
            $wochenMitArbeit + $wochenOffen,
            $wochenOffen,
            $this->deckelTextWochen($wochenMitArbeit, $wochenOffen, $zellenInOffenenWochen),
        );
        // Die Notbremse wird nur vermerkt, wenn sie wirklich gegriffen hat — bei einem normal
        // breiten Plan bleibt sie stumm, weil der Wochen-Deckel vorher bindet.
        $run->vermerkeDeckel(
            'speiseplan_zellen',
            self::SPEISEPLAN_MAX_ZELLEN,
            $idx + $offen,
            $offen,
            $this->deckelTextZellen($idx, $idx + $offen, $offen),
        );
        if ($idx === 0) {
            $run->update(['status' => 'done']);   // keine leere Zelle → nichts zu tun (kein Fehler)
        }

        return $run;
    }

    /**
     * Wie viele Zellen einer Zyklus-Woche noch leer sind — für die Deckel-Rechnung, ohne die
     * Woche zu bauen. Spiegelt die Schleifen-Bedingungen (Mo–Fr × Linien, belegte raus) exakt;
     * eine abweichende Zählung hier würde eine falsche Zahl in die Meldung schreiben, und eine
     * falsche Zahl ist schlimmer als keine Meldung.
     *
     * @param  array<string, bool>  $belegt
     */
    private function leereSpeiseplanZellen(FoodAlchemistSpeiseplan $plan, \Illuminate\Support\Carbon $start, int $week, array $belegt): array
    {
        // Spec 57 · Paket 9 + E11: Öffnungstage des Plans (Standard Mo–Fr) × Linien; jede Linie
        // in IHRER Mahlzeit (ohne feste Mahlzeit: Mittag, wie bisher).
        $leer = [];
        foreach ($plan->oeffnungstage() as $weekday) {
            $datum = $start->copy()->addDays(($week - 1) * 7 + ($weekday - 1))->format('Y-m-d');
            foreach ($plan->lines as $linie) {
                $meal = ($linie->meal ?? '') !== '' ? (string) $linie->meal : 'mittag';
                if (! isset($belegt[$datum . '|' . $meal . '|' . (int) $linie->id])) {
                    $leer[] = [$datum, $linie, $meal];
                }
            }
        }

        return $leer;
    }

    /** @return array<string, bool>  belegte Zellen als `Y-m-d|mahlzeit|line_id` */
    private function speiseplanBelegt(FoodAlchemistSpeiseplan $plan): array
    {
        $belegt = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date !== null) {
                $belegt[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id] = true;
            }
        }

        return $belegt;
    }

    /** Zell-Brief: Mahlzeit + Rolle der Linie + Linienname (+ vegetarisch). Mittag bleibt „Mittagsgericht“. */
    private function speiseplanZellBrief(\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie $linie, string $meal): string
    {
        $wort = [
            'fruehstueck' => 'Frühstücksgericht', 'mittag' => 'Mittagsgericht',
            'abend' => 'Abendgericht', 'snack' => 'Snack',
        ][$meal] ?? 'Mittagsgericht';
        $rolle = $linie->role !== null && $linie->role !== 'hauptgang'
            ? (\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie::ROLLEN[$linie->role] ?? null)
            : null;

        return $wort . ($rolle !== null ? ' (' . $rolle . ')' : '')
            . ' für die Linie „' . $linie->name . '“' . ($linie->is_vegetarian ? ' (vegetarisch)' : '') . '.';
    }

    /**
     * Spec 57 · 10.2: Welche leere Zelle eines Tages soll das vegane Gericht liefern, wenn der Tag
     * noch keins hat? Bevorzugt eine vegetarische Hauptgang-Linie, sonst die erste Hauptgang-Linie.
     * Tage mit veganem Eintrag bekommen kein Ziel.
     *
     * @param  list<array{0:string, 1:\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie, 2:string}>  $zellen
     * @return array<string, int>  Datum → line_id
     */
    private function speiseplanVeganZiele(FoodAlchemistSpeiseplan $plan, array $zellen): array
    {
        $svc = app(SpeiseplanService::class);
        $agg = app(ConcepterAggregateService::class);
        $ziele = [];
        foreach (collect($zellen)->groupBy(fn ($z) => $z[0]) as $datum => $tagZellen) {
            $hatVegan = $plan->entries->contains(fn ($e) => $e->entry_date?->format('Y-m-d') === $datum
                && $agg->allergenRollupFromGerichte($svc->eintragGerichte($e))['is_vegan']);
            if ($hatVegan) {
                continue;
            }
            $haupt = $tagZellen->filter(fn ($z) => $z[1]->istHauptgang());
            $wahl = $haupt->first(fn ($z) => (bool) $z[1]->is_vegetarian) ?? $haupt->first();
            if ($wahl !== null) {
                $ziele[$datum] = (int) $wahl[1]->id;
            }
        }

        return $ziele;
    }

    /**
     * Spec 57 · 10.2: Leitplanken aus dem PLANZUSTAND für eine Zelle — statt jede Zelle isoliert zu
     * erzeugen. (1) Was in ±max(6, Mindestabstand) Tagen schon steht, nicht wiederholen. (2) Fehlt
     * dem Tag ein veganes Gericht, fragt genau eine Zelle danach. (3) Preis und Wareneinsatz-Ziel
     * der Linie (Backlog #90). Heuristisch: Zellen eines Laufs laufen parallel und sehen einander
     * nicht — verbleibende Wiederholungen zeigt die Rail des Editors (LLM_GUIDE §3).
     */
    private function speiseplanZellLeitplanken(FoodAlchemistSpeiseplan $plan, string $datum, \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie $linie, string $meal, bool $veganGewuenscht): string
    {
        $svc = app(SpeiseplanService::class);
        $tag = \Illuminate\Support\Carbon::parse($datum)->startOfDay();
        $fenster = max(6, (int) $plan->min_abstand_tage);
        $namen = $plan->entries
            ->filter(fn ($e) => $e->entry_date !== null && $e->meal === $meal && abs((int) $e->entry_date->copy()->startOfDay()->diffInDays($tag, false)) <= $fenster)
            ->map(fn ($e) => $this->zellNamenMemo[(int) $e->id] ??= $svc->eintragName($e))
            ->unique()->take(10)->values()->all();

        $teile = [];
        if ($namen !== []) {
            $teile[] = 'nicht wiederholen, steht in ±' . $fenster . ' Tagen schon im Plan: ' . implode('; ', $namen);
        }
        if ($veganGewuenscht) {
            $teile[] = 'dem Tag fehlt noch ein veganes Gericht — bitte vegan';
        }
        $preis = $linie->manuellerPreis();
        if ($preis !== null) {
            $teile[] = 'Verkaufspreis netto ' . number_format($preis, 2, ',', '') . ' € je Portion';
        }
        if ($linie->target_wes_max_pct !== null) {
            $teile[] = 'Wareneinsatz-Ziel ' . ($linie->target_wes_min_pct !== null ? number_format((float) $linie->target_wes_min_pct, 0) . '–' : 'bis ')
                . number_format((float) $linie->target_wes_max_pct, 0) . ' %'
                . ($preis !== null ? ' (EK höchstens ' . number_format($preis * (float) $linie->target_wes_max_pct / 100, 2, ',', '') . ' €)' : '');
        }

        return $teile !== [] ? ' Leitplanken aus dem Plan: ' . implode(' · ', $teile) . '.' : '';
    }

    /**
     * Spec 57 · Paket 10.1: Vorschau vor dem Start — wie viele Zellen sind leer und wie viele
     * würde EIN Lauf jetzt starten (Wochen-Deckel + Notbremse wie {@see starteSpeiseplanVollkaskade}).
     * Nur lesend, legt nichts an.
     *
     * @return array{linien:int, leer:int, dieser_lauf:int, wochen:int, gedeckelt:bool}
     */
    public function speiseplanVorschau(Team $team, int $planId): array
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->find($planId);
        if ($plan === null) {
            throw new RuntimeException('Speiseplan nicht gefunden.');
        }
        $start = \Illuminate\Support\Carbon::parse($plan->start_date ?? now())->startOfWeek();
        $weeks = max(1, (int) ($plan->cycle_weeks ?? 1));
        $belegt = $this->speiseplanBelegt($plan);
        $leer = 0;
        $diesmal = 0;
        $wochenMitArbeit = 0;
        foreach (range(1, $weeks) as $week) {
            $n = count($this->leereSpeiseplanZellen($plan, $start, $week, $belegt));
            $leer += $n;
            if ($n > 0 && $wochenMitArbeit < self::SPEISEPLAN_MAX_WOCHEN) {
                $diesmal += $n;
                $wochenMitArbeit++;
            }
        }
        $diesmal = min($diesmal, self::SPEISEPLAN_MAX_ZELLEN);

        return [
            'linien' => $plan->lines->count(), 'leer' => $leer, 'dieser_lauf' => $diesmal,
            'wochen' => $weeks, 'gedeckelt' => $diesmal < $leer,
        ];
    }

    /**
     * Der Satz für den Wochen-Deckel. Nennt WOCHEN, weil der Mensch in Wochen plant — die
     * Zellenzahl steht als Größenordnung dahinter.
     *
     * Die Handlung ist hier einfacher als beim Zell-Deckel: weil nur Wochen MIT Arbeit gezählt
     * werden, findet ein zweiter Lauf die ersten sechs belegt und nimmt die nächsten sechs. Es
     * braucht kein Vorrücken des Startdatums und keine Bedingung.
     */
    private function deckelTextWochen(int $gebaut, int $offen, int $zellen): string
    {
        return sprintf(
            'Wochen %d–%d noch offen (%d Zellen). Ein KI-Lauf füllt höchstens %d Wochen. Sobald die '
            . 'ersten %d im Plan stehen, noch einmal „Leere Zellen mit KI füllen" starten, dann kommen die nächsten dran.',
            $gebaut + 1,
            $gebaut + $offen,
            $zellen,
            self::SPEISEPLAN_MAX_WOCHEN,
            $gebaut,
        );
    }

    /**
     * Der Satz, den der Mensch liest, wenn der Zell-Deckel gegriffen hat. EINE Formulierungsstelle —
     * die Fläche liest den gespeicherten `text` und formatiert nichts nach.
     *
     * Zwei Zahlen, nicht eine: »30 nicht erzeugt« sagt nicht, wie groß der Auftrag war. 30 von 33
     * ist eine Randlage, 30 von 60 der halbe Plan.
     *
     * Die Bedingung »wenn die ersten im Plan stehen« ist kein Beiwerk, sondern der Unterschied
     * zwischen richtig und falsch: {@see materialisiereSpeiseplanZelle} trägt den Eintrag sofort
     * nach der Generierung ein, nicht erst bei der Freigabe. Ein zweiter Lauf baut `$belegt` aus
     * den Einträgen und nimmt darum exakt den Rest — klickt der Mensch aber sofort, sind die
     * Zellen noch leer und er erzeugt dieselben Gerichte ein zweites Mal.
     */
    private function deckelTextZellen(int $gestartet, int $verlangt, int $offen): string
    {
        return sprintf(
            '%d von %d Zellen gestartet, %d %s leer. Sobald die ersten im Plan stehen, im Speiseplan '
            . 'noch einmal „Leere Zellen mit KI füllen" starten. Der Lauf nimmt nur die leeren Zellen.',
            $gestartet,
            $verlangt,
            $offen,
            $offen === 1 ? 'bleibt' : 'bleiben',
        );
    }

    /**
     * Worker-Logik (aus {@see MaterializeSpeiseplanCellJob}): erdet EINE Speiseplan-Zelle zu einem VK-Gericht
     * ({@see RecipeGeneratorService}, vkModus) und trägt es via {@see SpeiseplanService::addEintrag} in die
     * Zelle (Datum/Mahlzeit/Linie) ein; Trend-Lineage + Rückmeldung an den Step.
     */
    public function materialisiereSpeiseplanZelle(Team $team, int $planId, string $entryDate, string $meal, int $lineId, string $brief, int $stepId, ?int $planningSessionId = null): void
    {
        try {
            // Fan-out erbt die Leitplanken der Session (Regler am Planung-Go); Steuer-Keys gewinnen.
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
            app(SpeiseplanService::class)->addEintrag($team, $planId, [
                'entry_date' => $entryDate, 'mahlzeit' => $meal, 'line_id' => $lineId, 'sales_recipe_id' => (int) $recipe->id,
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
