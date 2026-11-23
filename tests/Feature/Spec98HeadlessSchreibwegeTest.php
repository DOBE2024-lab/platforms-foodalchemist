<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistAbgleichLog;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\GpAbgleich\GpAbgleichService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\LabNoteService;
use Platform\FoodAlchemist\Services\Plattform\OutletService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\ProductionStationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 1.3c — Tools schreiben nicht selbst in Models, sondern über Services (headless: dieselbe Regel
 * für UI und MCP). Diese Datei sichert die neuen Service-Schreibwege und das Verhalten der Tools,
 * die bisher keinen eigenen Test hatten (lab_notes.PUT/DELETE).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('LabNoteService: aktualisieren prüft Titel und Evidenz-Stufe, loeschen entfernt die Notiz', function () {
    $svc = app(LabNoteService::class);
    $note = $svc->create($this->rootTeam, ['title' => 'Alt', 'evidence_tier' => 'T3']);

    $svc->aktualisieren($note, ['title' => '  Neu  ', 'body' => '', 'evidence_tier' => 'T1', 'source_ref' => 'x:1']);
    $note->refresh();
    expect($note->title)->toBe('Neu')
        ->and($note->body)->toBeNull()
        ->and($note->evidence_tier)->toBe('T1')
        ->and($note->source_ref)->toBe('x:1');

    expect(fn () => $svc->aktualisieren($note, ['title' => ' ']))->toThrow(RuntimeException::class, 'Titel');
    expect(fn () => $svc->aktualisieren($note, ['evidence_tier' => 'T9']))->toThrow(RuntimeException::class, 'T0..T3');
    expect($note->refresh()->title)->toBe('Neu');

    $svc->loeschen($note);
    expect(FoodAlchemistLabNote::whereKey($note->id)->exists())->toBeFalse();
});

it('lab_notes.PUT und lab_notes.DELETE: eigenes Team ändert/löscht, Fehler bleiben Tool-Fehler', function () {
    $note = app(LabNoteService::class)->create($this->rootTeam, ['title' => 'Hypothese']);

    $put = $this->registry->get('foodalchemist.lab_notes.PUT')->execute(['id' => $note->id, 'title' => 'Bestätigt', 'evidence_tier' => 'T2'], $this->kontext);
    expect($put->success)->toBeTrue()
        ->and($put->data['title'])->toBe('Bestätigt')
        ->and($put->data['evidence_tier'])->toBe('T2');

    $falsch = $this->registry->get('foodalchemist.lab_notes.PUT')->execute(['id' => $note->id, 'evidence_tier' => 'T7'], $this->kontext);
    expect($falsch->success)->toBeFalse();
    $leer = $this->registry->get('foodalchemist.lab_notes.PUT')->execute(['id' => $note->id, 'title' => ''], $this->kontext);
    expect($leer->success)->toBeFalse()
        ->and($note->refresh()->evidence_tier)->toBe('T2');

    $fremd = app(LabNoteService::class)->create($this->childA, ['title' => 'Fremd']);
    expect($this->registry->get('foodalchemist.lab_notes.DELETE')->execute(['id' => $fremd->id], $this->kontext)->success)->toBeFalse()
        ->and(FoodAlchemistLabNote::whereKey($fremd->id)->exists())->toBeTrue();

    $del = $this->registry->get('foodalchemist.lab_notes.DELETE')->execute(['id' => $note->id], $this->kontext);
    expect($del->success)->toBeTrue()
        ->and(FoodAlchemistLabNote::whereKey($note->id)->exists())->toBeFalse();
});

it('OutletService::anlegen: Name Pflicht und eindeutig, Sortierung hinten an — Tool und Einstellungen gleich', function () {
    $svc = app(OutletService::class);
    $a = $svc->anlegen($this->rootTeam, '  Kantine  ');
    $b = $svc->anlegen($this->rootTeam, 'Bistro');
    expect($a->name)->toBe('Kantine')
        ->and((int) $b->sort_order)->toBeGreaterThan((int) $a->sort_order);
    expect(fn () => $svc->anlegen($this->rootTeam, 'Kantine'))->toThrow(RuntimeException::class);
    expect(fn () => $svc->anlegen($this->rootTeam, ' '))->toThrow(RuntimeException::class);

    $post = $this->registry->get('foodalchemist.outlets.POST')->execute(['name' => 'Event'], $this->kontext);
    expect($post->success)->toBeTrue()
        ->and((int) FoodAlchemistOutlet::find($post->data['id'])->sort_order)->toBeGreaterThan((int) $b->sort_order);
    expect($this->registry->get('foodalchemist.outlets.POST')->execute(['name' => 'Event'], $this->kontext)->success)->toBeFalse();
});

it('ProductionStationService::setzeSammelposten schreibt nur eigene Posten', function () {
    $posten = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'pass-t', 'name' => 'Pass', 'sort_order' => 1]);

    $neu = app(ProductionStationService::class)->setzeSammelposten($this->rootTeam, $posten, true);
    expect((bool) $neu->ist_sammelposten)->toBeTrue()
        ->and((bool) $posten->refresh()->ist_sammelposten)->toBeTrue();

    $geerbt = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'pass-g', 'name' => 'Pass G', 'sort_order' => 2]);
    expect(fn () => app(ProductionStationService::class)->setzeSammelposten($this->childA, $geerbt, true))->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect((bool) $geerbt->refresh()->ist_sammelposten)->toBeFalse();
});

it('GpService::setzeKochmedium setzt nur das Kochmedium-Flag', function () {
    $gp = $this->makeGp($this->rootTeam, 'Rapsöl: raffiniert');
    $gp->update(['piece_default_g' => 12]);

    app(GpService::class)->setzeKochmedium($this->rootTeam, $gp->id, true);
    $gp->refresh();
    expect((bool) $gp->is_kochmedium)->toBeTrue()
        ->and((float) $gp->piece_default_g)->toBe(12.0);
});

it('PlanningSessionService::setzeSuchbegriffe ersetzt nur den einen Scope', function () {
    $session = FoodAlchemistPlanningSession::create(['team_id' => $this->rootTeam->id, 'title' => 'Herbst', 'status' => 'divergenz', 'suchbegriffe' => ['gericht' => [['t' => 'Kürbis']]]]);

    app(PlanningSessionService::class)->setzeSuchbegriffe($this->rootTeam, $session->id, 'rezept', [['t' => 'Fond']]);
    expect($session->refresh()->suchbegriffe)->toBe(['gericht' => [['t' => 'Kürbis']], 'rezept' => [['t' => 'Fond']]]);
});

it('GpAbgleichService::markiereAusgefuehrt hält Aktion und GP am Protokoll fest', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zander: Filet, TK');
    $log = FoodAlchemistAbgleichLog::forceCreate([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => 1, 'aktion' => 'verknuepfen', 'modus' => 'vorschlag', 'ausgefuehrt' => false,
    ]);

    app(GpAbgleichService::class)->markiereAusgefuehrt($log, ['ausgefuehrt' => 'verknuepft', 'gp' => $gp]);
    $log->refresh();
    expect((bool) $log->ausgefuehrt)->toBeTrue()
        ->and($log->ausgefuehrt_aktion)->toBe('verknuepft')
        ->and((int) $log->ausgefuehrt_gp_id)->toBe($gp->id);
});

it('RecipeStepService (98 · 1.3e): Schritte headless anlegen, füllen, umsortieren, löschen; KI-Quelle je Ebene', function () {
    $svc = app(\Platform\FoodAlchemist\Services\RecipeStepService::class);
    $r = $this->makeRecipe($this->rootTeam, 'Rinderfond');
    $p = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::EBENE_PRODUKTION;

    $a = $svc->schrittAnlegen($r, $p);
    $b = $svc->schrittAnlegen($r, $p);
    expect([(int) $a->position, (int) $b->position])->toBe([1, 2]);

    expect($svc->schrittFeldSetzen($r, $p, $a->id, 'text', '  Knochen rösten. '))->toBeTrue();
    $svc->schrittFeldSetzen($r, $p, $b->id, 'text', 'Ansetzen.');
    $svc->schrittFeldSetzen($r, $p, $a->id, 'phase', '   ');
    expect($a->refresh()->text)->toBe('Knochen rösten.')->and($a->phase)->toBeNull();
    expect(fn () => $svc->schrittFeldSetzen($r, $p, $a->id, 'ebene', 'x'))->toThrow(InvalidArgumentException::class);

    $svc->reihenfolgeSetzen($r, $p, [$b->id, $a->id]);
    expect([(int) $b->refresh()->position, (int) $a->refresh()->position])->toBe([1, 2]);

    expect($svc->schrittLoeschen($r, $p, $b->id))->toBeTrue()
        ->and((int) $a->refresh()->position)->toBe(1)
        ->and($svc->schrittLoeschen($r, $p, $b->id))->toBeFalse();

    $svc->kiUebernehmen($r, [['text' => 'Neu.']], \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::EBENE_ANRICHTEN, 0.7);
    $r->refresh();
    expect($r->plating_source)->toBe('ki')
        ->and($r->preparation_source)->not->toBe('ki');
});
