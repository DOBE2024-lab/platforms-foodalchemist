<?php

namespace Platform\FoodAlchemist\Services\GpStamm;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\KeinPlatzhalterException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;

/**
 * Platzhalter-GPs: Allergene, Zusatzstoffe und Nährwerte von Hand (Entscheid Dominique 19.11.2026: „bei neutralen Artikeln, also
 * Platzhalter-Artikeln, … händisch setzen. Aber nur bei Platzhalter.“).
 *
 * Platzhalter (`is_platzhalter = 1`, „Leitungswasser (neutral)“) brauchen keinen Lieferantenartikel. Ohne Artikel gab es keine Werte:
 * 14 Allergene unbekannt, Konfidenz NONE, Zusatzstoffe ohne Angabe, keine Nährwerte — und jedes Rezept mit Wasser wurde auf LOW gedeckelt.
 *
 * Keine zweite Rechnung, sondern die vorhandenen Hand-Ebenen am GP:
 * - Allergene: Override-Spalten `allergen_*` (GL-01 §4.3 Prio 1), `allergens_source = manual`. Sind alle 14 gesetzt, gilt das Profil als
 *   belegt: {@see GpAggregateService::allergenKonfidenz} meldet HIGH (Quelle `manuell`).
 * - Zusatzstoffe: `deklaration_manuell` {stoff: 1|3}, von {@see GpAggregateService} je Stoff vor das Artikel-MAX gelegt (wie der Allergen-Override).
 * - Nährwerte: `nutri_*` mit `nutri_source = manual` — die liest der Rezept-Pfad schon ({@see Rezept\NaehrwertAusbeuteRechner}).
 *
 * Nur Platzhalter, die das Team besitzt (isOwnedBy). Alles andere: {@see KeinPlatzhalterException} bzw. {@see GeerbtNurLesenException}.
 */
class PlatzhalterDeklarationService
{
    /** Erlaubte Allergen-Werte von Hand (unbekannt = Feld leeren, nie als Wert speichern). */
    public const ALLERGEN_WERTE = ['enthalten', 'spuren', 'nicht_enthalten'];

    /** Schlüssel der Nährwert-Eingabe (je 100 g) → GP-Spalte. */
    public const NAEHRWERTE = [
        'kcal' => 'nutri_kcal_per_100g',
        'protein_g' => 'nutri_protein_g_per_100g',
        'fat_g' => 'nutri_fat_g_per_100g',
        'saturated_fat_g' => 'nutri_saturated_fat_g_per_100g',
        'carbs_g' => 'nutri_carbs_g_per_100g',
        'sugar_g' => 'nutri_sugar_g_per_100g',
        'salt_g' => 'nutri_salt_g_per_100g',
    ];

    public const NAEHRWERT_LABELS = [
        'kcal' => 'Energie (kcal)', 'protein_g' => 'Eiweiß (g)', 'fat_g' => 'Fett (g)', 'saturated_fat_g' => 'davon gesättigte Fettsäuren (g)',
        'carbs_g' => 'Kohlenhydrate (g)', 'sugar_g' => 'davon Zucker (g)', 'salt_g' => 'Salz (g)',
    ];

    /** Zusatzstoffe + freiwillige Angaben (Jod) — dieselben Schlüssel wie an der Artikel-Deklaration. @return list<string> */
    public static function stoffe(): array
    {
        return [...array_keys(FoodAlchemistItemDeclaration::STOFFE), ...array_keys(FoodAlchemistItemDeclaration::FREIWILLIG)];
    }

    /** Alle 14 Allergene von Hand gesetzt — nur dann gilt ein Platzhalter als belegt. */
    public static function allergeneVollstaendig(FoodAlchemistGp $gp): bool
    {
        return $gp->is_platzhalter && count($gp->allergenOverrides()) === count(FoodAlchemistGp::ALLERGEN_FIELDS);
    }

    /** Zusatzstoffe von Hand als [stoff => 1|3] (nur Platzhalter, sonst leer). @return array<string,int> */
    public static function zusatzstoffeManuell(FoodAlchemistGp $gp): array
    {
        if (! $gp->is_platzhalter) {
            return [];
        }
        $roh = $gp->deklaration_manuell;
        if (is_string($roh)) {
            $roh = json_decode($roh, true);
        }

        return collect(is_array($roh) ? $roh : [])
            ->filter(fn ($v, $k) => in_array($k, self::stoffe(), true) && in_array((int) $v, [1, 3], true))
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Lesesicht (UI + MCP): Werte von Hand, Vollständigkeit.
     *
     * @return array{allergene: array<string,?string>, zusatzstoffe: array<string,?string>, naehrwerte: array<string,?float>, allergene_vollstaendig: bool, zusatzstoffe_vollstaendig: bool, naehrwerte_vollstaendig: bool, quelle: string}
     */
    public function lesen(FoodAlchemistGp $gp): array
    {
        $allergene = [];
        foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $allergene[$feld] = AllergenValue::tryFrom((string) $gp->getAttribute("allergen_{$feld}"))?->value;
        }
        $manuell = self::zusatzstoffeManuell($gp);
        $zusatz = [];
        foreach (self::stoffe() as $stoff) {
            $zusatz[$stoff] = isset($manuell[$stoff]) ? ($manuell[$stoff] === 3 ? 'ja' : 'nein') : null;
        }
        $naehr = [];
        foreach (self::NAEHRWERTE as $key => $spalte) {
            $naehr[$key] = $gp->nutri_source === 'manual' && $gp->getAttribute($spalte) !== null ? (float) $gp->getAttribute($spalte) : null;
        }

        return [
            'allergene' => $allergene,
            'zusatzstoffe' => $zusatz,
            'naehrwerte' => $naehr,
            'allergene_vollstaendig' => ! in_array(null, $allergene, true),
            'zusatzstoffe_vollstaendig' => ! in_array(null, array_intersect_key($zusatz, FoodAlchemistItemDeclaration::STOFFE), true),
            'naehrwerte_vollstaendig' => ! in_array(null, $naehr, true),
            'quelle' => 'manual',
        ];
    }

    /**
     * Werte von Hand setzen (Teil-Update: nur übergebene Schlüssel ändern sich; null leert ein Feld).
     *
     * Eingabe: `allergene` {feld: enthalten|spuren|nicht_enthalten|null}, `alle_allergene` (ein Wert für alle 14), `zusatzstoffe` {stoff: ja|nein|null},
     * `alle_zusatzstoffe` (ja|nein für alle Pflicht-Stoffe), `naehrwerte` {kcal|protein_g|…: Zahl|null} je 100 g.
     * Danach rechnen die Rezepte mit diesem Platzhalter neu (bestehender Pfad {@see LeadLaService::recomputeNutzerFuerGps}).
     *
     * @param  array<string,mixed>  $eingabe
     * @return array<string,mixed> {@see lesen()} + `rezepte_neu_gerechnet`
     *
     * @throws NichtGefundenException|KeinPlatzhalterException|GeerbtNurLesenException|\RuntimeException
     */
    public function setzen(Team $team, int $gpId, array $eingabe, bool $rezepteRechnen = true): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new NichtGefundenException('Grundprodukt nicht sichtbar oder nicht vorhanden.');
        }
        if (! $gp->is_platzhalter) {
            throw new KeinPlatzhalterException((string) $gp->name);
        }
        GeerbtNurLesenException::wennNichtEigen($gp, $team, 'der Platzhalter');

        $update = [];

        // Allergene (Override-Ebene)
        $allergene = [];
        if (array_key_exists('alle_allergene', $eingabe) && $eingabe['alle_allergene'] !== null && $eingabe['alle_allergene'] !== '') {
            $allergene = array_fill_keys(FoodAlchemistGp::ALLERGEN_FIELDS, $eingabe['alle_allergene']);
        }
        foreach ((array) ($eingabe['allergene'] ?? []) as $feld => $wert) {
            $allergene[$feld] = $wert;
        }
        foreach ($allergene as $feld => $wert) {
            if (! in_array($feld, FoodAlchemistGp::ALLERGEN_FIELDS, true)) {
                throw new \RuntimeException("Unbekanntes Allergen „{$feld}“. Erlaubt: " . implode(', ', FoodAlchemistGp::ALLERGEN_FIELDS) . '.');
            }
            $wert = $wert === '' || $wert === 'unbekannt' ? null : $wert;
            if ($wert !== null && ! in_array($wert, self::ALLERGEN_WERTE, true)) {
                throw new \RuntimeException("Allergen {$feld}: „{$wert}“ ist kein Wert. Erlaubt: " . implode(', ', self::ALLERGEN_WERTE) . ' (leer = Feld leeren).');
            }
            $update["allergen_{$feld}"] = $wert;
        }

        // Zusatzstoffe (Hand-Ebene, nur Platzhalter)
        $zusatz = self::zusatzstoffeManuell($gp);
        $zusatzGeaendert = false;
        if (array_key_exists('alle_zusatzstoffe', $eingabe) && $eingabe['alle_zusatzstoffe'] !== null && $eingabe['alle_zusatzstoffe'] !== '') {
            $alle = $this->jaNein($eingabe['alle_zusatzstoffe'], 'alle_zusatzstoffe');
            foreach (array_keys(FoodAlchemistItemDeclaration::STOFFE) as $stoff) {
                $zusatz[$stoff] = $alle;
            }
            $zusatzGeaendert = true;
        }
        foreach ((array) ($eingabe['zusatzstoffe'] ?? []) as $stoff => $wert) {
            if (! in_array($stoff, self::stoffe(), true)) {
                throw new \RuntimeException("Unbekannter Zusatzstoff „{$stoff}“. Erlaubt: " . implode(', ', self::stoffe()) . '.');
            }
            $roh = $this->jaNein($wert, $stoff);
            if ($roh === null) {
                unset($zusatz[$stoff]);
            } else {
                $zusatz[$stoff] = $roh;
            }
            $zusatzGeaendert = true;
        }
        if ($zusatzGeaendert) {
            $update['deklaration_manuell'] = $zusatz === [] ? null : $zusatz;
        }

        // Nährwerte je 100 g (nutri_source = manual)
        if (array_key_exists('naehrwerte', $eingabe)) {
            foreach ((array) $eingabe['naehrwerte'] as $key => $wert) {
                if (! isset(self::NAEHRWERTE[$key])) {
                    throw new \RuntimeException("Unbekannter Nährwert „{$key}“. Erlaubt: " . implode(', ', array_keys(self::NAEHRWERTE)) . '.');
                }
                $zahl = $this->zahl($wert, $key);
                $update[self::NAEHRWERTE[$key]] = $zahl;
            }
        }

        if ($update === []) {
            throw new \RuntimeException('Nichts zu setzen: allergene, alle_allergene, zusatzstoffe, alle_zusatzstoffe oder naehrwerte angeben.');
        }

        $gp->fill($update);
        // Allergen-Quelle und Konfidenz-Metadaten nachziehen (die WERTE stehen in den Override-Spalten)
        if ($allergene !== []) {
            $gesetzt = count($gp->allergenOverrides());
            $gp->allergens_source = $gesetzt > 0 ? 'manual' : null;
            // Konfidenz NICHT als Zahl ablegen: sie wird live gerechnet (allergenKonfidenz → high bei 14 von 14). Eine gesetzte Zahl ohne Artikel
            // hielten Liste und Panel für eine KI-Schätzung (✨-Marker, GpService::zeilenAnreichern).
            $gp->allergens_confidence = null;
            $gp->allergens_aggregated_at = now();
        }
        if (array_key_exists('naehrwerte', $eingabe)) {
            $irgendeiner = collect(self::NAEHRWERTE)->contains(fn ($spalte) => $gp->getAttribute($spalte) !== null);
            $gp->nutri_source = $irgendeiner ? 'manual' : null;
            $gp->nutri_ai_confidence = null;
        }
        $gp->save();

        $n = $rezepteRechnen ? app(\Platform\FoodAlchemist\Services\Einkauf\LeadLaService::class)->recomputeNutzerFuerGps([(int) $gp->id]) : 0;

        return [...$this->lesen($gp->refresh()), 'rezepte_neu_gerechnet' => $n];
    }

    /** ja|nein|true|false|1|3 → 3|1, leer → null. */
    private function jaNein(mixed $wert, string $feld): ?int
    {
        return match (true) {
            $wert === null, $wert === '', $wert === 'unbekannt' => null,
            $wert === true, $wert === 'ja', $wert === 3, $wert === '3' => 3,
            $wert === false, $wert === 'nein', $wert === 1, $wert === '1' => 1,
            default => throw new \RuntimeException("{$feld}: „" . (is_scalar($wert) ? (string) $wert : gettype($wert)) . '“ ist kein Wert. Erlaubt: ja, nein (leer = Feld leeren).'),
        };
    }

    /** Zahl ≥ 0 (Komma erlaubt), leer → null. */
    private function zahl(mixed $wert, string $feld): ?float
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $text = is_string($wert) ? str_replace(',', '.', trim($wert)) : $wert;
        if (! is_numeric($text) || (float) $text < 0) {
            throw new \RuntimeException("Nährwert {$feld}: „" . (is_scalar($wert) ? (string) $wert : gettype($wert)) . '“ ist keine Zahl ≥ 0.');
        }

        return round((float) $text, 3);
    }
}
