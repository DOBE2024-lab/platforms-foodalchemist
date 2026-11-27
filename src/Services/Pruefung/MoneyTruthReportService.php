<?php

namespace Platform\FoodAlchemist\Services\Pruefung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 22 · H2a — die MESSUNG vor dem Umbau der Geld-Wahrheiten.
 *
 * Der Etappen-Text von 22·H2 verlangt die Reihenfolge ausdrücklich: „Messung zuerst,
 * Umbau danach … die Messzahl entscheidet, ob das ein stiller Dauerfehler oder ein
 * Randfall ist." Dieser Service ist genau diese Messung und **schreibt nichts** —
 * keine Signale, keine Zeitreihe, keine Lauf-Zeile. Er beantwortet heute zwei Fragen (B und C):
 *
 *  A · `sales_unit_count` (V-041) — entfallen mit 98 · 3.5-6a: Die Frage war, wie weit die Divisions-Lesart
 *      `ek_total / sales_unit_count` von der Darreichung abweicht. Dominique hat entschieden (27.11.): Darreichung = Portion,
 *      Teiler fest 1 (io: 0 Gerichte mit > 1). Die Spalte fällt mit 6c; eine Messung darauf bräche dann.
 *
 *  B · Preis-Wahrheit (Spec 85 D-1): trägt jedes Verkaufsrezept genau EINE Standard-Darreichung mit Preis?
 *      Gezählt: ohne Darreichung, Darreichungen ohne `is_standard` (V-059), mehrere Standard-Formen, kein VK.
 *      (Bis D-1 PR-C verglich der Block Darreichungs- und Rezept-Preis — die Rezept-Spalte gibt es nicht mehr.)
 *
 *  C · Lead-LA-Preiszeilen (V-053): wie viele GPs gelten der Datenqualitäts-Ampel als
 *      versorgt, weil ihr Prädikat laxer ist als das des Money-Paths? Drei Fassungen
 *      derselben Frage, absichtlich getrennt gezählt: die **laxe** der Ampel
 *      (`price > 0`, nicht gesperrt, nicht gelöscht), die **strenge** des Money-Paths
 *      ({@see PriceService::scopeAktiv} — zusätzlich `status IN ('0','2')`) und die
 *      **Gültigkeits**-Fassung (zusätzlich unbefristet oder `valid_to` in der Zukunft).
 *      Die zwei Deltas sind die Antwort auf die offene `valid_to`-Frage der Etappe.
 *
 * **Warum die Beispiele mitkommen:** eine nackte Zahl beantwortet „Randfall oder
 * Dauerfehler" nur halb — erst eine benennbare Rezept-/GP-ID macht sie nachprüfbar.
 * Deshalb liefert jeder Block bis zu `$limit` konkrete Zeilen mit den Zahlen, an denen
 * die Divergenz hängt.
 */
class MoneyTruthReportService
{
    public function __construct(private \Platform\FoodAlchemist\Services\Preis\PriceService $prices) {}

    /**
     * @return array{team_id: int, b_preis_wahrheit: array, c_lead_la_preis: array}
     */
    public function messe(Team $team, int $limit = 5): array
    {
        return [
            'team_id' => (int) $team->id,
            'b_preis_wahrheit' => $this->blockPreisWahrheit($team, $limit),
            'c_lead_la_preis' => $this->blockLeadLaPreis($team, $limit),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B · Spec 85 D-1 — Struktur der Preis-Wahrheit: genau EINE Standard-Darreichung je Verkaufsrezept
    // (vorher V-046/V-059: Darreichungs-Preis gegen die Rezept-Altspalte; die gibt es seit PR-C nicht mehr)
    // ─────────────────────────────────────────────────────────────────────────

    private function blockPreisWahrheit(Team $team, int $limit): array
    {
        $gerichte = $this->vkGerichte($team)->with('standardPresentation')->get();
        $ids = $gerichte->pluck('id')->all();

        // Eine Query je Kennzahl für alle Gerichte: Darreichungs-Zeilen überhaupt / Zahl der Standard-Formen.
        $mitZeilen = array_fill_keys(DB::table('foodalchemist_recipe_presentations')
            ->whereNull('deleted_at')->whereIn('recipe_id', $ids)
            ->distinct()->pluck('recipe_id')->map(fn ($id) => (int) $id)->all(), true);
        $mehrereStandard = DB::table('foodalchemist_recipe_presentations')
            ->whereNull('deleted_at')->where('is_standard', true)->whereIn('recipe_id', $ids)
            ->groupBy('recipe_id')->havingRaw('COUNT(*) > 1')
            ->pluck('recipe_id')->map(fn ($id) => (int) $id)->all();
        $mehrere = array_fill_keys($mehrereStandard, true);

        $zaehler = ['mit_standard_darreichung' => 0, 'ohne_darreichung' => 0, 'darreichungen_ohne_standard' => 0, 'kein_preis' => 0];
        $beispiele = [];
        foreach ($gerichte as $recipe) {
            $standard = $recipe->standardPresentation;
            $problem = null;
            if ($standard !== null) {
                $zaehler['mit_standard_darreichung']++;
                if ($standard->sales_net === null || (float) $standard->sales_net <= 0) {
                    $zaehler['kein_preis']++;
                    $problem = 'standard_ohne_preis';
                }
            } elseif (! isset($mitZeilen[(int) $recipe->id])) {
                $zaehler['ohne_darreichung']++;
                $zaehler['kein_preis']++;
                $problem = 'ohne_darreichung';
            } else {
                $zaehler['darreichungen_ohne_standard']++;   // V-059: Formen ja, Standard nein → kein Preis des Gerichts
                $zaehler['kein_preis']++;
                $problem = 'ohne_standard';
            }
            if (isset($mehrere[(int) $recipe->id])) {
                $problem = 'mehrere_standard';
            }
            if ($problem !== null && count($beispiele) < $limit) {
                $beispiele[] = ['recipe_id' => (int) $recipe->id, 'name' => (string) $recipe->name, 'problem' => $problem];
            }
        }

        return ['vk_gerichte' => $gerichte->count(), ...$zaehler, 'mehrere_standard' => count($mehrereStandard), 'beispiele' => $beispiele];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C · V-053 — drei Fassungen von „aktiver Preis" am Lead-LA
    // ─────────────────────────────────────────────────────────────────────────

    private function blockLeadLaPreis(Team $team, int $limit): array
    {
        $basis = fn () => FoodAlchemistGp::visibleToTeam($team)->whereNotNull('lead_la_supplier_item_id');

        $mitLead = $basis()->count();
        $lax = $basis()->whereExists($this->laxeFassung())->count();
        $streng = $basis()->whereExists($this->strengeFassung())->count();
        $gueltig = $basis()->whereExists($this->gueltigkeitsFassung())->count();

        // Die zwei Lagen, um die es der Etappe geht: was die Ampel heute grün meldet,
        // obwohl der Money-Path nichts findet.
        $nurStatusfremd = $basis()
            ->whereExists($this->laxeFassung())
            ->whereNotExists($this->strengeFassung())
            ->get(['id', 'name', 'lead_la_supplier_item_id'])
            ->take($limit);

        $nurAbgelaufen = $basis()
            ->whereExists($this->strengeFassung())
            ->whereNotExists($this->gueltigkeitsFassung())
            ->get(['id', 'name', 'lead_la_supplier_item_id'])
            ->take($limit);

        return [
            'gps_mit_lead' => $mitLead,
            'lax_erfuellt' => $lax,
            'streng_erfuellt' => $streng,
            'gueltig_erfuellt' => $gueltig,
            'delta_lax_streng' => $lax - $streng,
            'delta_streng_gueltig' => $streng - $gueltig,
            'beispiele_nur_statusfremd' => $this->gpBeispiele($nurStatusfremd),
            'beispiele_nur_abgelaufen' => $this->gpBeispiele($nurAbgelaufen),
        ];
    }

    /**
     * Die Fassung, die `DataQualityService::aktivPreisFuerLead()` heute benutzt —
     * bewusst als Kopie ihres Prädikats, denn sie ist der Vergleichs-Gegenstand.
     * (`deleted_at IS NULL` kommt hier aus dem SoftDeletes-Scope des Models statt
     * wie dort per Hand — dieselbe Bedingung, ein Weg weniger.)
     */
    private function laxeFassung(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->korreliert(FoodAlchemistPrice::query())
            ->where('price', '>', 0)
            ->where('is_blocked', false);
    }

    /**
     * Die Fassung des Money-Paths — `PriceService::scopeAktiv` selbst, nicht nachgebaut.
     * Genau darum geht es in V-053: das Prädikat darf nicht ein drittes Mal geschrieben werden.
     */
    private function strengeFassung(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->prices->scopeAktiv($this->korreliert(FoodAlchemistPrice::query()));
    }

    /** Strenge Fassung + die offene `valid_to`-Frage: unbefristet oder noch gültig. */
    private function gueltigkeitsFassung(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->strengeFassung()->where(function ($w) {
            $w->whereNull('foodalchemist_prices.valid_to')
                ->orWhere('foodalchemist_prices.valid_to', '>=', now());
        });
    }

    /** Die EXISTS-Korrelation Preis-Zeile ↔ Lead-LA des GP — an einer Stelle. */
    private function korreliert(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        // Spec 82 3a: Report über den globalen Lead → nur Listenpreis-Zeilen.
        return $this->prices->nurListenpreis($q)->select(DB::raw(1))
            ->whereColumn('foodalchemist_prices.supplier_item_id', 'foodalchemist_gps.lead_la_supplier_item_id');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function vkGerichte(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true);
    }

    /** @param \Illuminate\Support\Collection<int, FoodAlchemistGp> $gps */
    private function gpBeispiele($gps): array
    {
        return $gps->map(fn ($gp) => [
            'gp_id' => (int) $gp->id,
            'name' => (string) $gp->name,
            'lead_la_supplier_item_id' => (int) $gp->lead_la_supplier_item_id,
        ])->values()->all();
    }
}
