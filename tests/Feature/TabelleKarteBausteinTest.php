<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/** Plan 98, 2.4a PR B: x-fa::table und x-fa::card. */
it('x-fa::table rendert fa-table, compact und eigene Klassen in der bisherigen Reihenfolge', function () {
    expect(Blade::render('<x-fa::table><tbody><tr><td>A</td></tr></tbody></x-fa::table>'))
        ->toBe('<table class="fa-table"><tbody><tr><td>A</td></tr></tbody></table>')
        ->and(Blade::render('<x-fa::table compact class="min-w-[760px]" data-test-tabelle="">X</x-fa::table>'))
        ->toBe('<table class="fa-table fa-table--compact min-w-[760px]" data-test-tabelle="">X</table>');
});

it('x-fa::card rendert fa-surface vor den eigenen Klassen, as wählt das Element', function () {
    expect(trim(Blade::render('<x-fa::card class="p-4 flex" data-test-karte="">I</x-fa::card>')))
        ->toBe('<div class="fa-surface p-4 flex" data-test-karte="">I</div>')
        ->and(trim(Blade::render('<x-fa::card as="section" class="overflow-hidden">S</x-fa::card>')))
        ->toBe('<section class="fa-surface overflow-hidden">S</section>');
});

it('x-fa::card reicht blankes x-data und Alpine-Attribute unverändert durch', function () {
    $html = Blade::render('<x-fa::card as="article" x-data x-on:click="auf = true" x-bind:class="auf ? \'a\' : \'\'">K</x-fa::card>');

    expect($html)->toContain('x-data ')->not->toContain('x-data="x-data"')
        ->toContain('x-on:click="auf = true"')->toContain('x-bind:class=');
});

it('x-fa::card wirft bei klickbaren oder unbekannten Elementen', function () {
    expect(fn () => Blade::render('<x-fa::card as="button">B</x-fa::card>'))->toThrow(Exception::class, 'as="button"');
});
