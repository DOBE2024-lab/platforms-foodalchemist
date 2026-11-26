<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Services\Concepter\AufbauKandidatenService;
use Platform\FoodAlchemist\Services\Rezept\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1f (U2, E1 19.11.2026) · Gericht-Picker im Concepter: Entwürfe wählbar (gekennzeichnet), Platzhalter und Veraltet nicht;
 * der Hauptgruppen-Chip zählt mit derselben Regel wie die Liste (Chip = Listenlänge).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->hg = FoodAlchemistDishMainGroup::create(['code' => 'TEL', 'label' => 'Tellergericht']);
    foreach (['approved' => 'Freigegeben', 'review' => 'Prüfen', 'draft' => 'Entwurf', 'stub' => 'Platzhalter', 'deprecated' => 'Veraltet'] as $s => $n) {
        $this->makeRecipe($this->rootTeam, "Gericht {$n}", ['is_sales_recipe' => true, 'status' => $s, 'dish_main_group_id' => $this->hg->id]);
        $this->makeRecipe($this->rootTeam, "Basis {$n}", ['status' => $s]);
    }
});

it('Gericht-Picker: Entwurf und Prüfen wählbar, Platzhalter und Veraltet nicht; Status für die Kennzeichnung geladen', function () {
    $liste = app(AufbauKandidatenService::class)->gerichtKandidaten($this->rootTeam, '', ['hauptgruppe' => $this->hg->id]);
    expect($liste->pluck('name')->all())->toEqualCanonicalizing(['Gericht Freigegeben', 'Gericht Prüfen', 'Gericht Entwurf'])
        ->and($liste->firstWhere('name', 'Gericht Entwurf')->status->label())->toBe('Entwurf');
});

it('Basis-Picker (Concepter): dieselbe Regel', function () {
    expect(app(AufbauKandidatenService::class)->basisKandidaten($this->rootTeam, 'Basis')->pluck('name')->all())
        ->toEqualCanonicalizing(['Basis Freigegeben', 'Basis Prüfen', 'Basis Entwurf']);
});

it('Chip-Zähler = Listenlänge (Picker-Sicht), auch mit weiterem Filter', function () {
    $sales = app(SalesRecipeService::class);
    $liste = app(AufbauKandidatenService::class)->gerichtKandidaten($this->rootTeam, '', ['hauptgruppe' => $this->hg->id]);
    expect($sales->hauptgruppenCounts($this->rootTeam, ['waehlbar' => true])[$this->hg->id])->toBe($liste->count());

    $filter = ['hauptgruppe' => null, 'class' => null, 'geschmack' => 'herzhaft', 'diet_form' => ''];
    $this->makeRecipe($this->rootTeam, 'Gericht Herzhaft', ['is_sales_recipe' => true, 'status' => 'draft', 'dish_main_group_id' => $this->hg->id, 'taste_direction' => 'herzhaft']);
    expect($sales->hauptgruppenCounts($this->rootTeam, ['waehlbar' => true] + $filter)[$this->hg->id])
        ->toBe(app(AufbauKandidatenService::class)->gerichtKandidaten($this->rootTeam, '', ['hauptgruppe' => $this->hg->id] + $filter)->count());
});
