<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * 98 · 2.3c: freie Position aus einem geplanten Produktionsauftrag entfernen — wie im Produktions-Editor
 * (ProductionOrderService::removeManualLine). Berechnete Zeilen werden gestrichen, nicht gelöscht. Confirm=true Pflicht.
 */
class ProductionOrdersLineRemoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.LINE_REMOVE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine freie Position (origin manual) aus einem eigenen, geplanten Produktionsauftrag. Erfordert confirm=true. '
            . 'Berechnete Zeilen lassen sich nicht löschen — dafür production_orders.LINE_OVERRIDE (is_struck).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer', 'description' => 'Id der freien Position.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['line_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $lineId = (int) ($arguments['line_id'] ?? 0);
        $orderId = FoodAlchemistProductionOrderLine::whereKey($lineId)->value('production_order_id');
        if ($orderId === null) {
            return ToolResult::error('Produktionszeile nicht vorhanden.', 'NOT_FOUND');
        }
        if (($guard = $this->guardOwned($team, FoodAlchemistProductionOrder::class, (int) $orderId, 'Produktionsauftrag')) !== null) {
            return $guard;
        }

        try {
            app(ProductionOrderService::class)->removeManualLine($team, $lineId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        }

        return ToolResult::success(['line_id' => $lineId, 'order_id' => (int) $orderId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'zeile', 'freie_position', 'delete'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.production_orders.LINE_ADD', 'foodalchemist.production_orders.LINE_OVERRIDE'],
            'examples' => ['Entferne die freie Position 31 (confirm=true).'],
        ];
    }
}
