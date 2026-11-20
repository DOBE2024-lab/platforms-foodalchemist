<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Support\Verweisziel;

/**
 * Code-Qualität 1.1j-1 (Mandanten-Prüfung, 19.11.2026) · Verweise einer Zutatenzeile (`referenced_recipe_id`, `gp_id`, `unit_vocab_id`) müssen
 * für das Team SICHTBAR sein, sonst flösse ein fremdes Basisrezept oder Grundprodukt in Kosten und Allergene (Gästesicherheit).
 *
 * Sicht heißt ausschließlich die bestehende Regel über {@see Verweisziel} (sichtBedingung inkl. Fassungen): Seed, Master-Kette, Inhaltsfreigaben,
 * Abo-Fassungen und beim GP das Stamm-Paket-Opt-in. Nie `isOwnedBy` und kein team_id-Vergleich für das Ziel eines Verweises.
 * Geprüft werden nur NEUE Verweise — was die Zeilen dieses Rezepts heute schon tragen, bleibt speicherbar (Altbestand blockiert kein Speichern).
 */
class ZutatenVerweise
{
    /**
     * @param  array<int, array>  $zeilen  Sync-Payload von {@see \Platform\FoodAlchemist\Services\RecipeService::syncIngredients()}
     *
     * @throws \RuntimeException
     */
    public function pruefeSicht(Team $team, FoodAlchemistRecipe $recipe, array $zeilen): void
    {
        $bestand = $recipe->ingredients()->get(['referenced_recipe_id', 'gp_id', 'unit_vocab_id']);
        $neu = fn (string $feld) => array_values(array_diff(
            array_unique(array_map('intval', array_filter(array_column($zeilen, $feld), fn ($v) => $v !== null && $v !== ''))),
            $bestand->pluck($feld)->filter()->map(fn ($v) => (int) $v)->all(),
        ));

        foreach (['referenced_recipe_id' => [FoodAlchemistRecipe::class, 'Unterrezept'], 'gp_id' => [FoodAlchemistGp::class, 'Grundprodukt'],
            'unit_vocab_id' => [FoodAlchemistVocabEinheit::class, 'Einheit']] as $feld => [$klasse, $was]) {
            if (($ids = $neu($feld)) !== []) {
                $this->ablehnen($ids, Verweisziel::sichtbar($klasse, $team)->whereKey($ids)->pluck((new $klasse)->getQualifiedKeyName())->all(), $was);
            }
        }
    }

    /** @param list<int> $ids @param array<int|string> $sichtbar */
    private function ablehnen(array $ids, array $sichtbar, string $was): void
    {
        $fehlt = array_values(array_diff($ids, array_map('intval', $sichtbar)));
        if ($fehlt !== []) {
            throw new \RuntimeException("{$was} nicht sichtbar für dieses Team (ID " . implode(', ', $fehlt) . ') — Verweis abgelehnt.');
        }
    }
}
