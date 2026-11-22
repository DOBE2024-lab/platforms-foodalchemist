<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Ein Text-Normalisierer für Vergleiche, Schlüssel und Matching (Code-Qualität 2.2b). Ersetzt die Kopien, die
 * vorher in rund 40 Dateien standen. Die Varianten sind bewusst getrennt benannt: wer „ä“ als „ae“ und wer es als
 * „a“ faltet, wer Akzente stehen lässt (SLUG) und wer sie wegwirft (KOMPAKT/WOERTER) — das bleibt so, sonst
 * verschieben sich Treffer und Dubletten-Schlüssel. Akzent-Tabellen bleiben bei ihren Stellen (TokenEngine,
 * StichwortFilter, RezeptTypVokabular), weil sie sich unterscheiden.
 */
final class Text
{
    /** Umlaut-Umschrift des Hauses (GP-Kennungen, Matching, Dubletten). Erwartet Kleinschrift. */
    public const UMSCHRIFT = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];

    /** Grundlaut statt Umschrift (ä → a). Nur wo es so vereinbart ist (Pairing-Slug, Hauptzutat-Bereinigung). */
    public const GRUNDLAUT = ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss'];

    /** klein + getrimmt + Umschrift. */
    public const STANDARD = 'standard';

    /** wie STANDARD, Leerraum vorher zu einem Leerzeichen kollabiert. */
    public const ZEILE = 'zeile';

    /** wie STANDARD, danach alles außer a-z0-9 ersatzlos weg (auch Leerzeichen, auch é). */
    public const KOMPAKT = 'kompakt';

    /** wie STANDARD, Folgen außer a-z0-9 zu einem Leerzeichen, getrimmt (é fällt weg). */
    public const WOERTER = 'woerter';

    /** wie STANDARD, Folgen außer Unicode-Buchstaben/-Ziffern zu „_“, getrimmt (é bleibt). */
    public const SLUG = 'slug';

    public static function umschrift(string $s): string
    {
        return strtr($s, self::UMSCHRIFT);
    }

    public static function grundlaut(string $s): string
    {
        return strtr($s, self::GRUNDLAUT);
    }

    public static function falte(string $s, string $variante = self::STANDARD): string
    {
        if ($variante === self::ZEILE) {
            $s = (string) preg_replace('/\s+/u', ' ', $s);
        }
        $s = self::umschrift(mb_strtolower(trim($s)));

        return match ($variante) {
            self::STANDARD, self::ZEILE => $s,
            self::KOMPAKT => (string) preg_replace('/[^a-z0-9]+/', '', $s),
            self::WOERTER => trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s)),
            self::SLUG => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', $s), '_'),
            default => throw new \InvalidArgumentException("Text::falte: unbekannte Variante „{$variante}“"),
        };
    }
}
