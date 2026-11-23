<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Concepter\WordingResolver;

/**
 * Gemeinsame Grundlagen aller Foodbook-Teile (98 · 3.3 S4-1, aus FoodbookService verschoben): Besitzer-Riegel für Foodbook,
 * Kapitel und Block, das Kunden-Label eines Blocks und das Lesemodell detail (S4-3). Nur, was mehrere Teile brauchen; ohne Zustand.
 */
final class FoodbookBasis
{
    /** Foodbook mit Kapitel-Baum, Blöcken (Concept/Gericht für Preis und Chips), CRM-Link und Bedarf-Defaults; null, wenn nicht sichtbar. */
    public function detail(Team $team, int $id): ?FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['chapters' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks.concept:id,name,price_per_person_cache,price_display,price_incomplete,team_id,ist_fassung,price_mode,price_per_person_manual,price_override_expires_at',   // price_display → Editor-Chip istEinzelpreis
                'chapters.blocks.dish:id,name,ek_total_eur,sales_unit_count', 'chapters.blocks.dish.standardPresentation',   // Spec 85: EK je Portion (blockPreis)
                'crmCompany', 'crmContact',   // #369: CRM-Kunde-Link
                'serviceMoments', 'targetGroups', 'defaultEventType', 'defaultServingForm']) // Spec 19 E3.3: Bedarf-Defaults
            ->find($id);
    }

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
