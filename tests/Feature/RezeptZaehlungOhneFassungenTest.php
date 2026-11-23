<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Dashboard;
use Platform\FoodAlchemist\Services\Plattform\KpiService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1g (B15) · Rohe Rezeptzählungen zählten Fassungen (Spec 82, eingefrorene Stände) als eigene Rezepte mit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $fond = $this->makeRecipe($this->rootTeam, 'Kalbsfond', ['status' => 'approved']);
    $this->makeRecipe($this->rootTeam, 'Kalbsfond', ['status' => 'approved', 'ist_fassung' => true, 'fassung_von_id' => $fond->id, 'fassung_nr' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Kalbsrücken', ['is_sales_recipe' => true, 'status' => 'draft']);
    $this->makeRecipe($this->rootTeam, 'Kalbsrücken', ['is_sales_recipe' => true, 'status' => 'draft', 'ist_fassung' => true, 'fassung_von_id' => $gericht->id, 'fassung_nr' => 1]);
});

it('KpiService zählt Rezepte ohne Fassungen', function () {
    expect(app(KpiService::class)->forTeam($this->rootTeam)['rezepte'])->toBe(2);
});

it('Dashboard: Workflow-Zähler ohne Fassungen', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    Livewire::test(Dashboard::class)->assertViewHas('workflow', fn (array $w) => $w['basis'] === 1 && $w['vk'] === 1
        && $w['approved'] === 1 && $w['draft'] === 1);
});
