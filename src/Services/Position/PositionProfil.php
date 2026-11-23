<?php

namespace Platform\FoodAlchemist\Services\Position;

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;

/**
 * 98 · 3.6 Positions-Kern: was jede Positionsart kann — an EINER Stelle (Entscheid E4: Tabellen getrennt, Code gemeinsam).
 * Die gewollten Unterschiede stehen hier, nicht verstreut in den Diensten:
 *  - Staffel nur im Foodbook (P5: keine Staffel im Angebot; die Slot-Staffel-Tabelle ist ungenutzt).
 *  - Darreichung schreibbar nur im Angebot (Konzept-Slots über setSlotDarreichung); preiswirksam nur im Konzept (P3).
 *  - Wahlgruppen in Foodbook und Angebot.
 *  - Konzept-Struktur-Blöcke: header_preis für neue Blöcke verboten (P6), Bestand bleibt pflegbar.
 *
 * Preisbasen je Typ; Aliasse (MCP-Ergonomie, „pro_stueck-Falle“) werden kanonisiert, bevor geprüft wird.
 */
final class PositionProfil
{
    public const FOODBOOK = 'foodbook';

    public const ANGEBOT = 'angebot';

    public const KONZEPT = 'konzept';

    /** Eingabe-Aliasse → kanonische Preisbasis (gelten für alle Arten). */
    public const PREISBASIS_ALIAS = ['pro_person' => 'person', 'pro_stueck' => 'pauschal'];

    /**
     * Typ-Aliasse je Art: die Header-Presets tragen Foodbook-Namen (header_neutral/header_frei/header_frei_preis), das
     * Angebot kennt nur header/header_preis. Echtes Mapping statt stillem Rückfall auf „text“ (vorher in OfferCompositionService).
     */
    private const TYP_ALIAS = [
        self::ANGEBOT => ['header_neutral' => 'header', 'header_frei' => 'header', 'header_frei_preis' => 'header_preis'],
    ];

    /** @return list<string> */
    public static function typen(string $art, bool $neu = true): array
    {
        return match ($art) {
            self::FOODBOOK => ['concept_ref', 'recipe_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer', 'text'],
            self::ANGEBOT => FoodAlchemistOfferBlock::BLOCK_TYPES,
            self::KONZEPT => $neu ? ['text', 'spacer', 'header'] : ['text', 'spacer', 'header', 'header_preis'],   // P6
            default => throw new \LogicException("Unbekannte Positionsart {$art}."),
        };
    }

    /** Erlaubte Preisbasen eines Typs; leer = der Typ trägt keine Preisbasis. @return list<string> */
    public static function preisbasen(string $art, string $typ): array
    {
        return match (true) {
            $art === self::FOODBOOK && $typ === 'header_frei_preis' => FoodAlchemistFoodbookBlock::PRICE_BASES,   // person, pauschal, staffel
            $art === self::FOODBOOK && in_array($typ, ['recipe_ref', 'concept_ref'], true) => ['person', 'pauschal'],
            $art === self::ANGEBOT && $typ === 'position' => FoodAlchemistOfferBlock::POSITION_BASES,
            $art === self::ANGEBOT && in_array($typ, ['header_preis', 'recipe_ref', 'concept_ref'], true) => FoodAlchemistOfferBlock::PRICE_BASES,
            $art === self::KONZEPT && $typ === 'header_preis' => ['person', 'pauschal'],
            default => [],
        };
    }

    public static function typAlias(string $art, string $typ): string
    {
        return self::TYP_ALIAS[$art][$typ] ?? $typ;
    }

    public static function staffel(string $art): bool
    {
        return $art === self::FOODBOOK;
    }

    public static function wahlgruppen(string $art): bool
    {
        return in_array($art, [self::FOODBOOK, self::ANGEBOT], true);
    }

    /** Darreichung an der Zeile schreibbar (Konzept-Slots haben dafür setSlotDarreichung). */
    public static function darreichungSchreibbar(string $art): bool
    {
        return $art === self::ANGEBOT;
    }

    /** Darreichung wirkt auf den Preis (P3: nur im Konzept). */
    public static function darreichungPreiswirksam(string $art): bool
    {
        return $art === self::KONZEPT;
    }
}
