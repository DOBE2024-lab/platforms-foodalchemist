<?php

namespace Platform\FoodAlchemist\Livewire\Produktion\Concerns;

use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Services\Produktion\ProductionOrderService;

/**
 * Zeile abhaken/starten — am Wandmonitor startet das zugleich einen noch geplanten Auftrag (und liefert die Zeile im
 * gestarteten Auftrag zurück). Gemeinsam für Tagesplan (Editor/Wall) und Tagesplan-Anleitung (98 · 3.3 S6, aus
 * Produktion\Tagesplan verschoben; vorher las die Methode `$this->display`, jetzt kommt der Wandmodus als Parameter).
 */
trait SetztZeileMitWallStart
{
    private function zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine $zeile, ProductionLineStatus $ziel, ProductionOrderService $svc, bool $wall): int
    {
        $lineId = (int) $zeile->id;
        if ($wall && in_array($ziel, [ProductionLineStatus::InProgress, ProductionLineStatus::Done], true)) {
            $order = $zeile->productionOrder;
            $status = $order?->status instanceof ProductionOrderStatus
                ? $order->status
                : ($order?->status !== null ? ProductionOrderStatus::from((string) $order->status) : null);

            if ($order !== null && $status === ProductionOrderStatus::Planned) {
                $svc->setStatus($team, (int) $order->id, ProductionOrderStatus::InProgress);

                $neu = FoodAlchemistProductionOrderLine::query()
                    ->where('production_order_id', $order->id)
                    ->when($zeile->recipe_id !== null, fn ($q) => $q->where('recipe_id', $zeile->recipe_id))
                    ->when($zeile->recipe_id === null, fn ($q) => $q->whereKey($lineId))
                    ->orderBy('position')
                    ->first();

                $lineId = (int) ($neu?->id ?? $lineId);
            }
        }

        $svc->setLineStatus($team, $lineId, $ziel);

        return $lineId;
    }
}
