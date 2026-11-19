<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Preis-Bausteine des Präsentations-Snapshots (2026-11-19, aus PresentationService geschnitten, Spec 85 §1):
 * MwSt-Satz, Gesamt mit Brutto, Preis-Aufschlüsselung für Foodbook und Angebot und der EK-Gurt.
 * Kundensicher: Aufschlüsselung und Gesamt tragen nur VK. EK kommt nur über die interne Vorschau
 * in den Snapshot und wird ohne sie von {@see self::ohneEk} restlos entfernt.
 */
final class PraesentationsPreise
{
    /** MwSt-Satz des Teams für Kundenpreise (Standardsatz ermäßigt oder regulär). */
    public static function mwstSatz(Team $team): float
    {
        $mwst = app(TeamSettingsService::class)->mwst($team);

        return ($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer'
            ? (float) ($mwst['regulaer'] ?? 19.0) : (float) ($mwst['ermaessigt'] ?? 7.0);
    }

    /** Preis aus dem Snapshot lesen: Zahl → float; deutscher Format-String „1.234,56 €" → float. */
    public static function parsePreis(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        $s = str_replace(['€', ' ', "\u{00a0}"], '', $v);
        $s = str_replace('.', '', $s);       // Tausenderpunkt
        $s = str_replace(',', '.', $s);      // Dezimalkomma

        return is_numeric($s) ? (float) $s : null;
    }

    /** Brutto zu einem Nettobetrag, null wenn eines fehlt. */
    public static function brutto(?float $netto, ?float $satz): ?float
    {
        return ($netto !== null && $satz !== null) ? round($netto * (1 + $satz / 100), 2) : null;
    }

    /**
     * Aufschlüsselungs-Zeilen fürs Foodbook: je Hauptkapitel mit Preis eine Zeile, mit Gästezahl auch die Summe.
     *
     * @param  list<array<string,mixed>>  $sections
     * @return list<array<string,mixed>>
     */
    public static function foodbookZeilen(array $sections, ?int $pax): array
    {
        $zeilen = [];
        foreach ($sections as $s) {
            if ((int) ($s['depth'] ?? 0) !== 0 || ! is_array($s['preis'] ?? null)) {
                continue;
            }
            $alt = ($s['preis']['pp'] ?? null) === null;
            $zeilen[] = [
                'titel' => (string) $s['title'],
                'pax' => $pax,
                'vk_pro_person' => $s['preis']['pp'],
                'gesamt' => (! $alt && $pax) ? round((float) $s['preis']['pp'] * $pax, 2) : null,
                'alternativen' => $alt,
                'preis_range' => $alt ? ['min' => $s['preis']['min'], 'max' => $s['preis']['max']] : null,
            ];
        }

        return $zeilen;
    }

    /**
     * Aufschlüsselungs-Zeilen fürs Angebot (#380 Q2) aus den Kapiteln der Komposition.
     *
     * @param  list<array<string,mixed>>  $kapitel
     * @return list<array<string,mixed>>
     */
    public static function angebotZeilen(array $kapitel): array
    {
        $zeilen = [];
        foreach ($kapitel as $kap) {
            $isAlt = $kap['ist_format'] && ($kap['format_price_mode'] ?? null) === 'alternativen';
            $hasRange = $isAlt && ! empty($kap['preis_range']) && ($kap['preis_range']['max'] ?? null) !== null;
            // Leere Kapitel (Header/Text ohne bepreiste Bausteine) nicht als 0,00-€-Zeile listen.
            if (! $hasRange && (float) ($kap['gesamt'] ?? 0) <= 0.0) {
                continue;
            }
            $zeilen[] = [
                'titel' => (string) $kap['title'],
                'pax' => $kap['pax'] ?? null,
                'vk_pro_person' => $kap['vk_pro_person'],
                'gesamt' => $kap['gesamt'] ?? null,
                'alternativen' => $isAlt,
                'preis_range' => $kap['preis_range'] ?? null,
            ];
        }

        return $zeilen;
    }

    /**
     * Kopf der Preis-Aufschlüsselung: Zeilen plus netto/MwSt/brutto. Ohne Nettosumme (Foodbook ohne Gästezahl)
     * bleiben die Summen leer, der Block zeigt dann nur den Preis pro Person.
     *
     * @param  list<array<string,mixed>>  $zeilen
     * @return array<string,mixed>
     */
    public static function aufschluesselung(array $zeilen, mixed $pax, ?float $proPerson, ?float $netto, ?float $satz): array
    {
        return [
            'zeilen' => $zeilen,
            'pax' => $pax,
            'pro_person' => $proPerson,
            'netto' => $netto !== null ? round($netto, 2) : null,
            'mwst_satz' => $satz,
            'mwst_betrag' => ($netto !== null && $satz !== null) ? round($netto * $satz / 100, 2) : null,
            'brutto' => self::brutto($netto, $satz),
            'zzgl' => [],
        ];
    }

    /** Entfernt alle EK-Angaben aus Abschnitten, Bausteinen und Zeilen (Gurt für den Kundenlink). */
    public static function ohneEk(array $sections): array
    {
        foreach ($sections as $si => $sec) {
            unset($sections[$si]['ek']);
            foreach ($sec['blocks'] ?? [] as $bi => $blk) {
                unset($sections[$si]['blocks'][$bi]['ek']);
                foreach ($blk['items'] ?? [] as $ii => $it) {
                    unset($sections[$si]['blocks'][$bi]['items'][$ii]['ek']);
                }
            }
        }

        return $sections;
    }
}
