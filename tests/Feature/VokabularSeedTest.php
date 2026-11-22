<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\Planung\VokabularCodes;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * CQ (Folge aus 2.5b) · Onboarding-Seed: Ein neues Wurzel-Team bekommt Saison, Einsatzmoment und Eventtyp aus dem Grundbestand mit Codes.
 * Unterteams erben die Kette, persönliche Teams bleiben leer; der Seed lässt das Anlegen eines Teams nie scheitern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();            // ohne Seed angelegt (TestCase schaltet ihn ab)
    config(['foodalchemist.vokabular_seed_bei_team_anlage' => true]);
    \Platform\FoodAlchemist\Support\Planung\VokabularSeed::vergessen();
    $this->anzahl = fn (Team $t) => collect(array_keys(VokabularCodes::GRUNDBESTAND))
        ->mapWithKeys(fn ($tab) => [$tab => DB::table($tab)->where('team_id', $t->id)->whereNotNull('code')->count()])->all();
});

it('ein neues Wurzel-Team bekommt 9 Einsatzmomente, 13 Eventtypen und 5 Saisons mit Codes', function () {
    $team = Team::create(['name' => 'Neuer Mandant', 'user_id' => 1, 'personal_team' => false]);

    expect(($this->anzahl)($team))->toBe(['foodalchemist_service_moments' => 9, 'foodalchemist_event_types' => 13, 'foodalchemist_seasons' => 5])
        ->and(DB::table('foodalchemist_service_moments')->where('team_id', $team->id)->where('name', 'Apéro/Empfang')->value('code'))->toBe('empfang');
});

it('Unterteam und persönliches Team bekommen nichts; ein zweiter Aufruf legt nichts doppelt an', function () {
    $kind = Team::create(['name' => 'Kind C', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);
    $privat = Team::create(['name' => 'Privat', 'user_id' => 1, 'personal_team' => true]);
    expect(array_sum(($this->anzahl)($kind)))->toBe(0)->and(array_sum(($this->anzahl)($privat)))->toBe(0);

    $team = Team::create(['name' => 'Neuer Mandant', 'user_id' => 1, 'personal_team' => false]);
    expect(VokabularCodes::grundbestandAnlegen($team))->toBe(0)
        ->and(array_sum(($this->anzahl)($team)))->toBe(27);
});

it('in einer Transaktion erst nach dem Commit; ein Rollback hinterlässt keine Vokabulare', function () {
    DB::beginTransaction();
    $team = Team::create(['name' => 'Wird zurückgerollt', 'user_id' => 1, 'personal_team' => false]);
    $id = $team->id;
    expect(DB::table('foodalchemist_seasons')->where('team_id', $id)->count())->toBe(0);   // noch nicht geschrieben
    DB::rollBack();

    expect(DB::table('foodalchemist_seasons')->where('team_id', $id)->count())->toBe(0);
});

it('wirft der Seed, wird das Team trotzdem angelegt (Warnung im Log)', function () {
    Log::spy();
    \Illuminate\Support\Facades\Schema::rename('foodalchemist_event_types', 'foodalchemist_event_types_weg');
    try {
        \Platform\FoodAlchemist\Support\Planung\VokabularSeed::vergessen();
        // Tabellenprüfung schlägt an → kein Seed, kein Fehler; danach ein Seed, der wirklich wirft
        $ohne = Team::create(['name' => 'Ohne Tabellen', 'user_id' => 1, 'personal_team' => false]);
        expect(Team::find($ohne->id))->not->toBeNull();
    } finally {
        \Illuminate\Support\Facades\Schema::rename('foodalchemist_event_types_weg', 'foodalchemist_event_types');
    }

    \Platform\FoodAlchemist\Support\Planung\VokabularSeed::vergessen();
    DB::statement('CREATE TRIGGER seed_wirft BEFORE INSERT ON foodalchemist_seasons BEGIN SELECT RAISE(ABORT, \'kaputt\'); END');
    $team = Team::create(['name' => 'Seed wirft', 'user_id' => 1, 'personal_team' => false]);
    DB::statement('DROP TRIGGER seed_wirft');

    expect(Team::find($team->id))->not->toBeNull()
        ->and(array_sum(($this->anzahl)($team)))->toBe(0);                    // ganz oder gar nicht: keine halben Vokabulare
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Vokabular-Seed') && $ctx['team_id'] === $team->id)->once();
});

it('Nachziehen: Trockenlauf zeigt Wurzel-Teams ohne Grundbestand, --apply legt an', function () {
    $this->artisan('foodalchemist:vokabular-codes --grundbestand')->expectsOutputToContain('Root (Katalog-Besitzer)')->assertSuccessful();
    expect(array_sum(($this->anzahl)($this->rootTeam)))->toBe(0);

    $this->artisan('foodalchemist:vokabular-codes --grundbestand --apply')->assertSuccessful();
    expect(array_sum(($this->anzahl)($this->rootTeam)))->toBe(27)
        ->and(array_sum(($this->anzahl)($this->childA)))->toBe(0);   // Unterteam erbt
});
