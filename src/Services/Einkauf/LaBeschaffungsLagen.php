<?php

namespace Platform\FoodAlchemist\Services\Einkauf;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Preis\PriceService;

/**
 * Beschaffungs-Lagen der Lieferantenartikel für die Datenqualitäts-Ampel: zugeordnete Artikel,
 * die eingestellt sind oder keinen positiven Einkaufspreis haben. Die {@see \Platform\FoodAlchemist\Services\DataQualityService}
 * zählt, listet und emittiert sie wie jede andere Metrik; Prädikat, Label und Signal stehen nur hier.
 * Aus der DataQualityService gelöst (Größenwächter, CQ main-grün 2026-11-26), Verhalten unverändert.
 */
final class LaBeschaffungsLagen
{
    public const LABELS = [
        'la_eingestellt' => 'Eingestellte Lieferantenartikel',
        'la_preis_fehlt' => 'Lieferantenartikel ohne Einkaufspreis',
    ];

    /** Signal-Deskriptor je Metrik: [Typ, dedup_key, Beschreibung]. */
    public const SIGNALE = [
        'la_eingestellt' => [SignalTyp::LaEingestellt, 'dq-la-eingestellt', 'Diese Artikel sind einem aktiven Grundprodukt zugeordnet und nicht mehr bestellbar. Ersatzartikel prüfen und die Zuordnung aktualisieren.'],
        'la_preis_fehlt' => [SignalTyp::LaPreisFehlt, 'dq-la-preis-fehlt', 'Für diese lieferbaren und zugeordneten Artikel fehlt in der Preissicht des Teams ein positiver Einkaufspreis. Preis am Artikel prüfen oder den Katalogimport kontrollieren.'],
    ];

    public function __construct(private PriceService $preise) {}

    /** Register im Format der DataQuality-Checks: Metrik → ['q' => fn(Team) => Builder]. */
    public function checks(): array
    {
        return array_map(fn ($m) => ['q' => fn (Team $team) => $this->abfrage($team, $m)], array_combine(array_keys(self::LABELS), array_keys(self::LABELS)));
    }

    public function abfrage(Team $team, string $metrik): Builder
    {
        $gps = FoodAlchemistGp::visibleToTeam($team)->whereIn('status', ['approved', 'tentative'])
            ->where('requires_la', true)->select('foodalchemist_gps.id');
        $artikel = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('foodalchemist_supplier_item_structures as struktur')
                ->whereColumn('struktur.supplier_item_id', 'foodalchemist_supplier_items.id')
                ->whereNull('struktur.deleted_at')->whereIn('struktur.gp_id', $gps));

        if ($metrik === 'la_eingestellt') {
            return $artikel->where(fn ($q) => $q->where('is_discontinued', true)->orWhere('lieferstatus', 'eingestellt'));
        }

        $preis = $this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $team)->toBase();

        return $artikel->lieferbar()->whereRaw('COALESCE(('.$preis->toSql().'), 0) <= 0', $preis->getBindings());
    }

    /** @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}> */
    public function liste(Builder $q, int $limit): array
    {
        return $q->orderBy('designation')->orderBy('id')->limit($limit)->get(['id', 'designation'])
            ->map(fn ($la) => ['kind' => 'la', 'id' => (int) $la->id, 'name' => (string) $la->designation, 'is_sales_recipe' => false])->all();
    }
}
