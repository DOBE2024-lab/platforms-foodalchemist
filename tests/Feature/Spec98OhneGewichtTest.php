<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Trockenlauf io 23.11. (R149), Kurator-Entscheid (c): Eine Zutat mit Menge, aber ohne Gramm (1 Stück Ei ohne Stückgewicht) fällt im
 * Komponentenmodell der Darreichung nicht mehr still heraus. Sie läuft über ihre Kosten bzw. ihren Rezept-Anteil mit, wird von
 * „eigene Menge“ und „Anteil“ nicht weggelassen und erscheint in der Produktion — gemeldet als `ohne_gewicht`.
 *
 * Linsensalat, eine Portion: 200 g Linsen à 4 €/kg = 0,80 €, 1 Ei à 0,30 € (10er-Karton 3,00 €, kein Stückgewicht) ⇒ EK 1,10 €.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $t = $this->rootTeam->id;
    $stk = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $ei = $this->makeGp($this->rootTeam, 'Ei');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Eierhof']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lieferant->id, 'designation' => 'Eier 10 Stk',
        'article_number' => 'EI-10', 'qty' => 10.0, 'unit_code' => 'Stk', 'packaging_unit' => 'Karton']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $ei->id]);
    FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => 3.00, 'status' => '0']);
    $ei->update(['lead_la_supplier_item_id' => $la->id, 'piece_default_g' => null]);

    [$this->salat] = ($this->rezept)('linsensalat-ei', 'Linsensalat | Ei', [[($this->gp)('Belugalinsen', 4.00), 200]]);
    $this->ei = $this->salat->ingredients()->create(['team_id' => $t, 'position' => 9, 'gp_id' => $ei->id, 'raw_text' => 'Ei', 'quantity' => 1,
        'unit_vocab_id' => $stk->id, 'match_method' => 'manual']);
    app(RecipeRecomputeService::class)->recomputePipeline($this->salat->id);
    $this->std = ($this->darreichung)($this->salat, 'teller', ['is_standard' => true, 'menge_vom_standard' => true]);
    $this->svc = app(DarreichungService::class);
    $this->svc->recomputeFuerRezept($this->salat->id);
});

it('Zeile ohne Gewicht ist markiert; Standard „Summe der Bestandteile“ rechnet das Ei im EK mit', function () {
    $zeilen = app(RecipeRecomputeService::class)->zeilenKostenUndMassen($this->salat->fresh(), $this->rootTeam);
    expect($zeilen[$this->ei->id]['ohne_gewicht'])->toBeTrue()
        ->and($zeilen[$this->ei->id]['masse_g'])->toBe(0.0)
        ->and(round((float) $zeilen[$this->ei->id]['kosten'], 4))->toBe(0.3);

    $k = $this->svc->komponentenSumme($this->std->fresh(), $this->salat->fresh(), $this->rootTeam);
    expect($k['ohne_gewicht'])->toBe([$this->ei->id])->and($k['vorlaeufig'])->toBeFalse()->and($k['fehlende'])->toBe([])
        ->and((float) $this->std->fresh()->ek_portion)->toBe(1.1)     // vorher 0,80 € — das Ei fehlte still
        ->and((float) $this->std->fresh()->quantity_per_unit_g)->toBe(200.0);   // Grammatur: nur was Gewicht hat
});

it('Variante: „eigene Menge“ und „Anteil“ lassen das Ei nicht weg, es läuft im Verhältnis der Variante mit', function () {
    $v = $this->svc->anlegen($this->rootTeam, $this->salat->id, ($this->form)('klein')->id);
    $this->svc->aktualisieren($this->rootTeam, $v->id, ['menge_vom_standard' => false]);   // fixiereKomposition
    expect(FoodAlchemistRecipeDarreichungDelta::where('presentation_id', $v->id)->where('recipe_ingredient_id', $this->ei->id)->exists())->toBeFalse()
        ->and((float) $v->fresh()->ek_portion)->toBe(1.1);

    $v = $this->svc->setzeAnteil($this->rootTeam, $v->id, 50);
    $k = $this->svc->komponentenSumme($v, $this->salat->fresh(), $this->rootTeam);
    expect(FoodAlchemistRecipeDarreichungDelta::where('presentation_id', $v->id)->where('recipe_ingredient_id', $this->ei->id)->exists())->toBeFalse()
        ->and((float) $v->ek_portion)->toBe(0.55)   // 100 g Linsen 0,40 € + ½ Ei 0,15 €
        ->and($k['vorlaeufig'])->toBeTrue();         // Verhältnis der Variante, keine eigene Menge fürs Ei
});

it('Produktion: das Ei erscheint mit seinem Anteil statt 0, gemeldet als ohne_gewicht', function () {
    $std = $this->svc->produktionsAnteil($this->salat->fresh(), $this->std->fresh(), 10, $this->rootTeam);
    expect($std['je_zutat'][$this->ei->id])->toBe(10.0)          // 10 Portionen × 1 Ansatz je Portion
        ->and($std['ohne_gewicht'])->toBe([$this->ei->id])
        ->and($std['warnung'])->toContain('ohne Gewicht');

    $v = $this->svc->anlegen($this->rootTeam, $this->salat->id, ($this->form)('klein')->id);
    $v = $this->svc->setzeAnteil($this->rootTeam, $v->id, 50);
    $klein = $this->svc->produktionsAnteil($this->salat->fresh(), $v, 10, $this->rootTeam);
    expect($klein['je_zutat'][$this->ei->id])->toBe(5.0)->and($klein['warnung'])->toContain('vorläufig');
});
