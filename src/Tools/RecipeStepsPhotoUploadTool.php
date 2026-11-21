<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\RezeptFotoService;
use Platform\FoodAlchemist\Tools\Concerns\DateiAusArgumenten;
use Platform\FoodAlchemist\Tools\Concerns\RezeptFotoZugriff;

/**
 * 98 · 2.3c: Schrittfoto hochladen — derselbe Weg wie der Schritt-Editor und das Handy-Detail (RezeptFotoService::schrittFoto):
 * speichern, ins Foto-Pool des Rezepts legen, mit step_id direkt dort anhängen. Bild-Prüfung wie die Oberfläche (BildUpload),
 * Riegel wie recipe_steps.PUT (Besitzer-Team, nur stub/draft).
 */
class RecipeStepsPhotoUploadTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use DateiAusArgumenten;
    use RezeptFotoZugriff;

    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.PHOTO_UPLOAD';
    }

    public function getDescription(): string
    {
        return 'Lädt ein Foto ins Foto-Pool eines team-eigenen Rezepts (nur Status stub/draft) und hängt es optional an einen Schritt (step_id, '
            . 'aus recipe_steps.GET; ohne step_id bleibt es schritt-frei im Pool). datei: base64 (auch data-URL) ODER url; nur Bilddateien '
            . '(JPG/PNG/WebP), höchstens 14 MB. Optional caption. Gepflegte Rezepte (review/approved/archived) sind gesperrt — dort in der '
            . 'Oberfläche unter Rezept › Schritte.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'step_id' => ['type' => 'integer', 'description' => 'Optional: Schritt dieses Rezepts, an den das Foto gehängt wird.'],
                'caption' => ['type' => 'string', 'description' => 'Optional: Bildunterschrift.'],
                'datei' => [
                    'type' => 'object',
                    'description' => 'Bilddatei: base64 (auch data:image/…;base64,…) ODER url (http/https), dazu optional name.',
                    'properties' => [
                        'base64' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'name' => ['type' => 'string', 'description' => 'Dateiname, z. B. schritt-3.jpg'],
                    ],
                ],
            ],
            'required' => ['recipe_id', 'datei'],
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
        $schritt = null;
        if (! empty($arguments['step_id'])) {
            $schritt = $this->fotoSchritt($recipe, (int) $arguments['step_id']);
            if ($schritt === null) {
                return ToolResult::error('Schritt gehört nicht zu diesem Rezept.', 'NOT_FOUND');
            }
        }

        try {
            $datei = $this->dateiAusArgumenten(is_array($arguments['datei'] ?? null) ? $arguments['datei'] : null);
            if ($datei === null) {
                return ToolResult::error('datei.base64 oder datei.url angeben.', 'VALIDATION_ERROR');
            }
            if (($bildFehler = $this->bildFehler($datei)) !== null) {
                return ToolResult::error($bildFehler, 'VALIDATION_ERROR');
            }
            $caption = isset($arguments['caption']) ? (string) $arguments['caption'] : null;
            $foto = app(RezeptFotoService::class)->schrittFoto($team, $recipe, $datei, $caption,
                $schritt?->id, $schritt?->ebene ?? FoodAlchemistRecipeStep::EBENE_PRODUKTION);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'photo' => $this->fotoArr($foto)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'steps', 'foto', 'upload', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_io',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipe_steps.GET', 'foodalchemist.recipe_steps.PHOTO_LINK', 'foodalchemist.recipe_steps.PHOTO_RESULT'],
            'examples' => ['Lade dieses Foto an Schritt 88 von Rezept 4711.'],
        ];
    }
}
