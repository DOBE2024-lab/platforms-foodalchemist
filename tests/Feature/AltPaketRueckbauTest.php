<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Support\AltPaketRueckbau;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 3.1: Riegel vor dem Drop der Alt-Pakete. Bricht ab bei nicht gelöschtem Slot mit package_id und bei einem
 * Alt-Paket ohne Gegenstück (gleicher Name, kind=paket, gleiches Team). Gelöschte Zeilen zählen nicht.
 * Auf SQLite stehen Tabellen und Spalte noch (die Migration lässt sie dort stehen), darum lässt sich der Riegel hier prüfen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->altPaket = fn (string $name, $team = null, bool $geloescht = false) => DB::table('foodalchemist_packages')->insertGetId([
        'uuid' => (string) Str::uuid(), 'team_id' => ($team ?? $this->rootTeam)->id, 'name' => $name,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $geloescht ? now() : null,
    ]);
    $this->gegenstueck = fn (string $name, $team = null) => FoodAlchemistConcept::create(['team_id' => ($team ?? $this->rootTeam)->id, 'name' => $name, 'kind' => 'paket', 'status' => 'active']);
    $this->migration = fn () => require __DIR__ . '/../../database/migrations/2027_01_13_100000_drop_alt_pakete.php';
});

it('frei: keine Alt-Pakete, keine package_id', function () {
    expect(AltPaketRueckbau::befunde())->toBe([]);
    ($this->migration)()->up();   // SQLite: Riegel läuft, Strukturen bleiben stehen
    expect(DB::getSchemaBuilder()->hasTable('foodalchemist_packages'))->toBeTrue();
});

it('frei: Alt-Paket mit Gegenstück und gelöschter Slot mit package_id', function () {
    $alt = ($this->altPaket)('NOSE TO TAIL');
    ($this->gegenstueck)('NOSE TO TAIL');
    $concepts = app(ConceptService::class);
    $c = $concepts->create($this->rootTeam, ['name' => 'Menü']);
    $slot = $concepts->addSlot($this->rootTeam, $c->id, ['role' => 'Station']);
    DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->update(['package_id' => $alt, 'deleted_at' => now()]);

    expect(AltPaketRueckbau::befunde())->toBe([]);
});

it('Abbruch: nicht gelöschter Slot mit package_id', function () {
    $alt = ($this->altPaket)('FIN TO FORK');
    ($this->gegenstueck)('FIN TO FORK');
    $concepts = app(ConceptService::class);
    $c = $concepts->create($this->rootTeam, ['name' => 'Menü']);
    $slot = $concepts->addSlot($this->rootTeam, $c->id, ['role' => 'Station']);
    DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->update(['package_id' => $alt]);

    expect(AltPaketRueckbau::befunde())->toBe(["1 Konzept-Slots mit package_id (nicht gelöscht): #{$slot->id}"])
        ->and(fn () => ($this->migration)()->up())->toThrow(\RuntimeException::class, 'Alt-Pakete können nicht gelöscht werden');
});

it('Abbruch: Alt-Paket ohne Gegenstück (falscher Name, falsches Team oder nur ein Konzept); ein gelöschtes Alt-Paket zählt nicht', function () {
    $ohne = ($this->altPaket)('ROOT TO LEAF');
    ($this->gegenstueck)('ROOT TO LEAF', $this->childA);                                    // anderes Team
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'ROOT TO LEAF', 'kind' => 'concept']);   // keine Art Paket
    ($this->altPaket)('Weg', null, true);                                                    // gelöscht

    expect(AltPaketRueckbau::befunde())->toBe(["Alt-Paket #{$ohne} „ROOT TO LEAF“ (Team {$this->rootTeam->id}) hat kein Gegenstück als Konzept der Art Paket"]);
});
