<?php

namespace Platform\FoodAlchemist\Services;

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
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

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
        private PlanungsblattService $planung,
        private PriceService $preise,
        private GebindeRechner $gebinde,
        private LeadLaService $leadLa,
        private InventoryService $inventory,
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

    /**
     * Reine Einkaufsvorschau aus Cockpit-Quellen. Schreibt keine Drafts, bündelt aber schon
     * exakt wie die echte Bestellung: je Lieferant + Liefertag.
     *
     * @param  list<array{type:string,id:int|string,qty?:int|float|string,unit?:string,delivery_date?:?string,reference?:?string}>  $sources
     * @param  array<string,int>  $overrides  override_key => lead_la_id
     * @return array{orders_preview:list<array>, unresolved:list<array>, warnings:list<string>, totals:array}
     */
    public function previewFromSources(Team $team, array $sources, LeadLaStrategie|string|null $strategy = null, array $overrides = []): array
    {
        $strategie = $this->bedarf->strategieAusWert($strategy);
        $gruppen = [];
        $unresolved = [];
        $warnings = [];
        $sourceCount = 0;
        $rezeptLager = $this->bedarf->rezeptLagerTopf($team, $overrides);

        foreach (array_values($sources) as $idx => $source) {
            $sourceCount++;
            $type = (string) ($source['type'] ?? '');
            $date = ($source['delivery_date'] ?? null) ?: null;
            $reference = trim((string) ($source['reference'] ?? ''));
            $label = $this->bedarf->sourceLabel($team, $source);
            $sourceRef = $this->bedarf->sourceRef($source, $idx);

            try {
                if ($type === 'supplier_item') {
                    $this->previewSupplierItem($team, $gruppen, $unresolved, $source, $date, $reference, $label, $sourceRef);
                } elseif ($type === 'gp') {
                    $this->previewGp($team, $gruppen, $unresolved, $source, $date, $reference, $label, $sourceRef, $strategie, $overrides);
                } elseif ($type === 'recipe') {
                    $this->previewZiel($team, $gruppen, $unresolved, $warnings, $this->bedarf->zielAusRecipeSource($source), $date, $reference, $label, $sourceRef, $strategie, $overrides, $rezeptLager);
                } elseif ($type === 'concept' || $type === 'paket') {
                    // Spec 73: Konzept / Paket × Personen — Auflösung wie in der Produktion
                    $this->previewZiel($team, $gruppen, $unresolved, $warnings, $this->bedarf->zielAusMengenQuelle($source), $date, $reference, $label, $sourceRef, $strategie, $overrides, $rezeptLager);
                } elseif ($type === 'nachfuellen') {
                    // Spec 74: Lagerartikel unter Mindestbestand → auf Sollbestand auffüllen
                    foreach (app(LagerartikelService::class)->unterMindest($team) as $la) {
                        $qty = in_array($la['base'], ['g', 'ml'], true) ? $la['nachfuellen_basis'] / 1000 : $la['nachfuellen_basis'];
                        if ($qty <= 0) {
                            continue;
                        }
                        $this->previewGp($team, $gruppen, $unresolved, ['type' => 'gp', 'id' => $la['gp_id'], 'qty' => round($qty, 3), 'unit' => $la['base'] === 'Stk' ? 'stk' : 'kg'],
                            $date, $reference !== '' ? $reference : 'Lagerartikel nachfüllen', 'Nachfüllen: ' . $la['name'], 'nachfuellen:gp:' . $la['gp_id'], $strategie, $overrides);
                    }
                } elseif ($type === 'production') {
                    $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                    if ($production === null) {
                        $unresolved[] = $this->bedarf->unresolved($source, $label, 'produktion_fehlt', 'Produktion nicht sichtbar oder nicht vorhanden.');

                        continue;
                    }
                    $targets = $this->releasedProductionTargets($production);
                    $prodDate = $date ?: $production->production_date?->toDateString();
                    foreach ($targets as $targetIdx => $target) {
                        $targetRef = ProductionOrderService::sourceRefFor((int) $production->id, (string) ($target['source_ref'] ?? ('target-'.$targetIdx)));
                        $this->previewZiel(
                            $team,
                            $gruppen,
                            $unresolved,
                            $warnings,
                            $this->bedarf->zielOhneMeta($target),
                            $prodDate,
                            $reference !== '' ? $reference : (string) ($target['label'] ?? ''),
                            $label,
                            $targetRef,
                            $strategie,
                            $overrides,
                            $rezeptLager
                        );
                    }
                } else {
                    $unresolved[] = $this->bedarf->unresolved($source, $label, 'quelle_unbekannt', 'Quelltyp wird noch nicht unterstützt.');
                }
            } catch (\Throwable $e) {
                $unresolved[] = $this->bedarf->unresolved($source, $label, 'quelle_fehlerhaft', $e->getMessage());
            }
        }

        [$gruppen, $ausLager, $ausgelassen, $vorrat] = $this->nachbearbeitePreview($team, $gruppen, $overrides);
        $ordersPreview = $this->bedarf->finalisierePreviewGruppen($gruppen);
        $totalNet = round(array_sum(array_map(fn ($g) => (float) ($g['total_net'] ?? 0), $ordersPreview)), 2);

        return [
            'orders_preview' => $ordersPreview,
            // Spec 71: aus dem Lager gedeckt bzw. von Hand ausgelassen (zum Wiederherstellen)
            'aus_lager' => $ausLager,
            'ausgelassen' => $ausgelassen,
            'lager_abgleich' => ! empty($overrides['lager_abgleich']),
            'rezept_lager' => $this->bedarf->rezeptLagerAnzeige($rezeptLager),
            'vorrat' => $vorrat,                                                        // Spec 74: Lagerartikel aus dem Rezeptbedarf
            'nachfuellen_moeglich' => count(app(LagerartikelService::class)->unterMindest($team)),
            'nachfuellen_aktiv' => collect($sources)->contains(fn ($q) => ($q['type'] ?? '') === 'nachfuellen'),
            'unresolved' => array_values($unresolved),
            'warnings' => array_values(array_unique($warnings)),
            'totals' => [
                'sources' => $sourceCount,
                'groups' => count($ordersPreview),
                'positions' => array_sum(array_map(fn ($g) => count($g['positionen'] ?? []), $ordersPreview)),
                'unresolved' => count($unresolved),
                'total_net' => $totalNet,
            ],
        ];
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
        $cleared = $this->clearSourceRefsFromDrafts($team, $this->runden->replacementSourceRefs($team, $replacementSources, $preview));
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
            } elseif (in_array($type, ['recipe', 'concept', 'paket'], true)) {
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
                $targets = $this->releasedProductionTargets($production);
                $prodDate = $date ?: $production->production_date?->toDateString();
                foreach ($targets as $targetIdx => $target) {
                    $targetRef = ProductionOrderService::sourceRefFor((int) $production->id, (string) ($target['source_ref'] ?? ('target-'.$targetIdx)));
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
            $aktualisiert = $this->clearSourceRefsFromDrafts($team, (array) ($round->source_refs ?? []));
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

    /** @return list<array> */
    private function releasedProductionTargets(FoodAlchemistProductionOrder $production): array
    {
        if ($production->procurement_released_at === null || empty($production->procurement_targets_snapshot)) {
            throw new \RuntimeException('Materialbedarf dieser Produktion ist noch nicht freigegeben.');
        }
        if ($production->procurement_targets_hash !== ProductionOrderService::targetsHash($production->targets)) {
            throw new \RuntimeException('Materialbedarf wurde nach der Freigabe geändert. Bitte in Produktion erneut freigeben.');
        }

        return array_values($production->procurement_targets_snapshot);
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
                $linked = app(ProductionOrderService::class)->verknuepfteOrders($team, (int) $production->id);
                $round = $linked->flatMap(fn (FoodAlchemistOrder $order) => $order->rounds)
                    ->sortByDesc('id')->first();
                $stale = $production->procurement_targets_hash !== ProductionOrderService::targetsHash($production->targets);
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
        $prefix = ProductionOrderService::sourceRefPrefix($productionOrderId);
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

        $updatedOrderIds = $this->clearSourceRefsFromDrafts($team, $sourceRefs);
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

    /**
     * Entfernt alte Beiträge derselben Quellen aus offenen Drafts, bevor ein Override-Pfad
     * die Vorschau neu schreibt. So wandert ein Bedarf wirklich zwischen Lieferanten-Schienen,
     * statt als Doppelbedarf in alter und neuer Schiene zu landen.
     *
     * @param  list<string>  $sourceRefs
     * @return list<int> betroffene Order-IDs
     */
    private function clearSourceRefsFromDrafts(Team $team, array $sourceRefs): array
    {
        $sourceRefs = array_values(array_unique(array_filter($sourceRefs)));
        if ($sourceRefs === []) {
            return [];
        }

        $touched = [];
        $lines = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($q) => $q
                ->where('team_id', $team->id)
                ->where('status', OrderStatus::Draft->value))
            ->get();

        foreach ($lines as $line) {
            $contrib = (array) ($line->source_contributions ?? []);
            $original = $contrib;
            foreach ($sourceRefs as $ref) {
                unset($contrib[$ref]);
            }
            if ($contrib === $original) {
                continue;
            }

            $touched[] = (int) $line->order_id;
            if ($contrib === []) {
                $line->delete();
            } else {
                $line->source_contributions = $contrib;
                $line->save();
                $this->recomputeLine($line->refresh());
            }
        }

        foreach (array_unique($touched) as $orderId) {
            $order = FoodAlchemistOrder::where('team_id', $team->id)->find($orderId);
            if ($order !== null) {
                $this->recomputeOrder($order->refresh());
            }
        }

        return array_values(array_unique($touched));
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
            $production->handover_targets_hash = ProductionOrderService::targetsHash($production->targets);
            $production->save();
        }
    }

    private function previewSupplierItem(Team $team, array &$gruppen, array &$unresolved, array $source, ?string $date, string $reference, string $label, string $sourceRef): void
    {
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure.gp:id,name,piece_default_g'])
            ->find((int) ($source['id'] ?? 0));

        if ($la === null || $la->supplier_id === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'artikel_fehlt', 'Lieferantenartikel fehlt oder hat keinen Lieferanten.');

            return;
        }

        $qty = max(0.0, $this->bedarf->sourceQty($source));
        if ($qty <= 0) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'menge_fehlt', 'Menge muss größer 0 sein.');

            return;
        }

        $ctx = (object) [
            'qty' => FoodAlchemistSupplierItem::mengeVon($la),   // korrigierte Menge vor Necta-qty
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id, $team)?->price,
        ];
        $geb = $this->gebinde->berechne($ctx, 0.0, $la->structure?->gp?->piece_default_g !== null ? (float) $la->structure->gp->piece_default_g : null);
        $packPrice = $geb['pack_price'] ?? null;
        $lineTotal = $packPrice !== null ? round($qty * (float) $packPrice, 2) : 0.0;
        $this->bedarf->previewAddPosition($gruppen, (int) $la->supplier_id, $la->supplier?->name ?? '—', $date, [
            'source_ref' => $sourceRef,
            'source_label' => $label,
            'reference' => $reference,
            'type' => 'supplier_item',
            'gp_id' => $la->structure?->gp?->id !== null ? (int) $la->structure->gp->id : null,
            'gp' => $la->structure?->gp?->name,
            'lead_la_id' => (int) $la->id,
            'override_key' => $this->bedarf->overrideKey($sourceRef, $la->structure?->gp?->id !== null ? (int) $la->structure->gp->id : null),
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'qty_packs' => $qty,
            'packaging_unit' => $la->packaging_unit,
            'pack_price' => $packPrice !== null ? (float) $packPrice : null,
            'line_total' => $lineTotal,
            'needed_base_g' => 0.0,
            'needed_display' => null,
            'needed_unit' => null,
            'bestellbar' => $packPrice !== null && $qty > 0,
        ]);

        if ($packPrice === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'preis_fehlt', 'Preis am Lieferantenartikel fehlt; Position wird als 0-Euro-Klärfall angezeigt.');
        }
    }

    private function previewGp(Team $team, array &$gruppen, array &$unresolved, array $source, ?string $date, string $reference, string $label, string $sourceRef, ?LeadLaStrategie $strategie, array $overrides = []): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
        if ($gp === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'gp_fehlt', 'Grundprodukt nicht sichtbar oder nicht vorhanden.');

            return;
        }

        $grams = $this->bedarf->sourceGramsForGp($gp, $source);
        if ($grams <= 0) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'menge_fehlt', 'Menge muss größer 0 sein.');

            return;
        }
        if (($bz = \Platform\FoodAlchemist\Support\Derivat\DerivatBedarf::bestellziel($gp, $grams)) === null) { $unresolved[] = $this->bedarf->unresolved($source, $label, 'sammelbestand', 'Nebenprodukt aus dem Sammelbestand, wird nicht bestellt.'); return; } [$gp, $grams] = $bz;   // Spec 96: Zweck-Derivat → Mutter
        $overrideKey = $this->bedarf->overrideKey($sourceRef, (int) $gp->id);
        $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
        $lead = $this->overrideLead($team, $gp, $overrides[$overrideKey] ?? null) ?? $lead;
        if ($lead?->supplier_id === null || $lead?->id === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'lead_la_fehlt', 'Kein bestellbarer Hauptartikel für dieses Grundprodukt. Lieferantenartikel zuordnen.');

            return;
        }

        $pieceG = $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null;
        $geb = $this->gebinde->berechne($lead, $grams, $pieceG);
        $packPrice = $geb['pack_price'] ?? null;
        $this->bedarf->previewAddPosition($gruppen, (int) $lead->supplier_id, $lead->supplier_name ?? '—', $date, [
            'source_ref' => $sourceRef,
            'source_label' => $label,
            'reference' => $reference,
            'type' => 'gp',
            'gp_id' => (int) $gp->id,
            'gp' => $gp->name,
            'lead_la_id' => (int) $lead->id,
            'override_key' => $overrideKey,
            'article_number' => $lead->article_number,
            'designation' => $lead->designation,
            'qty_packs' => (float) ($geb['qty_packs'] ?? 0),
            'packaging_unit' => $geb['packaging_unit'] ?? $lead->packaging_unit,
            'pack_price' => $packPrice !== null ? (float) $packPrice : null,
            'line_total' => ($geb['line_total'] ?? null) !== null ? (float) $geb['line_total'] : 0.0,
            'needed_base_g' => $grams,
            'needed_display' => $this->bedarf->displayMenge($grams, (string) ($source['unit'] ?? 'kg'), $pieceG),
            'needed_unit' => (string) ($source['unit'] ?? 'kg'),
            'bestellbar' => $packPrice !== null && (float) ($geb['qty_packs'] ?? 0) > 0,
        ]);

        if ($packPrice === null || ! ($geb['berechenbar'] ?? false)) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'gebinde_preis_fehlt', 'Gebinde oder Preis ist nicht vollständig berechenbar.');
        }
    }

    private function previewZiel(Team $team, array &$gruppen, array &$unresolved, array &$warnings, array $ziel, ?string $date, string $reference, string $label, string $sourceRef, ?LeadLaStrategie $strategie, array $overrides = [], ?array &$rezeptLager = null): void
    {
        $vorschlag = $this->planung->bestellvorschlag($team, $ziel, $strategie, $rezeptLager);
        $warnings = array_merge($warnings, $vorschlag['warnungen'] ?? []);

        foreach (\Platform\FoodAlchemist\Support\Derivat\DerivatBedarf::ohneSammelbestand($vorschlag['lieferanten'] ?? []) as $grp) {   // Spec 96: Sammelbestand nie bestellen
            $supplierId = $grp['supplier_id'] ?? null;
            if ($supplierId === null) {
                foreach (($grp['positionen'] ?? []) as $pos) {
                    $unresolved[] = $this->bedarf->unresolved(
                        ['type' => 'recipe', 'id' => $ziel['recipe_id'] ?? null],
                        $label.($pos['gp'] ? ' · '.$pos['gp'] : ''),
                        'lead_la_fehlt',
                        'Kein bestellbarer Hauptartikel für diese Bedarfsposition. Lieferantenartikel zuordnen.'
                    );
                }

                continue;
            }

            foreach (($grp['positionen'] ?? []) as $pos) {
                $geb = $pos['gebinde'] ?? [];
                $gpId = $pos['gp_id'] !== null ? (int) $pos['gp_id'] : null;
                $neededG = (float) ($pos['menge_kg'] ?? 0) * 1000.0;
                $overrideKey = $this->bedarf->overrideKey($sourceRef, $gpId);
                $leadLaId = $pos['lead_la_id'] !== null ? (int) $pos['lead_la_id'] : null;
                $supplierForGroup = (int) $supplierId;
                $supplierName = (string) ($grp['lieferant'] ?? '—');
                $articleNumber = $pos['lead_artikel_nr'] ?? ($geb['article_number'] ?? null);
                $designation = $pos['lead_artikel'] ?? null;
                $packagingUnit = $geb['packaging_unit'] ?? null;
                $usedOverride = false;
                if ($gpId !== null && $overrideKey !== null && array_key_exists($overrideKey, $overrides)) {
                    $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
                    $overrideLead = $gp !== null ? $this->overrideLead($team, $gp, $overrides[$overrideKey] ?? null) : null;
                    if ($overrideLead !== null && $overrideLead->supplier_id !== null) {
                        $usedOverride = true;
                        $supplierForGroup = (int) $overrideLead->supplier_id;
                        $supplierName = (string) ($overrideLead->supplier_name ?? '—');
                        $leadLaId = (int) $overrideLead->id;
                        $pieceG = $gp?->piece_default_g !== null ? (float) $gp->piece_default_g : null;
                        $geb = $this->gebinde->berechne($overrideLead, $neededG, $pieceG);
                        $articleNumber = $overrideLead->article_number;
                        $designation = $overrideLead->designation;
                        $packagingUnit = $geb['packaging_unit'] ?? $overrideLead->packaging_unit;
                    }
                }
                $packPrice = $geb['pack_price'] ?? null;
                $this->bedarf->previewAddPosition($gruppen, $supplierForGroup, $supplierName, $date, [
                    'source_ref' => $sourceRef,
                    'source_label' => $label,
                    'reference' => $reference,
                    'type' => 'recipe',
                    'gp_id' => $gpId,
                    'gp' => $pos['gp'] ?? null,
                    'lead_la_id' => $leadLaId,
                    'override_key' => $overrideKey,
                    'article_number' => $articleNumber,
                    'designation' => $designation,
                    'qty_packs' => (float) ($geb['qty_packs'] ?? 0),
                    'packaging_unit' => $packagingUnit,
                    'pack_price' => $packPrice !== null ? (float) $packPrice : null,
                    'line_total' => ($geb['line_total'] ?? null) !== null ? (float) $geb['line_total'] : ($usedOverride ? 0.0 : ($pos['bestell_ek_eur'] !== null ? (float) $pos['bestell_ek_eur'] : 0.0)),
                    'needed_base_g' => $neededG,
                    'needed_display' => (float) ($pos['menge_kg'] ?? 0),
                    'needed_unit' => 'kg',
                    'bestellbar' => $leadLaId !== null && $packPrice !== null && (float) ($geb['qty_packs'] ?? 0) > 0,
                ]);

                if ($leadLaId === null || $packPrice === null || ! ($geb['berechenbar'] ?? false)) {
                    $unresolved[] = $this->bedarf->unresolved(
                        ['type' => 'recipe', 'id' => $ziel['recipe_id'] ?? null],
                        $label.($pos['gp'] ? ' · '.$pos['gp'] : ''),
                        $leadLaId === null ? 'lead_la_fehlt' : 'gebinde_preis_fehlt',
                        $leadLaId === null ? 'Kein Hauptartikel gefunden. Lieferantenartikel zuordnen.' : 'Gebinde oder Preis ist nicht vollständig berechenbar.'
                    );
                }
            }
        }
    }

    /** 98 · 3.3 S5: BestellBedarf::positionsSchluessel() */
    public function positionsSchluessel(array $pos): string
    {
        return $this->bedarf->positionsSchluessel($pos);
    }

    /**
     * Spec 71 · Vorschau nachbearbeiten (vor dem Gruppen-Abschluss, gilt für Vorschau UND Speichern):
     *  1. Lager-Abgleich (`overrides.lager_abgleich`): Bedarf aus Rezepten/Grundprodukten minus Lagerbestand
     *     des Grundprodukts (alle aktiven Lagerorte, je GP nur einmal verteilt); voll gedeckt → „aus Lager".
     *  2. Auslassen (`overrides.skip[schluessel]`) → Liste „ausgelassen", wiederherstellbar.
     *  3. Menge von Hand (`overrides.menge[schluessel]` = Gebinde) überschreibt die gerechneten Gebinde.
     *
     * @return array{0: array, 1: list<array>, 2: list<array>}
     */
    private function nachbearbeitePreview(Team $team, array $gruppen, array $overrides): array
    {
        $skip = (array) ($overrides['skip'] ?? []);
        $mengen = (array) ($overrides['menge'] ?? []);
        $global = ! empty($overrides['lager_abgleich']);
        $jePos = (array) ($overrides['lager_pos'] ?? []);
        // Bestand immer lesen: jede Position zeigt, was vom GP im Lager liegt — abgezogen wird nur auf Knopfdruck.
        // Spec 73: abzüglich dessen, was andere gespeicherte Runden schon angerechnet haben.
        $reserviert = $this->bedarf->lagerReserviert($team, isset($overrides['round_id']) ? (int) $overrides['round_id'] : null);
        $lager = $this->bedarf->lagerJeGp($team, $reserviert['gp']);
        $ausLager = [];
        $ausgelassen = [];
        $vorrat = [];
        $lagerartikel = app(LagerartikelService::class)->ids($team);
        $vorratBestellen = (array) ($overrides['vorrat_bestellen'] ?? []);
        foreach ($gruppen as $gk => $g) {
            $neu = [];
            foreach ($g['positionen'] as $pos) {
                $pk = $this->bedarf->positionsSchluessel($pos);
                $pos['position_key'] = $pk;
                $gpId = $pos['gp_id'] ?? null;
                $nachfuellen = str_starts_with((string) ($pos['source_ref'] ?? ''), 'nachfuellen:');
                $pos['nachfuellen'] = $nachfuellen;
                // 0. Spec 74: Lagerartikel (Gewürze, Öle …) kommen aus dem Vorrat, nicht aus dem Rezeptbedarf
                if (! $nachfuellen && $gpId !== null && isset($lagerartikel[(int) $gpId]) && ($pos['type'] ?? '') !== 'supplier_item' && empty($vorratBestellen[$pk])) {
                    $vorrat[] = $pos + ['supplier' => $g['supplier']];

                    continue;
                }
                // 1. Lager — nur gerechneter Bedarf (Rezept/GP), nicht der feste Artikel in Gebinden
                $lagerBar = ! $nachfuellen && ($pos['type'] ?? '') !== 'supplier_item' && $gpId !== null && (float) ($pos['needed_base_g'] ?? 0) > 0 && ($lager[$gpId] ?? 0) > 0;
                if ($lagerBar) {
                    $pos['lager_verfuegbar_g'] = round((float) $lager[$gpId], 2);
                }
                if ($gpId !== null && ($reserviert['gp'][$gpId] ?? 0) > 0) {
                    $pos['lager_reserviert_g'] = round((float) $reserviert['gp'][$gpId], 2);
                    $pos['lager_reserviert_fuer'] = array_values(array_unique($reserviert['runden']['gp:' . $gpId] ?? []));
                }
                $abziehen = array_key_exists($pk, $jePos) ? (bool) $jePos[$pk] : $global;
                if ($lagerBar && $abziehen) {
                    $bedarf = (float) $pos['needed_base_g'];
                    $nimm = min($bedarf, (float) $lager[$gpId]);
                    $lager[$gpId] -= $nimm;
                    $pos['bedarf_g'] = round($bedarf, 2);
                    $pos['lager_g'] = round($nimm, 2);
                    $pos['needed_base_g'] = round($bedarf - $nimm, 2);
                    if ($pos['needed_base_g'] <= 0.5) {
                        $ausLager[] = $pos + ['supplier' => $g['supplier']];

                        continue;
                    }
                    $lead = $this->leadObjekt($team, (int) ($pos['lead_la_id'] ?? 0));
                    if ($lead !== null) {
                        $gpModel = FoodAlchemistGp::find($gpId, ['id', 'piece_default_g']);
                        $geb = $this->gebinde->berechne($lead, (float) $pos['needed_base_g'], $gpModel?->piece_default_g !== null ? (float) $gpModel->piece_default_g : null);
                        $pos['qty_packs'] = (float) ($geb['qty_packs'] ?? $pos['qty_packs']);
                        $pos['line_total'] = (float) ($geb['line_total'] ?? $pos['line_total']);
                    }
                }
                // 2. Auslassen
                if (! empty($skip[$pk])) {
                    $ausgelassen[] = $pos + ['supplier' => $g['supplier']];

                    continue;
                }
                // 3. Menge von Hand (Gebinde)
                if (isset($mengen[$pk]) && is_numeric($mengen[$pk]) && (float) $mengen[$pk] >= 0) {
                    $pos['qty_packs_berechnet'] = (float) ($pos['qty_packs'] ?? 0);
                    $pos['qty_packs'] = round((float) $mengen[$pk], 3);
                    $pos['line_total'] = ($pos['pack_price'] ?? null) !== null ? round($pos['qty_packs'] * (float) $pos['pack_price'], 2) : (float) ($pos['line_total'] ?? 0);
                    $pos['menge_von_hand'] = true;
                }
                $neu[] = $pos;
            }
            if ($neu === []) {
                unset($gruppen[$gk]);

                continue;
            }
            $gruppen[$gk]['positionen'] = $neu;
            $gruppen[$gk]['total_net'] = round(array_sum(array_map(fn ($p) => (float) ($p['line_total'] ?? 0), $neu)), 2);
        }

        return [$gruppen, $ausLager, $ausgelassen, $vorrat];
    }

    /** Lieferantenartikel als Gebinde-Kontext (wie overrideLead, ohne GP-Prüfung). */
    private function leadObjekt(Team $team, int $laId): ?object
    {
        $la = $laId > 0 ? FoodAlchemistSupplierItem::visibleToTeam($team)->find($laId) : null;
        if ($la === null) {
            return null;
        }

        return (object) ['id' => (int) $la->id, 'qty' => FoodAlchemistSupplierItem::mengeVon($la), 'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit, 'article_number' => $la->article_number, 'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id, $team)?->price];
    }

    private function overrideLead(Team $team, FoodAlchemistGp $gp, int|string|null $leadLaId): ?object
    {
        if ($leadLaId === null || (int) $leadLaId <= 0) {
            return null;
        }

        $la = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure.gp:id,name'])
            ->find((int) $leadLaId);
        if ($la === null || $la->supplier_id === null || (int) ($la->structure?->gp?->id ?? 0) !== (int) $gp->id) {
            return null;
        }

        return (object) [
            'id' => (int) $la->id,
            'supplier_id' => (int) $la->supplier_id,
            'supplier_name' => $la->supplier?->name,
            'qty' => FoodAlchemistSupplierItem::mengeVon($la),   // korrigierte Menge vor Necta-qty
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id, $team)?->price,
        ];
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

    /** Zeile neu rechnen: needed_base_g = Σ Beiträge, Gebinde/Preis via GebindeRechner. */
    public function recomputeLine(FoodAlchemistOrderLine $line): void
    {
        $sum = array_sum(array_map('floatval', $line->source_contributions ?? []));
        $line->needed_base_g = round($sum, 2);

        [$ctx, $pieceG] = $this->leadContext($line);
        $geb = $this->gebinde->berechne($ctx, $sum, $pieceG);

        // Snapshot im draft immer auffrischen (E11); beim send bleibt der letzte Stand stehen (E2).
        $line->article_number = $geb['article_number'] ?? ($ctx->article_number ?? null);
        $line->designation = $ctx->designation ?? $line->designation;
        $line->packaging_unit = $geb['packaging_unit'] ?? ($ctx->packaging_unit ?? null);
        $line->pack_qty = $geb['pack_qty'];
        $line->unit_code = $geb['pack_unit_code'] ?? ($ctx->unit_code ?? null);
        $line->pack_price = $geb['pack_price'];

        if (! $line->is_manual_qty) {
            $line->qty_packs = $geb['qty_packs'] ?? 0;
        }
        $packs = (float) $line->qty_packs;
        $line->line_total = $geb['pack_price'] !== null ? round($packs * (float) $geb['pack_price'], 2) : 0;
        $line->save();
    }

    /** Lead-Kontext (Plain-Objekt für GebindeRechner) + Stückgewicht aus dem konkreten LA. */
    private function leadContext(FoodAlchemistOrderLine $line): array
    {
        if ($line->supplier_item_id === null) {
            return [null, null];
        }
        $la = FoodAlchemistSupplierItem::find($line->supplier_item_id);
        if ($la === null) {
            return [null, null];
        }
        // Spec 82 3a: Preis des bestellenden Teams (Team → Vorfahren → Listenpreis).
        $price = $this->preise->activeFor((int) $la->id, $line->team_id ? Team::find($line->team_id) : null);
        $ctx = (object) [
            'qty' => FoodAlchemistSupplierItem::mengeVon($la),   // korrigierte Menge vor Necta-qty
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $price?->price !== null ? (float) $price->price : null,
        ];
        $pieceG = null;
        if ($line->gp_id !== null) {
            $gp = FoodAlchemistGp::find($line->gp_id);
            $pieceG = $gp?->piece_default_g !== null ? (float) $gp->piece_default_g : null;
        }

        return [$ctx, $pieceG];
    }

    /** Ganze Schiene neu rechnen: leere Zeilen wegräumen (draft), Zeilen auffrischen, total_net. */
    public function recomputeOrder(FoodAlchemistOrder $order): void
    {
        if ($order->status === OrderStatus::Draft) {
            foreach ($order->lines()->get() as $line) {
                if (empty($line->source_contributions) && ! $line->is_manual_qty) {
                    $line->delete();

                    continue;
                }
                $this->recomputeLine($line);
            }
        }
        $order->total_net = round((float) $order->lines()->sum('line_total'), 2);
        $order->save();
    }

    // ── Manuelle Pflege (nur im draft, nur Besitzer) ──────────────────────

    /** Gebinde-Anzahl manuell übersteuern (bleibt bei Recompute stehen) oder Notiz setzen. */
    public function updateLine(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        $line = $this->schiene->ownedDraftLine($team, $lineId);
        if (array_key_exists('qty_packs', $input) && $input['qty_packs'] !== '' && $input['qty_packs'] !== null) {
            $line->qty_packs = max(0, (float) $input['qty_packs']);
            $line->is_manual_qty = true;
            $line->line_total = $line->pack_price !== null ? round((float) $line->qty_packs * (float) $line->pack_price, 2) : 0;
        }
        if (array_key_exists('reset_qty', $input) && $input['reset_qty']) {
            $line->is_manual_qty = false;   // nächster Recompute rechnet wieder automatisch
        }
        if (array_key_exists('note', $input)) {
            $line->note = ($input['note'] ?? '') ?: null;
        }
        $line->save();
        $this->recomputeOrder($line->order()->first());

        return $line->refresh();
    }

    public function removeLine(Team $team, int $lineId): void
    {
        $line = $this->schiene->ownedDraftLine($team, $lineId);
        $order = $line->order()->first();
        $line->delete();
        $this->recomputeOrder($order);
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

    /**
     * Spec 20 · E2 — Direktbestellung: eine Zeile MANUELL an die Draft-Schiene des
     * Lieferanten hängen (unabhängig von jeder Produktion/jedem Ziel). Legt die Schiene
     * an, wenn nötig; die Zeile trägt `is_manual_qty=true` + leeres `source_contributions`
     * (der Cleanup-Guard in recomputeOrder verschont sie), Preis-Snapshot über den
     * recomputeLine-Pfad (needed_base_g bleibt 0 = kein Ziel-Bedarf). Existiert für den
     * Artikel bereits eine Zeile in der Schiene, wird deren Menge manuell übersteuert
     * (Setter-Semantik, idempotent) statt eine Dublette anzulegen.
     */
    public function addManualLine(Team $team, int $supplierItemId, float $qtyPacks, ?string $note = null, ?int $userId = null, ?string $deliveryDate = null): FoodAlchemistOrderLine
    {
        // D1: nur team-sichtbare Artikel (eigenes Team + Master-Kette/Seed) sind bestellbar.
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)->with('structure')->find($supplierItemId);
        if ($la === null) {
            throw new \RuntimeException('Lieferantenartikel nicht gefunden.');
        }
        if ($la->supplier_id === null) {
            throw new \RuntimeException('Lieferantenartikel ohne Lieferant ist nicht bestellbar.');
        }
        $qty = max(0.0, (float) $qtyPacks);
        $draft = $this->schiene->draftForSupplier($team, (int) $la->supplier_id, $deliveryDate, $userId);

        $line = FoodAlchemistOrderLine::where('order_id', $draft->id)
            ->where('supplier_item_id', $la->id)
            ->first();
        if ($line === null) {
            $line = new FoodAlchemistOrderLine([
                'team_id' => $team->id,
                'order_id' => $draft->id,
                'supplier_item_id' => $la->id,
                'gp_id' => $la->structure?->gp_id,
                'source_contributions' => [],
                'position' => (int) FoodAlchemistOrderLine::where('order_id', $draft->id)->max('position') + 1,
            ]);
        }
        $line->is_manual_qty = true;
        $line->qty_packs = $qty;
        if ($note !== null) {
            $line->note = $note !== '' ? $note : null;
        }
        $line->save();

        $this->recomputeOrder($draft->refresh());

        return $line->refresh();
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
            $this->recomputeOrder($o->refresh());
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
            $this->addManualLine($team, $newLaId, (float) $line->qty_packs, $line->note, $userId, $deliveryDate);
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
            $this->recomputeOrder($sourceOrder);
        }
        if ($target !== null) {
            $this->recomputeOrder($target->refresh());
        }

        return [
            'order_id' => $sourceOrderId,
            'target_order_id' => $target?->id !== null ? (int) $target->id : null,
            'schiene_wechsel' => $target !== null,
        ];
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
        $journal = app(PurchaseJournalService::class);
        if ($ziel === OrderStatus::Cancelled) {
            $journal->entferneOrder($order);
        } elseif ($ziel->value === app(TeamSettingsService::class)->purchaseJournalTrigger($team)) {
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
        $order = app(StandortService::class)->lesbarMitUnterTeams(FoodAlchemistOrder::query(), $team)
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
        return app(WareneingangService::class)->kurzwegMenge($team, $lineId, $receivedQtyPacks, $note);
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
        app(WareneingangService::class)->kurzwegAllesWieBestellt($team, $orderId);

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
        return app(LieferantenRechnungService::class)->kurzwegRechnung($team, $lineId, $qtyPacks, $packPrice, $note);
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
        app(LieferantenRechnungService::class)->kurzwegRechnungAusWareneingang($team, $orderId);

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
                app(WareneingangService::class)->kurzwegMenge($team, (int) $line->id, (float) $line->qty_packs, null);

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
