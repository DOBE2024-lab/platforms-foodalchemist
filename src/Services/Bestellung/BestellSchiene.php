<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;

/**
 * Bestell-Schiene (98 · 3.3 S5, aus OrderService verschoben): offenen Entwurf je Lieferant und Liefertag holen oder
 * anlegen, Bedarfs-Beitrag schreiben, Besitz-Guards für Bestellung und Entwurfszeile. Unterster Teil der Bestellung —
 * nutzt keinen anderen Teil und nicht die Fassade OrderService.
 */
final class BestellSchiene
{
    /**
     * Ein-offener-draft-Guard je (team, supplier, Liefertag): Transaktion + Lock gegen
     * Doppelklick. `$deliveryDate` = Liefertag (Y-m-d) oder null (undatierter Bucket) — so
     * koexistieren mehrere offene Bestellungen desselben Lieferanten für verschiedene
     * Liefertage. Der Liefertag ist damit Teil des Schlüssels, nicht nur ein Kopf-Feld.
     */
    public function draftForSupplier(Team $team, int $supplierId, ?string $deliveryDate = null, ?int $userId = null): FoodAlchemistOrder
    {
        $deliveryDate = ($deliveryDate !== null && $deliveryDate !== '') ? $deliveryDate : null;

        return DB::transaction(function () use ($team, $supplierId, $deliveryDate, $userId) {
            $draft = FoodAlchemistOrder::where('team_id', $team->id)
                ->where('supplier_id', $supplierId)
                ->where('status', OrderStatus::Draft->value)
                ->when($deliveryDate !== null, fn ($q) => $q->whereDate('desired_delivery_date', $deliveryDate))
                ->when($deliveryDate === null, fn ($q) => $q->whereNull('desired_delivery_date'))
                ->lockForUpdate()
                ->first();

            return $draft ?? FoodAlchemistOrder::create([
                'team_id' => $team->id,
                'supplier_id' => $supplierId,
                'status' => OrderStatus::Draft->value,
                'desired_delivery_date' => $deliveryDate,
                'created_by' => $userId,
                'total_net' => 0,
            ]);
        });
    }

    /** Setzt/ersetzt den Beitrag EINER Quelle an der Artikel-Zeile (E10). */
    public function upsertContribution(Team $team, FoodAlchemistOrder $draft, array $pos, string $sourceRef): void
    {
        $laId = $pos['lead_la_id'] ?? null;
        $gpId = $pos['gp_id'] ?? null;
        $g = round((float) ($pos['menge_g'] ?? 0), 2);
        if ($g <= 0) {
            return;
        }

        $query = FoodAlchemistOrderLine::where('order_id', $draft->id);
        $laId !== null
            ? $query->where('supplier_item_id', $laId)
            : $query->whereNull('supplier_item_id')->where('gp_id', $gpId);
        $line = $query->first();

        if ($line === null) {
            $line = new FoodAlchemistOrderLine([
                'team_id' => $team->id,
                'order_id' => $draft->id,
                'supplier_item_id' => $laId,
                'gp_id' => $gpId,
                'source_contributions' => [],
                'position' => (int) FoodAlchemistOrderLine::where('order_id', $draft->id)->max('position') + 1,
            ]);
        }

        $contrib = $line->source_contributions ?? [];
        $contrib[$sourceRef] = $g;                 // E10: gleiche Quelle ersetzt ihren Beitrag
        $line->source_contributions = $contrib;
        $line->save();
    }

    public function ownedOrder(Team $team, int $orderId): FoodAlchemistOrder
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->findOrFail($orderId);
        if (! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Bestellung gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }

        return $order;
    }

    public function ownedDraftLine(Team $team, int $lineId): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Bestellzeile gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein offener Entwurf ist editierbar.');
        }

        return $line;
    }
}
