<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Services\ConceptService;

/**
 * 98 · 2.4d (U9): Löschen auch im Concepter-Editor-Menü, wie in der Detailspalte. Im Ansichtsmodus über
 * MitBearbeitungssperre::mitKurzsperre (holen, löschen, freigeben; fremde Sperre → Absage).
 * Nur Concepts (inkl. Paket-Konzept), nicht das Alt-Paket (type pakete — eigene Seite).
 * Eigener Trait, damit Concepter\Editor nicht wächst (Größen-Wächter, Spec 85 §4).
 */
trait LoeschtConceptImEditor
{
    public function loeschen(): void
    {
        if ($this->id === null || $this->type !== 'concepts') {
            return;
        }
        $this->mitKurzsperre(function () {
            $id = $this->id;
            try {
                app(ConceptService::class)->delete($this->team(), $id);
            } catch (\RuntimeException $e) {   // z. B. noch in Foodbooks verwendet
                $this->dispatch('fa-saved', message: $e->getMessage(), type: 'error');

                return;
            }
            $this->bearbeitenBeenden();
            $this->dispatch('concepter-geloescht', id: $id);
            $this->dispatch('modal.close', name: 'concepter-editor');
        });
    }

    protected function kurzsperrMethoden(): array
    {
        return ['loeschen'];
    }
}
