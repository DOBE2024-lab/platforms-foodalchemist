<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderRound;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;

/**
 * Spec 17 / S2 — Bestellschienen-Motor (N-Track, OHNE Bestand).
 *
 * Eine Schiene = ein offener `draft`-Order je (team, supplier), der Bedarf sammelt (E1).
 * `addNeedFromTarget` übernimmt Bedarf beliebiger Granularität — Konzept/Event, Gericht
 * oder einzelne Produktion (E9) — je Lieferant in seine Schiene. Die Bestellzeile liegt
 * PRO ARTIKEL; `source_contributions` {source_ref: base_g} trägt die Quell-Beiträge:
 * needed_base_g = Summe, qty_packs = ceil(Summe ÷ Gebinde) via GebindeRechner — Aufrundung
 * IMMER aufs Aggregat (E3), Re-Import einer Quelle überschreibt nur ihren Schlüssel (E10).
 * Im draft werden Snapshot/Preis live aus dem aktiven Preis aufgefrischt (E11); `send`
 * friert den Beleg ein (E2). Rechen-Wahrheit = derselbe `GebindeRechner` wie S0 (kein Drift).
 *
 * Schreibt nur eigene Team-Belege (isOwnedBy) und nur solange `draft`.
 */
class OrderService
{
    /** 98 · 3.3 S5: Register liegt in BestellAmpeln (Etiketten aus orderWarnings/supplierLogistikWarnings). */
    public const HARTE_SPERREN = \Platform\FoodAlchemist\Services\Bestellung\BestellAmpeln::HARTE_SPERREN;

    /** 98 · 3.3 S5: Register liegt in BestellAmpeln (Etiketten aus orderWarnings/supplierLogistikWarnings). */
    public const WEICHE_HINWEISE = \Platform\FoodAlchemist\Services\Bestellung\BestellAmpeln::WEICHE_HINWEISE;

    public function __construct(
        private \Platform\FoodAlchemist\Services\Planung\PlanungsblattService $planung,
        private \Platform\FoodAlchemist\Services\Preis\PriceService $preise,
        private \Platform\FoodAlchemist\Services\Einkauf\GebindeRechner $gebinde,
        private \Platform\FoodAlchemist\Services\Einkauf\LeadLaService $leadLa,
        private \Platform\FoodAlchemist\Services\Einkauf\InventoryService $inventory,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellSchiene $schiene,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellSummen $summen,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellAmpeln $ampeln,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellHerkunft $herkunft,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellLesen $lesen,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellDokument $dokumente,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellEingang $eingang,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellPflege $pflege,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellBedarf $bedarf,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellRunde $runden,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellZeilen $zeilen,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellVorschau $vorschau,
        private \Platform\FoodAlchemist\Services\Bestellung\BestellUmquellen $umquellen,
    ) {}

    // ── Schiene holen/anlegen ─────────────────────────────────────────────

    /** 98 · 3.3 S5: BestellSchiene::draftForSupplier() */
    public function draftForSupplier(Team $team, int $supplierId, ?string $deliveryDate = null, ?int $userId = null): FoodAlchemistOrder
    {
        return $this->schiene->draftForSupplier($team, $supplierId, $deliveryDate, $userId);
    }

    // ── Bedarf in die Schienen übernehmen (E9 Granularität, E10 idempotent) ──

    /**
     * Übernimmt den Bedarf EINES Ziels (concept_id|recipe_id + Menge) in die passenden
     * Lieferanten-Schienen. `sourceRef` identifiziert die Quelle (Re-Import ersetzt sie, E10).
     *
     * Spec 20 · E3: optionaler $strategieOverride (Preisstrategie) steuert die Lead-LA-Wahl
     * des Vorschlags — so wechselt die Übernahme dieselbe Schiene wie eine spätere „Neu quellen".
     *
     * $deliveryDate (Liefertag, Y-m-d oder null) bestimmt, in welche Bestellung je Lieferant der
     * Bedarf läuft — Bestellungen sind je (Lieferant, Liefertag) getrennt.
     *
     * @param  array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}  $ziel
     * @return array{orders:list<int>, skipped_ohne_la:list<string>, warnungen:list<string>}
     */
    public function addNeedFromTarget(Team $team, array $ziel, string $sourceRef, ?int $userId = null, ?LeadLaStrategie $strategieOverride = null, ?string $deliveryDate = null): array
    {
        $vorschlag = $this->planung->bestellvorschlag($team, $ziel, $strategieOverride);
        $touched = [];
        $skipped = [];

        foreach ($vorschlag['lieferanten'] as $grp) {
            $supplierId = $grp['supplier_id'] ?? null;
            if ($supplierId === null) {            // Bucket „ohne Lead-LA" → nicht bestellbar (Sourcing/R9)
                $skipped[] = (string) ($grp['lieferant'] ?? '—');

                continue;
            }
            $draft = $this->schiene->draftForSupplier($team, (int) $supplierId, $deliveryDate, $userId);
            foreach ($grp['positionen'] as $pos) {
                $this->schiene->upsertContribution($team, $draft, $pos, $sourceRef);
            }
            $this->recomputeOrder($draft->refresh());
            $touched[] = (int) $draft->id;
        }

        return [
            'orders' => array_values(array_unique($touched)),
            'skipped_ohne_la' => array_values(array_unique($skipped)),
            'warnungen' => $vorschlag['warnungen'] ?? [],
        ];
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellVorschau::previewFromSources() */
    public function previewFromSources(Team $team, array $sources, LeadLaStrategie|string|null $strategy = null, array $overrides = []): array
    {
        return $this->vorschau->previewFromSources($team, $sources, $strategy, $overrides);
    }

    /**
     * Speichert Cockpit-Quellen als Draft-Bestellungen. Bestellbare Quellen werden je
     * Lieferant+Liefertag idempotent in Drafts übernommen; ungeklärte Quellen bleiben im Report.
     *
     * @param  list<array{type:string,id:int|string,qty?:int|float|string,unit?:string,delivery_date?:?string,reference?:?string}>  $sources
     * @param  array<string,int>  $overrides  override_key => lead_la_id
     * @param  array{id?:?int,label?:?string,desired_delivery_date?:?string,note?:?string,replace_production_ids?:list<int>}  $round
     * @return array{orders:list<int>, unresolved:list<array>, warnings:list<string>, preview:array, round:?array}
     */
    public function generateDraftsFromSources(Team $team, array $sources, LeadLaStrategie|string|null $strategy = null, ?int $userId = null, array $overrides = [], array $round = []): array
    {
        $replacementSources = $sources;
        foreach (($round['replace_production_ids'] ?? []) as $productionId) {
            if ((int) $productionId > 0) {
                $replacementSources[] = ['type' => 'production', 'id' => (int) $productionId];
            }
        }
        $this->runden->assertProductionSourcesReplaceable($team, $replacementSources);
        $strategie = $this->bedarf->strategieAusWert($strategy);
        $preview = $this->previewFromSources($team, $sources, $strategie, $overrides);
        $cleared = $this->zeilen->clearSourceRefsFromDrafts($team, $this->runden->replacementSourceRefs($team, $replacementSources, $preview));
        $touched = [];

        if (! empty($overrides)) {
            foreach ($preview['orders_preview'] as $gruppe) {
                $supplierId = (int) ($gruppe['supplier_id'] ?? 0);
                if ($supplierId <= 0) {
                    continue;
                }
                $draft = $this->schiene->draftForSupplier($team, $supplierId, ($gruppe['delivery_date'] ?? null) ?: null, $userId);
                foreach (($gruppe['positionen'] ?? []) as $pos) {
                    if (($pos['type'] ?? '') === 'supplier_item') {
                        $this->addManualLine($team, (int) ($pos['lead_la_id'] ?? 0), max(0.0, (float) ($pos['qty_packs'] ?? 0)), null, $userId, ($gruppe['delivery_date'] ?? null) ?: null);
                    } elseif (($pos['gp_id'] ?? null) !== null && ($pos['lead_la_id'] ?? null) !== null && (float) ($pos['needed_base_g'] ?? 0) > 0) {
                        $this->schiene->upsertContribution($team, $draft, [
                            'lead_la_id' => (int) $pos['lead_la_id'],
                            'gp_id' => (int) $pos['gp_id'],
                            'menge_g' => (float) $pos['needed_base_g'],
                        ], (string) ($pos['source_ref'] ?? 'preview:'.($pos['override_key'] ?? Str::uuid())));
                        // Spec 71: Gebinde von Hand gesetzt → Zeile hält die Menge fest (recomputeLine respektiert is_manual_qty)
                        if (! empty($pos['menge_von_hand'])) {
                            FoodAlchemistOrderLine::where('order_id', $draft->id)->where('supplier_item_id', (int) $pos['lead_la_id'])
                                ->update(['is_manual_qty' => true, 'qty_packs' => (float) $pos['qty_packs']]);
                        }
                    }
                    if (($pos['reference'] ?? '') !== '') {
                        $this->kopfAusQuelle($team, (int) $draft->id, (string) $pos['reference']);
                    }
                }
                $this->recomputeOrder($draft->refresh());
                $touched[] = (int) $draft->id;
            }
            $this->markProductionSourcesHandedOver($team, $sources);
            $this->runden->stampStrategyOnOrders($team, $touched, $strategie);
            $this->runden->deleteEmptyReplannedDrafts($team, $cleared);

            $roundDetail = $this->runden->persistRound($team, $touched, $strategie, $userId, $round + $this->runden->rundenStand($team, $sources, $overrides, $preview, $replacementSources));

            return [
                'orders' => array_values(array_unique(array_map('intval', $touched))),
                'unresolved' => $preview['unresolved'],
                'warnings' => $preview['warnings'],
                'preview' => $preview,
                'round' => $roundDetail,
            ];
        }

        foreach (array_values($sources) as $idx => $source) {
            $type = (string) ($source['type'] ?? '');
            $date = ($source['delivery_date'] ?? null) ?: null;
            $reference = trim((string) ($source['reference'] ?? ''));
            $sourceRef = $this->bedarf->sourceRef($source, $idx);

            if ($type === 'supplier_item') {
                $line = $this->addManualLine($team, (int) ($source['id'] ?? 0), max(0.0, $this->bedarf->sourceQty($source)), null, $userId, $date);
                $touched[] = (int) $line->order_id;
                $this->kopfAusQuelle($team, (int) $line->order_id, $reference);
            } elseif ($type === 'gp') {
                $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                if ($gp === null) {
                    continue;
                }
                if (($bz = \Platform\FoodAlchemist\Support\Derivat\DerivatBedarf::bestellziel($gp, $this->bedarf->sourceGramsForGp($gp, $source))) === null) { continue; } [$gp, $grams] = $bz;   // Spec 96: wie previewGp
                $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
                if ($lead?->supplier_id === null || $lead?->id === null) {
                    continue;
                }
                if ($grams <= 0) {
                    continue;
                }
                $draft = $this->schiene->draftForSupplier($team, (int) $lead->supplier_id, $date, $userId);
                $this->schiene->upsertContribution($team, $draft, ['lead_la_id' => (int) $lead->id, 'gp_id' => (int) $gp->id, 'menge_g' => $grams], $sourceRef);
                $this->recomputeOrder($draft->refresh());
                $this->kopfAusQuelle($team, (int) $draft->id, $reference);
                $touched[] = (int) $draft->id;
            } elseif (in_array($type, ['recipe', 'concept'], true)) {
                $res = $this->addNeedFromTarget($team, $type === 'recipe' ? $this->bedarf->zielAusRecipeSource($source) : $this->bedarf->zielAusMengenQuelle($source), $sourceRef, $userId, $strategie, $date);
                foreach ($res['orders'] as $id) {
                    $this->kopfAusQuelle($team, (int) $id, $reference);
                }
                $touched = array_merge($touched, $res['orders']);
            } elseif ($type === 'production') {
                $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                if ($production === null) {
                    continue;
                }
                $targets = $this->vorschau->releasedProductionTargets($production);
                $prodDate = $date ?: $production->production_date?->toDateString();
                foreach ($targets as $targetIdx => $target) {
                    $targetRef = \Platform\FoodAlchemist\Services\Produktion\ProductionOrderService::sourceRefFor((int) $production->id, (string) ($target['source_ref'] ?? ('target-'.$targetIdx)));
                    $res = $this->addNeedFromTarget($team, $this->bedarf->zielOhneMeta($target), $targetRef, $userId, $strategie, $prodDate);
                    foreach ($res['orders'] as $id) {
                        $this->kopfAusQuelle($team, (int) $id, $reference !== '' ? $reference : (string) ($target['label'] ?? ''));
                    }
                    $touched = array_merge($touched, $res['orders']);
                }
            }
        }
        $this->runden->stampStrategyOnOrders($team, $touched, $strategie);
        $this->runden->deleteEmptyReplannedDrafts($team, $cleared);
        $roundDetail = $this->runden->persistRound($team, $touched, $strategie, $userId, $round + $this->runden->rundenStand($team, $sources, $overrides, $preview, $replacementSources));

        return [
            'orders' => array_values(array_unique(array_map('intval', $touched))),
            'unresolved' => $preview['unresolved'],
            'warnings' => $preview['warnings'],
            'preview' => $preview,
            'round' => $roundDetail,
        ];
    }

    /** 98 · 3.3 S5: BestellBedarf::lagerReserviert() */
    public function lagerReserviert(Team $team, ?int $ohneRundeId = null): array
    {
        return $this->bedarf->lagerReserviert($team, $ohneRundeId);
    }

    /**
     * Spec 73: Bestellrunde löschen. Nur solange keine ihrer Bestellungen versendet ist. Ihre Beiträge
     * verschwinden aus den Entwürfen; Entwürfe, die nur zu dieser Runde gehören, werden ganz gelöscht.
     *
     * @return array{geloescht: list<int>, aktualisiert: list<int>}
     */
    public function deleteRound(Team $team, int $roundId): array
    {
        $round = FoodAlchemistOrderRound::where('team_id', $team->id)->with('orders.rounds:id')->findOrFail($roundId);
        $versendet = $round->orders->filter(fn (FoodAlchemistOrder $o) => ($o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status)) !== OrderStatus::Draft);
        if ($versendet->isNotEmpty()) {
            throw new \RuntimeException($versendet->count() . ' Bestellung(en) dieser Runde sind schon versendet — erst dort stornieren, dann die Runde löschen.');
        }

        return DB::transaction(function () use ($team, $round) {
            $aktualisiert = $this->zeilen->clearSourceRefsFromDrafts($team, (array) ($round->source_refs ?? []));
            $geloescht = [];
            foreach ($round->orders as $o) {
                if ($o->rounds->count() <= 1) {     // gehört nur zu dieser Runde → ganz weg
                    $o->lines()->delete();
                    $o->delete();
                    $geloescht[] = (int) $o->id;
                }
            }
            $this->runden->deleteEmptyReplannedDrafts($team, $aktualisiert);
            $round->orders()->detach();
            $round->delete();

            return ['geloescht' => $geloescht, 'aktualisiert' => array_values(array_diff(array_map('intval', $aktualisiert), $geloescht))];
        });
    }

    /** 98 · 3.3 S5: BestellRunde::roundsForTeam() */
    public function roundsForTeam(Team $team): Collection
    {
        return $this->runden->roundsForTeam($team);
    }

    public function productionDemandsForTeam(Team $team): Collection
    {
        return FoodAlchemistProductionOrder::visibleToTeam($team)
            ->whereNotNull('procurement_released_at')
            ->where('status', '!=', ProductionOrderStatus::Cancelled->value)
            ->orderBy('production_date')
            ->get()
            ->map(function (FoodAlchemistProductionOrder $production) use ($team) {
                $linked = app(\Platform\FoodAlchemist\Services\Produktion\ProductionOrderService::class)->verknuepfteOrders($team, (int) $production->id);
                $round = $linked->flatMap(fn (FoodAlchemistOrder $order) => $order->rounds)
                    ->sortByDesc('id')->first();
                $stale = $production->procurement_targets_hash !== \Platform\FoodAlchemist\Services\Produktion\ProductionOrderService::targetsHash($production->targets);
                $triggered = $linked->contains(fn (FoodAlchemistOrder $order) => in_array(
                    $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status),
                    [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered],
                    true,
                ));

                return [
                    'id' => (int) $production->id,
                    'name' => $production->name ?: 'Produktion #'.$production->id,
                    'production_date' => $production->production_date?->toDateString(),
                    'released_at' => $production->procurement_released_at?->toIso8601String(),
                    'stale' => $stale,
                    'targets' => count($production->procurement_targets_snapshot ?? []),
                    'orders' => $linked->count(),
                    'round_id' => $round?->id !== null ? (int) $round->id : null,
                    'round_label' => $round?->label,
                    'triggered' => $triggered,
                    'status' => $triggered ? ($stale ? 'korrektur nötig' : 'ausgelöst') : ($stale ? 'geaendert' : ($linked->isEmpty() ? 'offen' : 'geplant')),
                ];
            });
    }

    /**
     * Zieht den Bedarf einer stornierten Produktion aus allen offenen Entwürfen zurück.
     * Bereits ausgelöste Belege bleiben als unveränderlicher Einkaufsstand erhalten.
     *
     * @return array{updated_orders:list<int>,deleted_orders:list<int>,locked_orders:list<int>}
     */
    public function withdrawProductionDemand(Team $team, int $productionOrderId): array
    {
        $prefix = \Platform\FoodAlchemist\Services\Produktion\ProductionOrderService::sourceRefPrefix($productionOrderId);
        $draftLines = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->where('status', OrderStatus::Draft->value))
            ->where('source_contributions', 'like', '%'.$prefix.'%')
            ->get(['source_contributions']);

        $sourceRefs = $draftLines
            ->flatMap(fn (FoodAlchemistOrderLine $line) => array_keys((array) $line->source_contributions))
            ->filter(fn (string $ref) => str_starts_with($ref, $prefix))
            ->unique()->values()->all();

        $lockedOrderIds = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->whereIn('status', [
                    OrderStatus::Sent->value,
                    OrderStatus::Confirmed->value,
                    OrderStatus::Delivered->value,
                ]))
            ->where('source_contributions', 'like', '%'.$prefix.'%')
            ->distinct()->pluck('order_id')->map(fn ($id) => (int) $id)->values()->all();

        $updatedOrderIds = $this->zeilen->clearSourceRefsFromDrafts($team, $sourceRefs);
        $deletedOrderIds = FoodAlchemistOrder::query()
            ->where('team_id', $team->id)
            ->whereIn('id', $updatedOrderIds)
            ->where('status', OrderStatus::Draft->value)
            ->whereDoesntHave('lines')
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->runden->deleteEmptyReplannedDrafts($team, $updatedOrderIds);

        return [
            'updated_orders' => array_values(array_diff($updatedOrderIds, $deletedOrderIds)),
            'deleted_orders' => $deletedOrderIds,
            'locked_orders' => $lockedOrderIds,
        ];
    }

    /** 98 · 3.3 S5: BestellRunde::roundDetail() */
    public function roundDetail(Team $team, int $roundId): array
    {
        return $this->runden->roundDetail($team, $roundId);
    }

    public function sendRound(Team $team, int $roundId): array
    {
        $round = FoodAlchemistOrderRound::visibleToTeam($team)->with('orders')->findOrFail($roundId);
        if (! $round->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Bestellrunde gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }

        $drafts = $round->orders->filter(fn (FoodAlchemistOrder $order) => $order->status === OrderStatus::Draft);
        foreach ($drafts as $order) {
            $blockers = $this->ampeln->sendBlockers($order);
            if ($blockers !== []) {
                throw new \RuntimeException(($order->supplier?->name ?? 'Bestellung #'.$order->id).': '.implode(', ', $blockers));
            }
        }

        DB::transaction(function () use ($team, $drafts) {
            foreach ($drafts as $order) {
                $this->setStatus($team, (int) $order->id, OrderStatus::Sent);
            }
        });

        return $this->runden->roundDetail($team, $roundId);
    }

    /**
     * Löst alle aktuell versandfähigen Entwürfe aus. Gesperrte Belege bleiben zur Klärung offen.
     *
     * @return array{sent:int,blocked:int,blockers:list<string>}
     */
    public function sendReadyDrafts(Team $team): array
    {
        $ids = FoodAlchemistOrder::visibleToTeam($team)
            ->where('status', OrderStatus::Draft->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->sendSelectedDrafts($team, $ids);
    }

    /** 98 · 3.3 S5: BestellRunde::readyDraftIds() */
    public function readyDraftIds(Team $team): array
    {
        return $this->runden->readyDraftIds($team);
    }

    /** 98 · 3.3 S5: BestellRunde::selectedDraftPreview() */
    public function selectedDraftPreview(Team $team, array $orderIds): array
    {
        return $this->runden->selectedDraftPreview($team, $orderIds);
    }

    /** @return array{sent:int,blocked:int,blockers:list<string>,sent_ids:list<int>,sent_at:string} */
    public function sendSelectedDrafts(Team $team, array $orderIds): array
    {
        $preview = $this->runden->selectedDraftPreview($team, $orderIds);
        $readyIds = collect($preview['orders'])->where('sendable', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $blocked = collect($preview['orders'])->where('sendable', false);

        DB::transaction(function () use ($team, $readyIds) {
            foreach ($readyIds as $orderId) {
                $this->setStatus($team, $orderId, OrderStatus::Sent);
            }
        });

        return [
            'sent' => count($readyIds),
            'blocked' => $blocked->count(),
            'blockers' => $blocked->map(fn (array $order) => $order['supplier'].': '.implode(', ', $order['blockers']))->values()->all(),
            'sent_ids' => $readyIds,
            'sent_at' => now()->format('d.m.Y H:i'),
        ];
    }

    /** @return array{cancelled:int,cancelled_ids:list<int>} */
    public function cancelSelectedDrafts(Team $team, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $draftIds = FoodAlchemistOrder::visibleToTeam($team)
            ->whereIn('id', $ids)
            ->where('status', OrderStatus::Draft->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($team, $draftIds) {
            foreach ($draftIds as $orderId) {
                $this->setStatus($team, $orderId, OrderStatus::Cancelled);
            }
        });

        return ['cancelled' => count($draftIds), 'cancelled_ids' => $draftIds];
    }

    private function markProductionSourcesHandedOver(Team $team, array $sources): void
    {
        foreach ($sources as $source) {
            if (($source['type'] ?? '') !== 'production') {
                continue;
            }
            $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
            if ($production === null) {
                continue;
            }
            $production->last_handover_at = now();
            $production->handover_targets_hash = \Platform\FoodAlchemist\Services\Produktion\ProductionOrderService::targetsHash($production->targets);
            $production->save();
        }
    }

    /** 98 · 3.3 S5: BestellBedarf::positionsSchluessel() */
    public function positionsSchluessel(array $pos): string
    {
        return $this->bedarf->positionsSchluessel($pos);
    }

    private function kopfAusQuelle(Team $team, int $orderId, string $reference): void
    {
        if ($reference === '') {
            return;
        }
        $order = FoodAlchemistOrder::find($orderId);
        if ($order === null || ($order->reference ?? '') !== '') {
            return;
        }
        $this->pflege->updateHeader($team, $orderId, ['reference' => $reference]);
    }

    // ── Recompute (E3 Aggregat-Rundung, E11 Live-Preis im draft) ──────────

    /** 98 · 3.3 OrderService-Schnitt: BestellZeilen::recomputeLine() */
    public function recomputeLine(FoodAlchemistOrderLine $line): void
    {
        $this->zeilen->recomputeLine($line);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellZeilen::recomputeOrder() */
    public function recomputeOrder(FoodAlchemistOrder $order): void
    {
        $this->zeilen->recomputeOrder($order);
    }

    // ── Manuelle Pflege (nur im draft, nur Besitzer) ──────────────────────

    /** 98 · 3.3 OrderService-Schnitt: BestellZeilen::updateLine() */
    public function updateLine(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        return $this->zeilen->updateLine($team, $lineId, $input);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellZeilen::removeLine() */
    public function removeLine(Team $team, int $lineId): void
    {
        $this->zeilen->removeLine($team, $lineId);
    }

    /** 98 · 3.3 S5: BestellPflege::deleteEmptyDrafts() */
    public function deleteEmptyDrafts(Team $team): int
    {
        return $this->pflege->deleteEmptyDrafts($team);
    }

    /** 98 · 3.3 S5: BestellPflege::createDraft() */
    public function createDraft(Team $team, int $supplierId, array $header = [], ?int $userId = null): FoodAlchemistOrder
    {
        return $this->pflege->createDraft($team, $supplierId, $header, $userId);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellZeilen::addManualLine() */
    public function addManualLine(Team $team, int $supplierItemId, float $qtyPacks, ?string $note = null, ?int $userId = null, ?string $deliveryDate = null): FoodAlchemistOrderLine
    {
        return $this->zeilen->addManualLine($team, $supplierItemId, $qtyPacks, $note, $userId, $deliveryDate);
    }

    /** 98 · 3.3 S5: BestellPflege::updateHeader() */
    public function updateHeader(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        return $this->pflege->updateHeader($team, $orderId, $input);
    }

    /** 98 · 3.3 S5: BestellPflege::updateSupplierConfirmation() */
    public function updateSupplierConfirmation(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        return $this->pflege->updateSupplierConfirmation($team, $orderId, $input);
    }

    /** 98 · 3.3 S5: BestellPflege::updateInvoiceHeader() */
    public function updateInvoiceHeader(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        return $this->pflege->updateInvoiceHeader($team, $orderId, $input);
    }

    /** 98 · 3.3 S5: BestellPflege::updatePayment() */
    public function updatePayment(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        return $this->pflege->updatePayment($team, $orderId, $input);
    }

    /** 98 · 3.3 S5: BestellPflege::updateApproval() */
    public function updateApproval(Team $team, int $orderId, array $input, ?int $userId = null): FoodAlchemistOrder
    {
        return $this->pflege->updateApproval($team, $orderId, $input, $userId);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellUmquellen::resourceOrder() */
    public function resourceOrder(Team $team, int $orderId, ?LeadLaStrategie $strategie = null, bool $apply = true, ?int $userId = null): array
    {
        return $this->umquellen->resourceOrder($team, $orderId, $strategie, $apply, $userId);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellUmquellen::lineAlternativen() */
    public function lineAlternativen(Team $team, int $lineId): array
    {
        return $this->umquellen->lineAlternativen($team, $lineId);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellUmquellen::gpAlternativen() */
    public function gpAlternativen(Team $team, int $gpId, ?int $currentSupplierId = null, ?int $currentLaId = null): array
    {
        return $this->umquellen->gpAlternativen($team, $gpId, $currentSupplierId, $currentLaId);
    }

    /** 98 · 3.3 OrderService-Schnitt: BestellUmquellen::switchLineArticle() */
    public function switchLineArticle(Team $team, int $lineId, int $newLaId, ?int $userId = null): array
    {
        return $this->umquellen->switchLineArticle($team, $lineId, $newLaId, $userId);
    }

    // ── Status-Lebenszyklus (guarded) ─────────────────────────────────────

    public function setStatus(Team $team, int $orderId, OrderStatus $ziel): FoodAlchemistOrder
    {
        // Spec 77a: ab Kuratieren (Mitglied); ohne angemeldeten Benutzer (System) keine Prüfung
        app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Bestellstatus setzen (senden, bestätigen, geliefert, stornieren)');

        $order = $this->schiene->ownedOrder($team, $orderId);
        $aktuell = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if ($aktuell === $ziel) {
            return $order;
        }
        if (! $aktuell->darfWechselnZu($ziel)) {
            throw new \RuntimeException("Statuswechsel von „{$aktuell->label()}“ zu „{$ziel->label()}“ ist nicht möglich.");
        }
        // Beim Absenden: Snapshot einfrieren = letzten draft-Stand rechnen, dann Status setzen.
        if ($ziel === OrderStatus::Sent) {
            $this->recomputeOrder($order);
            $blocker = $this->ampeln->sendBlockers($order->refresh());
            if ($blocker !== []) {
                throw new \RuntimeException('Bestellung kann nicht versendet werden: '.implode(', ', $blocker));
            }
            // Spec 63: bei Versandart „server" muss eine Mail rausgehen können — sonst nicht absenden.
            app(OrderMailService::class)->pruefeVersandbar($team, $order);
            $order->sent_at = now();
        } elseif ($ziel === OrderStatus::Confirmed) {
            $order->confirmed_at = now();
        } elseif ($ziel === OrderStatus::Delivered) {
            $this->fillReceiptFromOrder($order);
            $order->delivered_at = now();
        }
        $order->status = $ziel;
        $order->save();

        // Spec 63: Bestellversand per Mail (nur Versandart „server"; Standard „mailprogramm" = mailto wie bisher).
        // Hier im Service, damit Oberfläche, Bestellrunden und MCP denselben Weg nehmen.
        $mailer = app(OrderMailService::class);
        if ($mailer->istServerVersand($team)) {
            if ($ziel === OrderStatus::Sent) {
                $mailer->planen($team, $order, 'bestellung');
            } elseif ($ziel === OrderStatus::Cancelled && in_array($aktuell, [OrderStatus::Sent, OrderStatus::Confirmed], true)) {
                $mailer->planen($team, $order, 'storno');
            }
        }

        // Einkauf E2: FA-Einkauf → Journal. Storno entfernt die Ist-Buchungen; das Erreichen
        // des konfigurierten Auslöse-Status (sent|delivered, TeamSettingsService) spiegelt die
        // Zeilen als Ist-Einkäufe (idempotent). So zählt der in FA getätigte Einkauf auf Spend.
        $journal = app(\Platform\FoodAlchemist\Services\Einkauf\PurchaseJournalService::class);
        if ($ziel === OrderStatus::Cancelled) {
            $journal->entferneOrder($order);
        } elseif ($ziel->value === app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->purchaseJournalTrigger($team)) {
            $journal->spiegelOrder($order);
        }

        return $order;
    }

    // ── Lesen / Aggregate ─────────────────────────────────────────────────

    /** 98 · 3.3 S5: BestellLesen::listForTeam() */
    public function listForTeam(Team $team, ?string $status = null, array $filters = []): Collection
    {
        return $this->lesen->listForTeam($team, $status, $filters);
    }

    /** Detail-Aggregat für UI/MCP inkl. MOQ-Ampel. */
    public function detail(Team $team, int $orderId): array
    {
        // Spec 77c: Oberteam darf Belege seiner Standorte lesend öffnen (is_owned/editierbar bleiben false)
        $order = app(\Platform\FoodAlchemist\Services\Plattform\StandortService::class)->lesbarMitUnterTeams(FoodAlchemistOrder::query(), $team)
            ->with(['supplier', 'lines.gp:id,piece_default_g', 'lines.supplierItem'])
            ->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);

        $alleRefs = [];
        $zeilen = $order->lines->map(function ($l) use (&$alleRefs, $order, $team) {
            [$bedarf, $bedarfEinheit] = $this->lesen->zeileBedarf($l);
            $refs = array_keys($l->source_contributions ?? []);
            $alleRefs = array_merge($alleRefs, $refs);

            return [
                'id' => (int) $l->id,
                'gp_id' => $l->gp_id !== null ? (int) $l->gp_id : null,
                'supplier_item_id' => $l->supplier_item_id !== null ? (int) $l->supplier_item_id : null,
                'article_number' => $l->article_number,
                'designation' => $l->designation,
                'packaging_unit' => $l->packaging_unit,
                'pack_qty' => $l->pack_qty !== null ? (float) $l->pack_qty : null,
                'unit_code' => $l->unit_code,
                'qty_packs' => (float) $l->qty_packs,
                'is_manual_qty' => (bool) $l->is_manual_qty,
                'pack_price' => $l->pack_price !== null ? (float) $l->pack_price : null,
                'line_total' => (float) $l->line_total,
                'received_qty_packs' => $l->received_qty_packs !== null ? (float) $l->received_qty_packs : null,
                'received_note' => $l->received_note,
                'received_at' => $l->received_at?->format('Y-m-d H:i'),
                'receipt_diff_packs' => $l->received_qty_packs !== null ? round((float) $l->received_qty_packs - (float) $l->qty_packs, 2) : null,
                'receipt_status' => $this->summen->lineReceiptStatus($l),
                'invoice_qty_packs' => $l->invoice_qty_packs !== null ? (float) $l->invoice_qty_packs : null,
                'invoice_pack_price' => $l->invoice_pack_price !== null ? (float) $l->invoice_pack_price : null,
                'invoice_note' => $l->invoice_note,
                'invoice_checked_at' => $l->invoice_checked_at?->format('Y-m-d H:i'),
                'invoice_line_total' => $l->invoice_qty_packs !== null && $l->invoice_pack_price !== null
                    ? round((float) $l->invoice_qty_packs * (float) $l->invoice_pack_price, 2)
                    : null,
                'invoice_diff_net' => $this->summen->lineInvoiceDiffNet($l),
                'invoice_status' => $this->summen->lineInvoiceStatus($l),
                'claim_status' => $l->claim_status,
                'claim_status_label' => $this->summen->claimStatusLabel($l->claim_status),
                'claim_qty_packs' => $l->claim_qty_packs !== null ? (float) $l->claim_qty_packs : null,
                'credit_expected_net' => $l->credit_expected_net !== null ? (float) $l->credit_expected_net : null,
                'claim_note' => $l->claim_note,
                'quota_consumed_packs' => $l->quota_consumed_packs !== null ? (float) $l->quota_consumed_packs : null,
                'quota_consumed_at' => $l->quota_consumed_at?->format('Y-m-d H:i'),
                'quota' => $this->summen->lineQuotaSummary($l, $order->desired_delivery_date ?? now()),
                'inventory' => $this->inventory->lineStockSummary($team, $l),
                'needed_base_g' => (float) $l->needed_base_g,
                'needed_display' => $bedarf,          // E1: korrekte Bedarfsmenge in der Grundeinheit des LA
                'needed_unit' => $bedarfEinheit,      // kg / l / Stk (nie mehr fälschlich „kg" bei Stück)
                'note' => $l->note,
                'herkunft' => $this->herkunft->parseHerkunft($refs),
                'bestellbar' => $l->pack_price !== null && (float) $l->qty_packs > 0,
            ];
        })->all();

        $aggregat = $this->herkunft->herkunftAggregat($alleRefs);   // CQ 3.3: Zeilen tragen dieselben Produktionsnamen wie das Aggregat (eine Abfrage)
        $namen = $this->herkunft->produktionsNamen($team, collect($aggregat)->pluck('production_order_id')->filter()->map(fn ($id) => (int) $id)->unique()->values());
        $herkunft = $this->herkunft->beschrifteHerkunft($aggregat, $namen);
        $zeilen = array_map(fn (array $z) => array_replace($z, ['herkunft' => $this->herkunft->beschrifteHerkunft($z['herkunft'], $namen)]), $zeilen);
        $warnings = $this->ampeln->orderWarnings($order);
        $sendBlockers = $this->ampeln->sendBlockers($order);
        $invoiceDueDate = $this->summen->invoiceDueDate($order);

        return [
            'id' => (int) $order->id,
            'supplier_id' => (int) $order->supplier_id,
            'supplier' => $order->supplier?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'supplier_order_number' => $order->supplier_order_number,
            'confirmed_delivery_date' => $order->confirmed_delivery_date?->toDateString(),
            'supplier_confirmation_note' => $order->supplier_confirmation_note,
            'invoice_number' => $order->invoice_number,
            'invoice_date' => $order->invoice_date?->toDateString(),
            'invoice_due_date' => $invoiceDueDate?->toDateString(),
            'payment_term_days' => $order->supplier?->payment_term_days,
            'payment_status' => $order->payment_status,
            'invoice_paid_at' => $order->invoice_paid_at?->toDateString(),
            'payment_note' => $order->payment_note,
            'approval_status' => $order->approval_status,
            'approval_requested_at' => $order->approval_requested_at?->format('Y-m-d H:i'),
            'approved_at' => $order->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $order->approved_by !== null ? (int) $order->approved_by : null,
            'approval_note' => $order->approval_note,
            'invoice_note' => $order->invoice_note,
            'note' => $order->note,
            'sourcing_strategy' => $order->sourcing_strategy,   // E3: Preisstrategie-Override je Schiene (NULL = Haupteinstellung)
            'total_net' => (float) $order->total_net,
            'is_owned' => $order->isOwnedBy($team),
            'editierbar' => $status->istOffen() && $order->isOwnedBy($team),
            'wareneingang_editierbar' => in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed], true) && $order->isOwnedBy($team),
            'rechnung_editierbar' => in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true) && $order->isOwnedBy($team),
            'receipt' => $this->summen->receiptSummary($order),
            'invoice' => $this->summen->invoiceSummary($order),
            'payment' => $this->summen->paymentSummary($order),
            'approval' => $this->summen->approvalSummary($order),
            'claims' => $this->summen->claimSummary($order),
            'quota' => $this->summen->quotaSummary($order),
            'inventory' => $this->summen->inventorySummaryFromLines($zeilen),
            'moq' => $this->ampeln->moqAmpel($order),
            'warnings' => $warnings,
            'send_blockers' => $sendBlockers,
            'logistik' => $this->ampeln->logistikInfo($order),
            'herkunft' => $herkunft,   // E1: Schienen-weite Quellen-Übersicht (dedupliziert, mit Links)
            'zeilen' => $zeilen,
        ];
    }

    /** 98 · 3.3 S5: BestellHerkunft::parseHerkunft() */
    public function parseHerkunft(array $refs): array
    {
        return $this->herkunft->parseHerkunft($refs);
    }

    /** 98 · 3.3 S5: BestellHerkunft::herkunftAggregat() */
    public function herkunftAggregat(array $refs): array
    {
        return $this->herkunft->herkunftAggregat($refs);
    }

    /** 98 · 3.3 S5: BestellHerkunft::herkunftMitProduktionsnamen() */
    public function herkunftMitProduktionsnamen(Team $team, array $herkunft): array
    {
        return $this->herkunft->herkunftMitProduktionsnamen($team, $herkunft);
    }

    /** 98 · 3.3 S5: BestellHerkunft::herkunftMitProduktionsnamenBulk() */
    public function herkunftMitProduktionsnamenBulk(Team $team, array $herkunftProOrder): array
    {
        return $this->herkunft->herkunftMitProduktionsnamenBulk($team, $herkunftProOrder);
    }

    /** 98 · 3.3 S5: BestellDokument::dokument() */
    public function dokument(Team $team, int $orderId): array
    {
        return $this->dokumente->dokument($team, $orderId);
    }

    /** 98 · 3.3 S5: BestellDokument::mailtoData() */
    public function mailtoData(Team $team, int $orderId): array
    {
        return $this->dokumente->mailtoData($team, $orderId);
    }

    /** 98 · 3.3 S5: BestellDokument::cancellationMailtoData() */
    public function cancellationMailtoData(Team $team, int $orderId): array
    {
        return $this->dokumente->cancellationMailtoData($team, $orderId);
    }

    /** 98 · 3.3 S5: BestellDokument::productionCancellationMailtoData() */
    public function productionCancellationMailtoData(Team $team, int $orderId, int $productionOrderId): array
    {
        return $this->dokumente->productionCancellationMailtoData($team, $orderId, $productionOrderId);
    }

    /**
     * Bucht die tatsächlich gelieferte Gebinde-Menge einer Bestellzeile. Der Lagerzugang
     * wird idempotent als Delta gegen die bisherige Wareneingangsbuchung gespiegelt.
     */
    /**
     * Spec 75c: Wareneingang je Zeile setzen (Bestell-Editor, MCP). Läuft über einen Editor-Lieferschein
     * (`WareneingangService::kurzwegMenge`), damit die Bestellzeile immer die Summe ihrer Lieferscheine ist.
     */
    public function updateReceiptLine(Team $team, int $lineId, int|float|string|null $receivedQtyPacks, ?string $note = null): FoodAlchemistOrderLine
    {
        return app(\Platform\FoodAlchemist\Services\Einkauf\WareneingangService::class)->kurzwegMenge($team, $lineId, $receivedQtyPacks, $note);
    }

    /**
     * Spec 75c: Ableitung Beleg → Bestellzeile. NUR für WareneingangService (Lieferscheine buchen/stornieren,
     * Kurzweg). Schreibt `received_qty_packs` und zieht Kontingent, Lager (idempotent je Zeile) und Journal nach.
     */
    public function schreibeWareneingangAbleitung(Team $team, int $lineId, int|float|string|null $receivedQtyPacks, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->eingang->guardReceiptLine($team, $line);

        if ($receivedQtyPacks === '' || $receivedQtyPacks === null) {
            $line->received_qty_packs = null;
            $line->received_at = null;
        } else {
            $line->received_qty_packs = max(0, (float) $receivedQtyPacks);
            $line->received_at = now();
        }
        if ($note !== null) {
            $line->received_note = trim($note) !== '' ? trim($note) : null;
        }
        $line->save();
        $this->eingang->syncQuotaConsumption($line->refresh(), $line->received_qty_packs !== null ? (float) $line->received_qty_packs : null);
        $this->inventory->syncReceiptLine($line->refresh());
        $this->eingang->journalNachziehen($line->order);

        return $line->refresh();
    }

    /** Setzt alle Wareneingangs-Mengen einer Bestellung auf die bestellte Menge (Spec 75c: über den Editor-Lieferschein). */
    public function completeReceipt(Team $team, int $orderId): FoodAlchemistOrder
    {
        $this->schiene->ownedOrder($team, $orderId);
        app(\Platform\FoodAlchemist\Services\Einkauf\WareneingangService::class)->kurzwegAllesWieBestellt($team, $orderId);

        return FoodAlchemistOrder::findOrFail($orderId);
    }

    /**
     * Erstellt aus unterlieferten Wareneingangszeilen eine neue Draft-Nachlieferung.
     *
     * @return array{order_id:int, lines:int, total_qty_packs:float}
     */
    public function createBackorderFromReceipt(Team $team, int $orderId, ?string $deliveryDate = null, ?int $userId = null): array
    {
        $source = $this->schiene->ownedOrder($team, $orderId)->load(['supplier', 'lines']);
        $missing = $source->lines
            ->filter(fn ($line) => $line->supplier_item_id !== null && $line->received_qty_packs !== null)
            ->map(function ($line) {
                $diff = round((float) $line->qty_packs - (float) $line->received_qty_packs, 2);

                return ['line' => $line, 'missing' => $diff];
            })
            ->filter(fn ($row) => $row['missing'] > 0.0)
            ->values();

        if ($missing->isEmpty()) {
            throw new \RuntimeException('Keine unterlieferten Wareneingangszeilen für eine Nachlieferung.');
        }

        $date = ($deliveryDate !== null && trim($deliveryDate) !== '') ? Carbon::parse($deliveryDate)->toDateString() : null;
        $draft = $this->schiene->draftForSupplier($team, (int) $source->supplier_id, $date, $userId);
        $draft->reference = trim('Nachlieferung ord-'.(int) $source->id.($source->reference ? ' · '.$source->reference : ''));
        $draft->note = trim(($draft->note ? $draft->note."\n" : '').'Nachlieferung aus Wareneingang ord-'.(int) $source->id);
        $draft->save();

        $total = 0.0;
        foreach ($missing as $row) {
            /** @var FoodAlchemistOrderLine $line */
            $line = $row['line'];
            $qty = (float) $row['missing'];
            $newLine = $this->addManualLine(
                $team,
                (int) $line->supplier_item_id,
                $qty,
                'Nachlieferung zu ord-'.(int) $source->id.' · Zeile '.(int) $line->id,
                $userId,
                $date
            );
            if ((int) $newLine->order_id !== (int) $draft->id) {
                $draft = $newLine->order()->first() ?? $draft;
            }
            $total += $qty;
        }

        return [
            'order_id' => (int) $draft->id,
            'lines' => $missing->count(),
            'total_qty_packs' => round($total, 2),
        ];
    }

    /** 98 · 3.3 S5: BestellEingang::updateReceiptNote() */
    public function updateReceiptNote(Team $team, int $lineId, ?string $note = null): FoodAlchemistOrderLine
    {
        return $this->eingang->updateReceiptNote($team, $lineId, $note);
    }

    /**
     * Rechnungsprüfung light: berechnete Menge/Preis je Zeile erfassen und gegen Bestellung
     * sowie Wareneingang auswerten.
     */
    public function updateInvoiceLine(Team $team, int $lineId, int|float|string|null $qtyPacks, int|float|string|null $packPrice, ?string $note = null): FoodAlchemistOrderLine
    {
        // Spec 75c: über eine Editor-Rechnung — die Prüfwerte an der Zeile sind die Summe der freigegebenen Rechnungen.
        return app(\Platform\FoodAlchemist\Services\Einkauf\LieferantenRechnungService::class)->kurzwegRechnung($team, $lineId, $qtyPacks, $packPrice, $note);
    }

    /** 98 · 3.3 S5: BestellEingang::schreibeRechnungsAbleitung() */
    public function schreibeRechnungsAbleitung(Team $team, int $lineId, int|float|string|null $qtyPacks, int|float|string|null $packPrice, ?string $note = null): FoodAlchemistOrderLine
    {
        return $this->eingang->schreibeRechnungsAbleitung($team, $lineId, $qtyPacks, $packPrice, $note);
    }

    /** Übernimmt die Rechnung aus Wareneingang; falls kein WE gebucht ist, aus der Bestellung (Spec 75c: Editor-Rechnung). */
    public function completeInvoiceFromReceipt(Team $team, int $orderId): FoodAlchemistOrder
    {
        $this->schiene->ownedOrder($team, $orderId);
        app(\Platform\FoodAlchemist\Services\Einkauf\LieferantenRechnungService::class)->kurzwegRechnungAusWareneingang($team, $orderId);

        return FoodAlchemistOrder::findOrFail($orderId);
    }

    /** 98 · 3.3 S5: BestellEingang::updateInvoiceNote() */
    public function updateInvoiceNote(Team $team, int $lineId, ?string $note = null): FoodAlchemistOrderLine
    {
        return $this->eingang->updateInvoiceNote($team, $lineId, $note);
    }

    /** 98 · 3.3 S5: BestellEingang::updateClaimLine() */
    public function updateClaimLine(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        return $this->eingang->updateClaimLine($team, $lineId, $input);
    }

    /** 98 · 3.3 S5: BestellEingang::updateLineQuota() */
    public function updateLineQuota(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        return $this->eingang->updateLineQuota($team, $lineId, $input);
    }

    /** Alle noch offenen Wareneingangs-Mengen mit der bestellten Menge vorbelegen. */
    private function fillReceiptFromOrder(FoodAlchemistOrder $order): void
    {
        // Spec 75c: noch offene Zeilen über den Editor-Lieferschein vorbelegen (gleicher Lagerweg, idempotent).
        $team = Team::find($order->team_id);
        foreach ($order->lines()->get() as $line) {
            if ($line->received_qty_packs === null && $team !== null) {
                app(\Platform\FoodAlchemist\Services\Einkauf\WareneingangService::class)->kurzwegMenge($team, (int) $line->id, (float) $line->qty_packs, null);

                continue;
            }
            $this->eingang->syncQuotaConsumption($line->refresh(), (float) $line->received_qty_packs);
            $this->inventory->syncReceiptLine($line->refresh());
        }
    }

    /** 98 · 3.3 S5: BestellSummen::receiptSummary() */
    public function receiptSummary(FoodAlchemistOrder $order): array
    {
        return $this->summen->receiptSummary($order);
    }

    /** 98 · 3.3 S5: BestellSummen::invoiceSummary() */
    public function invoiceSummary(FoodAlchemistOrder $order): array
    {
        return $this->summen->invoiceSummary($order);
    }

    /** 98 · 3.3 S5: BestellSummen::paymentSummary() */
    public function paymentSummary(FoodAlchemistOrder $order): array
    {
        return $this->summen->paymentSummary($order);
    }

    /** 98 · 3.3 S5: BestellSummen::claimSummary() */
    public function claimSummary(FoodAlchemistOrder $order): array
    {
        return $this->summen->claimSummary($order);
    }

    /** 98 · 3.3 S5: BestellSummen::quotaSummary() */
    public function quotaSummary(FoodAlchemistOrder $order): array
    {
        return $this->summen->quotaSummary($order);
    }

    /** 98 · 3.3 S5: BestellSummen::approvalSummary() */
    public function approvalSummary(FoodAlchemistOrder $order): array
    {
        return $this->summen->approvalSummary($order);
    }

    /** 98 · 3.3 S5: BestellAmpeln::moqAmpel() */
    public function moqAmpel(FoodAlchemistOrder $order): array
    {
        return $this->ampeln->moqAmpel($order);
    }

    /** 98 · 3.3 S5: BestellAmpeln::orderWarnings() */
    public function orderWarnings(FoodAlchemistOrder $order): array
    {
        return $this->ampeln->orderWarnings($order);
    }

    /** 98 · 3.3 S5: BestellAmpeln::sendBlockers() */
    public function sendBlockers(FoodAlchemistOrder $order): array
    {
        return $this->ampeln->sendBlockers($order);
    }

    /** 98 · 3.3 S5: BestellAmpeln::logistikInfo() */
    public function logistikInfo(FoodAlchemistOrder $order): array
    {
        return $this->ampeln->logistikInfo($order);
    }

}
