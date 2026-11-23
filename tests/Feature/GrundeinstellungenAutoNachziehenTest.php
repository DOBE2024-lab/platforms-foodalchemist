<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Concepter\PaketGrundeinstellungenService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 20.11.2026: „wir erweitern ja ständig und die haben ja dieses Paket gebucht“ — was der Master im Paket „Grundeinstellungen“ neu anlegt,
 * kommt bei den Mandanten mit gebuchtem Paket von selbst an (stündlich), ohne Klick auf „Nachziehen“. Nie überschreiben, Gelöschtes bleibt weg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->actingAs($this->admin);
    $this->svc = app(PaketGrundeinstellungenService::class);
    $this->ins = fn (string $t, array $z) => DB::table('foodalchemist_'.$t)->insertGetId($z + ['uuid' => (string) UuidV7::generate(), 'team_id' => $this->master->id,
        'created_at' => now(), 'updated_at' => now()]);
    $this->stil = fn (Team $t) => DB::table('foodalchemist_writing_styles')->where('team_id', $t->id)->whereNull('deleted_at')->orderBy('slug')->pluck('name', 'slug')->all();
    ($this->ins)('writing_styles', ['slug' => 'sachlich', 'name' => 'Sachlich', 'sprach_duktus' => 'nüchtern']);
    $this->ohnePaket = Team::create(['name' => 'Ohne Paket', 'user_id' => 1, 'personal_team' => false]);
});

it('★ neue Master-Zeile kommt beim gebuchten Mandanten ohne Klick an — Buchungsdatum bleibt, Mandant ohne Paket bekommt nichts', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);
    DB::table('foodalchemist_team_pakete')->where('team_id', $this->rootTeam->id)->update(['gebucht_am' => '2026-11-08 10:00:00']);
    ($this->ins)('writing_styles', ['slug' => 'poetisch', 'name' => 'Poetisch', 'sprach_duktus' => 'bildhaft']);

    $this->artisan('foodalchemist:grundeinstellungen-nachziehen')->assertSuccessful();

    expect(($this->stil)($this->rootTeam))->toBe(['poetisch' => 'Poetisch', 'sachlich' => 'Sachlich'])
        ->and(($this->stil)($this->ohnePaket))->toBe([])
        ->and((string) DB::table('foodalchemist_team_pakete')->where('team_id', $this->rootTeam->id)->value('gebucht_am'))->toStartWith('2026-11-08');
});

it('★ nie überschreiben, Gelöschtes bleibt gelöscht, zweiter Lauf kopiert nichts', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);
    DB::table('foodalchemist_writing_styles')->where('team_id', $this->rootTeam->id)->where('slug', 'sachlich')->update(['name' => 'Sachlich (unser)']);
    DB::table('foodalchemist_writing_styles')->where('team_id', $this->master->id)->where('slug', 'sachlich')->update(['name' => 'Sachlich NEU']);
    $weg = ($this->ins)('writing_styles', ['slug' => 'knapp', 'name' => 'Knapp', 'sprach_duktus' => 'kurz']);
    $this->svc->nachziehen($this->rootTeam);
    DB::table('foodalchemist_writing_styles')->where('team_id', $this->rootTeam->id)->where('slug', 'knapp')->delete();   // Mandant löscht hart

    $r = $this->svc->nachziehen($this->rootTeam);
    expect($r['neu'])->toBe(0)
        ->and(($this->stil)($this->rootTeam))->toBe(['sachlich' => 'Sachlich (unser)']);
});

it('nicht gebucht oder Master-Team: nachziehen tut nichts', function () {
    expect($this->svc->nachziehen($this->rootTeam)['neu'])->toBe(0)
        ->and($this->svc->nachziehen($this->master)['neu'])->toBe(0)
        ->and(($this->stil)($this->rootTeam))->toBe([]);
});
