<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * 98 · 3.8 Ordnung: Karte der aus dem Root von src/Services in Fachordner verschobenen Dienste (alter Pfad → neuer Pfad,
 * relativ zu src). Schreibt nur scripts/services-umzug.php; nie von Hand pflegen.
 *
 * Wozu: Baselines, die nach Pfad schlüsseln (Abhängigkeits-Klinke), sehen eine verschobene Datei sonst als neu (0 → n).
 * Mit dieser Karte zählt der neue Pfad bei der Quelle (BaselineKlinke::gestiegen, Umzugs-Parameter). Dazu prüft
 * ServicesOrdnungTest, dass kein Code mehr den alten Klassennamen nutzt.
 */
final class ServicesUmzuege
{
    /** @var array<string, string> 'Services/X.php' => 'Services/Ordner/X.php' */
    public const KARTE = [
    ];

    /** @return array<string, string> neuer Pfad => alter Pfad (Form, die BaselineKlinke::gestiegen erwartet) */
    public static function zielZuQuelle(): array
    {
        return array_flip(self::KARTE);
    }

    /** @return array<string, string> alter voller Klassenname => neuer voller Klassenname */
    public static function klassen(): array
    {
        $fqn = fn (string $pfad) => 'Platform\\FoodAlchemist\\'.str_replace('/', '\\', substr($pfad, 0, -4));
        $out = [];
        foreach (self::KARTE as $alt => $neu) {
            $out[$fqn($alt)] = $fqn($neu);
        }

        return $out;
    }
}
