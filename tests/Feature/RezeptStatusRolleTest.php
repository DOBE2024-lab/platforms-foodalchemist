<?php

use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1i (Rest): der Rezept-Status ändert sich auch über `RecipeService::update` nur mit der Rolle Kuratieren —
 * wie `setStatus` und `bulkStatus`. Ein unveränderter Status im Formular braucht keine Rolle.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Sauce: Hollandaise', ['status' => 'draft']);
});

it('update: Statuswechsel ohne Kuratieren abgelehnt, nichts gespeichert', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'gast'));
    foreach (['approved', 'review'] as $status) {
        expect(fn () => app(RecipeService::class)->update($this->rootTeam, $this->rezept->id, ['status' => $status, 'name' => 'Sauce: Neu']))
            ->toThrow(FaRechtFehltException::class);
    }
    expect($this->rezept->refresh()->status->value)->toBe('draft')->and($this->rezept->name)->toBe('Sauce: Hollandaise');
});

it('update: Statuswechsel mit Kuratieren geht durch', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    app(RecipeService::class)->update($this->rootTeam, $this->rezept->id, ['status' => 'approved']);
    expect($this->rezept->refresh()->status->value)->toBe('approved');
});

it('bulkStatus: ohne Kuratieren abgelehnt', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'gast'));
    expect(fn () => app(RecipeService::class)->bulkStatus($this->rootTeam, [$this->rezept->id], 'approved'))->toThrow(FaRechtFehltException::class);
    expect($this->rezept->refresh()->status->value)->toBe('draft');
});
