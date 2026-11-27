<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;

/**
 * Foodbook-Editor · Branding/CI (Farben, Fußzeile, Logo, Cover) und Kapitel-Bild + Galerie (98 · 3.3, aus Foodbooks\Index).
 * Braucht von der Komponente `$selectedId`, `$selectedKapitelId`, `team()` und `uploadGesperrt()`.
 */
trait FoodbookBrandingBilder
{
    // ── Phase 6: Branding / CI (pro Foodbook) — verdrahtet die FoodbookService-Branding-API ──
    public array $brandingForm = ['brand_color' => '#6d28d9', 'band_color' => '', 'footer_text' => ''];

    public $logoUpload = null;

    public $coverUpload = null;

    public ?int $brandingLoadedId = null;

    public ?string $brandingFehler = null;

    public bool $brandingGespeichert = false;

    public function brandingSpeichern(FoodbookService $svc): void
    {
        $this->brandingFehler = null;
        $this->brandingGespeichert = false;
        if ($this->selectedId === null) {
            return;
        }
        try {
            $fb = $svc->setBranding($this->team(), $this->selectedId, [
                'brand_color' => $this->brandingForm['brand_color'] ?? '#6d28d9',
                'band_color' => $this->brandingForm['band_color'] ?? '',
                'footer_text' => $this->brandingForm['footer_text'] ?? '',
            ]);
            $this->brandingForm = [
                'brand_color' => $fb->brand_color ?? '#6d28d9',
                'band_color' => $fb->band_color ?? '',
                'footer_text' => $fb->footer_text ?? '',
            ];
            $this->brandingGespeichert = true;
        } catch (\RuntimeException $e) {
            // Hex-Murks oder geerbtes Foodbook (Owner-Guard D1) → sauber als UI-Fehler.
            $this->brandingFehler = $e->getMessage();
        }
    }

    public function updatedLogoUpload(): void
    {
        if ($this->uploadGesperrt('logoUpload')) {
            return;
        }
        $this->brandingBildHochladen('logoUpload', 'storeLogo');
    }

    public function updatedCoverUpload(): void
    {
        if ($this->uploadGesperrt('coverUpload')) {
            return;
        }
        $this->brandingBildHochladen('coverUpload', 'storeCover');
    }

    /** Auto-Upload bei Dateiwahl: validieren → Service (räumt Altdatei) → Feld leeren. */
    private function brandingBildHochladen(string $prop, string $serviceMethod): void
    {
        $this->brandingFehler = null;
        if ($this->selectedId === null || $this->{$prop} === null) {
            return;
        }
        \Platform\FoodAlchemist\Support\BildUpload::pruefen($this, $prop);
        try {
            app(FoodbookService::class)->{$serviceMethod}($this->team(), $this->selectedId, $this->{$prop});
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->reset($prop);
    }

    public function brandingLogoEntfernen(FoodbookService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearLogo($this->team(), $this->selectedId);
        }
    }

    public function brandingCoverEntfernen(FoodbookService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearCover($this->team(), $this->selectedId);
        }
    }

    // ── Spec 43 (Bild-Epic): Kapitel-Bild (überschreibt das Concept-Titelbild im Kapitel-Band) ──
    public $kapitelImageUpload = null;

    public ?string $kapitelImageFehler = null;

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
            app(FoodbookService::class)->setKapitelImage($this->team(), $this->selectedKapitelId, $this->kapitelImageUpload);
        } catch (\RuntimeException $e) {
            $this->kapitelImageFehler = $e->getMessage();
        }
        $this->reset('kapitelImageUpload');
    }

    public function kapitelImageEntfernen(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId !== null) {
            $svc->clearKapitelImage($this->team(), $this->selectedKapitelId);
        }
    }

    // ── Spec 43 (Bild-Epic): Kapitel-Galerie (Mehrfach-Upload) ──
    /** @var array<int, \Illuminate\Http\UploadedFile> */
    public $kapitelGalleryUpload = [];

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
                app(FoodbookService::class)->addKapitelGalleryImage($this->team(), $this->selectedKapitelId, $datei);
            }
        } catch (\RuntimeException $e) {
            $this->kapitelImageFehler = $e->getMessage();
        }
        $this->reset('kapitelGalleryUpload');
    }

    public function kapitelGalerieBildEntfernen(int $imageId): void
    {
        app(FoodbookService::class)->removeKapitelGalleryImage($this->team(), $imageId);
    }

    /** Branding-Felder nur bei Selektions-WECHSEL aus dem Foodbook laden (aus render()). */
    private function brandingFelderLaden(?FoodAlchemistFoodbook $fb): void
    {
        if ($fb !== null && $this->brandingLoadedId !== $fb->id) {
            $this->brandingForm = [
                'brand_color' => $fb->brand_color ?? '#6d28d9',
                'band_color' => $fb->band_color ?? '',
                'footer_text' => $fb->footer_text ?? '',
            ];
            $this->brandingLoadedId = $fb->id;
        }
    }
}
