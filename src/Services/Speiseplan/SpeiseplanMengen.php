<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanBasis;

/**
 * Mengen, Produktion und Bedarf des Speiseplans (98 · 3.3 S3, aus SpeiseplanService verschoben): Spec 57 Paket 3
 * (Essen je Linie × Tag: Matrix, Zelle setzen, Vorwoche übernehmen, Woche skalieren), Spec 31 Stufe C (Woche an die
 * Produktion, Produktions- und Paket-Ziele) und Paket 4 (Bedarf aus Plan × Mengen). Nutzt Basis und Ansicht, nie die Fassade.
 */
final class SpeiseplanMengen
{
    public function __construct(
        private SpeiseplanBasis $basis,
        private SpeiseplanAnsicht $ansicht,
    ) {}

    /**
     * Mengen-Matrix einer Woche und Mahlzeit: je Linie × Öffnungstag die Essen der Zelle
     * (effektive Pax je Eintrag; mehrere Einträge einer Zelle = größte Zahl, weil sie dieselben
     * Gäste bedienen), dazu Vorwoche und Ø der letzten vier Wochen aus den PLANWERTEN, Summe,
     * Anteil, Wareneinsatz, Ø VK netto und Umsatz der Linie.
     *
     * @return array{tage: list<string>, zeilen: list<array>, summe: array}
     */
    public function mengenMatrix(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $tage = array_map(fn (Carbon $t) => $t->format('Y-m-d'), $this->basis->wochenTage($plan, $mo));
        $linien = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($mahlzeit))->values();

        // Einmal indizieren (Linie|Tag) statt je Zelle alle Einträge zu durchsuchen — ein
        // ausgerollter Jahresplan hat Tausende Einträge.
        $index = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date !== null && $e->meal === $mahlzeit) {
                $index[(int) $e->line_id . '|' . $e->entry_date->format('Y-m-d')][] = $e;
            }
        }
        $zellPax = function (int $lineId, string $ymd) use ($plan, $index): ?int {
            $liste = $index[$lineId . '|' . $ymd] ?? [];

            return $liste === [] ? null : (int) max(array_map(fn ($e) => $this->basis->effektivePax($e, $plan), $liste));
        };
        $wochenSumme = function (int $lineId, Carbon $woMo) use ($zellPax, $plan): int {
            $s = 0;
            foreach ($this->basis->wochenTage($plan, $woMo) as $t) {
                $s += (int) ($zellPax($lineId, $t->format('Y-m-d')) ?? 0);
            }

            return $s;
        };

        $zeilen = [];
        $gesamt = ['summe' => 0, 'umsatz' => 0.0, 'ek' => 0.0, 'ek_we' => 0.0, 'vorwoche' => 0, 'schnitt4' => 0.0, 'je_tag' => array_fill_keys($tage, 0)];
        foreach ($linien as $l) {
            $zellen = [];
            $summe = 0;
            $umsatz = 0.0;
            $ek = 0.0;
            $ekWe = 0.0;
            foreach ($tage as $ymd) {
                $p = $zellPax((int) $l->id, $ymd);
                $zellen[$ymd] = $p;
                $summe += (int) $p;
                $gesamt['je_tag'][$ymd] += (int) $p;
                foreach ($index[(int) $l->id . '|' . $ymd] ?? [] as $e) {
                    $preis = $this->ansicht->eintragPreis($e, $outlet);
                    $pax = $this->basis->effektivePax($e, $plan);
                    $umsatz += $preis['vk'] * $pax;
                    $ek += $preis['ek'] * $pax;
                    $ekWe += $preis['vk'] > 0 ? $preis['ek'] * $pax : 0.0;   // WE nur über Einträge mit Preis
                }
            }
            $vorwoche = $wochenSumme((int) $l->id, $mo->copy()->subWeek());
            $vier = [];
            for ($w = 1; $w <= 4; $w++) {
                $vier[] = $wochenSumme((int) $l->id, $mo->copy()->subWeeks($w));
            }
            $schnitt4 = round(array_sum($vier) / 4, 1);
            $zeilen[] = [
                'line_id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'role' => $l->role,
                'zellen' => $zellen, 'summe' => $summe, 'vorwoche' => $vorwoche, 'schnitt4' => $schnitt4,
                'umsatz' => round($umsatz, 2),
                'wes' => MargeService::wareneinsatzPct($ekWe, $umsatz),
                'vk_schnitt' => $summe > 0 ? round($umsatz / max(1, $summe), 2) : null,
            ];
            $gesamt['summe'] += $summe;
            $gesamt['umsatz'] += $umsatz;
            $gesamt['ek'] += $ek;
            $gesamt['ek_we'] += $ekWe;
            $gesamt['vorwoche'] += $vorwoche;
            $gesamt['schnitt4'] += $schnitt4;
        }
        foreach ($zeilen as $i => $z) {
            $zeilen[$i]['anteil'] = $gesamt['summe'] > 0 ? round($z['summe'] / $gesamt['summe'] * 100, 1) : null;
        }
        $gesamt['wes'] = MargeService::wareneinsatzPct($gesamt['ek_we'], $gesamt['umsatz']);
        unset($gesamt['ek_we']);
        $gesamt['umsatz'] = round($gesamt['umsatz'], 2);
        $gesamt['ek'] = round($gesamt['ek'], 2);

        return ['tage' => $tage, 'zeilen' => $zeilen, 'summe' => $gesamt];
    }

    /**
     * Essen einer Zelle (Linie × Tag × Mahlzeit) setzen — schreibt den Pax-Override aller
     * Einträge der Zelle. Leer/0 = zurück auf den Standard. Leere Zelle: nichts zu setzen.
     *
     * @return int Anzahl geänderter Einträge
     */
    public function setzeZellenPax(Team $team, int $planId, int $lineId, string $datum, string $mahlzeit, $pax): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->basis->guard($plan, $team);
        $wert = (int) $pax;
        // Je Model speichern (nicht per Massen-Update), damit das Activity-Log die Änderung sieht.
        $eintraege = FoodAlchemistSpeiseplanEintrag::where('menu_plan_id', $plan->id)
            ->whereDate('entry_date', Carbon::parse($datum)->format('Y-m-d'))
            ->where('meal', $mahlzeit)->where('line_id', $lineId)
            ->get();
        foreach ($eintraege as $e) {
            $e->update(['pax' => $wert > 0 ? $wert : null]);
        }

        return $eintraege->count();
    }

    /**
     * Mengen der Vorwoche übernehmen: jede belegte Zelle dieser Woche bekommt die Essen derselben
     * Linie am selben Wochentag der Vorwoche (wenn es dort Werte gab).
     *
     * @return int Anzahl geänderter Einträge
     */
    public function uebernehmeVorwoche(Team $team, int $planId, string $mahlzeit, Carbon $montag): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($planId);
        $this->basis->guard($plan, $team);
        $vorher = $this->mengenMatrix($team, $plan, $mahlzeit, $montag->copy()->subWeek());
        $n = 0;
        foreach ($vorher['zeilen'] as $z) {
            foreach ($z['zellen'] as $ymd => $pax) {
                if ($pax === null) {
                    continue;
                }
                $n += $this->setzeZellenPax($team, $planId, $z['line_id'], Carbon::parse($ymd)->addWeek()->format('Y-m-d'), $mahlzeit, $pax);
            }
        }

        return $n;
    }

    /**
     * Alle Essen der Woche (eine Mahlzeit) mit einem Faktor skalieren und als Override setzen.
     *
     * @return int Anzahl geänderter Einträge
     */
    public function skaliereWoche(Team $team, int $planId, string $mahlzeit, Carbon $montag, float $faktor): int
    {
        if ($faktor <= 0 || $faktor > 10) {
            throw new \RuntimeException('Skalierungsfaktor muss zwischen 0 und 10 liegen.');
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($planId);
        $this->basis->guard($plan, $team);
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $n = 0;
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($mo, $mo->copy()->addDays(6))) {
                continue;
            }
            $e->update(['pax' => max(1, (int) round($this->basis->effektivePax($e, $plan) * $faktor))]);
            $n++;
        }

        return $n;
    }

    /**
     * Spec 31 / Stufe C: Wochen-Speiseplan an die Produktion übergeben. Erzeugt je Öffnungstag MIT
     * Belegung EINEN Produktionsauftrag (GV kocht tagesweise); jeder Eintrag wird zu einem Ziel
     * (Concept → persons, VK-Gericht → portions, Paket → seine Gerichte je portions), Menge =
     * effektive Pax ({@see effektivePax}). Zielform gespiegelt aus Produktion\Editor.
     *
     * Spec 57 · 0.2: IDEMPOTENT. Ein zweiter Aufruf legt keine Dubletten an: ein noch offener
     * Auftrag desselben Plans/Tags/Namens bekommt die Ziele ersetzt (`aktualisiert`); ein Auftrag,
     * der schon läuft oder fertig ist, bleibt unangetastet (`gesperrt`).
     *
     * @return array{auftraege:int, aktualisiert:int, gesperrt:int, ziele:int, tage:list<string>}
     */
    public function wocheAnProduktion(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $userId = null): array
    {
        $this->basis->guard($plan, $team);
        $mahlzeit = array_key_exists($mahlzeit, SpeiseplanBasis::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $produktion = app(ProductionOrderService::class);
        $raster = $this->ansicht->wochenRaster($plan, $mahlzeit, $montag);
        $referenz = 'Speiseplan #' . $plan->id;

        $auftraege = 0;
        $aktualisiert = 0;
        $gesperrt = 0;
        $zieleGesamt = 0;
        $tage = [];
        foreach ($this->basis->wochenTage($plan, $montag) as $tag) {
            $ymd = $tag->format('Y-m-d');
            $eintraege = collect($raster)->flatMap(fn ($proLinie) => $proLinie[$ymd] ?? []);
            if ($eintraege->isEmpty()) {
                continue;
            }

            $targets = $this->produktionsZiele($plan, $eintraege, $ymd);
            if ($targets === []) {
                continue;
            }

            $name = $plan->name . ' · ' . SpeiseplanBasis::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.') . ' (' . SpeiseplanBasis::MAHLZEITEN[$mahlzeit] . ')';
            $vorhanden = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::where('team_id', $team->id)
                ->where('reference', $referenz)
                ->whereDate('production_date', $ymd)
                ->where('name', $name)
                // Ein stornierter Auftrag blockiert nicht — dann wird neu angelegt.
                ->where('status', '!=', \Platform\FoodAlchemist\Enums\ProductionOrderStatus::Cancelled->value)
                ->latest('id')->first();
            if ($vorhanden !== null) {
                $status = $vorhanden->status instanceof \Platform\FoodAlchemist\Enums\ProductionOrderStatus
                    ? $vorhanden->status
                    : \Platform\FoodAlchemist\Enums\ProductionOrderStatus::from((string) $vorhanden->status);
                if (! $status->istOffen()) {
                    $gesperrt++;

                    continue;
                }
                $produktion->replaceTargets($team, (int) $vorhanden->id, $targets);
                $aktualisiert++;
            } else {
                $produktion->saveNew($team, $ymd, $name, $targets, $referenz, null, $userId);
                $auftraege++;
            }
            $zieleGesamt += count($targets);
            $tage[] = $ymd;
        }

        return ['auftraege' => $auftraege, 'aktualisiert' => $aktualisiert, 'gesperrt' => $gesperrt, 'ziele' => $zieleGesamt, 'tage' => $tage];
    }

    /**
     * Produktions-Ziele der Einträge eines Tages — EINE Zielform für Produktion und Bedarf:
     * Concept → persons, VK-Gericht → portions, Paket → seine Gerichte je portions; Menge =
     * {@see effektivePax}. `source_ref` bleibt je Eintrag stabil (Idempotenz der Produktion).
     *
     * @param  iterable<FoodAlchemistSpeiseplanEintrag>  $eintraege
     * @return list<array<string, mixed>>
     */
    private function produktionsZiele(FoodAlchemistSpeiseplan $plan, iterable $eintraege, string $ymd): array
    {
        $targets = [];
        foreach ($eintraege as $e) {
            $pax = $this->basis->effektivePax($e, $plan);
            $ref = 'menuplan:' . $plan->id . ':' . $ymd . ':' . $e->id;
            if ($e->concept_id !== null) {
                $targets[] = ['concept_id' => (int) $e->concept_id, 'persons' => $pax, 'source_ref' => $ref];
            } elseif ($e->sales_recipe_id !== null) {
                // Darreichung des Eintrags wandert mit — die Produktion rechnet die Menge daraus (sonst Standard).
                $targets[] = array_filter(['recipe_id' => (int) $e->sales_recipe_id, 'portions' => $pax,
                    'presentation_id' => $e->presentation_id !== null ? (int) $e->presentation_id : null, 'source_ref' => $ref], fn ($v) => $v !== null);
            }
        }

        return $targets;
    }

    /**
     * Zutatenbedarf der Woche (oder eines Tages) einer Mahlzeit: dieselben Ziele wie die
     * Produktion ({@see produktionsZiele}) durch {@see PlanungsblattService::einkaufsliste} —
     * GP-Ebene, Basiseinheit, Lead-Lieferantenartikel, ganze Gebinde. Keine eigene Rechnung.
     * Nur lesend: die Übergabe an den Einkauf läuft über die Produktion (Spec 57 · E7).
     *
     * @return array{tage: list<string>, ziele: int, liste: ?array}
     */
    public function wochenBedarf(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?string $tag = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, SpeiseplanBasis::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $raster = $this->ansicht->wochenRaster($plan, $mahlzeit, $montag);
        $tage = array_map(fn (Carbon $t) => $t->format('Y-m-d'), $this->basis->wochenTage($plan, $montag));
        if ($tag !== null) {
            $tage = array_values(array_intersect($tage, [Carbon::parse($tag)->format('Y-m-d')]));
        }
        $ziele = [];
        foreach ($tage as $ymd) {
            $eintraege = collect($raster)->flatMap(fn ($proLinie) => $proLinie[$ymd] ?? []);
            foreach ($this->produktionsZiele($plan, $eintraege, $ymd) as $z) {
                unset($z['source_ref']);
                $ziele[] = $z;
            }
        }

        return [
            'tage' => $tage,
            'ziele' => count($ziele),
            'liste' => $ziele !== [] ? app(PlanungsblattService::class)->einkaufsliste($team, $ziele) : null,
        ];
    }
}
