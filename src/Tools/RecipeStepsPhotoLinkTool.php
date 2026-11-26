<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Rezept\RecipeStepService;
use Platform\FoodAlchemist\Tools\Concerns\RezeptFotoZugriff;

/**
 * 98 · 2.3c: Pool-Foto an einen Schritt hängen (M:N — ein Foto darf an mehreren Schritten hängen). Idempotent:
 * hängt es schon dort, bleibt alles, wie es ist (RecipeStepService::fotoUmschalten würde es sonst abhängen).
 */
class RecipeStepsPhotoLinkTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use RezeptFotoZugriff;

    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.PHOTO_LINK';
    }

    public function getDescription(): string
    {
        return 'Hängt ein Foto aus dem Foto-Pool eines team-eigenen Rezepts (nur Status stub/draft) an einen Schritt desselben Rezepts. '
            . 'Ein Foto darf an mehreren Schritten hängen; hängt es schon dort, ändert sich nichts. Abhängen: recipe_steps.PHOTO_UNLINK.';
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

        $schonDa = $schritt->photos()->whereKey($foto->id)->exists();
        if (! $schonDa) {
            app(RecipeStepService::class)->fotoUmschalten($recipe, (string) $schritt->ebene, (int) $schritt->id, (int) $foto->id);
        }

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'step_id' => (int) $schritt->id,
            'unveraendert' => $schonDa, 'photo' => $this->fotoArr($foto)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'steps', 'foto', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_steps.GET', 'foodalchemist.recipe_steps.PHOTO_UNLINK'],
            'examples' => ['Häng Foto 12 auch an Schritt 90 von Rezept 4711.'],
        ];
    }
}
