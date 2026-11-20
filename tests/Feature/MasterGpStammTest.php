<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GefaessZubehoerService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 20.11.2026: „wenn ein Grundprodukt neu angelegt wird, durch die Vererbung, durch das Abo-Paket, sollte es automatisch mit reingehen.“
 * Fall: „Deckel: für Achenbachglas“ im Master BHG.DIGITAL angelegt (team_id = Master) → in der Sandbox (Stamm-Paket gebucht) unsichtbar,
 * ebenso die Zubehör-Liste „Achenbachglas + Deckel“. Jetzt: Master-Anlage = global + stamm_paket; Zubehör des Masters gilt für Mandanten mit Paket.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    StammPaket::flush();
    StammPaket::freischalten($this->rootTeam);   // Mandant mit gebuchtem Paket (Unter-Teams erben)
    $this->ohnePaket = Team::create(['name' => 'Ohne Paket', 'user_id' => 1, 'personal_team' => false]);
    $this->actingAs($this->makeUser($this->master, 'Kurator'));
    $this->naming = app(GpNamingService::class);
    $this->nonFood = fn (?int $team, string $name) => FoodAlchemistGp::create(['team_id' => $team, 'gp_key' => 'mgs|'.mb_strtolower($name), 'name' => $name,
        'status' => 'approved', 'commodity_group_code' => NonFoodRegeln::WG_CODE, 'sub_category' => NonFoodRegeln::SUB_GESCHIRR,
        'stamm_class' => $team === null ? StammPaket::PAKET : StammPaket::BASIS]);
});

it('★ ein GP, das das Master-Team anlegt, ist global und Stamm-Paket — der Mandant mit Paket und sein Unter-Team sehen es', function () {
    $gp = $this->naming->createGp($this->master, ['hauptzutat' => 'Deckel', 'form' => 'für Achenbachglas', 'commodity_group_code' => '16']);

    expect($gp->team_id)->toBeNull()
        ->and($gp->stamm_class)->toBe(StammPaket::PAKET)
        ->and($gp->isOwnedBy($this->master))->toBeTrue()   // der Master pflegt es weiter
        ->and(FoodAlchemistGp::visibleToTeam($this->rootTeam)->whereKey($gp->id)->exists())->toBeTrue()
        ->and(FoodAlchemistGp::visibleToTeam($this->childA)->whereKey($gp->id)->exists())->toBeTrue()
        ->and(FoodAlchemistGp::visibleToTeam($this->ohnePaket)->whereKey($gp->id)->exists())->toBeFalse()   // ohne Paket kein Stamm
        ->and($gp->isOwnedBy($this->rootTeam))->toBeFalse();   // der Mandant liest nur
});

it('ein Kundenteam legt weiter team-eigen an (nicht global)', function () {
    $gp = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Deckel', 'form' => 'für Weckglas', 'commodity_group_code' => '16']);

    expect((int) $gp->team_id)->toBe($this->rootTeam->id)->and($gp->stamm_class)->toBe(StammPaket::BASIS)
        ->and(FoodAlchemistGp::visibleToTeam($this->ohnePaket)->whereKey($gp->id)->exists())->toBeFalse()   // andere Mandanten sehen es nicht
        ->and(GpNamingService::anlageTeamId($this->master))->toBeNull()
        ->and(GpNamingService::anlageTeamId($this->rootTeam))->toBe($this->rootTeam->id);
});

it('★ Zubehör, das der Master am Gefäß pflegt, gilt für Mandanten mit Paket; eine Kundenliste schlägt die Vorgabe', function () {
    $glas = ($this->nonFood)(null, 'Achenbachglas: Broich');
    $deckel = ($this->nonFood)(null, 'Deckel: für Achenbachglas');
    $schwarz = ($this->nonFood)(null, 'Twist-Off-Deckel: schwarz');
    $zub = app(GefaessZubehoerService::class);
    $zub->setzen($this->master, $glas->id, $deckel->id, 1);

    $sicht = fn (Team $t) => array_column($zub->liste($glas->id, $t)['zeilen'], 'gp_id');
    expect($sicht($this->rootTeam))->toBe([$deckel->id])
        ->and($zub->liste($glas->id, $this->rootTeam)['team_id'])->toBe($this->master->id)   // geerbt vom Master
        ->and($sicht($this->childA))->toBe([$deckel->id])
        ->and($sicht($this->ohnePaket))->toBe([]);

    // Kunde überschreibt: erste Änderung kopiert die Master-Liste ins Kundenteam, danach gilt nur die eigene
    $zub->setzen($this->rootTeam, $glas->id, $schwarz->id, 1);
    expect($sicht($this->rootTeam))->toBe([$deckel->id, $schwarz->id])
        ->and($zub->liste($glas->id, $this->rootTeam)['team_id'])->toBe($this->rootTeam->id);
    $zub->entfernen($this->master, $glas->id, $deckel->id);
    expect($sicht($this->rootTeam))->toBe([$deckel->id, $schwarz->id])   // Master-Änderung überschreibt die Kundenliste nicht
        ->and($sicht($this->childB))->toBe([$deckel->id, $schwarz->id]);   // Unter-Team erbt zuerst vom eigenen Haupt-Team
});

it('★ Kommando hebt Master-GPs: Trockenlauf schreibt nichts, --apply macht sie global, --zurueck holt sie zurück', function () {
    $a = FoodAlchemistGp::create(['team_id' => $this->master->id, 'gp_key' => 'limette||saft', 'name' => 'Limette: Saft', 'status' => 'tentative']);
    $b = FoodAlchemistGp::create(['team_id' => $this->master->id, 'gp_key' => 'deckel||achenbach', 'name' => 'Deckel: für Achenbachglas', 'status' => 'tentative']);
    $kunde = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'kunde||eigen', 'name' => 'Kundenware', 'status' => 'approved']);

    $this->artisan('foodalchemist:master-gps-stamm-heben')->assertSuccessful();
    expect((int) $a->fresh()->team_id)->toBe($this->master->id);

    $this->artisan('foodalchemist:master-gps-stamm-heben', ['--apply' => true])->assertSuccessful();
    expect($a->fresh()->team_id)->toBeNull()->and($a->fresh()->stamm_class)->toBe(StammPaket::PAKET)
        ->and($b->fresh()->team_id)->toBeNull()
        ->and((int) $kunde->fresh()->team_id)->toBe($this->rootTeam->id)   // Kundenware bleibt beim Kunden
        ->and(FoodAlchemistGp::visibleToTeam($this->rootTeam)->whereKey($b->id)->exists())->toBeTrue();
    $this->artisan('foodalchemist:master-gps-stamm-heben', ['--verify' => true])->assertSuccessful();

    $protokoll = collect(glob(storage_path('logs/master-gps-stamm-heben-*.json')))->sort()->last();
    $this->artisan('foodalchemist:master-gps-stamm-heben', ['--zurueck' => $protokoll])->assertSuccessful();
    expect((int) $a->fresh()->team_id)->toBe($this->master->id)->and($a->fresh()->stamm_class)->toBe(StammPaket::BASIS);
    @unlink($protokoll);
});

it('★ degenerierter Key („tomate||“) kollidiert mit globalem GP: Key wird neu gebildet, beide bleiben getrennt (Fall 14051 ⇄ 14493)', function () {
    $m = FoodAlchemistGp::create(['team_id' => $this->master->id, 'gp_key' => 'tomate||', 'name' => 'Tomate: trocken, geschwefelt', 'status' => 'approved',
        'main_ingredient_slug' => 'tomate', 'condition' => 'trocken', 'pflichtangabe' => 'geschwefelt', 'commodity_group_code' => '01']);
    $g = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'tomate||', 'name' => 'Tomate: konserviert, Pulpe / (Bio)', 'status' => 'approved']);

    $this->artisan('foodalchemist:master-gps-stamm-heben')->expectsOutputToContain('tomate|| →')->assertSuccessful();
    expect($m->fresh()->gp_key)->toBe('tomate||');   // Trockenlauf schreibt nichts

    $this->artisan('foodalchemist:master-gps-stamm-heben', ['--apply' => true])->assertSuccessful();
    expect($m->fresh()->team_id)->toBeNull()->and($m->fresh()->gp_key)->not->toBe('tomate||')
        ->and($g->fresh()->gp_key)->toBe('tomate||')
        ->and(DB::table('foodalchemist_gps')->whereNull('team_id')->where('gp_key', $m->fresh()->gp_key)->count())->toBe(1);

    $protokoll = collect(glob(storage_path('logs/master-gps-stamm-heben-*.json')))->sort()->last();
    $this->artisan('foodalchemist:master-gps-stamm-heben', ['--zurueck' => $protokoll])->assertSuccessful();
    expect($m->fresh()->gp_key)->toBe('tomate||')->and((int) $m->fresh()->team_id)->toBe($this->master->id);
    @unlink($protokoll);
});

it('echte Dublette (vollständiger Key) bricht ab — nichts wird geschrieben', function () {
    $m = FoodAlchemistGp::create(['team_id' => $this->master->id, 'gp_key' => 'zitrone|abrieb|', 'name' => 'Zitrone: Abrieb', 'status' => 'tentative']);
    FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'zitrone|abrieb|', 'name' => 'Zitrone: Abrieb', 'status' => 'approved']);

    $this->artisan('foodalchemist:master-gps-stamm-heben', ['--apply' => true])->assertFailed();
    expect((int) $m->fresh()->team_id)->toBe($this->master->id);
});
