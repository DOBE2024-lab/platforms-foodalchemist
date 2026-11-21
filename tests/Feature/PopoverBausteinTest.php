<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/** Plan 98, 2.4a: x-fa::popover — Aufklapp-Kasten mit freiem Inhalt auf dem faMenu-Helfer. */
function popoverHtml(string $props = '', string $inhalt = 'INHALT'): string
{
    return Blade::render('<x-fa::popover ' . $props . '><x-slot:ausloeser><button type="button" x-on:click="toggle($event)">AUF</button></x-slot:ausloeser>' . $inhalt . '</x-fa::popover>');
}

it('rendert Hülle mit faMenu, Auslöser und Kasten als Dialog mit Breite 80 und Innenabstand', function () {
    $html = popoverHtml('label="Neue Bestellrunde" class="inline-block" data-test-pop=""');

    expect($html)->toMatch('/<div class="relative inline-block" data-test-pop="" x-data="faMenu\(\)"/')
        ->and($html)->toContain('AUF')
        ->and($html)->toMatch('/class="hidden w-80 fa-surface shadow-lg p-3" x-bind:class="\{ hidden: ! offen \}" x-bind:style="pos" role="dialog"\s+aria-label="Neue Bestellrunde"/')
        ->and($html)->toContain('INHALT');
});

it('rolle menu und breite 72; kasten:-Attribute gehen an den Kasten', function () {
    $html = popoverHtml('rolle="menu" breite="72" kasten:data-kasten=""');

    expect($html)->toMatch('/<div class="hidden w-72 fa-surface shadow-lg p-3" data-kasten=""/')
        ->and($html)->toContain('role="menu"')
        ->and($html)->not->toContain('aria-label=');
});

it('wirft bei ungültiger Breite oder Rolle', function () {
    expect(fn () => popoverHtml('breite="99"'))->toThrow(Exception::class, 'breite')
        ->and(fn () => popoverHtml('rolle="tooltip"'))->toThrow(Exception::class, 'rolle');
});
