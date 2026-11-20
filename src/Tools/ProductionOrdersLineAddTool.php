<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * 98 · 2.3c: freie Position („Brot beim Bäcker abholen") an einen geplanten Produktionsauftrag hängen — wie
 * „Freie Position anlegen" im Produktions-Editor (ProductionOrderService::addManualLine). Ohne Rezept; nur eigene,
 * noch nicht gestartete Aufträge.
 */
class ProductionOrdersLineAddTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.LINE_ADD';
    }

    public function getDescription(): string
    {
        return 'Legt in einem eigenen, geplanten Produktionsauftrag eine freie Position an (etwas, das kein Rezept ist, z. B. „Brot beim Bäcker abholen“). '
            . 'titel ist Pflicht; optional ansaetze (Default 1), arbeitszeit_min (Personenminuten, zählt in die Posten-Summen) und note. '
            . 'Wer ein Rezept zusätzlich produzieren will, nutzt production_orders.ADD_TARGET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Produktionsauftrag (Status geplant).'],
                'titel' => ['type' => 'string', 'description' => 'Was zu tun ist (max. 255 Zeichen).'],
                'ansaetze' => ['type' => 'number', 'description' => 'Anzahl (größer 0, Default 1).'],
                'arbeitszeit_min' => ['type' => 'integer', 'description' => 'Arbeitszeit in Minuten (ab 0, optional).'],
                'note' => ['type' => 'string', 'description' => 'Notiz (optional).'],
            ],
            'required' => ['order_id', 'titel'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $orderId = (int) ($arguments['order_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistProductionOrder::class, $orderId, 'Produktionsauftrag')) !== null) {
            return $guard;
        }
        if (trim((string) ($arguments['titel'] ?? '')) === '') {
            return ToolResult::error('Freie Position braucht einen Titel.', 'VALIDATION_ERROR');
        }
        if (array_key_exists('ansaetze', $arguments) && (! is_numeric($arguments['ansaetze']) || (float) $arguments['ansaetze'] <= 0)) {
            return ToolResult::error('ansaetze: Zahl größer 0.', 'VALIDATION_ERROR');
        }
        if (isset($arguments['arbeitszeit_min']) && (! is_numeric($arguments['arbeitszeit_min']) || (int) $arguments['arbeitszeit_min'] < 0)) {
            return ToolResult::error('arbeitszeit_min: ganze Minuten ab 0.', 'VALIDATION_ERROR');
        }

        try {
            $zeile = app(ProductionOrderService::class)->addManualLine($team, $orderId,
                array_intersect_key($arguments, array_flip(['titel', 'ansaetze', 'arbeitszeit_min', 'note'])));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        }

        return ToolResult::success([
            'order_id' => $orderId,
            'line' => [
                'id' => (int) $zeile->id, 'titel' => $zeile->titel, 'origin' => $zeile->origin,
                'ansaetze' => (float) $zeile->ansaetze,
                'arbeitszeit_min' => $zeile->arbeitszeit_min !== null ? (int) $zeile->arbeitszeit_min : null,
                'note' => $zeile->note, 'plan_date' => $zeile->plan_date?->toDateString(),
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'zeile', 'freie_position', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.production_orders.LINE_REMOVE', 'foodalchemist.production_orders.GET'],
            'examples' => ['Hänge an Produktionsauftrag 7 die freie Position „Brot beim Bäcker abholen“ mit 15 Minuten.'],
        ];
    }
}
