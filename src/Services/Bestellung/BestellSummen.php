<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Carbon\Carbon;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Summen einer Bestellung (98 · 3.3 S5, aus OrderService verschoben; Spec 85 §6 „OrderSummaries“): Wareneingang,
 * Rechnung, Zahlung, Reklamation, Kontingent, Freigabe und die Zeilen-Status dazu. Reines Lesen über die geladenen
 * Zeilen — nutzt keinen anderen Teil und nicht die Fassade OrderService.
 */
final class BestellSummen
{
    /**
     * @param  list<array<string,mixed>>  $lines
     * @return array{tracked:int, covered:int, shortage:int}
     */
    public function inventorySummaryFromLines(array $lines): array
    {
        $tracked = 0;
        $covered = 0;
        $shortage = 0;

        foreach ($lines as $line) {
            $inventory = $line['inventory'] ?? null;
            if (! is_array($inventory)) {
                continue;
            }
            $tracked++;
            if ((float) ($inventory['shortage_base'] ?? 0) > 0.0001) {
                $shortage++;
            } else {
                $covered++;
            }
        }

        return [
            'tracked' => $tracked,
            'covered' => $covered,
            'shortage' => $shortage,
        ];
    }

    /** @return array{lines:int, booked:int, missing:int, differences:int, backorderable:int, received_net:float} */
    public function receiptSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines');
        $booked = 0;
        $differences = 0;
        $backorderable = 0;
        $receivedNet = 0.0;

        foreach ($order->lines as $line) {
            if ($line->received_qty_packs !== null) {
                $booked++;
                $receivedNet += $line->pack_price !== null ? (float) $line->received_qty_packs * (float) $line->pack_price : 0.0;
                if (abs((float) $line->received_qty_packs - (float) $line->qty_packs) >= 0.01) {
                    $differences++;
                }
                if ((float) $line->received_qty_packs < (float) $line->qty_packs) {
                    $backorderable++;
                }
            }
        }

        $lines = $order->lines->count();

        return [
            'lines' => $lines,
            'booked' => $booked,
            'missing' => max(0, $lines - $booked),
            'differences' => $differences,
            'backorderable' => $backorderable,
            'received_net' => round($receivedNet, 2),
        ];
    }

    /** @return array{lines:int, checked:int, missing:int, differences:int, invoice_net:float, diff_net:float} */
    public function invoiceSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines');
        $checked = 0;
        $differences = 0;
        $invoiceNet = 0.0;
        $diffNet = 0.0;

        foreach ($order->lines as $line) {
            if ($line->invoice_qty_packs !== null || $line->invoice_pack_price !== null) {
                $checked++;
                $lineTotal = $line->invoice_qty_packs !== null && $line->invoice_pack_price !== null
                    ? round((float) $line->invoice_qty_packs * (float) $line->invoice_pack_price, 2)
                    : 0.0;
                $invoiceNet += $lineTotal;
                $diff = $this->lineInvoiceDiffNet($line);
                $diffNet += $diff ?? 0.0;
                if ($this->lineInvoiceStatus($line) !== 'ok') {
                    $differences++;
                }
            }
        }

        $lines = $order->lines->count();

        return [
            'lines' => $lines,
            'checked' => $checked,
            'missing' => max(0, $lines - $checked),
            'differences' => $differences,
            'invoice_net' => round($invoiceNet, 2),
            'diff_net' => round($diffNet, 2),
        ];
    }

    /** @return array{status:?string, label:string, state:string, due_date:?string, paid_at:?string, overdue_days:int, note:?string} */
    public function paymentSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('supplier');
        $dueDate = $this->invoiceDueDate($order);
        $status = $order->payment_status;
        if ($status === null && ($order->invoice_number !== null || $order->invoice_date !== null)) {
            $status = 'open';
        }

        $state = 'no_invoice';
        $label = 'keine Rechnung';
        $overdueDays = 0;
        if ($status === 'paid') {
            $state = 'paid';
            $label = 'bezahlt';
        } elseif ($status === 'disputed') {
            $state = 'disputed';
            $label = 'strittig';
        } elseif ($status === 'open') {
            $state = 'open';
            $label = 'offen';
            if ($dueDate !== null) {
                $today = Carbon::today();
                if ($dueDate->isPast() && ! $dueDate->isSameDay($today)) {
                    $state = 'overdue';
                    $label = 'überfällig';
                    // Carbon 3: diffInDays() liefert float — der dokumentierte Vertrag (overdue_days:int) braucht den Cast.
                    $overdueDays = (int) $dueDate->diffInDays($today);
                } elseif ($dueDate->isSameDay($today)) {
                    $state = 'due_today';
                    $label = 'heute fällig';
                }
            }
        }

        return [
            'status' => $status,
            'label' => $label,
            'state' => $state,
            'due_date' => $dueDate?->toDateString(),
            'paid_at' => $order->invoice_paid_at?->toDateString(),
            'overdue_days' => $overdueDays,
            'note' => $order->payment_note,
        ];
    }

    /** @return array{lines:int, open:int, credit_expected:int, credited:int, resolved:int, credit_expected_net:float} */
    public function claimSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines');
        $summary = [
            'lines' => 0,
            'open' => 0,
            'credit_expected' => 0,
            'credited' => 0,
            'resolved' => 0,
            'credit_expected_net' => 0.0,
        ];

        foreach ($order->lines as $line) {
            $status = $line->claim_status;
            if ($status === null || $status === '') {
                continue;
            }
            $summary['lines']++;
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            if ($line->credit_expected_net !== null) {
                $summary['credit_expected_net'] += (float) $line->credit_expected_net;
            }
        }

        $summary['credit_expected_net'] = round($summary['credit_expected_net'], 2);

        return $summary;
    }

    /** @return array{lines:int, invalid:int, exceeded:int, remaining_after:float} */
    public function quotaSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines.supplierItem');
        $date = $order->desired_delivery_date ?? now();
        $summary = ['lines' => 0, 'invalid' => 0, 'exceeded' => 0, 'remaining_after' => 0.0];

        foreach ($order->lines as $line) {
            $quota = $this->lineQuotaSummary($line, $date);
            if ($quota === null) {
                continue;
            }
            $summary['lines']++;
            if (! $quota['is_valid_date']) {
                $summary['invalid']++;
            }
            if ($quota['exceeded']) {
                $summary['exceeded']++;
            }
            $summary['remaining_after'] += (float) $quota['remaining_after_packs'];
        }

        $summary['remaining_after'] = round($summary['remaining_after'], 2);

        return $summary;
    }

    /**
     * @return array{qty_packs:float, used_packs:float, consumed_packs:float, remaining_before_packs:float, remaining_after_packs:float, valid_from:?string, valid_to:?string, is_valid_date:bool, exceeded:bool, note:?string}|null
     */
    public function lineQuotaSummary(FoodAlchemistOrderLine $line, Carbon|\DateTimeInterface|string|null $date = null): ?array
    {
        $item = $line->supplierItem ?? ($line->supplier_item_id !== null ? FoodAlchemistSupplierItem::find((int) $line->supplier_item_id) : null);
        if ($item === null || $item->quota_qty_packs === null) {
            return null;
        }

        $checkDate = $date instanceof Carbon
            ? $date->copy()
            : ($date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date ?: now()));
        $qty = max(0.0, (float) $item->quota_qty_packs);
        $used = max(0.0, (float) ($item->quota_used_packs ?? 0));
        $consumed = max(0.0, (float) ($line->quota_consumed_packs ?? 0));
        $pendingConsumption = $consumed > 0 ? $consumed : (float) $line->qty_packs;
        $remainingBefore = round($qty - $used + $consumed, 2);
        $remainingAfter = round($remainingBefore - $pendingConsumption, 2);
        $validFrom = $item->quota_valid_from;
        $validTo = $item->quota_valid_to;
        $isValidDate = true;
        if ($validFrom !== null && $checkDate->lt($validFrom)) {
            $isValidDate = false;
        }
        if ($validTo !== null && $checkDate->gt($validTo)) {
            $isValidDate = false;
        }

        return [
            'qty_packs' => round($qty, 2),
            'used_packs' => round($used, 2),
            'consumed_packs' => round($consumed, 2),
            'remaining_before_packs' => $remainingBefore,
            'remaining_after_packs' => $remainingAfter,
            'valid_from' => $validFrom?->toDateString(),
            'valid_to' => $validTo?->toDateString(),
            'is_valid_date' => $isValidDate,
            'exceeded' => $remainingAfter < 0,
            'note' => $item->quota_note,
        ];
    }

    /** @return array{status:?string, label:string, state:string, requested_at:?string, approved_at:?string, approved_by:?int, note:?string} */
    public function approvalSummary(FoodAlchemistOrder $order): array
    {
        $status = $order->approval_status;
        $state = $status ?: 'not_required';

        return [
            'status' => $status,
            'label' => match ($status) {
                'requested' => 'Freigabe angefragt',
                'approved' => 'freigegeben',
                'rejected' => 'abgelehnt',
                default => 'keine Freigabe',
            },
            'state' => $state,
            'requested_at' => $order->approval_requested_at?->format('Y-m-d H:i'),
            'approved_at' => $order->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $order->approved_by !== null ? (int) $order->approved_by : null,
            'note' => $order->approval_note,
        ];
    }

    public function claimStatusLabel(?string $status): string
    {
        return match ($status) {
            'open' => 'offen',
            'credit_expected' => 'Gutschrift erwartet',
            'credited' => 'gutgeschrieben',
            'resolved' => 'erledigt',
            default => '—',
        };
    }

    public function invoiceDueDate(FoodAlchemistOrder $order): ?Carbon
    {
        $invoiceDate = $order->invoice_date;
        $paymentTermDays = $order->supplier?->payment_term_days;
        if ($invoiceDate === null || $paymentTermDays === null) {
            return null;
        }

        return $invoiceDate->copy()->addDays(max(0, (int) $paymentTermDays));
    }

    public function lineReceiptStatus(FoodAlchemistOrderLine $line): string
    {
        if ($line->received_qty_packs === null) {
            return 'offen';
        }

        $diff = round((float) $line->received_qty_packs - (float) $line->qty_packs, 2);
        if (abs($diff) < 0.01) {
            return 'ok';
        }

        return $diff < 0 ? 'unterliefert' : 'überliefert';
    }

    public function lineInvoiceDiffNet(FoodAlchemistOrderLine $line): ?float
    {
        if ($line->invoice_qty_packs === null || $line->invoice_pack_price === null) {
            return null;
        }

        $invoiceTotal = (float) $line->invoice_qty_packs * (float) $line->invoice_pack_price;
        $basisTotal = $line->received_qty_packs !== null
            ? (float) $line->received_qty_packs * (float) ($line->pack_price ?? 0)
            : (float) $line->line_total;

        return round($invoiceTotal - $basisTotal, 2);
    }

    public function lineInvoiceStatus(FoodAlchemistOrderLine $line): string
    {
        if ($line->invoice_qty_packs === null && $line->invoice_pack_price === null) {
            return 'offen';
        }
        if ($line->invoice_qty_packs === null || $line->invoice_pack_price === null) {
            return 'unvollständig';
        }

        $qtyBasis = $line->received_qty_packs !== null ? (float) $line->received_qty_packs : (float) $line->qty_packs;
        $qtyDiff = round((float) $line->invoice_qty_packs - $qtyBasis, 2);
        $priceDiff = round((float) $line->invoice_pack_price - (float) ($line->pack_price ?? 0), 4);
        if (abs($qtyDiff) < 0.01 && abs($priceDiff) < 0.0001) {
            return 'ok';
        }

        return 'abweichung';
    }
}
