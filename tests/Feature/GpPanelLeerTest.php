<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\Browser;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4b: Das leere GP-Panel kündigte „Grundprodukt in der Tabelle anklicken, dann erscheinen hier …“ an,
 * der Klick auf den Namen öffnet aber den Editor. Hinweis nennt jetzt den Zeilen-Klick und den Namen; ohne Auswahl
 * bleibt die Detail-Leiste am Rechner eingeklappt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('leeres GP-Panel: Hinweis nennt Zeilen-Klick und Editor über den Namen, nicht mehr bloß „anklicken“', function () {
    $html = Livewire::test(DetailPanel::class)->html();

    expect($html)->toContain('Kein Grundprodukt gewählt')
        ->toContain('Zeile in der Tabelle anklicken')
        ->toContain('Klick auf den Namen öffnet den Editor')
        ->not->toContain('Grundprodukt in der Tabelle anklicken, dann erscheinen hier');
});

it('x-fa::detail-sidebar mit leer-zu: ohne Auswahl eingeklappt, Rail-Klick zeigt den Hinweis ohne die Einstellung zu ändern', function () {
    $mit = Blade::render('<x-fa::detail-sidebar title="Detail" scope="activity_test" :auswahl="\'\'" :leer-zu="true">x</x-fa::detail-sidebar>');
    $ohne = Blade::render('<x-fa::detail-sidebar title="Detail" scope="activity_test" :auswahl="\'\'">x</x-fa::detail-sidebar>');

    expect($mit)->toContain('data-fa-leer-zu')
        ->toContain('leerZu: true')
        ->toContain("if (! this.handy && this.leerZu && this.auswahl === '') return this.leerAuf;")
        ->and($ohne)->toContain('leerZu: false')->not->toContain('data-fa-leer-zu');
});

it('GP-Browser: Detail-Leiste klappt ohne Auswahl ein und meldet die Auswahl', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');

    expect(Livewire::test(Browser::class)->html())->toContain('data-fa-leer-zu')->toContain('data-fa-auswahl=""')
        ->and(Livewire::test(Browser::class)->call('waehleGp', $gp->id)->html())->toContain('data-fa-auswahl="' . $gp->id . '"');
});

it('leere Panels in Produktion und Formate: gleicher Hinweis, dort öffnet der Name ebenfalls den Editor', function () {
    foreach ([\Platform\FoodAlchemist\Livewire\Produktion\DetailPanel::class, \Platform\FoodAlchemist\Livewire\Formate\DetailPanel::class] as $panel) {
        expect(Livewire::test($panel)->html())->toContain('Zeile in der Tabelle anklicken')->toContain('Klick auf den Namen öffnet den Editor');
    }
});
