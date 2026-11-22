<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * Golden-Vergleich für Charakterisierungstests (98 · 3.3): hält das heutige Verhalten als JSON fest.
 * `FA_GOLDEN_SCHREIBEN=1` schreibt fehlende oder geänderte Einträge (nur bewusst, z. B. beim ersten Lauf oder nach einer
 * gewollten Verhaltensänderung mit Begründung im PR); ohne die Variable vergleicht der Test und schreibt nie.
 */
final class Golden
{
    public static function pfad(string $datei): string
    {
        return __DIR__.'/../Fixtures/golden/'.$datei.'.json';
    }

    /** @return array{ist: mixed, soll: mixed} */
    public static function vergleich(string $datei, string $schluessel, mixed $ist): array
    {
        $ist = json_decode(json_encode($ist, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE), true);
        $pfad = self::pfad($datei);
        $alle = is_file($pfad) ? (json_decode((string) file_get_contents($pfad), true) ?? []) : [];
        if (getenv('FA_GOLDEN_SCHREIBEN') === '1' && ($alle[$schluessel] ?? null) !== $ist) {
            $alle[$schluessel] = $ist;
            ksort($alle);
            @mkdir(dirname($pfad), 0775, true);
            file_put_contents($pfad, json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }

        return ['ist' => $ist, 'soll' => array_key_exists($schluessel, $alle) ? $alle[$schluessel] : '(kein Golden-Eintrag – mit FA_GOLDEN_SCHREIBEN=1 anlegen)'];
    }
}
