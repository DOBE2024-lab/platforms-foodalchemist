<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderRound;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktion\EigenproduktionService;

/**
 * Bausteine der Bedarfs-Vorschau (98 · 3.3 S5, aus OrderService verschoben): Quell-Mengen und -Ziele, Labels und
 * Quell-Keys, Positions-Gruppen, Overrides, offene Punkte, Lagerstand je GP und Lager-Reservierung der Runden. Die
 * Vorschau selbst liegt in BestellVorschau (98 · 3.3 OrderService-Schnitt). Nutzt BestellAmpeln, nicht die Fassade.
 */
final class BestellBedarf
{
    public function __construct(
        private BestellAmpeln $ampeln,
    ) {}

    /**
     * Spec 73: Lager, das andere gespeicherte Runden schon angerechnet haben (bis zu ihrem Liefertag).
     *
     * @return array{gp: array<int, float>, rezept: array<int, float>, runden: array<int, list<string>>}
     */
    public function lagerReserviert(Team $team, ?int $ohneRundeId = null): array
    {
        $out = ['gp' => [], 'rezept' => [], 'runden' => []];
        $runden = FoodAlchemistOrderRound::where('team_id', $team->id)->whereNotNull('lager_reserviert')
            ->when($ohneRundeId !== null, fn ($q) => $q->whereKeyNot($ohneRundeId))
            ->where(fn ($q) => $q->whereDate('desired_delivery_date', '>=', now()->toDateString())
                ->orWhere(fn ($w) => $w->whereNull('desired_delivery_date')->where('created_at', '>=', now()->subDays(14))))
            ->get(['id', 'label', 'lager_reserviert']);
        foreach ($runden as $r) {
            foreach (['gp', 'rezept'] as $art) {
                foreach ((array) ($r->lager_reserviert[$art] ?? []) as $id => $menge) {
                    $out[$art][(int) $id] = ($out[$art][(int) $id] ?? 0) + (float) $menge;
                    $out['runden'][$art . ':' . (int) $id][] = (string) $r->label;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function previewSourceRefs(array $preview): array
    {
        return collect($preview['orders_preview'] ?? [])
            ->flatMap(fn ($gruppe) => collect($gruppe['positionen'] ?? [])->pluck('source_ref'))
            ->filter()
            ->map(fn ($ref) => (string) $ref)
            ->unique()
            ->values()
            ->all();
    }

    public function previewAddPosition(array &$gruppen, int $supplierId, string $supplierName, ?string $date, array $position): void
    {
        $key = $supplierId.'|'.($date ?: '');
        $gruppen[$key] ??= [
            'supplier_id' => $supplierId,
            'supplier' => $supplierName,
            'delivery_date' => $date,
            'positionen' => [],
            'total_net' => 0.0,
            'references' => [],
            'source_refs' => [],
        ];

        $gruppen[$key]['positionen'][] = $position;
        $gruppen[$key]['total_net'] = round((float) $gruppen[$key]['total_net'] + (float) ($position['line_total'] ?? 0), 2);
        if (($position['reference'] ?? '') !== '') {
            $gruppen[$key]['references'][$position['reference']] = true;
        }
        if (($position['source_ref'] ?? '') !== '') {
            $gruppen[$key]['source_refs'][$position['source_ref']] = true;
        }
    }

    public function finalisierePreviewGruppen(array $gruppen): array
    {
        $lieferanten = FoodAlchemistSupplier::query()
            ->whereIn('id', collect($gruppen)->pluck('supplier_id')->filter()->unique()->values())
            ->get(['id', 'min_order_value', 'free_shipping_threshold', 'delivery_days', 'order_cutoff_time', 'order_lead_days'])
            ->keyBy('id');

        return collect($gruppen)
            ->sortBy(fn ($g) => (($g['delivery_date'] ?? '9999-12-31').'|'.($g['supplier'] ?? '')))
            ->map(function ($g) use ($lieferanten) {
                $supplier = $lieferanten[(int) $g['supplier_id']] ?? null;
                $min = $supplier?->min_order_value !== null ? (float) $supplier->min_order_value : null;
                $free = $supplier?->free_shipping_threshold !== null ? (float) $supplier->free_shipping_threshold : null;
                $total = (float) ($g['total_net'] ?? 0);
                $g['references'] = array_keys($g['references'] ?? []);
                $g['source_refs'] = array_keys($g['source_refs'] ?? []);
                $g['warnings'] = $this->ampeln->previewLogistikWarnings($supplier, ($g['delivery_date'] ?? null) ?: null);
                $g['moq'] = [
                    'min_order_value' => $min,
                    'free_shipping_threshold' => $free,
                    'unter_mindestbestellwert' => $min !== null && $total < $min,
                    'fehlt_bis_min' => $min !== null && $total < $min ? round($min - $total, 2) : 0.0,
                    'frei_haus' => $free !== null && $total >= $free,
                    'fehlt_bis_frei_haus' => $free !== null && $total < $free ? round($free - $total, 2) : 0.0,
                ];

                return $g;
            })->values()->all();
    }

    public function sourceQty(array $source): float
    {
        return (float) str_replace(',', '.', trim((string) ($source['qty'] ?? 1)));
    }

    public function sourceGramsForGp(FoodAlchemistGp $gp, array $source): float
    {
        $qty = $this->sourceQty($source);
        $unit = (string) ($source['unit'] ?? 'kg');

        return match ($unit) {
            'g' => $qty,
            'stk' => $gp->piece_default_g !== null ? $qty * (float) $gp->piece_default_g : 0.0,
            default => $qty * 1000.0,
        };
    }

    public function displayMenge(float $grams, string $unit, ?float $pieceG): float
    {
        return match ($unit) {
            'g' => round($grams, 1),
            'stk' => $pieceG !== null && $pieceG > 0 ? round($grams / $pieceG, 2) : round($grams, 1),
            default => round($grams / 1000.0, 3),
        };
    }

    /** Spec 73: Konzept-Quelle (auch Paket = kind=paket) → Ziel (Personen). */
    public function zielAusMengenQuelle(array $source): array
    {
        return ['concept_id' => (int) ($source['id'] ?? 0), 'persons' => max(1, (int) round($this->sourceQty($source)))];
    }

    public function zielAusRecipeSource(array $source): array
    {
        $recipe = FoodAlchemistRecipe::find((int) ($source['id'] ?? 0));
        $qty = max(0.0, $this->sourceQty($source));
        $unit = (string) ($source['unit'] ?? ($recipe?->is_sales_recipe ? 'portions' : 'ansaetze'));
        $ziel = ['recipe_id' => (int) ($source['id'] ?? 0)];
        if ($unit === 'kg') {
            $ziel['amount_kg'] = $qty;
        } else {
            $ziel['portions'] = $qty;
        }

        return $ziel;
    }

    public function zielOhneMeta(array $target): array
    {
        return array_diff_key($target, array_flip(['source_ref', 'label']));
    }

    public function sourceLabel(Team $team, array $source): string
    {
        $id = (int) ($source['id'] ?? 0);

        return match ((string) ($source['type'] ?? '')) {
            'supplier_item' => (string) (FoodAlchemistSupplierItem::visibleToTeam($team)->find($id)?->designation ?? ('Artikel #'.$id)),
            'gp' => (string) (FoodAlchemistGp::visibleToTeam($team)->find($id)?->name ?? ('Grundprodukt #'.$id)),
            'recipe' => (string) (FoodAlchemistRecipe::visibleToTeam($team)->find($id)?->name ?? ('Rezept #'.$id)),
            'production' => (string) (FoodAlchemistProductionOrder::visibleToTeam($team)->find($id)?->name ?? ('Produktion #'.$id)),
            'concept' => (string) (\Platform\FoodAlchemist\Models\FoodAlchemistConcept::visibleToTeam($team)->find($id)?->name ?? ('Konzept #'.$id)),
            'nachfuellen' => 'Lagerartikel nachfüllen',
            default => 'Quelle',
        };
    }

    public function sourceRef(array $source, int $idx): string
    {
        if (($source['type'] ?? '') === 'production') {
            return 'production-source:'.(int) ($source['id'] ?? 0);
        }

        $basis = implode(':', [
            (string) ($source['type'] ?? 'source'),
            (string) ($source['id'] ?? $idx),
            (string) ($source['qty'] ?? 1),
            (string) ($source['unit'] ?? ''),
            (string) ($source['delivery_date'] ?? ''),
            (string) ($source['reference'] ?? ''),
        ]);

        return Str::slug((string) ($source['type'] ?? 'source'), '_').':'.(string) ($source['id'] ?? $idx).'@'.substr(sha1($basis), 0, 10);
    }

    /** Spec 71: stabiler Schlüssel einer Vorschau-Position (für Auslassen / Menge von Hand). */
    public function positionsSchluessel(array $pos): string
    {
        return (string) ($pos['override_key'] ?? ('la:' . ($pos['source_ref'] ?? '') . '|' . ($pos['lead_la_id'] ?? '')));
    }

    /** Spec 71: verfügbarer Lagerbestand je GP in g (Stk über Stückgewicht), alle aktiven Lagerorte des Teams. @return array<int, float> */
    public function lagerJeGp(Team $team, array $reserviert = []): array
    {
        $rows = \Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock::query()
            ->where('foodalchemist_inventory_stocks.team_id', $team->id)->whereNotNull('foodalchemist_inventory_stocks.gp_id')->where('qty_base', '>', 0)
            ->join('foodalchemist_inventory_locations as l', 'l.id', '=', 'foodalchemist_inventory_stocks.inventory_location_id')
            ->where('l.is_active', true)->whereNull('l.deleted_at')
            ->selectRaw('foodalchemist_inventory_stocks.gp_id, foodalchemist_inventory_stocks.base_unit, SUM(qty_base) as menge')
            ->groupBy('foodalchemist_inventory_stocks.gp_id', 'foodalchemist_inventory_stocks.base_unit')->get();
        $stueck = FoodAlchemistGp::whereIn('id', $rows->where('base_unit', 'Stk')->pluck('gp_id')->all() ?: [0])->pluck('piece_default_g', 'id');
        $out = [];
        foreach ($rows as $r) {
            $g = match ($r->base_unit) {
                'g', 'ml' => (float) $r->menge,
                'Stk' => isset($stueck[$r->gp_id]) && (float) $stueck[$r->gp_id] > 0 ? (float) $r->menge * (float) $stueck[$r->gp_id] : 0.0,
                default => 0.0,
            };
            $out[(int) $r->gp_id] = ($out[(int) $r->gp_id] ?? 0) + $g;
        }
        foreach ($reserviert as $gpId => $g) {     // Spec 73: von anderen Runden schon angerechnet
            if (isset($out[$gpId])) {
                $out[$gpId] = max(0.0, $out[$gpId] - (float) $g);
            }
        }

        return $out;
    }

    /**
     * Spec 72: Eigenproduktion im Lager als Topf für die Auflösung. Abgezogen wird wie beim GP nur per Knopf:
     * `lager_rezept[recipe_id]` (je Rezept) schlägt `lager_abgleich` (alle).
     *
     * @return array<int, array{menge: float, base: string, abziehen: bool, bedarf: float, genutzt: float, start: float}>
     */
    public function rezeptLagerTopf(Team $team, array $overrides): array
    {
        $global = ! empty($overrides['lager_abgleich']);
        $jeRezept = (array) ($overrides['lager_rezept'] ?? []);
        $reserviert = $this->lagerReserviert($team, isset($overrides['round_id']) ? (int) $overrides['round_id'] : null)['rezept'];
        $topf = [];
        foreach (app(EigenproduktionService::class)->lagerJeRezept($team, null) as $rid => $l) {
            $frei = max(0.0, (float) $l['menge'] - (float) ($reserviert[(int) $rid] ?? 0));
            $topf[(int) $rid] = ['menge' => $frei, 'start' => $frei, 'base' => (string) $l['base'],
                'abziehen' => array_key_exists($rid, $jeRezept) ? (bool) $jeRezept[$rid] : $global, 'bedarf' => 0.0, 'genutzt' => 0.0];
        }

        return $topf;
    }

    /** Nur Rezepte, die in dieser Runde gebraucht werden. @return list<array> */
    public function rezeptLagerAnzeige(array $topf): array
    {
        $ep = app(EigenproduktionService::class);
        $namen = FoodAlchemistRecipe::whereIn('id', array_keys(array_filter($topf, fn ($t) => ($t['bedarf'] ?? 0) > 0)) ?: [0])->pluck('name', 'id');
        $out = [];
        foreach ($topf as $rid => $t) {
            if (($t['bedarf'] ?? 0) <= 0) {
                continue;
            }
            $out[] = ['recipe_id' => (int) $rid, 'name' => (string) ($namen[$rid] ?? "Rezept #{$rid}"), 'base' => $t['base'],
                'einheit' => $ep->anzeigeEinheit($t['base']), 'abziehen' => (bool) $t['abziehen'],
                'im_lager' => $ep->anzeigeMenge($t['start'], $t['base']), 'bedarf' => $ep->anzeigeMenge($t['bedarf'], $t['base']),
                'abgezogen' => $ep->anzeigeMenge($t['genutzt'], $t['base']), 'abgezogen_basis' => round((float) $t['genutzt'], 4)];
        }

        return $out;
    }

    public function overrideKey(string $sourceRef, ?int $gpId): ?string
    {
        return $gpId !== null ? $sourceRef.'|gp:'.$gpId : null;
    }

    public function unresolved(array $source, string $label, string $code, string $message): array
    {
        return [
            'type' => (string) ($source['type'] ?? ''),
            'id' => $source['id'] ?? null,
            'label' => $label,
            'code' => $code,
            'message' => $message,
        ];
    }

    public function strategieAusWert(LeadLaStrategie|string|null $strategy): ?LeadLaStrategie
    {
        if ($strategy instanceof LeadLaStrategie) {
            return $strategy;
        }

        return $strategy !== null && $strategy !== '' ? LeadLaStrategie::tryFrom((string) $strategy) : null;
    }
}
