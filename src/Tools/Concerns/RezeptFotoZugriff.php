<?php

namespace Platform\FoodAlchemist\Tools\Concerns;

use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;

/**
 * 98 · 2.3c: gemeinsamer Zugriff der Schrittfoto-Tools (recipe_steps.PHOTO_*). Dieselben Riegel wie recipe_steps.PUT:
 * Besitzer-Team (D1) und Draft-Quarantäne (nur stub/draft) — mit dem Hinweis, wo es stattdessen geht.
 * Nur für Klassen, die von FoodAlchemistTool erben (nutzt kiEditGesperrt).
 */
trait RezeptFotoZugriff
{
    /** Das schreibbare Rezept oder das passende Fehler-Ergebnis (NOT_FOUND/ACCESS_DENIED). */
    protected function fotoRezept(Team $team, int $recipeId): FoodAlchemistRecipe|ToolResult
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey($recipeId)->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Fotos nur durchs Besitzer-Team (D1).', 'ACCESS_DENIED');
        }
        if (($sperre = $this->kiEditGesperrt($recipe)) !== null) {
            return ToolResult::error($sperre . ' Der Rezept-Status sperrt Foto-Änderungen per MCP; in der Oberfläche geht es weiterhin '
                . '(Rezept › Schritte).', 'ACCESS_DENIED');
        }

        return $recipe;
    }

    /** Schritt dieses Rezepts (beliebige Ebene) oder null. */
    protected function fotoSchritt(FoodAlchemistRecipe $recipe, int $stepId): ?FoodAlchemistRecipeStep
    {
        return FoodAlchemistRecipeStep::where('team_id', $recipe->team_id)->where('recipe_id', $recipe->id)->whereKey($stepId)->first();
    }

    /** Foto aus dem Pool dieses Rezepts oder null. */
    protected function poolFoto(FoodAlchemistRecipe $recipe, int $photoId): ?FoodAlchemistRecipeStepPhoto
    {
        return FoodAlchemistRecipeStepPhoto::where('team_id', $recipe->team_id)->where('recipe_id', $recipe->id)->whereKey($photoId)->first();
    }

    /** Kompakte Foto-Antwort (wie recipe_steps.GET). */
    protected function fotoArr(FoodAlchemistRecipeStepPhoto $foto): array
    {
        return [
            'id' => (int) $foto->id,
            'caption' => $foto->caption,
            'is_result' => (bool) $foto->is_result,
            'url' => $foto->url(),
            'step_ids' => $foto->steps()->pluck('foodalchemist_recipe_steps.id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }
}
