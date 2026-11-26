<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Jobs\ConformanceCheckJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpStamm\GpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 6 — Bestand erreichen: Backfill-Command über den GP-Bestand +
 * Freigabe-Trigger (approval → Critic detect-only). Beides wirft den ConformanceCheckJob.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

$gp = fn ($team, string $name, string $status, bool $platzhalter = false) => FoodAlchemistGp::create([
    'team_id' => $team->id,
    'gp_key' => 'bf-' . mb_strtolower(str_replace(' ', '-', $name)) . '|t|t',
    'name' => $name,
    'status' => $status,
    'is_platzhalter' => $platzhalter,
]);

it('Freigabe-Trigger: GP auf approved setzen stößt den Critic an (detect)', function () use ($gp) {
    Queue::fake();
    $g = $gp($this->rootTeam, 'Xi', 'tentative');

    app(GpService::class)->setStatus($g, GpStatus::Approved);

    Queue::assertPushed(ConformanceCheckJob::class, fn ($j) => $j->artifactTyp === 'gp' && $j->artifactId === $g->id);
});

it('Freigabe-Trigger: Rückstufung/kein-Übergang stößt NICHTS an', function () use ($gp) {
    Queue::fake();
    $g = $gp($this->rootTeam, 'Ypsilon', 'approved');

    app(GpService::class)->setStatus($g, GpStatus::Tentative);   // approved → tentative: kein Check
    app(GpService::class)->setStatus($g, GpStatus::Tentative);   // kein Übergang: kein Check

    Queue::assertNotPushed(ConformanceCheckJob::class);
});
