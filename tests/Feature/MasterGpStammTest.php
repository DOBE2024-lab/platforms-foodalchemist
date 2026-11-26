<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService;
use Platform\FoodAlchemist\Services\GpStamm\GpNamingService;
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

it('★ Kostenart am Gefäß: Mandant ohne eigenen Eintrag erbt die des Masters; ein eigener Eintrag schlägt sie (Deckel 20.11.2026)', function () {
    $deckel = ($this->nonFood)(null, 'Deckel: für Achenbachglas');
    $svc = app(\Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::class);
    $svc->setzen($this->master, $deckel->id, 'mehrweg', '0,12');

    // ohne eigenen Eintrag: geerbt vom Master (vorher sah der Mandant nichts → Automatik „einweg“)
    // (den Preis nur mit „Preise mitgeben“ für den Lieferanten — hier ohne Lead-Artikel, also nicht; eigener Test unten)
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'mehrweg', 'preis' => null, 'team_id' => $this->master->id])
        ->and($svc->teamVorgabe($deckel->id, $this->master)['preis'])->toBe(0.12)
        ->and($svc->teamVorgabe($deckel->id, $this->childA)['kostenart'])->toBe('mehrweg')
        ->and($svc->teamVorgabe($deckel->id, $this->ohnePaket)['kostenart'])->toBeNull();   // ohne Stamm-Paket kein Master

    // eigener Eintrag des Mandanten schlägt den Master; Unter-Team erbt zuerst vom eigenen Haupt-Team
    $svc->setzen($this->rootTeam, $deckel->id, 'einweg', '0,30');
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'einweg', 'preis' => 0.3, 'team_id' => $this->rootTeam->id])
        ->and($svc->teamVorgabe($deckel->id, $this->childA)['team_id'])->toBe($this->rootTeam->id);

    // Master ändert: die Kundenzeile bleibt; Kunde entfernt seine: wieder der Master
    $svc->setzen($this->master, $deckel->id, 'extern');
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam)['kostenart'])->toBe('einweg');
    $svc->setzen($this->rootTeam, $deckel->id, null);
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'extern', 'preis' => null, 'team_id' => $this->master->id]);
});

it('★ Gefäßpreis des Masters nur mit „Preise mitgeben“ für den Lieferanten des Lead-Artikels; die Kostenart erbt immer (Kurator 21.11.2026)', function () {
    $admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    $lieferant = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Glas-Händler', 'stamm_class' => StammPaket::PAKET]);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $lieferant->id, 'designation' => 'Deckel Achenbach',
        'article_number' => 'D1', 'qty' => 1, 'unit_code' => 'Stk', 'stamm_class' => StammPaket::PAKET]);
    $deckel = ($this->nonFood)(null, 'Deckel: für Achenbachglas');
    $deckel->update(['lead_la_supplier_item_id' => $la->id]);
    $stamm = app(\Platform\FoodAlchemist\Services\Einkauf\StammPaketService::class);
    $stamm->setzeLieferanten($this->rootTeam, [$lieferant->id], $admin);
    $svc = app(\Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::class);
    $svc->setzen($this->master, $deckel->id, 'mehrweg', '0,40');

    // ohne Haken: Art vom Master, Preis nicht → Darreichung meldet „Gefäß-Preis fehlt“ (mehrweg kauft nichts ein, kein Artikel-Rückfall)
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'mehrweg', 'preis' => null, 'team_id' => $this->master->id])
        ->and($svc->teamVorgabe($deckel->id, $this->childA)['preis'])->toBeNull()
        ->and($svc->teamVorgabe($deckel->id, $this->master)['preis'])->toBe(0.4)   // der Master selbst sieht seinen Preis
        ->and(app(\Platform\FoodAlchemist\Services\DarreichungService::class)->stueckKosten($deckel, $this->rootTeam)['preis'])->toBeNull();

    // mit Haken: Preis kommt mit, auch beim Unter-Team
    $stamm->setzePreisFreigabe($this->rootTeam, [$lieferant->id], $admin);
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'mehrweg', 'preis' => 0.4, 'team_id' => $this->master->id])
        ->and($svc->teamVorgabe($deckel->id, $this->childA)['preis'])->toBe(0.4);

    // eigener Eintrag des Mandanten schlägt beides (Art und Preis), mit und ohne Haken
    $svc->setzen($this->rootTeam, $deckel->id, 'einweg', '0,25');
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'einweg', 'preis' => 0.25, 'team_id' => $this->rootTeam->id]);
    $stamm->setzePreisFreigabe($this->rootTeam, 'keine', $admin);
    expect($svc->teamVorgabe($deckel->id, $this->rootTeam))->toBe(['kostenart' => 'einweg', 'preis' => 0.25, 'team_id' => $this->rootTeam->id]);
});

it('★ Rückfall auf den Lead-Artikelpreis (einweg ohne Preis am GP) zieht den Master-Preis nur mit Freigabe', function () {
    $admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    $lieferant = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Glas-Händler', 'stamm_class' => StammPaket::PAKET]);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $lieferant->id, 'designation' => 'Becher',
        'article_number' => 'B1', 'qty' => 1, 'unit_code' => 'Stk', 'stamm_class' => StammPaket::PAKET]);
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'supplier_item_id' => $la->id, 'team_id' => $this->master->id,
        'status' => '0', 'price' => 0.55, 'is_blocked' => false, 'status_valid_from' => '2026-11-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $becher = ($this->nonFood)(null, 'Becher: Einweg');
    $becher->update(['lead_la_supplier_item_id' => $la->id]);
    DB::table('foodalchemist_supplier_item_structures')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'supplier_item_id' => $la->id,
        'gp_id' => $becher->id, 'created_at' => now(), 'updated_at' => now()]);
    $stamm = app(\Platform\FoodAlchemist\Services\Einkauf\StammPaketService::class);
    $stamm->setzeLieferanten($this->rootTeam, [$lieferant->id], $admin);
    app(\Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::class)->setzen($this->master, $becher->id, 'einweg');
    $dar = app(\Platform\FoodAlchemist\Services\DarreichungService::class);

    expect($dar->stueckKosten($becher, $this->master)['preis'])->toBe(0.55);
    expect($dar->stueckKosten($becher, $this->rootTeam)['preis'])->toBeNull();   // kein Master-LA-Preis an der Freigabe vorbei
    $stamm->setzePreisFreigabe($this->rootTeam, [$lieferant->id], $admin);
    app()->forgetInstance(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class);   // Artikel-Vorrat gilt je Instanz (= je Anfrage)
    expect(app(\Platform\FoodAlchemist\Services\DarreichungService::class)->stueckKosten($becher, $this->rootTeam)['preis'])->toBe(0.55);
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
