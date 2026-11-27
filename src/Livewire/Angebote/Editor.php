<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;

/**
 * Angebote-Editor (Fullscreen-Modal, pro Angebot) — 1:1-Fork des Foodbook-Editors
 * ({@see \Platform\FoodAlchemist\Livewire\Foodbooks\Index}) aufs Angebot. Die volle
 * Editor-Maschinerie (Kapitel-Baum-CRUD, Board/Fortschritt, Katalog-Picker
 * Concept/Paket/Format/Gericht, Block-Editor mit Presets/Wahlgruppen/Slot-Wording/
 * Ebene/Sichtbar, Branding & Präsentation, KI-Texte, Bilder) — aber offer-scoped über
 * {@see AngebotService} + {@see OfferCompositionService} statt FoodbookService, und
 * FoodAlchemistFoodbook*→FoodAlchemistOffer* / foodbook_id→offer_id.
 *
 * PLUS die Angebot-Spezifika, die aus dem bisherigen Editor ERHALTEN bleiben:
 * Fullscreen-Modal (`angebot-editor.bearbeiten` → `modal.open`), Anfrage-Tab-Formular,
 * speichern/statusSetzen/loeschen/anProduktion, Voll-Kaskade (Leitstelle-Handoff),
 * Gerüst-Slots (VOR der Kaskade prüfen/bauen), CRM-Verknüpfung, ManagesCanvas,
 * InteractsWithSavedToast, `concepter-gespeichert`/`aktiver-betrieb-geaendert`.
 *
 * Anders als das Foodbook (Browser + Editor in EINER Seite) ist dies ein reiner
 * Modal-Editor pro Angebot — der Angebots-Browser lebt in {@see Index}. Es gibt daher
 * KEINE Pagination/Liste hier; das Angebot wird per `oeffnen(id)` gewählt.
 */
class Editor extends Component
{
    use ManagesCanvas;
    use WithFileUploads;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65
    use \Platform\FoodAlchemist\Livewire\Concerns\AngebotBrandingBilder;   // Logo/Cover/Präsentationsbilder + Kopieren
    use Concerns\AngebotKapitelBaum;   // 98 · 3.3: Kapitel ordnen
    use Concerns\AngebotKiText;        // 98 · 3.3: KI-Kundentext-Vorschau
    use Concerns\AngebotPraesentation;  // 98 · 3.3: Präsentation veröffentlichen

    /** Spec 65: Sperre gilt dem geöffneten Angebot. */
    protected function sperrZiel(): ?array
    {
        return $this->selectedId !== null ? ['angebot', $this->selectedId] : null;
    }

    protected function sperrModalName(): ?string
    {
        return 'angebot-editor';
    }

    /**
     * Spec 65 · ohne Sperre: Navigation (Kopf/Kapitel wählen), Katalog-Filter, Menü im Concepter öffnen (eigener Editor
     * mit eigener Sperre), Vorschlag/Inline-Edit verwerfen, Signale (Concepter-Recompute = abgeleitete Preise, Betrieb).
     * Block-/Slot-Bearbeiten öffnet ein Formular → erst im Bearbeiten-Modus.
     */
    protected function sperrFreiExtra(): array
    {
        return [
            'beiModalGeschlossen', 'kopfAnzeigen', 'kapitelWaehle', 'bearbeiteMenue',
            'katalogModus', 'waehleGerichtHg', 'waehleGerichtKlasse', 'toggleConceptFacet', 'resetConceptFacetten',
            'slotWordingAbbrechen', 'kiTextVerwerfen', 'nachConcepterEdit', 'betriebGewechselt',
            'canvasLaden', 'canvasTemplateData', 'canvasSchreibstile',
        ];
    }

    /** Spec 65: Editor geschlossen → eigene Sperre frei (einziger modal.closed-Handler dieser Komponente). */
    #[On('modal.closed')]
    public function beiModalGeschlossen(?string $name = null): void
    {
        $this->sperreBeiSchliessen($name);
    }

    /** Abbrechen: ungespeicherten Formularstand verwerfen, Angebot frisch laden. */
    protected function nachAbbrechen(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->ladeForm();
        $this->ladeKapitelForm();
        $this->editBlockId = null;
        $this->blockForm = [];
        $this->slotWordingAbbrechen();
        $this->kiTextVerwerfen();
        $this->neuesKapitelTitel = '';
        $this->brandingLoadedId = null;        // render() lädt Branding + Präsentation neu
        $this->presentationLoadedId = null;
        $this->canvasLaden();
    }

    /** Upload-Hooks (updated*) laufen nicht über den call-Haken — hier selbst prüfen. */
    private function uploadGesperrt(string $prop): bool
    {
        if (self::sperreAktiv() && ! $this->darfSchreiben()) {
            $this->reset($prop);
            $this->sperreAbgewiesen('updated'.ucfirst($prop));

            return true;
        }

        return false;
    }

    public ?int $selectedId = null;

    /** Master-Detail: gewähltes Kapitel (Speisen-/Aufbau-Ansicht). */
    public ?int $selectedKapitelId = null;

    // ── Anfrage-Tab: Angebots-Kopf (Angebot-Spezifikum, erhalten) ──────────────
    public array $form = [
        'name' => '', 'status' => 'anfrage', 'occasion' => '', 'personen' => null,
        'budget' => null, 'event_date' => null, 'location' => '', 'diet_requirement' => '',
        'brief' => '', 'total_price' => null, 'valid_until' => null, 'price_mode' => 'auto',
        'price_override_reason' => '',
    ];

    // ── Kapitel-Formular (Foodbook-Parität) ────────────────────────────────────
    /** `description` = Kapitel-Kundentext (Hinführung / Story). */
    public array $kapitelForm = ['title' => '', 'consumer_title' => '', 'description' => '', 'price_mode' => 'auto', 'price_per_person' => null, 'personen' => null, 'writing_style_id' => null, 'is_struktur' => false];

    public string $neuesKapitelTitel = '';

    // ── Katalog-Picker (concept|paket|format|gericht) ──────────────────────────
    public string $conceptSuche = '';

    public string $paketSuche = '';

    public string $formatSuche = '';

    public string $gerichtSuche = '';

    public ?int $gerichtHauptgruppe = null;

    public ?int $gerichtDishClass = null;

    /** Aktiver Katalog-Modus (Server-Modus, wie Speisekarte/Foodbook). */
    public string $pickerModus = 'concept';

    /** @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int} */
    public array $conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    /** @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int} */
    public array $paketFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    // ── Block-Editor ───────────────────────────────────────────────────────────
    public ?int $editBlockId = null;

    public array $blockForm = [];

    /** Inline-editierte Gericht-Zeile in der Block-Vorschau — Key "blockId:slotId" + Text. */
    public ?string $editSlotKey = null;

    public string $editSlotWording = '';

    /** Markierte concept_ref-Blöcke für die Wahl-Gruppe. */
    public array $markiert = [];

    // ── CRM-Picker (Angebot-Spezifikum, erhalten) ──────────────────────────────
    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    // ── Branding / CI (pro Angebot) ────────────────────────────────────────────
    public array $brandingForm = ['brand_color' => '#6d28d9', 'band_color' => '', 'footer_text' => ''];

    public $logoUpload = null;

    public $coverUpload = null;

    /** Freie Präsentationsbilder 1–3 (image-Block im Design mit quelle=angebot_N). Index = Nummer. */
    public array $praesBildUpload = [1 => null, 2 => null, 3 => null];

    public ?int $brandingLoadedId = null;

    public ?string $brandingFehler = null;

    public bool $brandingGespeichert = false;

    // ── Kapitel-Bild + Galerie (Bild-Epic) ─────────────────────────────────────
    public $kapitelImageUpload = null;

    public ?string $kapitelImageFehler = null;

    /** @var array<int, \Illuminate\Http\UploadedFile> */
    public $kapitelGalleryUpload = [];

    // ══════════════════════════════════════════════════════════════════════════
    //  Modal-Lifecycle (Angebot-Spezifikum, erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    #[On('angebot-editor.bearbeiten')]
    public function oeffnen(int $id): void
    {
        if ($this->selectedId !== null && $this->selectedId !== $id) {
            $this->bearbeitenBeenden();   // Spec 65: Wechsel auf ein anderes Angebot gibt die eigene Sperre frei
        }
        $this->selectedId = $id;
        $this->selectedKapitelId = null;
        $this->editBlockId = null;
        $this->markiert = [];
        $this->firmaSuche = '';
        $this->kontaktSuche = '';
        $this->conceptSuche = '';
        $this->resetConceptFacetten();
        $this->ladeForm();
        $this->dispatch('modal.open', name: 'angebot-editor');
    }

    private function ladeForm(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        // Fürs Formular genügt der Angebotskopf. Die schweren Editor-Relationen
        // lädt render() direkt danach genau einmal.
        $a = FoodAlchemistAngebot::visibleToTeam($this->team())->find($this->selectedId);
        if ($a === null) {
            $this->selectedId = null;

            return;
        }
        // Öffnen ist ein reiner Leseweg. Preisändernde Commands aktualisieren den
        // Auto-Preis bereits selbst; eine volle Kalkulation hier würde direkt vor
        // dem Render dieselbe schwere Arbeit nochmals ausführen und in die DB schreiben.
        $this->form = [
            'name' => $a->name,
            'status' => $a->status?->value ?? 'anfrage',
            'occasion' => $a->occasion,
            'personen' => $a->personen,
            'budget' => $a->budget,
            'event_date' => $a->event_date?->format('Y-m-d'),
            'location' => $a->location,
            'diet_requirement' => $a->diet_requirement,
            'brief' => $a->brief,
            'total_price' => $a->total_price,
            'valid_until' => $a->valid_until?->format('Y-m-d'),
            'price_mode' => $a->price_mode ?? 'auto',
            'price_override_reason' => $a->price_override_reason ?? '',
        ];
    }

    /** Spec 65: „Speichern" schreibt und beendet die Bearbeitung — der Editor bleibt offen im Lesemodus. */
    public function speichern(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->kopfSchreiben($svc);
        $this->bearbeitenBeenden();
    }

    /**
     * Sofort-Übernahme im Bearbeiten-Modus (Gäste-Feld, „Preis übernehmen" in der Kalkulation): schreibt den Kopf,
     * die Bearbeitung läuft weiter. Ohne Sperre weist der call-Haken ab.
     */
    public function kopfUebernehmen(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $this->kopfSchreiben($svc);
        }
    }

    private function kopfSchreiben(AngebotService $svc): void
    {
        $svc->update($this->team(), $this->selectedId, $this->form);
        // Tab-übergreifend: der prominente „Speichern" sichert auch das Branding-Tab
        // (Marken-Farbe/Bandfarbe/Footer) — spiegelt Foodbook::speichern. Idempotent,
        // wenn Branding nicht angefasst wurde; Hex-Fehler landen in brandingFehler.
        $this->brandingSpeichern($svc);
        $this->ladeForm();
        $this->dispatch('angebot-gespeichert');
        $this->savedToast('Angebot gespeichert');
        // Der übernommene KI-Text ist jetzt echter Feld-Inhalt — die Vorschau-Fläche ruht.
        $this->kiTextHinweis = null;
    }

    /** Spec 82 §12 B3: Angebot hat einen aktiven eingefrorenen Stand (nur zur Anzeige und für die Rückweg-Bestätigung). */
    public bool $eingefroren = false;

    /** Schritt 4b: Vorschau „neuere Fassung übernehmen“ (Diff, Zwischenfassungen, betroffene Angebote) und die ausdrückliche Bestätigung. @var array<string,mixed>|null */
    public ?array $uebernahmeVorschau = null;

    public bool $uebernahmeBestaetigt = false;

    public function uebernahmePruefen(int $zeileId, int $familieId): void
    {
        $this->uebernahmeBestaetigt = false;
        $angebot = $this->selectedId !== null ? app(AngebotService::class)->detail($this->team(), $this->selectedId) : null;
        if ($angebot === null) {
            return;
        }
        try {
            $this->uebernahmeVorschau = app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotEinfrierService::class)->uebernahmeVorschau($angebot, $zeileId, $familieId);
        } catch (\RuntimeException $e) {
            $this->uebernahmeVorschau = null;
            $this->errorToast($e->getMessage());
        }
    }

    public function uebernahmeAbbrechen(): void
    {
        $this->uebernahmeVorschau = null;
        $this->uebernahmeBestaetigt = false;
    }

    public function uebernahmeAusfuehren(): void
    {
        $angebot = $this->selectedId !== null ? app(AngebotService::class)->detail($this->team(), $this->selectedId) : null;
        $v = $this->uebernahmeVorschau;
        if ($angebot === null || $v === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotEinfrierService::class)
                ->uebernehmen($this->team(), Auth::user(), $angebot, (int) $v['zeile_id'], (int) $v['familie_id'], $this->uebernahmeBestaetigt);
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->uebernahmeAbbrechen();
        $this->savedToast('Neuere Fassung übernommen');
        $this->dispatch('angebot-gespeichert');
    }

    /** Rückweg aus dem eingefrorenen Stand: bestätigt (wire:confirm), verwirft den Snapshot. Eigene Methode, damit Livewire nur Positionsargumente ohne Dienst-Parameter dazwischen bekommt. */
    public function statusSetzenVerwerfend(string $status, AngebotService $svc): void
    {
        $this->statusSetzen($status, $svc, true);
    }

    public function statusSetzen(string $status, AngebotService $svc, bool $snapshotVerwerfen = false): void
    {
        if ($this->selectedId === null) {
            return;
        }
        try {
            $svc->setStatus($this->team(), $this->selectedId, $status, $snapshotVerwerfen);
        } catch (\RuntimeException $e) {
            $this->errorToast($e->getMessage());   // z. B. Rückweg aus dem eingefrorenen Stand ohne Bestätigung (Spec 82 §12 B3)

            return;
        }
        $this->ladeForm();
        $this->dispatch('angebot-gespeichert');
    }

    /** Stufe 3 — Angebot in die Produktion übergeben (concept × Pax → Produktionsauftrag). */
    public function anProduktion(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $res = $svc->anProduktion($this->team(), $this->selectedId, Auth::id());
        session()->flash('angebot_produktion', $res['order_id'] !== null
            ? "In Produktion übergeben ({$res['ziele']} Ziele) — jetzt im Tagesplan planbar."
            : 'Kein Menü/Concept im Angebot — nichts zu übergeben.');
        $this->dispatch('angebot-gespeichert');
    }

    public function loeschen(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $id = $this->selectedId;
        $svc->delete($this->team(), $id);
        $this->bearbeitenBeenden();   // Spec 65: Sperre am gelöschten Angebot nicht stehen lassen
        $this->selectedId = null;
        $this->dispatch('modal.close', name: 'angebot-editor');
        $this->dispatch('angebot-geloescht', id: $id);
    }

    /** Zurück auf den Angebots-Kopf (Master-Detail: kein Kapitel gewählt). */
    public function kopfAnzeigen(): void
    {
        $this->selectedKapitelId = null;
        $this->editBlockId = null;
        $this->markiert = [];
        if ($this->kiTextZiel === 'kapitel') {
            $this->kiTextZuruecksetzen('angebot');   // die Kapitel-Fläche ist weg, ihr Vorschlag auch
        }
        $this->dispatch('angebot-goto', tab: 'anfrage');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  CRM-Kunde-Link (Angebot-Spezifikum, erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    public function verknuepfeFirma(int $companyId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $a = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $companyId, $a?->crm_contact_id);
        $this->firmaSuche = '';
        $this->dispatch('angebot-gespeichert');
    }

    public function verknuepfeKontakt(int $contactId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $a = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $a?->crm_company_id, $contactId);
        $this->kontaktSuche = '';
        $this->dispatch('angebot-gespeichert');
    }

    public function loeseKunde(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->verknuepfeKunde($this->team(), $this->selectedId, null, null);
        $this->dispatch('angebot-gespeichert');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Angebots-lokale Menü-Entwürfe (Concepter-Editor, nested) — erhalten
    // ══════════════════════════════════════════════════════════════════════════

    public function bearbeiteMenue(int $conceptId): void
    {
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $conceptId);
    }

    public function referenziereConcept(int $conceptId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->referenziereConcept($this->team(), $this->selectedId, $conceptId);
        $this->conceptSuche = '';
        $this->dispatch('angebot-gespeichert');
    }

    public function entferneReferenz(int $conceptId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->entferneReferenz($this->team(), $this->selectedId, $conceptId);
        $this->dispatch('angebot-gespeichert');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Kapitel-Baum (Foodbook-Fork, offer-scoped über OfferCompositionService)
    // ══════════════════════════════════════════════════════════════════════════

    /** Nach jeder Kompositions-Änderung: Auto-Preis neu + Form + Liste aktualisieren. */
    private function nachKomposition(): void
    {
        if ($this->selectedId !== null) {
            app(AngebotService::class)->recomputeAngebot($this->team(), $this->selectedId);
            $this->ladeForm();
            $this->dispatch('angebot-gespeichert');
        }
    }

    private function eigenesKapitel(int $id): ?FoodAlchemistOfferChapter
    {
        $team = $this->team();
        $k = FoodAlchemistOfferChapter::visibleToTeam($team)->find($id);

        return ($k !== null && $k->isOwnedBy($team)) ? $k : null;
    }

    private function eigenerBlock(int $id): ?FoodAlchemistOfferBlock
    {
        $team = $this->team();
        $b = FoodAlchemistOfferBlock::visibleToTeam($team)->find($id);

        return ($b !== null && $b->isOwnedBy($team)) ? $b : null;
    }

    public function kapitelNeu(?int $parentId = null): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc = app(OfferCompositionService::class);   // via Container, nicht als Action-Param — DI kollidiert sonst mit $parentId
        $titel = $parentId !== null ? 'Neues Unterkapitel' : ($this->neuesKapitelTitel ?: 'Neues Kapitel');
        $k = $svc->addKapitel($this->team(), $this->selectedId, ['title' => $titel], $parentId);
        $this->neuesKapitelTitel = '';
        $this->selectedKapitelId = $k->id;
        $this->ladeKapitelForm();
        $this->nachKomposition();
    }

    public function kapitelWaehle(int $id): void
    {
        // Fremde/geerbte Kapitel gar nicht erst auswählen — sonst lädt ladeKapitelForm() ihre Daten.
        if ($this->eigenesKapitel($id) === null) {
            return;
        }
        $this->selectedKapitelId = $id;
        $this->ladeKapitelForm();
        $this->editBlockId = null;
        $this->markiert = [];
        $this->dispatch('angebot-goto', tab: 'speisen');
    }

    private function ladeKapitelForm(): void
    {
        // Kapitel-Wechsel = anderer Gegenstand: ein KI-Vorschlag fürs vorige Kapitel darf hier
        // nicht stehen bleiben (er würde beim „Übernehmen" im falschen Feld landen).
        if ($this->kiTextZiel === 'kapitel') {
            $this->kiTextZuruecksetzen('kapitel');
        }
        if ($this->selectedKapitelId === null) {
            return;
        }
        $k = $this->eigenesKapitel($this->selectedKapitelId);
        if ($k) {
            $this->kapitelForm = [
                'title' => $k->title, 'consumer_title' => $k->consumer_title ?? '',
                'description' => $k->description ?? '',
                'price_mode' => $k->price_mode, 'price_per_person' => $k->price_per_person,
                'personen' => $k->personen,
                'writing_style_id' => $k->writing_style_id,
                'is_struktur' => (bool) $k->is_struktur,
            ];
        }
    }

    public function kapitelSpeichern(OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId !== null) {
            $comp->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
            if ($this->kiTextZiel === 'kapitel') {
                $this->kiTextHinweis = null;
            }
            $this->nachKomposition();
        }
    }

    /** Board: manuellen Kapitel-Fortschritt setzen (offen|in_arbeit|fertig). */
    public function kapitelFortschritt(int $id, string $wert, OfferCompositionService $comp): void
    {
        try {
            $comp->kapitelFortschritt($this->team(), $id, $wert);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    public function kapitelLoeschen(int $id, OfferCompositionService $comp): void
    {
        $comp->deleteKapitel($this->team(), $id);
        if ($this->selectedKapitelId === $id) {
            $this->selectedKapitelId = null;
        }
        $this->nachKomposition();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Katalog-Picker (concept | paket | format | gericht)
    // ══════════════════════════════════════════════════════════════════════════

    public function katalogModus(string $modus): void
    {
        if (in_array($modus, ['concept', 'paket', 'format', 'gericht'], true)) {
            $this->pickerModus = $modus;
        }
    }

    public function conceptHinzu(int $conceptId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            $comp->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->conceptSuche = '';
        $this->nachKomposition();
    }

    /**
     * Ein Paket (kind=paket-Concept) als concept_ref-Block ans Kapitel — dieselbe Buchung
     * wie {@see conceptHinzu} (concept_id trägt Concept + Paket). Eigener Picker-Reiter.
     */
    public function paketHinzu(int $paketId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            $comp->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $paketId]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->paketSuche = '';
        $this->nachKomposition();
    }

    /**
     * Ein Format ins Angebot buchen — als eigenes (lebendes) Format-Kapitel. Braucht nur das
     * gewählte Angebot, kein Ziel-Kapitel. Fail-soft: Kunden-IP-/Status-Guard meldet sich
     * als Fehler, kippt den Editor nicht.
     */
    public function formatEinfuegen(int $formatId, OfferCompositionService $comp): void
    {
        if ($this->selectedId === null) {
            return;
        }
        try {
            $comp->insertFormatKapitel($this->team(), $this->selectedId, $formatId);
        } catch (\Throwable $e) {
            $this->addError('formatKapitel', $e->getMessage());

            return;
        }
        $this->formatSuche = '';
        $this->nachKomposition();
    }

    /** Nur für Format-Kapitel: additiv|alternativen umschalten. */
    public function formatPreisModus(int $chapterId, string $mode, OfferCompositionService $comp): void
    {
        try {
            $comp->setFormatPriceMode($this->team(), $chapterId, $mode);
            $this->nachKomposition();
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /**
     * Einzel-Gericht (VK-Rezept) als `recipe_ref`-Block direkt ans Kapitel (€/Position).
     * Spiegelt {@see conceptHinzu}; die Schreibpfad-Validierung übernimmt addBlock/pruefeRecipeRef.
     */
    public function gerichtHinzu(int $recipeId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            $comp->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'recipe_ref', 'recipe_id' => $recipeId]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->gerichtSuche = '';
        $this->nachKomposition();
    }

    public function waehleGerichtHg(?int $hgId): void
    {
        $this->gerichtHauptgruppe = ($this->gerichtHauptgruppe === $hgId) ? null : $hgId;
        $this->gerichtDishClass = null;
    }

    public function waehleGerichtKlasse(int $dishClassId): void
    {
        $this->gerichtDishClass = ($this->gerichtDishClass === $dishClassId) ? null : $dishClassId;
    }

    public function updatedGerichtHauptgruppe(): void
    {
        $this->gerichtDishClass = null;
    }

    public function toggleConceptFacet(string $feld, int $id): void
    {
        if (! array_key_exists($feld, $this->conceptFacetten)) {
            return;
        }
        $this->conceptFacetten[$feld] = ($this->conceptFacetten[$feld] === $id) ? null : $id;
    }

    public function resetConceptFacetten(): void
    {
        $this->conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];
    }

    public function updatedConceptFacetten($value, $key): void
    {
        $this->conceptFacetten[$key] = ($value === '' || $value === null) ? null : (int) $value;
    }

    public function updatedPaketFacetten($value, $key): void
    {
        $this->paketFacetten[$key] = ($value === '' || $value === null) ? null : (int) $value;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Blöcke
    // ══════════════════════════════════════════════════════════════════════════

    public function presetHinzu(string $type, ?string $slug, ?string $label, ?string $preisBasis, bool $sichtbar, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $comp->addBlock($this->team(), $this->selectedKapitelId, [
            // Spec 50 · C-7: `header_source` GIBT es am Angebot (Spalte seit 2026_08_31_000033) —
            // es fehlte nur in OfferCompositionService::BLOCK_FELDER und wurde deshalb still
            // verworfen. Der frühere Kommentar an dieser Stelle („keine Spalte") war falsch.
            // Die Preset-Herkunft trägt die KI-Lineage und gehört ans Angebot wie ans Foodbook.
            'type' => $type, 'label' => $label, 'header_source' => $slug,
            'price_basis' => $type === 'header_frei_preis' ? ($preisBasis ?: 'person') : null,
            'price_value' => $type === 'header_frei_preis' ? 0 : null,
            'visible' => $sichtbar,
        ]);
        $this->nachKomposition();
    }

    public function blockBasis(string $type, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $comp->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type,
            'height' => $type === 'spacer' ? 'mittel' : null,
            'price_basis' => $type === 'header_frei_preis' ? 'person' : null,
            'price_value' => $type === 'header_frei_preis' ? 0 : null,
        ]);
        $this->nachKomposition();
    }

    public function blockBearbeiten(int $id): void
    {
        $block = $this->eigenerBlock($id);
        if ($block === null) {
            return;
        }
        $this->editBlockId = $id;
        $this->blockForm = [
            'label' => $block->label ?? '', 'wording' => $block->wording ?? '',
            'customer_text' => $block->customer_text ?? '',
            'price_value' => $block->price_value, 'price_basis' => $block->price_basis ?? 'person',
            'height' => $block->height ?? 'mittel', 'interne_bemerkung' => $block->interne_bemerkung ?? '', 'quantity' => $block->quantity, 'unit_vocab_id' => (string) ($block->unit_vocab_id ?? ''), 'ek_value' => $block->ek_value,
        ];
    }

    /**
     * ✨ Kundentext-Vorschlag für den gerade editierten concept_ref-Block — der Marketing-Text
     * lebt kundenspezifisch am Block (nicht am Gericht). Fail-soft: Feld bleibt bei Provider-
     * Ausfall/Kill-Switch unverändert, kein Crash im Editor.
     */
    public function kiKundentext(): void
    {
        if ($this->editBlockId === null) {
            return;
        }
        $block = FoodAlchemistOfferBlock::visibleToTeam($this->team())
            ->with('concept.slots.dish:id,name,sales_wording_standard')->find($this->editBlockId);
        if ($block === null || $block->concept === null) {
            return;
        }
        $wording = app(\Platform\FoodAlchemist\Services\Concepter\WordingResolver::class);
        $kontext = [
            'concept' => $block->concept->name,
            'anzeigename' => trim((string) ($this->blockForm['wording'] ?? '')) !== '' ? $this->blockForm['wording'] : null,
            'gerichte' => collect($wording->gerichtZeilen($block->concept, $block))
                ->where('type', 'gericht')->pluck('text')->values()->all(),
        ];
        try {
            $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('vk.marketing', $kontext, [
                // Ebene 2 der DNA-Kette: Endkunde des Angebots (Kunde-DNA fließt in den Marketing-Text)
                'food_dna_angebot_id' => $this->selectedId,   // Spec 82 §7: Angebots-Ebene der Kaskade
                'food_dna_crm_company_id' => FoodAlchemistAngebot::whereKey($this->selectedId)->value('crm_company_id'),
                'target_table' => 'foodalchemist_offer_blocks', 'target_id' => $block->id,
            ]);
            $text = $v->werte['marketing_text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $this->blockForm['customer_text'] = trim($text);
            }
        } catch (\Throwable $e) {
            // still — Feld bleibt unverändert (Kill-Switch/Provider-Fehler); kein Crash im Editor
        }
    }

    public function blockSpeichern(OfferCompositionService $comp): void
    {
        if ($this->editBlockId !== null) {   // 98 · 3.6 PR6: Ablehnung (z. B. Menge) als Hinweis, Formular bleibt offen
            try { $comp->updateBlock($this->team(), $this->editBlockId, $this->blockForm); } catch (\RuntimeException $e) { $this->errorToast($e->getMessage()); return; }
        }
        $this->editBlockId = null;
        $this->nachKomposition();
    }

    public function blockRaus(int $id, OfferCompositionService $comp): void
    {
        $comp->deleteBlock($this->team(), $id);
        $this->nachKomposition();
    }

    /**
     * Eine einzelne Gericht-Zeile der Block-Vorschau inline bearbeiten (manuelles Wording,
     * wenn das KI-Ergebnis nicht passt). Öffnet den Inline-Editor mit dem aktuellen Text.
     */
    public function slotWordingBearbeiten(int $blockId, int $slotId, ?string $aktuell = null): void
    {
        $this->editSlotKey = $blockId . ':' . $slotId;
        $this->editSlotWording = (string) $aktuell;
    }

    /**
     * Den inline bearbeiteten Anzeigenamen speichern — als angebots-lokaler Block-Override
     * (payload_json['wording_overrides'][slotId], oberste Stufe der Wording-Kette). Leer =
     * zurück auf die Kette. Das Concept bleibt unangetastet.
     */
    public function slotWordingSpeichern(OfferCompositionService $comp): void
    {
        if ($this->editSlotKey === null || ! str_contains($this->editSlotKey, ':')) {
            return;
        }
        [$blockId, $slotId] = array_map('intval', explode(':', $this->editSlotKey, 2));
        try {
            $comp->setBlockSlotWording($this->team(), $blockId, $slotId, $this->editSlotWording);
        } catch (\Throwable $e) {
            $this->addError('slotWording', $e->getMessage());

            return;
        }
        $this->editSlotKey = null;
        $this->editSlotWording = '';
        $this->nachKomposition();
    }

    public function slotWordingAbbrechen(): void
    {
        $this->editSlotKey = null;
        $this->editSlotWording = '';
    }

    public function blockSichtbar(int $id, OfferCompositionService $comp): void
    {
        $block = FoodAlchemistOfferBlock::find($id);
        if ($block !== null) {
            $comp->blockSichtbar($this->team(), $id, ! $block->visible);
            $this->nachKomposition();
        }
    }

    public function blockEbene(int $id, int $delta, OfferCompositionService $comp): void
    {
        $comp->blockEbene($this->team(), $id, $delta);
        $this->nachKomposition();
    }

    public function blockHoch(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeBlock($id, -1, $comp);
    }

    public function blockRunter(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeBlock($id, 1, $comp);
    }

    private function verschiebeBlock(int $id, int $richtung, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $ids = FoodAlchemistOfferBlock::where('chapter_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $pos = array_search($id, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $comp->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
        $this->nachKomposition();
    }

    /**
     * Drag & Drop: Block `$id` HINTER Block `$afterId` einsortieren (Insert-after). Der
     * Ziehgriff sitzt in der Block-Zeile; ▲▼ bleibt als zuverlässige Kanten-Alternative.
     */
    public function blockVerschiebenAuf(int $id, int $afterId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null || $id === $afterId) {
            return;
        }
        $ids = FoodAlchemistOfferBlock::where('chapter_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $ids = array_values(array_filter($ids, fn ($x) => $x !== $id));
        $pos = array_search($afterId, $ids, true);
        if ($pos === false) {
            return; // Ziel gehört nicht zum Kapitel — kein blinder Append
        }
        array_splice($ids, $pos + 1, 0, [$id]);
        $comp->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
        $this->nachKomposition();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Wahl-Gruppe (A|B|C zwischen Concepts)
    // ══════════════════════════════════════════════════════════════════════════

    public function markiere(int $id): void
    {
        $this->markiert = in_array($id, $this->markiert, true)
            ? array_values(array_diff($this->markiert, [$id]))
            : [...$this->markiert, $id];
    }

    public function wahlGruppeBilden(OfferCompositionService $comp): void
    {
        if (count($this->markiert) < 2 || $this->selectedKapitelId === null) {
            return;
        }
        try { $comp->setVariantGroup($this->team(), $this->markiert, $comp->nextVariantGroupId($this->team(), $this->selectedKapitelId)); $this->markiert = []; }
        catch (\RuntimeException $e) { $this->errorToast($e->getMessage()); }   // 98 · 3.6 PR6 (B8): Ablehnung als Hinweis
        $this->nachKomposition();
    }

    public function wahlGruppeAufheben(int $id, OfferCompositionService $comp): void
    {
        $comp->setVariantGroup($this->team(), [$id], null);
        $this->nachKomposition();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Branding / CI (pro Angebot)
    // ══════════════════════════════════════════════════════════════════════════

    public function brandingSpeichern(AngebotService $svc): void
    {
        $this->brandingFehler = null;
        $this->brandingGespeichert = false;
        if ($this->selectedId === null) {
            return;
        }
        try {
            $a = $svc->setBranding($this->team(), $this->selectedId, [
                'brand_color' => $this->brandingForm['brand_color'] ?? '#6d28d9',
                'band_color' => $this->brandingForm['band_color'] ?? '',
                'footer_text' => $this->brandingForm['footer_text'] ?? '',
            ]);
            $this->brandingForm = [
                'brand_color' => $a->brand_color ?? '#6d28d9',
                'band_color' => $a->band_color ?? '',
                'footer_text' => $a->footer_text ?? '',
            ];
            $this->brandingGespeichert = true;
        } catch (\RuntimeException $e) {
            // Hex-Murks oder geerbtes Angebot (Owner-Guard) → sauber als UI-Fehler.
            $this->brandingFehler = $e->getMessage();
        }
    }

    // ── Kapitel-Bild (überschreibt das Concept-Titelbild im Kapitel-Band) ──────
    public function updatedKapitelImageUpload(): void
    {
        if ($this->uploadGesperrt('kapitelImageUpload')) {
            return;
        }
        $this->kapitelImageFehler = null;
        if ($this->selectedKapitelId === null || $this->kapitelImageUpload === null) {
            return;
        }
        \Platform\FoodAlchemist\Support\BildUpload::pruefen($this, 'kapitelImageUpload');
        try {
            app(OfferCompositionService::class)->setKapitelImage($this->team(), $this->selectedKapitelId, $this->kapitelImageUpload);
        } catch (\RuntimeException $e) {
            $this->kapitelImageFehler = $e->getMessage();
        }
        $this->reset('kapitelImageUpload');
    }

    public function kapitelImageEntfernen(OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId !== null) {
            $comp->clearKapitelImage($this->team(), $this->selectedKapitelId);
        }
    }

    // ── Kapitel-Galerie (Mehrfach-Upload) ──────────────────────────────────────
    public function updatedKapitelGalleryUpload(): void
    {
        if ($this->uploadGesperrt('kapitelGalleryUpload')) {
            return;
        }
        $this->kapitelImageFehler = null;
        $dateien = array_filter(is_array($this->kapitelGalleryUpload) ? $this->kapitelGalleryUpload : [$this->kapitelGalleryUpload]);
        if ($this->selectedKapitelId === null || $dateien === []) {
            return;
        }
        \Platform\FoodAlchemist\Support\BildUpload::pruefen($this, 'kapitelGalleryUpload.*');
        try {
            foreach ($dateien as $datei) {
                app(OfferCompositionService::class)->addKapitelGalleryImage($this->team(), $this->selectedKapitelId, $datei);
            }
        } catch (\RuntimeException $e) {
            $this->kapitelImageFehler = $e->getMessage();
        }
        $this->reset('kapitelGalleryUpload');
    }

    public function kapitelGalerieBildEntfernen(int $imageId, OfferCompositionService $comp): void
    {
        $comp->removeKapitelGalleryImage($this->team(), $imageId);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Voll-Kaskade + Gerüst-Review (Angebot-Spezifika, erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Voll-Kaskade fürs Angebot — je Frame-Slot ein Konzept, ans Angebot referenziert. Slots kann
     * der Mensch VOR der Kaskade im Gerüst-Tab prüfen/bauen; ohne Gerüst wird EINMAL aus dem
     * Angebots-Kopf (Anlass/Gäste) auto-strukturiert. Springt in die Leitstelle.
     */
    public function vollKaskadeStarten(
        \Platform\FoodAlchemist\Services\Planung\PlanningSessionService $sessions,
        \Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService $cascade,
        \Platform\FoodAlchemist\Services\Concepter\ConceptGeneratorService $gen,
    ) {
        $team = $this->team();
        if ($this->selectedId === null) {
            return null;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($this->selectedId);
        if ($angebot === null) {
            return null;
        }
        try {
            $frame = app(\Platform\FoodAlchemist\Services\Planung\PlanningFrameService::class)->find('offer', (int) $this->selectedId);
            if ($frame === null || $frame->slots()->count() === 0) {
                $gen->geruestAusBriefFuerOwner($team, 'offer', (int) $this->selectedId, $this->angebotBrief($angebot));
            }
            $session = $sessions->create($team, [
                'title' => 'Voll-Kaskade: ' . ($angebot->name ?: ('Angebot #' . $this->selectedId)),
                'created_via' => 'angebot_vollkaskade',
            ]);
            $cascade->starteKaskade($team, 'vollkaskade', $session, 'voll_kreativ', [
                'owner_type' => 'offer', 'owner_id' => (int) $this->selectedId, 'created_via' => 'angebot_vollkaskade',
            ]);

            return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return null;
        }
    }

    /** Minimaler Brief fürs Auto-Gerüst aus den Angebots-Kopf-Feldern (Anlass/Gäste). */
    private function angebotBrief(FoodAlchemistAngebot $a): string
    {
        $teile = [];
        if (trim((string) $a->occasion) !== '') {
            $teile[] = 'Anlass: ' . trim((string) $a->occasion);
        }
        if ((int) $a->personen > 0) {
            $teile[] = 'Gäste: ' . (int) $a->personen . ' Personen';
        }

        return implode(' — ', $teile);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Nested-Livewire-Signale (erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    /** Concepter-Editor hat einen angebots-lokalen Entwurf geändert → Auto-Preis + Detail neu. */
    #[On('concepter-gespeichert')]
    public function nachConcepterEdit(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->recomputeAngebot($this->team(), $this->selectedId);
            $this->ladeForm();
        }
    }

    /** Ebene 2: Betrieb-Wechsel im Sidebar → Angebots-Kalkulation gegen den neuen Betrieb neu rendern. */
    #[On('aktiver-betrieb-geaendert')]
    public function betriebGewechselt(): void
    {
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Render
    // ══════════════════════════════════════════════════════════════════════════

    /** Kapitel-Wording im gewählten Stil als angebots-lokalen Block-Snapshot erzeugen. */
    public function kapitelWordingGenerieren(OfferCompositionService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $this->resetErrorBag('kapitelWording');
        $svc->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
        $stilId = $this->kapitelForm['writing_style_id'] ?? null;
        if ($stilId === null || $stilId === '') {
            $this->addError('kapitelWording', 'Kein Kapitel-Schreibstil gewählt — Standard erbt live aus den Concepten.');

            return;
        }
        try {
            $anzahl = $svc->kapitelWordingRegenerieren($this->team(), $this->selectedKapitelId);
        } catch (\Throwable $e) {
            $this->addError('kapitelWording', $e->getMessage());

            return;
        }
        $this->dispatch('angebot-gespeichert');
        $this->savedToast($anzahl > 0
            ? "{$anzahl} Konzept(e) im Kapitel-Stil neu betextet."
            : 'Keine betextbaren Konzept-Blöcke im Kapitel.');
    }

    public function render(AngebotService $svc)
    {
        $team = $this->team();
        $angebot = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;
        $this->eingefroren = $angebot !== null && app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotEinfrierService::class)->aktive($angebot) !== null;
        if ($this->selectedId !== null && $angebot === null) {
            $this->selectedId = null;
        }

        $comp = app(OfferCompositionService::class);

        // Canvas nur bei Selektions-WECHSEL (re)laden — kein Edit-Verlust je Roundtrip.
        if ($angebot !== null && $this->canvasOwnerId !== $angebot->id) {
            $this->canvasInit('angebot', 'angebot', $angebot->id);
        }

        // Branding-Felder nur bei Selektions-WECHSEL laden.
        if ($angebot !== null && $this->brandingLoadedId !== $angebot->id) {
            $this->brandingForm = [
                'brand_color' => $angebot->brand_color ?? '#6d28d9',
                'band_color' => $angebot->band_color ?? '',
                'footer_text' => $angebot->footer_text ?? '',
            ];
            $this->brandingLoadedId = $angebot->id;
        }

        $this->praesentationFelderLaden($angebot);

        // Gewähltes Kapitel (mit Bild + Galerie) für die Speisen-/Aufbau-Ansicht.
        $kapitel = ($angebot !== null && $this->selectedKapitelId !== null)
            ? $angebot->chapters->firstWhere('id', $this->selectedKapitelId) : null;

        // Live-Menü-Vorschau je concept_ref-Block (aufgelöste gerichtZeilen). Key = Block-ID.
        $blockMenus = [];
        if ($kapitel !== null) {
            $conceptBloecke = $kapitel->blocks->where('type', 'concept_ref')->filter(fn ($b) => $b->concept_id !== null);
            if ($conceptBloecke->isNotEmpty()) {
                $wording = app(\Platform\FoodAlchemist\Services\Concepter\WordingResolver::class);
                $geladen = FoodAlchemistOfferBlock::whereIn('id', $conceptBloecke->pluck('id'))
                    ->with([
                        'concept.slots' => fn ($q) => $q->orderBy('position'),
                        'concept.slots.dish:id,name,sales_wording_standard',
                        'concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache,price_incomplete,ek_per_person_cache,ek_incomplete',
                        'concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
                    ])->get();
                foreach ($geladen as $b) {
                    $blockMenus[$b->id] = $b->concept !== null ? $wording->gerichtZeilen($b->concept, $b) : [];
                }
            }
        }

        // Ebene 2: Angebot wird gegen den aktiven Betrieb gerechnet (Kosten + Ziel-WE-Ampel).
        $outlet = app(\Platform\FoodAlchemist\Services\Plattform\ActiveOutletContext::class)->current($team);
        // Den schweren Kompositionsbaum und seine Preiseinheiten pro Render nur einmal bauen.
        $komposition = $angebot ? $comp->komposition($team, $angebot, $outlet, true) : null;
        $einheiten = $angebot ? $comp->preisEinheiten($team, $angebot, $outlet) : null;
        $kalkulation = $angebot ? $svc->kalkulation($team, $angebot, $outlet, $komposition, $einheiten) : null;
        $zielWareneinsatzPct = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)
            ->zielWareneinsatzPct($team, $outlet);
        $wareneinsatzAmpel = app(\Platform\FoodAlchemist\Services\Preis\MargeService::class)
            ->weAmpel($kalkulation['wareneinsatz_pct'] ?? null, $zielWareneinsatzPct);

        // Extern-sichere Kundensicht aus demselben Baum ableiten (keine zweite DB-/Preisrunde).
        $menue = $komposition !== null ? $comp->kundensicht($komposition) : null;

        // Board: EIN Baum mit Status + Preis je Kapitel (gegen die Betriebsbrille gerechnet).
        $kapitelBoard = $angebot ? app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotKapitelBoard::class)->daten($team, $angebot, $komposition, $zielWareneinsatzPct) : [];

        $praes = $this->praesentationDaten($team, $angebot);

        $facetten = $svc->facetten($team);

        // Kapitel-Bild + Galerie-URLs (Media-Service).
        $media = app(\Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService::class);

        $einfrierSvc = app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotEinfrierService::class);

        return view('foodalchemist::livewire.angebote.editor', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'angebot' => $angebot,
            // Spec 82 §12 B3: eingefrorener Stand und „neuere Fassung verfügbar“
            'einfrierung' => $angebot !== null ? $einfrierSvc->aktive($angebot) : null,
            'einfrierVeraltet' => $angebot !== null ? $einfrierSvc->veraltet($angebot) : [],
            // Komposition (intern) + Kundensicht + Kalkulation
            'komposition' => $komposition,
            'menue' => $menue,
            'kalkulation' => $kalkulation,
            'wareneinsatzAmpel' => $wareneinsatzAmpel,
            'zielWareneinsatzPct' => $zielWareneinsatzPct,
            'statusWerte' => $svc->statusWerte(),
            // Kapitel-Baum + gewähltes Kapitel + Board + Block-Menüs
            'kapitelTree' => array_map(fn (array $k) => [
                'id' => $k['id'], 'title' => $k['title_intern'], 'parent_id' => $k['parent_id'], 'depth' => $k['depth'],
            ], $komposition['kapitel'] ?? []),
            'kapitel' => $kapitel,
            'kapitelBoard' => $kapitelBoard,
            'blockMenus' => $blockMenus,
            // Spec 50 · C-7: die angebots-eigene Preset-Liste — ohne das Staffel-Preset,
            // das nur das Foodbook rechnen kann (OfferCompositionService::headerPresets).
            'headerPresets' => OfferCompositionService::headerPresets(),
            // Kapitel-Bild + Galerie
            'kapitelImageUrl' => ($kapitel !== null && ($kapitel->image_context_file_id || $kapitel->image_path))
                ? $media->url($kapitel->image_context_file_id, $kapitel->image_path)
                : null,
            'kapitelGallery' => $kapitel !== null
                ? $kapitel->images->map(fn ($gi) => ['id' => $gi->id, 'url' => $media->url($gi->context_file_id, $gi->path)])->all()
                : [],
            // Katalog-Picker (concept | paket | format | gericht) — sofort browsebare Liste, Filter optional
            'conceptKandidaten' => $this->selectedKapitelId !== null
                ? $svc->katalogConcepts($team, $this->conceptSuche, 50, $this->conceptFacetten) : collect(),
            'paketKandidaten' => $this->selectedKapitelId !== null
                ? $svc->paketKandidaten($team, $this->paketSuche, 50, $this->paketFacetten) : collect(),
            'gerichtKandidaten' => $this->selectedKapitelId !== null
                ? $svc->gerichtKandidaten($team, $this->gerichtSuche, 50, $this->gerichtHauptgruppe, $this->gerichtDishClass) : collect(),
            // Format wird zum eigenen Kapitel → braucht nur das Angebot, nicht die Kapitel-Auswahl
            'formatKandidaten' => $this->selectedId !== null ? $svc->formatKandidaten($team, $this->formatSuche, 50) : collect(),
            // Concept-/Paket-Picker-Facetten (Concepter-Dimensionen)
            'facetteEventtypen' => $facetten['eventtypen'],
            'facetteServierformen' => $facetten['servierformen'],
            'facetteMomente' => $facetten['momente'],
            'facetteSaisons' => $facetten['saisons'],
            // Gericht-Picker: Klassen-/Untergruppen-Spalte (Modell A)
            'gerichtHauptgruppen' => $this->selectedKapitelId !== null
                ? app(\Platform\FoodAlchemist\Services\Rezept\SalesRecipeService::class)->dishMainGroups($team) : collect(),
            'gerichtUntergruppen' => ($this->selectedKapitelId !== null && $this->gerichtHauptgruppe !== null)
                ? \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam($team)
                    ->where('dish_main_group_id', $this->gerichtHauptgruppe)->orderBy('id')->get(['id', 'label', 'diet_form'])
                : collect(),
            // Branding & Präsentation
            'presentationInfo' => $praes['presentationInfo'],
            'presentationLink' => $praes['presentationLink'],
            'presentationDesignOptionen' => $praes['presentationDesignOptionen'],
            'betriebsLinks' => $praes['betriebsLinks'],
            'betriebsOptionen' => $praes['betriebsOptionen'],
            // CRM-Picker
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            // Gerüst-Review (Slots VOR der Kaskade)
            // Betriebe fürs Status-/Zuordnungs-Bauteil (nur aktive)
            'betriebe' => \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // Schreibstile fürs Kapitel-Tonalitäts-Override (aktive, team-sichtbar)
            'schreibstile' => \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('name')->get(['id', 'name']),
            // Board-Coverage (SOLL/IST) — Angebot hat (noch) kein Gerüst-Coverage-Panel → leer, Blade ist ?? -sicher.
            'boardCoverage' => [],
            // Kontext-Tab: Kunden-Segment + Portfolio-Konflikt — beim Angebot (noch) nicht belegt.
            'segment' => null,
            'portfolioKonflikt' => null,
            // Zuschlagskalkulation (Vollkosten-Wasserfall) fürs GANZE Angebot × Pax (B3-Partial).
            'auftragsKalkulation' => $angebot ? $svc->auftragsKalkulation($team, $angebot, $outlet, $einheiten) : null,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
