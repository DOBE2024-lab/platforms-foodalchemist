<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\Preis\LetzterPreisService;
use Platform\FoodAlchemist\Services\Preis\PriceService;
use Platform\FoodAlchemist\Support\LeadOverlay;

/**
 * Code-Qualität 2.1a · Lese-Vorrat des Recompute: Artikel mit Aktiv-Preis und Lead-Overlay je **Team + GP** (Preise und Pins sind teamabhängig,
 * Spec 82 3a / Spec 85 PR-B), Formen-Gewichte je GP + Einheit. Innerhalb eines Laufs ({@see lauf}) bleibt der Vorrat über alle Rezepte stehen
 * und wird je Rezept für alle GP-Zeilen in je einer Abfrage nachgeladen ({@see vorladen}); am Ende des Laufs wird er verworfen. Außerhalb eines
 * Laufs verhält sich alles wie vorher (Einzelabfrage, Leeren beim Teamwechsel).
 */
final class RecomputeVorrat
{
    /** @var array<string, Collection> "team:gp" => Artikel inkl. aktiver_preis */
    private array $las = [];

    /** @var array<string, array> "team:gp" => Lead-Overlay (LeadOverlay::fuer) */
    private array $prefs = [];

    /** @var array<string, mixed> "f:gp:slug" => gramm | "c:gp:unit" => default_g (roh, null = keine Zeile) */
    private array $formen = [];

    private int $tiefe = 0;

    public function __construct(private PriceService $preise) {}

    /** `$fn` als ein Lauf: Vorrat (und das GP-Memo von {@see GpAggregateService}) gilt bis zum Ende des äußersten Laufs. */
    public function lauf(callable $fn): mixed
    {
        if ($this->tiefe++ === 0) {
            $this->leeren();                                       // nie Preise von vor dem Lauf mitnehmen
        }
        try {
            return GpAggregateService::imLauf($fn);
        } finally {
            if (--$this->tiefe === 0) {
                $this->leeren();
            }
        }
    }

    public function imLauf(): bool
    {
        return $this->tiefe > 0;
    }

    public function leeren(): void
    {
        [$this->las, $this->prefs, $this->formen] = [[], [], []];
    }

    /** Artikel des GP inkl. Aktiv-Preis in der Sicht des Rechen-Teams. */
    public function las(int $gpId, ?Team $team): Collection
    {
        return $this->las[self::key($team, $gpId)] ??= $this->ladeLas([$gpId], $team)[$gpId];
    }

    /** Fremd geladene Artikel (Preisvarianten des Zutateneditors) übernehmen. */
    public function setzeLas(int $gpId, ?Team $team, Collection $las): void
    {
        $this->las[self::key($team, $gpId)] = $las;
    }

    /** Lead-Overlay (Pin/Sperre inkl. Übernahme vom Kunden-Hauptteam) des Teams für den GP. */
    public function prefs(int $gpId, Team $team): array
    {
        return $this->prefs[self::key($team, $gpId)] ??= LeadOverlay::fuer($team, [$gpId])[$gpId] ?? [];
    }

    public function setzePrefs(int $gpId, Team $team, array $prefs): void
    {
        $this->prefs[self::key($team, $gpId)] = $prefs;
    }

    /** Gramm einer GP-Form (Formen-Editor/KI), roh; null = keine Form. Im Lauf gemerkt. */
    public function formGramm(int $gpId, string $slug): mixed
    {
        $k = "f:{$gpId}:{$slug}";
        if (array_key_exists($k, $this->formen)) {
            return $this->formen[$k];
        }
        $v = DB::table('foodalchemist_gp_forms')->where('gp_id', $gpId)->where('form_slug', $slug)->whereNull('deleted_at')->value('gramm');

        return $this->tiefe > 0 ? $this->formen[$k] = $v : $v;
    }

    /** Alt-Tabelle Zähl-Einheiten-Gewicht (Zehe 5 g / Knolle 40 g), roh; null = keine Zeile. Im Lauf gemerkt. */
    public function zaehlGramm(int $gpId, ?int $unitId): mixed
    {
        $k = "c:{$gpId}:{$unitId}";
        if (array_key_exists($k, $this->formen)) {
            return $this->formen[$k];
        }
        $v = DB::table('foodalchemist_gp_count_unit_defaults')->where('gp_id', $gpId)->where('unit_vocab_id', $unitId)->whereNull('deleted_at')->value('default_g');

        return $this->tiefe > 0 ? $this->formen[$k] = $v : $v;
    }

    /**
     * Alles, was der Recompute je GP-Zeile liest, für das ganze Rezept in je einer Abfrage nachladen (nur im Lauf; was schon im Vorrat
     * steht, wird nicht erneut geladen).
     *
     * @param  Collection<int, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient>  $zutaten  mit geladenem gp und unit
     */
    public function vorladen(Collection $zutaten, ?Team $team, GpAggregateService $aggregat): void
    {
        if ($this->tiefe === 0) {
            return;
        }
        $gps = $zutaten->pluck('gp')->filter()->unique('id')->values();
        $ids = $gps->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fehlend = array_values(array_filter($ids, fn ($id) => ! isset($this->las[self::key($team, $id)])));
        if ($fehlend !== []) {
            foreach ($this->ladeLas($fehlend, $team) as $id => $las) {
                $this->las[self::key($team, $id)] = $las;
            }
        }
        if ($team !== null && ($fehlend = array_values(array_filter($ids, fn ($id) => ! isset($this->prefs[self::key($team, $id)])))) !== []) {
            $overlay = LeadOverlay::fuer($team, $fehlend);
            foreach ($fehlend as $id) {
                $this->prefs[self::key($team, $id)] = $overlay[$id] ?? [];
            }
        }
        $aggregat->vorladen($gps, $team);
        $this->formenVorladen($zutaten);
    }

    /** Formen und Zähl-Gewichte der Zähl-/Löffel-Zeilen (grammFaktor T1 Zeile 3a/3b) in je einer Abfrage. */
    private function formenVorladen(Collection $zutaten): void
    {
        $zeilen = $zutaten->filter(fn ($z) => $z->gp_id !== null
            && ($z->unit?->dimension === 'count' || in_array($z->unit?->slug, GpFormService::LOEFFEL_SLUGS, true)));
        if ($zeilen->isEmpty()) {
            return;
        }
        $gpIds = $zeilen->pluck('gp_id')->unique()->values()->all();
        $formen = DB::table('foodalchemist_gp_forms')->whereIn('gp_id', $gpIds)->whereIn('form_slug', $zeilen->map(fn ($z) => $z->unit->slug)->unique()->values()->all())
            ->whereNull('deleted_at')->get(['gp_id', 'form_slug', 'gramm']);
        $zaehl = DB::table('foodalchemist_gp_count_unit_defaults')->whereIn('gp_id', $gpIds)->whereNull('deleted_at')->get(['gp_id', 'unit_vocab_id', 'default_g']);
        foreach ($zeilen as $z) {
            $this->formen["f:{$z->gp_id}:{$z->unit->slug}"] ??= $formen->first(fn ($f) => (int) $f->gp_id === (int) $z->gp_id && $f->form_slug === $z->unit->slug)?->gramm;
            $this->formen["c:{$z->gp_id}:{$z->unit_vocab_id}"] ??= $zaehl->first(fn ($c) => (int) $c->gp_id === (int) $z->gp_id && (int) $c->unit_vocab_id === (int) $z->unit_vocab_id)?->default_g;
        }
    }

    /**
     * Artikel vieler GPs inkl. Aktiv-Preis (Spec 82 3a: Preise des Rechen-Teams, Team → Vorfahren → global; ohne Team nur Listenpreis),
     * Lieferanten-Status und Rückfall auf den letzten Preis eingestellter Artikel (27.10.) — eine Abfrage.
     *
     * @param  list<int>  $gpIds
     * @return array<int, Collection>
     */
    private function ladeLas(array $gpIds, ?Team $team): array
    {
        $rows = FoodAlchemistSupplierItem::query()
            ->when($team, fn ($q) => $q->visibleToTeam($team))
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->whereIn('s.gp_id', $gpIds)->whereNull('s.deleted_at')
            ->select('foodalchemist_supplier_items.*', DB::raw(FoodAlchemistSupplier::nichtBestellbarSql() . ' AS lieferant_inaktiv'), 's.gp_id AS _preis_gp_id')
            ->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $team)->toBase(), 'aktiver_preis')
            ->pipe(fn ($q) => app(LetzterPreisService::class)->laden($q, $team))
            ->groupBy(fn ($la) => (int) $la->_preis_gp_id);
        $out = [];
        foreach ($gpIds as $id) {
            $out[$id] = $rows->get($id, collect())->values();
        }

        return $out;
    }

    private static function key(?Team $team, int $gpId): string
    {
        return ($team?->id ?? 0) . ':' . $gpId;
    }
}
