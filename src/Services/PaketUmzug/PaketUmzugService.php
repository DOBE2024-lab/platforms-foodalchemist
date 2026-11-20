<?php

namespace Platform\FoodAlchemist\Services\PaketUmzug;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;

/**
 * Code-Qualität 2.6a: Alt-Paket (foodalchemist_packages) → kind=paket-Concept, mit Trockenlauf und Rückweg.
 *
 * - `plan()` schreibt nichts: Ziel je Paket (über uuid, auch gelöschte Kopien), Aktion (neu / wiederherstellen / aktualisieren),
 *   ob eine vorhandene Kopie veraltet ist (Inhalt alt ≠ Kopie, Änderung nach dem Stand der Kopie), Verweise und Blocker.
 * - `anwenden()` läuft in einer Transaktion und gibt ein Journal zurück: der Zustand VOR dem Lauf als Rohzeilen.
 *   Blockierte Pakete (Fassungen, Abo-/Sammlungs-Objekte, Kündigungskopien, fremde uuid, Staffeln an Alt-Slots) brechen den
 *   ganzen Lauf ab — sie gehören zu 2.6b und werden dort einzeln entschieden.
 * - `rueckweg()` stellt aus dem Journal exakt den Zustand davor wieder her (Concept, Slots, Einbettungen).
 *
 * Die Alt-Tabellen bleiben unangetastet (Shadow) — Lesen und Rückweg bleiben dadurch jederzeit möglich.
 */
final class PaketUmzugService
{
    /** Spalten Alt-Paket → Concept, die 1:1 übernommen werden (Rest: Sonderfälle in attribute()). */
    public const FELDER_GLEICH = [
        'team_id', 'name', 'consumer_name', 'class', 'level', 'offer_id', 'description', 'note',
        'calculated_price_per_person', 'price_calculation_source', 'price_calculation_version', 'price_calculated_at',
        'price_override_reason', 'price_override_user_id', 'price_override_at', 'price_override_expires_at',
        'nutrition_cache', 'work_time_min_cache',
    ];

    /** Spalten Alt-Position → Concept-Slot (1:1). */
    public const POSITION_FELDER = ['sales_recipe_id', 'quantity', 'unit_vocab_id', 'presentation_id', 'tableware_item_id', 'tableware_alt_item_id'];

    /**
     * @param  list<int>  $ids  leer = alle Alt-Pakete (ohne Fassungen)
     * @return list<array<string, mixed>>
     */
    public function plan(array $ids = []): array
    {
        return $this->pakete($ids)->map(fn (FoodAlchemistPaket $p) => $this->planZeile($p))->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array{journal: array<string, mixed>, zeilen: list<array<string, mixed>>}
     *
     * @throws \RuntimeException wenn ein ausgewähltes Paket blockiert ist (dann wird nichts geschrieben)
     */
    public function anwenden(array $ids = []): array
    {
        $plan = $this->plan($ids);
        $blockiert = array_values(array_filter($plan, fn ($z) => $z['blocker'] !== []));
        if ($blockiert !== []) {
            throw new \RuntimeException('Blockiert, nichts geschrieben: '.implode('; ', array_map(
                fn ($z) => '#'.$z['paket_id'].' '.$z['name'].' ('.implode(', ', $z['blocker']).')', $blockiert)));
        }

        $journal = ['version' => 1, 'erstellt_at' => now()->toIso8601String(), 'pakete' => []];
        DB::transaction(function () use ($plan, &$journal) {
            foreach ($plan as $zeile) {
                $journal['pakete'][] = $this->ueberfuehre(FoodAlchemistPaket::findOrFail($zeile['paket_id']), $zeile);
            }
        });

        return ['journal' => $journal, 'zeilen' => $plan];
    }

    /**
     * Stellt den Zustand vor `anwenden()` wieder her. Reihenfolge rückwärts, alles in einer Transaktion.
     *
     * @param  array<string, mixed>  $journal
     * @return int Anzahl zurückgesetzter Pakete
     */
    public function rueckweg(array $journal): int
    {
        if (($journal['version'] ?? null) !== 1) {
            throw new \RuntimeException('Unbekanntes Journal-Format.');
        }

        DB::transaction(function () use ($journal) {
            foreach (array_reverse($journal['pakete']) as $j) {
                foreach ($j['einbettungen_vorher'] as $slotId => $alt) {
                    DB::table('foodalchemist_concept_slots')->where('id', (int) $slotId)->update(['embedded_concept_id' => $alt]);
                }
                DB::table('foodalchemist_concept_slots')->whereIn('id', $j['slots_neu'])->delete();
                foreach ($j['slots_vorher'] as $row) {
                    DB::table('foodalchemist_concept_slots')->insert($row);
                }
                if ($j['concept_vorher'] === null) {
                    DB::table('foodalchemist_concepts')->where('id', $j['concept_id'])->delete();
                } else {
                    DB::table('foodalchemist_concepts')->where('id', $j['concept_id'])->update($j['concept_vorher']);
                }
            }
        });

        return count($journal['pakete']);
    }

    /** Concept zur uuid des Alt-Pakets — jede Art, auch gelöscht oder Fassung. */
    public function ziel(FoodAlchemistPaket $paket): ?FoodAlchemistConcept
    {
        return FoodAlchemistConcept::withoutGlobalScopes()->where('uuid', $paket->uuid)->first();
    }

    /**
     * Positionen des Alt-Pakets in Slot-Form, sortiert — dieselbe Form wie {@see slotPositionen()}.
     *
     * @return list<array<string, mixed>>
     */
    public function altPositionen(FoodAlchemistPaket $paket): array
    {
        return FoodAlchemistPaketGericht::withoutGlobalScopes()->where('package_id', $paket->id)->whereNull('deleted_at')
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn ($pd) => $this->position($pd))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function slotPositionen(FoodAlchemistConcept $concept): array
    {
        return FoodAlchemistConceptSlot::withoutGlobalScopes()->where('concept_id', $concept->id)->whereNull('deleted_at')
            ->whereNotNull('sales_recipe_id')->orderBy('position')->orderBy('id')->get()
            ->map(fn ($s) => $this->position($s))->values()->all();
    }

    // ── intern ────────────────────────────────────────────────────────────

    private function pakete(array $ids)
    {
        return FoodAlchemistPaket::withoutGlobalScopes()->whereNull('deleted_at')->where('ist_fassung', false)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function planZeile(FoodAlchemistPaket $p): array
    {
        $ziel = $this->ziel($p);
        $alt = $this->altPositionen($p);
        $verweise = $this->verweise($p);
        $blocker = $this->blocker($verweise, $ziel);

        $zeile = [
            'paket_id' => (int) $p->id, 'name' => (string) $p->name, 'team_id' => (int) $p->team_id, 'uuid' => (string) $p->uuid,
            'concept_id' => $ziel?->id, 'aktion' => $ziel === null ? 'neu' : ($ziel->deleted_at !== null ? 'wiederherstellen' : 'aktualisieren'),
            'positionen_alt' => count($alt), 'positionen_kopie' => null,
            'veraltet' => false, 'veraltet_gruende' => [], 'kopie_stand' => null,
            'verweise' => $verweise, 'blocker' => $blocker,
        ];
        if ($ziel === null) {
            return $zeile;
        }

        $kopie = $this->slotPositionen($ziel);
        $stand = FoodAlchemistConceptSlot::withoutGlobalScopes()->where('concept_id', $ziel->id)->max('created_at') ?? $ziel->created_at;
        $stand = $stand !== null ? Carbon::parse($stand) : null;
        $zeile['positionen_kopie'] = count($kopie);
        $zeile['kopie_stand'] = $stand?->toDateTimeString();

        $gruende = [];
        if ($alt !== $kopie) {
            $gruende[] = 'Positionen alt ≠ Kopie';
        }
        foreach ($this->spaetereAenderungen($p, $stand) as $g) {
            $gruende[] = $g;
        }
        $zeile['veraltet'] = $gruende !== [];
        $zeile['veraltet_gruende'] = $gruende;

        return $zeile;
    }

    /** @return list<string> */
    private function spaetereAenderungen(FoodAlchemistPaket $p, ?Carbon $stand): array
    {
        if ($stand === null) {
            return [];
        }
        $g = [];
        if ($p->updated_at !== null && $p->updated_at->gt($stand)) {
            $g[] = 'Paket geändert '.$p->updated_at->toDateTimeString();
        }
        $pos = DB::table('foodalchemist_package_dishes')->where('package_id', $p->id);
        $geaendert = (clone $pos)->where('updated_at', '>', $stand)->max('updated_at');
        if ($geaendert !== null) {
            $g[] = 'Position geändert '.Carbon::parse($geaendert)->toDateTimeString();
        }
        $entfernt = (clone $pos)->where('deleted_at', '>', $stand)->max('deleted_at');
        if ($entfernt !== null) {
            $g[] = 'Position entfernt '.Carbon::parse($entfernt)->toDateTimeString();
        }

        return $g;
    }

    /** @return array<string, int> Verweise auf das Alt-Paket (historische Referenzen, Rechte, Fassungen) */
    public function verweise(FoodAlchemistPaket $p): array
    {
        $id = (int) $p->id;
        $zaehle = fn (string $t, callable $w) => Schema::hasTable($t) ? (int) $w(DB::table($t))->count() : 0;

        return [
            'concept_slots' => $zaehle('foodalchemist_concept_slots', fn ($q) => $q->where('package_id', $id)->whereNull('deleted_at')),
            'speiseplan' => $zaehle('foodalchemist_menu_plan_entries', fn ($q) => $q->where('package_id', $id)),
            'bestellvorlagen' => $zaehle('foodalchemist_order_template_lines', fn ($q) => $q->where('paket_id', $id)),
            'fassungen' => $zaehle('foodalchemist_packages', fn ($q) => $q->where('fassung_von_id', $id)->where('ist_fassung', true)->whereNull('deleted_at')),
            'abo_objekte' => $zaehle('foodalchemist_abo_objekte', fn ($q) => $q->where('typ', 'paket')->where('objekt_id', $id)),
            'sammlung_objekte' => $zaehle('foodalchemist_sammlung_objekte', fn ($q) => $q->where('typ', 'paket')->where('objekt_id', $id)),
            'kuendigungskopien' => $zaehle('foodalchemist_paket_kopien', fn ($q) => $q->where('tabelle', 'foodalchemist_packages')
                ->where(fn ($w) => $w->where('quell_id', $id)->orWhere('kopie_id', $id))),
            'kopie_von' => $p->kopie_von_id !== null ? 1 : 0,
        ];
    }

    /** @return list<string> */
    private function blocker(array $verweise, ?FoodAlchemistConcept $ziel): array
    {
        $b = [];
        foreach (['speiseplan', 'bestellvorlagen', 'fassungen', 'abo_objekte', 'sammlung_objekte', 'kuendigungskopien', 'kopie_von'] as $k) {
            if (($n = $verweise[$k]) > 0) {
                $b[] = "{$k}: {$n}";
            }
        }
        if ($ziel !== null && ($ziel->kind !== 'paket' || $ziel->ist_fassung)) {
            $b[] = 'uuid belegt durch Concept #'.$ziel->id.' (kind='.$ziel->kind.($ziel->ist_fassung ? ', Fassung' : '').')';
        }
        if ($ziel !== null && Schema::hasTable('foodalchemist_concept_slot_staffel')
            && DB::table('foodalchemist_concept_slot_staffel')->whereIn('slot_id', DB::table('foodalchemist_concept_slots')->select('id')->where('concept_id', $ziel->id))->exists()) {
            $b[] = 'Staffelpreise an Slots der Kopie';
        }

        return $b;
    }

    /** @return array<string, mixed> Journal-Eintrag */
    private function ueberfuehre(FoodAlchemistPaket $pkg, array $zeile): array
    {
        $ziel = $this->ziel($pkg);
        $vorher = $ziel !== null ? (array) DB::table('foodalchemist_concepts')->where('id', $ziel->id)->first() : null;
        $slotsVorher = $ziel !== null ? DB::table('foodalchemist_concept_slots')->where('concept_id', $ziel->id)->get()->map(fn ($r) => (array) $r)->all() : [];

        $attr = $this->attribute($pkg);
        if ($ziel === null) {
            $concept = FoodAlchemistConcept::create($attr + ['uuid' => $pkg->uuid]);   // uuid-Reuse = stabiles Mapping
        } else {
            $concept = $ziel;
            if ($concept->trashed()) {
                $concept->restore();
            }
            $concept->update($attr);
        }

        DB::table('foodalchemist_concept_slots')->where('concept_id', $concept->id)->delete();
        $neu = [];
        $gerichte = FoodAlchemistPaketGericht::withoutGlobalScopes()->with('dish:id,is_sales_recipe')->where('package_id', $pkg->id)
            ->whereNull('deleted_at')->orderBy('position')->orderBy('id')->get();
        foreach ($gerichte->values() as $i => $pd) {
            $neu[] = FoodAlchemistConceptSlot::create(array_merge($pd->only(self::POSITION_FELDER), [
                'team_id' => $pkg->team_id, 'concept_id' => $concept->id,
                'type' => ($pd->dish?->is_sales_recipe ?? true) ? 'gericht' : 'basisrezept',   // wie fillSlot (1.1c): aus dem Rezept abgeleitet
                'position' => $pd->position ?? $i, 'is_pflicht' => true,
            ]))->id;
        }

        $einbettung = DB::table('foodalchemist_concept_slots')->where('package_id', $pkg->id)->pluck('embedded_concept_id', 'id')->all();
        DB::table('foodalchemist_concept_slots')->whereIn('id', array_keys($einbettung))->update(['embedded_concept_id' => $concept->id]);

        return [
            'paket_id' => (int) $pkg->id, 'concept_id' => (int) $concept->id, 'aktion' => $zeile['aktion'],
            'concept_vorher' => $vorher, 'slots_vorher' => $slotsVorher, 'slots_neu' => $neu, 'einbettungen_vorher' => $einbettung,
        ];
    }

    /** @return array<string, mixed> */
    private function attribute(FoodAlchemistPaket $p): array
    {
        $a = $p->only(self::FELDER_GLEICH);
        foreach (['kopie_von_id', 'kopie_stand_at'] as $f) {
            if ($p->{$f} !== null && Schema::hasColumn('foodalchemist_concepts', $f)) {
                $a[$f] = $p->{$f};
            }
        }

        return $a + [
            'kind' => 'paket',
            'status' => $p->is_inactive ? 'archiviert' : 'active',
            'price_mode' => in_array($p->price_mode, ['auto', 'manuell'], true) ? $p->price_mode : 'manuell',
            'price_per_person_manual' => $p->price_per_person,
            'price_per_person_cache' => $p->price_per_person,   // Anzeige vor Recompute
            'ek_per_person_cache' => $p->ek_per_person,
            'created_via' => 'paket_merge',
        ];
    }

    /** @return array<string, mixed> */
    private function position($row): array
    {
        $out = [];
        foreach (self::POSITION_FELDER as $f) {
            $v = $row->{$f};
            $out[$f] = $v === null ? null : ($f === 'quantity' ? round((float) $v, 4) : (int) $v);
        }

        return $out;
    }
}
