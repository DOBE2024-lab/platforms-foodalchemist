<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Gericht\GerichtGpUmbauService;

/**
 * Umbau-Weg für den Reife-Befund `gp_im_gericht` (Entscheid Dominique 17.11.2026: ein Gericht besteht nur aus
 * Basisrezepten). Legt aus EINER GP-Zeile eines Gerichts ein Basisrezept (Entwurf) mit genau dieser Zutat und Menge an
 * und hängt die Zeile darauf um. Ohne `name` gilt der Vorschlag (Regelwerk Basisrezepte §1.2/§17).
 */
class RecipeGpZuBasisrezeptPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_gp_zu_basisrezept.POST';
    }

    public function getDescription(): string
    {
        return 'Baut eine Zeile „GP direkt im Gericht" (Reife-Befund gp_im_gericht, Altbestand) um: legt ein Basisrezept (Entwurf) '
            . 'mit genau diesem Grundprodukt und dieser Menge an und hängt die Gericht-Zeile darauf um (referenced_recipe_id statt gp_id). '
            . 'Ein Gericht enthält nur Basisrezepte. Ohne name gilt der Vorschlag „Typ: Bezeichnung (Zukauf)" (Typ aus der Warengruppe, '
            . 'Regelwerk Basisrezepte §17); ist kein Typ ableitbar, ist „Topping: …" nur ein Vorschlag — dann name bewusst setzen '
            . '(z. B. Garnitur-Basisrezept). Gibt es ein eigenes Basisrezept mit genau diesem Namen, wird es verknüpft statt doppelt angelegt. '
            . 'Nur für stub/draft-Gerichte des eigenen Teams.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ingredient_id' => ['type' => 'integer', 'description' => 'Zeile des Gerichts mit gp_id (aus recipes.GET / reife.GET).'],
                'name' => ['type' => 'string', 'description' => 'Optional: Name des neuen Basisrezepts (Typ-Präfix nach §1.2).'],
            ],
            'required' => ['ingredient_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $zeile = FoodAlchemistRecipeIngredient::query()->find((int) ($arguments['ingredient_id'] ?? 0));
        $gericht = $zeile !== null ? FoodAlchemistRecipe::visibleToTeam($team)->find((int) $zeile->recipe_id) : null;
        if ($gericht === null) {
            return ToolResult::error('Zutaten-Zeile nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (($sperre = $this->kiEditGesperrt($gericht)) !== null) {
            return ToolResult::error($sperre, 'ACCESS_DENIED');
        }

        try {
            $r = app(GerichtGpUmbauService::class)->basisrezeptAnlegen($team, (int) $zeile->id,
                isset($arguments['name']) ? (string) $arguments['name'] : null);
        } catch (NichtGefundenException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        } catch (GeerbtNurLesenException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($this->mitReife([
            'basisrezept' => ['id' => $r['recipe_id'], 'name' => $r['name'], 'neu' => $r['neu'], 'status' => $r['neu'] ? 'draft' : null],
            'gericht_id' => $r['gericht_id'], 'ingredient_id' => $r['ingredient_id'],
        ], $team, $r['gericht_id'], true));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'verkaufsrezept', 'basisrezept', 'zutaten', 'reife'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates', 'updates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.reife.GET', 'foodalchemist.recipe_ingredients.PUT'],
            'examples' => ['Baue die GP-Zeile 812 im Gericht zu einem Basisrezept um'],
        ];
    }
}
