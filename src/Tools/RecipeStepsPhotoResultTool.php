<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Rezept\RecipeStepService;
use Platform\FoodAlchemist\Tools\Concerns\RezeptFotoZugriff;

/**
 * 98 · 2.3c: Endprodukt-Bild („so soll es fertig aussehen", max. 1 je Rezept) setzen oder aufheben
 * (RecipeStepService::endproduktSetzen). Anders als der Umschalter in der Oberfläche idempotent: erneutes Setzen hebt nicht auf.
 */
class RecipeStepsPhotoResultTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use RezeptFotoZugriff;

    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.PHOTO_RESULT';
    }

    public function getDescription(): string
    {
        return 'Markiert ein Foto aus dem Pool eines team-eigenen Rezepts (nur Status stub/draft) als Endprodukt-Bild („so soll es fertig '
            . 'aussehen“, höchstens eines je Rezept; ein bisheriges verliert die Markierung). Ohne photo_id (oder null) wird die Markierung aufgehoben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'photo_id' => ['type' => 'integer', 'description' => 'Foto aus dem Pool dieses Rezepts; weglassen = Markierung aufheben.'],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $recipe = $this->fotoRezept($team, (int) ($arguments['recipe_id'] ?? 0));
        if ($recipe instanceof ToolResult) {
            return $recipe;
        }
        $svc = app(RecipeStepService::class);
        if (empty($arguments['photo_id'])) {
            $svc->endproduktSetzen($recipe, null);

            return ToolResult::success(['recipe_id' => (int) $recipe->id, 'result_photo' => null]);
        }
        $foto = $this->poolFoto($recipe, (int) $arguments['photo_id']);
        if ($foto === null) {
            return ToolResult::error('Foto gehört nicht zu diesem Rezept.', 'NOT_FOUND');
        }
        if (! $foto->is_result) {
            $svc->endproduktSetzen($recipe, (int) $foto->id);   // der Service schaltet um — nur aufrufen, wenn noch nicht markiert
        }

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'result_photo' => $this->fotoArr($foto->refresh())]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'steps', 'foto', 'endprodukt', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_steps.GET', 'foodalchemist.recipe_steps.PHOTO_UPLOAD'],
            'examples' => ['Markiere Foto 12 als Endprodukt-Bild von Rezept 4711.'],
        ];
    }
}
