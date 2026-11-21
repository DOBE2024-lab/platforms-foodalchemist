<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tools\Concerns\RezeptFotoZugriff;

/**
 * 98 · 2.3c: Foto endgültig aus dem Pool löschen — inkl. Datei und aller Schritt-Verknüpfungen (RecipeStepService::fotoLoeschen).
 * Confirm=true Pflicht.
 */
class RecipeStepsPhotoDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use RezeptFotoZugriff;

    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.PHOTO_DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein Foto endgültig aus dem Foto-Pool eines team-eigenen Rezepts (nur Status stub/draft) — samt Datei und allen '
            . 'Schritt-Verknüpfungen. Erfordert confirm=true. Nur abhängen: recipe_steps.PHOTO_UNLINK.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'photo_id' => ['type' => 'integer', 'description' => 'Foto aus dem Pool dieses Rezepts.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['recipe_id', 'photo_id'],
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
        $recipe = $this->fotoRezept($team, (int) ($arguments['recipe_id'] ?? 0));
        if ($recipe instanceof ToolResult) {
            return $recipe;
        }
        $photoId = (int) ($arguments['photo_id'] ?? 0);
        if ($this->poolFoto($recipe, $photoId) === null) {
            return ToolResult::error('Foto gehört nicht zu diesem Rezept.', 'NOT_FOUND');
        }

        app(RecipeStepService::class)->fotoLoeschen($recipe, $photoId, $team);

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'photo_id' => $photoId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'steps', 'foto', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.recipe_steps.PHOTO_UNLINK', 'foodalchemist.recipe_steps.GET'],
            'examples' => ['Lösche Foto 12 aus Rezept 4711 (confirm=true).'],
        ];
    }
}
