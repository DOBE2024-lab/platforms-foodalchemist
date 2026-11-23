<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\LaufStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Hängende Kaskaden-Läufe (io 23.11.: Board pollte tagelang alle 3 s). Diagnose des Masters:
 *  - Fall 1 (Lauf 62): Schritt `running`, sein KI-Auftrag seit Tagen abgelaufen → failed mit Grund.
 *  - Fall 2 (Lauf 66): alle Schritte freigegeben, Anreicherung fertig, Lauf trotzdem `running` → neu bewerten.
 *  - Fall 3 (Läufe 69, 61, 38): Schritte done, Anreicherung `queued` ohne Job → nach 30 min failed.
 *  - Dazu: liegengebliebene Phase. Gegenfälle: offener KI-Auftrag mit Restlaufzeit und junge Schritte bleiben.
 * Der Poll (LaufStatus) ruft den Reaper ab 30 min Stillstand selbst, höchstens alle 5 min je Lauf, und taktet 3 s → 15 s → 60 s.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
    $this->session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Green & Tasty', 'brief' => 'Dill']);
    $this->lauf = fn (string $status = 'running') => FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id,
        'planning_session_id' => $this->session->id, 'scope' => 'rezept', 'status' => $status]);
    $this->schritt = fn ($run, array $a) => FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'label' => 'Crème: Dill-Schmand'] + $a);
    $this->alt = fn ($step, int $min = 45) => DB::table('foodalchemist_cascade_run_steps')->where('id', $step->id)->update(['updated_at' => now()->subMinutes($min)]);
    $this->auftrag = fn (string $key, string $status, $laeuftAb) => DB::table('foodalchemist_ki_auftraege')->insert(['uuid' => (string) Str::uuid(),
        'team_id' => $this->rootTeam->id, 'feature' => 'recipe.generator', 'messages_hash' => str_repeat('a', 64), 'messages' => '[]',
        'status' => $status, 'laeuft_ab_at' => $laeuftAb, 'lauf_key' => $key, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    $this->reaper = fn ($run) => app(PlanningCascadeService::class)->reapeVerwaisteSteps($this->rootTeam, (int) $run->id);
});

it('Fall 1: Schritt running mit abgelaufenem KI-Auftrag → failed mit Grund; offener Auftrag mit Restlaufzeit hält ihn', function () {
    $run = ($this->lauf)();
    $step = ($this->schritt)($run, ['status' => 'running', 'generator_run_id' => 'gen-62']);
    ($this->alt)($step, 60 * 24 * 10);
    ($this->auftrag)('generate-recipe:gen-62', 'offen', now()->addHours(3));

    expect(($this->reaper)($run))->toBe(0)->and($step->fresh()->status)->toBe('running');   // Gegenfall: wartet berechtigt

    DB::table('foodalchemist_ki_auftraege')->update(['status' => 'abgelaufen', 'laeuft_ab_at' => now()->subDays(8)]);
    expect(($this->reaper)($run))->toBe(1)
        ->and($step->fresh()->status)->toBe('failed')
        ->and($step->fresh()->error)->toContain('KI-Auftrag abgelaufen')
        ->and($run->fresh()->status)->toBe('failed');
});

it('Fall 1: ein offener, aber verstrichener Auftrag hält nicht mehr fest', function () {
    $run = ($this->lauf)();
    $step = ($this->schritt)($run, ['status' => 'running', 'generator_run_id' => 'gen-63']);
    ($this->alt)($step, 120);
    ($this->auftrag)('generate-recipe:gen-63', 'offen', now()->subMinute());

    expect(($this->reaper)($run))->toBe(1)->and($step->fresh()->error)->toContain('KI-Auftrag abgelaufen');
});

it('Lauf-Schlüssel exakt: der Auftrag eines REZEPTS mit derselben Nummer hält den Schritt nicht fest', function () {
    $run = ($this->lauf)();
    $step = ($this->schritt)($run, ['status' => 'running']);   // ref_id NULL wie Lauf 62
    ($this->alt)($step);
    // „enrich-recipe:{team}:{id}“ gehört zu Rezept {id}, nicht zu Schritt {id} — kein LIKE auf „…:{team}:{id}“
    ($this->auftrag)("enrich-recipe:{$this->rootTeam->id}:{$step->id}", 'offen', now()->addHours(3));
    ($this->auftrag)("sensorik-nachholen:{$this->rootTeam->id}:{$step->id}", 'offen', now()->addHours(3));

    expect(($this->reaper)($run))->toBe(1)->and($step->fresh()->status)->toBe('failed');
});

it('Fall 3: Anreicherung hängt auf queued ohne Job → nach 30 min failed, der Lauf wird neu bewertet; jung oder mit offenem Auftrag bleibt sie', function () {
    $run = ($this->lauf)();
    $step = ($this->schritt)($run, ['status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => 4711,
        'deferred' => ['enrich' => ['status' => 'queued', 'at' => now()->subMinutes(10)->toIso8601String()]]]);

    expect(($this->reaper)($run))->toBe(0)->and($step->fresh()->deferred['enrich']['status'])->toBe('queued');   // jung

    $step->update(['deferred' => ['enrich' => ['status' => 'queued', 'at' => now()->subMinutes(45)->toIso8601String()]]]);
    ($this->auftrag)("enrich-recipe:{$this->rootTeam->id}:4711", 'offen', now()->addHours(3));
    expect(($this->reaper)($run))->toBe(0)->and($step->fresh()->deferred['enrich']['status'])->toBe('queued');   // wartet auf Antwort

    DB::table('foodalchemist_ki_auftraege')->delete();
    expect(($this->reaper)($run))->toBeGreaterThanOrEqual(1)
        ->and($step->fresh()->deferred['enrich']['status'])->toBe('failed')
        ->and($step->fresh()->deferred['enrich']['error'])->toContain('Neu anreichern')
        ->and($run->fresh()->status)->not->toBe('running');
});

it('liegengebliebene Phase wird geräumt, eine junge bleibt', function () {
    $run = ($this->lauf)('review');
    $alt = ($this->schritt)($run, ['status' => 'done', 'phase' => 'Anreicherung läuft …', 'phase_at' => now()->subMinutes(45)]);
    $jung = ($this->schritt)($run, ['status' => 'done', 'phase' => 'KI-Fotos werden erzeugt …', 'phase_at' => now()->subMinutes(5)]);

    expect(($this->reaper)($run))->toBe(1)
        ->and($alt->fresh()->phase)->toBeNull()
        ->and($jung->fresh()->phase)->toBe('KI-Fotos werden erzeugt …');
});

it('Fall 2: Lauf running ohne offene Schritte (alles freigegeben, Anreicherung fertig) → neu bewertet auf done', function () {
    $run = ($this->lauf)();
    foreach (range(1, 3) as $i) {
        ($this->schritt)($run, ['status' => 'freigegeben', 'deferred' => ['enrich' => ['status' => 'done']]]);
    }

    expect(($this->reaper)($run))->toBe(1)->and($run->fresh()->status)->toBe('done');
    expect(($this->reaper)($run))->toBe(0);   // idempotent
});

it('Takt-Stufen nach Stillstand: Grundtakt, ab 2 min 15 s, ab 30 min 60 s', function () {
    expect(LaufStatus::taktFuer('3s', null))->toBe('3s')
        ->and(LaufStatus::taktFuer('3s', 0))->toBe('3s')
        ->and(LaufStatus::taktFuer('3s', 119))->toBe('3s')
        ->and(LaufStatus::taktFuer('3s', 120))->toBe('15s')
        ->and(LaufStatus::taktFuer('1500ms', 600))->toBe('15s')
        ->and(LaufStatus::taktFuer('3s', 1799))->toBe('15s')
        ->and(LaufStatus::taktFuer('3s', 1800))->toBe('60s');
});

it('Poll: frischer Lauf 3 s ohne Reaper; hängender Lauf 60 s, Hinweis am Board, Reaper höchstens alle 5 min', function () {
    $frisch = ($this->lauf)();
    ($this->schritt)($frisch, ['status' => 'running']);
    $this->partialMock(PlanningCascadeService::class, fn ($m) => $m->shouldReceive('reapeVerwaisteSteps')->once()->andReturn(0));

    $board = Livewire::test(LaufStatus::class, ['laufIds' => [$frisch->id], 'neueBeobachten' => true, 'takt' => '3s']);
    $board->call('pruefen')->assertSet('aktuellerTakt', '3s')->assertDontSeeHtml('data-planung-lauf-haengt');

    $haengt = ($this->lauf)();
    $step = ($this->schritt)($haengt, ['status' => 'running']);
    ($this->alt)($step, 50);
    DB::table('foodalchemist_cascade_runs')->where('id', $haengt->id)->update(['updated_at' => now()->subMinutes(50)]);
    $board = Livewire::test(LaufStatus::class, ['laufIds' => [$haengt->id], 'neueBeobachten' => true, 'takt' => '3s']);
    $board->call('pruefen')->call('pruefen')   // zweiter Poll innerhalb der 5 min: kein zweiter Reaper-Aufruf (once())
        ->assertSet('aktuellerTakt', '60s')
        ->assertSeeHtml('data-planung-lauf-haengt="' . $haengt->id . '"')
        ->assertSeeHtml('wire:poll.60s="pruefen"')
        ->assertSee('hängt seit');
});

it('Poll: ein Fehler im Reaper bricht den Status-Poll nicht, er landet im Log', function () {
    $run = ($this->lauf)();
    $step = ($this->schritt)($run, ['status' => 'running']);
    ($this->alt)($step, 50);
    DB::table('foodalchemist_cascade_runs')->where('id', $run->id)->update(['updated_at' => now()->subMinutes(50)]);
    $this->partialMock(PlanningCascadeService::class, fn ($m) => $m->shouldReceive('reapeVerwaisteSteps')->andThrow(new RuntimeException('kaputt')));
    Log::spy();

    Livewire::test(LaufStatus::class, ['laufIds' => [$run->id], 'neueBeobachten' => true, 'takt' => '3s'])->call('pruefen')->assertSet('aktuellerTakt', '60s');

    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'Kaskade-Reaper aus dem Poll') && str_contains((string) $m, 'kaputt'));
});

it('Mess-Test bleibt: ein Poll eines frischen Laufs braucht weniger als 10 Abfragen', function () {
    $run = ($this->lauf)();
    ($this->schritt)($run, ['status' => 'running']);
    $status = Livewire::test(LaufStatus::class, ['laufIds' => [$run->id]]);
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $status->call('pruefen');

    expect($n)->toBeLessThan(10);
});
