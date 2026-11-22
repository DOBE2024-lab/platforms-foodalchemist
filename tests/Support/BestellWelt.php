<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * 98 · 3.3 (S5): gemeinsame Bestell-Fixture für Charakterisierung und MCP-Rauchtests — dieselbe Welt wie OrderServiceTest:
 * Kuchen (10 Portionen/Batch) = 1000 g Mehl (Chefs) + 150 g Vanillesauce; Vanillesauce (1000 g) = 500 g Zucker (Chefs)
 * + 500 g Butter (Hanos). Alle LAs 1-kg-Gebinde: Mehl 2 €, Zucker 1 €, Butter 12 €. Ausweich-Mehl bei Hanos (1,80 €) per ausweichMehl().
 * Bei 100 Portionen: Mehl 10 kg, Zucker 1 kg, Butter 1 kg → Chefs 21 €, Hanos 12 €.
 *
 * Erwartet SeedsTeamHierarchy im Test (rootTeam, makeGp).
 */
trait BestellWelt
{
    protected function bestellWeltAnlegen(): void
    {
        $this->seedTeamHierarchy();
        $t = $this->rootTeam->id;
        $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

        $this->laOf = [];
        $la = function (string $name, $gp, string $lieferant, float $preis, string $artikel) use ($t) {
            $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $t, 'name' => $lieferant]);
            $item = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $supplier->id, 'designation' => $name.' 1kg',
                'article_number' => $artikel, 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
            FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $item->id, 'price' => $preis, 'status' => '0']);

            return $item;
        };
        $gp = function (string $name, float $preis, string $lieferant) use ($la) {
            $g = $this->makeGp($this->rootTeam, $name);
            $this->laOf[$name] = $la($name, $g, $lieferant, $preis, 'ART-'.strtoupper(substr($name, 0, 3)));
            $g->update(['lead_la_supplier_item_id' => $this->laOf[$name]->id]);

            return $g->refresh();
        };

        $this->mehl = $gp('Mehl', 2.00, 'Chefs');
        $this->zucker = $gp('Zucker', 1.00, 'Chefs');
        $this->butter = $gp('Butter', 12.00, 'Hanos');
        $this->chefs = FoodAlchemistSupplier::where('team_id', $t)->where('name', 'Chefs')->first();
        $this->hanos = FoodAlchemistSupplier::where('team_id', $t)->where('name', 'Hanos')->first();

        $this->sauce = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'vanillesauce', 'name' => 'Vanillesauce',
            'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0]);
        $this->sauce->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->zucker->id, 'raw_text' => 'Zucker', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);
        $this->sauce->ingredients()->create(['team_id' => $t, 'position' => 1, 'gp_id' => $this->butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);
        $this->kuchen = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen',
            'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 10]);
        $this->kuchen->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id]);
        $this->kuchen->ingredients()->create(['team_id' => $t, 'position' => 1, 'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 150, 'unit_vocab_id' => $this->g->id]);
        $this->ausweichLa = $la;
        $rc = app(RecipeRecomputeService::class);
        $rc->recomputePipeline($this->sauce->id);
        $rc->recomputePipeline($this->kuchen->id);
    }

    /**
     * Ausweich-Mehl bei Hanos (1,80 €), erst auf Abruf: Mit ihm zieht die Standard-Preisstrategie den Mehlbedarf zu Hanos
     * (heutiges Verhalten), deshalb nicht in der Grund-Welt.
     */
    protected function ausweichMehl()
    {
        return $this->mehlHanos ??= ($this->ausweichLa)('Mehl Hanos', $this->mehl, 'Hanos', 1.80, 'H-MEHL');
    }

    /** Normalisierter Zustand aller Bestellungen des Teams (ohne Ids/Zeitstempel), für Golden-Vergleiche. */
    protected function bestellZustand(): array
    {
        $zahl = fn ($v, int $d = 2) => $v === null ? null : round((float) $v, $d);

        return FoodAlchemistOrder::where('team_id', $this->rootTeam->id)->with(['supplier', 'lines'])->get()
            ->map(fn ($o) => [
                'lieferant' => $o->supplier?->name,
                'status' => $o->status instanceof \BackedEnum ? $o->status->value : $o->status,
                'liefertag' => $o->desired_delivery_date?->toDateString(),
                'referenz' => $o->reference,
                'total_net' => $zahl($o->total_net),
                'zeilen' => $o->lines->sortBy('designation')->values()->map(fn ($l) => [
                    'bezeichnung' => $l->designation, 'artikel' => $l->article_number,
                    'gebinde' => $zahl($l->qty_packs), 'manuell' => (bool) $l->is_manual_qty,
                    'preis' => $zahl($l->pack_price, 4), 'summe' => $zahl($l->line_total), 'bedarf_g' => $zahl($l->needed_base_g),
                    'quellen' => collect((array) $l->source_contributions)->map(fn ($g) => $zahl($g))->sortKeys()->all(),
                    'eingang' => $zahl($l->received_qty_packs), 'rechnung' => [$zahl($l->invoice_qty_packs), $zahl($l->invoice_pack_price, 4)],
                    'reklamation' => [$l->claim_status, $zahl($l->claim_qty_packs), $zahl($l->credit_expected_net), $l->claim_note],
                    'notiz' => $l->note,
                ])->all(),
            ])
            ->sortBy(fn ($o) => $o['lieferant'].'|'.$o['liefertag'].'|'.$o['referenz'])->values()->all();
    }
}
