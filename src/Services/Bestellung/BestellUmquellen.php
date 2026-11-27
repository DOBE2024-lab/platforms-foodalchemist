<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;

/**
 * Bezugsquellen einer offenen Bestellung wechseln (98 · 3.3, aus OrderService verschoben): „Neu quellen" nach Preisstrategie
 * (Spec 20 · E3), Ausweichquellen je Zeile und GP, Einzelwechsel einer Zeile (E3b) über denselben Motor applyLaSwitch.
 * Nutzt BestellSchiene und BestellZeilen, nicht die Fassade.
 */
final class BestellUmquellen
{
    public function __construct(
        private \Platform\FoodAlchemist\Services\Einkauf\LeadLaService $leadLa,
        private BestellSchiene $schiene,
        private BestellZeilen $zeilen,
    ) {}

    /**
     * Spec 20 · E3 — „Neu quellen": die OFFENE Schiene mit einer (optionalen) Preisstrategie
     * neu auflösen. Je Bedarfs-Zeile (mit source_contributions) wird der Lead-LA über
     * effektiverLead($strategie) neu bestimmt:
     *   • gleicher Lieferant, anderer LA ⇒ nur der Artikel der Zeile wechselt;
     *   • anderer Lieferant ⇒ die Beiträge wandern idempotent (E10) in dessen Draft-Schiene,
     *     die Quell-Zeile fällt weg (Contribution „verschoben", nicht dupliziert).
     * Manuelle Zeilen (is_manual_qty) und Zeilen ohne Lead bleiben unangetastet; E3-Aggregat-
     * Rundung läuft über recomputeOrder(). $apply=false ⇒ reine Vorschau (nichts persistiert,
     * gleicher Report-Shape) für den „Neu quellen"-Dialog. Der gewählte Override wird an der
     * Schiene vermerkt (orders.sourcing_strategy; NULL = Team-Haupteinstellung).
     *
     * @return array{strategie:?string, wechsel:list<array{gp_id:int, gp:string, von_la_id:?int, nach_la_id:int, nach_artikel:?string, nach_lieferant:?string, schiene_wechsel:bool}>, orders:list<int>}
     */
    public function resourceOrder(Team $team, int $orderId, ?LeadLaStrategie $strategie = null, bool $apply = true, ?int $userId = null): array
    {
        $order = $this->schiene->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Bezugsquellen lassen sich nur in einem offenen Entwurf neu ermitteln.');
        }

        $wechsel = [];
        $moves = [];   // [FoodAlchemistOrderLine $line, bool $sameSupplier, int $newSupplierId, int $newLaId, int $gpId]
        foreach ($order->lines()->get() as $line) {
            // E3: manuelle Zeilen + reine Direktbestellungen (leere contributions) bleiben unberührt.
            if ($line->is_manual_qty || empty($line->source_contributions) || $line->gp_id === null) {
                continue;
            }
            $gp = FoodAlchemistGp::find($line->gp_id);
            if ($gp === null) {
                continue;
            }
            $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
            $newLaId = $lead?->id !== null ? (int) $lead->id : null;
            $newSupplierId = $lead?->supplier_id !== null ? (int) $lead->supplier_id : null;
            if ($newLaId === null || $newSupplierId === null) {
                continue;   // kein bestellbarer Lead unter dieser Strategie → Zeile bleibt
            }
            $altLaId = $line->supplier_item_id !== null ? (int) $line->supplier_item_id : null;
            if ($newLaId === $altLaId) {
                continue;   // Strategie ändert für diesen GP nichts
            }

            $sameSupplier = $newSupplierId === (int) $order->supplier_id;
            $wechsel[] = [
                'gp_id' => (int) $gp->id,
                'gp' => $gp->name,
                'von_la_id' => $altLaId,
                'nach_la_id' => $newLaId,
                'nach_artikel' => $lead->designation,
                'nach_lieferant' => $lead->supplier_name,
                'schiene_wechsel' => ! $sameSupplier,
            ];
            $moves[] = [$line, $sameSupplier, $newSupplierId, $newLaId, (int) $gp->id];
        }

        if (! $apply) {
            return ['strategie' => $strategie?->value, 'wechsel' => $wechsel, 'orders' => [(int) $order->id]];
        }

        $touched = [(int) $order->id => $order];
        foreach ($moves as [$line, $sameSupplier, $newSupplierId, $newLaId, $gpId]) {
            $target = $this->applyLaSwitch($team, $line, $sameSupplier, $newSupplierId, $newLaId, $gpId, $userId);
            if ($target !== null) {
                $touched[(int) $target->id] = $target;
            }
        }

        $order->sourcing_strategy = $strategie?->value;   // Override an der Schiene vermerken (NULL = Haupteinstellung)
        $order->save();
        foreach ($touched as $o) {
            $this->zeilen->recomputeOrder($o->refresh());
        }

        return [
            'strategie' => $strategie?->value,
            'wechsel' => $wechsel,
            'orders' => array_values(array_map(fn ($o) => (int) $o->id, $touched)),
        ];
    }

    /**
     * Spec 20 · E3 — eine Zeile auf einen anderen Lead-LA umziehen (gemeinsamer Motor für
     * resourceOrder + E3b-Einzelwechsel). Gleicher Lieferant ⇒ nur der Artikel der Zeile
     * wechselt (Schiene bleibt, gibt NULL zurück). Anderer Lieferant ⇒ die Beiträge wandern
     * idempotent (E10) in dessen Draft-Schiene und die Quell-Zeile fällt weg — bei einer
     * beitragslosen (manuellen) Zeile wandert stattdessen die manuelle Menge mit. Rückgabe:
     * die Ziel-Draft-Schiene bei Lieferanten-Wechsel, sonst NULL.
     */
    private function applyLaSwitch(Team $team, FoodAlchemistOrderLine $line, bool $sameSupplier, int $newSupplierId, int $newLaId, int $gpId, ?int $userId): ?FoodAlchemistOrder
    {
        if ($sameSupplier) {
            $line->supplier_item_id = $newLaId;   // Artikel wechselt, Schiene bleibt
            $line->save();

            return null;
        }
        $deliveryDate = $line->order?->desired_delivery_date?->toDateString();
        $targetDraft = $this->schiene->draftForSupplier($team, $newSupplierId, $deliveryDate, $userId);
        $contribs = $line->source_contributions ?? [];
        if (! empty($contribs)) {
            foreach ($contribs as $ref => $g) {
                $this->schiene->upsertContribution($team, $targetDraft, ['lead_la_id' => $newLaId, 'gp_id' => $gpId, 'menge_g' => $g], (string) $ref);
            }
        } else {
            // Beitragslose (manuelle) Zeile: manuelle Menge in die Ziel-Schiene übernehmen.
            $this->zeilen->addManualLine($team, $newLaId, (float) $line->qty_packs, $line->note, $userId, $deliveryDate);
        }
        $line->order->zeileAbgegebenAn($line, $targetDraft);   // Beitrag/Menge verschoben, nicht dupliziert; Kopf/leerer Entwurf: Entscheid 22.11.

        return $targetDraft;
    }

    /**
     * Spec 20 · E3b — Ausweichquellen einer Bedarfs-Zeile fürs Panel-2-Dropdown: die
     * GP-Rangliste (V-27) ohne den aktuell gewählten LA, jeweils mit Vergleichspreis und
     * Schienen-Wechsel-Hinweis (anderer Lieferant als die aktuelle Schiene). Nur eigene,
     * offene Belege; leere Liste bei Zeile ohne GP.
     *
     * @return list<array{la_id:int, designation:?string, supplier:?string, supplier_id:?int, vergleichspreis:?float, vergleichspreis_einheit:?string, ist_stamm:bool, gesperrt:bool, schiene_wechsel:bool}>
     */
    public function lineAlternativen(Team $team, int $lineId): array
    {
        $line = $this->schiene->ownedDraftLine($team, $lineId);
        if ($line->gp_id === null) {
            return [];
        }
        $gp = FoodAlchemistGp::find($line->gp_id);
        if ($gp === null) {
            return [];
        }
        $aktuelleSchieneSupplier = (int) $line->order->supplier_id;
        $aktuellLa = $line->supplier_item_id !== null ? (int) $line->supplier_item_id : null;

        return $this->leadLa->rangliste($gp, $team)
            ->reject(fn ($la) => (int) $la->id === $aktuellLa)   // Rang > „aktuell" (der gewählte LA fällt raus)
            ->map(fn ($la) => [
                'la_id' => (int) $la->id,
                'designation' => $la->designation,
                'supplier' => $la->supplier_name,
                'supplier_id' => $la->supplier_id !== null ? (int) $la->supplier_id : null,
                'vergleichspreis' => $la->vergleichspreis_wert !== null ? (float) $la->vergleichspreis_wert : null,
                'vergleichspreis_einheit' => $la->vergleichspreis['unit'] ?? null,
                'ist_stamm' => (bool) $la->ist_stamm,
                'gesperrt' => (bool) $la->locked,
                'schiene_wechsel' => $la->supplier_id !== null && (int) $la->supplier_id !== $aktuelleSchieneSupplier,
            ])
            ->values()->all();
    }

    /**
     * Ausweichquellen für eine noch nicht gespeicherte Cockpit-/Vorschau-Position. Gleicher
     * Shape wie `lineAlternativen`, aber ohne Draft-Line als Voraussetzung.
     *
     * @return list<array{la_id:int, designation:?string, supplier:?string, supplier_id:?int, vergleichspreis:?float, vergleichspreis_einheit:?string, ist_stamm:bool, gesperrt:bool, schiene_wechsel:bool}>
     */
    public function gpAlternativen(Team $team, int $gpId, ?int $currentSupplierId = null, ?int $currentLaId = null): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return [];
        }

        return $this->leadLa->rangliste($gp, $team)
            ->reject(fn ($la) => $currentLaId !== null && (int) $la->id === $currentLaId)
            ->map(fn ($la) => [
                'la_id' => (int) $la->id,
                'designation' => $la->designation,
                'supplier' => $la->supplier_name,
                'supplier_id' => $la->supplier_id !== null ? (int) $la->supplier_id : null,
                'vergleichspreis' => $la->vergleichspreis_wert !== null ? (float) $la->vergleichspreis_wert : null,
                'vergleichspreis_einheit' => $la->vergleichspreis['unit'] ?? null,
                'ist_stamm' => (bool) $la->ist_stamm,
                'gesperrt' => (bool) $la->locked,
                'schiene_wechsel' => $currentSupplierId !== null && $la->supplier_id !== null && (int) $la->supplier_id !== $currentSupplierId,
            ])
            ->values()->all();
    }

    /**
     * Spec 20 · E3b — eine einzelne OFFENE Bedarfs-Zeile manuell auf einen Ausweich-LA ihrer
     * GP-Rangliste umstellen (Alternativ-Artikel-Dropdown). Gleicher Lieferant ⇒ nur der
     * Artikel wechselt; anderer Lieferant ⇒ die Zeile wandert in dessen Draft-Schiene (über
     * denselben applyLaSwitch-Motor wie „Neu quellen"). Der Ziel-LA muss ein Rangliste-
     * Kandidat der GP sein (I2-analog). Nur eigene, offene Belege.
     *
     * @return array{order_id:int, target_order_id:?int, schiene_wechsel:bool}
     */
    public function switchLineArticle(Team $team, int $lineId, int $newLaId, ?int $userId = null): array
    {
        $line = $this->schiene->ownedDraftLine($team, $lineId);
        if ($line->gp_id === null) {
            throw new \RuntimeException('Zeile ohne Grundprodukt: kein Ausweichartikel wählbar.');
        }
        $gp = FoodAlchemistGp::find($line->gp_id);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht gefunden.');
        }
        $kandidat = $this->leadLa->rangliste($gp, $team)->firstWhere('id', $newLaId);
        if ($kandidat === null) {
            throw new \RuntimeException('Artikel gehört nicht zu den Ausweichquellen dieses Grundprodukts.');
        }
        if ($kandidat->supplier_id === null) {
            throw new \RuntimeException('Artikel ohne Lieferant ist nicht bestellbar.');
        }
        $sourceOrderId = (int) $line->order_id;
        if ((int) $newLaId === ($line->supplier_item_id !== null ? (int) $line->supplier_item_id : null)) {
            return ['order_id' => $sourceOrderId, 'target_order_id' => null, 'schiene_wechsel' => false];
        }

        $sameSupplier = (int) $kandidat->supplier_id === (int) $line->order->supplier_id;
        $target = $this->applyLaSwitch($team, $line, $sameSupplier, (int) $kandidat->supplier_id, (int) $newLaId, (int) $gp->id, $userId);

        $sourceOrder = FoodAlchemistOrder::find($sourceOrderId);
        if ($sourceOrder !== null) {
            $this->zeilen->recomputeOrder($sourceOrder);
        }
        if ($target !== null) {
            $this->zeilen->recomputeOrder($target->refresh());
        }

        return [
            'order_id' => $sourceOrderId,
            'target_order_id' => $target?->id !== null ? (int) $target->id : null,
            'schiene_wechsel' => $target !== null,
        ];
    }
}
