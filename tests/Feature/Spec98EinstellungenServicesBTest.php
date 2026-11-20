<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Services\Einstellungen\ConcepterDimensionenService;
use Platform\FoodAlchemist\Services\Einstellungen\SchreibstileService;
use Platform\FoodAlchemist\Services\Einstellungen\WissenskategorienService;
use Platform\FoodAlchemist\Services\Einstellungen\WissenssteuerungService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3a (Teil B) — Einstellungs-Seiten Concepter-Dimensionen, Schreibstile, Wissenskategorien und Wissenssteuerung
 * schreiben über Services. Je Service: Happy Path, Besitzer-Guard (Kind-Team schreibt keine Zeile des Root-Teams) und
 * eine Eingabe-Meldung — dieselben Texte, die die Seiten vorher selbst gesetzt haben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
});

it('ConcepterDimensionenService: anlegen, umschalten, löschen; Kind-Team darf Root-Zeile nicht ändern', function () {
    $svc = app(ConcepterDimensionenService::class);

    expect($svc->anlegen($this->rootTeam, 'saisons', '  Sommer  '))->toBe('Sommer');
    $id = (int) DB::table('foodalchemist_seasons')->where('name', 'Sommer')->value('id');
    expect(DB::table('foodalchemist_seasons')->where('id', $id)->value('team_id'))->toBe($this->rootTeam->id);

    expect(fn () => $svc->anlegen($this->rootTeam, 'saisons', 'Sommer'))
        ->toThrow(InvalidArgumentException::class, '«Sommer» existiert schon in Saisons.');
    expect(fn () => $svc->anlegen($this->rootTeam, 'saisons', ' '))->toThrow(InvalidArgumentException::class, 'Name ist Pflicht.');
    expect(fn () => $svc->anlegen($this->rootTeam, 'gibtsnicht', 'X'))->toThrow(InvalidArgumentException::class, 'Name ist Pflicht.');

    expect(fn () => $svc->aktivUmschalten($this->childA, 'saisons', $id))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann ändern.');
    expect(fn () => $svc->loeschen($this->childA, 'saisons', $id))
        ->toThrow(GeerbtNurLesenException::class, '«Sommer» ist geerbt/Master — nur das Besitzer-Team kann löschen.');

    expect($svc->aktivUmschalten($this->rootTeam, 'saisons', $id))->toBeTrue()
        ->and((bool) DB::table('foodalchemist_seasons')->where('id', $id)->value('is_inactive'))->toBeTrue()
        ->and($svc->aktivUmschalten($this->rootTeam, 'saisons', 999999))->toBeFalse();

    expect($svc->loeschen($this->rootTeam, 'saisons', $id))->toBe('Sommer')
        ->and(DB::table('foodalchemist_seasons')->where('id', $id)->exists())->toBeFalse()
        ->and($svc->loeschen($this->rootTeam, 'saisons', $id))->toBeNull();

    // Servierform aus der WaWi (legacy_id) nie löschen
    $wawi = DB::table('foodalchemist_serving_forms')->insertGetId(['uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'code' => 'teller', 'label' => 'Teller', 'legacy_id' => 7, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $svc->loeschen($this->rootTeam, 'servierformen', $wawi))
        ->toThrow(InvalidArgumentException::class, '«Teller» kommt aus der WaWi (Master) — nur deaktivieren.');
});

it('SchreibstileService: anlegen, ändern, umschalten, löschen; Kind-Team darf Root-Stil nicht ändern', function () {
    $svc = app(SchreibstileService::class);

    $id = $svc->anlegen($this->rootTeam, ['name' => ' Klar ', 'sprach_duktus' => 'kurz', 'beispiele_md' => '', 'description' => '']);
    $zeile = DB::table('foodalchemist_writing_styles')->find($id);
    expect($zeile->name)->toBe('Klar')->and($zeile->slug)->toBe('klar')->and($zeile->beispiele_md)->toBeNull()
        ->and($zeile->team_id)->toBe($this->rootTeam->id);

    expect(fn () => $svc->anlegen($this->rootTeam, ['name' => 'Klar', 'sprach_duktus' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'Der Schreibstil «Klar» existiert schon.');
    expect(fn () => $svc->aktualisieren($this->rootTeam, $id, ['name' => 'Klar', 'sprach_duktus' => ' ']))
        ->toThrow(InvalidArgumentException::class, 'Name und Sprachstil sind Pflicht.');

    expect(fn () => $svc->aktualisieren($this->childA, $id, ['name' => 'Gekapert', 'sprach_duktus' => 'x']))
        ->toThrow(GeerbtNurLesenException::class, 'Dieser Schreibstil gehört einem anderen Team. Ändern kann ihn nur das Team, das ihn angelegt hat.');
    expect(fn () => $svc->aktivUmschalten($this->childA, $id))->toThrow(GeerbtNurLesenException::class);
    expect(fn () => $svc->loeschen($this->childA, $id))
        ->toThrow(GeerbtNurLesenException::class, 'Dieser Schreibstil gehört einem anderen Team. Löschen kann ihn nur das Team, das ihn angelegt hat.');

    $svc->aktualisieren($this->rootTeam, $id, ['name' => 'Klarer', 'sprach_duktus' => 'knapp', 'description' => 'd', 'sort_order' => '5']);
    $zeile = DB::table('foodalchemist_writing_styles')->find($id);
    expect($zeile->name)->toBe('Klarer')->and($zeile->sprach_duktus)->toBe('knapp')->and((int) $zeile->sort_order)->toBe(5);

    expect($svc->aktivUmschalten($this->rootTeam, $id))->toBeTrue()
        ->and($svc->loeschen($this->rootTeam, $id))->toBeTrue()
        ->and(DB::table('foodalchemist_writing_styles')->where('id', $id)->exists())->toBeFalse();
});

it('WissenskategorienService: anlegen, umbenennen, umschalten, löschen samt Docs; Kind-Team darf Root-Kategorie nicht ändern', function () {
    $svc = app(WissenskategorienService::class);

    $slug = $svc->anlegen($this->rootTeam, ['label' => 'Pairing Neu', 'description' => '']);
    expect($slug)->toBe('pairing_neu');
    $id = (int) DB::table('foodalchemist_knowledge_categories')->where('slug', $slug)->value('id');

    expect(fn () => $svc->anlegen($this->rootTeam, ['label' => 'Pairing Neu']))
        ->toThrow(InvalidArgumentException::class, 'Kategorie «pairing_neu» existiert schon.');
    expect(fn () => $svc->aktualisieren($this->rootTeam, $id, ['label' => ' ']))->toThrow(InvalidArgumentException::class, 'Name ist Pflicht.');

    expect(fn () => $svc->aktualisieren($this->childA, $id, ['label' => 'Gekapert']))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbtes/globales Vokabular — umbenennen kann nur das Besitzer-Team.');
    expect(fn () => $svc->aktivUmschalten($this->childA, $id))
        ->toThrow(GeerbtNurLesenException::class, 'Geerbtes/globales Vokabular — aktiv/inaktiv setzt nur das Besitzer-Team.');
    expect(fn () => $svc->loeschen($this->childA, $id))
        ->toThrow(GeerbtNurLesenException::class, '«pairing_neu» ist geerbtes/globales Vokabular — nur das Master-Team kann es löschen.');

    $svc->aktualisieren($this->rootTeam, $id, ['label' => 'Pairing', 'description' => 'x', 'sort_order' => 3]);
    $svc->aktivUmschalten($this->rootTeam, $id);
    $kat = DB::table('foodalchemist_knowledge_categories')->find($id);
    expect($kat->label)->toBe('Pairing')->and((bool) $kat->active)->toBeFalse();

    $doc = fn (?int $teamId, string $s) => DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'slug' => $s, 'title' => $s, 'category' => 'pairing_neu', 'content_md' => "# {$s}\n",
        'version' => 1, 'content_hash' => hash('sha256', $s), 'char_count' => 10, 'active' => 1, 'team_id' => $teamId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $eigen = $doc($this->rootTeam->id, 'eigen');
    $fremd = $doc($this->childB->id, 'fremd');

    // Fremde Docs bleiben → Kategorie bleibt; eigene Docs sind schon weg (wie bisher)
    expect(fn () => $svc->loeschen($this->rootTeam, $id))
        ->toThrow(NochVerwendetException::class, '1 Dok(s) gelöscht; 1 Dok(s) anderer Teams bleiben — Kategorie «pairing_neu» nicht entfernt.');
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $eigen)->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_knowledge_categories')->where('id', $id)->exists())->toBeTrue();

    DB::table('foodalchemist_knowledge_documents')->where('id', $fremd)->delete();
    expect($svc->loeschen($this->rootTeam, $id))->toBe(['label' => 'Pairing', 'docs' => 0])
        ->and(DB::table('foodalchemist_knowledge_categories')->where('id', $id)->exists())->toBeFalse()
        ->and($svc->loeschen($this->rootTeam, $id))->toBeNull();
});

it('WissenssteuerungService: anlegen/überschreiben, ändern, entfernen nur als Master-Team', function () {
    $svc = app(WissenssteuerungService::class);
    $eingabe = ['feature' => ' recipe.generator ', 'category' => 'pairing', 'mode' => 'always', 'max_docs' => '2', 'max_chars_per_doc' => ''];

    $svc->speichern($this->rootTeam, null, $eingabe);
    $svc->speichern($this->rootTeam, null, ['mode' => 'none'] + $eingabe);   // (feature, category) unique → überschreiben
    $zeilen = DB::table('foodalchemist_knowledge_routings')->where('feature', 'recipe.generator')->where('category', 'pairing')->get();
    expect($zeilen)->toHaveCount(1)->and($zeilen[0]->mode)->toBe('none')->and((int) $zeilen[0]->max_docs)->toBe(2)
        ->and($zeilen[0]->max_chars_per_doc)->toBeNull();
    $id = (int) $zeilen[0]->id;

    $svc->speichern($this->rootTeam, $id, ['mode' => 'discovery', 'max_docs' => '-3', 'max_chars_per_doc' => '500']);
    $r = DB::table('foodalchemist_knowledge_routings')->find($id);
    expect($r->mode)->toBe('discovery')->and((int) $r->max_docs)->toBe(0)->and((int) $r->max_chars_per_doc)->toBe(500);

    expect(fn () => $svc->speichern($this->rootTeam, null, ['mode' => 'kaputt']))
        ->toThrow(InvalidArgumentException::class, 'Bitte eine Verwendung wählen');
    expect(fn () => $svc->speichern($this->rootTeam, null, ['mode' => 'always', 'feature' => '', 'category' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'Bitte Arbeitsschritt und Kategorie angeben.');

    expect(fn () => $svc->speichern($this->childA, $id, ['mode' => 'always']))
        ->toThrow(GeerbtNurLesenException::class, 'Diese Einstellung gilt für alle Teams. Ändern darf sie nur das Master-Team.');
    expect(fn () => $svc->entfernen($this->childA, $id))->toThrow(GeerbtNurLesenException::class);
    expect(DB::table('foodalchemist_knowledge_routings')->where('id', $id)->exists())->toBeTrue();

    $svc->entfernen($this->rootTeam, $id);
    expect(DB::table('foodalchemist_knowledge_routings')->where('id', $id)->exists())->toBeFalse();
});
