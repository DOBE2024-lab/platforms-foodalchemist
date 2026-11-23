<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4c Nachtrag (Charge 4): Der Basisrezept-Editor zeigt „Rezept-Name ist Pflicht (§1).“ am Feld — rote Linie,
 * aria-invalid, aria-describedby auf die Meldung — wie der Anlege-Dialog (#946), nicht mehr nur oben im Hinweis.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('Anlegen mit leerem Namen markiert das Feld und legt nichts an', function () {
    $lw = Livewire::test(RecipeModal::class)->call('oeffnen');
    expect($lw->html())->not->toContain('aria-invalid');

    $lw->set('form.name', '   ')->call('speichern')
        ->assertSet('nameFehler', 'Rezept-Name ist Pflicht (§1).')
        ->assertSet('fehler', null)
        ->assertNotDispatched('recipe-gespeichert');

    expect(FoodAlchemistRecipe::count())->toBe(0)
        ->and($lw->html())->toMatch('/<input[^>]*id="rezept-name"[^>]*aria-invalid="true"[^>]*aria-describedby="rezept-name-fehler"/')
        ->toContain('<p id="rezept-name-fehler"')
        ->toContain('Rezept-Name ist Pflicht (§1).')
        ->not->toContain('data-modal-fehler');   // nicht doppelt oben im Hinweis
});

it('ein eingetippter Name nimmt die Markierung sofort zurück, danach wird angelegt', function () {
    $lw = Livewire::test(RecipeModal::class)->call('oeffnen')->call('speichern')
        ->assertSet('nameFehler', 'Rezept-Name ist Pflicht (§1).');

    $lw->set('form.name', 'Fond: Feldfehler')->assertSet('nameFehler', null);
    expect($lw->html())->not->toContain('aria-invalid')->not->toContain('rezept-name-fehler');

    $lw->call('speichern')->assertSet('nameFehler', null)->assertDispatched('recipe-gespeichert');
    expect(FoodAlchemistRecipe::where('name', 'Fond: Feldfehler')->exists())->toBeTrue();
});

it('Bearbeiten: Name geleert wird am Feld abgewiesen, der gespeicherte Name bleibt', function () {
    $r = app(RecipeService::class)->create($this->rootTeam, ['name' => 'Fond: Bestand']);

    $lw = Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)->call('bearbeitenStarten')
        ->set('form.name', '')->call('speichern')
        ->assertSet('nameFehler', 'Rezept-Name ist Pflicht (§1).');

    expect($r->fresh()->name)->toBe('Fond: Bestand');

    $lw->call('oeffnen', $r->id)->assertSet('nameFehler', null);   // neues Laden räumt die Markierung ab
});
