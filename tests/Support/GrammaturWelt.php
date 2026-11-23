<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * Plan 98 B: gemeinsame Welt für die Grammatur-Charakterisierung und den Umstellungsbefehl — Rezepte A–D wie in
 * Spec98GrammaturCharakterisierungTest beschrieben (A Bratkartoffeln, B Roulade, C Schmorbraten mit festem Ertrag,
 * D Eintopf mit unbepreister Komponente). Erwartet SeedsTeamHierarchy im Test.
 */
trait GrammaturWelt
{
    protected function grammaturWeltAnlegen(): void
    {
        $this->seedTeamHierarchy();
        $this->actingAs($this->makeUser($this->rootTeam));
        $t = $this->rootTeam->id;
        $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
        $this->ak = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'class_factor_pct' => 100,
            'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
        $lieferant = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Großhandel']);
        // GP mit 1-kg-LA und Preis je kg; ohne Preis → unbepreist
        $this->gp = function (string $name, ?float $eurJeKg) use ($t, $lieferant) {
            $gp = $this->makeGp($this->rootTeam, $name);
            $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lieferant->id, 'designation' => $name.' 1kg',
                'article_number' => 'A-'.$gp->id, 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
            if ($eurJeKg !== null) {
                FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => $eurJeKg, 'status' => '0']);
            }
            $gp->update(['lead_la_supplier_item_id' => $la->id]);

            return $gp->refresh();
        };
        $this->rezept = function (string $key, string $name, array $zutaten, array $attrs = []) use ($t) {
            $r = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved', 'is_sales_recipe' => true] + $attrs);
            $zeilen = [];
            foreach (array_values($zutaten) as $i => [$gp, $gramm]) {
                $zeilen[$gp->name] = $r->ingredients()->create(['team_id' => $t, 'position' => $i, 'gp_id' => $gp->id, 'raw_text' => $gp->name,
                    'quantity' => $gramm, 'unit_vocab_id' => $this->g->id, 'match_method' => 'manual']);
            }
            app(RecipeRecomputeService::class)->recomputePipeline($r->id);

            return [$r->refresh(), $zeilen];
        };
        $this->form = fn (string $code) => FoodAlchemistServierform::firstOrCreate(['team_id' => $t, 'code' => $code], ['label' => ucfirst($code)]);
        $this->darreichung = fn (FoodAlchemistRecipe $r, string $code, array $a) => FoodAlchemistRecipeDarreichung::create(['team_id' => $t, 'recipe_id' => $r->id,
            'serving_form_id' => ($this->form)($code)->id] + $a + ['unit_count' => 1, 'price_mode' => 'auto', 'markup_class_id' => $this->ak->id]);

        // A · Bratkartoffeln: Ansatz 1,2 kg = Komponentensumme, 4 Teller à 300 g
        $kartoffel = ($this->gp)('Kartoffeln', 1.20);
        $speck = ($this->gp)('Speck', 9.00);
        [$this->a, $za] = ($this->rezept)('bratkartoffeln', 'Bratkartoffeln', [[$kartoffel, 1000], [$speck, 200]]);
        $this->a->update(['yield_kg_manual' => 1.2]);
        ($this->darreichung)($this->a, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 300]);
        ($this->darreichung)($this->a, 'kinder', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 150]);
        $ohneSpeck = ($this->darreichung)($this->a, 'ohne-speck', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 250]);
        FoodAlchemistRecipeDarreichungDelta::create(['team_id' => $t, 'presentation_id' => $ohneSpeck->id, 'recipe_ingredient_id' => $za['Speck']->id, 'omitted' => true]);
        ($this->darreichung)($this->a, 'doppel', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 2]);
        ($this->darreichung)($this->a, 'wie-standard', ['is_standard' => false, 'menge_vom_standard' => true]);

        // B · Roulade: Ansatz = eine Portion, Standard aus den Komponenten
        $rind = ($this->gp)('Rinderoberschale', 22.00);
        $jus = ($this->gp)('Jus', 6.00);
        [$this->b, $zb] = ($this->rezept)('roulade', '[HG] Rinderroulade', [[$rind, 180], [$jus, 80]]);
        ($this->darreichung)($this->b, 'teller', ['is_standard' => true, 'menge_vom_standard' => true]);
        $klein = ($this->darreichung)($this->b, 'klein', ['is_standard' => false, 'menge_vom_standard' => false]);
        FoodAlchemistRecipeDarreichungDelta::create(['team_id' => $t, 'presentation_id' => $klein->id, 'recipe_ingredient_id' => $zb['Rinderoberschale']->id, 'quantity_override_g' => 120]);

        // C · Schmorbraten: fester Ertrag 0,8 kg bei 1 kg netto Komponenten
        $schulter = ($this->gp)('Rinderschulter', 14.00);
        [$this->c] = ($this->rezept)('schmorbraten', 'Schmorbraten', [[$schulter, 1000]]);
        $this->c->update(['yield_kg_manual' => 0.8]);
        ($this->darreichung)($this->c, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 200]);
        ($this->darreichung)($this->c, 'kinder', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 100]);

        // D · Eintopf mit unbepreister Komponente
        $linsen = ($this->gp)('Linsen', 3.00);
        $kraut = ($this->gp)('Kräuter', null);
        [$this->d] = ($this->rezept)('eintopf', 'Linseneintopf', [[$linsen, 900], [$kraut, 100]]);
        ($this->darreichung)($this->d, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 250]);
        ($this->darreichung)($this->d, 'kinder', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 125]);

        foreach ([$this->a, $this->b, $this->c, $this->d] as $r) {
            app(RecipeRecomputeService::class)->recomputePipeline($r->id);
            app(DarreichungService::class)->recomputeFuerRezept($r->id);
        }
    }
}
