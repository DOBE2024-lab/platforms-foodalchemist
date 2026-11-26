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
                'vk_pro_person' => $s['preis']['pp'], 'vorlaeufig' => (bool) ($s['preis']['vorlaeufig'] ?? false),
                'gesamt' => (! $alt && $pax) ? round((float) $s['preis']['pp'] * $pax, 2) : null,
                'alternativen' => $alt,
                'preis_range' => $alt ? ['min' => $s['preis']['min'], 'max' => $s['preis']['max']] : null,
                'wahl_spanne' => ! empty($s['preis']['wahl']) ? ['von' => $s['preis']['min'], 'bis' => $s['preis']['max']] : null,   // 98 · 3.6 P4
            ];
        }

        return $zeilen;
    }

    /**
     * CQ price_incomplete, Republish `preserve`: mit einem eingefrorenen Preis friert auch sein „vorläufig“-Merker ein.
     * Liest die Pfade aus {@see \Platform\FoodAlchemist\Services\Ausgabe\PresentationService::preisPfade} (Block, Kapitel, Gesamt)
     * und übernimmt den Merker des Alt-Snapshots an genau diesen Knoten; der Live-Merker gilt dort nicht mehr.
     *
     * @param  list<string>  $pfade  die tatsächlich eingefrorenen Preis-Pfade
     */
    public static function merkerEinfrieren(array $alt, array $neu, array $pfade): array
    {
        foreach ($pfade as $pfad) {
            if (preg_match('/^s(\d+)\.b(\d+)\.(?:pp|pauschal)$/', $pfad, $m) && is_array($neu['content']['sections'][$m[1]]['blocks'][$m[2]]['price'] ?? null)) {
                $neu['content']['sections'][$m[1]]['blocks'][$m[2]]['price']['vorlaeufig'] = (bool) ($alt['content']['sections'][$m[1]]['blocks'][$m[2]]['price']['vorlaeufig'] ?? false);
            } elseif (preg_match('/^s(\d+)\.pp$/', $pfad, $m) && is_array($neu['content']['sections'][$m[1]]['preis'] ?? null)) {
                $neu['content']['sections'][$m[1]]['preis']['vorlaeufig'] = (bool) ($alt['content']['sections'][$m[1]]['preis']['vorlaeufig'] ?? false);
            } elseif (str_starts_with($pfad, 'total.') && is_array($neu['content']['total'] ?? null)) {
                $neu['content']['total']['vorlaeufig'] = (bool) ($alt['content']['total']['vorlaeufig'] ?? false);
            }
        }

        return $neu;
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
                'vk_pro_person' => $kap['vk_pro_person'], 'vorlaeufig' => (bool) ($kap['vk_fehlt'] ?? false),
                'gesamt' => $kap['gesamt'] ?? null,
                'alternativen' => $isAlt,
                'preis_range' => $kap['preis_range'] ?? null,
                'wahl_spanne' => $kap['wahl_spanne'] ?? null,   // 98 · 3.6 P4: Wahlgruppen als Spanne, gerechnet mit der teuersten
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
