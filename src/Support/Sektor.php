<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Das eine Sektor-Vokabular (Verpflegungskontext) — Code-Qualität 2.5a. Vorher gab es drei abweichende Sätze:
 * Planung/Generator (betriebsgastronomie, catering, restaurant, care, schule_kita), Rezept-Eignung (die sechs unten)
 * und Freitext an der Concept-Eignung. Entscheid Dominique 2026-11-22: Ziel sind die sechs der Rezept-Eignung, die
 * Planung bekommt „Crew“ dazu. Alte Planungswerte (gespeicherte Sitzungen, Brief-Vorlagen, Wissens-Geltungen,
 * Kanon-Achsen) bleiben in der DB und laufen über {@see self::ALIASE}; es gibt keine Datenmigration.
 *
 * Nicht dieses Vokabular (eigene Achsen): TrendVokabular::SPARTEN, ObservatoriumInhalt::BRANCHEN,
 * TeamSettingsService::KUNDENTYPEN.
 */
final class Sektor
{
    /** Slug => Label. Reihenfolge = Anzeige-Reihenfolge. */
    public const LABELS = [
        'business' => 'Betriebsgastronomie',
        'care' => 'Care und Klinik',
        'crew' => 'Crew-Verpflegung',
        'event_privat' => 'Catering und Event',
        'kita_schule' => 'Kita und Schule',
        'restaurant' => 'Restaurant',
    ];

    /** Die Slugs als Liste (für Konstanten-Ausdrücke; deckungsgleich mit den Schlüsseln von LABELS, Test pinnt das). */
    public const SLUGS = ['business', 'care', 'crew', 'event_privat', 'kita_schule', 'restaurant'];

    /** Alte Planungs-/Generator-Werte => Slug. Nur lesend: neue Daten werden mit dem Slug geschrieben. */
    public const ALIASE = [
        'betriebsgastronomie' => 'business',
        'catering' => 'event_privat',
        'schule_kita' => 'kita_schule',
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return self::SLUGS;
    }

    public static function gueltig(?string $slug): bool
    {
        return $slug !== null && isset(self::LABELS[$slug]);
    }

    /** Slug oder Alias → Slug; alles andere (auch Leeres) → null. Groß-/Kleinschreibung und Rand-Leerraum egal. */
    public static function kanonisch(?string $wert): ?string
    {
        $w = mb_strtolower(trim((string) $wert));
        if (isset(self::LABELS[$w])) {
            return $w;
        }

        return self::ALIASE[$w] ?? null;
    }

    /**
     * Der Slug plus alle alten Schreibweisen, die auf ihn zeigen — für Lookups gegen Altdaten (Kanon-Achsen,
     * Wissens-Geltungen), die noch mit dem alten Wert gespeichert sind. Unbekannt → [].
     *
     * @return list<string>
     */
    public static function schreibweisen(?string $wert): array
    {
        $slug = self::kanonisch($wert);
        if ($slug === null) {
            return [];
        }

        return [$slug, ...array_keys(array_filter(self::ALIASE, fn ($ziel) => $ziel === $slug))];
    }

    public static function label(?string $wert): ?string
    {
        $slug = self::kanonisch($wert);

        return $slug === null ? null : self::LABELS[$slug];
    }

    /** @return array<string, string> Auswahlliste, optional mit Leer-Eintrag vorneweg. */
    public static function optionen(?string $leer = null): array
    {
        return $leer === null ? self::LABELS : ['' => $leer] + self::LABELS;
    }
}
