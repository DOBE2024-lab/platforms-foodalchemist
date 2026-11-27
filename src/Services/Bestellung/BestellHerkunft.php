<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;

/**
 * Herkunft der Bestellzeilen (98 · 3.3 S5, aus OrderService verschoben; Read-Model): Quellen-Referenzen lesen,
 * zusammenfassen und mit Produktionsnamen beschriften. Nutzt keinen anderen Teil und nicht die Fassade.
 */
final class BestellHerkunft
{
    /**
     * Basis-Token der Produktions-Herkunft in `order_lines.source_contributions` (P4: EIN Ort für Bau und Lesen).
     * Liegt hier statt in ProductionOrderService, damit Bestell-Teile das Format kennen, ohne den Produktionsdienst
     * (und über ihn die Fassade) zu ziehen (98 · 3.3 S5); ProductionOrderService führt Konstante und Präfix als Alias.
     */
    public const PRODUKTION_BASIS = 'produktion:';

    /** Per-Auftrag-Präfix `produktion:{id}:` (trailing-Doppelpunkt disambiguiert #1 gegen #10). */
    public static function produktionsPraefix(int $productionOrderId): string
    {
        return self::PRODUKTION_BASIS.$productionOrderId.':';
    }

    /** Voller Quell-Key `produktion:{id}:{ref}` für die Bedarfs-Übergabe (98 · 3.3: hierher, ProductionOrderService::sourceRefFor ist Alias). */
    public static function produktionsRef(int $productionOrderId, string $ref): string
    {
        return self::produktionsPraefix($productionOrderId).$ref;
    }

    /** Stabiler Hash der Ziel-Liste einer Produktion (Stale-Marker P4; 98 · 3.3: hierher, ProductionOrderService::targetsHash ist Alias). */
    public static function zielHash(?array $targets): string
    {
        return sha1(json_encode(array_values($targets ?? []), JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** Produktionsauftrag-ID aus einem Quell-Key `produktion:{id}:…`, sonst null (Gegenstück zu produktionsPraefix). */
    public static function produktionsAuftragId(string $ref): ?int
    {
        return preg_match('/^'.preg_quote(self::PRODUKTION_BASIS, '/').'(\d+):/', $ref, $m) ? (int) $m[1] : null;
    }

    /**
     * E1: source_ref-Schlüssel in menschenlesbare Herkunft übersetzen. Kein FK — der Präfix
     * kodiert die Quelle: `produktion:{id}:…` (Produktionsauftrag), `concept:{id}@…`,
     * `recipe:{id|key}@…`, `event:…`. Unbekanntes bleibt als Roh-Label erhalten.
     *
     * @param  list<string>  $refs
     * @return list<array{ref:string, type:string, label:string, production_order_id:?int}>
     */
    public function parseHerkunft(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $ref = (string) $ref;
            if ($ref === '') {
                continue;
            }
            $type = 'sonstige';
            $label = $ref;
            $prodId = null;

            if (($prodId = self::produktionsAuftragId($ref)) !== null) {
                $type = 'produktion';
                $label = 'Produktion #'.$prodId;
            } elseif (preg_match('/^concept:([^@:]+)/', $ref, $m)) {
                $type = 'concept';
                $label = 'Konzept '.$m[1];
            } elseif (preg_match('/^recipe:([^@:]+)/', $ref, $m)) {
                $type = 'recipe';
                $label = 'Gericht '.$m[1];
            } elseif (preg_match('/^event:(.+)$/', $ref, $m)) {
                $type = 'event';
                $label = $m[1];
            }

            $out[] = ['ref' => $ref, 'type' => $type, 'label' => $label, 'production_order_id' => $prodId];
        }

        return $out;
    }

    /**
     * E1: dedupliziertes Schienen-Aggregat der Herkunft — je Quelle einmal (Produktion nach
     * Auftrag, sonst nach Präfix bis zum ersten „@"), für Panel 3 „Herkunft mit Links".
     *
     * @param  list<string>  $refs
     * @return list<array{key:string, type:string, label:string, production_order_id:?int}>
     */
    public function herkunftAggregat(array $refs): array
    {
        $byKey = [];
        foreach ($this->parseHerkunft($refs) as $h) {
            $key = $h['production_order_id'] !== null
                ? self::PRODUKTION_BASIS.$h['production_order_id']
                : $h['type'].':'.$h['label'];
            $byKey[$key] = [
                'key' => $key,
                'type' => $h['type'],
                'label' => $h['label'],
                'production_order_id' => $h['production_order_id'],
            ];
        }

        return array_values($byKey);
    }

    /**
     * Produktion-Herkunft als echten Produktionskontext beschriften, nicht nur als ID.
     *
     * @param  list<array{key:string, type:string, label:string, production_order_id:?int}>  $herkunft
     * @return list<array{key:string, type:string, label:string, production_order_id:?int}>
     */
    public function herkunftMitProduktionsnamen(Team $team, array $herkunft): array
    {
        return $this->beschrifteHerkunft($herkunft, $this->produktionsNamen($team, collect($herkunft)->pluck('production_order_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()));
    }

    /**
     * #6 N+1: wie {@see herkunftMitProduktionsnamen()}, aber fuer VIELE Bestellungen in EINEM Query.
     * Vorher wurde die Einzel-Methode je Order aufgerufen (Orders/Index) → 1 ProductionOrder-Query
     * pro Zeile. Hier gehen ALLE Produktions-IDs ueber alle Orders in ein whereIn; danach wird jede
     * Order in-memory beschriftet.
     *
     * @param  array<int, list<array{key:string, type:string, label:string, production_order_id:?int}>>  $herkunftProOrder
     * @return array<int, list<array{key:string, type:string, label:string, production_order_id:?int}>>
     */
    public function herkunftMitProduktionsnamenBulk(Team $team, array $herkunftProOrder): array
    {
        $ids = collect($herkunftProOrder)
            ->flatMap(fn ($herkunft) => collect($herkunft)->pluck('production_order_id')->all())
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return $herkunftProOrder;
        }

        $namen = $this->produktionsNamen($team, $ids);
        $out = [];
        foreach ($herkunftProOrder as $orderId => $herkunft) {
            $out[$orderId] = $this->beschrifteHerkunft($herkunft, $namen);
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, string> production_order_id ⇒ Label (ein Query) */
    public function produktionsNamen(Team $team, \Illuminate\Support\Collection $ids): \Illuminate\Support\Collection
    {
        return $ids->isEmpty() ? collect() : FoodAlchemistProductionOrder::visibleToTeam($team)   // ohne Produktions-Herkunft keine Abfrage
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'production_date'])
            ->mapWithKeys(fn ($p) => [
                (int) $p->id => trim(($p->name ?: 'Produktion #'.$p->id).($p->production_date ? ' · '.$p->production_date->format('d.m.Y') : '')),
            ]);
    }

    /**
     * @param  list<array{key:string, type:string, label:string, production_order_id:?int}>  $herkunft
     * @return list<array{key:string, type:string, label:string, production_order_id:?int}>
     */
    public function beschrifteHerkunft(array $herkunft, \Illuminate\Support\Collection $namen): array
    {
        return collect($herkunft)->map(function ($h) use ($namen) {
            if (($h['production_order_id'] ?? null) !== null) {
                $h['label'] = $namen[(int) $h['production_order_id']] ?? $h['label'];
            }

            return $h;
        })->values()->all();
    }
}
