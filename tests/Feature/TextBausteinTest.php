<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\UiKatalog;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Welle 1, Paket 1.4a — x-fa::text: eine Rolle, ein Element, die Klasse aus Ui::maps()['textRolle'].
 * Dazu: beide Hüllen laden resources/dist/foodalchemist-text.css über _platform/fa-assets (ohne Host-Build).
 */
$basis = dirname(__DIR__, 2);

it('rendert Rolle und passendes Element, Überschriften als h1–h3', function () {
    expect(trim(Blade::render('<x-fa::text rolle="muted">leise</x-fa::text>')))
        ->toBe('<span class="fa-t-muted">leise</span>')
        ->and(trim(Blade::render('<x-fa::text rolle="body">Fließtext</x-fa::text>')))
        ->toBe('<p class="fa-t-body">Fließtext</p>')
        ->and(trim(Blade::render('<x-fa::text rolle="h2">Allergene</x-fa::text>')))
        ->toBe('<h2 class="fa-t-h2">Allergene</h2>');
});

it('übernimmt ein anderes Element und eigene Klassen/Attribute', function () {
    $html = trim(Blade::render('<x-fa::text rolle="label" as="dt" class="tabular-nums" data-x="1">EK je kg</x-fa::text>'));

    expect($html)->toBe('<dt class="fa-t-label tabular-nums" data-x="1">EK je kg</dt>');
});

it('lehnt unbekannte Rollen und Elemente laut ab statt still ungestylt zu rendern', function () {
    expect(fn () => Blade::render('<x-fa::text rolle="klein">x</x-fa::text>'))->toThrow(\Throwable::class, 'rolle')
        ->and(fn () => Blade::render('<x-fa::text rolle="body" as="script">x</x-fa::text>'))->toThrow(\Throwable::class, 'as=');
});

it('Plattform-Hülle: jede FA-Seite lädt die Text-Rollen genau einmal über die Kopfzeile, mit Cache-Schlüssel', function () {
    config(['foodalchemist.shell' => 'plattform']);
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Text-Rollen CSS TEST'));

    $html = Livewire::test(UiKatalog::class)->html();

    expect(substr_count($html, 'data-fa-text-css'))->toBe(1)
        ->and($html)->toContain('/_platform/fa-assets/foodalchemist-text.css?v=')
        ->and(FaShell::assetUrl('foodalchemist-text.css'))->not->toEndWith('?v=0');
});

it('eigenständige Hülle: das Layout lädt die Text-Rollen im <head>, die Kopfzeile nicht noch einmal', function () use ($basis) {
    config(['foodalchemist.shell' => 'eigenstaendig']);
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Text-Rollen CSS TEST'));

    $layout = file_get_contents($basis.'/resources/views/layouts/standalone.blade.php');
    $kopf = substr($layout, 0, strpos($layout, '</head>'));

    expect($kopf)->toContain('<x-foodalchemist::shell.text-css />')
        ->and(Blade::render('<x-foodalchemist::shell.text-css />'))->toContain('/_platform/fa-assets/foodalchemist-text.css?v=')
        ->and(substr_count(Livewire::test(UiKatalog::class)->html(), 'data-fa-text-css'))->toBe(0);
});

it('Kiosk-Layout (Wand-Monitor) lädt die Text-Rollen in beiden Hüllen', function () use ($basis) {
    $layout = file_get_contents($basis.'/resources/views/layouts/kiosk.blade.php');

    expect(substr($layout, 0, strpos($layout, '</head>')))->toContain('<x-foodalchemist::shell.text-css />');
});
