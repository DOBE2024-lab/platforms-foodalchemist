<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Kopf-Pflege einer Bestellung (98 · 3.3 S5, aus OrderService verschoben): neuer Entwurf, leere Entwürfe aufräumen,
 * Kopf, Lieferantenbestätigung, Rechnungskopf, Zahlung und Freigabe. Was neu rechnet (recomputeLine/recomputeOrder
 * und damit updateLine, removeLine, addManualLine, createBackorderFromReceipt, setStatus), bleibt in der Fassade:
 * PriceService und LeadLaService liegen mit OrderService in einem Kreis (98 · 3.4) und zögen dieses Teil sonst mit
 * hinein. Nutzt BestellSchiene, nicht die Fassade.
 */
final class BestellPflege
{
    public function __construct(
        private BestellSchiene $schiene,
    ) {}

    /** Entfernt leere eigene Entwurfs-Bestellungen aus der operativen Liste. */
    public function deleteEmptyDrafts(Team $team): int
    {
        $drafts = FoodAlchemistOrder::visibleToTeam($team)
            ->where('team_id', $team->id)
            ->where('status', OrderStatus::Draft->value)
            ->whereDoesntHave('lines')
            ->get();

        foreach ($drafts as $draft) {
            $draft->delete();
        }

        return $drafts->count();
    }

    /**
     * Spec 20 · E2 — „Neue Bestellung": eine (leere) Draft-Schiene für einen Lieferanten
     * anlegen bzw. die bestehende offene zurückgeben (findOrCreate, idempotent je (team,
     * supplier, Liefertag)). Nur team-sichtbare Lieferanten (D1). Optionale Kopf-Felder werden
     * direkt gesetzt (reference/desired_delivery_date/note); der Liefertag ist Teil des Schlüssels.
     *
     * @param  array{reference?:?string, desired_delivery_date?:?string, note?:?string}  $header
     */
    public function createDraft(Team $team, int $supplierId, array $header = [], ?int $userId = null): FoodAlchemistOrder
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->find($supplierId);
        if ($supplier === null) {
            throw new \RuntimeException('Lieferant nicht gefunden.');
        }
        // Liefertag ist Teil des Draft-Schlüssels → schon beim find-or-create setzen.
        $deliveryDate = ($header['desired_delivery_date'] ?? null) ?: null;
        $draft = $this->schiene->draftForSupplier($team, (int) $supplier->id, $deliveryDate, $userId);

        $kopf = array_intersect_key($header, array_flip(['reference', 'note']));
        if ($kopf !== []) {
            $draft = $this->updateHeader($team, (int) $draft->id, $kopf);
        }

        return $draft;
    }

    /**
     * Spec 20 · E1 — Kopf-Felder einer OFFENEN Schiene pflegen (Anlass, Wunsch-Liefertermin,
     * Notiz). Nur eigene Belege, nur solange draft; gesendete Belege sind eingefroren (E2).
     *
     * @param  array{reference?:?string, desired_delivery_date?:?string, note?:?string}  $input
     */
    public function updateHeader(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->schiene->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein offener Entwurf ist editierbar.');
        }
        if (array_key_exists('reference', $input)) {
            $order->reference = ($input['reference'] ?? '') !== '' ? $input['reference'] : null;
        }
        if (array_key_exists('note', $input)) {
            $order->note = ($input['note'] ?? '') !== '' ? $input['note'] : null;
        }
        if (array_key_exists('desired_delivery_date', $input)) {
            $neuerLiefertag = ($input['desired_delivery_date'] ?? '') !== '' ? $input['desired_delivery_date'] : null;
            // Liefertag ist Teil des Draft-Schlüssels: kein zweiter offener Entwurf je (Lieferant, Liefertag).
            $kollision = FoodAlchemistOrder::where('team_id', $team->id)
                ->where('supplier_id', $order->supplier_id)
                ->where('status', OrderStatus::Draft->value)
                ->where('id', '!=', $order->id)
                ->when($neuerLiefertag !== null, fn ($q) => $q->whereDate('desired_delivery_date', $neuerLiefertag))
                ->when($neuerLiefertag === null, fn ($q) => $q->whereNull('desired_delivery_date'))
                ->exists();
            if ($kollision) {
                throw new \RuntimeException('Für diesen Lieferanten gibt es an diesem Liefertag bereits eine offene Bestellung.');
            }
            $order->desired_delivery_date = $neuerLiefertag;
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Lieferantenbestätigung nach dem Absenden pflegen: Nummer, bestätigter Liefertag, Notiz.
     *
     * @param  array{supplier_order_number?:?string, confirmed_delivery_date?:?string, supplier_confirmation_note?:?string}  $input
     */
    public function updateSupplierConfirmation(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->schiene->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Lieferantenbestätigung ist erst nach dem Absenden möglich.');
        }

        if (array_key_exists('supplier_order_number', $input)) {
            $order->supplier_order_number = trim((string) ($input['supplier_order_number'] ?? '')) ?: null;
        }
        if (array_key_exists('confirmed_delivery_date', $input)) {
            $order->confirmed_delivery_date = ($input['confirmed_delivery_date'] ?? '') !== '' ? $input['confirmed_delivery_date'] : null;
        }
        if (array_key_exists('supplier_confirmation_note', $input)) {
            $order->supplier_confirmation_note = trim((string) ($input['supplier_confirmation_note'] ?? '')) ?: null;
        }
        if ($status === OrderStatus::Sent) {
            $order->status = OrderStatus::Confirmed;
            $order->confirmed_at ??= now();
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Rechnungskopf pflegen; die zeilenweise Prüfung bleibt in `updateInvoiceLine`.
     *
     * @param  array{invoice_number?:?string, invoice_date?:?string, invoice_note?:?string}  $input
     */
    public function updateInvoiceHeader(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->schiene->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Rechnungskopf ist erst nach dem Absenden möglich.');
        }

        if (array_key_exists('invoice_number', $input)) {
            $order->invoice_number = trim((string) ($input['invoice_number'] ?? '')) ?: null;
        }
        if (array_key_exists('invoice_date', $input)) {
            $order->invoice_date = ($input['invoice_date'] ?? '') !== '' ? $input['invoice_date'] : null;
        }
        if (array_key_exists('invoice_note', $input)) {
            $order->invoice_note = trim((string) ($input['invoice_note'] ?? '')) ?: null;
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Offene-Posten-light: Zahlungsstatus am Rechnungsbeleg pflegen.
     *
     * @param  array{payment_status?:?string, invoice_paid_at?:?string, payment_note?:?string}  $input
     */
    public function updatePayment(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->schiene->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Zahlungsstatus ist erst nach dem Absenden möglich.');
        }
        if ($order->invoice_number === null && $order->invoice_date === null) {
            throw new \RuntimeException('Zahlungsstatus braucht zuerst einen Rechnungskopf.');
        }

        if (array_key_exists('payment_status', $input)) {
            $paymentStatus = trim((string) ($input['payment_status'] ?? ''));
            if (in_array($paymentStatus, ['paid', 'disputed'], true)) {
                // Spec 77a: bezahlt/strittig ab Freigeben — wie „Rechnung bezahlt" im Rechnungs-Service
                app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Freigeben, 'Zahlungsstatus bezahlt oder strittig setzen');
            }
            if ($paymentStatus !== '' && ! in_array($paymentStatus, ['open', 'paid', 'disputed'], true)) {
                throw new \RuntimeException('Unbekannter Zahlungsstatus. Erlaubt: open, paid, disputed.');
            }
            $order->payment_status = $paymentStatus !== '' ? $paymentStatus : null;
        }
        if (array_key_exists('invoice_paid_at', $input)) {
            $order->invoice_paid_at = ($input['invoice_paid_at'] ?? '') !== '' ? $input['invoice_paid_at'] : null;
        }
        if (array_key_exists('payment_note', $input)) {
            $order->payment_note = trim((string) ($input['payment_note'] ?? '')) ?: null;
        }
        if (($order->payment_status ?? null) === 'paid' && $order->invoice_paid_at === null) {
            $order->invoice_paid_at = now()->toDateString();
        }
        if (($order->payment_status ?? null) !== 'paid' && array_key_exists('payment_status', $input)) {
            $order->invoice_paid_at = array_key_exists('invoice_paid_at', $input) ? $order->invoice_paid_at : null;
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Freigabe-light am Bestellkopf. Kein Rollenworkflow, aber ein prüfbarer Zustand vor Versand.
     *
     * @param  array{approval_status?:?string, approval_note?:?string}  $input
     */
    public function updateApproval(Team $team, int $orderId, array $input, ?int $userId = null): FoodAlchemistOrder
    {
        $order = $this->schiene->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Draft, OrderStatus::Sent, OrderStatus::Confirmed], true)) {
            throw new \RuntimeException('Freigabe ist nur vor Abschluss der Bestellung editierbar.');
        }

        if (array_key_exists('approval_status', $input)) {
            $approvalStatus = trim((string) ($input['approval_status'] ?? ''));
            if ($approvalStatus !== '' && ! in_array($approvalStatus, ['requested', 'approved', 'rejected'], true)) {
                throw new \RuntimeException('Unbekannter Freigabestatus. Erlaubt: requested, approved, rejected.');
            }
            // Spec 75c / 61: freigeben und ablehnen nur mit Recht „Freigeben", anfragen ab Kuratieren.
            // Ohne Benutzer (System, Bestellrunde) keine Prüfung.
            if ($userId !== null) {
                in_array($approvalStatus, ['approved', 'rejected'], true)
                    ? app(FaRechte::class)->pruefeId($userId, $team, \Platform\FoodAlchemist\Enums\FaRolle::Freigeben, 'Bestellung freigeben oder ablehnen')
                    : app(FaRechte::class)->pruefeId($userId, $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Freigabe anfragen');
            }
            $order->approval_status = $approvalStatus !== '' ? $approvalStatus : null;
            if ($approvalStatus === 'requested') {
                $order->approval_requested_at ??= now();
                $order->approved_at = null;
                $order->approved_by = null;
            } elseif ($approvalStatus === 'approved') {
                $order->approved_at = now();
                $order->approved_by = $userId;
            } elseif ($approvalStatus === 'rejected' || $approvalStatus === '') {
                $order->approved_at = null;
                $order->approved_by = null;
            }
        }
        if (array_key_exists('approval_note', $input)) {
            $order->approval_note = trim((string) ($input['approval_note'] ?? '')) ?: null;
        }
        $order->save();

        return $order->refresh();
    }
}
