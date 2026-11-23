<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PurchaseJournalService;

/**
 * Eingangs-Pflege einer Bestellung (98 · 3.3 S5, aus OrderService verschoben): Status-Guards für Wareneingang und
 * Rechnung, Notizen, Reklamation, Kontingent-Verbrauch, Rechnungs-Ableitung und Einkaufsjournal. Die Einstiege mit
 * Wareneingangs-, Rechnungs- oder Lagerbezug (updateReceiptLine, schreibeWareneingangAbleitung, completeReceipt,
 * updateInvoiceLine, completeInvoiceFromReceipt, fillReceiptFromOrder) bleiben vorerst in der Fassade:
 * WareneingangService, LieferantenRechnungService und InventoryService liegen mit OrderService in einem Kreis
 * (98 · 3.4) und zögen dieses Teil sonst mit hinein. Nutzt keinen anderen Teil und nicht die Fassade.
 */
final class BestellEingang
{
    /** Spec 66 §1: Steht die Bestellung schon im Einkaufsjournal, Ist-Menge/-Preis nachziehen. */
    public function journalNachziehen(?FoodAlchemistOrder $order): void
    {
        if ($order === null) {
            return;
        }
        $journal = app(PurchaseJournalService::class);
        if ($journal->istGespiegelt($order)) {
            $journal->spiegelOrder($order->fresh('lines'));
        }
    }

    public function updateReceiptNote(Team $team, int $lineId, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardReceiptLine($team, $line);

        $line->received_note = trim((string) $note) !== '' ? trim((string) $note) : null;
        $line->save();

        return $line->refresh();
    }

    /** Spec 75c: Ableitung Rechnung → Bestellzeile. NUR für LieferantenRechnungService. */
    public function schreibeRechnungsAbleitung(Team $team, int $lineId, int|float|string|null $qtyPacks, int|float|string|null $packPrice, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardInvoiceLine($team, $line);

        $qtyEmpty = $qtyPacks === '' || $qtyPacks === null;
        $priceEmpty = $packPrice === '' || $packPrice === null;
        $line->invoice_qty_packs = $qtyEmpty ? null : max(0, (float) $qtyPacks);
        $line->invoice_pack_price = $priceEmpty ? null : max(0, (float) $packPrice);
        $line->invoice_checked_at = $qtyEmpty && $priceEmpty ? null : now();
        if ($note !== null) {
            $line->invoice_note = trim($note) !== '' ? trim($note) : null;
        }
        $line->save();
        $this->journalNachziehen($line->order);

        return $line->refresh();
    }

    public function updateInvoiceNote(Team $team, int $lineId, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardInvoiceLine($team, $line);

        $line->invoice_note = trim((string) $note) !== '' ? trim((string) $note) : null;
        $line->save();

        return $line->refresh();
    }

    /**
     * Reklamation/Gutschrift light pro Position: Status, reklamierte Menge, erwarteter
     * Gutschriftbetrag und Notiz. Keine Kreditorenbuchung, nur Nachverfolgung am Beleg.
     *
     * @param  array{claim_status?:?string, claim_qty_packs?:mixed, credit_expected_net?:mixed, claim_note?:?string}  $input
     */
    public function updateClaimLine(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardInvoiceLine($team, $line);

        if (array_key_exists('claim_status', $input)) {
            $status = trim((string) ($input['claim_status'] ?? ''));
            if ($status !== '' && ! in_array($status, ['open', 'credit_expected', 'credited', 'resolved'], true)) {
                throw new \RuntimeException('Unbekannter Reklamationsstatus. Erlaubt: open, credit_expected, credited, resolved.');
            }
            $line->claim_status = $status !== '' ? $status : null;
        }
        if (array_key_exists('claim_qty_packs', $input)) {
            $value = $input['claim_qty_packs'];
            $line->claim_qty_packs = $value === '' || $value === null ? null : max(0, (float) $value);
        }
        if (array_key_exists('credit_expected_net', $input)) {
            $value = $input['credit_expected_net'];
            $line->credit_expected_net = $value === '' || $value === null ? null : max(0, (float) $value);
        }
        if (array_key_exists('claim_note', $input)) {
            $line->claim_note = trim((string) ($input['claim_note'] ?? '')) ?: null;
        }
        if (
            ($line->claim_status === null || $line->claim_status === '')
            && ($line->claim_qty_packs !== null || $line->credit_expected_net !== null || $line->claim_note !== null)
        ) {
            $line->claim_status = 'open';
        }
        $line->save();

        return $line->refresh();
    }

    /**
     * Kontingent/Rahmenabruf am Lieferantenartikel über eine Bestellzeile pflegen.
     *
     * @param  array{quota_qty_packs?:mixed, quota_used_packs?:mixed, quota_valid_from?:?string, quota_valid_to?:?string, quota_note?:?string}  $input
     */
    public function updateLineQuota(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Bestellzeile gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }
        if ($line->supplier_item_id === null) {
            throw new \RuntimeException('Zeile hat keinen Lieferantenartikel für ein Kontingent.');
        }

        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) $line->supplier_item_id);
        if ($item === null) {
            throw new \RuntimeException('Kein Zugriff auf diesen Lieferantenartikel.');
        }

        foreach (['quota_qty_packs', 'quota_used_packs'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                $item->{$field} = $value === null || $value === '' ? null : max(0.0, (float) $value);
            }
        }
        foreach (['quota_valid_from', 'quota_valid_to'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = trim((string) ($input[$field] ?? ''));
                $item->{$field} = $value !== '' ? Carbon::parse($value)->toDateString() : null;
            }
        }
        if (array_key_exists('quota_note', $input)) {
            $item->quota_note = trim((string) ($input['quota_note'] ?? '')) ?: null;
        }
        $item->save();

        return $line->refresh();
    }

    /**
     * Schreibt den Verbrauch idempotent auf den Lieferantenartikel. Korrekturen im
     * Wareneingang addieren nur die Differenz zum bereits verbuchten Verbrauch.
     */
    public function syncQuotaConsumption(FoodAlchemistOrderLine $line, ?float $targetPacks): void
    {
        if ($line->supplier_item_id === null) {
            return;
        }

        DB::transaction(function () use ($line, $targetPacks) {
            $freshLine = FoodAlchemistOrderLine::lockForUpdate()->find($line->id);
            if ($freshLine === null || $freshLine->supplier_item_id === null) {
                return;
            }

            $item = FoodAlchemistSupplierItem::lockForUpdate()->find((int) $freshLine->supplier_item_id);
            if ($item === null || $item->quota_qty_packs === null) {
                return;
            }

            $old = (float) ($freshLine->quota_consumed_packs ?? 0);
            $new = $targetPacks !== null ? max(0.0, round($targetPacks, 2)) : 0.0;
            $delta = round($new - $old, 2);
            if (abs($delta) >= 0.01) {
                $item->quota_used_packs = max(0.0, round((float) ($item->quota_used_packs ?? 0) + $delta, 2));
                $item->save();
            }

            $freshLine->quota_consumed_packs = $new > 0 ? $new : null;
            $freshLine->quota_consumed_at = $new > 0 ? now() : null;
            $freshLine->save();
        });
    }

    public function guardReceiptLine(Team $team, FoodAlchemistOrderLine $line): void
    {
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Bestellzeile gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }

        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed], true)) {
            throw new \RuntimeException('Wareneingang ist nur für gesendete oder bestätigte Bestellungen möglich.');
        }
    }

    private function guardInvoiceLine(Team $team, FoodAlchemistOrderLine $line): void
    {
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Bestellzeile gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }

        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Rechnungsprüfung ist erst nach dem Absenden möglich.');
        }
    }
}
