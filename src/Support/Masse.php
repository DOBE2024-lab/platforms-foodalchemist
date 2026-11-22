<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Code-Qualität 2.2a: Preis je kg → Preis je g an einer Stelle (vorher je Stelle „€/kg geteilt durch 1000“ von Hand in
 * Zutaten-Editor, Recompute, Darreichungen, Team-VK und Zielsuche). Null bleibt null — kein stilles 0 €/g.
 */
final class Masse
{
    public const G_JE_KG = 1000;

    public static function preisJeG(mixed $preisJeKg): ?float
    {
        return $preisJeKg === null ? null : (float) $preisJeKg / self::G_JE_KG;
    }
}
