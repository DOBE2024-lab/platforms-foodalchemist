<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabRolle;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10-01: Concepter-Fundament — Schema + Model-Relationen + Team-Hierarchie.
 * Baut das DOEC-„Grill-Buffet"-Beispiel aus Doc 15 §M10 als Fixture.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Zwei Gerichte (VK-Rezepte) für den Vorspeisen-Paket
    $this->greenPower = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'gp1', 'name' => 'Salat: Green Power',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $this->sunnyKick = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sk1', 'name' => 'Salat: Sunny Kick',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
});

it('baut ein Concept mit Slots: Paket ODER festes Gericht je Slot (Grill-Buffet)', function () {
    // 98 · 2.6c: Paket = kind=paket-Concept, eingebettet über embedded_concept_id
    $saladWall = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Salad Wall', 'kind' => 'paket', 'status' => 'active']);

    $concept = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'occasion' => 'Sommerfest', 'status' => 'draft',
    ]);
    // Slot A: gefüllt mit Paket (austauschbar)
    $concept->slots()->create([
        'team_id' => $this->rootTeam->id, 'role' => 'Vorspeise', 'title' => 'Vorspeise',
        'position' => 0, 'type' => 'paket', 'embedded_concept_id' => $saladWall->id,
    ]);
    // Slot B: festes Gericht
    $concept->slots()->create([
        'team_id' => $this->rootTeam->id, 'role' => 'Dessert', 'title' => 'Dessert',
        'position' => 1, 'recipe_id' => $this->greenPower->id, 'quantity' => 1,
    ]);

    $slots = $concept->slots()->get();
    expect($slots)->toHaveCount(2)
        ->and($slots[0]->embeddedConcept->name)->toBe('Salad Wall')
        ->and($slots[1]->embeddedConcept)->toBeNull()
        ->and($slots[1]->dish->name)->toBe('Salat: Green Power');
});

it('Vorlage-Scopes + Rollen-Vokabular', function () {
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => '3-Gang-Vorlage', 'is_template' => true]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'is_template' => false]);

    expect(FoodAlchemistConcept::vorlagen()->count())->toBe(1)
        ->and(FoodAlchemistConcept::echte()->count())->toBe(1);

    $rolle = FoodAlchemistVocabRolle::create(['team_id' => $this->rootTeam->id, 'slug' => 'vorspeise', 'name' => 'Vorspeise']);
    expect($rolle->uuid)->not->toBeNull();
});
