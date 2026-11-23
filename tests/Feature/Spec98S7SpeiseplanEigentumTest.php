<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 3.3 S7 P0 · Die Speiseplan-Voll-Kaskade („Leere Zellen mit KI füllen“) läuft nur auf einem eigenen Plan.
 * Vorher legte ein Team auf einem geerbten (sichtbaren) Plan einen Lauf an, dessen Zellen danach alle am Riegel in
 * SpeiseplanService::addEintrag scheiterten. Jetzt wird früh abgewiesen, und es entsteht kein Lauf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
    $this->starte = fn ($team, $plan) => app(PlanningCascadeService::class)
        ->starteKaskade($team, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'speiseplan', 'owner_id' => (int) $plan->id]);
});

it('geerbter Plan: klare Meldung, kein Lauf, kein Zell-Job', function () {
    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Wochenplan Root', 'start_date' => '2026-08-03']);

    expect(fn () => ($this->starte)($this->childA, $plan))
        ->toThrow(RuntimeException::class, 'gehört einem anderen Team');
    expect(FoodAlchemistCascadeRun::where('team_id', $this->childA->id)->count())->toBe(0);
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);
});

it('fremder Plan (nicht sichtbar): wie bisher „nicht gefunden“, kein Lauf', function () {
    $plan = app(SpeiseplanService::class)->create($this->childB, ['name' => 'Wochenplan B', 'start_date' => '2026-08-03']);

    expect(fn () => ($this->starte)($this->childA, $plan))
        ->toThrow(RuntimeException::class, 'Speiseplan nicht gefunden');
    expect(FoodAlchemistCascadeRun::where('team_id', $this->childA->id)->count())->toBe(0);
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);
});

it('eigener Plan: Lauf und Zell-Jobs wie bisher', function () {
    $plan = app(SpeiseplanService::class)->create($this->childA, ['name' => 'Wochenplan A', 'start_date' => '2026-08-03']);

    $run = ($this->starte)($this->childA, $plan);

    expect($run->source_owner_type)->toBe('speiseplan')
        ->and($run->steps()->where('kind', 'gericht')->count())->toBeGreaterThan(0);
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class);
});
