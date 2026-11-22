<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Support\WgRolle;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Code-Qualität 3.7: Rollen-Flags an den Warengruppen (Getränk, Non-Food, kühl, fertig) statt Code-Literale.
 * Rollen gelten global (Basis-Katalog), Team-Kopien erben über den Code; ohne Katalog-Zeile gilt WgRolle::STANDARD.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    WgRolle::vergessen();
});

function wgRoh(string $code, ?int $team = null, array $flags = []): void
{
    DB::table('foodalchemist_lookup_commodity_groups')->insert(array_merge([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $team, 'code' => $code, 'name' => "WG {$code}",
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $flags));
    WgRolle::vergessen();
}

it('Standard-Belegung: 15 Getränk, 16 Non-Food, 13 kühl und fertig (Entscheid Dominique)', function () {
    expect(WgRolle::STANDARD)->toBe([
        'getraenk' => ['15'],
        'non_food' => ['16'],
        'kuehl' => ['01', '02', '03', '04', '05', '06', '13', '14'],
        'fertig' => ['10', '11', '13'],
    ]);
});

it('ohne Katalog-Zeile gilt der Standard', function () {
    expect(WgRolle::istGetraenk('15'))->toBeTrue()
        ->and(WgRolle::istGetraenk('14'))->toBeFalse()
        ->and(WgRolle::istNonFood('16'))->toBeTrue()
        ->and(WgRolle::istNonFood(null))->toBeFalse()
        ->and(WgRolle::istKuehl('13'))->toBeTrue()
        ->and(WgRolle::istFertig('13'))->toBeTrue()
        ->and(WgRolle::istFertig('09'))->toBeFalse()
        ->and(WgRolle::codes(WgRolle::NON_FOOD))->toBe(['16']);
});

it('eine Katalog-Zeile mit Flag gewinnt gegen den Standard — in beide Richtungen', function () {
    wgRoh('15', null, ['is_getraenk' => false]);
    wgRoh('17', null, ['is_getraenk' => true]);

    expect(WgRolle::istGetraenk('15'))->toBeFalse()
        ->and(WgRolle::istGetraenk('17'))->toBeTrue()
        ->and(WgRolle::codes(WgRolle::GETRAENK))->toBe(['17']);
});

it('Team-Kopien überschreiben keine Rolle — maßgeblich ist der Basis-Katalog', function () {
    wgRoh('18', $this->childA->id, ['is_non_food' => true]);
    wgRoh('16', $this->childA->id, ['is_non_food' => false]);

    expect(WgRolle::istNonFood('18'))->toBeFalse()
        ->and(WgRolle::istNonFood('16'))->toBeTrue();
});

it('neue Zeilen bekommen die Standard-Rollen ihres Codes, ausdrücklich gesetzte bleiben', function () {
    $nf = FoodAlchemistLookupWarengruppe::create(['team_id' => null, 'code' => '16', 'name' => '16 Non-Food']);
    $x = FoodAlchemistLookupWarengruppe::create(['team_id' => null, 'code' => '15', 'name' => 'Getränke', 'is_getraenk' => false]);

    expect($nf->refresh()->is_non_food)->toBeTrue()
        ->and($nf->is_getraenk)->toBeFalse()
        ->and($x->refresh()->is_getraenk)->toBeFalse();
});

it('Speichern einer Warengruppe verwirft den Cache', function () {
    $wg = FoodAlchemistLookupWarengruppe::create(['team_id' => null, 'code' => '15', 'name' => 'Getränke']);
    expect(WgRolle::istGetraenk('15'))->toBeTrue();

    $wg->update(['is_getraenk' => false]);
    expect(WgRolle::istGetraenk('15'))->toBeFalse();
});

it('unbekannte Rolle ist ein Programmierfehler', function () {
    WgRolle::hat('15', 'alkohol');
})->throws(InvalidArgumentException::class);

it('Migration: Backfill nach Code, idempotent, nimmt nichts weg', function () {
    wgRoh('15');                                    // Flag fehlt (Stand vor dem Backfill)
    wgRoh('13', $this->childA->id);              // Team-Zeile wird mitgezogen
    wgRoh('07', null, ['is_kuehl' => true]);        // händisch gesetzt, nicht im Standard → bleibt

    $migration = require dirname(__DIR__, 2).'/database/migrations/2027_01_08_100000_warengruppen_rollen.php';
    $migration->up();
    $migration->up();

    $z = fn (string $code) => DB::table('foodalchemist_lookup_commodity_groups')->where('code', $code)->first();
    expect((bool) $z('15')->is_getraenk)->toBeTrue()
        ->and((bool) $z('15')->is_kuehl)->toBeFalse()
        ->and((bool) $z('13')->is_kuehl)->toBeTrue()
        ->and((bool) $z('13')->is_fertig)->toBeTrue()
        ->and((bool) $z('07')->is_kuehl)->toBeTrue();
});

it('Kommando: Probelauf schreibt nichts, --apply setzt die fehlenden Standard-Flags', function () {
    wgRoh('16');

    $this->artisan('foodalchemist:wg-rollen')->expectsOutputToContain('1 Flag(s) zu setzen')->assertSuccessful();
    expect((bool) DB::table('foodalchemist_lookup_commodity_groups')->where('code', '16')->value('is_non_food'))->toBeFalse();

    $this->artisan('foodalchemist:wg-rollen', ['--apply' => true])->expectsOutputToContain('1 Flag(s) gesetzt')->assertSuccessful();
    expect((bool) DB::table('foodalchemist_lookup_commodity_groups')->where('code', '16')->value('is_non_food'))->toBeTrue();
});
