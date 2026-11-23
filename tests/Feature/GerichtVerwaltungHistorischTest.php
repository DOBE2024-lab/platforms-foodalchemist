<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Verwaltung (Plan „Verwaltung am Gericht“, Kurator/Dominique 23.11.): Ein Gericht ist nach dem Tausch weich
 * löschbar. Historische Verweise (vergangene Speiseplan-Tage, versendete Angebote, inaktive Foodbooks, abgeschlossene
 * Produktion …) behalten es: Die sechs Verweis-Relationen laden weich gelöschte Gerichte mit (withTrashed), damit die
 * Ansichten den Namen zeigen statt „—“ oder eines leeren Felds.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rinderroulade', ['is_sales_recipe' => true]);
    $this->gericht->delete();   // weich
});

it('die sechs Verweis-Relationen lösen ein weich gelöschtes Gericht weiter auf', function (string $klasse, string $fk, string $relation) {
    $zeile = new $klasse([$fk => $this->gericht->id]);

    expect($zeile->{$relation})->not->toBeNull()
        ->and($zeile->{$relation}->name)->toBe('Rinderroulade')
        ->and($zeile->{$relation}->trashed())->toBeTrue();
})->with([
    'Konzept-Position' => [FoodAlchemistConceptSlot::class, 'sales_recipe_id', 'dish'],
    'Angebots-Block' => [FoodAlchemistOfferBlock::class, 'sales_recipe_id', 'dish'],
    'Foodbook-Block' => [FoodAlchemistFoodbookBlock::class, 'sales_recipe_id', 'dish'],
    'Speiseplan-Eintrag' => [FoodAlchemistSpeiseplanEintrag::class, 'sales_recipe_id', 'dish'],
    'Speisekarten-Position' => [FoodAlchemistSpeisekartePosition::class, 'sales_recipe_id', 'dish'],
    'Produktions-Zeile' => [FoodAlchemistProductionOrderLine::class, 'recipe_id', 'recipe'],
]);

it('der Speiseplan-Eintrag zeigt den Namen statt „—“', function () {
    expect((new FoodAlchemistSpeiseplanEintrag(['sales_recipe_id' => $this->gericht->id]))->inhaltName())->toBe('Rinderroulade');
});

it('ein hart fehlendes Gericht bleibt null (kein Platzhalter-Objekt)', function () {
    expect((new FoodAlchemistOfferBlock(['sales_recipe_id' => 999999]))->dish)->toBeNull();
});
