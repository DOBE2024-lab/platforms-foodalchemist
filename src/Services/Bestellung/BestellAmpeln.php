<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Carbon\Carbon;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;

/**
 * Ampeln einer Bestellung (98 · 3.3 S5, aus OrderService verschoben): Warn-Etiketten, Versand-Sperren, MOQ-Ampel,
 * Logistik (Bestellschluss, Cutoff, Liefertage). Hält das Register HARTE_SPERREN / WEICHE_HINWEISE; die Fassade
 * OrderService führt beide als Alias. Nutzt BestellSummen, nicht die Fassade.
 */
final class BestellAmpeln
{
    /**
     * HARTE VERSANDSPERREN. Konstante statt Inline-Array in `sendBlockers()`, weil eine neue
     * Sperr-Warnung dort sonst STILL zur bloßen Warnung degradiert: der Beleg zeigt den Hinweis,
     * der Absenden-Knopf wird trotzdem aktiv, `setStatus(Sent)` friert den Snapshot ein und das
     * Einkaufsjournal bucht ihn als Ist-Einkauf. Nicht umkehrbar in dem Sinn, der zählt — die
     * Bestellung ist beim Lieferanten raus, auf den falschen Tag. Und kein Test schlägt an,
     * weil die Warnung ja erzeugt wird.
     *
     * `public`, damit der Register-Test die Liste HIER liest statt sie nachzubauen — eine im
     * Test kopierte Liste driftet vom Original weg und prüft am Ende sich selbst.
     */
    public const HARTE_SPERREN = [
        'leer',
        'Klärung',
        'Liefertag nicht beliefert',
        'Liefertag vorbei',
        'Bestellschluss verpasst',
        'Freigabe abgelehnt',
    ];
    /**
     * Hinweise, die absichtlich NICHT sperren — die Küche kommt trotzdem an die Ware.
     *
     * Zusammen mit HARTE_SPERREN ist das das VOLLSTÄNDIGE Register aller Etiketten, die
     * `orderWarnings()` und `supplierLogistikWarnings()` erzeugen können. Ein Wächter-Test
     * liest die Literale aus dem Quelltext dieser Klasse und hält jedes gegen das Register:
     * ein neues Etikett ist rot, bis jemand es ausdrücklich hart ODER weich stellt. Damit ist
     * die Vollzähligkeit erzwungen und nicht behauptet.
     *
     * Bewusst weich, mit Begründung — das ist die inhaltliche Hälfte der Liste:
     *  · `Mindestwert`, `Kontingent …`      — kaufmännisch, nicht operativ blockierend
     *  · `WE-Differenz`, `RE-Differenz`     — treten erst NACH dem Versand auf
     *  · `Reklamation offen`, `Zahlung überfällig` — Lieferanten-Historie, nicht dieser Beleg
     *  · `Liefertag abweichend`             — der Lieferant hat bestätigt, nur anders
     *  · `Freigabe offen`                   — die Küche kommt auch bei abwesendem Freigeber
     *    an die Ware. Entschieden 2026-09-03 (Dominique): bleibt weich, „theoretisch müsste
     *    man ab einer gewissen Summe sich Freigaben holen". Und genau das ist der Grund, warum
     *    es heute NICHT hart sein kann: die Freigabe wird von HAND angefragt
     *    (`approval_status` & Co. sind da, ein Auslöser nicht). Hart wäre sie damit beliebig —
     *    wer keine anfragt, hat keine offene. Mit einer Betrags-Schwelle je Team/Betrieb
     *    (Muster: `TeamSettingsService::skalar($team, …, $outlet)`, wie `stundensatz_eur`)
     *    beantwortet sich die Frage von selbst: oberhalb der Schwelle MUSS sie hart sein,
     *    darunter entsteht sie nie. Backlog, nicht heute.
     *  · `Bestellschluss heute`, `Liefertag heute` — knapp, aber nicht zu spät
     */
    public const WEICHE_HINWEISE = [
        'Mindestwert',
        'WE-Differenz',
        'RE-Differenz',
        'Reklamation offen',
        'Kontingent nicht gültig',
        'Kontingent überschritten',
        'Liefertag abweichend',
        'Zahlung überfällig',
        'Freigabe offen',
        'Bestellschluss heute',
        'Liefertag heute',
    ];

    public function __construct(
        private BestellSummen $summen,
    ) {}

    /** MOQ-/Frei-Haus-Ampel: total_net gegen Lieferanten-Konditionen (R9). */
    public function moqAmpel(FoodAlchemistOrder $order): array
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $min = $supplier?->min_order_value !== null ? (float) $supplier->min_order_value : null;
        $frei = $supplier?->free_shipping_threshold !== null ? (float) $supplier->free_shipping_threshold : null;
        $total = (float) $order->total_net;

        return [
            'total_net' => $total,
            'min_order_value' => $min,
            'free_shipping_threshold' => $frei,
            'unter_mindestbestellwert' => $min !== null && $total < $min,
            'fehlt_bis_min' => $min !== null && $total < $min ? round($min - $total, 2) : 0.0,
            'frei_haus' => $frei !== null && $total >= $frei,
            'fehlt_bis_frei_haus' => $frei !== null && $total < $frei ? round($frei - $total, 2) : 0.0,
        ];
    }

    /**
     * WaWi-Hinweise für operative Einkaufssteuerung. Die Liste bleibt label-basiert, damit
     * bestehende Views ohne neues DTO weiterarbeiten können.
     *
     * @return list<string>
     */
    public function orderWarnings(FoodAlchemistOrder $order): array
    {
        $order->loadMissing(['supplier', 'lines']);
        $warnings = [];
        $lines = $order->lines;
        $total = (float) $order->total_net;

        if ($lines->count() === 0) {
            $warnings[] = 'leer';
        }
        if ($total <= 0.0 || $lines->contains(fn ($line) => $line->pack_price === null || (float) $line->qty_packs <= 0.0)) {
            $warnings[] = 'Klärung';
        }

        $moq = $this->moqAmpel($order);
        if ($lines->count() > 0 && $moq['unter_mindestbestellwert']) {
            $warnings[] = 'Mindestwert';
        }
        if ($this->summen->receiptSummary($order)['differences'] > 0) {
            $warnings[] = 'WE-Differenz';
        }
        if ($this->summen->invoiceSummary($order)['differences'] > 0) {
            $warnings[] = 'RE-Differenz';
        }
        $claims = $this->summen->claimSummary($order);
        if ($claims['open'] > 0 || $claims['credit_expected'] > 0) {
            $warnings[] = 'Reklamation offen';
        }
        $quota = $this->summen->quotaSummary($order);
        if ($quota['invalid'] > 0) {
            $warnings[] = 'Kontingent nicht gültig';
        }
        if ($quota['exceeded'] > 0) {
            $warnings[] = 'Kontingent überschritten';
        }
        if (
            $order->confirmed_delivery_date !== null
            && $order->desired_delivery_date !== null
            && ! $order->confirmed_delivery_date->isSameDay($order->desired_delivery_date)
        ) {
            $warnings[] = 'Liefertag abweichend';
        }
        if ($this->summen->paymentSummary($order)['state'] === 'overdue') {
            $warnings[] = 'Zahlung überfällig';
        }
        $approval = $this->summen->approvalSummary($order);
        if ($approval['state'] === 'requested') {
            $warnings[] = 'Freigabe offen';
        } elseif ($approval['state'] === 'rejected') {
            $warnings[] = 'Freigabe abgelehnt';
        }

        $warnings = array_merge($warnings, $this->logistikWarnings($order));

        return array_values(array_unique($warnings));
    }

    /**
     * Harte Versandsperren: diese Belege darf ein WaWi-Cockpit nicht als Bestellung ausgeben.
     *
     * @return list<string>
     */
    public function sendBlockers(FoodAlchemistOrder $order): array
    {
        return array_values(array_filter(
            $this->orderWarnings($order),
            fn ($warning) => in_array($warning, self::HARTE_SPERREN, true)
        ));
    }

    /**
     * @return array{delivery_days:list<int>, cutoff:?string, lead_days:int, deadline:?string}
     */
    public function logistikInfo(FoodAlchemistOrder $order): array
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $uhrzeit = $supplier !== null ? $this->cutoffUhrzeit($supplier) : null;
        $deadline = $this->orderDeadline($order);

        // `cutoff` an denselben Parser wie `deadline` binden: sonst zeigt die Payload eine
        // Uhrzeit an (»12:00:00«, »abc«), zu der es keine Frist gibt. `deadline = null` ist ab
        // jetzt die Aussage »dieser Lieferant hat keinen Bestellschluss« — der Editor guardet
        // schon darauf und druckt dann nichts. `lead_days` bleibt int mit `?? 0`: NULL und 0
        // heissen fachlich dasselbe (kein Vorlauf), die Aussage trägt `deadline`.
        return [
            'delivery_days' => $this->lieferTage($supplier),
            'cutoff' => $uhrzeit !== null ? sprintf('%02d:%02d', $uhrzeit[0], $uhrzeit[1]) : null,
            'lead_days' => max(0, (int) ($supplier?->order_lead_days ?? 0)),
            'deadline' => $deadline?->format('Y-m-d H:i'),
        ];
    }

    /** @return list<string> */
    private function logistikWarnings(FoodAlchemistOrder $order): array
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $date = $order->desired_delivery_date;
        if ($supplier === null || $date === null) {
            return [];
        }

        return $this->supplierLogistikWarnings($supplier, $date->copy());
    }

    /** @return list<string> */
    public function previewLogistikWarnings(?FoodAlchemistSupplier $supplier, ?string $date): array
    {
        if ($supplier === null || $date === null || $date === '') {
            return [];
        }

        return $this->supplierLogistikWarnings($supplier, Carbon::parse($date));
    }

    /** @return list<string> */
    private function supplierLogistikWarnings(FoodAlchemistSupplier $supplier, Carbon $date): array
    {
        $warnings = [];
        $deliveryDays = $this->lieferTage($supplier);
        if ($deliveryDays !== [] && ! in_array((int) $date->isoWeekday(), $deliveryDays, true)) {
            $warnings[] = 'Liefertag nicht beliefert';
        }

        // Zwei Fälle, zwei Etiketten. Trägt der Lieferant einen Bestellschluss (Vorlaufzeit
        // ODER Uhrzeit gepflegt), heisst die Grenze so. Trägt er keinen, ist die Grenze der
        // LIEFERTAG selbst — dann wird auch kein Bestellschluss behauptet. Die Schwelle ist
        // in beiden Zweigen dieselbe wie bisher (`endOfDay(Liefertag)`), es sperrt also
        // weiterhin genau dasselbe: nur das Wort ändert sich.
        $now = Carbon::now();
        $deadline = $this->supplierDeadline($supplier, $date);
        if ($deadline !== null) {
            if ($now->greaterThan($deadline)) {
                $warnings[] = 'Bestellschluss verpasst';
            } elseif ($now->isSameDay($deadline)) {
                $warnings[] = 'Bestellschluss heute';
            }
        } elseif ($now->greaterThan($date->copy()->endOfDay())) {
            $warnings[] = 'Liefertag vorbei';
        } elseif ($now->isSameDay($date)) {
            $warnings[] = 'Liefertag heute';
        }

        return $warnings;
    }

    private function orderDeadline(FoodAlchemistOrder $order): ?Carbon
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $date = $order->desired_delivery_date;
        if ($supplier === null || $date === null) {
            return null;
        }

        return $this->supplierDeadline($supplier, $date->copy());
    }

    /**
     * Gepflegte Bestellschluss-Uhrzeit als [Stunde, Minute], sonst null. Eigene Methode, weil
     * an ihr auch hängt, OB der Lieferant einen Bestellschluss hat — nicht nur, wann er ist.
     *
     * Das optionale `:ss` schliesst einen stillen Fehler: `supplier_conditions.PUT` validiert
     * das Format nicht, ein per MCP geschriebenes »12:00:00« matchte das alte Muster nicht und
     * fiel damit in den endOfDay-Zweig — derselbe Bug wie beim nackten Lieferanten, nur mit
     * gepflegtem Lieferanten und deshalb noch schwerer zu sehen.
     *
     * @return ?array{0:int,1:int}
     */
    private function cutoffUhrzeit(FoodAlchemistSupplier $supplier): ?array
    {
        $cutoff = trim((string) ($supplier->order_cutoff_time ?? ''));

        return preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $cutoff, $m) === 1
            ? [min(23, (int) $m[1]), min(59, (int) $m[2])]
            : null;
    }

    /**
     * Bestellschluss-Zeitpunkt für einen Liefertag — oder null, wenn der Lieferant keinen hat.
     *
     * Vorher lieferte der ungepflegte Lieferant `endOfDay(Liefertag)`, also einen ERFUNDENEN
     * Bestellschluss. Folge: `logistikInfo` wies gleichzeitig `cutoff => null` und eine
     * `deadline` aus, der Editor druckte daraus »Bestellschluss: … 23:59« für einen
     * Lieferanten ohne Bestellschluss, und die Sperre trug ein Etikett, das sachlich falsch war.
     *
     * Drei Lagen, nicht zwei:
     *   1. Uhrzeit gepflegt              → echte Frist (Vorlauf + Uhrzeit)
     *   2. nur Vorlaufzeit gepflegt      → echte Frist, aber tagfein (Ende des Vorlauftags)
     *   3. weder Vorlauf noch Uhrzeit    → KEINE Frist; der Liefertag selbst ist die Grenze
     */
    private function supplierDeadline(FoodAlchemistSupplier $supplier, Carbon $date): ?Carbon
    {
        $leadDays = max(0, (int) ($supplier->order_lead_days ?? 0));
        $uhrzeit = $this->cutoffUhrzeit($supplier);

        if ($leadDays === 0 && $uhrzeit === null) {
            return null;
        }

        $deadline = $date->copy()->subDays($leadDays)->startOfDay();

        return $uhrzeit !== null ? $deadline->setTime($uhrzeit[0], $uhrzeit[1]) : $deadline->endOfDay();
    }

    /** @return list<int> */
    private function lieferTage(?FoodAlchemistSupplier $supplier): array
    {
        if ($supplier === null || trim((string) $supplier->delivery_days) === '') {
            return [];
        }

        return collect(explode(',', (string) $supplier->delivery_days))
            ->map(fn ($day) => (int) trim($day))
            ->filter(fn ($day) => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();
    }
}
