<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Bestellzeilen rechnen und pflegen (98 · 3.3, aus OrderService verschoben): Zeile und Schiene neu rechnen (E3 Aggregat-
 * Rundung, E11 Live-Preis im Entwurf), Menge von Hand, Zeile entfernen, Direktbestellung (Spec 20 · E2) und Quell-Beiträge
 * aus offenen Entwürfen entfernen. Nutzt BestellSchiene, nicht die Fassade. PriceService und GebindeRechner liegen seit
 * 98 · 3.4 nicht mehr mit OrderService in einem Kreis, deshalb kann das Rechnen hier stehen.
 */
final class BestellZeilen
{
    public function __construct(
        private \Platform\FoodAlchemist\Services\Preis\PriceService $preise,
        private \Platform\FoodAlchemist\Services\Einkauf\GebindeRechner $gebinde,
        private BestellSchiene $schiene,
    ) {}

    /**
     * Entfernt alte Beiträge derselben Quellen aus offenen Drafts, bevor ein Override-Pfad
     * die Vorschau neu schreibt. So wandert ein Bedarf wirklich zwischen Lieferanten-Schienen,
     * statt als Doppelbedarf in alter und neuer Schiene zu landen.
     *
     * @param  list<string>  $sourceRefs
     * @return list<int> betroffene Order-IDs
     */
    public function clearSourceRefsFromDrafts(Team $team, array $sourceRefs): array
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
}
