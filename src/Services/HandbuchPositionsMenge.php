<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;

/** Mengen-Allowlist fürs Handbuch; dieselbe Portionsumrechnung wie die Konzeptkalkulation. */
class HandbuchPositionsMenge
{
    public function fuer(FoodAlchemistConceptSlot|FoodAlchemistPaketGericht $position): array
    {
        $recipe = $position->dish?->fresh();
        $dar = $position instanceof FoodAlchemistConceptSlot
            ? app(DarreichungResolver::class)->fuerSlot($position)
            : app(DarreichungResolver::class)->fuerPaketGericht($position);
        if ($dar !== null && (int) $dar->recipe_id !== (int) $recipe?->id) {
            $dar = $recipe !== null ? app(DarreichungResolver::class)->standardFuer($recipe) : null;
        }
        $menge = $position->quantity !== null ? (float) $position->quantity : null;
        $unit = $position->unit;

        return [
            'menge_pro_gast' => $menge,
            'einheit' => $unit?->display_de ?: ($unit?->slug ?: 'Teile'),
            // Fehlende Werte sichtbar lassen; nicht die Kalkulations-Vorgabe 1 vortäuschen.
            'portionen_pro_gast' => $menge !== null && $recipe !== null
                ? ConcepterAggregateService::portionsAequivalent($menge, $unit, $recipe, $dar?->quantity_per_unit_g) : null,
            'presentation_id' => $dar?->id,
        ];
    }
}
