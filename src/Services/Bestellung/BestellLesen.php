<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Services\Plattform\StandortService;

/**
 * Lesesicht auf Bestellungen (98 · 3.3 S5, aus OrderService verschoben): Liste je Team (Standort-Sichtbarkeit,
 * Status, Datumsfenster) und Bedarf je Zeile. Schreibt nichts. `detail` bleibt vorerst in der Fassade: es braucht den
 * Lagerbestand über InventoryService, der in der großen Kreis-Komponente liegt (98 · 3.4) und dieses Teil sonst mit
 * hineinzöge.
 */
final class BestellLesen
{
    /**
     * Bestell-Liste fürs Team, optional nach Status + Datumsfenster gefiltert.
     * `$filters`: datumsbasis ('liefertag'|'bestelldatum'), von/bis (Y-m-d oder null).
     *  • liefertag   → Fenster/Sortierung auf `desired_delivery_date` (aufsteigend, undatiert ans Ende);
     *  • bestelldatum → Fenster/Sortierung auf `created_at` (angelegt, absteigend).
     *
     * @param  array{datumsbasis?:string, von?:?string, bis?:?string}  $filters
     * @return Collection<int, FoodAlchemistOrder>
     */
    public function listForTeam(Team $team, ?string $status = null, array $filters = []): Collection
    {
        $basis = ($filters['datumsbasis'] ?? 'liefertag') === 'bestelldatum' ? 'bestelldatum' : 'liefertag';
        $spalte = $basis === 'bestelldatum' ? 'created_at' : 'desired_delivery_date';
        $von = ($filters['von'] ?? null) ?: null;
        $bis = ($filters['bis'] ?? null) ?: null;

        // Spec 77c: Team-Brille — im Oberteam ggf. Unter-Teams (Standorte) statt Kette aufwärts
        $q = app(StandortService::class)->leseBereichOderSichtbar(FoodAlchemistOrder::query(), $team)
            ->with([
                'supplier:id,name,min_order_value,free_shipping_threshold,delivery_days,order_cutoff_time,order_lead_days,payment_term_days',
                'lines:id,order_id,supplier_item_id,gp_id,article_number,designation,packaging_unit,qty_packs,pack_price,received_qty_packs,quota_consumed_packs,source_contributions,note,received_note,invoice_note,claim_note',
                'lines.supplierItem:id,designation,article_number',
                'lines.gp:id,name',
            ])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($von !== null, fn ($q) => $q->whereDate($spalte, '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate($spalte, '<=', $bis));

        if ($basis === 'bestelldatum') {
            $q->orderByDesc('created_at');
        } else {
            // Liefertag aufsteigend (anstehende zuerst), undatierte ans Ende, dann jüngste zuerst.
            $q->orderByRaw('desired_delivery_date IS NULL')
                ->orderBy('desired_delivery_date')
                ->orderByDesc('updated_at');
        }

        return $q->get();
    }

    /**
     * E1-Bug-Fix: Bedarf in der korrekten Grundeinheit des Lead-LA. `needed_base_g` liegt
     * IMMER in Gramm (Basis); Stück-Artikel werden über das Stückgewicht zurückgerechnet
     * (spiegelt GebindeRechner), statt fälschlich als „kg" ausgewiesen zu werden.
     *
     * @return array{0:float, 1:string} [Menge, Einheit]
     */
    public function zeileBedarf(FoodAlchemistOrderLine $line): array
    {
        $g = (float) $line->needed_base_g;
        $unit = $line->unit_code;

        if ($unit === 'Stk') {
            $pieceG = $line->gp?->piece_default_g !== null ? (float) $line->gp->piece_default_g : null;
            if ($pieceG !== null && $pieceG > 0.0) {
                return [round($g / $pieceG, 2), 'Stk'];
            }

            return [round($g, 0), 'g'];   // ohne Stückgewicht nicht in Stück umrechenbar → Gramm belassen
        }

        // kg/l (Dichte 1.0) und Fallback für sonstige/leere Einheiten.
        return [round($g / 1000.0, 3), $unit === 'l' ? 'l' : 'kg'];
    }
}
