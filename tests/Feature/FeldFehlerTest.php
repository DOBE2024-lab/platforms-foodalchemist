<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4c Nachtrag (io-Prüfung Master): Ein Fehler am Feld ist nicht nur roter Text darunter. Das Feld selbst
 * trägt aria-invalid, verweist per aria-describedby auf die Meldung und bekommt die rote Linie (--fa-crit-line, 3.9).
 * Der Mechanismus sitzt im Baustein: x-fa::input fehler=…, x-fa::field gibt der Meldung die id „<for>-fehler“.
 */
it('x-fa::input mit fehler: aria-invalid und Verweis auf die Meldung von x-fa::field; ohne fehler nichts davon', function () {
    $mit = Blade::render('<x-fa::field label="Name" for="f-name" error="Bitte einen Namen eingeben."><x-fa::input id="f-name" fehler="Bitte einen Namen eingeben." /></x-fa::field>');
    $ohne = Blade::render('<x-fa::field label="Name" for="f-name"><x-fa::input id="f-name" /></x-fa::field>');

    expect($mit)->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="f-name-fehler"')
        ->toContain('id="f-name-fehler"')
        ->and($ohne)->not->toContain('aria-invalid')->not->toContain('aria-describedby')->not->toContain('-fehler');
});

it('x-fa::input fehler ohne id: aria-invalid, aber kein Verweis ins Leere', function () {
    $html = Blade::render('<x-fa::input :fehler="true" />');

    expect($html)->toContain('aria-invalid="true"')->not->toContain('aria-describedby');
});

it('Anlege-Dialog: leerer Name markiert das Feld, korrigierter Name nimmt die Markierung zurück', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $c = Livewire::test(FoodbooksIndex::class)->call('neu');
    expect($c->html())->not->toContain('aria-invalid');

    $c->set('neuName', '  ')->call('neuAnlegen');
    expect($c->html())->toContain('id="fa-anlegen-name-fehler"')
        ->toMatch('/<input[^>]*id="fa-anlegen-name"[^>]*aria-invalid="true"[^>]*aria-describedby="fa-anlegen-name-fehler"/');

    $c->set('neuName', 'Sommerfest')->call('neuAnlegen');
    expect($c->html())->not->toContain('aria-invalid');
});

it('rote Linie im Fehlerzustand: Regel in pass.css und im ausgelieferten dist', function () {
    $wurzel = dirname(__DIR__, 2);

    foreach (['resources/css/foodalchemist-pass.css', 'resources/dist/foodalchemist.css'] as $datei) {
        expect(str_replace('"', '', (string) preg_replace('/\s+/', '', file_get_contents("$wurzel/$datei"))))   // dist ist minifiziert, ohne Anführungszeichen
            ->toContain('.fa-control[aria-invalid=true]{border-color:var(--fa-crit-line)');   // pass.css endet mit ;}, dist mit }
    }
});
