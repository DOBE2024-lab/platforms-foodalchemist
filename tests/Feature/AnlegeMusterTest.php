<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser as ConcepterBrowser;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4c (U3): ein Anlege-Muster — erst der Dialog mit dem Namen, dann der Datensatz.
 * „Neu“ legt nichts mehr an; Abbrechen hinterlässt keine „Neues …“-Leiche; leerer Name wird abgewiesen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Foodbook: neu öffnet nur den Dialog, leerer Name abgewiesen, mit Namen angelegt und ausgewählt', function () {
    $c = Livewire::test(FoodbooksIndex::class)->assertSeeHtml('data-anlege-dialog')
        ->call('neu')->assertDispatched('modal.open', name: 'fa-anlegen');
    expect(FoodAlchemistFoodbook::count())->toBe(0);

    $c->set('neuName', '   ')->call('neuAnlegen')->assertSet('neuFehler', 'Bitte einen Namen eingeben.')
        ->assertNotDispatched('modal.close');
    expect(FoodAlchemistFoodbook::count())->toBe(0);

    $c->set('neuName', ' Sommerfest Adler ')->call('neuAnlegen')
        ->assertDispatched('modal.close', name: 'fa-anlegen')->assertSet('neuFehler', null)->assertSet('neuName', '');
    $fb = FoodAlchemistFoodbook::sole();
    expect($fb->label)->toBe('Sommerfest Adler')
        ->and($c->get('selectedId'))->toBe($fb->id);
});

it('Concepter: Concept und Paket erst mit Namen, danach Editor offen; kein „Neues …“ mehr', function () {
    Livewire::test(ConcepterBrowser::class)->assertSeeHtml('data-anlege-dialog')
        ->call('neu')->assertNotDispatched('concepter-editor.oeffnen')
        ->set('neuName', 'Hochzeit Klein')->call('neuAnlegen')
        ->assertDispatched('concepter-editor.oeffnen');
    Livewire::test(ConcepterBrowser::class)->set('tab', 'pakete')
        ->call('neu')->set('neuName', 'Grillpaket')->call('neuAnlegen');

    expect(FoodAlchemistConcept::where('name', 'Hochzeit Klein')->exists())->toBeTrue()
        ->and(FoodAlchemistConcept::pakete()->where('name', 'Grillpaket')->exists())->toBeTrue()
        ->and(FoodAlchemistConcept::where('name', 'like', 'Neues %')->exists())->toBeFalse();
});

it('x-fa::anlege-dialog: Modal fa-anlegen mit Namensfeld, Enter legt an, Fehler sichtbar', function () {
    $html = Blade::render('<x-fa::anlege-dialog title="Foodbook anlegen" knopf="Foodbook anlegen" fehler="Bitte einen Namen eingeben." />');

    expect($html)->toContain('fa-anlegen')
        ->toContain('wire:model="neuName"')
        ->toContain('wire:keydown.enter="neuAnlegen"')
        ->toContain('wire:click="neuAnlegen"')
        ->toContain('Foodbook anlegen')
        ->toContain('Bitte einen Namen eingeben.');
});

it('am Handy kein Anlege-Dialog im DOM (dort wird nicht angelegt)', function () {
    foreach ([ConcepterBrowser::class, FoodbooksIndex::class, \Platform\FoodAlchemist\Livewire\Formate\Browser::class] as $klasse) {
        Livewire::withCookie(\Platform\FoodAlchemist\Support\HandyLesemodus::COOKIE, '1')->test($klasse)->assertDontSeeHtml('data-anlege-dialog');
        Livewire::withCookie(\Platform\FoodAlchemist\Support\HandyLesemodus::COOKIE, '0')->test($klasse)->assertSeeHtml('data-anlege-dialog');
    }
});
