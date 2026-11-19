<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Kapitel-Auswahl im Präsentations-Design (2026-11-19): ein Kapitel-Block (`chapter_loop`) zeigt
 * alle Kapitel, einen Bereich (Kapitel X bis Y) oder die übrigen, die kein anderer Kapitel-Block
 * des Designs zeigt. So lassen sich Kapitel einzeln zwischen Bilder, Texte und Preisblöcke setzen.
 *
 * Gezählt werden Hauptkapitel ab 1, in der Reihenfolge der Ausgabe; Unterkapitel gehören immer
 * zu ihrem Hauptkapitel. Ein Design gilt für viele Dokumente, deshalb zählt die Position und
 * nicht eine Kapitel-ID. Gilt für jede Form mit Kapiteln (Foodbook, Angebot, Speisekarte).
 */
final class KapitelAuswahl
{
    public const MODI = ['alle', 'bereich', 'rest'];

    public const MAX_NR = 99;

    /** Modus aus dem Block-Stil (Unbekanntes = alle). */
    public static function modus(array $style): string
    {
        $m = (string) ($style['kapitel_auswahl'] ?? 'alle');

        return in_array($m, self::MODI, true) ? $m : 'alle';
    }

    /**
     * Bereich aus dem Block-Stil: von ≥ 1, bis leer = bis zum letzten Kapitel. Vertauschte Werte werden gedreht.
     *
     * @return array{0:int, 1:int}
     */
    public static function bereich(array $style): array
    {
        $von = self::zahl($style['kapitel_von'] ?? null) ?? 1;
        $bis = self::zahl($style['kapitel_bis'] ?? null) ?? self::MAX_NR;

        return $von <= $bis ? [$von, $bis] : [$bis, $von];
    }

    /**
     * Hauptkapitel-Nummer je Abschnitt (Schlüssel bleibt der Abschnitts-Index).
     *
     * @param  array<int, array>  $sections
     * @return array<int, int>
     */
    public static function nummern(array $sections): array
    {
        $nr = 0;
        $out = [];
        foreach ($sections as $i => $s) {
            if ((int) ($s['depth'] ?? 0) === 0 || $nr === 0) {
                $nr++;
            }
            $out[$i] = $nr;
        }

        return $out;
    }

    /**
     * Abschnitte, die ein Bereichs-Block des Layouts zeigt — die „übrigen“ lassen sie aus.
     *
     * @param  list<array{block_type?:string, style?:array}>  $layout
     * @param  array<int, array>  $sections
     * @return array<int, true>
     */
    public static function vergeben(array $layout, array $sections): array
    {
        $nummern = self::nummern($sections);
        $vergeben = [];
        foreach ($layout as $block) {
            $style = is_array($block['style'] ?? null) ? $block['style'] : [];
            if (($block['block_type'] ?? '') !== 'chapter_loop' || self::modus($style) !== 'bereich') {
                continue;
            }
            [$von, $bis] = self::bereich($style);
            foreach ($nummern as $i => $nr) {
                if ($nr >= $von && $nr <= $bis) {
                    $vergeben[$i] = true;
                }
            }
        }

        return $vergeben;
    }

    /**
     * Die Abschnitte, die dieser Block zeigt (Schlüssel = ursprünglicher Index, für eindeutige Anker).
     *
     * @param  array<int, array>  $sections
     * @param  array<int, true>  $vergeben
     * @return array<int, array>
     */
    public static function filtern(array $sections, array $style, array $vergeben = []): array
    {
        $modus = self::modus($style);
        if ($modus === 'alle') {
            return $sections;
        }
        if ($modus === 'rest') {
            return array_diff_key($sections, $vergeben);
        }
        [$von, $bis] = self::bereich($style);
        $nummern = self::nummern($sections);

        return array_filter($sections, fn ($s, $i) => $nummern[$i] >= $von && $nummern[$i] <= $bis, ARRAY_FILTER_USE_BOTH);
    }

    /** Kurzname für die Aufbau-Liste im Builder („Kapitel 2“, „Kapitel 1 bis 3“, „Übrige Kapitel“). */
    public static function titel(array $style): string
    {
        $modus = self::modus($style);
        if ($modus === 'alle') {
            return 'Alle Kapitel';
        }
        if ($modus === 'rest') {
            return 'Übrige Kapitel';
        }
        [$von, $bis] = self::bereich($style);
        if ($von === $bis) {
            return 'Kapitel ' . $von;
        }

        return $bis >= self::MAX_NR ? 'Kapitel ab ' . $von : 'Kapitel ' . $von . ' bis ' . $bis;
    }

    private static function zahl(mixed $v): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return max(1, min(self::MAX_NR, (int) $v));
    }
}
