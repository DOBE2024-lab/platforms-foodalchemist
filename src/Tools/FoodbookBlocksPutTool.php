<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;

/** MCP-Steuerbarkeit · D7: einen Foodbook-Block bearbeiten (Label/Text/Menge/Preis/Sichtbarkeit). */
class FoodbookBlocksPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = ['label', 'customer_text', 'interne_bemerkung', 'sales_recipe_id', 'quantity', 'price_value', 'price_basis', 'visible', 'level', 'presentation_id'];   // presentation_id: Dienst lehnt ab (B7)

    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen Block eines team-eigenen Entwurf-Foodbooks (felder: label, customer_text, quantity, '
            . 'price_value, price_basis, visible, level, sales_recipe_id). Struktur/Preis-Wahrheit bleibt im Service.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer', 'description' => 'Block-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['block_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(self::FELDER));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }
        $blockId = (int) ($arguments['block_id'] ?? 0);
        if (($guard = $this->guardFoodbookEditable($team, $this->foodbookVonBlock($team, $blockId))) !== null) {
            return $guard;
        }
        // recipe_ref: nur sichtbares, echtes VK-Gericht (keine Slot-Variante) — prüft der Dienst (BlockReferenz, 98 · 3.6 PR5), Code NOT_FOUND

        try {
            app(FoodbookService::class)->updateBlock($team, $blockId, $in);
        } catch (\RuntimeException $e) {   // 98 · 3.6: Prüfung im Positions-Kern, Code aus dem PositionFehler
            return ToolResult::error($e->getMessage(), $e instanceof \Platform\FoodAlchemist\Services\Position\PositionFehler ? $e->fehlerCode : 'VALIDATION_ERROR');
        }

        return ToolResult::success(['block_id' => $blockId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'block', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbook_blocks.REORDER', 'foodalchemist.foodbook_blocks.DELETE'],
            'examples' => ['Ändere bei Block 30 den Kundentext.'],
        ];
    }
}
