<?php

use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\Einstellungen\AufschlagsklassenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3a — Preisklassen headless: dieselben Regeln wie die Einstellungsseite, ohne Livewire.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(AufschlagsklassenService::class);
});

it('legt an, ändert, schaltet um und löscht eine eigene Preisklasse', function () {
    $ak = $this->svc->anlegen($this->rootTeam, ['code' => ' std ', 'label' => 'Standard', 'class_factor_pct' => '110,5', 'bereich' => 'speisen']);
    expect($ak->code)->toBe('STD')
        ->and((float) $ak->class_factor_pct)->toBe(110.5)
        ->and((int) $ak->team_id)->toBe($this->rootTeam->id);

    $this->svc->aktualisieren($this->rootTeam, $ak->id, ['label' => 'Gehoben', 'class_factor_pct' => '120', 'rounding_decimals' => '9']);
    $ak->refresh();
    expect($ak->label)->toBe('Gehoben')->and((int) $ak->rounding_decimals)->toBe(4);

    $this->svc->aktivUmschalten($this->rootTeam, $ak->id);
    expect((bool) $ak->refresh()->is_inactive)->toBeTrue();

    $this->svc->loeschen($this->rootTeam, $ak->id);
    expect(FoodAlchemistMarkupClass::whereKey($ak->id)->exists())->toBeFalse();
});

it('lehnt Pflichtfehler, doppelte Codes und Faktor 0 mit der Oberflächen-Meldung ab', function () {
    $this->svc->anlegen($this->rootTeam, ['code' => 'A', 'label' => 'A', 'class_factor_pct' => '100']);

    expect(fn () => $this->svc->anlegen($this->rootTeam, ['code' => '', 'label' => 'x']))->toThrow(InvalidArgumentException::class, 'Code und Bezeichnung sind Pflicht.')
        ->and(fn () => $this->svc->anlegen($this->rootTeam, ['code' => 'a', 'label' => 'B', 'class_factor_pct' => '100']))->toThrow(InvalidArgumentException::class, 'schon vergeben')
        ->and(fn () => $this->svc->anlegen($this->rootTeam, ['code' => 'C', 'label' => 'C', 'class_factor_pct' => '0']))->toThrow(InvalidArgumentException::class, 'größer als 0');
});

it('schreibt nie in geerbte oder unsichtbare Klassen', function () {
    $master = $this->svc->anlegen($this->rootTeam, ['code' => 'M', 'label' => 'Master', 'class_factor_pct' => '100']);
    $fremd = FoodAlchemistMarkupClass::create(['team_id' => $this->childB->id, 'code' => 'B', 'label' => 'B', 'class_factor_pct' => 100]);

    expect(fn () => $this->svc->aktualisieren($this->childA, $master->id, ['label' => 'Gekapert', 'class_factor_pct' => '1']))->toThrow(GeerbtNurLesenException::class)
        ->and(fn () => $this->svc->loeschen($this->childA, $fremd->id))->toThrow(NichtGefundenException::class);
    expect($master->refresh()->label)->toBe('Master')
        ->and(FoodAlchemistMarkupClass::whereKey($fremd->id)->exists())->toBeTrue();
});
