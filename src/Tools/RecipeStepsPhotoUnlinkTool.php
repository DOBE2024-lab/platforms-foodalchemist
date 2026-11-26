<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Rezept\RecipeStepService;
use Platform\FoodAlchemist\Tools\Concerns\RezeptFotoZugriff;

/** 98 · 2.3c: Foto von einem Schritt abhängen (RecipeStepService::fotoEntkoppeln) — das Foto bleibt im Pool des Rezepts. */
class RecipeStepsPhotoUnlinkTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use RezeptFotoZugriff;

    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.PHOTO_UNLINK';
    }

    public function getDescription(): string
    {
        return 'Hängt ein Foto von einem Schritt eines team-eigenen Rezepts ab (nur Status stub/draft). Das Foto bleibt im Foto-Pool '
            . 'und an anderen Schritten; endgültig löschen: recipe_steps.PHOTO_DELETE.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'step_id' => ['type' => 'integer', 'description' => 'Schritt dieses Rezepts.'],
                'photo_id' => ['type' => 'integer', 'description' => 'Foto aus dem Pool dieses Rezepts.'],
            ],
            'required' => ['recipe_id', 'step_id', 'photo_id'],
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
        $schritt = $this->fotoSchritt($recipe, (int) ($arguments['step_id'] ?? 0));
        $foto = $this->poolFoto($recipe, (int) ($arguments['photo_id'] ?? 0));
        if ($schritt === null || $foto === null) {
            return ToolResult::error('Schritt oder Foto gehört nicht zu diesem Rezept.', 'NOT_FOUND');
        }

        app(RecipeStepService::class)->fotoEntkoppeln($recipe, (string) $schritt->ebene, (int) $schritt->id, (int) $foto->id);

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'step_id' => (int) $schritt->id, 'photo' => $this->fotoArr($foto)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'steps', 'foto', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_steps.PHOTO_LINK', 'foodalchemist.recipe_steps.PHOTO_DELETE'],
            'examples' => ['Nimm Foto 12 von Schritt 90 in Rezept 4711 weg.'],
        ];
    }
}
