<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\WordingResolver;

/**
 * Gemeinsame Grundlagen aller Foodbook-Teile (98 · 3.3 S4-1, aus FoodbookService verschoben): Besitzer-Riegel für Foodbook,
 * Kapitel und Block sowie das Kunden-Label eines Blocks. Nur, was mehrere Teile brauchen; ohne Zustand.
 */
final class FoodbookBasis
{
    /**
     * Kunden-Label eines Blocks — concept_ref/recipe_ref über die Wording-Kette
     * (WordingResolver: wording → kundentext-Legacy → Standard → Name);
     * header/text behalten kundentext als Inhalt; spacer/image => null.
     */
    public function dokBlockLabel(FoodAlchemistFoodbookBlock $b): ?string
    {
        return match (true) {
            in_array($b->type, ['concept_ref', 'recipe_ref'], true) => app(WordingResolver::class)->blockTitel($b)['text'],
            str_starts_with((string) $b->type, 'header') => $b->customer_text ?: null,
            $b->type === 'text' => $b->customer_text ?: null,
            default => null,
        };
    }

    public function ownedKapitel(Team $team, int $id): FoodAlchemistFoodbookKapitel
    {
        $k = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->findOrFail($id);
        if (! $k->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook: Ändern kann es nur das Besitzer-Team.');
        }

        return $k;
    }

    public function ownedBlock(Team $team, int $id): FoodAlchemistFoodbookBlock
    {
        $block = FoodAlchemistFoodbookBlock::visibleToTeam($team)->findOrFail($id);
        if (! $block->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook: Ändern kann es nur das Besitzer-Team.');
        }

        return $block;
    }

    public function guard(FoodAlchemistFoodbook $fb, Team $team): void
    {
        if (! $fb->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook: Ändern kann es nur das Besitzer-Team.');
        }
    }
}
