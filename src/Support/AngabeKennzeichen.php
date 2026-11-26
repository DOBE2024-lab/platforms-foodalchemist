<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Internes Kennzeichen einer Pflichtangabe ohne Beleg (GP-Regelwerk v3.10.0 Regel 91 „Angabe nicht belegt“ und 820 „Konservierungsart“,
 * Entscheid Dominique 12./13.11.2026). Steht nie im Namen.
 *
 * - `angenommen`: der übliche Wert aus der Startliste wurde angenommen (z. B. TK-Würfel ohne Maß → `Würfel 10 mm`, Honig → `flüssig`).
 *   Nennt ein Artikel/Produktpass später etwas anderes, wird korrigiert.
 * - `offen`: kein üblicher Wert; das GP bleibt ohne die Angabe (z. B. Konservierungsart nicht belegt, Güteklasse Olivenöl). Ein offenes GP wird
 *   in das EINE belegte GP derselben Ware zusammengeführt (Regel 91 v3, 14.11.2026); bei mehreren belegten Varianten bleibt es getrennt
 *   ({@see \Platform\FoodAlchemist\Services\GpStamm\GpBereinigenService::planDubletten()}, {@see self::zusammenfuehrbar()}).
 *
 * Grund = Prüflisten-Text, welche Angabe fehlt („Konservierungsart nicht belegt (§8.20)“). Ersetzt die frühere Formulierung
 * `needs_review`/`konservierungsart_pflicht` aus 820 v1 (die es im Code nie gab).
 */
final class AngabeKennzeichen
{
    public const ANGENOMMEN = 'angenommen';

    public const OFFEN = 'offen';

    public const WERTE = [self::ANGENOMMEN, self::OFFEN];

    /**
     * Spalten aus der Eingabe (`angabe_status`, `angabe_grund`). Mit GP (Bearbeiten): nur was mitkommt, sonst bleibt der Wert. Leerer Status
     * löscht Kennzeichen und Grund.
     *
     * @param  array<string, mixed>  $in
     * @return array{angabe_status?: ?string, angabe_grund?: ?string}
     *
     * @throws \RuntimeException bei unbekanntem Status
     */
    public static function felder(array $in, ?FoodAlchemistGp $gp): array
    {
        $hatStatus = array_key_exists('angabe_status', $in);
        $hatGrund = array_key_exists('angabe_grund', $in);
        if ($gp !== null && ! $hatStatus && ! $hatGrund) {
            return [];
        }
        $status = $hatStatus ? self::status($in['angabe_status']) : ($gp?->angabe_status ?: null);
        $grund = $hatGrund ? (trim((string) $in['angabe_grund']) ?: null) : ($gp?->angabe_grund ?: null);

        return ['angabe_status' => $status, 'angabe_grund' => $status === null ? null : $grund];
    }

    /** Status normalisieren: leer = kein Kennzeichen; sonst nur angenommen | offen. */
    public static function status(mixed $wert): ?string
    {
        $w = mb_strtolower(trim((string) $wert));
        if ($w === '') {
            return null;
        }
        if (! in_array($w, self::WERTE, true)) {
            throw new \RuntimeException("Regel 91: Kennzeichen »{$wert}« unbekannt — erlaubt: angenommen, offen (oder leer).");
        }

        return $w;
    }

    /**
     * Darf der Verlierer `$verlierer` in den Sieger `$sieger` zusammengeführt werden? Regel 91 v3 (Entscheid Dominique N-G2, 14.11.2026):
     * Gibt es genau EIN GP derselben Ware mit belegter Angabe, wird das offene dorthin zusammengeführt (Artikel gelten dann als „angenommen“) —
     * diese 1:1-Lage stellt der Merge-Plan fest (eine Gruppe = eine Ware). Nie umgekehrt: ein belegtes GP geht nicht in ein offenes auf.
     */
    public static function zusammenfuehrbar(?string $verlierer, ?string $sieger): bool
    {
        return ! ($sieger === self::OFFEN && $verlierer !== self::OFFEN);
    }
}
