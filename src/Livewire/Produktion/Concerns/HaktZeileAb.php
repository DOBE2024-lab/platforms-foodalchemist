<?php

namespace Platform\FoodAlchemist\Livewire\Produktion\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Eine Zeile abhaken (Tagesplan-Editor und Küchenmonitor, 98 · 3.3 S6 aus Produktion\Tagesplan verschoben). Die
 * Komponente sagt mit imWandmodus(), ob ein geplanter Auftrag dabei gestartet wird; Fehler landen in `$this->fehler`.
 */
trait HaktZeileAb
{
    use SetztZeileMitWallStart;

    abstract protected function imWandmodus(): bool;

    /**
     * Spec 30 E6 — Zeile abhaken. Der Tagesplan IST die Küchen-Sicht: nach Posten gefiltert
     * arbeitet man hier die Positionen des Tages ab. Ein zweiter Klick nimmt den Haken zurück
     * (Checkliste, kein Beleg-Lebenszyklus).
     */
    public function abhaken(int $lineId, ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $zeile = FoodAlchemistProductionOrderLine::findOrFail($lineId);
            $ziel = $zeile->line_status === ProductionLineStatus::Done
                ? ProductionLineStatus::Open
                : ProductionLineStatus::Done;

            $this->zeileSetzenMitWallStart($team, $zeile, $ziel, $svc, $this->imWandmodus());
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }
}
