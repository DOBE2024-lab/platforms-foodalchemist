<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Einstellungen\BehaelterService;
use Platform\FoodAlchemist\Services\Einstellungen\EinsatzorteService;
use Platform\FoodAlchemist\Services\Einstellungen\VkTaxonomieService;
use Platform\FoodAlchemist\Services\OutletService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3a — Einstellungs-Schreibwege über Services (Behälter, Betriebe, Einsatzorte, VK-Taxonomie):
 * je Service-Methode Erfolgsfall, Besitzer-Schutz (Kind-Team schreibt keine Zeile des Wurzel-Teams)
 * und eine Eingabe-Meldung — mit genau dem Wortlaut, den die Oberfläche zeigt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('BehaelterService: anlegen, aktualisieren, umschalten, löschen — mit Pflicht-, Dubletten- und Verwendungs-Meldung', function () {
    $svc = app(BehaelterService::class);

    expect($svc->anlegen($this->rootTeam, 'behaelter', ['name' => 'GN 1/1 65', 'laenge_mm' => '530', 'nutzfaktor' => '']))->toBe('GN 1/1 65');
    $zeile = DB::table('foodalchemist_vocab_containers')->where('team_id', $this->rootTeam->id)->where('slug', Str::slug('GN 1/1 65', '_'))->first();
    expect((float) $zeile->laenge_mm)->toBe(530.0)->and((float) $zeile->nutzfaktor)->toBe(0.85);

    expect(fn () => $svc->anlegen($this->rootTeam, 'behaelter', ['name' => ' ']))->toThrow(\InvalidArgumentException::class, 'Name ist Pflicht.');
    expect(fn () => $svc->anlegen($this->rootTeam, 'behaelter', ['name' => 'GN 1/1 65']))
        ->toThrow(\InvalidArgumentException::class, '«GN 1/1 65» existiert schon in Behälter (GN & Co.) (gn_11_65).');

    expect($svc->aktualisieren($this->rootTeam, $zeile->id, ['name' => 'GN 1/1 tief', 'tiefe_mm' => '65,5']))->toBe('GN 1/1 tief');
    expect(DB::table('foodalchemist_vocab_containers')->find($zeile->id))
        ->name->toBe('GN 1/1 tief')->slug->toBe('gn_1_1_65');
    expect(fn () => $svc->aktualisieren($this->rootTeam, $zeile->id, ['name' => '']))->toThrow(\InvalidArgumentException::class, 'Name ist Pflicht.');

    $svc->aktivUmschalten($this->rootTeam, 'behaelter', $zeile->id);
    expect((bool) DB::table('foodalchemist_vocab_containers')->find($zeile->id)->is_inactive)->toBeTrue();

    $rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'r98', 'name' => 'Fond', 'status' => 'approved']);
    $rezept->forceFill(['container_warm_vocab_id' => $zeile->id])->save();
    expect(fn () => $svc->loeschen($this->rootTeam, 'behaelter', $zeile->id))
        ->toThrow(NochVerwendetException::class, '«GN 1/1 tief» ist 1× in Verwendung (Rezepte, Darreichungen, Regeneration) — erst umhängen oder deaktivieren.');

    $rezept->forceFill(['container_warm_vocab_id' => null])->save();
    expect($svc->loeschen($this->rootTeam, 'behaelter', $zeile->id))->toBe('GN 1/1 tief');
    expect(DB::table('foodalchemist_vocab_containers')->find($zeile->id))->toBeNull();
    expect(fn () => $svc->loeschen($this->rootTeam, 'behaelter', $zeile->id))->toThrow(NichtGefundenException::class);
});

it('BehaelterService: ein Kind-Team schreibt keine Zeile des Wurzel-Teams', function () {
    $svc = app(BehaelterService::class);
    $svc->anlegen($this->rootTeam, 'regen', ['name' => 'Kombidämpfer']);
    $regen = DB::table('foodalchemist_vocab_regeneration_devices')->where('team_id', $this->rootTeam->id)->value('id');
    $svc->anlegen($this->rootTeam, 'behaelter', ['name' => 'Eimer 10 l']);
    $behaelter = DB::table('foodalchemist_vocab_containers')->where('team_id', $this->rootTeam->id)->value('id');

    expect(fn () => $svc->eigenerBehaelter($this->childA, $behaelter))
        ->toThrow(GeerbtNurLesenException::class, 'Fremder Eintrag — nur das Besitzer-Team (bzw. der Master bei globalen Zeilen) kann ändern.');
    expect(fn () => $svc->aktualisieren($this->childA, $behaelter, ['name' => 'Gekapert']))->toThrow(GeerbtNurLesenException::class);
    expect(fn () => $svc->aktivUmschalten($this->childA, 'regen', $regen))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann ändern.');
    expect(fn () => $svc->loeschen($this->childA, 'regen', $regen))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann löschen.');

    expect(DB::table('foodalchemist_vocab_containers')->find($behaelter)->name)->toBe('Eimer 10 l')
        ->and((bool) DB::table('foodalchemist_vocab_regeneration_devices')->find($regen)->is_inactive)->toBeFalse();
});

it('OutletService: aktualisieren, Logo entfernen, umschalten — nur eigene Profile, Name eindeutig', function () {
    $svc = app(OutletService::class);
    $a = $svc->anlegen($this->rootTeam, 'Kantine');
    $svc->anlegen($this->rootTeam, 'Bistro');

    $svc->aktualisieren($this->rootTeam, $a->id, ['name' => ' Kantine Nord ', 'color' => 'rot', 'sort_order' => '-5', 'vorlage' => '']);
    expect($a->refresh())->name->toBe('Kantine Nord')->color->toBeNull()->sort_order->toEqual(0)->presentation_design->toBeNull();

    expect(fn () => $svc->aktualisieren($this->rootTeam, $a->id, ['name' => 'Bistro']))
        ->toThrow(\InvalidArgumentException::class, 'Es gibt bereits ein Kalkulationsprofil mit diesem Namen.');

    $a->update(['logo_path' => 'foodalchemist/outlet-logo/x.png']);
    $svc->logoEntfernen($this->rootTeam, $a->id);
    expect($a->refresh()->logo_path)->toBeNull();

    $svc->aktivUmschalten($this->rootTeam, $a->id);
    expect($a->refresh()->is_inactive)->toBeTrue();

    foreach ([
        fn () => $svc->aktualisieren($this->childA, $a->id, ['name' => 'Gekapert']),
        fn () => $svc->logoEntfernen($this->childA, $a->id),
        fn () => $svc->aktivUmschalten($this->childA, $a->id),
    ] as $versuch) {
        expect($versuch)->toThrow(NichtGefundenException::class);
    }
    expect(FoodAlchemistOutlet::find($a->id))->name->toBe('Kantine Nord')->is_inactive->toBeTrue();
});

it('EinsatzorteService: umbenennen und umschalten nur fürs Besitzer-Team', function () {
    $layer = fn (?int $teamId, string $slug) => DB::table('foodalchemist_knowledge_layers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $teamId, 'slug' => $slug, 'label' => $slug, 'description' => null,
        'kind' => 'prompt', 'sort_order' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $eigen = $layer($this->rootTeam->id, 'eigen');
    $svc = app(EinsatzorteService::class);

    $svc->aktualisieren($this->rootTeam, $eigen, ['label' => ' Bankett ', 'description' => '']);
    expect(DB::table('foodalchemist_knowledge_layers')->find($eigen))->label->toBe('Bankett')->description->toBeNull();
    expect(fn () => $svc->aktualisieren($this->rootTeam, $eigen, ['label' => ' ']))->toThrow(\InvalidArgumentException::class, 'Bezeichnung ist Pflicht.');

    $svc->aktivUmschalten($this->rootTeam, $eigen);
    expect((bool) DB::table('foodalchemist_knowledge_layers')->find($eigen)->active)->toBeFalse();

    expect(fn () => $svc->aktualisieren($this->childA, $eigen, ['label' => 'Gekapert']))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbter/globaler Einsatzort — umbenennen kann nur das Besitzer-Team.');
    expect(fn () => $svc->aktivUmschalten($this->childA, $eigen))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbter/globaler Einsatzort — aktiv/inaktiv setzt nur das Besitzer-Team.');
    expect(fn () => $svc->aktivUmschalten($this->childA, $layer(null, 'global')))->toThrow(GeerbtNurLesenException::class);
    expect(DB::table('foodalchemist_knowledge_layers')->find($eigen))->label->toBe('Bankett');
});

it('VkTaxonomieService: Hauptgruppe umschalten nur fürs Besitzer-Team', function () {
    $hg = $this->makeMainGroup($this->rootTeam, 'S98');
    $svc = app(VkTaxonomieService::class);

    expect($svc->hauptgruppeAktivUmschalten($this->rootTeam, $hg->id)->is_inactive)->toBeTruthy();

    expect(fn () => $svc->hauptgruppeAktivUmschalten($this->childA, $hg->id))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbte Speisen-Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
    expect(fn () => $svc->hauptgruppeAktivUmschalten($this->childA, $this->makeMainGroup($this->childB, 'FREMD')->id))
        ->toThrow(NichtGefundenException::class);
    expect($hg->refresh()->is_inactive)->toBeTruthy();
});
