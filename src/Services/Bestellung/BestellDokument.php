<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;

/**
 * Ausgaben einer Bestellung (98 · 3.3 S5, aus OrderService verschoben): Bestelldokument und die mailto-Daten für
 * Bestellung, Storno und Produktions-Storno. Schreibt nichts. Nutzt BestellSummen, BestellAmpeln und das
 * Herkunfts-Format aus BestellHerkunft, nicht die Fassade und nicht ProductionOrderService.
 */
final class BestellDokument
{
    public function __construct(
        private BestellSummen $summen,
        private BestellAmpeln $ampeln,
    ) {}

    /** S3: Volldaten für Bestell-Dokument (PDF/Druck/CSV) — Lieferant-Stammdaten + Zeilen. */
    public function dokument(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->with(['supplier', 'lines.supplierItem'])->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        $sup = $order->supplier;
        $invoiceDueDate = $this->summen->invoiceDueDate($order);

        return [
            'id' => (int) $order->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'created_at' => $order->created_at?->format('d.m.Y'),
            'sent_at' => $order->sent_at?->format('d.m.Y H:i'),
            'confirmed_at' => $order->confirmed_at?->format('d.m.Y H:i'),
            'delivered_at' => $order->delivered_at?->format('d.m.Y H:i'),
            'supplier_order_number' => $order->supplier_order_number,
            'confirmed_delivery_date' => $order->confirmed_delivery_date?->toDateString(),
            'supplier_confirmation_note' => $order->supplier_confirmation_note,
            'invoice_number' => $order->invoice_number,
            'invoice_date' => $order->invoice_date?->toDateString(),
            'invoice_due_date' => $invoiceDueDate?->toDateString(),
            'payment_term_days' => $sup?->payment_term_days,
            'payment_status' => $order->payment_status,
            'invoice_paid_at' => $order->invoice_paid_at?->toDateString(),
            'payment_note' => $order->payment_note,
            'approval_status' => $order->approval_status,
            'approval_requested_at' => $order->approval_requested_at?->format('Y-m-d H:i'),
            'approved_at' => $order->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $order->approved_by !== null ? (int) $order->approved_by : null,
            'approval_note' => $order->approval_note,
            'invoice_note' => $order->invoice_note,
            'total_net' => (float) $order->total_net,
            'moq' => $this->ampeln->moqAmpel($order),
            'receipt' => $this->summen->receiptSummary($order),
            'invoice' => $this->summen->invoiceSummary($order),
            'payment' => $this->summen->paymentSummary($order),
            'approval' => $this->summen->approvalSummary($order),
            'claims' => $this->summen->claimSummary($order),
            'quota' => $this->summen->quotaSummary($order),
            'lieferant' => [
                'name' => $sup?->name,
                'email_order' => $sup?->email_order,
                'address' => $sup?->address,
                'postal_code' => $sup?->postal_code,
                'city' => $sup?->city,
            ],
            'zeilen' => $order->lines->map(fn ($l) => [
                'article_number' => $l->article_number,
                'designation' => $l->designation,
                'packaging_unit' => $l->packaging_unit,
                'pack_qty' => $l->pack_qty !== null ? (float) $l->pack_qty : null,
                'unit_code' => $l->unit_code,
                'qty_packs' => (float) $l->qty_packs,
                'pack_price' => $l->pack_price !== null ? (float) $l->pack_price : null,
                'line_total' => (float) $l->line_total,
                'received_qty_packs' => $l->received_qty_packs !== null ? (float) $l->received_qty_packs : null,
                'receipt_diff_packs' => $l->received_qty_packs !== null ? round((float) $l->received_qty_packs - (float) $l->qty_packs, 2) : null,
                'received_note' => $l->received_note,
                'invoice_qty_packs' => $l->invoice_qty_packs !== null ? (float) $l->invoice_qty_packs : null,
                'invoice_pack_price' => $l->invoice_pack_price !== null ? (float) $l->invoice_pack_price : null,
                'invoice_line_total' => $l->invoice_qty_packs !== null && $l->invoice_pack_price !== null
                    ? round((float) $l->invoice_qty_packs * (float) $l->invoice_pack_price, 2)
                    : null,
                'invoice_diff_net' => $this->summen->lineInvoiceDiffNet($l),
                'invoice_note' => $l->invoice_note,
                'claim_status' => $l->claim_status,
                'claim_status_label' => $this->summen->claimStatusLabel($l->claim_status),
                'claim_qty_packs' => $l->claim_qty_packs !== null ? (float) $l->claim_qty_packs : null,
                'credit_expected_net' => $l->credit_expected_net !== null ? (float) $l->credit_expected_net : null,
                'claim_note' => $l->claim_note,
                'quota_consumed_packs' => $l->quota_consumed_packs !== null ? (float) $l->quota_consumed_packs : null,
                'quota_consumed_at' => $l->quota_consumed_at?->format('Y-m-d H:i'),
                'quota' => $this->summen->lineQuotaSummary($l, $order->desired_delivery_date ?? now()),
                'needed_base_g' => (float) $l->needed_base_g,
                'bestellbar' => $l->pack_price !== null && (float) $l->qty_packs > 0,
            ])->all(),
        ];
    }

    /** S3: vorbefüllte E-Mail an den Lieferanten (Bestellweg suppliers.email_order). */
    public function mailtoData(Team $team, int $orderId): array
    {
        $d = $this->dokument($team, $orderId);
        $name = $d['lieferant']['name'] ?? 'Lieferant';
        $betreff = 'Bestellung '.$name.' — '.($d['reference'] ?: ('#'.$d['id']));

        $z = ['Guten Tag,', '', 'bitte folgende Bestellung:', ''];
        foreach ($d['zeilen'] as $l) {
            $menge = rtrim(rtrim(number_format($l['qty_packs'], 2, ',', '.'), '0'), ',');
            $geb = trim(($l['packaging_unit'] ?? '').' '.($l['designation'] ?? ''));
            $z[] = "- {$menge}× {$geb}".($l['article_number'] ? " (Art. {$l['article_number']})" : '');
        }
        $z[] = '';
        if ($d['desired_delivery_date']) {
            $z[] = 'Wunsch-Liefertermin: '.$d['desired_delivery_date'];
        }
        $z[] = 'Netto gesamt: '.number_format($d['total_net'], 2, ',', '.').' €';
        $z[] = '';
        $z[] = 'Vielen Dank.';

        return ['to' => $d['lieferant']['email_order'] ?? '', 'subject' => $betreff, 'body' => implode("\n", $z)];
    }

    /** Vorbefüllte Storno-Mail für einen bereits ausgelösten Lieferantenbeleg. */
    public function cancellationMailtoData(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if ($status === OrderStatus::Cancelled || ! $order->wurdeVersendet()) {   // Storno, solange nicht storniert und nur nach Versand
            return ['to' => '', 'subject' => '', 'body' => ''];
        }

        $d = $this->dokument($team, $orderId);
        $name = $d['lieferant']['name'] ?? 'Lieferant';
        $kennung = $d['reference'] ?: ('#'.$d['id']);
        $subject = 'Stornierung unserer Bestellung '.$kennung;
        $body = [
            'Guten Tag,',
            '',
            'bitte stornieren Sie unsere Bestellung '.$kennung.' vollständig.',
            'Lieferant: '.$name,
        ];
        if ($d['desired_delivery_date']) {
            $body[] = 'Geplanter Liefertermin: '.$d['desired_delivery_date'];
        }
        $body[] = '';
        $body[] = 'Bitte bestätigen Sie uns die Stornierung kurz schriftlich.';
        $body[] = '';
        $body[] = 'Vielen Dank.';

        return [
            'to' => $d['lieferant']['email_order'] ?? '',
            'subject' => $subject,
            'body' => implode("\n", $body),
        ];
    }

    /**
     * Lieferantenkommunikation beim Produktionsstorno. Ein reiner Produktionsbeleg wird
     * vollständig storniert; bei einem Sammelbeleg werden nur die entfallenden Anteile gemeldet.
     *
     * @return array{to:string,subject:string,body:string,kind:'full'|'partial'}
     */
    public function productionCancellationMailtoData(Team $team, int $orderId, int $productionOrderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->with(['supplier', 'lines'])->findOrFail($orderId);
        $prefix = BestellHerkunft::produktionsPraefix($productionOrderId);
        $affected = [];
        $hasOtherDemand = false;

        foreach ($order->lines as $line) {
            $contributions = (array) $line->source_contributions;
            $cancelledGrams = collect($contributions)
                ->filter(fn ($value, string $ref) => str_starts_with($ref, $prefix))
                ->sum(fn ($value) => (float) $value);
            if ($cancelledGrams > 0) {
                $affected[] = [
                    'article_number' => $line->article_number,
                    'designation' => $line->designation,
                    'cancelled_grams' => (float) $cancelledGrams,
                ];
            }
            if ($line->is_manual_qty || collect(array_keys($contributions))->contains(fn (string $ref) => ! str_starts_with($ref, $prefix))) {
                $hasOtherDemand = true;
            }
        }

        $identifier = $order->reference ?: ('#'.$order->id);
        $kind = $hasOtherDemand ? 'partial' : 'full';
        $subject = $kind === 'full'
            ? 'Stornierung unserer Bestellung '.$identifier
            : 'Änderung unserer Bestellung '.$identifier;
        $body = ['Guten Tag,', ''];

        if ($kind === 'full') {
            $body[] = 'bitte stornieren Sie unsere Bestellung '.$identifier.' vollständig.';
        } else {
            $body[] = 'bei unserer Bestellung '.$identifier.' entfällt ein Teil des Bedarfs.';
            $body[] = 'Bitte reduzieren Sie folgende Positionen und senden Sie uns eine aktualisierte Bestätigung:';
            $body[] = '';
            foreach ($affected as $line) {
                $kg = rtrim(rtrim(number_format($line['cancelled_grams'] / 1000, 3, ',', '.'), '0'), ',');
                $article = $line['article_number'] ? ' (Art. '.$line['article_number'].')' : '';
                $body[] = '- '.($line['designation'] ?: 'Position').$article.': entfallender Bedarfsanteil '.$kg.' kg';
            }
        }
        $body[] = '';
        $body[] = 'Bitte bestätigen Sie uns die Änderung kurz schriftlich.';
        $body[] = '';
        $body[] = 'Vielen Dank.';

        return [
            'to' => (string) ($order->supplier?->email_order ?? ''),
            'subject' => $subject,
            'body' => implode("\n", $body),
            'kind' => $kind,
        ];
    }
}
