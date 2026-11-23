<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanBasis;

/**
 * Vorlage für Betriebe und Plan/Ist (98 · 3.3 S3, aus SpeiseplanService verschoben): Spec 57 Paket 7 (Plan als
 * Vorlage markieren, Betriebs-Kopien listen, Abgleich Kopie ↔ Vorlage) und Paket 8 (Plan/Ist aus vorhandenen
 * Verkaufsdaten, nur lesend). betriebsKopieAnlegen und ausVorlageUebernehmen bleiben in der Fassade: sie bauen auf
 * dupliziere bzw. den Linien-Feldern des Kerns. Nutzt Basis, Ernährung und Ansicht, nie die Fassade.
 */
final class SpeiseplanVorlage
{
    public function __construct(
        private SpeiseplanBasis $basis,
        private SpeiseplanErnaehrung $ernaehrung,
        private SpeiseplanAnsicht $ansicht,
    ) {}

    /** Plan als Vorlage für Betriebe freigeben oder zurücknehmen. Eine Betriebs-Kopie kann keine Vorlage sein. */
    public function setzeVorlage(Team $team, int $planId, bool $istVorlage): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->basis->guard($plan, $team);
        if ($istVorlage && $plan->source_plan_id !== null) {
            throw new \RuntimeException('Dieser Plan ist selbst eine Kalkulationsprofil-Kopie und kann keine Vorlage sein.');
        }
        $plan->update(['is_template' => $istVorlage]);

        return $plan->refresh();
    }

    /**
     * Betriebs-Kopien einer Vorlage mit Stand des Abgleichs.
     *
     * @return list<array{id:int, name:string, outlet:?string, status:string, aus_vorlage:int, lokal:int, synced_at:?string}>
     */
    public function betriebsKopien(Team $team, int $vorlageId): array
    {
        $kopien = FoodAlchemistSpeiseplan::where('team_id', $team->id)->where('source_plan_id', $vorlageId)->orderBy('name')->get();
        $outlets = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->whereIn('id', $kopien->pluck('outlet_id')->filter())->pluck('name', 'id');

        return $kopien->map(function ($k) use ($team, $outlets) {
            $abgleich = $this->vorlagenAbgleich($team, (int) $k->id);

            return [
                'id' => (int) $k->id, 'name' => $k->name,
                'outlet' => $k->outlet_id !== null ? ($outlets[$k->outlet_id] ?? null) : null,
                'status' => $k->statusWert()->label(),
                'aus_vorlage' => collect($abgleich['zellen'])->where('art', 'vorlage_geaendert')->count() + count($abgleich['neue_linien']),
                'lokal' => collect($abgleich['zellen'])->where('art', 'lokal_abweichend')->count(),
                'synced_at' => $k->source_synced_at?->format('d.m.Y H:i'),
            ];
        })->values()->all();
    }

    /**
     * Abgleich Betriebs-Kopie ↔ Vorlage ab einem Datum (Standard heute): je Zelle (Datum ×
     * Mahlzeit × Linie der Vorlage) die Inhalte beider Seiten, wenn sie abweichen. `art`:
     * `vorlage_geaendert` = die Vorlage hat die Zelle seit dem letzten Abgleich geändert;
     * `lokal_abweichend` = der Betrieb weicht bewusst ab. Neue Linien der Vorlage extra.
     *
     * @return array{vorlage: ?array{id:int, name:string}, zellen: list<array>, neue_linien: list<array{id:int, name:string}>}
     */
    public function vorlagenAbgleich(Team $team, int $kopieId, ?string $ab = null): array
    {
        $kopie = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($kopieId);
        $leer = ['vorlage' => null, 'zellen' => [], 'neue_linien' => []];
        if ($kopie->source_plan_id === null) {
            return $leer;
        }
        $vorlage = FoodAlchemistSpeiseplan::visibleToTeam($team)->with('lines')->find($kopie->source_plan_id);
        if ($vorlage === null) {
            return $leer;
        }
        $abDatum = Carbon::parse($ab ?? 'today')->startOfDay();
        $seit = $kopie->source_synced_at;
        // Vorlage-Einträge inkl. gelöschter (eine gelöschte Zelle ist auch eine Änderung).
        $vEintraege = FoodAlchemistSpeiseplanEintrag::withTrashed()->where('menu_plan_id', $vorlage->id)
            ->whereDate('entry_date', '>=', $abDatum->format('Y-m-d'))->get();
        $linienMap = $kopie->lines->filter(fn ($l) => $l->source_line_id !== null)
            ->mapWithKeys(fn ($l) => [(int) $l->source_line_id => (int) $l->id]);
        $vLinienName = $vorlage->lines->pluck('name', 'id');

        $zellen = [];
        $sammeln = function ($eintraege, string $seite) use (&$zellen, $linienMap) {
            foreach ($eintraege as $e) {
                $vLinie = $seite === 'v' ? (int) $e->line_id : (int) ($linienMap->search((int) $e->line_id) ?: 0);
                $key = $e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . $vLinie;
                $zellen[$key][$seite][] = $e;
            }
        };
        $sammeln($vEintraege->whereNull('deleted_at'), 'v');
        $sammeln($kopie->entries->filter(fn ($e) => $e->entry_date !== null && $e->entry_date->gte($abDatum)), 'k');
        $geaendert = [];
        foreach ($vEintraege as $e) {
            $ts = $e->deleted_at ?? $e->updated_at ?? $e->created_at;
            if ($seit === null || ($ts !== null && $ts->gt($seit))) {
                $geaendert[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id] = true;
            }
        }

        $out = [];
        ksort($zellen);
        foreach ($zellen as $key => $z) {
            $v = collect($z['v'] ?? [])->map(fn ($e) => $e->inhaltKey())->filter()->sort()->values()->all();
            $k = collect($z['k'] ?? [])->map(fn ($e) => $e->inhaltKey())->filter()->sort()->values()->all();
            if ($v === $k) {
                continue;
            }
            [$datum, $meal, $vLinie] = explode('|', $key);
            $out[] = [
                'key' => $key, 'datum' => $datum, 'mahlzeit' => $meal,
                'linie' => (int) $vLinie > 0 ? ($vLinienName[(int) $vLinie] ?? 'Linie #' . $vLinie) : 'Ohne Linie',
                'vorlage' => collect($z['v'] ?? [])->map(fn ($e) => $this->ernaehrung->eintragName($e))->values()->all(),
                'betrieb' => collect($z['k'] ?? [])->map(fn ($e) => $this->ernaehrung->eintragName($e))->values()->all(),
                'art' => isset($geaendert[$key]) ? 'vorlage_geaendert' : 'lokal_abweichend',
            ];
        }
        $neueLinien = $vorlage->lines->reject(fn ($l) => $linienMap->has((int) $l->id))
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->values()->all();

        return ['vorlage' => ['id' => (int) $vorlage->id, 'name' => $vorlage->name], 'zellen' => $out, 'neue_linien' => $neueLinien];
    }

    /**
     * Plan gegen Ist einer Woche und Mahlzeit, je Gericht: geplante Essen und Plan-Umsatz gegen
     * verkaufte Menge und Umsatz aus dem Verkaufsjournal (`foodalchemist_sales_facts`, strikt das
     * eigene Team). Concepts und Pakete haben kein Gericht in der Kasse → „nicht vergleichbar“.
     * Das Journal kennt keinen Betrieb (nur `source_scope_label`) — es zählt jede Verkaufsstelle
     * des Teams; das steht als Hinweis im Ergebnis.
     *
     * @return array{zeilen: list<array>, summe: array, hat_ist: bool, nicht_vergleichbar: list<string>, hinweis: string}
     */
    public function planIst(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, SpeiseplanBasis::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $so = $mo->copy()->addDays(6);
        $proGericht = [];
        $nichtVergleichbar = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($mo, $so)) {
                continue;
            }
            if ($e->sales_recipe_id === null) {
                $nichtVergleichbar[] = $e->inhaltName();

                continue;
            }
            $gid = (int) $e->sales_recipe_id;
            $pax = $this->basis->effektivePax($e, $plan);
            $proGericht[$gid]['name'] ??= $this->ernaehrung->eintragName($e);
            $proGericht[$gid]['plan'] = ($proGericht[$gid]['plan'] ?? 0) + $pax;
            $proGericht[$gid]['plan_umsatz'] = ($proGericht[$gid]['plan_umsatz'] ?? 0) + $this->ansicht->eintragPreis($e, $outlet)['vk'] * $pax;
        }

        $ist = $proGericht === [] ? collect() : \Platform\FoodAlchemist\Models\FoodAlchemistSalesFact::where('team_id', $team->id)
            ->whereIn('recipe_id', array_keys($proGericht))
            ->whereBetween('sold_at', [$mo->format('Y-m-d'), $so->format('Y-m-d')])
            ->selectRaw('recipe_id, SUM(qty_sold) as menge, SUM(revenue_net) as umsatz')
            ->groupBy('recipe_id')->get()->keyBy('recipe_id');

        $zeilen = [];
        $summe = ['plan' => 0, 'ist' => 0.0, 'plan_umsatz' => 0.0, 'ist_umsatz' => 0.0];
        foreach ($proGericht as $gid => $g) {
            $i = $ist->get($gid);
            $menge = $i !== null ? (float) $i->menge : null;
            $umsatz = $i !== null ? (float) $i->umsatz : null;
            $zeilen[] = [
                'recipe_id' => $gid, 'name' => $g['name'], 'plan' => (int) $g['plan'],
                'ist' => $menge, 'abweichung_pct' => $menge !== null && $g['plan'] > 0 ? round(($menge - $g['plan']) / $g['plan'] * 100, 1) : null,
                'plan_umsatz' => round($g['plan_umsatz'], 2), 'ist_umsatz' => $umsatz !== null ? round($umsatz, 2) : null,
            ];
            $summe['plan'] += (int) $g['plan'];
            $summe['plan_umsatz'] += $g['plan_umsatz'];
            if ($menge !== null) {
                $summe['ist'] += $menge;
                $summe['ist_umsatz'] += (float) $umsatz;
            }
        }
        usort($zeilen, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $hatIst = $ist->isNotEmpty();
        $summe['plan_umsatz'] = round($summe['plan_umsatz'], 2);
        $summe['ist_umsatz'] = round($summe['ist_umsatz'], 2);
        $summe['abweichung_pct'] = $hatIst && $summe['plan'] > 0 ? round(($summe['ist'] - $summe['plan']) / $summe['plan'] * 100, 1) : null;

        return [
            'zeilen' => $zeilen, 'summe' => $summe, 'hat_ist' => $hatIst,
            'nicht_vergleichbar' => array_values(array_unique($nichtVergleichbar)),
            'hinweis' => 'Ist aus dem Verkaufsjournal des Teams (alle Verkaufsstellen, Zeitraum Mo–So). Gerichte ohne Zuordnung im Journal zählen nicht.',
        ];
    }
}
