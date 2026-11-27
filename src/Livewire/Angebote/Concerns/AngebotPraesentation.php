<?php

namespace Platform\FoodAlchemist\Livewire\Angebote\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationDesignService;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationService;

/**
 * Angebots-Editor · Präsentation (digitales Kundenbuch, TYPE_ANGEBOT): Felder, Veröffentlichen/Zurückziehen, Links je
 * Kalkulationsprofil (98 · 3.3, aus Angebote\Editor). Braucht von der Komponente `$selectedId` und `team()`.
 */
trait AngebotPraesentation
{
    // ── Präsentation (digitales Kundenbuch, TYPE_ANGEBOT) ──────────────────────
    public string $presentationDesign = 'editorial';

    public ?string $presentationGueltigBis = null;

    public bool $presentationPreisAnzeige = true;

    /** Cover zeigt den Angebotsnamen. Aus → nur Logo (größer), z. B. wenn noch kein Kunde feststeht. */
    public bool $presentationTitelZeigen = true;

    /** Republish-Preis-Schutz: AUS = eingefrorene Preise behalten; AN = aktuelle VK ziehen. */
    public bool $presentationPreiseAktualisieren = false;

    public bool $presentationDeklaration = true;

    public ?string $presentationCtaText = null;

    public ?string $presentationCtaLink = null;

    public ?string $presentationSlug = null;

    public ?int $presentationLoadedId = null;

    public ?string $presentationFehler = null;

    public ?string $presentationHinweis = null;

    // publish-per-Betrieb: zusätzlicher Link je Betrieb mit DESSEN Preisen + eigener Freigabe.
    public ?int $outletPublishId = null;

    public ?string $outletPublishGueltigBis = null;

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
            app(PresentationService::class)->publish($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, [
                'design' => $this->presentationDesign,
                'expires_at' => $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'show_title' => $this->presentationTitelZeigen,
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
            app(PresentationService::class)->withdraw($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlichung zurückgezogen — der Link ist jetzt inaktiv (404).';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Einen zweiten Link FÜR einen Betrieb anlegen — dessen Preise + Vorlage, eigene Freigabe. */
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
                'show_title' => $this->presentationTitelZeigen,
                'price_mode' => $this->presentationPreiseAktualisieren ? 'auto' : 'preserve',
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ];
            if (trim((string) $this->outletPublishDesign) !== '') {
                $settings['design'] = $this->outletPublishDesign;
            }
            if (trim((string) $this->outletPublishSlug) !== '') {
                $settings['slug'] = $this->outletPublishSlug;
            }
            app(PresentationService::class)->publishForOutlet($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, $this->outletPublishId, $settings);
            $this->outletPublishId = null;
            $this->outletPublishGueltigBis = null;
            $this->outletPublishDesign = '';
            $this->outletPublishSlug = '';
            $this->presentationHinweis = 'Kalkulationsprofil-Link veröffentlicht — eigener Link mit den Preisen, der Vorlage und dem Namen dieses Kalkulationsprofils.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Einen Betriebs-Link vom Netz nehmen (Standard-Link bleibt unberührt). */
    public function betriebZuruckziehen(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdrawForOutlet($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, $outletId);
            $this->presentationHinweis = 'Kalkulationsprofil-Link zurückgezogen.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Einen zurückgezogenen Betriebs-Link wieder live nehmen (Snapshot/Preise bleiben eingefroren). */
    public function betriebWiederFreigeben(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->republishForOutlet($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, $outletId, $this->presentationGueltigBis);
            $this->presentationHinweis = 'Kalkulationsprofil-Link wieder freigegeben — gleiche URL, eingefrorene Preise bleiben.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Präsentations-Publish-Felder nur bei Selektions-WECHSEL laden (aus render()). */
    private function praesentationFelderLaden(?FoodAlchemistAngebot $angebot): void
    {
        if ($angebot !== null && $this->presentationLoadedId !== $angebot->id) {
            $s = $angebot->presentationSettings();
            $this->presentationDesign = $angebot->presentation_design ?: 'editorial';
            $this->presentationGueltigBis = $angebot->presentation_expires_at?->format('Y-m-d');
            $this->presentationPreisAnzeige = (bool) ($s['price_display'] ?? true);
            $this->presentationTitelZeigen = (bool) ($s['show_title'] ?? true);
            $this->presentationDeklaration = (bool) ($s['declaration'] ?? true);
            $this->presentationCtaText = $s['cta']['text'] ?? null;
            $this->presentationCtaLink = $s['cta']['link'] ?? null;
            $this->presentationSlug = $angebot->presentation_slug;
            $this->presentationLoadedId = $angebot->id;
        }
    }

    /**
     * Präsentations-Status + Kunden-Link + Design-Auswahl fürs Branding-&-Präsentation-Tab (aus render()).
     *
     * @return array{presentationInfo:?array,presentationLink:?string,presentationDesignOptionen:mixed,betriebsLinks:array,betriebsOptionen:array}
     */
    private function praesentationDaten(\Platform\Core\Models\Team $team, ?FoodAlchemistAngebot $angebot): array
    {
        $presentationInfo = null;
        $presentationLink = null;
        $betriebsLinks = [];
        $betriebsOptionen = [];
        if ($angebot !== null) {
            $presentationInfo = [
                'enabled' => (bool) $angebot->presentation_enabled,
                'live' => $angebot->isPresentationLive(),
                'published_at' => $angebot->presentation_published_at?->format('d.m.Y H:i'),
                'expires_at' => $angebot->presentation_expires_at?->format('d.m.Y'),
                // Bug-Runde 2026-09-17 #2: Design nach der Veröffentlichung geändert → Link hinkt hinterher.
                'design_veraltet' => app(PresentationService::class)->designGeaendertSeitPublish($angebot),
            ];
            if ($angebot->presentation_enabled && $angebot->presentationPublicRef()) {
                $presentationLink = url('/p/angebot/' . $angebot->presentationPublicRef());
            }
            $betriebsLinks = app(PresentationService::class)->outletPresentations($team, PresentationService::TYPE_ANGEBOT, $angebot->id);
            $betriebsOptionen = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all();
        }
        $presentationDesignOptionen = app(PresentationDesignService::class)->pickerOptions($team, PresentationService::TYPE_ANGEBOT);

        return compact('presentationInfo', 'presentationLink', 'presentationDesignOptionen', 'betriebsLinks', 'betriebsOptionen');
    }
}
