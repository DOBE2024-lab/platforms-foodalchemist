<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Support\Masse;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Zutaten-Picker des Editors (P-8), aus {@see RecipeService::sucheZutatenZiel()} ausgelagert (Größen-Wächter Spec 85 §9).
 * Gericht = nur Basisrezepte (Dominique 17.11.2026): im Gericht liefert die Suche keine Grundprodukte.
 */
final class ZutatenZielSuche
{
    /**
     * P-8-Picker: GPs der Team-Kette + Basisrezepte (ohne das Rezept selbst) — Auto-Fill-Daten
     * (ek_pro_g für die Client-Live-Summe) inklusive.
     */
    public function suche(Team $team, string $suche, int $ohneRecipeId, int $limit = 8): array
    {
        if (trim($suche) === '') {
            return [];
        }
        $recompute = app(RecipeRecomputeService::class);
        // Gericht = nur Basisrezepte (Dominique 17.11.2026): der Picker eines Gerichts bietet keine Grundprodukte an.
        $nurBasisrezepte = (bool) FoodAlchemistRecipe::query()->whereKey($ohneRecipeId)->value('is_sales_recipe');

        $gps = $nurBasisrezepte ? collect() : \Platform\FoodAlchemist\Support\Suche::like(
            FoodAlchemistGp::visibleToTeam($team)->lebensmittel(), 'name', $suche)   // Multi-Wort: jedes Token muss treffen; Non-Food ist keine Zutat
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'lead_la_supplier_item_id', 'piece_default_g', 'team_id'])
            ->map(fn ($gp) => [
                'type' => 'gp', 'id' => $gp->id, 'name' => $gp->name,
                'ek_pro_g' => $recompute->preisProGrammPublic($gp, $team),
                // Stückgewicht fürs Live-Rechnen: ohne das bleibt eine stk-Zeile im Client
                // ohne Gramm-Faktor (Anteil %/Yield/EK leer), obwohl der Server sie kennt.
                'g_pro_stueck' => $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null,
                'url' => \Platform\FoodAlchemist\Support\Sprungziel::gp($gp->id),  // R5: Sprung-Ziel
            ]);

        $subs = \Platform\FoodAlchemist\Support\Suche::like(
            FoodAlchemistRecipe::visibleToTeam($team)->basis()
                ->where('id', '!=', $ohneRecipeId), 'name', $suche)
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'ek_per_kg_eur'])
            ->map(fn ($r) => [
                'type' => 'sub', 'id' => $r->id, 'name' => '↳ ' . $r->name,
                'ek_pro_g' => Masse::preisJeG($r->ek_per_kg_eur),
                'url' => \Platform\FoodAlchemist\Support\Sprungziel::rezept($r->id),
            ]);

        return $gps->concat($subs)->take($limit)->values()->all();
    }
}
