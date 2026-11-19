<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Preis je Kapitel für die Präsentation (2026-11-19), kundensicher: nur VK netto pro Person, nie EK oder Marge.
 * Ein Kapitel hat entweder einen festen Preis pro Person (Summe seiner Bausteine) oder, als Format mit
 * Alternativen, eine Spanne von bis. Beides landet als `preis` am Abschnitt und wird von `chapter_loop`
 * (Schalter „Preis je Kapitel“) und von der Preis-Aufschlüsselung gelesen. Gilt für Foodbook und Angebot.
 */
final class KapitelPreis
{
    /**
     * @param  array{min?:?float, max?:?float}|null  $spanne
     * @return array{pp:?float, min:?float, max:?float}|null  null = kein zeigbarer Preis
     */
    public static function bauen(?float $proPerson, ?array $spanne = null, bool $alternativen = false): ?array
    {
        if ($alternativen || ($proPerson === null && $spanne !== null)) {
            $min = isset($spanne['min']) ? (float) $spanne['min'] : null;
            $max = isset($spanne['max']) ? (float) $spanne['max'] : null;
            if ($max === null || $max <= 0) {
                return null;
            }

            return ['pp' => null, 'min' => $min, 'max' => $max];
        }
        if ($proPerson === null || $proPerson <= 0) {
            return null;
        }

        return ['pp' => round($proPerson, 2), 'min' => null, 'max' => null];
    }

    /** Anzeige-Text, z. B. „48,50 €“ oder „38,00 – 52,00 €“. Leer, wenn nichts zu zeigen ist. */
    public static function text(?array $preis): string
    {
        if (! is_array($preis)) {
            return '';
        }
        $nf = fn ($v) => number_format((float) $v, 2, ',', '.');
        if (($preis['pp'] ?? null) !== null && (float) $preis['pp'] > 0) {
            return $nf($preis['pp']) . ' €';
        }
        $min = $preis['min'] ?? null;
        $max = $preis['max'] ?? null;
        if ($max === null) {
            return '';
        }

        return ($min !== null && (float) $min !== (float) $max) ? $nf($min) . ' – ' . $nf($max) . ' €' : $nf($max) . ' €';
    }
}
