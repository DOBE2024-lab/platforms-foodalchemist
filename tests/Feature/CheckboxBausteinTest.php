<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\UiKatalog;
use Platform\FoodAlchemist\Support\Ui;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Welle 1, Paket 1.4c — x-fa::checkbox: eine Haken-Optik aus Ui::maps()['haken'],
 * Bindung und Daten am <input>, Layout am <label>.
 */
function checkboxHtml(string $blade): string
{
    return preg_replace('/\s+/', ' ', trim(Blade::render($blade)));
}

it('rendert Label mit Haken in der einen Optik und gibt die Bindung an das input', function () {
    $html = checkboxHtml('<x-fa::checkbox wire:model.live="nurAktive" data-filter-aktiv>Nur aktive</x-fa::checkbox>');

    expect($html)->toContain('<label class="inline-flex gap-2 items-center cursor-pointer">')
        ->and($html)->toContain('<input type="checkbox" class="'.Ui::maps()['haken'].'" wire:model.live="nurAktive" data-filter-aktiv')
        ->and($html)->toMatch('/data-filter-aktiv(="data-filter-aktiv")?> Nur aktive <\/label>/');
});

it('legt class, wire:key, x-show, x-cloak und title an das label, den Rest an das input', function () {
    $html = checkboxHtml('<x-fa::checkbox class="text-sm" wire:key="k-1" x-show="offen" title="Hilfe" value="7" name="ids[]" disabled>Sieben</x-fa::checkbox>');

    expect($html)->toMatch('/<label class="inline-flex gap-2 items-center cursor-pointer text-sm" wire:key="k-1" x-show="offen" title="Hilfe">/')
        ->and($html)->toMatch('/<input type="checkbox" class="[^"]+" value="7" name="ids\[\]" disabled(="disabled")?>/');
});

it('setzt Layout-Standard nur, wo das label keine eigenen Layout-Klassen mitbringt', function () {
    $html = checkboxHtml('<x-fa::checkbox class="flex items-start gap-1.5 h-9">Zeile</x-fa::checkbox>');

    expect($html)->toContain('<label class="cursor-pointer flex items-start gap-1.5 h-9">')
        ->and($html)->toContain(Ui::maps()['haken'].' mt-0.5');
});

it('lässt ein eigenes Display ohne items-* stehen und setzt den Haken oben an', function () {
    $html = checkboxHtml('<x-fa::checkbox class="fa-surface p-2 flex gap-2 min-w-0" value="4">Karte</x-fa::checkbox>');

    expect($html)->toContain('<label class="cursor-pointer fa-surface p-2 flex gap-2 min-w-0">')
        ->and($html)->toContain(Ui::maps()['haken'].' mt-0.5');
});

it('setzt mit Hinweis den Haken oben an und zeigt den Hinweis als muted', function () {
    $html = checkboxHtml('<x-fa::checkbox wire:model="bio" hinweis="nur zertifiziert">Bio</x-fa::checkbox>');

    expect($html)->toContain('<label class="inline-flex gap-2 items-start cursor-pointer">')
        ->and($html)->toContain(Ui::maps()['haken'].' mt-0.5')
        ->and($html)->toContain('<span class="fa-t-muted">nur zertifiziert</span>');
});

it('ohne Text: nacktes input mit allen Attributen, aber nur mit Beschriftung für Screenreader', function () {
    $html = checkboxHtml('<x-fa::checkbox wire:model="auswahl" value="3" aria-label="Zeile auswählen" class="ml-1" />');

    expect($html)->toBe('<input type="checkbox" class="'.Ui::maps()['haken'].' ml-1" wire:model="auswahl" value="3" aria-label="Zeile auswählen">')
        ->and(fn () => Blade::render('<x-fa::checkbox wire:model="auswahl" />'))->toThrow(\Exception::class, 'aria-label');
});

it('steht im UI-Katalog und bindet dort an eine echte Property', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Checkbox Baustein TEST'));

    Livewire::test(UiKatalog::class)
        ->assertSeeHtml('data-katalog-checkbox')
        ->assertSeeHtml('wire:model.live="nurAktive"')
        ->set('nurAktive', false)
        ->assertSet('nurAktive', false);
});
