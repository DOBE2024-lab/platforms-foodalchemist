<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Plattform\ActiveOutletContext;
use Platform\FoodAlchemist\Services\OrderCostingService;

/**
 * 98 · 2.3c: Concept-Kalkulation mit Gästezahl (read-only) — dieselbe Auftragsvorschau wie im Concepter-Tab
 * „Kalkulation" (OrderCostingService::costConcept): Bedarfsexplosion für pax Gäste, MEK/FEK/Zuschläge bis HK2.
 * Das Concept ist ein Verweisziel (Sicht genügt, Master/Abo erlaubt); gerechnet wird im aktiven Betrieb
 * (ActiveOutletContext, sonst Team-Baseline). Verändert weder Concept noch Katalogpreis.
 */
class ConceptsKalkulationTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.KALKULATION';
    }

    public function getDescription(): string
    {
        return 'Rechnet ein sichtbares Concept für eine Gästezahl durch (read-only, wie die Auftragsvorschau im Concepter-Tab Kalkulation): '
            . 'Produktionsblatt-Explosion für pax Personen, Wareneinsatz (MEK), Lohn (FEK, getrennt nach Produktion/Regenerieren/Anrichten), '
            . 'Zuschläge bis HK2 (Vollkosten), Katalogpreis je Person/gesamt, Postenlast, Zeitaufschlüsselung und Warnungen. '
            . 'Gerechnet wird im aktiven Betrieb (outlets.SET_ACTIVE), sonst gegen die Team-Baseline. Ändert nichts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Concept-Id (sichtbar, auch Master/Abo).'],
                'pax' => ['type' => 'integer', 'description' => 'Gästezahl (ab 1).'],
            ],
            'required' => ['concept_id', 'pax'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $pax = $arguments['pax'] ?? null;
        if (! is_numeric($pax) || (int) $pax < 1 || (float) $pax !== (float) (int) $pax) {
            return ToolResult::error('pax: ganze Zahl ab 1 (Gästezahl).', 'VALIDATION_ERROR');
        }
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereKey((int) ($arguments['concept_id'] ?? 0))->first();
        if ($concept === null) {
            return ToolResult::error('Concept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $outlet = app(ActiveOutletContext::class)->current($team, $context->user?->id !== null ? (int) $context->user->id : null);

        try {
            $kalkulation = app(OrderCostingService::class)->costConcept($team, $concept, (int) $pax, $outlet);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'concept' => ['id' => (int) $concept->id, 'name' => $concept->name],
            'pax' => (int) $pax,
            'outlet_id' => $outlet?->id !== null ? (int) $outlet->id : null,
            'kalkulation' => $kalkulation,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'concept', 'kalkulation', 'pax', 'vollkosten', 'hk2', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.kalkulation.GET', 'foodalchemist.concepts.GET', 'foodalchemist.outlets.SET_ACTIVE'],
            'examples' => ['Was kostet Concept 12 für 80 Gäste?'],
        ];
    }
}
