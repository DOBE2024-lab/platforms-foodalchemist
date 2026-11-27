<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationDesignService;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationService;

/**
 * Foodbook-Editor · Präsentation (digitales Kundenbuch, Spec 43): Felder, Veröffentlichen/Zurückziehen, Links je
 * Kalkulationsprofil (98 · 3.3, aus Foodbooks\Index). Braucht von der Komponente `$selectedId` und `team()`.
 */
trait FoodbookPraesentation
{
    // ── Spec 43: Präsentation (digitales Kundenbuch) — Publish-Steuerung im Branding-Tab ──
    public string $presentationDesign = 'editorial';

    public ?string $presentationGueltigBis = null;   // Pflicht-Datum beim Veröffentlichen

    public bool $presentationPreisAnzeige = true;

    /** Republish-Preis-Schutz (Ebene 2): AUS = eingefrorene Preise behalten (neue Speisen live);
     *  AN = aktuelle VK ziehen. Greift nur beim erneuten Veröffentlichen; Erstpublish immer live. */
    public bool $presentationPreiseAktualisieren = false;

    public bool $presentationDeklaration = true;

    public ?string $presentationCtaText = null;

    public ?string $presentationCtaLink = null;

    public ?string $presentationSlug = null;   // Spec 43: optionaler eigener Link-Name (statt Zufalls-Token)

    public ?int $presentationLoadedId = null;

    public ?string $presentationFehler = null;

    public ?string $presentationHinweis = null;

    // Slice F: publish-per-Betrieb — zusätzlicher Link je Betrieb mit DESSEN Preisen + eigener Freigabe.
    public ?int $outletPublishId = null;

    public ?string $outletPublishGueltigBis = null;

    // Pro Betriebs-Link eigene Vorlage + eigener Link-Name (leer = Betriebs-Default bzw. Zufalls-Token).
    public ?string $outletPublishDesign = '';

    public ?string $outletPublishSlug = '';

    public function veroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->publish($this->team(), 'foodbook', $this->selectedId, [
                'design' => $this->presentationDesign,
                'expires_at' => $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'price_mode' => $this->presentationPreiseAktualisieren ? 'auto' : 'preserve',
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
                'slug' => $this->presentationSlug,
            ]);
            $this->presentationLoadedId = null; // erzwingt Neuladen des Status im render()
            $this->presentationHinweis = 'Veröffentlicht — der Kundenlink ist aktiv.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    public function zuruckziehen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdraw($this->team(), 'foodbook', $this->selectedId);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlichung zurückgezogen — der Link ist jetzt inaktiv (404).';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen zweiten Link FÜR einen Betrieb anlegen — dessen Preise + Vorlage, eigene Freigabe. */
    public function betriebVeroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        if ($this->outletPublishId === null) {
            $this->presentationFehler = 'Bitte zuerst ein Kalkulationsprofil wählen.';

            return;
        }
        try {
            $settings = [
                'expires_at' => $this->outletPublishGueltigBis ?: $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'price_mode' => $this->presentationPreiseAktualisieren ? 'auto' : 'preserve',
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ];
            // Nur setzen, wenn der Nutzer aktiv wählt — sonst greift die Fallback-Kette
            // (Betriebs-Vorlage → Dokument-Vorlage) bzw. der Zufalls-Token.
            if (trim((string) $this->outletPublishDesign) !== '') {
                $settings['design'] = $this->outletPublishDesign;
            }
            if (trim((string) $this->outletPublishSlug) !== '') {
                $settings['slug'] = $this->outletPublishSlug;
            }
            app(PresentationService::class)->publishForOutlet($this->team(), 'foodbook', $this->selectedId, $this->outletPublishId, $settings);
            $this->outletPublishId = null;
            $this->outletPublishGueltigBis = null;
            $this->outletPublishDesign = '';
            $this->outletPublishSlug = '';
            $this->presentationHinweis = 'Kalkulationsprofil-Link veröffentlicht — eigener Link mit den Preisen, der Vorlage und dem Namen dieses Kalkulationsprofils.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen Betriebs-Link vom Netz nehmen (Standard-Link bleibt unberührt). */
    public function betriebZuruckziehen(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdrawForOutlet($this->team(), 'foodbook', $this->selectedId, $outletId);
            $this->presentationHinweis = 'Kalkulationsprofil-Link zurückgezogen.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen zurückgezogenen Betriebs-Link wieder live nehmen (Snapshot/Preise bleiben eingefroren). */
    public function betriebWiederFreigeben(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->republishForOutlet($this->team(), 'foodbook', $this->selectedId, $outletId, $this->presentationGueltigBis);
            $this->presentationHinweis = 'Kalkulationsprofil-Link wieder freigegeben — gleiche URL, eingefrorene Preise bleiben.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Präsentations-Publish-Felder nur bei Selektions-WECHSEL laden (aus render()). */
    private function praesentationFelderLaden(?FoodAlchemistFoodbook $fb): void
    {
        if ($fb !== null && $this->presentationLoadedId !== $fb->id) {
            $s = $fb->presentationSettings();
            $this->presentationDesign = $fb->presentation_design ?: 'editorial';
            $this->presentationGueltigBis = $fb->presentation_expires_at?->format('Y-m-d');
            $this->presentationPreisAnzeige = (bool) ($s['price_display'] ?? true);
            $this->presentationDeklaration = (bool) ($s['declaration'] ?? true);
            $this->presentationCtaText = $s['cta']['text'] ?? null;
            $this->presentationCtaLink = $s['cta']['link'] ?? null;
            $this->presentationSlug = $fb->presentation_slug;
            $this->presentationLoadedId = $fb->id;
        }
    }

    /**
     * Präsentations-Status, Kunden-Link, Design-Auswahl und Betriebs-Links fürs Branding-&-Präsentation-Tab (aus render()).
     *
     * @return array{presentationInfo:?array,presentationLink:?string,presentationDesignOptionen:mixed,betriebsLinks:array,betriebsOptionen:array}
     */
    private function praesentationDaten(\Platform\Core\Models\Team $team, ?FoodAlchemistFoodbook $fb): array
    {
        // Spec 43: Präsentations-Status + Kunden-Link + Design-Auswahl fürs Branding-&-Präsentation-Tab.
        $presentationInfo = null;
        $presentationLink = null;
        if ($fb !== null) {
            $presentationInfo = [
                'enabled' => (bool) $fb->presentation_enabled,
                'live' => $fb->isPresentationLive(),
                'published_at' => $fb->presentation_published_at?->format('d.m.Y H:i'),
                'expires_at' => $fb->presentation_expires_at?->format('d.m.Y'),
                // Bug-Runde 2026-09-17 #2: Design nach der Veröffentlichung geändert → Link hinkt hinterher.
                'design_veraltet' => app(PresentationService::class)->designGeaendertSeitPublish($fb),
            ];
            if ($fb->presentation_enabled && $fb->presentationPublicRef()) {
                $presentationLink = url('/p/foodbook/' . $fb->presentationPublicRef());
            }
        }
        $presentationDesignOptionen = app(PresentationDesignService::class)->pickerOptions($team, 'foodbook');

        // Slice F: bestehende Betriebs-Links + wählbare Betriebe (aktiv, team-scoped).
        $betriebsLinks = [];
        $betriebsOptionen = [];
        if ($fb !== null) {
            $betriebsLinks = app(PresentationService::class)->outletPresentations($team, 'foodbook', $fb->id);
            $betriebsOptionen = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all();
        }

        return compact('presentationInfo', 'presentationLink', 'presentationDesignOptionen', 'betriebsLinks', 'betriebsOptionen');
    }
}
