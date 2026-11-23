<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Picker-Kandidaten für den Concepter-Aufbau (Gerichte und Basisrezepte als Position).
 * Lag bis Plan 98 · 2.6c im alten PaketService; unverändert hierher verschoben, nur lesend.
 */
class AufbauKandidatenService
{
    /**
     * Gericht-Treffer für den Aufbau-Picker. Neben der Freitext-Suche jetzt
     * BAUM-FILTER (VK-Hauptgruppe → Klasse, + Geschmack) — gleiche Kaskade wie
     * der VK-Browser, damit man Gerichte browsen statt nur tippen kann.
     *
     * @param  array{hauptgruppe?:?int, klasse?:?int, geschmack?:?string}  $filter
     */
    public function gerichtKandidaten(Team $team, string $suche, array $filter = [], int $limit = 60): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id') // R4.4: Slot-Varianten sind konzept-lokal, nicht pickbar
            ->waehlbar()   // 1.1f (E1): Entwürfe wählbar (gekennzeichnet), Platzhalter/Veraltet nicht — gleiche Regel wie der Chip-Zähler
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            // Modell A: HG = eigene Achse (recipes.dish_main_group_id) — DIREKT filtern, exakt wie
            // SalesRecipeService::browserQuery/hauptgruppenCounts (Count-Quelle des Chips). Vorher lief
            // der HG-Filter indirekt über dish_class (dish_class.dish_main_group_id) und lieferte 0 Treffer
            // trotz Count, weil die Gerichte an globalen Diätform-Klassen (dish_main_group_id=NULL) hängen.
            // Zähler und Liste MÜSSEN dieselbe Filtersemantik haben, sonst laufen sie auseinander.
            ->when($filter['hauptgruppe'] ?? null, fn ($q, $hg) => $q->where('dish_main_group_id', $hg))
            ->when($filter['class'] ?? null, fn ($q, $k) => $q->where('dish_class_id', $k))
            ->when(($filter['geschmack'] ?? '') !== '', fn ($q) => $q->where('taste_direction', $filter['geschmack']))
            // R4.2 Lücken-Klick: Diät-Filter über die kanonische Klassen-Achse (dish_classes.diet_form)
            ->when(($filter['diet_form'] ?? '') !== '', fn ($q) => $q
                ->whereIn('dish_class_id', FoodAlchemistDishClass::where('diet_form', $filter['diet_form'])->pluck('id')))
            ->orderBy('name')->limit($limit)
            ->select(['id', 'name', 'status'])->mitStandardVk()->get();   // Spec 85 D-1: VK als vk_netto aus der Standard-Darreichung
    }

    /**
     * B2: Basisrezepte als Concept-Position (keine Dish-Klassen — die hängen an VK-Gerichten).
     * Filter wie der Rezept-Browser: Hauptgruppe (via Kategorie→main_group), Kategorie, Niveau.
     */
    public function basisKandidaten(Team $team, string $suche, array $filter = [], int $limit = 60): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->waehlbar()   // 1.1f (E1): Entwürfe wählbar (gekennzeichnet), Platzhalter/Veraltet nicht
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when(($filter['hauptgruppe'] ?? null), fn ($q, $hg) => $q->whereHas('category', fn ($k) => $k->where('main_group_id', (int) $hg)))
            ->when(($filter['category'] ?? null), fn ($q, $kat) => $q->where('category_id', (int) $kat))
            ->when(($filter['level'] ?? '') !== '', fn ($q) => $q->whereHas('levelSuitabilities', fn ($n) => $n->where('level_slug', $filter['level'])))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'status', 'ek_total_eur']);
    }
}
