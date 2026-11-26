<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/** Explizite Tätigkeiten ersetzen die alte, von der Servierform abhängige Zeit vollständig. */
class DarreichungsZeitService
{
    /** Gemeinsame Basis für Vorschau und Auftragskosten; Lohnnebenkosten ergänzt die Auftragskalkulation einmal. */
    public function serviceKosten(Team $team, float $minuten, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $satz = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->stundensatz($team, $outlet);
        return ['minuten' => max(0, $minuten), 'stundensatz' => $satz, 'lohn_eur' => max(0, $minuten) / 60 * $satz];
    }

    public const TAETIGKEITEN = [
        'anrichten_teller' => 'Teller anrichten',
        'abfuellen' => 'Abfüllen',
        'portionieren_platte' => 'Platten vorbereiten',
        'stueck' => 'Stücke fertigstellen',
        'pauschale' => 'Vorbereitung / Zusatzarbeit',
    ];

    public const BASIS = [
        'portion' => 'Portion (s)',
        'einheit' => 'Glas / Einheit (s)',
        'stueck' => 'Stück (s)',
        'behaelter' => 'Ausgabeplatte / Behälter (min)',
        'kg' => 'Kilogramm (min)',
        'auftragszeile' => 'Auftrag je Darreichung (min)',
    ];

    public function normalisieren(mixed $zeilen): array
    {
        if (! is_array($zeilen) || ! array_is_list($zeilen) || count($zeilen) > 30) {
            throw new \RuntimeException('Arbeitszeiten müssen eine Liste mit höchstens 30 Arbeitsschritten sein.');
        }
        return array_map(function ($z) {
            if (! is_array($z) || ! is_string($z['use_case'] ?? null) || ! is_string($z['basis'] ?? null)
                || ! isset(self::TAETIGKEITEN[$z['use_case'] ?? ''])
                || ! isset(self::BASIS[$z['basis'] ?? '']) || ! in_array($z['ort'] ?? null, ['kueche', 'vor_ort'], true)) {
                throw new \RuntimeException('Tätigkeit, Bezugsmenge oder Arbeitsort ungültig.');
            }
            $wert = str_replace(',', '.', trim((string) ($z['wert'] ?? '')));
            if (! is_numeric($wert) || ! is_finite((float) $wert) || (float) $wert < 0 || (float) $wert > 100000) {
                throw new \RuntimeException('Bitte eine Zeit zwischen 0 und 100.000 eingeben.');
            }
            $out = ['use_case' => $z['use_case'], 'basis' => $z['basis'], 'ort' => $z['ort'], 'wert' => (float) $wert];
            if (($z['quelle'] ?? null) === 'ki_vorschlag') {
                $out += ['quelle' => 'ki_vorschlag', 'confidence' => max(0, min(1, (float) ($z['confidence'] ?? 0)))];
            } elseif (($z['quelle'] ?? null) === 'bestand') {
                $out['quelle'] = 'bestand';
            }
            return $out;
        }, $zeilen);
    }

    /** Nur Vorschau/Formular: keine Bestandsänderung beim Lesen. */
    public function formular(FoodAlchemistRecipeDarreichung $dar): array
    {
        if ($dar->arbeitszeiten !== null) {
            return $dar->arbeitszeiten;
        }
        $code = $dar->servingForm?->code;
        $einheit = \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::anrichteEinheit($code)['einheit'];
        if ($einheit === 'vor_ort') {
            return [];
        }
        $wert = $dar->anrichten_wert;
        $basis = ['stueck' => 'stueck', 'behaelter' => 'behaelter'][$einheit] ?? 'portion';
        if ($wert === null && $basis === 'portion') {
            $r = $dar->recipe;
            if ($r->variable_work_time_min !== null && ($r->variable_work_time_basis ?? 'kg') === 'portion') {
                $wert = (float) $r->variable_work_time_min * 60;
            } elseif ($r->variable_work_time_min !== null) {
                $basis = ($r->variable_work_time_basis ?? 'kg') === 'kg' ? 'kg' : 'stueck';
                $wert = (float) $r->variable_work_time_min * ($basis === 'kg' ? 1 : 60);
            } elseif ($r->variable_work_time_min === null) {
                $wert = app(\Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::class)->tellerKomponenten($r) * \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::ANRICHTEN_SEKUNDEN_JE_KOMPONENTE;
            }
        }
        $wert ??= match ($basis) {
            'stueck' => \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::ANRICHTEN_SEKUNDEN_JE_STUECK,
            'behaelter' => \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::ANRICHTEN_MINUTEN_JE_PLATTE,
            default => null,
        };
        $zeilen = $wert !== null ? [[
            'use_case' => ['stueck' => 'stueck', 'behaelter' => 'portionieren_platte'][$basis] ?? 'anrichten_teller',
            'basis' => $basis, 'ort' => 'kueche', 'wert' => $wert,
        ]] : [];
        if ((float) $dar->anrichten_pauschal_min > 0) {
            $zeilen[] = ['use_case' => 'pauschale', 'basis' => 'auftragszeile', 'ort' => 'kueche', 'wert' => (float) $dar->anrichten_pauschal_min];
        }
        return $zeilen;
    }

    public function berechnen(Team $team, FoodAlchemistRecipe $gericht, FoodAlchemistRecipeDarreichung $dar, float $portionen): array
    {
        $kueche = $vorOrt = 0.0;
        $posten = $warnungen = [];
        $vollstaendig = true;
        foreach ($dar->arbeitszeiten ?? [] as $z) {
            $menge = match ($z['basis']) {
                'portion' => $portionen,
                'auftragszeile' => $portionen > 0 ? 1.0 : 0.0,
                'einheit', 'stueck' => $dar->unit_count !== null && $dar->unit_count > 0 ? $portionen * $dar->unit_count : null,
                'behaelter' => $this->platten($team, $gericht, $dar, $portionen),
                'kg' => ($gramm = app(DarreichungService::class)->grammJeEinheit($dar, $gericht)) !== null && $dar->unit_count > 0
                    ? $portionen * $gramm * $dar->unit_count / 1000 : null,
            };
            $minuten = $menge !== null ? $menge * $z['wert'] / (in_array($z['basis'], ['portion', 'einheit', 'stueck'], true) ? 60 : 1) : null;
            if ($minuten === null) {
                $vollstaendig = false;
                $warnungen[] = 'Zeit nicht vollständig berechnet: Menge für '.self::BASIS[$z['basis']].' fehlt.';
            } elseif ($z['ort'] === 'kueche') {
                $kueche += $minuten;
            } else {
                $vorOrt += $minuten;
            }
            $posten[] = $z + ['menge' => $menge, 'minuten' => $minuten !== null ? round($minuten, 3) : null];
        }
        return ['minuten' => $kueche, 'vor_ort_minuten' => $vorOrt, 'posten' => $posten,
            'vollstaendig' => $vollstaendig, 'warnungen' => array_values(array_unique($warnungen))];
    }

    private function platten(Team $team, FoodAlchemistRecipe $gericht, FoodAlchemistRecipeDarreichung $dar, float $portionen): ?float
    {
        $anteil = app(DarreichungService::class)->produktionsAnteil($gericht, $dar, $portionen, $team);
        $b = app(\Platform\FoodAlchemist\Services\Produktion\BehaelterBedarfService::class)->behaelterFuerGericht($team, $gericht, $anteil['ansaetze'], 'ausgabe');
        return $b['anzahl'] > 0 ? (float) $b['anzahl'] : null;
    }
}
