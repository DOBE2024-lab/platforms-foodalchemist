<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderRound;

/**
 * Bestellrunden (98 · 3.3 S5, aus OrderService verschoben): Runden lesen und speichern, Versand-Vorschau, Strategie
 * an den Schienen vermerken, leere neu geplante Entwürfe aufräumen und die Prüfung, welche Produktions-Quellen
 * ersetzt werden dürfen. Übernahme, Versand und Löschen (generateDraftsFromSources, sendReadyDrafts/
 * sendSelectedDrafts/cancelSelectedDrafts, deleteRound, withdrawProductionDemand, productionDemandsForTeam,
 * markProductionSourcesHandedOver) bleiben in der Fassade: sie rechnen neu oder brauchen ProductionOrderService,
 * das mit OrderService in einem Kreis liegt (98 · 3.4). Nutzt BestellAmpeln und BestellBedarf, nicht die Fassade.
 */
final class BestellRunde
{
    public function __construct(
        private BestellAmpeln $ampeln,
        private BestellBedarf $bedarf,
    ) {}

    /**
     * Spec 73: was die Runde über sich speichert — Quellen + Einstellungen (vollständig wieder öffnen),
     * Source-Refs (sauber löschen) und was sie aus dem Lager angerechnet hat (Reservierung).
     */
    public function rundenStand(Team $team, array $sources, array $overrides, array $preview, array $replacementSources): array
    {
        $gp = [];
        foreach (collect($preview['orders_preview'] ?? [])->flatMap(fn ($g) => $g['positionen'] ?? [])->concat($preview['aus_lager'] ?? []) as $pos) {
            if (($pos['gp_id'] ?? null) !== null && (float) ($pos['lager_g'] ?? 0) > 0) {
                $gp[(int) $pos['gp_id']] = round(($gp[(int) $pos['gp_id']] ?? 0) + (float) $pos['lager_g'], 2);
            }
        }
        $rezept = [];
        foreach ($preview['rezept_lager'] ?? [] as $r) {
            if ((float) ($r['abgezogen_basis'] ?? 0) > 0) {
                $rezept[(int) $r['recipe_id']] = (float) $r['abgezogen_basis'];
            }
        }

        return ['_stand' => [
            'sources' => array_values($sources),
            'overrides' => array_diff_key($overrides, ['round_id' => 1]),
            'source_refs' => array_values(array_unique(array_merge($this->bedarf->previewSourceRefs($preview), $this->replacementSourceRefs($team, $replacementSources, $preview)))),
            'lager_reserviert' => $gp === [] && $rezept === [] ? null : ['gp' => $gp, 'rezept' => $rezept],
        ]];
    }

    /**
     * @param  list<int>  $orderIds
     * @param  array{id?:?int,label?:?string,desired_delivery_date?:?string,note?:?string}  $metadata
     */
    public function persistRound(Team $team, array $orderIds, ?LeadLaStrategie $strategy, ?int $userId, array $metadata): ?array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return null;
        }

        return DB::transaction(function () use ($team, $orderIds, $strategy, $userId, $metadata) {
            $roundId = isset($metadata['id']) ? (int) $metadata['id'] : null;
            $round = $roundId !== null && $roundId > 0
                ? FoodAlchemistOrderRound::visibleToTeam($team)->lockForUpdate()->findOrFail($roundId)
                : new FoodAlchemistOrderRound(['team_id' => $team->id, 'created_by' => $userId]);

            if ($round->exists && ! $round->isOwnedBy($team)) {
                throw new \RuntimeException('Diese Bestellrunde gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
            }

            $round->fill([
                'label' => trim((string) ($metadata['label'] ?? '')) ?: ($round->label ?: 'Bestellrunde '.now()->format('d.m.Y · H:i')),
                'desired_delivery_date' => ($metadata['desired_delivery_date'] ?? null) ?: $round->desired_delivery_date,
                'sourcing_strategy' => $strategy?->value,
                'note' => trim((string) ($metadata['note'] ?? '')) ?: $round->note,
            ]);
            if (isset($metadata['_stand'])) {
                $round->fill($metadata['_stand']);
            }
            $round->save();

            $ownedIds = FoodAlchemistOrder::query()
                ->where('team_id', $team->id)
                ->whereIn('id', $orderIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (count($ownedIds) !== count($orderIds)) {
                throw new \RuntimeException('Mindestens eine Bestellung gehört nicht zum aktuellen Team.');
            }

            $round->orders()->syncWithoutDetaching(collect($ownedIds)->mapWithKeys(fn ($id) => [$id => ['team_id' => $team->id]])->all());

            return $this->roundDetail($team, (int) $round->id);
        });
    }

    public function roundsForTeam(Team $team): Collection
    {
        return FoodAlchemistOrderRound::visibleToTeam($team)
            ->with(['orders.supplier', 'orders.lines.supplierItem'])
            ->latest('id')
            ->get()
            ->map(fn (FoodAlchemistOrderRound $round) => $this->roundSummary($round));
    }

    public function roundDetail(Team $team, int $roundId): array
    {
        $round = FoodAlchemistOrderRound::visibleToTeam($team)
            ->with(['orders.supplier', 'orders.lines'])
            ->findOrFail($roundId);

        return $this->roundSummary($round);
    }

    /** @return list<int> */
    public function readyDraftIds(Team $team): array
    {
        return FoodAlchemistOrder::visibleToTeam($team)
            ->with(['supplier', 'lines'])
            ->where('status', OrderStatus::Draft->value)
            ->get()
            ->filter(fn (FoodAlchemistOrder $order) => $this->ampeln->sendBlockers($order) === [])
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return array{orders:list<array>,selected:int,ready:int,blocked:int,total_net:float} */
    public function selectedDraftPreview(Team $team, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $orders = FoodAlchemistOrder::visibleToTeam($team)
            ->with(['supplier', 'lines'])
            ->whereIn('id', $ids)
            ->where('status', OrderStatus::Draft->value)
            ->get()
            ->map(function (FoodAlchemistOrder $order) {
                $blockers = $this->ampeln->sendBlockers($order);

                return [
                    'id' => (int) $order->id,
                    'supplier' => $order->supplier?->name ?? '—',
                    'created_at' => $order->created_at?->toDateString(),
                    'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
                    'positions' => $order->lines->count(),
                    'total_net' => (float) $order->total_net,
                    'sendable' => $blockers === [],
                    'blockers' => $blockers,
                ];
            })->sortBy('supplier')->values();

        return [
            'orders' => $orders->all(),
            'selected' => $orders->count(),
            'ready' => $orders->where('sendable', true)->count(),
            'blocked' => $orders->where('sendable', false)->count(),
            'total_net' => round((float) $orders->where('sendable', true)->sum('total_net'), 2),
        ];
    }

    private function roundSummary(FoodAlchemistOrderRound $round): array
    {
        $orders = $round->orders;
        $drafts = $orders->filter(fn (FoodAlchemistOrder $order) => $order->status === OrderStatus::Draft);
        $blockers = $drafts->flatMap(fn (FoodAlchemistOrder $order) => $this->ampeln->sendBlockers($order))->unique()->values()->all();
        $productionIds = $orders
            ->flatMap(fn (FoodAlchemistOrder $order) => $order->lines
                ->flatMap(fn (FoodAlchemistOrderLine $line) => array_keys((array) $line->source_contributions)))
            ->map(fn (string $ref) => BestellHerkunft::produktionsAuftragId($ref))
            ->filter()->unique()->values()->all();

        return [
            'id' => (int) $round->id,
            'uuid' => (string) $round->uuid,
            'label' => $round->label ?: 'Bestellrunde '.($round->created_at?->format('d.m.Y · H:i') ?? '#'.$round->id),
            'desired_delivery_date' => $round->desired_delivery_date?->toDateString(),
            'sourcing_strategy' => $round->sourcing_strategy,
            'note' => $round->note,
            'created_at' => $round->created_at?->toIso8601String(),
            'production_ids' => $productionIds,
            'sources' => (array) ($round->sources ?? []),       // Spec 73: Runde öffnet vollständig wieder
            'overrides' => (array) ($round->overrides ?? []),
            'lager_reserviert' => $round->lager_reserviert,
            'orders' => $orders->map(fn (FoodAlchemistOrder $order) => [
                'id' => (int) $order->id,
                'supplier' => $order->supplier?->name ?? '—',
                'status' => ($order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status))->value,
                'status_label' => ($order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status))->label(),
                'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
                'positions' => $order->lines->count(),
                'total_net' => (float) $order->total_net,
                'warnings' => $this->ampeln->orderWarnings($order),
            ])->values()->all(),
            'order_count' => $orders->count(),
            'supplier_count' => $orders->pluck('supplier_id')->unique()->count(),
            'position_count' => $orders->sum(fn (FoodAlchemistOrder $order) => $order->lines->count()),
            'total_net' => round((float) $orders->sum('total_net'), 2),
            'draft_count' => $drafts->count(),
            'editable' => $orders->isNotEmpty() && $drafts->count() === $orders->count(),
            'sendable' => $drafts->isNotEmpty() && $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Aktuelle Preview-Refs plus frühere Refs derselben Produktionen. So verschwinden beim
     * erneuten Planen auch entfernte Ziele und Beiträge an einem alten Liefertag.
     *
     * @return list<string>
     */
    public function replacementSourceRefs(Team $team, array $sources, array $preview): array
    {
        $refs = $this->bedarf->previewSourceRefs($preview);
        $prefixes = collect($sources)
            ->filter(fn (array $source) => ($source['type'] ?? null) === 'production' && (int) ($source['id'] ?? 0) > 0)
            ->map(fn (array $source) => BestellHerkunft::produktionsPraefix((int) $source['id']))
            ->unique()->values();
        if ($prefixes->isEmpty()) {
            return $refs;
        }

        FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->where('status', OrderStatus::Draft->value))
            ->get(['source_contributions'])
            ->each(function (FoodAlchemistOrderLine $line) use (&$refs, $prefixes) {
                foreach (array_keys((array) $line->source_contributions) as $ref) {
                    if ($prefixes->contains(fn (string $prefix) => str_starts_with((string) $ref, $prefix))) {
                        $refs[] = (string) $ref;
                    }
                }
            });

        return array_values(array_unique($refs));
    }

    public function assertProductionSourcesReplaceable(Team $team, array $sources): void
    {
        $prefixes = collect($sources)
            ->filter(fn (array $source) => ($source['type'] ?? null) === 'production' && (int) ($source['id'] ?? 0) > 0)
            ->map(fn (array $source) => BestellHerkunft::produktionsPraefix((int) $source['id']))
            ->unique()->values();
        if ($prefixes->isEmpty()) {
            return;
        }

        $locked = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::Confirmed->value, OrderStatus::Delivered->value]))
            ->get(['source_contributions'])
            ->contains(function (FoodAlchemistOrderLine $line) use ($prefixes) {
                return collect(array_keys((array) $line->source_contributions))
                    ->contains(fn (string $ref) => $prefixes->contains(fn (string $prefix) => str_starts_with($ref, $prefix)));
            });

        if ($locked) {
            throw new \RuntimeException('Mindestens eine zugehörige Bestellung wurde bereits ausgelöst. Der bestehende Stand bleibt eingefroren; Änderungen benötigen einen Korrekturbedarf.');
        }
    }

    /** @param list<int> $orderIds */
    public function deleteEmptyReplannedDrafts(Team $team, array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        FoodAlchemistOrder::visibleToTeam($team)
            ->where('team_id', $team->id)
            ->whereIn('id', array_values(array_unique(array_map('intval', $orderIds))))
            ->where('status', OrderStatus::Draft->value)
            ->whereDoesntHave('lines')
            ->get()
            ->each->delete();
    }

    public function stampStrategyOnOrders(Team $team, array $orderIds, ?LeadLaStrategie $strategie): void
    {
        foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
            if ($orderId <= 0) {
                continue;
            }
            $order = FoodAlchemistOrder::where('team_id', $team->id)->find($orderId);
            if ($order === null) {
                continue;
            }
            $order->sourcing_strategy = $strategie?->value;
            $order->save();
        }
    }
}
