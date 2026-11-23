<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B, Schritt 4 (Kurator 23.11.): Der Rezept-Kopf `sales_quantity_per_unit_g` spiegelt die Grammatur der
 * Standard-Darreichung. Vorher blieb der Kopf stehen, wenn eine Zutatenmenge sich änderte, die Darreichung rechnete neu.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->standard = fn ($r) => FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)->where('is_standard', true)->firstOrFail();
    $this->gramm = fn ($r) => app(DarreichungService::class)->grammJeEinheit(($this->standard)($r), $r->fresh());
});

it('Zutatenmenge ändern, Neuberechnung: Kopf und Standard-Darreichung zeigen denselben Wert', function () {
    // B · Roulade: Standard aus den Komponenten (180 g Rind + 80 g Jus), eine Portion
    expect((float) $this->b->fresh()->sales_quantity_per_unit_g)->toBe(260.0)
        ->and(($this->gramm)($this->b))->toBe(260.0);

    $this->b->ingredients()->where('raw_text', 'Rinderoberschale')->firstOrFail()->update(['quantity' => 200]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->b->id);

    expect(($this->gramm)($this->b))->toBe(280.0)
        ->and((float) $this->b->fresh()->sales_quantity_per_unit_g)->toBe(280.0);
});

it('ein abweichender Kopf wird bei der nächsten Neuberechnung nachgezogen; Varianten schreiben ihn nicht', function () {
    // A · Bratkartoffeln: Standard-Teller 300 g, Kinder-Variante 150 g
    $this->a->update(['sales_quantity_per_unit_g' => 999]);
    app(DarreichungService::class)->recomputeFuerRezept($this->a->id);

    expect((float) $this->a->fresh()->sales_quantity_per_unit_g)->toBe(300.0)
        ->and(($this->gramm)($this->a))->toBe(300.0);
});
