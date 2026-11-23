<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Planung\KaskadenLauf;
use Platform\FoodAlchemist\Livewire\Planung\LaufStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.1d · Mess-Test 5 (Bericht 97): Abfragen je Poll der Planung. Vorher renderte jeder Poll die ganze `Planung/Index`
 * (Board, Lauf, Cockpit, Composer, Trends, Auswahllisten). Abnahme: < 10 je Poll. Jetzt pollt `LaufStatus` und meldet nur Änderungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
    $this->session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Herbstmenü', 'brief' => 'Kürbis, Wild']);
    $this->run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $this->session->id, 'scope' => 'concept', 'status' => 'running']);
    $this->steps = [];
    foreach ([['concept', 'done'], ['gericht', 'done'], ['gericht', 'running'], ['rezept', 'queued'], ['rezept', 'queued']] as $i => [$kind, $status]) {
        $this->steps[] = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->run->id, 'kind' => $kind,
            'status' => $status, 'label' => "Schritt {$i}", 'sort' => $i]);
    }
});

it('Mess-Test 5: ein Poll während eines laufenden Laufs braucht weniger als 10 Abfragen', function () {
    $status = Livewire::test(LaufStatus::class, ['laufIds' => [$this->run->id]]);
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $status->call('pruefen');

    expect($n)->toBeLessThan(10);
    $status->assertNotDispatched('planung.lauf-geaendert');
});

it('LaufStatus meldet eine Änderung genau einmal (Schritt fertig, Phase, Lauf-Ende), sonst schweigt er', function () {
    $status = Livewire::test(LaufStatus::class, ['laufIds' => [$this->run->id]]);

    $this->steps[2]->update(['status' => 'done']);
    $status->call('pruefen')->assertDispatched('planung.lauf-geaendert');
    $status->call('pruefen')->assertNotDispatched('planung.lauf-geaendert');

    $this->steps[3]->update(['phase' => 'Konformität wird geprüft …']);
    $status->call('pruefen')->assertDispatched('planung.lauf-geaendert');

    $this->run->update(['status' => 'review']);
    $status->call('pruefen')->assertDispatched('planung.lauf-geaendert');
});

it('Board: ein neu gestarteter Lauf des Teams zählt als Änderung; ohne beobachteten Lauf räumt die Planung auf', function () {
    $board = Livewire::test(LaufStatus::class, ['laufIds' => [$this->run->id], 'neueBeobachten' => true, 'takt' => '3s']);
    FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $this->session->id, 'scope' => 'gericht', 'status' => 'running']);
    $board->call('pruefen')->assertDispatched('planung.lauf-geaendert');

    Livewire::test(LaufStatus::class, ['laufIds' => []])->call('pruefen')->assertDispatched('planung.lauf-geaendert');
});

it('Watchdog: das Überschreiten der Schwelle zählt als Änderung, die Planung zeigt dann den Hinweis', function () {
    FoodAlchemistCascadeRunStep::where('cascade_run_id', $this->run->id)->update(['status' => 'queued']);
    $status = Livewire::test(LaufStatus::class, ['laufIds' => [$this->run->id]]);
    FoodAlchemistCascadeRun::where('id', $this->run->id)->update(['created_at' => now()->subSeconds(120)]);
    $status->call('pruefen')->assertDispatched('planung.lauf-geaendert');

    Livewire::test(KaskadenLauf::class, ['laufId' => $this->run->id])->set('laeuft', true)   // Spec 85 C1 PR-3: Lauf-Zustand im Kind
        ->dispatch('planung.lauf-geaendert')
        ->assertSet('laeuft', true)->assertNotSet('hinweis', null);
});

it('Spec 85 C1 PR-3: ein Poll ohne Änderung meldet dem Parent nichts (keine Render-Schleife Parent ↔ Kind)', function () {
    $kind = Livewire::test(KaskadenLauf::class, ['laufId' => $this->run->id])->assertSet('laeuft', true);   // läuft, frisch → kein Watchdog
    $kind->dispatch('planung.lauf-geaendert')
        ->assertSet('laeuft', true)
        ->assertNotDispatched('planung.lauf-status')
        ->assertNotDispatched('planung.meldung');
    $kind->dispatch('planung.lauf-geaendert')->assertNotDispatched('planung.lauf-status')->assertNotDispatched('planung.meldung');

    $this->run->update(['status' => 'review']);   // Gegenprobe: echte Änderung → genau dann melden
    $kind->dispatch('planung.lauf-geaendert')
        ->assertDispatched('planung.lauf-status', laufId: $this->run->id, laeuft: false)
        ->assertDispatched('planung.meldung', art: 'meldung');
});

it('Planung: das Status-Ereignis zieht den Lauf nach und lädt ihn je Anfrage nur einmal; der Fortschritt bindet LaufStatus statt wire:poll ein', function () {
    // Kinder-Komponenten rendert der Test nur als Platzhalter mit wire:key. Spec 85 C1 PR-3: die Planung bindet das Kind
    // KaskadenLauf ein, das Kind den LaufStatus.
    Livewire::test(PlanungIndex::class)->call('oeffne', $this->session->id)
        ->assertSeeHtml('wire:key="planung-lauf-' . $this->session->id . '-' . $this->run->id . '"')->assertDontSeeHtml('wire:poll.1500ms="pruefeLauf"');
    $planung = Livewire::test(KaskadenLauf::class, ['laufId' => $this->run->id])->set('laeuft', true);
    $planung->assertSeeHtml('wire:key="planung-lauf-status-' . $this->run->id . '"')->assertDontSeeHtml('wire:poll.1500ms="pruefeLauf"');

    $laufLaden = 0;
    DB::listen(function ($q) use (&$laufLaden) {
        if (str_contains($q->sql, 'from "foodalchemist_cascade_runs"') && str_contains($q->sql, '"foodalchemist_cascade_runs"."id" = ?')) {
            $laufLaden++;
        }
    });
    $this->run->update(['status' => 'review']);
    $planung->dispatch('planung.lauf-geaendert')->assertSet('laeuft', false)
        ->assertDispatched('planung.lauf-status', laufId: $this->run->id, laeuft: false);   // Parent-Kopie zieht nach

    expect($laufLaden)->toBe(1);
});

it('Daten nur für den aktiven Reiter: Composer-Daten erst auf dem Composer-Reiter', function () {
    $this->partialMock(PairingService::class, function ($m) {
        $m->shouldReceive('composerAnkerBrowse')->once()->andReturn(['items' => [], 'total' => 0, 'kategorien' => []]);
    });

    $planung = Livewire::test(PlanungIndex::class)->call('oeffne', $this->session->id)
        ->assertSet('editorTab', 'worker');                 // Planung mit Lauf öffnet auf „Fortschritt"
    $planung->set('composerTerm', 'Kür');                    // Render auf „Fortschritt": kein Composer-Picker
    $planung->set('editorTab', 'composer');                  // Reiterwechsel (Alpine $watch → $wire.set): genau ein Aufruf
});

it('Ampel: wird der Heartbeat des Workers alt, springt die Ampel beim nächsten Poll um (Board neu gezeichnet)', function () {
    \Illuminate\Support\Facades\Cache::put(\Platform\FoodAlchemist\Services\Plattform\WorkerHealthService::HEARTBEAT_KEY, now()->timestamp, 3600);
    $planung = Livewire::test(PlanungIndex::class)->assertSeeHtml('data-planung-worker-ampel="gesund"');
    $board = Livewire::test(LaufStatus::class, ['laufIds' => [$this->run->id], 'neueBeobachten' => true, 'takt' => '3s']);

    \Illuminate\Support\Facades\Cache::put(\Platform\FoodAlchemist\Services\Plattform\WorkerHealthService::HEARTBEAT_KEY, now()->subSeconds(120)->timestamp, 3600);
    $board->call('pruefen')->assertDispatched('planung.lauf-geaendert');
    $planung->dispatch('planung.lauf-geaendert')->assertSeeHtml('data-planung-worker-ampel="still"');
});

it('KI-Warten: ein offener externer KI-Auftrag eines Schritts beginnt oder endet → Änderung beim nächsten Poll', function () {
    $status = Livewire::test(LaufStatus::class, ['laufIds' => [$this->run->id]]);
    $uuid = (string) \Illuminate\Support\Str::uuid();
    DB::table('foodalchemist_ki_auftraege')->insert(['uuid' => $uuid, 'team_id' => $this->rootTeam->id, 'feature' => 'recipe.plan',
        'messages_hash' => hash('sha256', $uuid), 'messages' => '[]', 'status' => 'offen', 'laeuft_ab_at' => now()->addDay(),
        'lauf_key' => 'recipe-plan:' . $this->rootTeam->id . ':' . $this->steps[2]->id, 'created_at' => now(), 'updated_at' => now()]);
    $status->call('pruefen')->assertDispatched('planung.lauf-geaendert');
    $status->call('pruefen')->assertNotDispatched('planung.lauf-geaendert');

    DB::table('foodalchemist_ki_auftraege')->where('uuid', $uuid)->update(['status' => 'beantwortet']);
    $status->call('pruefen')->assertDispatched('planung.lauf-geaendert');
});
