<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;
use Platform\FoodAlchemist\Services\WordingResolver;

/**
 * Kennzeichnung und Ernährung im Speiseplan (98 · 3.3 S3, aus SpeiseplanService verschoben): Spec 31 Kennzeichnung
 * (Name, Codes, aufgelöste Gerichte je Eintrag, Wochen-Kennzeichnung, Kostformen-Abdeckung) und Stufe D (DGE-Nährwertbilanz,
 * Abwechslung), dazu die Diät-Merkmale. Hält den In-Request-Memo der aufgelösten Gerichte; die Fassade teilt eine Instanz
 * mit allen Teilen. Nutzt SpeiseplanBasis, nie die Fassade.
 */
final class SpeiseplanErnaehrung
{
    /**
     * Spec 31 (GV-Ausbau) — Kostformen, deren Tages-Abdeckung geprüft wird. Alle aus den
     * vorhandenen Diät-Spec-Flags am Rezept ableitbar (keine neuen Daten). „Passiert/püriert"
     * bewusst NICHT dabei — dafür gibt es (noch) kein Datenfeld, also nicht raten.
     */
    public const KOSTFORMEN = [
        'vegetarisch' => 'Vegetarisch',
        'vegan' => 'Vegan',
        'schweinefrei' => 'Schweinefleischfrei',
        'glutenfrei' => 'Glutenfrei',
        'laktosefrei' => 'Laktosefrei',
        'halal' => 'Halal',
    ];

    /** Konfidenz-Rang (schwächstes Glied) — lokal, da die Aggregat-Konstante privat ist. */
    private const KONF_RANG = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    /** In-Request-Memo: Eintrag-Inhalt (c{id}/p{id}/g{id}) → aufgelöste Gerichte-Sammlung. */
    private array $gerichteCache = [];

    public function __construct(
        private SpeiseplanBasis $basis,
    ) {}

    /**
     * Tage für die Wochen-Aggregate: explizite Anzahl (Alt-Signatur, ab Montag) oder — Standard —
     * die Öffnungstage des Plans.
     *
     * @return list<Carbon>
     */
    private function aggregatTage(FoodAlchemistSpeiseplan $plan, Carbon $montag, ?int $anzahl): array
    {
        if ($anzahl === null) {
            return $this->basis->wochenTage($plan, $montag);
        }
        $mo = $montag->copy()->startOfDay();

        return array_map(fn (int $i) => $mo->copy()->addDays($i), range(0, max(1, $anzahl) - 1));
    }

    /**
     * Diät-Merkmale aus vorhandenen Flags — ohne Raten. Geflügel/Lamm/Wild haben kein Datenfeld;
     * sie erscheinen als „fleisch“ (unbestimmt), nicht als geratene Tierart (Spec 57 · E3).
     * Spec 59: „fleisch“ nur bei BELEGTEM Fleisch (`fleisch_belegt` im Rollup). Gerichte ganz ohne
     * Diät-Pflege liefern `[]` (= ohne Angabe) — unbekannt ist nicht Fleisch.
     *
     * @return list<string>  vegan | vegetarisch | schwein | rind | fisch | fleisch
     */
    public function diaetMerkmale(array $roll, array $kennzeichnung): array
    {
        if (($roll['n_gerichte'] ?? 0) === 0) {
            return [];
        }
        if ($roll['is_vegan']) {
            return ['vegan'];
        }
        if ($roll['is_vegetarian']) {
            return ['vegetarisch'];
        }
        $out = [];
        if ($roll['contains_pork']) {
            $out[] = 'schwein';
        }
        if ($roll['contains_beef']) {
            $out[] = 'rind';
        }
        $fisch = collect($kennzeichnung['allergene'] ?? [])->contains(fn ($a) => $a['slug'] === 'fish' && $a['status'] === 'enthalten');
        if ($fisch) {
            $out[] = 'fisch';
        }

        // „Fleisch" nur, wenn es FESTSTEHT (mind. ein Gericht ausdrücklich nicht vegetarisch, oder
        // Schwein/Rind gepflegt). Unbekannte Diät-Angaben (NULL nach Recompute, GP ohne Tags) sind
        // kein Fleisch — vorher landeten Suppen und Desserts ohne Pflege als „Fleisch" auf dem Aushang.
        if ($out === [] && ($roll['fleisch_belegt'] ?? false)) {
            $out[] = 'fleisch';
        }

        return $out;
    }

    /**
     * Löst einen Speiseplan-Eintrag (Concept/Paket/Gericht) auf seine tatsächlichen Gerichte
     * (Verkaufsrezepte) auf — Basis für Kennzeichnungs- und Diät-Rollups. Gleiche Sammel-Logik
     * wie {@see ConceptService::allergenRollup} (slots→package→dishes + slot→dish). In-Request
     * memoisiert, damit derselbe Inhalt in einer Woche nicht mehrfach geladen wird.
     *
     * @return Collection<int, FoodAlchemistRecipe>
     */
    /**
     * Anzeigename eines Eintrags fürs Kunden-/Aushang-Dokument: Gericht über die Wording-Kette
     * (saubere Kunden-Namen, ohne interne [HG]/[KAE]-Marker), Paket/Concept behalten ihren Namen.
     */
    public function eintragName(FoodAlchemistSpeiseplanEintrag $e): string
    {
        // Eigenes Wording des Eintrags (Hand oder KI) vor der Wording-Kette des Gerichts.
        if (trim((string) $e->wording) !== '') {
            return trim((string) $e->wording);
        }
        if ($e->sales_recipe_id !== null) {
            $dish = $e->relationLoaded('dish') ? $e->dish : $e->dish()->first();
            if ($dish !== null) {
                return app(WordingResolver::class)->fuerGericht($dish)['text'] ?? $e->inhaltName();
            }
        }

        return $e->inhaltName();
    }

    /**
     * LMIV-Codes EINES Eintrags (Allergen-Buchstaben, `*` = Spuren, Zusatzstoff-Nummern) aus dem
     * Kennzeichnungs-Rollup seiner Gerichte. Sammelt die vorkommenden Slugs by-ref für die Legende.
     * Eine Stelle für Aushang ({@see dokumentDaten}) und Zellen-Kennzahlen.
     *
     * @return list<string>
     */
    public function eintragCodes(FoodAlchemistSpeiseplanEintrag $e, array &$usedAlg = [], array &$usedZus = []): array
    {
        $agg = app(ConcepterAggregateService::class);
        $katalog = $agg->kennzeichnungKatalog();
        $k = $agg->kennzeichnungFromGerichte($this->eintragGerichte($e));
        $codes = [];
        foreach ($k['allergene'] as $a) {
            if ($a['status'] === 'enthalten' || $a['status'] === 'spuren') {
                $usedAlg[$a['slug']] = true;
                $codes[] = $katalog['allergene'][$a['slug']]['code'] . ($a['status'] === 'spuren' ? '*' : '');
            }
        }
        foreach ($k['zusatzstoffe'] as $z) {
            if ($z['status'] === 'ja') {
                $usedZus[$z['slug']] = true;
                $codes[] = $katalog['zusatzstoffe'][$z['slug']]['code'];
            }
        }

        return $codes;
    }

    public function eintragGerichte(FoodAlchemistSpeiseplanEintrag $e): Collection
    {
        $key = $e->inhaltKey();
        if ($key === null) {
            return collect();
        }
        if (isset($this->gerichteCache[$key])) {
            return $this->gerichteCache[$key];
        }

        $gerichte = collect();
        if ($e->sales_recipe_id !== null) {
            $dish = FoodAlchemistRecipe::find($e->sales_recipe_id);
            $gerichte = $dish ? collect([$dish]) : collect();
        } elseif ($e->package_id !== null) {
            $pkg = FoodAlchemistPaket::with('dishes.gericht')->find($e->package_id);
            $gerichte = $pkg ? $pkg->dishes->pluck('gericht')->filter() : collect();
        } elseif ($e->concept_id !== null) {
            $c = FoodAlchemistConcept::with(['slots.package.dishes.gericht', 'slots.dish'])->find($e->concept_id);
            if ($c !== null) {
                foreach ($c->slots as $slot) {
                    if ($slot->package) {
                        $gerichte = $gerichte->merge($slot->package->dishes->pluck('gericht')->filter());
                    }
                    if ($slot->dish) {
                        $gerichte->push($slot->dish);
                    }
                }
            }
        }

        return $this->gerichteCache[$key] = $gerichte->filter()->unique('id')->values();
    }

    /**
     * LMIV-Kennzeichnung (14 Allergene + 18 Zusatzstoffe) je Werktag der sichtbaren Woche +
     * Wochen-Rollup, ALL-MAXIMAL über alle Gerichte des Tages. Für Rail-Übersicht + Aushang.
     *
     * @return array{pro_tag: array<string, array>, woche: array}
     */
    public function wochenKennzeichnung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $proTag = [];
        $wocheGerichte = collect();
        foreach ($this->aggregatTage($plan, $montag, $tage) as $tag) {
            $tagGerichte = collect();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                $tagGerichte = $tagGerichte->merge($this->eintragGerichte($e));
            }
            $tagGerichte = $tagGerichte->filter()->unique('id')->values();
            $proTag[$tag->format('Y-m-d')] = $agg->kennzeichnungFromGerichte($tagGerichte);
            $wocheGerichte = $wocheGerichte->merge($tagGerichte);
        }

        return ['pro_tag' => $proTag, 'woche' => $agg->kennzeichnungFromGerichte($wocheGerichte->unique('id')->values())];
    }

    /**
     * Nährwert-Wochenbilanz — Ø je Person und Werktag (kcal/Eiweiß/Fett/ges.Fett/Salz/Zucker/KH).
     * Reuse {@see ConcepterAggregateService::naehrwertAggregat}: je Gericht 1 Portion/Person
     * (GV-Modell „eine Komponente = eine Portion"). Gemittelt über Werktage MIT Nährwertdaten.
     *
     * @return array{schnitt: array<string,?float>, tage_mit_daten:int, confidence:string}
     */
    public function wochenNaehrwerte(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $felder = ['kcal', 'protein_g', 'fett_g', 'gesfett_g', 'salz_g', 'zucker_g', 'kh_g'];
        $summe = array_fill_keys($felder, 0.0);
        $nTage = 0;
        $konfRang = null;

        foreach ($this->aggregatTage($plan, $montag, $tage) as $tag) {
            $rows = collect();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                foreach ($this->eintragGerichte($e) as $g) {
                    $rows->push(['gericht' => $g, 'quantity' => 1, 'unit' => null]);
                }
            }
            if ($rows->isEmpty()) {
                continue;
            }
            $n = $agg->naehrwertAggregat($rows);
            if (($n['n_mit_naehrwerten'] ?? 0) === 0) {
                continue;
            }
            $nTage++;
            foreach ($felder as $f) {
                $summe[$f] += (float) ($n[$f] ?? 0);
            }
            $rang = self::KONF_RANG[$n['confidence']] ?? 0;
            $konfRang = $konfRang === null ? $rang : min($konfRang, $rang);
        }

        $schnitt = array_fill_keys($felder, null);
        if ($nTage > 0) {
            foreach ($felder as $f) {
                $roh = $summe[$f] / $nTage;
                $schnitt[$f] = $f === 'kcal' ? round($roh) : ($f === 'salz_g' ? round($roh, 2) : round($roh, 1));
            }
        }

        return [
            'schnitt' => $schnitt,
            'tage_mit_daten' => $nTage,
            'confidence' => $nTage === 0 ? 'unknown' : (array_search($konfRang ?? 0, self::KONF_RANG, true) ?: 'unknown'),
        ];
    }

    /**
     * Abwechslung/Häufigkeit der Woche: Diät-Mix je serviertem GERICHT + Warengruppen-Häufigkeit
     * (dish_main_group) + Spec 59 Plan-Vorgaben (mind./höchstens je Chip). Weicher Hinweis, wenn
     * eine Warengruppe die Woche dominiert (≥ $tage Vorkommen). Alles aus vorhandenen Feldern.
     *
     * Spec 59: Diät je Gericht über denselben Rollup wie die Zellen-Chips ({@see diaetMerkmale}).
     * Vorher zählte alles Nicht-Vegane/-Vegetarische als „mit Fleisch oder Fisch“ — auch Gerichte
     * ganz ohne Diät-Pflege. Jetzt: Fleisch nur belegt, Rest unter `ohne_angabe`.
     * Zählbasis = Gericht-Vorkommen (ein Paket/Concept mit 3 Gerichten zählt 3).
     * `diaet_eintraege`/`wg_eintraege`/`treffer` liefern die Eintrag-Ids fürs Hervorheben in der Matrix.
     *
     * @return array{diaet: array{vegan:int, vegetarisch:int, fleisch:int, fisch:int, schwein:int, rind:int, ohne_angabe:int, omnivor:int},
     *               warengruppen: list<array{id:int, name:string, count:int}>, hinweis: ?string,
     *               vorgaben: list<array>, treffer: array<int, list<int>>,
     *               diaet_eintraege: array<string, list<int>>, wg_eintraege: array<int, list<int>>}
     */
    public function wochenAbwechslung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $diaetKeys = ['vegan', 'vegetarisch', 'fleisch', 'fisch', 'schwein', 'rind', 'ohne_angabe'];
        $diaet = array_fill_keys($diaetKeys, 0);
        $omni = 0;
        $diaetEintraege = array_fill_keys($diaetKeys, []);
        $wg = [];            // dish_main_group_id => count
        $wgEintraege = [];   // dish_main_group_id => [entry_id => true]
        $paare = [];         // je Gericht-Vorkommen: Merkmale für die Vorgaben-Prüfung
        $merkmaleCache = [];
        $tageListe = $this->aggregatTage($plan, $montag, $tage);
        $tage = count($tageListe);
        foreach ($tageListe as $tag) {
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                foreach ($this->eintragGerichte($e) as $g) {
                    $gc = collect([$g]);
                    $m = $merkmaleCache[$g->id] ??= $this->diaetMerkmale($agg->allergenRollupFromGerichte($gc), $agg->kennzeichnungFromGerichte($gc));
                    // Schwein/Rind sind Fleisch — für Zählung und Chip „Fleisch“.
                    if (array_intersect($m, ['schwein', 'rind']) !== [] && ! in_array('fleisch', $m, true)) {
                        $m[] = 'fleisch';
                    }
                    $schluessel = $m === [] ? ['ohne_angabe'] : $m;
                    foreach ($schluessel as $k) {
                        $diaet[$k]++;
                        $diaetEintraege[$k][$e->id] = true;
                    }
                    if (in_array('fleisch', $m, true) || in_array('fisch', $m, true)) {
                        $omni++;
                    }
                    $gid = $g->dish_main_group_id !== null ? (int) $g->dish_main_group_id : null;
                    if ($gid !== null) {
                        $wg[$gid] = ($wg[$gid] ?? 0) + 1;
                        $wgEintraege[$gid][$e->id] = true;
                    }
                    $paare[] = ['entry_id' => (int) $e->id, 'gericht_id' => (int) $g->id, 'diaet' => $m, 'hauptgruppe' => $gid];
                }
            }
        }

        // Warengruppen-Namen in EINER Query auflösen.
        $namen = [];
        if ($wg !== []) {
            // Spec 84 Stufe 4: gespeicherte Verweise der Gerichte (auch Abo-Gerichte mit Master-HG) — Label wie die Relation ('verweis')
            $namen = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::ohneTeamSicht('verweis')->whereIn('id', array_keys($wg))
                ->pluck('label', 'id')->all();
        }
        arsort($wg);
        $warengruppen = [];
        $dominant = null;
        foreach ($wg as $gid => $count) {
            $name = $namen[$gid] ?? ('#' . $gid);
            $warengruppen[] = ['id' => (int) $gid, 'name' => $name, 'count' => $count];
            if ($dominant === null && $count >= $tage) {
                $dominant = $name;
            }
        }
        $vorgaben = app(SpeiseplanVorgabenService::class)->auswerten($plan, $mahlzeit, $paare);

        return [
            // `omnivor` (= Gerichte mit Fleisch ∪ Fisch) bleibt für Altverwender.
            'diaet' => $diaet + ['omnivor' => $omni],
            'warengruppen' => array_slice($warengruppen, 0, 6),
            'hinweis' => $dominant !== null ? 'Warengruppe „' . $dominant . '" dominiert die Woche — mehr Abwechslung erwägen.' : null,
            'vorgaben' => $vorgaben['vorgaben'],
            'treffer' => $vorgaben['treffer'],
            'diaet_eintraege' => array_map('array_keys', $diaetEintraege),
            'wg_eintraege' => array_map('array_keys', $wgEintraege),
        ];
    }

    /**
     * Kostformen-Abdeckung: hat jeder Werktag in der gewählten Mahlzeit mindestens EINEN Eintrag,
     * der die jeweilige Kostform erfüllt? Verallgemeinert den linien-basierten {@see veggieCheck}
     * auf die tatsächliche Rezept-Diät (Diät-Flag-Rollup je Eintrag, ein Eintrag genügt/Tag).
     *
     * @return list<array{key:string, label:string, erfuellt:bool, fehltage:list<string>, abgedeckt:int, tage:int}>
     */
    public function kostformAbdeckung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $fehl = array_fill_keys(array_keys(self::KOSTFORMEN), []);
        $tageListe = $this->aggregatTage($plan, $montag, $tage);
        $tage = count($tageListe);

        foreach ($tageListe as $tag) {
            $tagErfuellt = array_fill_keys(array_keys(self::KOSTFORMEN), false);
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                $roll = $agg->allergenRollupFromGerichte($this->eintragGerichte($e));
                foreach (self::KOSTFORMEN as $k => $_) {
                    if (! $tagErfuellt[$k] && $this->kostformErfuellt($k, $roll)) {
                        $tagErfuellt[$k] = true;
                    }
                }
            }
            foreach (self::KOSTFORMEN as $k => $_) {
                if (! $tagErfuellt[$k]) {
                    $fehl[$k][] = $tag->format('Y-m-d');
                }
            }
        }

        $out = [];
        foreach (self::KOSTFORMEN as $k => $label) {
            $out[] = [
                'key' => $k, 'label' => $label,
                'erfuellt' => $fehl[$k] === [], 'fehltage' => $fehl[$k],
                'abgedeckt' => $tage - count($fehl[$k]), 'tage' => $tage,
            ];
        }

        return $out;
    }

    /** Erfüllt der Diät-Flag-Rollup eines Eintrags die Kostform? (leerer Eintrag erfüllt nichts) */
    private function kostformErfuellt(string $key, array $roll): bool
    {
        if (($roll['n_gerichte'] ?? 0) === 0) {
            return false;
        }

        return match ($key) {
            'vegetarisch' => (bool) $roll['is_vegetarian'],
            'vegan' => (bool) $roll['is_vegan'],
            'schweinefrei' => ! $roll['contains_pork'],
            'glutenfrei' => (bool) $roll['is_gluten_free'],
            'laktosefrei' => (bool) $roll['is_lactose_free'],
            'halal' => (bool) $roll['is_halal'],
            default => false,
        };
    }
}
