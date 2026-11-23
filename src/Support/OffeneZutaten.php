<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Offene Pflicht-Zutaten eines Rezepts: kein GP, kein Unterrezept, nicht optional, nicht bewusst ignoriert. Reine Lese-Abfrage
 * auf dem Modell, genutzt von der Reife-Messung ({@see \Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter}) und von der
 * EK-Vollständigkeit ({@see \Platform\FoodAlchemist\Services\KostenCacheService::fuer}).
 *
 * Plan 98 · 3.3 S7 P1: verschoben aus RecipeReifeAdapter::offeneZutaten. KostenCacheService brauchte nur diese Abfrage und hing
 * damit über den Reife-Adapter (der BulkEnrich, OneShot und die Datenqualität komponiert) an der großen Kreis-Komponente.
 */
final class OffeneZutaten
{
    /** @return list<string> Namen der offenen Pflicht-Zutaten, in Positions-Reihenfolge */
    public static function namen(FoodAlchemistRecipe $r): array
    {
        return $r->ingredients()->whereNull('gp_id')->whereNull('referenced_recipe_id')->where('is_optional', false)
            ->where(fn ($q) => $q->whereNull('match_method')->orWhere('match_method', '!=', \Platform\FoodAlchemist\Enums\MatchMethod::Ignored->value))
            ->orderBy('position')->get(['display_name', 'raw_text'])
            ->map(fn ($z) => (string) ($z->display_name ?: $z->raw_text))->values()->all();
    }
}
