<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Tests\Support\LesemodusDom;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · Lesemodus (Spec 65) in den Einstellungen: Anzeige bleibt ohne „Bearbeiten“ bedienbar, Design-Daten nicht.
 * Geprüft im gerenderten DOM (Vorfahr fieldset[disabled]) und am Server-Guard.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Anna'));
});

it('Präsentations-Designs: „EK-Ansicht im Link“ im Lesemodus gesperrt (DOM) und serverseitig zurückgesetzt; Vorschau-Steuerung frei', function () {
    $c = Livewire::test(PraesentationsDesigns::class)->assertSeeHtml('data-fa-lesemodus="1"');
    $html = $c->html();

    expect(LesemodusDom::gesperrt($html, '//input[@data-fa-vorschau-ek]'))->toBe([true])
        ->and(LesemodusDom::gesperrt($html, '//select[@id="design-vorschau-quelle"]'))->toBe([false]);

    // wire:model umgeht den call-Haken — der Hook setzt ohne Sperre zurück, auch „ungespeichert“
    $c->set('tokens.ek_im_link', true)
        ->assertSet('tokens.ek_im_link', false)
        ->assertSet('ungespeichert', false);

    $c->call('bearbeitenStarten')->assertSeeHtml('data-fa-lesemodus="0"')
        ->set('tokens.ek_im_link', true)
        ->assertSet('tokens.ek_im_link', true)
        ->assertSet('ungespeichert', true);
    expect(LesemodusDom::gesperrt($c->html(), '//input[@data-fa-vorschau-ek]'))->toBe([false]);
});
