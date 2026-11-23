<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Livewire\Concerns\PlanBestehendesConcept;
use Platform\FoodAlchemist\Livewire\Concerns\PlanGerichtVorgaben;
use Platform\FoodAlchemist\Livewire\Concerns\PlanKonzeptAusBrief;
use Platform\FoodAlchemist\Livewire\Concerns\PlanRezepturFuerGericht;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TitelVorschlagService;
use Platform\FoodAlchemist\Services\Plattform\WorkerHealthService;
use Platform\FoodAlchemist\Support\Planung\DiktatZiele;
use Platform\FoodAlchemist\Support\Planung\KaskadenStart;
use Platform\FoodAlchemist\Support\Planung\LeitplankenUebernahme;
use Platform\FoodAlchemist\Support\Planung\PlanungVokabular;
use Platform\FoodAlchemist\Support\Planung\ReglerParams;

/**
 * Planungs-Leitstelle · Erstell-Reiter Basisrezept, Gericht und Konzept (Spec 85 C1 PR-4, Verschiebung aus {@see Index}).
 *
 * EINE Komponente für alle drei Scopes (`eingabe`/`regler` sind Arrays je Scope): Briefing, Leitplanken, Vorlagen, Suchbegriffe,
 * Titelvorschlag, Wissens-Vorschau, KI-Kopf-Plan und der Go. Konstanten bleiben in Index (Partials und ReglerParams lesen sie dort).
 *
 * Zusammenspiel mit dem Parent (gekeyt je Session, `sessionId` gesperrt, `laeuft` reaktiv als Sperre):
 *  · Brief → Parent: `planung.lauf-gestartet(laufId)` nach dem Go, `planung.brief-gespiegelt` nach dem Spiegeln auf die Session,
 *    `planung.ungespeichert` bei jeder Eingabe-/Leitplanken-Änderung, `planung.entwurf-gespeichert` nach dem Sichern des Entwurfs,
 *    `planung.meldung(text, art)` für Meldung/Fehler im Modal-Kopf (nur bei Änderung, {@see rendering()}).
 *  · Parent → Brief: `planung.speichern(sessionId, scope)` sichert den Entwurf aller Reiter (Briefing mitspeichern).
 *  · Parent/Kinder → Brief: `planung.brief-uebernehmen(scope, werte)` (Diktat hängt an, Composer füllt leer und setzt den Seed-Pin),
 *    `planung.trend-uebernommen(scope)` (Ziel-Ebene überschreiben, andere nur leer füllen; kein Remount, Entscheid Kurator).
 *  · Agenten-Panels: `voice.formularstand-aktualisiert` bei jedem Render, `voice.komponenten-uebernahme` hierher.
 */
class Brief extends Component
{
    use HandyLesebereich, PlanBestehendesConcept, PlanGerichtVorgaben, PlanKonzeptAusBrief, PlanRezepturFuerGericht;   // PlanKonzeptAusBrief: 98 · 2.6c Brief vorbelegen

    /** Aktive Planungs-Session des Parents. Setzt nur der Parent beim Mount (Kind je Session gekeyt). */
    #[Locked]
    public ?int $sessionId = null;

    /** Schreibgeschützte Kopie aus dem Parent: ein Lauf rechnet → Start-, Plan- und Vorlagen-Knöpfe gesperrt. */
    #[Reactive]
    public bool $laeuft = false;

    /** #1b Grounding-Preview: welches Wissen/Pairing/Template ein Basisrezept-Lauf ziehen würde (on-demand, ohne Generierung). */
    public ?array $wissenVorschau = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    // ── Leitstelle: PER-TAB Eingabe + Leitplanken (jeder Scope eigener Zustand) ──
    /**
     * Eingabe je Creation-Tab (rezept|gericht|concept): Titel/Beschreibung/Kreativ-Modus.
     * Jeder Tab ist UNABHÄNGIG — Werte auf einem Tab wirken nicht auf die anderen. In mount()
     * initialisiert. Am Go zählt der Satz des Start-Tabs.
     * @var array<string,array{titel:string,brief:string,creative_mode:string}>
     */
    public array $eingabe = [];

    /**
     * Richtungs-Regler (Leitplanken) JE Scope — jeder Tab hat einen eigenen kompletten Satz
     * (inkl. favoriten/favoriten_conv_only/ziel_vk/voll_anreichern/ki_bilder). In mount() je Tab
     * aus REGLER_DEFAULT kopiert. **Kaskaden-Regel (User-Entscheid 2026-08-14):** am Go zählt NUR
     * der Satz des Start-Tabs; er wird als `generation_params` persistiert und propagiert die ganze
     * Kaskade nach unten (Start-Tab gilt für alles darunter). Spiegelt Generator-/VkGenerator-Modal.
     * @var array<string,array<string,mixed>>
     */
    public array $regler = [];

    /**
     * Spec 55: welche `$regler[$scope]`-Felder zuletzt vom Sprachbefehl-Agenten übernommen
     * wurden (`$scope => [$feld => true]`) — reine Anzeige-Markierung für die Leitplanken-UI
     * („vom Agenten"), kein eigener Speicherort. Ein manueller Edit des Feldes löscht seine
     * eigene Markierung nicht automatisch (kein Watcher pro Feld) — vertretbar für eine reine
     * Kennzeichnung, sie verschwindet spätestens beim nächsten Tab-Wechsel/Neuladen.
     */
    public array $reglerVonAgent = [];

    /**
     * Foodpairing-Composer (2026-08-24): PERSISTENTES Seed-Pin für den „in den Erstellen-Tab springen"-
     * Flow. Überlebt den Tab-Wechsel als Livewire-State, damit
     * der reguläre Go im Erstellen-Tab (mit vollem Leitplanken-Regler) die Anker als seed_anker mitnimmt.
     * Form: ['scope' => 'rezept'|'gericht', 'slugs' => [...]]. Wird in goKaskade nach Verwendung geleert.
     */
    public array $composerSeedPin = [];

    /** Welche Schnellstart-Vorlage je Scope aktuell geladen ist (id) — fürs Chip-Highlight. Eine manuelle Brief-Änderung hebt sie auf ({@see updated()}). */
    public array $aktiveVorlage = [];

    /** Eingabe für „Als Vorlage speichern" (Snapshot-Name), im Erstell-Tab gebunden. */
    public string $vorlageName = '';

    /**
     * Geplanter Pfad (Etappe 2b, „KI-Kopf"): die ID des vorab ausgearbeiteten, im Conceptor geprüften
     * Draft-Concepts ({@see kiKopf} → {@see ConceptGeneratorService::planAusBrief}). Gesetzt = der nächste
     * Concept-Go referenziert diesen Plan (`existing_concept_id`, KEIN neuer GenerateConceptJob), statt neu
     * zu generieren. `null` = Schnell-Pfad (Brief → Go generiert frisch). **Transient** (keine DB-Spalte) —
     * lebt nur, solange die Fläche gemountet ist; nach dem Go verbraucht ({@see goKaskade} setzt zurück).
     */
    public ?int $planConceptId = null;

    /**
     * Editierbare Semantik-Skalare des vorbereiteten KI-Kopf-Plans (Leitidee/Name+Claim/USP/Inszenierung/
     * intern) — INLINE in der Leitstelle sichtbar + änderbar (statt Wegsprung in den Conceptor). Geladen
     * aus der `concept.plan`-Canvas ({@see ladePlanForm}); {@see planFeldSpeichern} schreibt zurück. Diese
     * Felder SIND der LLM-Kontext für die Concept-/Gericht-Erzeugung (fließen beim Go mit) — „ein Ort, ein
     * Kontext". Geschmackswelten + die Menü-Positionen bleiben Lese-Anzeige ({@see planVorschau}).
     *
     * @var array<string,string>
     */
    public array $planForm = [];

    /**
     * Befund des letzten Leitplanken-Vorschlags — bewusst SICHTBAR statt still:
     * was gesetzt wurde, was verworfen (erfundener Wert), was ignoriert (gültiger
     * Sitzungs-Parameter, aber kein Regler dieses Tabs) und was offen blieb.
     *
     * @var array{scope:string,gesetzt:list<string>,verworfen:list<string>,ignoriert:list<string>,unklar:list<string>,begruendung:?string,confidence:float}|null
     */
    public ?array $leitplankenBefund = null;

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    // ── Datenbeschaffung ───────────────────────────────────────────────

    private function aktiveSession(): ?FoodAlchemistPlanningSession
    {
        if ($this->sessionId === null) {
            return null;
        }
        $team = $this->team();

        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId);
    }

    /**
     * Spec 55 (Design-Punkt b): „Übernehmen"-Klick eines `komponenten_uebernahme`-Vorschlags im
     * eingebetteten Sprachbefehl-Panel ({@see \Platform\FoodAlchemist\Livewire\VoiceModal::komponentenUebernehmen()}) —
     * schreibt DIREKT in die Regler/den Brief DIESER Komponente (kein MCP-Tool, kein Katalog-
     * Wachstum). Whitelist der Leitplanken-Schlüssel schon in VoiceCommandService gefiltert,
     * hier NOCHMAL geprüft (Browser-Events sind kein vertrauenswürdiger Server-Kanal).
     *
     * @param  array<string, mixed>  $felder
     */
    #[\Livewire\Attributes\On('voice.komponenten-uebernahme')]
    public function voiceKomponentenUebernehmen(string $scope, array $felder, ?string $brief = null): void
    {
        if (! in_array($scope, Index::SCOPES, true)) {
            return;
        }
        $erlaubt = array_intersect_key($felder, array_flip(Index::AGENT_SCHREIBBARE_REGLER));
        foreach ($erlaubt as $feld => $wert) {
            $this->regler[$scope][$feld] = $wert;
            $this->reglerVonAgent[$scope][$feld] = true;
        }
        if ($brief !== null && trim($brief) !== '') {
            $this->eingabe[$scope]['brief'] = trim($brief);
            $this->reglerVonAgent[$scope]['brief'] = true;
        }
        $this->meldung = 'Vorschlag des Sprachbefehl-Agenten übernommen.';
    }

    private function rehydriereReglerAusParams(FoodAlchemistPlanningSession $session): void
    {
        $h = $this->reglerHelfer();
        $h->rehydriereReglerAusParams($session);
        $this->regler = $h->regler;   // Spec 84 C1-1: Logik in ReglerParams, der neue Stand kommt zurück
    }

    /**
     * Trend-Herkunft → Go-Briefing: den Brief/Titel einer Trend-Session in alle Tab-Briefings
     * ({@see SCOPES}) übertragen. **Nur befüllen, nie überschreiben** — ein bereits (vom Nutzer
     * oder einer Vorlage) getipptes Tab-Briefing bleibt unangetastet. Der Session-Brief ist bewusst
     * scope-agnostisch formuliert (die Session hat keine Ebene); beim Übertragen wird der Lead je Tab
     * ebenen-spezifisch geschärft ({@see PlanningSessionService::briefFuerScope} — Basisrezept/Gericht/
     * Konzept), Einordnung/Kernaussage bleiben unverändert. Kein „Go" — nur Prefill (Nordstern: nichts
     * läuft still).
     */
    private function seedBriefingAusTrendSession(FoodAlchemistPlanningSession $session): void
    {
        $brief = trim((string) $session->brief);
        $titel = trim((string) $session->title);
        if ($brief === '') {
            return;
        }
        foreach (Index::SCOPES as $scope) {
            if (trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
                $this->eingabe[$scope]['brief'] = PlanningSessionService::briefFuerScope($brief, $scope);
            }
            if ($titel !== '' && trim((string) ($this->eingabe[$scope]['titel'] ?? '')) === '') {
                $this->eingabe[$scope]['titel'] = $titel;
            }
        }
    }

    /** Multi-Select-Regler (Toggle in/aus der Liste): Diät + Frische-Erlaubnis + Allergen-No-Go. Spec 85 C1 PR-4: aus Index (war private, nur hier gebraucht). */
    private const MULTI_REGLER = ['diaet_hart', 'frische', 'allergen_nogo'];

    /** Pill-Toggle für die Richtungs-Regler EINES Scopes (MULTI_REGLER togglen, sonst Single-Set). */
    public function reglerPill(string $scope, string $feld, string $wert): void
    {
        if (! isset($this->regler[$scope])) {
            return;
        }
        // Spec 55 Nachtrag: ein Pill-Klick ist ein DIREKTER Server-Write (kein `wire:model`-
        // Property-Sync) — der generische `updated()`-Hook sieht ihn nie, darum hier explizit
        // dasselbe "manuelle Änderung löscht den Agent-Marker"-Verhalten wie dort.
        unset($this->reglerVonAgent[$scope][$feld]);
        if (in_array($feld, self::MULTI_REGLER, true)) {
            $cur = (array) ($this->regler[$scope][$feld] ?? []);
            $this->regler[$scope][$feld] = in_array($wert, $cur, true)
                ? array_values(array_diff($cur, [$wert]))
                : [...$cur, $wert];

            return;
        }
        if (array_key_exists($feld, $this->regler[$scope])) {
            $this->regler[$scope][$feld] = $wert;
        }
    }

    /**
     * Brief-Vorlagen, die für den gegebenen Creation-Tab gelten (Schnellstart statt Blank Page,
     * Etappe 4). Die Blade blendet den Vorlagen-Block nur ein, wenn die Liste nicht leer ist —
     * so trägt der Basisrezept-Tab (Teil 1: keine Vorlagen) keine leere Auswahl.
     *
     * @return array<string,array<string,mixed>>
     */
    public function vorlagenFuer(string $scope): array
    {
        $team = $this->team();

        return $team ? app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->fuer($team, $scope) : [];
    }

    /**
     * Lädt eine Brief-Vorlage in den Start-Tab (Schnellstart statt Blank Page, Etappe 4). Füllt das
     * Briefing bewusst (Starter — die leere Seite soll weg) und setzt Sektor/Anlass/Serviceform als
     * Kontext-Vorschlag; der Titel wird NUR gesetzt, wenn der Nutzer noch keinen getippt hat (nicht
     * überschreiben). Alles bleibt frei anpassbar. Kein Go — der Mensch prüft und drückt selbst.
     */
    public function briefVorlage(string $scope, string $key): void
    {
        if (! isset($this->eingabe[$scope]) || ! isset($this->regler[$scope])) {
            return;
        }
        $team = $this->team();
        $tpl = $team ? app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->lade($team, (int) $key, $scope) : null;
        if ($tpl === null) {
            $this->fehler = 'Unbekannte oder für diesen Tab ungültige Vorlage.';

            return;
        }
        $payload = is_array($tpl->payload) ? $tpl->payload : [];
        $this->eingabe[$scope]['brief'] = (string) $tpl->brief;
        if (trim((string) ($this->eingabe[$scope]['titel'] ?? '')) === '' && trim((string) $tpl->titel) !== '') {
            $this->eingabe[$scope]['titel'] = (string) $tpl->titel;
        }
        if (! empty($payload['creative_mode']) && array_key_exists('creative_mode', $this->eingabe[$scope])) {
            $this->eingabe[$scope]['creative_mode'] = (string) $payload['creative_mode'];
        }
        // Leitplanken-Snapshot anwenden — NUR Keys, die der Ziel-Regler-Satz dieses Scopes führt
        // (kein Fremd-Key-Erbe; z. B. VK-/Menü-Achsen eines Gericht-Snapshots landen nicht im Basisrezept).
        foreach (($payload['regler'] ?? []) as $feld => $wert) {
            if (array_key_exists($feld, $this->regler[$scope])) {
                $this->regler[$scope][$feld] = $feld === 'sektor' && is_string($wert) ? (\Platform\FoodAlchemist\Support\Sektor::kanonisch($wert) ?? $wert) : $wert;   // CQ 2.5a: Altwert → Slug
            }
        }
        $this->aktiveVorlage[$scope] = (string) $key;   // Chip-Highlight: sichtbar, WELCHE Vorlage geladen wurde
        $this->fehler = null;
        $this->meldung = 'Vorlage „'.$tpl->label.'" geladen — Briefing, Kreativ-Modus und Leitplanken gesetzt, bitte prüfen und anpassen.';
    }

    /**
     * Eine MANUELLE Brief-Änderung hebt die Schnellstart-Markierung des Scopes auf — die Vorlage war
     * nur Startpunkt, ab hier ist es ein eigenes Briefing (ein stehender Chip-Highlight wäre irreführend).
     * Feuert NICHT beim programmatischen Setzen in {@see briefVorlage()} (nur client-originierte Updates).
     *
     * Spec 55: dieselbe Logik für `reglerVonAgent` — der „vom Agenten"-Marker ist eine Aussage
     * über den AKTUELLEN Feldwert („das steht so, weil der Agent es vorgeschlagen hat"); ändert
     * der Mensch das Feld manuell, stimmt die Aussage nicht mehr. `updated()` feuert NUR bei
     * client-originierten `wire:model`-Updates (Tippen/Regler-Bedienung), NICHT wenn
     * {@see voiceKomponentenUebernehmen()} selbst den Marker setzt (das ist ein Server-Write
     * in derselben Methode, kein Property-Update-Zyklus).
     */
    public function updated(string $name): void
    {
        if (preg_match('#^eingabe\.([^.]+)\.brief$#', $name, $m)) {
            unset($this->aktiveVorlage[$m[1]]);
            unset($this->reglerVonAgent[$m[1]]['brief']);
        }
        if (preg_match('#^regler\.([^.]+)\.([^.]+)$#', $name, $m)) {
            unset($this->reglerVonAgent[$m[1]][$m[2]]);
        }
        // Briefing mitspeichern: jede Änderung an Eingabe oder Leitplanken → Hinweis „ungespeichert“ im Modal-Kopf.
        if (preg_match('#^(eingabe\.[^.]+\.(titel|brief|creative_mode)|regler\.)#', $name)) {   // Suchbegriffe speichern sich selbst
            $this->dispatch('planung.ungespeichert');
        }
    }

    /**
     * „Briefing mitspeichern“ (Dominique/Kurator 23.11.2026): auf „Planung speichern“ im Parent den Entwurf aller drei
     * Reiter sichern — Titel, Brief, Kreativ-Modus, Leitplanken (Vorlage und Seed-Pin nicht). Nur für die eigene Planung:
     * das Event trägt die Session des Parent-Formulars; passt sie nicht (Wechsel), wird nichts geschrieben.
     */
    #[On('planung.speichern')]
    public function entwurfSpeichern(int $sessionId, ?string $scope, PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null || $this->sessionId === null || $sessionId !== $this->sessionId) {
            return;
        }
        $entwurf = [];
        foreach (Index::SCOPES as $s) {
            $entwurf[$s] = [
                'titel' => (string) ($this->eingabe[$s]['titel'] ?? ''),
                'brief' => (string) ($this->eingabe[$s]['brief'] ?? ''),
                'creative_mode' => (string) ($this->eingabe[$s]['creative_mode'] ?? 'voll_kreativ'),
                'regler' => $this->regler[$s] ?? [],
            ];
        }
        try {
            $svc->speichereEntwurf($team, $this->sessionId, $entwurf, in_array($scope, Index::SCOPES, true) ? $scope : null);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->fehler = null;
        $this->dispatch('planung.entwurf-gespeichert');
    }

    /**
     * Gespeicherten Entwurf in die Reiter zurückholen. Leitplanken nur für bekannte Regler-Schlüssel; war nach dem Speichern
     * schon ein Go, gelten für die Leitplanken die beim Go persistierten Werte (generation_params, schon rehydriert).
     */
    private function ladeEntwurf(FoodAlchemistPlanningSession $session): void
    {
        $entwurf = is_array($session->entwurf_json) ? $session->entwurf_json : [];
        if ($entwurf === []) {
            return;
        }
        $gespeichert = isset($entwurf['gespeichert_at']) ? \Illuminate\Support\Carbon::parse($entwurf['gespeichert_at']) : null;
        $letzterGo = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun::visibleToTeam($this->team())->where('planning_session_id', $session->id)->max('created_at');
        $reglerAusEntwurf = $gespeichert === null || $letzterGo === null || $gespeichert->gte(\Illuminate\Support\Carbon::parse($letzterGo));
        foreach (Index::SCOPES as $s) {
            $e = is_array($entwurf[$s] ?? null) ? $entwurf[$s] : null;
            if ($e === null) {
                continue;
            }
            $this->eingabe[$s]['titel'] = (string) ($e['titel'] ?? '');
            $this->eingabe[$s]['brief'] = (string) ($e['brief'] ?? '');
            $this->eingabe[$s]['creative_mode'] = in_array($e['creative_mode'] ?? null, FoodAlchemistPlanningSession::CREATIVE_MODES, true)
                ? $e['creative_mode'] : 'voll_kreativ';
            if ($reglerAusEntwurf && is_array($e['regler'] ?? null)) {
                $this->regler[$s] = array_merge(Index::REGLER_DEFAULT, array_intersect_key($e['regler'], Index::REGLER_DEFAULT));
            }
        }
    }

    /**
     * „Als Vorlage speichern": nimmt den AKTUELLEN Tab-Stand (Brief + Kreativ-Modus + kompletter
     * Leitplanken-Satz) als benannte, team-eigene Schnellstart-Vorlage auf — genau dort, wo die Regler
     * schon eingestellt sind (kein nachgebautes Formular). Erscheint danach als Chip auf DIESEM Scope.
     */
    public function alsVorlageSpeichern(string $scope): void
    {
        $team = $this->team();
        if ($team === null || ! isset($this->eingabe[$scope]) || ! isset($this->regler[$scope])) {
            return;
        }
        try {
            $tpl = app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->speichere(
                $team, $scope, $this->vorlageName, (string) ($this->eingabe[$scope]['brief'] ?? ''),
                $this->regler[$scope],
                $this->eingabe[$scope]['titel'] ?? null,
                $this->eingabe[$scope]['creative_mode'] ?? null,
                Auth::id(),
            );
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->vorlageName = '';
        $this->aktiveVorlage[$scope] = (string) $tpl->id;   // die gerade gespeicherte ist aktiv markiert
        $this->fehler = null;
        $this->meldung = 'Vorlage „'.$tpl->label.'" gespeichert — steht ab jetzt als Schnellstart bereit.';
    }

    /** Eine team-EIGENE Vorlage löschen (Globals sind read-only → Service wirft). Inline aus dem Chip. */
    public function loeschenVorlage(string $scope, int $id): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->loeschen($team, $id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if (($this->aktiveVorlage[$scope] ?? null) === (string) $id) {
            unset($this->aktiveVorlage[$scope]);
        }
        $this->fehler = null;
        $this->meldung = 'Vorlage gelöscht.';
    }

    /**
     * Et.4 (Eingabe-Reife) »Titel-/Namensvorschlag aus dem Brief« — Teil 3 (UI). Schlägt aus dem
     * Tab-Briefing einen nüchternen, §-konformen Titel vor ({@see TitelVorschlagService}, Teil 2 —
     * `recipe.titel_vorschlag` §1 bzw. `vk.titel_vorschlag` §4.4-Pipe) und füllt ihn ins Tab-Titelfeld
     * — **nur wenn dort noch keiner steht** (empty-only, Muster {@see briefVorlage});
     * ein bereits getippter Titel bleibt unangetastet. Kein Go — reiner Vorschlag, der Mensch prüft.
     *
     * `concept` ist bewusst außen vor (dessen `name_claim` liefert der kreative KI-Kopf, nicht der
     * nüchterne Titel-Prompt) — die Blade blendet den Knopf nur im Basisrezept-/Gericht-Tab ein; der
     * Scope-Guard hier fängt einen Fehl-Aufruf fail-soft ab.
     */
    public function titelVorschlagen(string $scope, TitelVorschlagService $svc): void
    {
        if (! in_array($scope, Index::SCOPES, true) || ! isset($this->eingabe[$scope])) {
            return;
        }
        // Empty-only: ein bereits gesetzter Titel wird NIE überschrieben.
        if (trim((string) ($this->eingabe[$scope]['titel'] ?? '')) !== '') {
            $this->fehler = null;
            $this->meldung = 'Es steht bereits ein Titel — Vorschlag übersprungen (nicht überschrieben).';

            return;
        }
        $brief = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($brief === '') {
            $this->fehler = 'Für den Titelvorschlag erst ein Briefing im Tab eingeben.';

            return;
        }
        $vorschlag = $svc->titelVorschlag($scope, $brief);
        if ($vorschlag === null) {
            // concept (kein nüchterner Titel) ODER KI weg/leeres Ergebnis — fail-soft, kein Titel gefüllt.
            $this->fehler = $scope === 'concept'
                ? 'Für Konzepte liefert der KI-Kopf den Namen — der Titelvorschlag gilt nur für Basisrezept/Gericht.'
                : 'Kein Titelvorschlag möglich — bitte das Briefing schärfen oder manuell benennen.';

            return;
        }
        $this->eingabe[$scope]['titel'] = $vorschlag;
        $this->fehler = null;
        $this->meldung = 'Titel vorgeschlagen: „'.$vorschlag.'" — bitte prüfen und anpassen.';
    }

    /**
     * BRIEFING → LEITPLANKEN in der Oberfläche. Bis hierher war die Brücke nur per MCP
     * erreichbar — ein Produkt muss das in sich lösen (Dominique 2026-09-02).
     *
     * Die Sitzung wird ABSICHTLICH nicht mitgegeben: der Vorschlag füllt nur die Regler
     * DIESES Tabs, damit der Mensch die Pills vor dem Go sieht. Persistiert wird am Go
     * (Start-Tab-Regel) — die Entscheidung bleibt menschlich.
     */

    /** Spec 80 A3: Chip entfernen (Index in der Liste des Tabs). */
    public function suchbegriffEntfernen(string $scope, int $index): void
    {
        if (! isset($this->eingabe[$scope]['suchbegriffe'][$index])) {
            return;
        }
        $liste = $this->eingabe[$scope]['suchbegriffe'];
        unset($liste[$index]);
        $this->eingabe[$scope]['suchbegriffe'] = array_values($liste);
    }

    /** Spec 80 A3: eigenen Begriff ergänzen (Gruppe „eigene", Quelle Mensch). Komma trennt mehrere. */
    public function suchbegriffHinzufuegen(string $scope): void
    {
        if (! in_array($scope, Index::SCOPES, true)) {
            return;
        }
        $roh = (string) ($this->eingabe[$scope]['suchbegriff_neu'] ?? '');
        $liste = (array) ($this->eingabe[$scope]['suchbegriffe'] ?? []);
        $belegt = array_flip(array_map(static fn ($b) => mb_strtolower((string) ($b['t'] ?? '')), $liste));
        foreach (preg_split('/[,;]/u', $roh) ?: [] as $teil) {
            $t = trim($teil);
            if (mb_strlen($t) < 2 || mb_strlen($t) > 60 || isset($belegt[mb_strtolower($t)])) {
                continue;
            }
            $liste[] = ['t' => $t, 'g' => 'eigene', 'q' => 'mensch'];
            $belegt[mb_strtolower($t)] = true;
        }
        $this->eingabe[$scope]['suchbegriffe'] = array_values($liste);
        $this->eingabe[$scope]['suchbegriff_neu'] = '';
    }

    public function leitplankenAusBriefing(string $scope, BriefingLeitplankenService $svc): void
    {
        if (! in_array($scope, Index::SCOPES, true) || ! isset($this->regler[$scope])) {
            return;
        }
        $brief = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($brief === '') {
            $this->fehler = 'Für die Leitplanken erst ein Briefing in diesem Tab eingeben.';

            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team im Kontext.';

            return;
        }

        try {
            $r = $svc->ausBriefing($team, $brief, null, $scope);
        } catch (\Throwable $e) {
            $this->fehler = 'Leitplanken-Vorschlag fehlgeschlagen: ' . $e->getMessage();

            return;
        }

        // Spec 85 C1 PR-4 (C-c): den Vorschlag auf Regler, Kreativ-Modus und Suchbegriffe anwenden in {@see LeitplankenUebernahme}.
        $u = LeitplankenUebernahme::anwenden($scope, $r, $this->eingabe, $this->regler);
        [$this->eingabe, $this->regler, $this->leitplankenBefund, $gesetzt] = [$u['eingabe'], $u['regler'], $u['befund'], $u['befund']['gesetzt']];
        $this->fehler = null;
        $this->meldung = $gesetzt === []
            ? 'Keine Leitplanke ableitbar — das Briefing nennt keine der geschlossenen Achsen.'
            : count($gesetzt) . ' Leitplanke(n) vorgeschlagen — bitte prüfen, das Go bleibt bei dir.';
    }

    /** Spec 84 C1-1: Leitplanken-Logik liegt in {@see ReglerParams}; hier nur der Zugang mit dem aktuellen Stand. */
    private function reglerHelfer(): ReglerParams
    {
        return new ReglerParams($this->regler, $this->eingabe);
    }

    private function reglerParams(string $scope): array
    {
        return $this->reglerHelfer()->reglerParams($scope);
    }

    private function menueAchsenFuer(string $scope): array
    {
        return $this->reglerHelfer()->menueAchsenFuer($scope);
    }

    private function menueLeitplankenFehler(string $scope): ?string
    {
        return $this->reglerHelfer()->menueLeitplankenFehler($scope);
    }

    private function zielVkEur(string $scope): ?float
    {
        return $this->reglerHelfer()->zielVkEur($scope);
    }

    private function effektiverBrief(string $scope): string
    {
        return $this->reglerHelfer()->effektiverBrief($scope);
    }

    /**
     * #1b Grounding-Preview: zeigt VOR dem Go, welches Wissen/Pairing/Template die Generierung für die
     * aktuellen Regler ziehen würde — OHNE zu generieren. Ruft denselben Kontext-Bau wie die Generierung
     * ({@see RecipeGenerationContextService::build}) und legt das `kontext`-Bündel für den Kontext-Inspektor
     * ab. Fail-soft: eine Preview darf den Editor nie brechen.
     */
    public function wissenVorschau(string $scope): void
    {
        $team = $this->team();
        if ($team === null || ! in_array($scope, Index::SCOPES, true)) {
            return;
        }
        $brief = $this->effektiverBrief($scope);
        if ($brief === '') {
            $this->fehler = 'Für die Wissens-Vorschau erst Titel oder Beschreibung im Tab setzen.';

            return;
        }
        try {
            $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
                ->build($team, $brief, $this->reglerParams($scope), $scope !== 'rezept');
            $this->wissenVorschau = is_array($ctx['kontext'] ?? null) ? $ctx['kontext'] : null;
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Wissens-Vorschau fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── „KI-Kopf" — Concept-Plan vorab ausarbeiten (Etappe 2b, geplanter Pfad) ──

    /**
     * „KI-Kopf" (Etappe 2b): arbeitet aus dem Concept-Briefing einen vollständigen Plan-Entwurf aus
     * ({@see ConceptGeneratorService::planAusBrief} → Draft-Concept + Gerüst + kreative Canvas + LEERE
     * Fan-out-Slots) und öffnet den vollen inline-Conceptor direkt auf „Konzept & Planung" ({@see
     * Concepter\Editor::oeffnen} Start-Tab 'konzept') zur Prüfung/Korrektur. Startet KEINE Kaskade —
     * der geprüfte Plan geht später als `existing_concept_id` in den Go (nächster Chunk). Nur für den
     * Concept-Scope sinnvoll (ein Concept ist ein Menü, kein Einzelrezept).
     *
     * Fail-soft (Nordstern): ein Fehler (leerer Brief / KI aus) wird gesagt, nicht geschluckt — der
     * Absender ist ein Mensch, der korrigieren kann.
     */
    public function kiKopf(ConceptGeneratorService $svc, PlanningSessionService $sessions): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            $this->fehler = 'Kein Team/Session — KI-Kopf nicht möglich.';

            return;
        }
        $brief = $this->effektiverBrief('concept');
        if ($brief === '') {
            $this->fehler = 'Für den KI-Kopf erst ein Konzept-Briefing eingeben.';

            return;
        }
        // Menü-Leitplanken zuerst validieren (wie beim Go) — der KI-Kopf darf nicht mit einer
        // stillschweigend verworfenen Zahl-Vorgabe generieren.
        if (($menueFehler = $this->menueLeitplankenFehler('concept')) !== null) {
            $this->fehler = $menueFehler;

            return;
        }
        // Concept-Briefing auf die Session spiegeln + persistieren (nicht verlieren) — wie beim Go.
        $this->spiegleAufSession('concept', $sessions);

        $titel = trim((string) ($this->eingabe['concept']['titel'] ?? ''));
        try {
            // Menü-Leitplanken (Gänge/Preis-Korridor/Quoten/Balance/Buffet) an den Plan durchreichen —
            // sonst arbeitet der plan-first-Standard-Pfad ohne sie (Bug: nur der Schnell-Go über den Job
            // reichte sie bisher). Gleiche menue_*-Teilmenge wie dispatchConceptStep. $via bleibt 'ui'
            // (der created_via-Marker `concept_plan_ui` wird andernorts erwartet — nicht verändern).
            $plan = $svc->planAusBrief($team, $brief, [], $titel !== '' ? $titel : null, 'ui', $this->menueAchsenFuer('concept'));
        } catch (\Throwable $e) {
            $this->fehler = 'KI-Kopf fehlgeschlagen: ' . $e->getMessage();

            return;
        }
        $this->fehler = null;
        // Draft-ID merken: der nächste Concept-Go referenziert diesen geprüften Plan statt neu zu
        // generieren („Beide Pfade behalten", Etappe 2b). Verbraucht wird sie in goKaskade.
        $this->planConceptId = (int) $plan['concept']->id;
        // #53 persistent: an die Session schreiben, damit der geprüfte Plan einen Reload übersteht
        // (rehydriert in ladeForm). Fail-soft: kippt die Persistenz, bleibt der Plan wenigstens in der
        // laufenden Session (transiente Prop) nutzbar — ein Speicher-Fehler darf den KI-Kopf nicht kippen.
        try {
            app(PlanningSessionService::class)->setzePlanConcept($team, (int) $session->id, $this->planConceptId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] plan_concept_id-Persistenz übersprungen (KI-Kopf bleibt für diese Session nutzbar) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // Inline-Plan-Panel: die Semantik-Felder in die Fläche laden — der Plan bleibt SICHTBAR in der
        // Leitstelle (kein Wegsprung mehr in den Conceptor). „Ein Ort, ein Kontext": Leitidee/Menü hier
        // prüfen + ändern (fließt in die LLM), dann „Go aus geprüftem Plan". Der Conceptor bleibt als
        // optionaler Tiefen-Editor per Knopf im Panel erreichbar (Entscheid 2026-08-18).
        $this->ladePlanForm();
        $this->meldung = 'KI-Kopf: Plan ausgearbeitet — prüfe/ändere Leitidee & Menü direkt hier, dann „Go aus geprüftem Plan".';
    }

    /**
     * Lädt die editierbaren Semantik-Skalare des vorbereiteten Plans aus der `concept.plan`-Canvas in
     * {@see $planForm} (leert sie, wenn kein Plan/kein Canvas). Rein lesend, fail-soft: ein fehlender
     * Canvas/Concept lässt das Panel leer, kippt aber nichts.
     */
    private function ladePlanForm(): void
    {
        $this->planForm = [];
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            return;
        }
        $canvas = app(CanvasService::class)->find('concept', 'concept', (int) $this->planConceptId);
        $werte = $canvas !== null ? app(CanvasService::class)->werte($canvas) : [];
        foreach (['name_claim', 'leitidee', 'usp_eignung', 'inszenierung', 'intern'] as $key) {
            $this->planForm[$key] = (string) ($werte[$key] ?? '');
        }
    }

    /**
     * Speichert die inline editierten Semantik-Felder ({@see $planForm}) zurück in die `concept.plan`-
     * Canvas des vorbereiteten Plans. Diese Felder sind der LLM-Kontext für die Concept-/Gericht-Erzeugung
     * — eine Änderung hier steuert also direkt, was beim nächsten Go entsteht („ein Ort, ein Kontext").
     * Fail-soft (Nordstern): ist der Plan inzwischen weg, wird das GESAGT statt still geschluckt.
     */
    public function planFeldSpeichern(): void
    {
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            $this->fehler = 'Kein vorbereiteter Plan — nichts zu speichern.';

            return;
        }
        $concept = FoodAlchemistConcept::where('team_id', $team->id)->whereKey($this->planConceptId)->first();
        if ($concept === null) {   // Plan gelöscht/Team-fremd → Referenz still lösen, Panel schließt sich
            $this->planConceptId = null;
            $this->planForm = [];
            $this->fehler = 'Der vorbereitete Plan ist nicht mehr da — bitte neu ausarbeiten.';

            return;
        }
        $canvas = app(CanvasService::class)->canvasFor($team, 'concept', 'concept', (int) $concept->id);
        app(CanvasService::class)->saveSkalare($canvas, $this->planForm);
        $this->fehler = null;
        $this->meldung = 'Plan aktualisiert — die geänderte Leitidee & co. fließen beim Erzeugen in die Gerichte.';
    }

    /**
     * Lese-Anzeige des vorbereiteten Plans für das Inline-Panel: Name, Geschmackswelten (repeatable) und
     * die geplanten Menü-Positionen ({@see FoodAlchemistConcept::slots} — Rolle/Titel/Pflicht). `null`,
     * wenn kein Plan vorbereitet ist. Rein lesend (2 Queries) — im Render aufgerufen, fail-soft.
     *
     * @return array{name:string,geschmackswelten:array<int,array<string,mixed>>,speisen:array<int,array<string,mixed>>}|null
     */
    public function planVorschau(): ?array
    {
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            return null;
        }
        $concept = app(ConceptService::class)->detail($team, (int) $this->planConceptId);
        if ($concept === null) {
            return null;
        }
        $canvas = app(CanvasService::class)->find('concept', 'concept', (int) $concept->id);
        $werte = $canvas !== null ? app(CanvasService::class)->werte($canvas) : [];

        return [
            'name' => (string) $concept->name,
            'geschmackswelten' => is_array($werte['geschmackswelten'] ?? null) ? $werte['geschmackswelten'] : [],
            'speisen' => $concept->slots->map(fn ($s) => [
                'rolle' => (string) ($s->role ?? ''),
                'titel' => (string) ($s->title ?? ''),
                'pflicht' => (bool) $s->is_pflicht,
            ])->values()->all(),
        ];
    }

    /**
     * Den vorbereiteten KI-Kopf-Plan verwerfen und zurück auf den Schnell-Pfad wechseln („Beide Pfade
     * behalten", Etappe 2b): nur die transiente Referenz wird gelöst — der Concept-Go generiert dann
     * wieder frisch aus dem Briefing. Das Draft-Concept selbst bleibt bestehen (löscht nichts).
     */
    public function planVerwerfen(): void
    {
        $this->planConceptId = null;
        // #53 persistent: auch den gespeicherten Zeiger lösen, sonst holt der Reload den verworfenen
        // Plan wieder (rehydrate in ladeForm). Das Draft-Concept selbst bleibt bestehen (löscht nichts).
        $session = $this->aktiveSession();
        if ($session !== null && $session->plan_concept_id !== null && ($team = Auth::user()?->currentTeamRelation) !== null) {
            try {
                app(PlanningSessionService::class)->setzePlanConcept($team, (int) $session->id, null);
            } catch (\Throwable) {
                // Persistenz-Fehler darf das Verwerfen nicht kippen — die Prop ist bereits null.
            }
        }
        $this->meldung = 'Vorbereiteter Plan verworfen — der Go generiert wieder frisch aus dem Briefing.';
        $this->fehler = null;
    }

    // ── „Go" — Tiefen-Leiter über den geteilten Kaskaden-Motor ─────────

    /**
     * Go → in-place Generierung über {@see PlanningCascadeService}. `$scope` = Einstiegs-Stufe
     * (`rezept`|`gericht`|`concept`). Sammelt die Richtungs-Regler (rezept/gericht), persistiert
     * sie als `generation_params` (Fan-out-Vererbung) und reicht sie als Lauf-`params` an den Motor.
     * Startet im Hintergrund; die Fläche pollt {@see pruefeLauf}. Kein Redirect.
     */
    public function goKaskade(string $scope, PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || ! in_array($scope, Index::SCOPES, true)) {
            return;
        }
        $vk = $scope !== 'rezept';
        // Ziel-VK-Eingabe (nur wo VK) wird GESAGT statt still verworfen — der Absender ist ein
        // Mensch, der 8,5 statt 850 meinte, und kann korrigieren (L8b-2, Spiegel VkGeneratorModal).
        if ($vk && trim((string) ($this->regler[$scope]['ziel_vk'] ?? '')) !== '' && $this->zielVkEur($scope) === null) {
            $this->fehler = 'Ziel-VK: bitte einen Netto-Preis je Portion zwischen 0,50 € und 500,00 € angeben (z. B. 8,50) — oder das Feld leer lassen.';

            return;
        }
        // Menü-Leitplanken (nur Concept): eine mistgetippte Zahl wird GESAGT statt still verworfen —
        // derselbe Grundsatz wie bei Ziel-VK (der Absender ist ein Mensch, der korrigieren kann).
        if (($menueFehler = $this->menueLeitplankenFehler($scope)) !== null) {
            $this->fehler = $menueFehler;

            return;
        }
        // Kontext des Start-Tabs auf die Session spiegeln (Dashboard-Anzeige + creative_mode) und persistieren.
        $this->spiegleAufSession($scope, $svc);
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        // Spec 85 C1 PR-4 (C-c): Lauf-Start (Params persistieren, Suchbegriffe, Lauf-Params, geplanter Pfad, Start,
        // Plan-Verbrauch) in {@see KaskadenStart}; hier bleiben Prüfungen, Spiegeln und Meldungen.
        $r = app(KaskadenStart::class)->starte($team, $session, $scope, $this->eingabe, $this->regler, $this->composerSeedPin, $this->planConceptId);
        [$this->eingabe, $this->composerSeedPin, $this->planConceptId] = [$r['eingabe'], $r['seed_pin'], $r['plan_concept_id']];
        if ($r['run'] === null) {
            $this->fehler = $r['fehler'];

            return;
        }
        $this->dispatch('planung.lauf-gestartet', laufId: (int) $r['run']->id);   // Parent übernimmt laufId/laeuft (Spec 85 C1 PR-4)
        $this->wissenVorschau = null;   // neue Kaskade → Vorschau weg; die Steps zeigen dann das ECHTE Wissen (#1a)
        $this->meldung = 'Kaskade gestartet — Entwurf wird erzeugt …';
        $this->fehler = null;
    }

    public function mount(): void
    {
        // Per-Tab-State initialisieren — jeder Scope eigene Eingabe + eigener Leitplanken-Satz.
        foreach (Index::SCOPES as $s) {
            $this->eingabe[$s] = ['titel' => '', 'brief' => '', 'creative_mode' => 'voll_kreativ',
                'suchbegriffe' => [], 'suchbegriff_neu' => ''];
            $this->regler[$s] = Index::REGLER_DEFAULT;
        }
        $session = $this->aktiveSession();
        if ($session !== null) {
            $this->ladeAusSession($session);
            // Spec 53/D: Sprachbefehl-Handoff (`?open=1&tab=`): die Session trägt den scope-spezifischen Brief — ins Tab-Briefing
            // vorbefüllen, wenn dort noch nichts steht (früher in Index::mount).
            $tab = request()->boolean('open') && in_array(request('tab'), ['basisrezept', 'gericht', 'concept'], true) ? request('tab') : null;
            if ($tab !== null) {
                $scope = $tab === 'basisrezept' ? 'rezept' : $tab;
                $brief = trim((string) $session->brief);
                if ($brief !== '' && trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
                    $this->eingabe[$scope]['brief'] = $brief;
                }
            }
        }
        $this->gemeldet = $this->meldeStand();
    }

    /** Aus der Session lesen, was zum Briefing gehört (früher der Brief-Teil von Index::ladeForm). */
    private function ladeAusSession(FoodAlchemistPlanningSession $session): void
    {
        // Spec 80: gespeicherte Suchbegriffe je Tab zurückholen (nur Tabs, die welche haben).
        foreach ((is_array($session->suchbegriffe) ? $session->suchbegriffe : []) as $sc => $liste) {
            if (isset($this->eingabe[$sc]) && is_array($liste)) {
                $this->eingabe[$sc]['suchbegriffe'] = array_values(array_filter($liste, static fn ($b) => is_array($b) && isset($b['t'])));
            }
        }

        // Trendradar-Anbindung (Spec 79): eine aus dem Trendradar eröffnete Session (`source_trend_refs`,
        // Carry-in via `?open=1`) füllt ihren Brief ins Tab-Briefing je Ebene vor (nur leere Felder) —
        // der Nutzer startet mit einem gefüllten Briefing statt Blank Page.
        if (! empty($session->source_trend_refs)) {
            $this->seedBriefingAusTrendSession($session);
        }

        // #53 Persistenz: einen vorbereiteten KI-Kopf-Plan (plan_concept_id) über den Reload retten —
        // aber nur, wenn das Draft-Concept noch existiert + team-eigen ist (sonst still auf null; ein
        // toter/verwaister Zeiger darf den Concept-Go nicht auf einen Geister-Plan schicken). Spiegelt
        // die Fail-soft-Ownership-Prüfung in goKaskade.
        $this->planConceptId = null;
        $team = $this->team();
        if ($team !== null && $session->plan_concept_id !== null
            && FoodAlchemistConcept::where('team_id', $team->id)->whereKey($session->plan_concept_id)->exists()) {
            $this->planConceptId = (int) $session->plan_concept_id;
        }
        // Inline-Plan-Panel: die editierbaren Semantik-Felder aus der Canvas nachziehen (leert, wenn kein Plan).
        $this->ladePlanForm();

        // L5: die beim Go persistierten Leitplanken (generation_params) in die Regler zurücklesen — sonst
        // zeigt jeder Tab nach einem Reload wieder die Defaults, während der Lauf mit anderen Werten fuhr.
        $this->rehydriereReglerAusParams($session);

        // Briefing mitspeichern: der gespeicherte Entwurf je Reiter (Texte immer, Leitplanken wenn nach dem letzten Go gespeichert).
        $this->ladeEntwurf($session);
    }

    /**
     * Briefing und Kreativ-Modus des Scopes auf die Session spiegeln (Dashboard-Anzeige + creative_mode), vor KI-Kopf und Go.
     * Ersetzt den früheren Umweg über Index::speichern(): derselbe Service-Weg (ownedSession: sichtbar + Besitzer-Team), aber
     * nur Brief und Modus — Titel und Analyse bleiben, wie sie sind. Danach lädt der Parent seinen Session-Spiegel nach.
     */
    private function spiegleAufSession(string $scope, PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        $svc->update($team, $session->id, ['brief' => (string) ($this->eingabe[$scope]['brief'] ?? '')]);
        $modus = (string) ($this->eingabe[$scope]['creative_mode'] ?? 'voll_kreativ');
        if (in_array($modus, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $svc->setCreativeMode($team, $session->id, $modus);
        }
        $this->dispatch('planung.brief-gespiegelt');
    }

    /** Spec 79 · Trendradar-Übernahme: Briefing der Ziel-Ebene ÜBERSCHREIBEN (ausdrücklicher Klick), die anderen nur leer füllen. */
    #[On('planung.trend-uebernommen')]
    public function trendUebernommen(string $scope): void
    {
        $session = in_array($scope, Index::SCOPES, true) ? $this->aktiveSession() : null;
        if ($session === null) {
            return;
        }
        $this->eingabe[$scope]['brief'] = PlanningSessionService::briefFuerScope((string) $session->brief, $scope);
        $this->eingabe[$scope]['titel'] = (string) $session->title;
        $this->seedBriefingAusTrendSession($session);
    }

    /**
     * Werte von außen ins Briefing eines Scopes (Composer im Parent): `brief_wenn_leer` (getippten Brief nicht überschreiben),
     * `seed_pin` (Composer-Anker, die der Go mitnimmt). Andere Schlüssel werden ignoriert. Das Diktat kommt seit Spec 85 C1 PR-5
     * direkt hier an ({@see agentDiktatUebernehmen}).
     *
     * @param  array<string, mixed>  $werte
     */
    #[On('planung.brief-uebernehmen')]
    public function briefUebernehmen(string $scope, array $werte): void
    {
        if (! isset($this->eingabe[$scope])) {
            return;
        }
        if (isset($werte['brief_wenn_leer']) && trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
            $this->eingabe[$scope]['brief'] = (string) $werte['brief_wenn_leer'];
        }
        if (isset($werte['seed_pin']) && is_array($werte['seed_pin'])) {
            $this->composerSeedPin = ['scope' => $scope, 'slugs' => array_values(array_map('strval', $werte['seed_pin']))];
        }
    }

    /**
     * Kurskorrektur „pro Tab genau EINE Diktierfunktion" (2026-09-19): das Agent-Panel ist in den drei Erstell-Reitern der einzige
     * Diktier-Weg. Sein Mikro transkribiert selbst (VoiceModal::updatedAudio()) und liefert das Rohtranskript hierher — ANHÄNGEN,
     * nie ersetzen ({@see DiktatZiele::anhaengen}). Spec 85 C1 PR-5: direkt im Brief statt über den Parent (das Panel steckt im Brief).
     */
    #[On('voice.diktat-transkribiert')]
    public function agentDiktatUebernehmen(string $scope, string $text): void
    {
        if (! in_array($scope, Index::SCOPES, true) || trim($text) === '') {
            return;
        }
        $this->eingabe[$scope]['brief'] = DiktatZiele::anhaengen((string) ($this->eingabe[$scope]['brief'] ?? ''), $text);
        $this->meldung = 'Diktat übernommen — bitte gegenlesen.';
    }

    /** Stand beim Laden der Anfrage; {@see rendering()} meldet dem Parent nur, was sich geändert hat. */
    private array $gemeldet = [];

    public function hydrate(): void
    {
        $this->gemeldet = $this->meldeStand();
    }

    /** @return array<string, ?string> */
    private function meldeStand(): array
    {
        return ['meldung' => $this->meldung, 'fehler' => $this->fehler];
    }

    /** Meldung und Fehler erscheinen wie bisher im Modal-Kopf des Parents (nur bei Änderung, keine Render-Schleife). */
    public function rendering($view = null, $data = []): void
    {
        $neu = $this->meldeStand();
        foreach ($neu as $art => $text) {
            if ($text !== ($this->gemeldet[$art] ?? null)) {
                $this->dispatch('planung.meldung', text: $text, art: $art);
            }
        }
        $this->gemeldet = $neu;
    }

    /** #1003 PR7: am Handy Auftrag geben und starten (Briefing, Leitplanken, KI-Plan, Go); Vorlagen-Pflege und Trendradar nicht. */
    public function handyLeseMethoden(): array
    {
        return [
            'reglerPill', 'briefVorlage', 'titelVorschlagen', 'suchbegriffEntfernen', 'suchbegriffHinzufuegen', 'leitplankenAusBriefing',
            'voiceKomponentenUebernehmen', 'wissenVorschau', 'kiKopf', 'planFeldSpeichern', 'planVerwerfen', 'goKaskade',
            'briefUebernehmen', 'agentDiktatUebernehmen',   // Diktat über das Agenten-Panel (am Handy erlaubt)
            'entwurfSpeichern',   // „Planung speichern“ (am Handy erlaubt) sichert den Entwurf über dieses Event
        ];
    }

    public function render()
    {
        $team = $this->team();

        // Spec 55 Nachtrag (Agent-am-Brief): der Formularstand geht bei JEDEM Render an alle gemounteten Agenten-Panels
        // (Browser-Event, kein `#[Reactive]`-Prop). Nur die AGENT_SCHREIBBARE_REGLER-Teilmenge + Brief je Scope.
        foreach (Index::SCOPES as $scope) {
            $this->dispatch('voice.formularstand-aktualisiert', scope: $scope,
                regler: array_intersect_key($this->regler[$scope] ?? [], array_flip(Index::AGENT_SCHREIBBARE_REGLER)),
                brief: (string) ($this->eingabe[$scope]['brief'] ?? ''),
            );
        }

        return view('foodalchemist::livewire.planung.brief', [
            // Spec 55: Agenten-Panel — sichtbar, wenn das Team es nicht abgeschaltet hat (Default AN).
            'agentPanelSichtbar' => $team !== null && app(TeamSettingsService::class)->voiceAgentPanelPlanung($team),
            // CQ 2.5b: Saison, Anlass, Eventtyp, Service-Art aus den DB-Vokabularen
            'planungVokabular' => collect(PlanungVokabular::MODELLE)->map(fn ($m, $feld) => app(PlanungVokabular::class)->optionen($team, $feld))->all(),
            // Worker-Präsenz proaktiv neben dem Go (Etappe 8, Teil 2): null = gesund, keine Warnung
            'workerWarnung' => app(WorkerHealthService::class)->status()['state'] === 'gesund'
                ? null : 'Die Hintergrund-Erstellung läuft gerade nicht. Neue Aufträge warten in der Warteschlange, bis sie wieder anläuft.',
        ]);
    }
}
