<?php

namespace Platform\FoodAlchemist\Services\Kaskade;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\Concerns\KaskadeKiWarten;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;

/**
 * Plan 98 · 3.3 S1 · Lese-Seite der Kaskade für Fläche und MCP, verschoben aus {@see PlanningCascadeService} (reine
 * Verschiebung): neuester Lauf einer Planung, KI-Status des Teams, Owner-Kontext der Session und der flache Lauf-Status.
 * Schreibt nichts. Die Fassade reicht die öffentlichen Methoden weiter; Tools, Livewire und Tests rufen weiter die Fassade.
 */
class KaskadeAbfragen
{
    use KaskadeKiWarten;   // externer KI-Weg (Lauf 62) + Lauf-Status-Hinweis

    /** Ein team-sichtbarer Lauf inkl. Steps (oder null) — liegt in {@see KaskadeKontext}; hier für laufStatus und den Trait. */
    private function lauf(Team $team, int $runId): ?FoodAlchemistCascadeRun
    {
        return app(KaskadeKontext::class)->lauf($team, $runId);
    }

    /** Neuester Lauf einer Planung (für das Cockpit-Polling), null wenn keiner. */
    public function letzterLauf(Team $team, int $planningSessionId): ?FoodAlchemistCascadeRun
    {
        return FoodAlchemistCascadeRun::visibleToTeam($team)
            ->where('planning_session_id', $planningSessionId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Spec 53 / Paket C: Globaler KI-Status fürs Planung-Kopf — EIN Aggregat über alle Steps des
     * Teams (nicht nur die des aktuell offenen Laufs), damit „N warten · M laufen" auch sichtbar ist,
     * wenn gerade kein Lauf im Cockpit geöffnet ist. Team-EIGENE Steps (nicht die Team-Ancestry —
     * der Status soll zeigen, was DIESES Team gerade selbst anstößt).
     *
     * @return array{wartend:int, laufend:int, aktuelle_phase:?string, fehler_24h:int, queue:?int}
     */
    public function kiStatusFuerTeam(Team $team): array
    {
        $steps = FoodAlchemistCascadeRunStep::where('team_id', $team->id);

        $wartend = (clone $steps)->where('status', 'queued')->count();
        // „laufend" = belegt gerade tatsächlich einen Worker-Slot: primäre Generierung (status=running)
        // ODER eine sekundäre Phase (Anreicherung/Bilder/Konformität), die auch nach der Freigabe
        // (status=freigegeben/done) noch läuft und deshalb kein `running` mehr trägt.
        $laufend = (clone $steps)->where(function ($q) {
            $q->where('status', 'running')->orWhereNotNull('phase');
        })->count();
        $aktuellePhase = (clone $steps)->whereNotNull('phase')
            ->orderByDesc('phase_at')
            ->value('phase');
        $fehler24h = (clone $steps)->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        // Optionale Queue-Tiefe: nur wenn die `jobs`-Tabelle existiert UND die Default-Queue-Connection
        // `database` ist (sonst liegt die Warteschlange z. B. in Redis — kein Job hierfür zu erfinden).
        $queue = null;
        try {
            if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                $queue = (int) \Illuminate\Support\Facades\DB::table('jobs')->count();
            }
        } catch (\Throwable) {
            $queue = null;
        }

        return [
            'wartend' => $wartend,
            'laufend' => $laufend,
            'aktuelle_phase' => $aktuellePhase,
            'fehler_24h' => $fehler24h,
            'queue' => $queue,
        ];
    }

    /**
     * E1b (Spec 40): Owner-Kontext der Session für die Leitstelle — macht den Einbahn-Sprung zum
     * sichtbaren Round-Trip: WOFÜR wird hier geplant (Ausgabe-Modul + Name) + der Rückweg dorthin.
     * Liest den jüngsten Lauf der Session MIT Ausgabe-Owner (`source_owner_type`/`_id` — die sitzen
     * auf dem Lauf, nicht der Session) und löst Anzeige-Name + Rück-Route inkl. Deep-Link-Param auf.
     * `null`, wenn die Session keinen Ausgabe-Owner trägt (freie Cockpit-Planung) → dann kein Banner.
     *
     * @return array{owner_type:string, owner_id:int, typ_label:string, name:string, route:string, route_param:array<string,int>}|null
     */
    public function ownerKontext(Team $team, int $sessionId): ?array
    {
        $run = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->where('planning_session_id', $sessionId)
            ->whereNotNull('source_owner_type')
            ->whereNotNull('source_owner_id')
            ->orderByDesc('id')->first();
        if ($run === null) {
            return null;
        }
        $type = (string) $run->source_owner_type;
        $id = (int) $run->source_owner_id;
        [$typLabel, $name, $route, $param] = match ($type) {
            'foodbook' => ['Foodbook', \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($id)->value('label'), 'foodalchemist.foodbooks.index', 'fb'],
            'speisekarte' => ['Speisekarte', \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.speisekarte.index', 'sk'],
            'speiseplan' => ['Speiseplan', FoodAlchemistSpeiseplan::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.speiseplan.index', 'sp'],
            'offer' => ['Angebot', \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.angebote.index', 'sel'],
            'format' => ['Format', \Platform\FoodAlchemist\Models\FoodAlchemistFormat::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.formate.index', 'fmt'],
            default => [null, null, null, null],
        };
        if ($route === null) {
            return null;
        }

        return [
            'owner_type' => $type,
            'owner_id' => $id,
            'typ_label' => $typLabel,
            'name' => (string) ($name !== null && $name !== '' ? $name : $typLabel . ' #' . $id),
            'route' => $route,
            'route_param' => [$param => $id],
        ];
    }

    /**
     * Etappe 9 (MCP): der READ-ONLY Kaskaden-Status eines Laufs als flache, KI-lesbare Struktur —
     * die Statuszahlen, die das Cockpit zeigt, aber headless. Team-gescopt über {@see lauf()}
     * (visibleToTeam = Read-Contract); `null`, wenn der Lauf für das Team nicht sichtbar ist.
     *
     * Rein ableitend, keine Erfindung: Lauf-Kopf + je Ebene (`kind`) ein Status-Aggregat + die
     * Einzel-Schritte (inkl. Anreicherungs-/Bild-Status aus `deferred`) + ein `hinweis`, der den
     * bestehenden Run-Status (running|review|done|failed) in einen Handlungs-Satz übersetzt
     * (Freigabe/Fortsetzen bleiben human-only, kein MCP-Trigger).
     *
     * @return array<string, mixed>|null
     */
    public function laufStatus(Team $team, int $runId): ?array
    {
        $run = $this->lauf($team, $runId);
        if ($run === null) {
            return null;
        }

        $steps = $run->steps->sortBy('sort')->values();
        // Externer KI-Weg: wartet ein Schritt auf eine Antwort von außen, steht das an keinem Step-Feld (die Job-Transaktion rollt beim
        // Warten zurück, auch die Phase) — die offenen Aufträge selbst sind die Wahrheit.
        $kiWartend = $this->kiWartend($steps);

        $schritte = $steps->map(function (FoodAlchemistCascadeRunStep $s) use ($kiWartend): array {
            $deferred = is_array($s->deferred) ? $s->deferred : [];
            $ki = $kiWartend[(int) $s->id] ?? null;
            $snapshot = is_array($s->context_snapshot) ? $s->context_snapshot : [];

            return array_filter([
                'id' => (int) $s->id,
                'ebene' => (string) $s->kind,
                'label' => (string) $s->label,
                'status' => (string) $s->status,
                // Spec 53 / Paket C(-Nachtrag): DB-Wahrheit der laufenden Zwischen-Phase (Cockpit + MCP)
                // — die Terminal-Phasen (Entwurf bereit/Fehler) ergeben sich aus `status`, nicht aus
                // `phase`. queued/running OHNE gesetzte Phase heißt „dispatcht, aber der Worker hat den
                // Job noch nicht aufgenommen" (Cockpit-Text „eingereiht — wartet auf Worker",
                // step-zeile.blade.php) — derselbe Text hier, damit ein MCP-Konsument dieselbe
                // Unterscheidung sieht, statt sie aus Status+fehlender Phase selbst zu rekonstruieren.
                // Wartet der Schritt auf eine externe KI-Antwort, ist DAS die Phase — nicht „wartet auf Worker" (Lauf 62, io 13.11.2026:
                // der Worker war frei, der Lauf wartete auf ki_auftraege.ANTWORT, Status und Cockpit zeigten auf die Queue).
                'phase' => $ki !== null
                    ? self::kiWartetText((int) $ki['anzahl'])
                    : ($s->phase ?: (in_array($s->status, ['queued', 'running'], true) ? 'eingereiht — wartet auf Worker' : null)),
                'wartet_auf_ki' => $ki,
                // Spec 53 / Paket A: `context_snapshot['timings']` je Step (generator_ms/context_ms/…),
                // sobald Paket A sie schreibt — Peter zeigt sie nur an, Fallback null bis dahin.
                'timings' => (! empty($snapshot['timings']) && is_array($snapshot['timings'])) ? $snapshot['timings'] : null,
                'tiefe' => (int) $s->depth,
                'ref_type' => $s->ref_type,
                'ref_id' => $s->ref_id !== null ? (int) $s->ref_id : null,
                'parent_step_id' => $s->parent_step_id !== null ? (int) $s->parent_step_id : null,
                'anreicherung' => $deferred['enrich']['status'] ?? null,
                // Tiefe des Anreicherungs-Passes: bei complete_coverage=false bleiben Schritte,
                // Sensorik, Zeiten, Equipment und Pairings leer. Headless war das nicht
                // unterscheidbar — 'done' sah in beiden Faellen gleich aus.
                'anreicherung_tief' => isset($deferred['enrich']['tief'])
                    ? (bool) $deferred['enrich']['tief'] : null,
                // Reifegrad einer UEBERNAHME (`skipped`). Ohne das ist per MCP nicht erkennbar,
                // dass „uebernommen" auf ein draft mit 0 Schritten zeigt — genau der Fall, der
                // Lauf 65 als „abgeschlossen" meldete.
                'uebernahme_reif' => isset($deferred['reuse']['reif'])
                    ? (bool) $deferred['reuse']['reif'] : null,
                'uebernahme_luecken' => ! empty($deferred['reuse']['luecken'])
                    ? array_values((array) $deferred['reuse']['luecken']) : null,
                'bilder' => $deferred['bilder']['status'] ?? null,
                'fehler' => $s->error,
                // E-P0 (Spec 40): Attach-Fehler auch headless sichtbar — das Konzept ist erzeugt, hängt aber
                // nicht am Ausgabe-Kapitel/der Rubrik (behebbar per haengeKonzeptNach). Nur gesetzt, wenn offen.
                'attach_fehler' => $deferred['attach_error'] ?? null,
                // Verwendetes Wissen je Step (aus context_snapshot, geschrieben von RecipeGenerationContextService::build):
                // welche Wissens-Dossiers real in den Prompt geflossen sind — damit die Erdung headless prüfbar ist.
                'wissen' => ! empty($snapshot['knowledge_files']) ? $snapshot['knowledge_files'] : null,
                // Spec 53 Paket B Aufgabe 6 — was gebaut, aber NICHT gesendet wurde, additiv neben
                // 'wissen': 'retrieval' aus dem Budget-Schnitt der Fuzzy-Discovery, 'kanon' aus
                // gedroppten wenn_platz-Dossiers (RecipeDependencyWorkflowService::afterGenerated
                // korrigiert diesen Zweig nach dem Gateway-Call). Nur der Snapshot-Key wird gelesen —
                // kein neuer Rechenweg. `null` statt einer leeren Struktur, solange nichts verworfen
                // wurde (kein Etikett ohne Landebahn).
                'wissen_verworfen' => (static function () use ($snapshot): ?array {
                    $d = is_array($snapshot['knowledge_dropped'] ?? null) ? $snapshot['knowledge_dropped'] : [];
                    $retrieval = is_array($d['retrieval'] ?? null) ? array_values($d['retrieval']) : [];
                    $kanon = is_array($d['kanon'] ?? null) ? array_values($d['kanon']) : [];

                    return ($retrieval === [] && $kanon === []) ? null : array_filter([
                        'retrieval' => $retrieval !== [] ? $retrieval : null,
                        'kanon' => $kanon !== [] ? $kanon : null,
                    ]);
                })(),
            ], static fn ($v): bool => $v !== null && $v !== '');
        })->all();

        // Task 6 (#505-Nachtrag 2026-09, Lauf 65 „abgeschlossen" trotz 0-Schritte-Draft):
        // RecipeService::reifegrad() bleibt die einzige Wahrheit — hier nur aggregiert, was
        // RecipeDependencyWorkflowService::afterGenerated je Step bereits in deferred.reuse
        // ablegt, damit der Run-Kopf (nicht nur die einzelne Step-Zeile) zeigt, ob "übernommen"
        // wirklich fertig heißt.
        $uebernahme = $this->zaehleUebernommenUnreif($steps);

        $stufen = $steps->groupBy('kind')->map(static function ($group, $kind): array {
            return [
                'ebene' => (string) $kind,
                'gesamt' => $group->count(),
                'geplant' => $group->where('status', 'geplant')->count(),
                'laufend' => $group->whereIn('status', ['queued', 'running'])->count(),
                'entwurf_offen' => $group->where('status', 'done')->count(),   // fertiger Draft, wartet auf Freigabe (Gate 2)
                'freigegeben' => $group->where('status', 'freigegeben')->count(),
                'uebernommen' => $group->where('status', 'skipped')->count(),   // Reuse-Treffer
                'verworfen' => $group->where('status', 'verworfen')->count(),
                'fehlgeschlagen' => $group->where('status', 'failed')->count(),
            ];
        })->values()->all();

        // Die real wirksamen Leitplanken dieses Laufs — gegen die Whitelist gefiltert, damit nur die Regler
        // (nicht die Flow-Steuer-Keys owner_type/cascade_step_id/…) sichtbar werden. So ist per MCP prüfbar,
        // WOMIT der Lauf lief (Kern des „Leitplanken prüfen").
        // Quelle: bei Leaf-Läufen (rezept|gericht|concept) ist run.params die am START eingefrorene Kopie;
        // bei Vollkaskaden bleibt run.params leer (der Fan-out liest die Session live) → Fallback auf die
        // Session-generation_params, sonst wäre die wirksame Steuerung dort unsichtbar.
        $quelle = is_array($run->params) ? $run->params : [];
        if ($quelle === [] && $run->planning_session_id !== null) {
            $sess = app(PlanningSessionService::class)->get($team, (int) $run->planning_session_id);
            $quelle = is_array($sess?->generation_params) ? $sess->generation_params : [];
        }
        $leitplanken = array_intersect_key(
            $quelle,
            array_flip(FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS)
        );

        return [
            'run_namespace' => 'planung_kaskade',
            'lauf' => array_filter([
                'id' => (int) $run->id,
                'scope' => (string) $run->scope,
                'status' => (string) $run->status,
                'gestuft' => (bool) $run->staged,
                'creative_mode' => (string) $run->creative_mode,
                'planning_session_id' => $run->planning_session_id !== null ? (int) $run->planning_session_id : null,
                'origin_dish_idea_id' => $run->origin_dish_idea_id !== null ? (int) $run->origin_dish_idea_id : null,
                'uebernommen' => $uebernahme['gesamt'] > 0 ? $uebernahme['gesamt'] : null,
                'uebernommen_unreif' => $uebernahme['gesamt'] > 0 ? $uebernahme['unreif'] : null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'leitplanken' => $leitplanken === [] ? null : $leitplanken,
            'stufen' => $stufen,
            'schritte' => $schritte,
            'kohaerenz_warnung' => is_array($run->cohesion_warning) ? $run->cohesion_warning : null,
            // Was der Lauf NICHT erzeugt hat. Steht hier NEBEN den Leitplanken und nicht darin:
            // `params` wird oben gegen ALLOWED_GENERATION_PARAMS gefiltert, weil es die wirksame
            // STEUERUNG zeigen soll — ein Ergebnis-Hinweis darin wurde deshalb weggeworfen. Genau
            // das ist der Grund, warum `params.gedeckelt_zellen_offen` seit seiner Einführung
            // niemanden erreicht hat, obwohl der Docblock „kein stiller Deckel" behauptete.
            'deckel_hinweise' => is_array($run->deckel_hinweise) && $run->deckel_hinweise !== []
                ? array_values($run->deckel_hinweise)
                : null,
            // Offene KI-Aufträge des Laufs (externer Weg) mit der nächsten Aktion — null, wenn der Lauf auf keine Antwort wartet.
            'ki_auftraege_offen' => self::kiAuftraegeOffenBlock($kiWartend, (int) $run->id),
            'hinweis' => $this->laufStatusHinweis((string) $run->status, $steps, count(self::kiIds($kiWartend)), (int) $run->id, (string) ($run->created_via ?? '')),
        ];
    }

    /**
     * `skipped`-Steps (Bestands-Übernahme) + wie viele davon laut `deferred.reuse.reif`
     * UNREIF sind. `RecipeService::reifegrad()` bleibt die einzige Quelle für „reif" — hier
     * wird nur das von `RecipeDependencyWorkflowService::afterGenerated` bereits geschriebene
     * deferred-Feld gezählt (fehlt `reuse` ganz, zählt der Step nicht mit — kein Reuse-Fall).
     *
     * @return array{gesamt: int, unreif: int, unreif_labels: list<string>}
     */
    private function zaehleUebernommenUnreif(mixed $steps): array
    {
        $uebernommen = collect($steps)->where('status', 'skipped');
        $unreif = $uebernommen->filter(static function ($s): bool {
            $reuse = is_array($s->deferred['reuse'] ?? null) ? $s->deferred['reuse'] : null;

            return $reuse !== null && ! ($reuse['reif'] ?? false);
        });

        return [
            'gesamt' => $uebernommen->count(),
            'unreif' => $unreif->count(),
            'unreif_labels' => $unreif->pluck('label')->filter()->values()->all(),
        ];
    }
}
