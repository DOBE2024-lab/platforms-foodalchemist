<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * 98 · 1.3d · „X aus Brief" — EIN Use-Case je Ausgabe (Foodbook, Speisekarte, Speiseplan, Angebot, Format) für die
 * Leitstelle (Livewire\Planung\Index) und die MCP-Tools (*PlanFromBriefTool). Vorher lebte jede Kette doppelt und lief
 * auseinander (Besitz-Prüfung nur im Tool, Marken-Kontext der Speisekarte nur in der Oberfläche, Format-Branding
 * verschieden). Ablauf je Ausgabe: Hülle (bestehend + team-EIGEN oder neu) → Gerüst aus dem Brief → ggf. Struktur →
 * Review-Session (+ Leitplanken) → Voll-Kaskade mit Owner.
 *
 * Optionen ($opt): owner_id (bestehende Hülle), titel (Name einer neuen Hülle), creative_mode (voll_kreativ|hybrid|
 * datenbank, sonst voll_kreativ), leitplanken (Regler der Session, whitelist-gefiltert), created_via (Pflicht:
 * Herkunft, z. B. leitstelle_foodbook_brief | mcp_foodbook_brief). Speisekarte zusätzlich `fuellung`
 * (gerichte|concepte, Standard gerichte), Format zusätzlich `origin` (eigen|gruppe|kunde).
 *
 * @throws \InvalidArgumentException leerer Brief
 * @throws NichtGefundenException bestehende Hülle nicht team-sichtbar
 * @throws GeerbtNurLesenException bestehende Hülle geerbt (nur das Besitzer-Team plant)
 */
class PlanungsEinstiegService
{
    public const MODI = ['voll_kreativ', 'hybrid', 'datenbank'];

    public function __construct(
        private ConceptGeneratorService $concepts,
        private PlanningCascadeService $cascade,
        private PlanningSessionService $sessions,
        private TeamSettingsService $settings,
        private CanvasService $canvas,
    ) {}

    /** @return array{owner: FoodAlchemistFoodbook, session: FoodAlchemistPlanningSession, run: FoodAlchemistCascadeRun} */
    public function foodbookAusBrief(Team $team, string $brief, array $opt): array
    {
        $brief = $this->brief($brief);
        $foodbooks = app(FoodbookService::class);
        $fb = $this->eigeneHuelle(FoodAlchemistFoodbook::class, $team, $opt, 'Foodbook', 'Geerbtes Foodbook')
            ?? $foodbooks->create($team, ['label' => $this->titel($opt, 'Foodbook aus Brief')]);

        $this->concepts->geruestAusBriefFuerOwner($team, 'foodbook', (int) $fb->id, $brief, [
            'segment' => $this->settings->segment($team),
            'leitplanken' => $foodbooks->leitplanken($team, $fb),
            'marken_kontext' => $this->canvas->cascadeKontext($team, null, (int) $fb->id, null, $fb->crm_company_id)['marken_kontext'] ?? null,
        ]);
        $foodbooks->strukturAusGeruest($team, (int) $fb->id);   // Gerüst-Slots → Kapitel (idempotent)

        return ['owner' => $fb] + $this->sessionUndKaskade($team, 'foodbook', (int) $fb->id, 'Foodbook aus Brief: ' . $fb->label, $brief, $opt);
    }

    /**
     * Owner-generisch wie Foodbook, aber KEIN strukturAusGeruest: die Rubriken entstehen pro Slot im Fan-out
     * (`SpeisekarteService::rubrikFuerSlot`).
     *
     * @return array{owner: FoodAlchemistSpeisekarte, session: FoodAlchemistPlanningSession, run: FoodAlchemistCascadeRun}
     */
    public function speisekarteAusBrief(Team $team, string $brief, array $opt): array
    {
        $brief = $this->brief($brief);
        $karte = $this->eigeneHuelle(FoodAlchemistSpeisekarte::class, $team, $opt, 'Speisekarte', 'Geerbte Speisekarte')
            ?? app(SpeisekarteService::class)->create($team, ['name' => $this->titel($opt, 'Speisekarte aus Brief')]);

        $this->concepts->geruestAusBriefFuerOwner($team, 'speisekarte', (int) $karte->id, $brief, [
            'segment' => $this->settings->segment($team),
            'marken_kontext' => $this->canvas->cascadeKontext($team, null, null, null, $karte->crm_company_id)['marken_kontext'] ?? null,
        ]);
        // Füllung je Rubrik (Gerichte oder ganze Concepte) — immer gesetzt, Standard Gerichte, damit UI und MCP dieselben Regler tragen.
        $opt['leitplanken'] = (array) ($opt['leitplanken'] ?? []) + ['speisekarte_fuellung' => ($opt['fuellung'] ?? null) === 'concepte' ? 'concepte' : 'gerichte'];

        return ['owner' => $karte] + $this->sessionUndKaskade($team, 'speisekarte', (int) $karte->id, 'Speisekarte aus Brief: ' . ($karte->name ?? ('#' . $karte->id)), $brief, $opt);
    }

    /**
     * Kein Gänge-Gerüst: {@see SpeiseplanService::create} legt GV-Standard-Linien + Zyklus + Start-Montag an. Ein im Brief
     * genannter Zyklus („4 Wochen") wird übernommen.
     *
     * @return array{owner: FoodAlchemistSpeiseplan, session: FoodAlchemistPlanningSession, run: FoodAlchemistCascadeRun}
     */
    public function speiseplanAusBrief(Team $team, string $brief, array $opt): array
    {
        $brief = $this->brief($brief);
        $plan = $this->eigeneHuelle(FoodAlchemistSpeiseplan::class, $team, $opt, 'Speiseplan', 'Geerbter Speiseplan');
        if ($plan === null) {
            $in = ['name' => $this->titel($opt, 'Speiseplan aus Brief')];
            if (preg_match('/(\d+)\s*[- ]?woche/iu', $brief, $m)) {
                $in['cycle_weeks'] = max(1, (int) $m[1]);
            }
            $plan = app(SpeiseplanService::class)->create($team, $in);
        }

        return ['owner' => $plan] + $this->sessionUndKaskade($team, 'speiseplan', (int) $plan->id, 'Speiseplan aus Brief: ' . ($plan->name ?? ('#' . $plan->id)), $brief, $opt);
    }

    /**
     * owner_type=offer: je Slot ein Konzept, das via `GenerateConceptJob`-Andock direkt ans Angebot referenziert wird
     * (kein Zwischen-Container).
     *
     * @return array{owner: FoodAlchemistAngebot, session: FoodAlchemistPlanningSession, run: FoodAlchemistCascadeRun}
     */
    public function angebotAusBrief(Team $team, string $brief, array $opt): array
    {
        $brief = $this->brief($brief);
        $angebot = $this->eigeneHuelle(FoodAlchemistAngebot::class, $team, $opt, 'Angebot', 'Geerbtes Angebot')
            ?? app(AngebotService::class)->create($team, ['name' => $this->titel($opt, 'Angebot aus Brief')]);

        $this->concepts->geruestAusBriefFuerOwner($team, 'offer', (int) $angebot->id, $brief, [
            'segment' => $this->settings->segment($team),
            'marken_kontext' => $this->canvas->cascadeKontext($team, null, null, (int) $angebot->id, $angebot->crm_company_id ?? null)['marken_kontext'] ?? null,
        ]);

        return ['owner' => $angebot] + $this->sessionUndKaskade($team, 'offer', (int) $angebot->id, 'Angebot aus Brief: ' . ($angebot->name ?? ('#' . $angebot->id)), $brief, $opt);
    }

    /**
     * owner_type=format: Marken-Identität + eigenständige Concepte je Slot. Override-First (Spec 50 GL-07, C-5): der
     * Name aus dem Gerüst nur bei NEU angelegtem Format; Branding (consumer_name/claim/story) füllt ausschließlich
     * LÜCKEN — eine gesetzte Identität bleibt, auch am bestehenden Format.
     *
     * @return array{owner: FoodAlchemistFormat, session: FoodAlchemistPlanningSession, run: FoodAlchemistCascadeRun, neu: bool, branding_gesetzt: list<string>}
     */
    public function formatAusBrief(Team $team, string $brief, array $opt): array
    {
        $brief = $this->brief($brief);
        $formate = app(FormatService::class);
        $format = $this->eigeneHuelle(FoodAlchemistFormat::class, $team, $opt, 'Format', 'Geerbtes Format');
        $neu = $format === null;
        if ($neu) {
            $origin = in_array($opt['origin'] ?? '', ['eigen', 'gruppe', 'kunde'], true) ? (string) $opt['origin'] : 'eigen';
            $format = $formate->create($team, ['name' => $this->titel($opt, 'Format aus Brief'), 'origin' => $origin]);
        }

        $geruest = $this->concepts->geruestAusBriefFuerOwner($team, 'format', (int) $format->id, $brief, [
            'segment' => $this->settings->segment($team),
        ]);
        $upd = [];
        if ($neu && is_string($geruest['name'] ?? null) && trim((string) $geruest['name']) !== '') {
            $upd['name'] = trim((string) $geruest['name']);
        }
        foreach (['consumer_name', 'claim', 'story'] as $feld) {
            $wert = $geruest['branding'][$feld] ?? null;
            if (is_string($wert) && trim($wert) !== '' && trim((string) $format->{$feld}) === '') {
                $upd[$feld] = trim($wert);
            }
        }
        if ($upd !== []) {
            $format = $formate->update($team, (int) $format->id, $upd);
        }

        return ['owner' => $format, 'neu' => $neu, 'branding_gesetzt' => array_keys($upd)]
            + $this->sessionUndKaskade($team, 'format', (int) $format->id, 'Format aus Brief: ' . ($format->name ?? ('#' . $format->id)), $brief, $opt);
    }

    // ── gemeinsame Schritte ─────────────────────────────────────────────

    private function brief(string $brief): string
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw new \InvalidArgumentException('brief ist Pflicht.');
        }

        return $brief;
    }

    private function titel(array $opt, string $standard): string
    {
        return trim((string) ($opt['titel'] ?? '')) ?: $standard;
    }

    /**
     * Bestehende Hülle (owner_id gesetzt): team-sichtbar UND team-EIGEN, sonst Fehler. Ohne owner_id → null (neu anlegen).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $klasse
     * @return T|null
     */
    private function eigeneHuelle(string $klasse, Team $team, array $opt, string $was, string $geerbt): ?object
    {
        if (empty($opt['owner_id'])) {
            return null;
        }
        $huelle = $klasse::visibleToTeam($team)->find((int) $opt['owner_id']);
        if ($huelle === null) {
            throw new NichtGefundenException("{$was} nicht gefunden oder nicht team-sichtbar.");
        }
        if (! $huelle->isOwnedBy($team)) {
            throw new GeerbtNurLesenException($was, "{$geerbt} gehört einem anderen Team – nur das Besitzer-Team darf planen. Bitte eine eigene Kopie anlegen.");
        }

        return $huelle;
    }

    /** @return array{session: FoodAlchemistPlanningSession, run: FoodAlchemistCascadeRun} */
    private function sessionUndKaskade(Team $team, string $ownerType, int $ownerId, string $titel, string $brief, array $opt): array
    {
        $via = (string) $opt['created_via'];
        $modus = in_array($opt['creative_mode'] ?? '', self::MODI, true) ? (string) $opt['creative_mode'] : 'voll_kreativ';
        $session = $this->sessions->create($team, [
            'title' => $titel,
            'brief' => $brief,
            'creative_mode' => $modus,
            'created_via' => $via,
        ]);
        // Leitplanken steuern Komposition (menue_*) und erben in Gerichte/Basisrezepte; ohne sie liefe die Kaskade mit leeren Reglern.
        if (! empty($opt['leitplanken']) && is_array($opt['leitplanken'])) {
            $session = $this->sessions->setGenerationParams($team, (int) $session->id, $opt['leitplanken']);
        }
        $run = $this->cascade->starteKaskade($team, 'vollkaskade', $session, $modus, [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'created_via' => $via,
        ]);

        return ['session' => $session, 'run' => $run];
    }
}
