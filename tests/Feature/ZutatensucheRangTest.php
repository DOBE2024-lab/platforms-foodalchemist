<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Support\Suche;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4g (U7): „Butter“ stellt „Butter: frisch“ nach oben — vorher alphabetisch, Butteraroma und Butter-Alternativen
 * davor. Die besten Treffer stehen direkt unter dem Suchfeld (vorher nur in der linken Spalte, Suchfeld rechts).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sauce_hollandaise', 'name' => 'Sauce: Hollandaise', 'status' => 'draft',
    ]);
    // Reihenfolge des Anlegens absichtlich ungünstig; alphabetisch läge „Butter: frisch“ nicht vorn
    foreach (['Kräuterbutter: frisch', 'Butteraroma: flüssig', 'Butter-Alternative: vegan', 'Erdnuss Butter: konserviert',
        'Butter, gesalzen: frisch', 'Butter: frisch', 'Buttermilch: frisch'] as $name) {
        $this->makeGp($this->rootTeam, $name);
    }
});

it('Rang: Grundname exakt vor erstem Wort vor Anfang vor Wortanfang vor Teilwort; gefaltet', function () {
    expect(Suche::rang('Butter: frisch', 'Butter'))->toBe(0)
        ->and(Suche::rang('Butter, gesalzen: frisch', 'butter'))->toBe(1)
        ->and(Suche::rang('Butteraroma: flüssig', 'butter'))->toBe(2)
        ->and(Suche::rang('Erdnuss Butter: konserviert', 'butter'))->toBe(3)
        ->and(Suche::rang('Kräuterbutter: frisch', 'butter'))->toBe(4)
        ->and(Suche::rang('Püree: Kartoffel', 'pueree'))->toBe(0)        // Text::falte: Umlaut wie Umschrift
        ->and(Suche::rang('Pueree: Kartoffel', 'Püree'))->toBe(0)
        ->and(Suche::rang('Butter: frisch', 'butter frisch'))->toBe(0);   // ganzer Name

    $namen = Suche::nachRang(['Butteraroma: flüssig', 'Butter: TK', 'Kräuterbutter', 'Butter: frisch'], 'butter', fn ($n) => $n);
    expect($namen)->toBe(['Butter: frisch', 'Butter: TK', 'Butteraroma: flüssig', 'Kräuterbutter'])
        ->and(Suche::nachRang(['b', 'a'], '  ', fn ($n) => $n))->toBe(['b', 'a']);   // ohne Suche bleibt die Reihenfolge
});

it('Zeilen-Picker (sucheZutatenZiel): „Butter: frisch“ zuerst, auch wenn das Limit knapp ist', function () {
    $treffer = app(RecipeService::class)->sucheZutatenZiel($this->rootTeam, 'Butter', $this->rezept->id, 3);

    expect(array_column($treffer, 'name'))->toBe(['Butter: frisch', 'Butter, gesalzen: frisch', 'Butter-Alternative: vegan']);
});

it('Browser (browseKatalog): GP-Liste nach Rang, Rang je Treffer für „Beste Treffer“, ohne Suche alphabetisch ohne Rang', function () {
    $sub = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'butter_braun', 'name' => 'Butter: braun', 'status' => 'review',
    ]);
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $k = $komponente->browseKatalog([], [], 'butter');
    expect(array_column($k['gps']['items'], 'name'))->toBe([
        'Butter: frisch', 'Butter, gesalzen: frisch', 'Butter-Alternative: vegan', 'Butteraroma: flüssig', 'Buttermilch: frisch',
        'Erdnuss Butter: konserviert', 'Kräuterbutter: frisch',
    ])
        ->and($k['gps']['items'][0]['rang'])->toBe(0)
        ->and(collect($k['rezepte']['items'])->firstWhere('id', $sub->id)['rang'])->toBe(0);

    $ohne = $komponente->browseKatalog();
    expect(array_column($ohne['gps']['items'], 'rang'))->each->toBeNull()
        ->and($ohne['gps']['total'])->toBe(7);
});

it('Editor: Beste Treffer stehen unter dem Suchfeld und übernehmen per Klick', function () {
    $html = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->html();
    $suchfeld = strpos($html, 'data-browse-suche');
    $beste = strpos($html, 'data-beste-treffer=""');

    expect($beste)->toBeGreaterThan($suchfeld)
        ->and(substr($html, $suchfeld, $beste - $suchfeld))->not->toContain('data-park-zeile')   // direkt darunter, vor der Park-Zeile
        ->and($html)->toContain('x-for="ziel in besteTreffer()"')
        ->toContain('x-on:click="parke(ziel)"')
        ->toContain('data-bester-treffer=""');
});
