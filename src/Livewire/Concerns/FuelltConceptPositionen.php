<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Services\Concepter\ConceptService;

/**
 * Concepter-Editor: Positionen anlegen und füllen (aus Concepter/Editor ausgelagert, Spec 85 Größen-Wächter).
 *
 * CQ 1.1c: ConceptService::fillSlot lehnt fremde Ziele, Kreise und einen nicht zum Rezept passenden
 * Typ ab. Hier wird das als Hinweis in `$fehler` gezeigt statt als Fehlerseite; eine neu angelegte,
 * leer gebliebene Position wird wieder entfernt.
 *
 * Erwartet vom Host: `team()`, `$id`, `$fehler`, `portionEinheitId()`, `reloadSlotForm()`.
 */
trait FuelltConceptPositionen
{
    /** fillSlot mit Ablehnung als Hinweis; true = gefüllt. */
    private function positionFuellen(int $slotId, array $in): bool
    {
        try {
            app(ConceptService::class)->fillSlot($this->team(), $slotId, $in);
            $this->fehler = null;

            return true;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return false;
        }
    }

    /** Legt eine gefüllte Position (Paket/Gericht/Basisrezept) an und sortiert sie ggf. hinter $afterId ein. */
    private function neuePosition(string $typ, int $id, ?int $afterId): ?int
    {
        $svc = app(ConceptService::class);
        $slot = $svc->addSlot($this->team(), $this->id, []);
        $in = $typ === 'paket'
            ? ['embedded_concept_id' => $id]   // Kaskade: $id ist ein kind=paket-Concept → einbetten via embedded_concept_id.
            : ['recipe_id' => $id, 'quantity' => 1, 'unit_vocab_id' => $typ === 'basisrezept' ? null : $this->portionEinheitId(),   // 17.11.: Basisrezept = Gramm (Standard), Portion rechnete dort nicht
                'type' => $typ === 'basisrezept' ? 'basisrezept' : 'gericht'];
        if (! $this->positionFuellen($slot->id, $in)) {
            $svc->removeSlot($this->team(), $slot->id);   // keine leere Hülle zurücklassen

            return null;
        }
        if ($afterId !== null) {
            $this->positionNach($svc, $slot->id, $afterId);
        }
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);

        return $slot->id;
    }

    /** Sortiert $slotId direkt hinter $afterId ein (über reorderSlots mit neu gebauter Reihenfolge). */
    private function positionNach(ConceptService $svc, int $slotId, int $afterId): void
    {
        $ids = $svc->detail($this->team(), $this->id)->slots->pluck('id')->map(fn ($x) => (int) $x)->all();
        $ids = array_values(array_filter($ids, fn ($x) => $x !== $slotId));
        $pos = array_search($afterId, $ids, true);
        if ($pos === false) {
            $ids[] = $slotId;
        } else {
            array_splice($ids, $pos + 1, 0, [$slotId]);
        }
        $svc->reorderSlots($this->team(), $this->id, $ids);
    }
}
