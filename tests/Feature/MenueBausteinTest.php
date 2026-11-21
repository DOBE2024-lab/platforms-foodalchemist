<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\UiKatalog;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Welle 1, Paket 1.4c — x-fa::menu: Hülle mit faMenu-Helfer, Standard-Auslöser „…“, Menükasten mit Breiten-Stufe;
 * x-fa::menu-item schließt beim Klick (Ausweg :schliessen="false").
 */
function menueHtml(string $blade): string
{
    return preg_replace('/\s+/', ' ', trim(Blade::render($blade)));
}

it('baut Hülle, Standard-Auslöser und Menükasten auf dem faMenu-Helfer', function () {
    $html = menueHtml('<x-fa::menu wire:key="m-1" class="inline-block"><x-fa::menu-item>Duplizieren</x-fa::menu-item></x-fa::menu>');

    expect($html)->toContain('<div class="relative inline-block" wire:key="m-1" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">')
        ->and($html)->toMatch('/<button type="button" aria-label="Weitere Aktionen" title="Weitere Aktionen" class="[^"]*w-9 h-9[^"]*" x-on:click="toggle\(\$event\)" aria-haspopup="menu" x-bind:aria-expanded="offen">/')
        ->and($html)->toContain('<div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">');
});

it('nimmt Breite, Scroll, Größe und die Präfixe ausloeser: und menue:', function () {
    $html = menueHtml('<x-fa::menu label="Status ändern" size="sm" breite="72" scroll ausloeser:data-status-knopf menue:data-status-liste>x</x-fa::menu>');

    expect($html)->toMatch('/aria-label="Status ändern"[^>]*class="[^"]*w-7 h-7[^"]*"[^>]*data-status-knopf/')
        ->and($html)->toContain('<div class="hidden w-72 max-h-80 overflow-y-auto fa-surface shadow-lg py-1" data-status-liste')
        ->and($html)->not->toContain('ausloeser:')
        ->and($html)->not->toContain('menue:');
});

it('lässt einen eigenen Auslöser im Slot zu', function () {
    $html = menueHtml('<x-fa::menu>
        <x-slot:ausloeser><x-fa::button x-on:click="toggle($event)" aria-haspopup="menu">Neu</x-fa::button></x-slot:ausloeser>
        <x-fa::menu-item>Rezept</x-fa::menu-item>
    </x-fa::menu>');

    expect($html)->toContain('x-on:click="toggle($event)" aria-haspopup="menu"> Neu </button>')
        ->and($html)->not->toContain('aria-label="Weitere Aktionen"');
});

it('lehnt eine Breite außerhalb der Stufen ab', function () {
    expect(fn () => Blade::render('<x-fa::menu breite="50">x</x-fa::menu>'))->toThrow(\Exception::class, 'breite');
});

it('menu-item schließt das Menü, ein eigenes x-on:click läuft vorher, :schliessen="false" hält es offen', function () {
    $html = menueHtml('<x-fa::menu-item wire:click="weg">Weg</x-fa::menu-item>'
        .'<x-fa::menu-item x-on:click="$dispatch(\'senden\')">Senden</x-fa::menu-item>'
        .'<x-fa::menu-item :schliessen="false">Bleibt</x-fa::menu-item>');

    expect($html)->toContain('x-on:click="offen = false" wire:click="weg">Weg</button>')
        ->and($html)->toContain('x-on:click="$dispatch(&#039;senden&#039;); offen = false">Senden</button>')
        ->and($html)->toMatch('/<button type="button" role="menuitem" class="[^"]*">Bleibt<\/button>/');
});

it('detail-kopf nutzt x-fa::menu und behält die Handy-Markierung', function () {
    $html = menueHtml('<x-fa::detail-kopf title="Minestrone"><x-slot:menue><x-fa::menu-item>Löschen</x-fa::menu-item></x-slot:menue></x-fa::detail-kopf>');

    expect($html)->toContain('<div class="relative shrink-0" data-fa-aendern="kopf.menue" x-data="faMenu()"')
        ->and($html)->toContain('class="hidden w-60 fa-surface shadow-lg py-1"')
        ->and($html)->not->toContain('data-fa-aendern-frei');

    config(['foodalchemist.handy.aendern_frei' => ['kopf.menue']]);
    expect(menueHtml('<x-fa::detail-kopf title="M"><x-slot:menue><x-fa::menu-item>L</x-fa::menu-item></x-slot:menue></x-fa::detail-kopf>'))
        ->toContain('data-fa-aendern-frei');
});

it('steht im UI-Katalog', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Menue Baustein TEST'));

    Livewire::test(UiKatalog::class)
        ->assertSeeHtml('data-katalog-menue')
        ->assertSeeHtml('Duplizieren (x-fa::menu)');
});
