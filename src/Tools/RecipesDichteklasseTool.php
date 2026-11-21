<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Services\BehaelterRechner;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * 98 · 2.3c: Dichteklasse eines Rezepts von Hand setzen bzw. leeren — wie im Rezept-Modal (Regeneration & Behälter),
 * über RecipeService::setzeDichteklasse (Eigentümer-Riegel, `dichteklasse_source` = manual). Die `*_source`-Felder
 * bleiben für MCP nur lesbar; dieses Tool schreibt ausschließlich die Klasse.
 */
class RecipesDichteklasseTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.DICHTEKLASSE';
    }

    public function getDescription(): string
    {
        return 'Setzt die Dichteklasse eines team-eigenen Rezepts (Auffangnetz der Behälter-Rechnung, greift nur, wo keine Menge je Behälter steht): '
            . implode(' | ', array_map(fn ($k, $v) => "{$k} ({$v} kg/l)", array_keys(BehaelterRechner::DICHTE), BehaelterRechner::DICHTE))
            . '. Leer ("" oder null) entfernt sie. Die Herkunft wird dabei auf manual gesetzt; geerbte Rezepte sind nur lesbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (team-eigen).'],
                'dichteklasse' => ['type' => 'string', 'enum' => [...array_keys(BehaelterRechner::DICHTE), ''],
                    'description' => 'Dichteklasse; "" (oder null) = entfernen.'],
            ],
            'required' => ['recipe_id', 'dichteklasse'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! array_key_exists('dichteklasse', $arguments)) {
            return ToolResult::error('dichteklasse angeben ("" oder null entfernt sie).', 'VALIDATION_ERROR');
        }
        $klasse = $arguments['dichteklasse'];
        if ($klasse !== null && $klasse !== '' && ! array_key_exists((string) $klasse, BehaelterRechner::DICHTE)) {
            return ToolResult::error("dichteklasse '" . (is_scalar($klasse) ? $klasse : json_encode($klasse)) . "' unbekannt – erlaubt: "
                . implode(', ', array_keys(BehaelterRechner::DICHTE)) . '.', 'VALIDATION_ERROR');
        }
        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        try {
            $recipe = app(RecipeService::class)->setzeDichteklasse($team, $recipeId, $klasse === null ? null : (string) $klasse);
        } catch (GeerbtNurLesenException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe_id' => (int) $recipe->id,
            'dichteklasse' => $recipe->dichteklasse,
            'dichteklasse_source' => $recipe->dichteklasse_source,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'dichteklasse', 'behaelter', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_container.PUT', 'foodalchemist.recipe_regeneration.PUT'],
            'examples' => ['Setze bei Rezept 4711 die Dichteklasse auf fluessig.'],
        ];
    }
}
