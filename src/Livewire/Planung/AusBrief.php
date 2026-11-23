<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\Planung\PlanungsEinstiegService;
use Platform\FoodAlchemist\Support\Planung\DiktatZiele;
use Platform\FoodAlchemist\Support\Planung\PlanungReiterDaten;

/**
 * Planungs-Leitstelle · „Ganze Ausgabe aus einem Brief“ (Spec 85 C1 PR-5, 1:1-Verschiebung aus {@see Index}): Foodbook, Speisekarte,
 * Speiseplan, Angebot und Format aus einem Brief planen, je mit Auswahl eines bestehenden Objekts (Owner) und Diktat.
 *
 * EINE Klasse, ZWEIMAL eingebunden (Entscheid Kurator, Variante A): `ort = board` (Panels auf dem Board, über das Menü „Ganze Ausgabe
 * aus einem Brief“) und `ort = reiter` (Editor-Reiter mit Auswahllisten und im Foodbook-Reiter den Rails). Die beiden Instanzen teilen
 * den getippten Brief NICHT mehr (vorher dieselben Parent-Props). Die Zusammenführung der fünf Formulare bleibt ein eigener PR.
 *
 * Zum Parent: `planung.oeffne(id, tab)` nach dem Start (statt $this->oeffne), `planung.owner-gewaehlt(ownerType, ownerId)` nach der
 * Owner-Auswahl (Parent aktiviert die jüngste Session), `planung.meldung` für Diktat-Meldung/-Fehler im Modal-Kopf (nur bei Änderung).
 * Am Handy gesperrt (HandyLesebereich ohne Lese-Methoden; die Flächen sind dort ausgeblendet).
 */
class AusBrief extends Component
{
    use HandyLesebereich, WithFileUploads;

    /** board | reiter — welche der beiden Flächen diese Instanz rendert. */
    #[Locked]
    public string $ort = 'reiter';

    /** Aktive Planungs-Session des Parents (Foodbook-Reiter: Rails für das Foodbook der Session). */
    #[Reactive]
    public ?int $sessionId = null;

    /** Foodbook der aktiven Session (Owner-Kontext, rechnet der Parent ohnehin für den Banner), sonst null. */
    #[Reactive]
    public ?int $fbAusSession = null;

    /**
     * Aktiver Editor-Reiter des Parents (2.1d: Auswahlliste nur für den aktiven Reiter laden).
     * Nullbar: Livewire 4 hydriert reaktive Props bei JEDER Parent-Anfrage aus den übergebenen Props; die Board-Instanz
     * bekommt keinen Reiter → null. Als `string` warf das einen TypeError und jede Planungs-Anfrage endete in 500 (io, Charge 7).
     */
    #[Reactive]
    public ?string $editorTab = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    /** Foodbook-aus-Brief-Eingabe (Spec 42 F1): Rahmen wird in der Leitstelle geplant, nicht im Modul. */
    public string $fbTitel = '';

    public string $fbBrief = '';

    public ?string $fbMeldung = null;

    /** Gesetzt beim Handoff aus einem bestehenden Foodbook (Spec 42 F2): dann kein neues Foodbook anlegen. */
    public ?int $fbOwnerId = null;

    /** Speisekarte-aus-Brief-Eingabe (Spec-42-Vollzug Stufe 1) — spiegelt fb*, owner_type=speisekarte. */
    public string $skTitel = '';

    public string $skBrief = '';

    public ?string $skMeldung = null;

    /** Handoff aus einer bestehenden Speisekarte: dann keine neue Karte anlegen. */
    public ?int $skOwnerId = null;

    /** Speiseplan-aus-Brief-Eingabe (Spec-42-Vollzug Stufe 4) — spiegelt sk*, owner_type=speiseplan. */
    public string $spTitel = '';

    public string $spBrief = '';

    public ?string $spMeldung = null;

    /** Handoff aus einem bestehenden Speiseplan: dann keinen neuen anlegen. */
    public ?int $spOwnerId = null;

    /** #5 (2026-08-28): Angebot-aus-Brief-Eingabe — spiegelt sk*, owner_type=offer (Andock + Kaskade existieren). */
    public string $offerTitel = '';

    public string $offerBrief = '';

    public ?string $offerMeldung = null;

    /** Handoff aus einem bestehenden Angebot: dann kein neues anlegen. */
    public ?int $offerOwnerId = null;

    /** Format-aus-Brief-Eingabe (Kickoff-Tab) — owner_type=format (Kaskade/Gerüst/Dock existieren via MCP-Pipeline). */
    public string $fmtTitel = '';

    public string $fmtBrief = '';

    public ?string $fmtMeldung = null;

    /** Handoff aus einem bestehenden Format: dann kein neues anlegen. */
    public ?int $fmtOwnerId = null;

    /** Diktat: Audio-Blob fürs Briefing (WithFileUploads) + Ziel-Feld. */
    public $briefAudio = null;

    /**
     * Ziel des Diktats — eine Property-Pfad-Angabe, die vom CLIENT kommt und darum
     * gegen eine Whitelist läuft. Ohne sie könnte ein manipulierter Aufruf beliebige
     * Livewire-Properties überschreiben; Fail-closed ist hier keine Formalie.
     *
     * Deckt ALLE Briefing-Felder der Planungsstelle ab (Dominique 2026-09-02: „in der
     * planungsstelle"): die drei Erstell-Scopes plus die fünf Ausgabeformen.
     */
    public string $diktatZiel = 'fbBrief';

    /** #5: Angebot-Auswähler — bestehendes Angebot wählen → Owner + jüngste Session (spiegelt updatedSkOwnerId). */
    public function updatedOfferOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->offerOwnerId === null) {
            return;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($this->offerOwnerId);
        if ($angebot === null) {
            $this->offerOwnerId = null;

            return;
        }
        $this->dispatch('planung.owner-gewaehlt', ownerType: 'offer', ownerId: (int) $this->offerOwnerId);   // Parent aktiviert die jüngste Session (Spec 85 C1 PR-5)
    }

    /** Format-Auswähler — bestehendes Format wählen → Owner + jüngste Session (spiegelt updatedOfferOwnerId). */
    public function updatedFmtOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->fmtOwnerId === null) {
            return;
        }
        $format = FoodAlchemistFormat::visibleToTeam($team)->find($this->fmtOwnerId);
        if ($format === null) {
            $this->fmtOwnerId = null;

            return;
        }
        $this->dispatch('planung.owner-gewaehlt', ownerType: 'format', ownerId: (int) $this->fmtOwnerId);   // Parent aktiviert die jüngste Session (Spec 85 C1 PR-5)
    }

    /**
     * Stage 2 (Leitstelle-Auswähler, 2026-08-23): ein BESTEHENDES Foodbook zum Planen wählen. Setzt den
     * Owner-Kontext (fbOwnerId) → Buch-Ebene + Kapitel-Steuerung erscheinen; die jüngste Planungs-Session
     * dieses Foodbooks wird reaktiviert (Owner-Banner + Kaskaden-Cockpit). „Gerüst planen" (Brief) und
     * „Kapitel erzeugen" laufen danach wie gehabt. Deselect (leer) nimmt nur die Auswahl zurück.
     */
    public function updatedFbOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->fbOwnerId === null) {
            return;
        }
        $fb = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->find($this->fbOwnerId);
        if ($fb === null) {
            $this->fbOwnerId = null;

            return;
        }
        $this->dispatch('planung.owner-gewaehlt', ownerType: 'foodbook', ownerId: (int) $this->fbOwnerId);   // Parent aktiviert die jüngste Session (Spec 85 C1 PR-5)
    }

    /** Speisekarte-Auswähler (Stage 2, SK/SP-Parität): bestehende Speisekarte wählen → Owner + jüngste Session. */
    public function updatedSkOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->skOwnerId === null) {
            return;
        }
        $karte = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::visibleToTeam($team)->find($this->skOwnerId);
        if ($karte === null) {
            $this->skOwnerId = null;

            return;
        }
        $this->dispatch('planung.owner-gewaehlt', ownerType: 'speisekarte', ownerId: (int) $this->skOwnerId);   // Parent aktiviert die jüngste Session (Spec 85 C1 PR-5)
    }

    /** Speiseplan-Auswähler (Stage 2, SK/SP-Parität): bestehenden Speiseplan wählen → Owner + jüngste Session. */
    public function updatedSpOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->spOwnerId === null) {
            return;
        }
        $plan = \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::visibleToTeam($team)->find($this->spOwnerId);
        if ($plan === null) {
            $this->spOwnerId = null;

            return;
        }
        $this->dispatch('planung.owner-gewaehlt', ownerType: 'speiseplan', ownerId: (int) $this->spOwnerId);   // Parent aktiviert die jüngste Session (Spec 85 C1 PR-5)
    }

    /**
     * F1 (Spec 42) — „Foodbook aus Brief" IN der Leitstelle: Shell anlegen → Gerüst aus Brief →
     * Struktur anwenden → Session + Voll-Kaskade (owner_type=foodbook). Spiegelt den bisherigen
     * Foodbook-Kickoff (`Foodbooks/Index::frameAusBriefVorschlagen` + `strukturAnwenden` +
     * `vollKaskadeStarten`), aber der Rahmen entsteht jetzt hier — das Foodbook wird zur reinen
     * Ausgabe. Ergebnis dockt via `attachToOutput` automatisch ins Foodbook zurück; der Owner-Banner
     * (Spec 40 E1b) leuchtet, sobald der Lauf `source_owner_type='foodbook'` trägt.
     */
    public function foodbookAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->fbMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->fbMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->fbBrief) === '') {
            $this->fbMeldung = 'Bitte einen Brief eingeben (Anlass, Gäste, Saison, Niveau, Budget …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->foodbookAusBrief($team, $this->fbBrief, [
                'owner_id' => $this->fbOwnerId,
                'titel' => $this->fbTitel,
                'created_via' => 'leitstelle_foodbook_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->fbTitel = '';
            $this->fbBrief = '';
            $this->fbOwnerId = null;
            $this->dispatch('planung.oeffne', id: (int) $r['session']->id, tab: 'worker');   // Parent öffnet den Lauf (Spec 85 C1 PR-5)
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->fbMeldung = $e->getMessage();
        }
    }

    /**
     * Spec-42-Vollzug Stufe 1 — „Speisekarte aus Brief" IN der Leitstelle. Spiegelt {@see foodbookAusBrief},
     * aber owner_type=speisekarte: die „aus Brief"-Kette ist owner-generisch (der Gerüst-Prompt ist
     * owner-neutral), und der Container wird pro Slot als Rubrik angelegt — DAHER KEIN `strukturAusGeruest`:
     * `PlanningCascadeService::vollkaskadeSlots` ruft `SpeisekarteService::rubrikFuerSlot` inline im Fan-out.
     * Ergebnis dockt via `GenerateConceptJob::attachEinmal` (speisekarte→`addPosition(menue_ref)`) zurück.
     */
    public function speisekarteAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->skMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->skMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->skBrief) === '') {
            $this->skMeldung = 'Bitte einen Brief eingeben (Anlass, Gäste, Saison, Niveau, Budget …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->speisekarteAusBrief($team, $this->skBrief, [
                'owner_id' => $this->skOwnerId,
                'titel' => $this->skTitel,
                'created_via' => 'leitstelle_speisekarte_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->skTitel = '';
            $this->skBrief = '';
            $this->skOwnerId = null;
            $this->dispatch('planung.oeffne', id: (int) $r['session']->id, tab: 'worker');   // Parent öffnet den Lauf (Spec 85 C1 PR-5)
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->skMeldung = $e->getMessage();
        }
    }

    /**
     * Spec-42-Vollzug Stufe 4 — „Speiseplan aus Brief" IN der Leitstelle. Anders als Foodbook/Speisekarte
     * braucht der Speiseplan kein Gänge-Gerüst: {@see SpeiseplanService::create} legt die GV-Standard-Linien
     * (Menü 1 / Vegetarisch / Dessert) + Zyklus (cycle_weeks) + Start-Montag an. Der Zyklus wird — falls im
     * Brief genannt („4 Wochen") — übernommen. Danach startet die Grid-Voll-Kaskade
     * ({@see PlanningCascadeService::starteSpeiseplanVollkaskade}): je leerer Zelle (Tag × Mahlzeit × Linie)
     * ein Gericht, das den Brief-Kontext der Session erbt. Linien bleiben im Speiseplan-Editor frei änderbar.
     */
    public function speiseplanAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->spMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->spMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->spBrief) === '') {
            $this->spMeldung = 'Bitte einen Brief eingeben (Anlass, Saison, Linien, Zyklus …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->speiseplanAusBrief($team, $this->spBrief, [
                'owner_id' => $this->spOwnerId,
                'titel' => $this->spTitel,
                'created_via' => 'leitstelle_speiseplan_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->spTitel = '';
            $this->spBrief = '';
            $this->spOwnerId = null;
            $this->dispatch('planung.oeffne', id: (int) $r['session']->id, tab: 'worker');   // Parent öffnet den Lauf (Spec 85 C1 PR-5)
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->spMeldung = $e->getMessage();
        }
    }

    /**
     * #5 (Dominique 2026-08-28) — „Angebot aus Brief" IN der Leitstelle. Spiegelt {@see speisekarteAusBrief},
     * owner_type=offer: der Gerüst-Prompt ist owner-neutral, die Voll-Kaskade erzeugt je Slot ein Konzept,
     * das via `GenerateConceptJob`-Andock direkt ans Angebot referenziert wird (Pivot foodalchemist_offer_concept;
     * KEIN Zwischen-Container wie Kapitel/Rubrik — containerId = Angebots-ID). Andock + Kaskade existieren bereits;
     * hier fehlte nur der Leitstellen-Einstieg.
     */
    public function angebotAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->offerMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->offerMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->offerBrief) === '') {
            $this->offerMeldung = 'Bitte einen Brief eingeben (Anlass, Gäste, Saison, Niveau, Budget …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->angebotAusBrief($team, $this->offerBrief, [
                'owner_id' => $this->offerOwnerId,
                'titel' => $this->offerTitel,
                'created_via' => 'leitstelle_offer_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->offerTitel = '';
            $this->offerBrief = '';
            $this->offerOwnerId = null;
            $this->dispatch('planung.oeffne', id: (int) $r['session']->id, tab: 'worker');   // Parent öffnet den Lauf (Spec 85 C1 PR-5)
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->offerMeldung = $e->getMessage();
        }
    }

    /**
     * Format aus Brief (Kickoff-Tab) — Leitstellen-Einstieg zum bestehenden MCP-Pfad
     * (FormatPlanFromBriefTool). owner_type=format: Marken-Identität + eigenständige Concepte je Slot,
     * die automatisch ans Format docken (Kaskade/Gerüst/Dock kennen 'format' bereits).
     */
    public function formatAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->fmtMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->fmtMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->fmtBrief) === '') {
            $this->fmtMeldung = 'Bitte einen Brief eingeben (Marke, Anlass, Ausrichtung, Niveau …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->formatAusBrief($team, $this->fmtBrief, [
                'owner_id' => $this->fmtOwnerId,
                'titel' => $this->fmtTitel,
                'created_via' => 'leitstelle_format_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->fmtTitel = '';
            $this->fmtBrief = '';
            $this->fmtOwnerId = null;
            $this->dispatch('planung.oeffne', id: (int) $r['session']->id, tab: 'worker');   // Parent öffnet den Lauf (Spec 85 C1 PR-5)
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->fmtMeldung = $e->getMessage();
        }
    }

    /**
     * Diktat fürs Briefing: gesprochener Text wird ANGEHÄNGT, nie ersetzt — ein Diktat
     * ist ein Nachtrag, und ein versehentlich überschriebenes Briefing wäre nicht
     * wiederherstellbar. Reines STT, kein Tool-Loop: das Briefing soll exakt das sein,
     * was der Mensch gesagt hat, nicht die Deutung eines Agenten. Die Deutung passiert
     * danach sichtbar in einem Schritt (Leitplanken vorschlagen).
     */
    public function updatedBriefAudio(): void
    {
        $this->briefDiktatUebernehmen();                             // dünner Hook, Logik testbar daneben
    }

    public function briefDiktatUebernehmen(): void
    {
        if (! DiktatZiele::erlaubt($this->diktatZiel)) {
            $this->fehler = 'Unbekanntes Diktat-Ziel — nichts übernommen.';
            $this->briefAudio = null;

            return;
        }
        if ($this->briefAudio === null) {
            return;
        }
        $r = DiktatZiele::transkribiere($this->briefAudio);
        $this->briefAudio = null;
        if ($r['fehler'] !== null) {
            $this->fehler = $r['fehler'];

            return;
        }
        $ziel = $this->diktatZiel;
        $this->{$ziel} = DiktatZiele::anhaengen((string) ($this->{$ziel} ?? ''), $r['text']);
        $this->fehler = null;
        $this->meldung = 'Diktat übernommen — bitte gegenlesen.';
    }

    /** Handoff aus Foodbook/Speisekarte/Angebot (`?fb_owner=` …): Owner vorbelegen (früher Index::mount). Die Session aktiviert der Parent. */
    public function mount(): void
    {
        foreach (['fb_owner' => 'fbOwnerId', 'sk_owner' => 'skOwnerId', 'offer_owner' => 'offerOwnerId'] as $param => $prop) {
            if (request()->filled($param)) {
                $this->{$prop} = (int) request($param);
            }
        }
        $this->gemeldet = $this->meldeStand();
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
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

    /** Diktat-Meldung und -Fehler erscheinen wie bisher im Modal-Kopf des Parents (nur bei Änderung). */
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

    public function render()
    {
        $team = $this->team();
        $daten = [];
        if ($this->ort === 'reiter') {
            // 2.1d: Auswahllisten bestehender Ausgabe-Objekte nur für den aktiven Reiter ({@see PlanungReiterDaten::auswahl}).
            $daten = app(PlanungReiterDaten::class)->auswahl($team, (string) $this->editorTab);
            // Foodbook-Reiter: Rails für das gewählte Foodbook ODER das Foodbook der aktiven Session (Owner-Kontext aus dem Parent).
            $fbAktiv = $this->fbOwnerId ?? $this->fbAusSession;
            $daten['fbAktiv'] = $fbAktiv;
        }

        return view('foodalchemist::livewire.planung.aus-brief', $daten);
    }
}
