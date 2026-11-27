<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\GpStamm\VocabularyService;

/**
 * Phase B: Block in einem Kapitel anlegen — das inhaltliche Atom des Foodbooks.
 * Ein Kapitel trägt Konzepte (Paket, €/Gast) UND direkte Einzel-Gerichte (€/Position):
 * Paket-Block via concept_id (type=concept_ref) · Einzel-Gericht via recipe_id
 * (type=recipe_ref, Spec 19 Entscheidung 5) · dazu Text/Header/Spacer.
 * Nur solange das Foodbook draft ist. Optional mit Preis-Staffel.
 */
class FoodbookBlocksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Block in einem Kapitel eines draft-Foodbooks an (Position ans Ende). '
            . 'Ein Kapitel trägt Paket-Blöcke UND direkte Einzel-Gerichte: '
            . 'concept_ref (concept_id = Konzept/Paket, €/Gast, via foodalchemist.concepts.SEARCH) '
            . 'oder recipe_ref (recipe_id = echtes VK-Gericht, €/Position, via foodalchemist.verkaufsrezepte.SEARCH). '
            . 'Weitere Typen: text (freier Text/Notiz) | header_neutral | header_frei | header_frei_preis | spacer. '
            . 'price_basis: pro_person (€/Gast, ×Pax) | pro_stueck (€/Position, flach) | pauschal (flach). '
            . 'Optional staffel: [{min_persons, price}] für Pax-abhängige Preise.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'enum' => ['text', 'concept_ref', 'recipe_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer'], 'default' => 'text'],
                'label' => ['type' => 'string', 'description' => 'Interner Titel des Blocks'],
                'customer_text' => ['type' => 'string', 'description' => 'Kundenseitiger Angebotstext'],
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept/Paket bei type=concept_ref'],
                'recipe_id' => ['type' => 'integer', 'description' => 'Echtes VK-Gericht bei type=recipe_ref (verkauf()-Scope, keine Slot-Variante)'],
                'sales_recipe_id' => \Platform\FoodAlchemist\Support\RezeptIdAlias::schema(),
                'quantity' => ['type' => 'number'],
                'unit' => ['type' => 'string', 'description' => 'Einheiten-Slug, z. B. stk, portion'],
                'price_value' => ['type' => 'number'],
                'price_basis' => ['type' => 'string', 'enum' => ['pro_person', 'pro_stueck', 'pauschal'], 'description' => 'Basis für price_value'],
                'visible' => ['type' => 'boolean', 'default' => true],
                'interne_bemerkung' => ['type' => 'string'],
                'staffel' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'min_persons' => ['type' => 'integer', 'minimum' => 1],
                            'price' => ['type' => 'number'],
                        ],
                        'required' => ['min_persons', 'price'],
                    ],
                ],
            ],
            'required' => ['chapter_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $arguments = \Platform\FoodAlchemist\Support\RezeptIdAlias::eingabe($arguments);   // 98 · 3.5: alter Name sales_recipe_id als Alias bis 4 Wochen nach Deploy
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kapitel = FoodAlchemistFoodbookKapitel::whereKey((int) $arguments['chapter_id'])->first();
        $fb = $kapitel !== null
            ? FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($kapitel->foodbook_id)->first()
            : null;
        if ($fb === null) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        // Spec 33 P0: Status ist gecastet und heisst jetzt `entwurf` — der alte Vergleich
        // `(string) …->status !== 'draft'` warf am Enum UND traf das falsche Vokabular.
        if ($fb->statusWert() !== AusgabeStatus::Entwurf) {
            return ToolResult::error("Foodbook hat Status \"{$fb->statusWert()->label()}\" — via MCP ist nur ein Entwurf editierbar.", 'ACCESS_DENIED');
        }
        // 98 · 3.6: Konzept/Gericht (Sicht, keine Slot-Variante → NOT_FOUND) und Preisbasis (Aliasse pro_person/pro_stueck)
        // prüft der Positions-Kern im Dienst — keine zweite Prüfung hier.
        $daten = array_intersect_key($arguments, array_flip([
            'type', 'label', 'customer_text', 'interne_bemerkung',
            'concept_id', 'recipe_id', 'quantity', 'price_value', 'price_basis', 'visible', 'presentation_id',   // presentation_id: Dienst lehnt ab (B7)
        ]));
        if (($arguments['unit'] ?? '') !== '') {
            $unit = app(VocabularyService::class)->findEinheit($team, (string) $arguments['unit']);
            if ($unit === null) {
                return ToolResult::error('Unbekannte Einheit "' . $arguments['unit'] . '".', 'VALIDATION_ERROR');
            }
            $daten['unit_vocab_id'] = $unit->id;
        }
        $svc = app(FoodbookService::class);

        try {
            $block = $svc->addBlock($team, $kapitel->id, $daten);
            if (! empty($arguments['staffel'])) {
                $svc->setStaffel($team, $block->id, $arguments['staffel']);
            }
        } catch (\RuntimeException $e) {   // 98 · 3.6: Prüfung im Positions-Kern, Code aus dem PositionFehler
            return ToolResult::error($e->getMessage(), $e instanceof \Platform\FoodAlchemist\Services\Position\PositionFehler ? $e->fehlerCode : 'VALIDATION_ERROR');
        }

        return ToolResult::success(['block' => [
            'id' => $block->id, 'type' => $block->type, 'position' => $block->position,
            'label' => $block->label, ...\Platform\FoodAlchemist\Support\RezeptIdAlias::beide($block->recipe_id),
            'staffel_zeilen' => count($arguments['staffel'] ?? []),
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'block', 'gericht', 'staffel', 'anlegen', 'draft'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.foodbook_kapitel.POST', 'foodalchemist.recipes.POST'],
            'examples' => ['Füge Kapitel 34 das Verkaufsrezept 812 als Gericht mit Staffelpreis hinzu'],
        ];
    }
}
