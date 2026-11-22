<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\VoiceCommandService;
use Platform\FoodAlchemist\Support\Planung\PlanungOptionen;
use Platform\FoodAlchemist\Support\Planung\PlanungVokabular;
use Platform\FoodAlchemist\Support\Planung\VokabularCodes;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.5b · Die Planung liest Saison, Anlass (= Einsatzmoment), Eventtyp und Service-Art aus den DB-Vokabularen
 * (Einstellungen › Konzepter-Dimensionen). Gespeichert wird der Code; Alt-Wert `occasion=konferenz` wird Eventtyp (Dominique 22.11.2026).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Rückfall: ein Team ohne eigene Kopien behält lunch, empfang und konferenz (Grundbestand)', function () {
    $vok = app(PlanungVokabular::class);
    expect(DB::table('foodalchemist_service_moments')->count())->toBe(0)
        ->and($vok->gueltig($this->rootTeam, 'occasion', 'lunch'))->toBeTrue()
        ->and($vok->gueltig($this->rootTeam, 'occasion', 'empfang'))->toBeTrue()
        ->and($vok->gueltig($this->rootTeam, 'eventtyp', 'konferenz'))->toBeTrue()
        ->and(app(PlanningSessionService::class)->filterGenerationParams(['occasion' => 'konferenz', 'serviceform' => 'buffet', 'saison' => 'winter'], $v, $this->rootTeam))
        ->toBe(['serviceform' => 'buffet', 'saison' => 'winter', 'eventtyp' => 'konferenz']);
});

it('Rückfall: eigene Kopien des Teams gehen vor, das Unterteam erbt sie', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    DB::table('foodalchemist_service_moments')->where('team_id', $this->rootTeam->id)->where('code', 'lunch')->update(['name' => 'Mittagstisch']);

    expect(app(PlanungVokabular::class)->optionen($this->rootTeam, 'occasion'))->toHaveKey('lunch', 'Mittagstisch')
        ->and(app(PlanungVokabular::class)->optionen($this->childA, 'occasion'))->toHaveKey('lunch', 'Mittagstisch');
});

it('Konstanten weg: Anlass, Serviceform und Saison stehen nicht mehr im Code', function () {
    expect(defined(PlanungOptionen::class . '::OCCASION_OPTIONEN'))->toBeFalse()
        ->and(defined(PlanungOptionen::class . '::SERVICEFORM_OPTIONEN'))->toBeFalse()
        ->and(defined(PlanungIndex::class . '::SAISON_OPTIONEN'))->toBeFalse()
        ->and(defined(PlanungIndex::class . '::OCCASION_OPTIONEN'))->toBeFalse();
});

it('Codes: Grundbestand behält die bisherigen Planungs-Slugs, Service-Art ist global', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    $vok = app(PlanungVokabular::class);

    expect($vok->optionen($this->rootTeam, 'occasion'))->toMatchArray(['fruehstueck' => 'Frühstück', 'lunch' => 'Lunch', 'empfang' => 'Apéro/Empfang',
        'dinner' => 'Dinner', 'late_night' => 'Late-Night/Mitternachtssnack'])
        ->and($vok->optionen($this->rootTeam, 'eventtyp'))->toHaveKey('konferenz', 'Konferenz/Tagung')
        ->and(array_keys($vok->optionen($this->rootTeam, 'serviceform')))->toBe(['tellerservice', 'buffet', 'flying', 'stehempfang', 'boxed'])
        ->and(array_keys($vok->optionen($this->rootTeam, 'saison')))->toContain('fruehling', 'sommer', 'herbst', 'winter', 'ganzjaehrig')
        ->and($vok->optionen($this->childA, 'occasion'))->toHaveKey('lunch');   // Unterteam erbt
});

it('Ein neuer Eintrag in den Einstellungen ist sofort in der Planung wählbar', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    app(\Platform\FoodAlchemist\Services\Einstellungen\ConcepterDimensionenService::class)->anlegen($this->rootTeam, 'einsatzmomente', 'Mitternachtsbuffet');

    expect(DB::table('foodalchemist_service_moments')->where('name', 'Mitternachtsbuffet')->value('code'))->toBe('mitternachtsbuffet')
        ->and(app(PlanungVokabular::class)->optionen($this->rootTeam, 'occasion'))->toHaveKey('mitternachtsbuffet');
});

it('Leitplanken: DB-Codes passieren, Unbekanntes wird verworfen, konferenz wandert in den Eventtyp', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    $verworfen = [];
    $ok = app(PlanningSessionService::class)->filterGenerationParams(
        ['occasion' => 'lunch', 'serviceform' => 'flying', 'saison' => 'herbst', 'eventtyp' => 'hochzeit'], $verworfen, $this->rootTeam);
    expect($ok)->toBe(['occasion' => 'lunch', 'serviceform' => 'flying', 'saison' => 'herbst', 'eventtyp' => 'hochzeit'])->and($verworfen)->toBe([]);

    $alt = app(PlanningSessionService::class)->filterGenerationParams(['occasion' => 'konferenz', 'serviceform' => 'gondel'], $verworfen, $this->rootTeam);
    expect($alt)->toBe(['eventtyp' => 'konferenz'])->and($verworfen)->toBe(['serviceform=gondel']);
});

it('Planung zeigt die DB-Auswahl und rehydriert eine Alt-Sitzung mit occasion=konferenz als Eventtyp', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Tagung', 'brief' => 'Lunch für 80']);
    DB::table('foodalchemist_planning_sessions')->where('id', $session->id)->update(['generation_params' => json_encode(['occasion' => 'konferenz', 'serviceform' => 'buffet'])]);

    Livewire::test(PlanungIndex::class)->call('oeffne', $session->id)
        ->assertSet('regler.gericht.eventtyp', 'konferenz')
        ->assertSet('regler.gericht.occasion', '')
        ->assertSet('regler.gericht.serviceform', 'buffet')
        ->assertSee('Konferenz/Tagung')->assertSee('Flying Service')->assertSee('Apéro/Empfang');
});

it('Sprachassistent bietet die DB-Codes an und prüft gegen sie', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    expect(VoiceCommandService::regelVokabular('serviceform'))->toHaveKey('stehempfang')
        ->and(VoiceCommandService::regelWertGueltig('occasion', 'dinner'))->toBeTrue()
        ->and(VoiceCommandService::regelWertGueltig('occasion', 'konferenz'))->toBeFalse()
        ->and(VoiceCommandService::regelWertGueltig('eventtyp', 'konferenz'))->toBeTrue();
});

it('Trockenlauf-Report schreibt nichts und meldet fehlende Codes', function () {
    VokabularCodes::grundbestandAnlegen($this->rootTeam->id);
    DB::table('foodalchemist_seasons')->where('team_id', $this->rootTeam->id)->where('name', 'Sommer')->update(['code' => null]);

    $this->artisan('foodalchemist:vokabular-codes')->expectsOutputToContain('Sommer → sommer')->assertSuccessful();
    expect(DB::table('foodalchemist_seasons')->where('team_id', $this->rootTeam->id)->where('name', 'Sommer')->value('code'))->toBeNull();

    $this->artisan('foodalchemist:vokabular-codes --apply')->assertSuccessful();
    expect(DB::table('foodalchemist_seasons')->where('team_id', $this->rootTeam->id)->where('name', 'Sommer')->value('code'))->toBe('sommer');
});
