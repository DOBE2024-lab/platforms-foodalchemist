<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Die eine Quelle für die Bezeichnungen der 14 EU-Hauptallergene (Code-Qualität 2.2b). Schlüssel = Spaltenteil
 * `allergen_<schlüssel>` bzw. FoodAlchemistGp::ALLERGEN_FIELDS, Reihenfolge = LMIV Anhang II (Kennbuchstaben A–N).
 * Eingangsschlüssel fremder Systeme (Necta: egg, peanut, nuts, sulfites) sind keine Labels und bleiben im Mapper.
 */
final class Allergene
{
    /** LMIV-Langform: Deklaration, Etikett, Speisekarte, Datenblätter. */
    public const LANG = [
        'gluten' => 'Glutenhaltiges Getreide',
        'crustaceans' => 'Krebstiere',
        'eggs' => 'Eier',
        'fish' => 'Fisch',
        'peanuts' => 'Erdnüsse',
        'soy' => 'Soja',
        'milk' => 'Milch',
        'tree_nuts' => 'Schalenfrüchte',
        'celery' => 'Sellerie',
        'mustard' => 'Senf',
        'sesame' => 'Sesam',
        'sulphites' => 'Schwefeldioxid & Sulfite',
        'lupin' => 'Lupinen',
        'molluscs' => 'Weichtiere',
    ];

    /** Kurzform für Regler, Pillen und Signale (Planung, Fassungs-Signale). */
    public const KURZ = [
        'gluten' => 'Gluten',
        'crustaceans' => 'Krebstiere',
        'eggs' => 'Eier',
        'fish' => 'Fisch',
        'peanuts' => 'Erdnüsse',
        'soy' => 'Soja',
        'milk' => 'Milch',
        'tree_nuts' => 'Schalenfrüchte',
        'celery' => 'Sellerie',
        'mustard' => 'Senf',
        'sesame' => 'Sesam',
        'sulphites' => 'Sulfite',
        'lupin' => 'Lupinen',
        'molluscs' => 'Weichtiere',
    ];
}
