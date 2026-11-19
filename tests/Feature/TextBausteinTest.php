<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Welle 1, Paket 1.4a — x-fa::text: eine Rolle, ein Element, die Klasse aus Ui::maps()['textRolle'].
 */
it('rendert Rolle und passendes Element, Überschriften als h1–h3', function () {
    expect(Blade::render('<x-fa::text rolle="muted">leise</x-fa::text>'))
        ->toBe('<span class="fa-t-muted">leise</span>')
        ->and(Blade::render('<x-fa::text rolle="body">Fließtext</x-fa::text>'))
        ->toBe('<p class="fa-t-body">Fließtext</p>')
        ->and(Blade::render('<x-fa::text rolle="h2">Allergene</x-fa::text>'))
        ->toBe('<h2 class="fa-t-h2">Allergene</h2>');
});

it('übernimmt ein anderes Element und eigene Klassen/Attribute', function () {
    $html = Blade::render('<x-fa::text rolle="label" as="dt" class="tabular-nums" data-x="1">EK je kg</x-fa::text>');

    expect($html)->toBe('<dt class="fa-t-label tabular-nums" data-x="1">EK je kg</dt>');
});

it('lehnt unbekannte Rollen und Elemente laut ab statt still ungestylt zu rendern', function () {
    expect(fn () => Blade::render('<x-fa::text rolle="klein">x</x-fa::text>'))->toThrow(\Throwable::class, 'rolle')
        ->and(fn () => Blade::render('<x-fa::text rolle="body" as="script">x</x-fa::text>'))->toThrow(\Throwable::class, 'as');
});
