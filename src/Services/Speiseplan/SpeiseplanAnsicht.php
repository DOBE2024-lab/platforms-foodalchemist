<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;
use Platform\FoodAlchemist\Services\Preis\CatalogPricingService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\Preis\MargeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Ansicht des Speiseplans (98 · 3.3 S3, aus SpeiseplanService verschoben): Wochen-Matrix und Monats-Kalender,
 * Preis und Darreichung je Eintrag, Wochenkosten, Zelle „auf einen Blick“ (Spec 57 Paket 1+2) mit Zielband, WES-Status
 * und Budget-Ampel. Nutzt SpeiseplanBasis und SpeiseplanErnaehrung, nie die Fassade.
 */
final class SpeiseplanAnsicht
{
    public function __construct(
        private ConceptService $concepts,
        private SpeiseplanBasis $basis,
        private SpeiseplanErnaehrung $ernaehrung,
    ) {}

    /**
     * Wochen-Matrix einer Mahlzeit: [line_id][Y-m-d] => list<Eintrag> (Mo..So ab $montag).
     * Einträge ohne Linie laufen unter Key 0 (»Ohne Linie«).
     *
     * @return array<int, array<string, list<FoodAlchemistSpeiseplanEintrag>>>
     */
    public function wochenRaster(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $grid = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($start, $ende)) {
                continue;
            }
            $grid[(int) $e->line_id][$e->entry_date->format('Y-m-d')][] = $e;
        }

        return $grid;
    }

    /**
     * Monats-Belegung: [Y-m-d] => {count, vk} (optional auf eine Mahlzeit gefiltert).
     *
     * @return array<string, array{count:int, vk:float}>
     */
    public function monatsRaster(FoodAlchemistSpeiseplan $plan, int $jahr, int $monat, ?string $mahlzeit = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $out = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || (int) $e->entry_date->year !== $jahr || (int) $e->entry_date->month !== $monat) {
                continue;
            }
            if ($mahlzeit !== null && $e->meal !== $mahlzeit) {
                continue;
            }
            $key = $e->entry_date->format('Y-m-d');
            $p = $this->eintragPreis($e, $outlet);
            $out[$key]['count'] = ($out[$key]['count'] ?? 0) + 1;
            $out[$key]['vk'] = round(($out[$key]['vk'] ?? 0) + $p['vk'], 2);
        }

        return $out;
    }

    /** Per-Person-Preis eines Eintrags (Concept/Paket/Gericht). @return array{vk: float, ek: float} */
    public function eintragPreis(FoodAlchemistSpeiseplanEintrag $e, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $preis = $this->inhaltPreis($e, $outlet);
        // Spec 57 · E1: eine Linie mit `price_mode=manuell` setzt den Ausgabepreis (Anzeige und
        // Kennzahlen). Der EK bleibt der des Inhalts; das Gericht behält seinen eigenen VK.
        $manuell = $e->line_id !== null ? $e->line?->manuellerPreis() : null;
        if ($manuell !== null) {
            $preis = ['vk' => $manuell, 'vorlaeufig' => false] + $preis;   // Linienpreis gesetzt, nicht summiert
        }

        return $preis;
    }

    /** VK/EK je Person aus dem Inhalt selbst (Concept/Paket/Gericht), ohne Linienpreis. @return array{vk: float, ek: float} */
    private function inhaltPreis(FoodAlchemistSpeiseplanEintrag $e, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        if ($e->concept_id !== null && $e->concept) {
            $c = $this->concepts->preisCockpit($e->concept, $outlet);

            return ['vk' => (float) $c['price_per_person'], 'ek' => (float) $c['ek_per_person'], 'vorlaeufig' => \Platform\FoodAlchemist\Services\ConceptPreisService::unvollstaendig($c)];
        }
        if ($e->sales_recipe_id !== null && $e->dish) {
            $dar = $this->eintragDarreichung($e);
            $vk = null;
            if ($dar !== null && $e->presentation_id !== null && (int) $dar->id === (int) $e->presentation_id) {
                // Explizit gewählte Form: deren VK (im Betrieb gegen dessen Kostenstruktur).
                $vk = $outlet !== null
                    ? app(CatalogPricingService::class)->salesNetFor(Team::find($outlet->team_id), $dar, $outlet)
                    : ($dar->sales_net !== null ? (float) $dar->sales_net : null);
            }
            $resolver = app(DarreichungResolver::class);   // Spec 85 D-1: Preisleiter; im Betrieb dessen VK, sonst die Baseline
            $vk ??= (float) (($outlet !== null ? $resolver->vkNetto($e->dish, $outlet) : null) ?? $resolver->vkNetto($e->dish) ?? 0);

            // EK JE PORTION: ek_portion der geltenden Darreichung. `ek_total_eur` ist der Ansatz
            // (alle Portionen) — bis 2026-10-07 stand er hier ungeteilt und blähte WE-% und EK/Gast auf.
            // Fallback ohne Darreichung: Ansatz ÷ Portionen, ohne Portionszahl = eine Portion —
            // dieselbe Konvention wie DarreichungService::recomputePreise (gemessen 2026-10-07:
            // 950 von 950 Gerichten ohne sales_unit_count, bei 837 ist Portions-EK = Ansatz-EK).
            $ek = $dar?->ek_portion !== null
                ? (float) $dar->ek_portion
                : (float) ($e->dish->ek_total_eur ?? 0) / max(1, (int) ($e->dish->sales_unit_count ?? 1));

            return ['vk' => (float) $vk, 'ek' => $ek];
        }

        return ['vk' => 0.0, 'ek' => 0.0];
    }

    /** Geltende Darreichung eines Gericht-Eintrags (explizit → Standard), null bei Concept/Paket. */
    public function eintragDarreichung(FoodAlchemistSpeiseplanEintrag $e): ?FoodAlchemistRecipeDarreichung
    {
        return app(DarreichungResolver::class)->fuerSpeiseplanEintrag($e);
    }

    /**
     * Kosten/Person der sichtbaren Woche+Mahlzeit: je Tag und Wochensumme.
     *
     * @return array{pro_tag: array<string,array{vk:float,ek:float}>, woche: array{vk:float,ek:float}}
     */
    public function wochenKosten(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $proTag = [];
        $wVk = 0.0;
        $wEk = 0.0;
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($start, $ende)) {
                continue;
            }
            $p = $this->eintragPreis($e, $outlet);
            $k = $e->entry_date->format('Y-m-d');
            $proTag[$k]['vk'] = round(($proTag[$k]['vk'] ?? 0) + $p['vk'], 2);
            $proTag[$k]['ek'] = round(($proTag[$k]['ek'] ?? 0) + $p['ek'], 2);
            $wVk += $p['vk'];
            $wEk += $p['ek'];
        }

        return ['pro_tag' => $proTag, 'woche' => ['vk' => round($wVk, 2), 'ek' => round($wEk, 2)]];
    }

    /**
     * Zielband Wareneinsatz einer Linie in %. Ohne gepflegtes Band gilt das Team-/Betriebs-Ziel
     * als Obergrenze (keine Untergrenze) — dieselbe Leiter wie {@see MargeService::weAmpel}.
     *
     * @return array{min:?float, max:float, quelle:string}
     */
    public function zielband(?FoodAlchemistSpeiseplanLinie $linie, float $teamZiel): array
    {
        $min = $linie?->target_wes_min_pct;
        $max = $linie?->target_wes_max_pct;
        if ($min === null && $max === null) {
            return ['min' => null, 'max' => $teamZiel, 'quelle' => 'team'];
        }

        return ['min' => $min, 'max' => $max ?? $teamZiel, 'quelle' => 'linie'];
    }

    /**
     * Ampel für einen Wareneinsatz gegen ein Band: `unter` (unter der Untergrenze, nur Hinweis) ·
     * `ok` · `ueber` (bis 1,5 × Obergrenze) · `weit_ueber` · `unbekannt` (kein VK — nie geraten).
     */
    public function wesStatus(?float $wes, array $band): string
    {
        if ($wes === null) {
            return 'unbekannt';
        }
        if ($band['min'] !== null && $wes < (float) $band['min']) {
            return 'unter';
        }
        if ($wes <= (float) $band['max']) {
            return 'ok';
        }

        return $wes > (float) $band['max'] * 1.5 ? 'weit_ueber' : 'ueber';
    }

    /**
     * Kennzahlen der sichtbaren Woche für die Matrix: je Eintrag (Titel/Wording, Diät, LMIV-Codes,
     * VK, EK, Wareneinsatz gegen das Zielband der Linie, Pax, kcal) und je Öffnungstag (Gäste =
     * Essen auf Hauptgang-Linien, Umsatz, Wareneinsatz, EK je Gast), dazu je Linie die Wochen-Quote.
     * Rechnet nichts neu: Preis über {@see eintragPreis}, Pax über {@see effektivePax}, Diät und
     * Kennzeichnung über den {@see ConcepterAggregateService}.
     *
     * @return array{eintraege: array<int, array>, tage: array<string, array>, linien: array<int, array>, team_ziel: float, gaeste_aus_rollen: bool}
     */
    public function zellenKennzahlen(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitKomponenten = false): array
    {
        $agg = app(ConcepterAggregateService::class);
        $teamZiel = app(TeamSettingsService::class)->zielWareneinsatzPct($team, $outlet);
        $linienById = $plan->lines->keyBy(fn ($l) => (int) $l->id);
        $hatRollen = $plan->lines->contains(fn ($l) => $l->istHauptgang());

        $tage = [];
        foreach ($this->basis->wochenTage($plan, $montag) as $tag) {
            $tage[$tag->format('Y-m-d')] = ['gaeste' => 0, 'portionen' => 0, 'umsatz' => 0.0, 'ek' => 0.0, 'ek_we' => 0.0, 'ohne_preis' => 0];
        }
        $linienWoche = [];
        $eintraege = [];

        foreach ($this->wochenRaster($plan, $mahlzeit, $montag) as $lineId => $proTag) {
            $linie = $linienById->get((int) $lineId);
            $band = $this->zielband($linie, $teamZiel);
            foreach ($proTag as $ymd => $liste) {
                foreach ($liste as $e) {
                    $preis = $this->eintragPreis($e, $outlet);
                    $pax = $this->basis->effektivePax($e, $plan);
                    // Wareneinsatz nur, wo ein VK steht: ein Eintrag ohne Preis hätte EK ohne Umsatz
                    // (Bananenbrot ohne Preis ⇒ 16.100 % am Tag). Sein EK zählt trotzdem in die EK-Summe.
                    $mitPreis = $preis['vk'] > 0;
                    $wes = MargeService::wareneinsatzPct($preis['ek'], $preis['vk']);
                    $gerichte = $this->ernaehrung->eintragGerichte($e);
                    [$titel, $untertitel] = $this->titelUndWording($e, $gerichte);
                    $naehr = $gerichte->isNotEmpty()
                        ? $agg->naehrwertAggregat($gerichte->map(fn ($g) => ['gericht' => $g, 'quantity' => 1, 'unit' => null]))
                        : null;

                    $eintraege[(int) $e->id] = [
                        'id' => (int) $e->id,
                        'typ' => $e->concept_id !== null ? 'concept' : ($e->package_id !== null ? 'paket' : 'gericht'),
                        'titel' => $titel,
                        'untertitel' => $untertitel,
                        'diaet' => $this->ernaehrung->diaetMerkmale($agg->allergenRollupFromGerichte($gerichte), $agg->kennzeichnungFromGerichte($gerichte)),
                        'codes' => $this->ernaehrung->eintragCodes($e),
                        'vk' => round($preis['vk'], 2), 'vk_vorlaeufig' => (bool) ($preis['vorlaeufig'] ?? false),
                        'ek' => round($preis['ek'], 2),
                        'linienpreis' => $linie?->manuellerPreis() !== null,
                        'wes' => $wes,
                        'band' => $band,
                        'status' => $this->wesStatus($wes, $band),
                        'pax' => $pax,
                        'pax_override' => (int) $e->pax > 0,
                        'kcal' => $naehr['kcal'] ?? null,
                        'portion_g' => $this->portionG($e, $gerichte),
                        // Nur eine EIGENS gewählte Form wird an der Zelle genannt; Standard bleibt stumm.
                        // Die Grammatur (portion_g) zeigt die Zelle immer — sie folgt der geltenden Form.
                        'darreichung' => $e->presentation_id !== null ? $this->eintragDarreichung($e)?->servingForm?->label : null,
                        'komponenten' => $mitKomponenten ? $this->komponenten($e, $gerichte) : [],
                    ];

                    if (isset($tage[$ymd])) {
                        $tage[$ymd]['umsatz'] += $preis['vk'] * $pax;
                        $tage[$ymd]['ek'] += $preis['ek'] * $pax;
                        $tage[$ymd]['ek_we'] += $mitPreis ? $preis['ek'] * $pax : 0.0;
                        $tage[$ymd]['ohne_preis'] += $mitPreis ? 0 : 1;
                        $tage[$ymd]['portionen'] += $pax;
                        if ($linie?->istHauptgang()) {
                            $tage[$ymd]['gaeste'] += $pax;
                        }
                        $linienWoche[(int) $lineId]['umsatz'] = ($linienWoche[(int) $lineId]['umsatz'] ?? 0) + $preis['vk'] * $pax;
                        $linienWoche[(int) $lineId]['ek'] = ($linienWoche[(int) $lineId]['ek'] ?? 0) + $preis['ek'] * $pax;
                        $linienWoche[(int) $lineId]['ek_we'] = ($linienWoche[(int) $lineId]['ek_we'] ?? 0) + ($mitPreis ? $preis['ek'] * $pax : 0.0);
                    }
                }
            }
        }

        foreach ($tage as $ymd => $t) {
            $tage[$ymd]['umsatz'] = round($t['umsatz'], 2);
            $tage[$ymd]['ek'] = round($t['ek'], 2);
            $tage[$ymd]['wes'] = MargeService::wareneinsatzPct($t['ek_we'], $t['umsatz']);
            $tage[$ymd]['status'] = $this->wesStatus($tage[$ymd]['wes'], ['min' => null, 'max' => $teamZiel]);
            $tage[$ymd]['ek_je_gast'] = $t['gaeste'] > 0 ? round($t['ek'] / $t['gaeste'], 2) : null;
        }

        $linien = [];
        foreach ($plan->lines as $l) {
            $w = ($linienWoche[(int) $l->id] ?? []) + ['umsatz' => 0.0, 'ek' => 0.0, 'ek_we' => 0.0];
            $band = $this->zielband($l, $teamZiel);
            $wes = MargeService::wareneinsatzPct($w['ek_we'], $w['umsatz']);
            $linien[(int) $l->id] = ['name' => $l->name, 'color' => $l->color, 'band' => $band, 'wes' => $wes, 'status' => $this->wesStatus($wes, $band)];
        }

        $wUmsatz = array_sum(array_column($tage, 'umsatz'));
        $wEk = array_sum(array_column($tage, 'ek'));
        $wEkWe = array_sum(array_column($tage, 'ek_we'));
        $wWes = MargeService::wareneinsatzPct($wEkWe, $wUmsatz);
        $woche = [
            'umsatz' => round($wUmsatz, 2), 'ek' => round($wEk, 2), 'wes' => $wWes,
            'status' => $this->wesStatus($wWes, ['min' => null, 'max' => $teamZiel]),
            'portionen' => (int) array_sum(array_column($tage, 'portionen')),
            'gaeste' => (int) array_sum(array_column($tage, 'gaeste')),
            'ohne_preis' => (int) array_sum(array_column($tage, 'ohne_preis')),
        ];

        return ['eintraege' => $eintraege, 'tage' => $tage, 'linien' => $linien, 'woche' => $woche, 'team_ziel' => $teamZiel, 'gaeste_aus_rollen' => $hatRollen];
    }

    /**
     * Wareneinsatz-Budget-Ampel (Spec 57 · E2): Ø EK je GAST und Tag gegen das Budget (€/Person).
     * Gäste = Essen auf Hauptgang-Linien. Hat kein Plan-Tag Hauptgang-Gäste (Linien ohne Rolle),
     * gilt das Altmodell „Summe je Person über alle Linien“ — ausgewiesen in `basis`.
     *
     * @return ?array{avg:float, budget:float, ueber_tage:int, ampel:string, basis:string}
     */
    public function budgetAmpel(FoodAlchemistSpeiseplan $plan, array $kennzahlen, array $kosten): ?array
    {
        if (! $plan->budget_wareneinsatz) {
            return null;
        }
        $budget = (float) $plan->budget_wareneinsatz;
        $jeGast = collect($kennzahlen['tage'] ?? [])->pluck('ek_je_gast')->filter(fn ($v) => $v !== null);
        if ($jeGast->isNotEmpty()) {
            $werte = $jeGast;
            $basis = 'je_gast';
        } else {
            $werte = collect($kosten['pro_tag'] ?? [])->pluck('ek');
            $basis = 'summe_linien';
        }
        if ($werte->isEmpty()) {
            return null;
        }
        $avg = round((float) $werte->avg(), 2);
        $ueber = $werte->filter(fn ($v) => (float) $v > $budget)->count();

        return [
            'avg' => $avg, 'budget' => $budget, 'ueber_tage' => $ueber, 'basis' => $basis,
            'ampel' => $avg > $budget ? 'danger' : ($ueber > 0 ? 'warning' : 'success'),
        ];
    }

    /**
     * Portionsgewicht der Zelle — dieselbe Regel wie der Portions-EK in
     * DarreichungService::recomputePreise, damit Grammatur und Preis zusammenpassen:
     * Grammatur der geltenden Form × Anzahl; fehlt sie an der STANDARD-Form, Rezept-Grammatur,
     * sonst Ertrag ÷ Portionen (mind. 1). Ohne Form: Rezept-Grammatur bzw. Ertrag ÷ Portionen (mind. 1).
     */
    private function portionG(FoodAlchemistSpeiseplanEintrag $e, Collection $gerichte): ?float
    {
        $dar = $this->eintragDarreichung($e);
        $anzahl = (float) ($dar?->unit_count ?: 1);
        if ($dar !== null && (float) ($dar->quantity_per_unit_g ?? 0) > 0) {
            return round((float) $dar->quantity_per_unit_g * $anzahl, 1);
        }
        $dish = $e->dish;
        if ($dish === null) {
            return $gerichte->sum(fn ($g) => (float) ($g->sales_quantity_per_unit_g ?? 0)) ?: null;
        }
        if ((float) ($dish->sales_quantity_per_unit_g ?? 0) > 0) {
            return round((float) $dish->sales_quantity_per_unit_g * $anzahl, 1);
        }
        if ($dish->yield_kg === null || (float) $dish->yield_kg <= 0) {
            return null;
        }
        if ($dar !== null) {
            // Nur die Standard-Form fällt auf den Ertrag zurück (wie recomputePreise); andere Formen
            // ohne Grammatur haben auch keinen Portions-EK.
            return $dar->is_standard ? round((float) $dish->yield_kg * 1000 / max(1, (int) ($dish->sales_unit_count ?? 0)) * $anzahl, 1) : null;
        }
        // Ohne Form: dieselbe Konvention wie der EK — Ansatz ÷ Portionen, ohne Portionszahl eine Portion.
        return round((float) $dish->yield_kg * 1000 / max(1, (int) ($dish->sales_unit_count ?? 0)), 1);
    }

    /**
     * Titel + Wording für die Zelle. Gericht: Wording-Kette ({@see eintragName}); der erste
     * Pipe-Teil wird Titel, der Rest Untertitel („Kürbissuppe | Ingwer | Kernöl“). Concept/Paket:
     * Name + Anzahl Gerichte.
     *
     * @return array{0:string, 1:?string}
     */
    private function titelUndWording(FoodAlchemistSpeiseplanEintrag $e, Collection $gerichte): array
    {
        $name = $this->ernaehrung->eintragName($e);
        if ($e->sales_recipe_id !== null) {
            $teile = array_values(array_filter(array_map('trim', explode('|', $name)), fn ($t) => $t !== ''));
            if (count($teile) > 1) {
                return [$teile[0], implode(' · ', array_slice($teile, 1))];
            }

            return [$name, null];
        }
        $typ = $e->concept_id !== null ? 'Konzept' : 'Paket';
        $n = $gerichte->count();

        return [$name, $typ . ($n > 0 ? ' · ' . $n . ' Gericht' . ($n === 1 ? '' : 'e') : '')];
    }

    /**
     * Komponenten für die Detail-Dichte: beim Gericht die Zutaten (Name, Menge, Einheit), bei
     * Concept/Paket die enthaltenen Gerichte. Nur auf Anforderung geladen (eine Abfrage je Gericht).
     *
     * @return list<array{name:string, menge:?string}>
     */
    private function komponenten(FoodAlchemistSpeiseplanEintrag $e, Collection $gerichte): array
    {
        if ($e->sales_recipe_id === null) {
            return $gerichte->map(fn ($g) => ['name' => (string) $g->name, 'menge' => null])->values()->all();
        }
        $dish = $gerichte->first();
        if ($dish === null) {
            return [];
        }

        return $dish->ingredients()->with(['gp:id,name,zusatz', 'referencedRecipe:id,name', 'unit:id,slug'])->limit(12)->get()
            ->map(fn ($z) => [
                'name' => (string) ($z->display_name ?: ($z->gp !== null ? app(GpNamingService::class)->nameOhneMarke((string) $z->gp->name, $z->gp->zusatz) : ($z->referencedRecipe?->name ?? $z->raw_text))),   // Kundendokument: ohne Marken-Klammer
                'menge' => $z->quantity !== null
                    ? rtrim(rtrim(number_format((float) $z->quantity, 2, ',', ''), '0'), ',') . ' ' . ($z->unit?->slug ?? '')
                    : null,
            ])->values()->all();
    }
}
