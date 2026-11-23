<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Planung\Brief;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Briefing mitspeichern“ (Dominique/Kurator 23.11.2026): „Planung speichern“ sichert je Reiter Titel, Brief, Kreativ-Modus
 * und Leitplanken (entwurf_json), session.brief = Brief des offenen Reiters, Name der Planung wieder editierbar mit
 * Leer-Meldung am Feld, Hinweis „ungespeichert“; Entwurf bleibt nach dem Go; MCP Sessions-GET liefert ihn mit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(PlanningSessionService::class);
    $this->session = $this->svc->create($this->rootTeam, ['title' => 'Sommerfest', 'brief' => 'alt']);
});

it('Service: speichert den Entwurf gesäubert und setzt session.brief auf den offenen Reiter', function () {
    $this->svc->speichereEntwurf($this->rootTeam, $this->session->id, [
        'gericht' => ['titel' => 'Grillteller', 'brief' => 'Sommerlich, leicht', 'creative_mode' => 'hybrid',
            'regler' => ['pax' => '80', 'diaet_hart' => ['vegan'], 'Böse Taste' => 'x', 'tief' => ['a' => ['b' => 1]]]],
        'concept' => ['titel' => '', 'brief' => 'Buffet', 'creative_mode' => 'quatsch', 'regler' => []],
        'fremd' => ['titel' => 'x'],
    ], 'gericht');

    $s = FoodAlchemistPlanningSession::find($this->session->id);
    expect($s->brief)->toBe('Sommerlich, leicht')
        ->and($s->entwurf_json['gericht']['regler'])->toBe(['pax' => '80', 'diaet_hart' => ['vegan']])
        ->and($s->entwurf_json['gericht']['creative_mode'])->toBe('hybrid')
        ->and($s->entwurf_json['concept']['creative_mode'])->toBe('voll_kreativ')
        ->and($s->entwurf_json)->not->toHaveKey('fremd')
        ->and($s->entwurf_json)->toHaveKey('gespeichert_at');
});

it('Service: fremde (geerbte) Planung wird abgewiesen', function () {
    expect(fn () => $this->svc->speichereEntwurf($this->childA, $this->session->id, ['rezept' => ['brief' => 'x']]))
        ->toThrow(RuntimeException::class);
});

it('Brief: „planung.speichern“ sichert alle drei Reiter; eine andere Planung im Event schreibt nichts', function () {
    $brief = Livewire::test(Brief::class, ['sessionId' => $this->session->id])
        ->set('eingabe.rezept.brief', 'Fond für den Grill')
        ->set('eingabe.gericht.titel', 'Grillteller')
        ->set('eingabe.gericht.brief', 'Sommerlich')
        ->set('regler.gericht.pax', '80')
        ->assertDispatched('planung.ungespeichert');

    $brief->dispatch('planung.speichern', sessionId: $this->session->id + 999, scope: 'gericht');
    expect(FoodAlchemistPlanningSession::find($this->session->id)->entwurf_json)->toBeNull();

    $brief->dispatch('planung.speichern', sessionId: $this->session->id, scope: 'gericht')
        ->assertDispatched('planung.entwurf-gespeichert');
    $e = FoodAlchemistPlanningSession::find($this->session->id)->entwurf_json;
    expect($e['rezept']['brief'])->toBe('Fond für den Grill')
        ->and($e['gericht']['titel'])->toBe('Grillteller')
        ->and($e['gericht']['regler']['pax'])->toBe('80')
        ->and($e)->toHaveKeys(['rezept', 'gericht', 'concept'])
        ->and(FoodAlchemistPlanningSession::find($this->session->id)->brief)->toBe('Sommerlich');
});

it('Brief: der gespeicherte Entwurf kommt beim Öffnen zurück (Texte und Leitplanken)', function () {
    $this->svc->speichereEntwurf($this->rootTeam, $this->session->id, [
        'gericht' => ['titel' => 'Grillteller', 'brief' => 'Sommerlich', 'creative_mode' => 'hybrid', 'regler' => ['pax' => '80', 'unbekannt' => 'x']],
    ]);

    Livewire::test(Brief::class, ['sessionId' => $this->session->id])
        ->assertSet('eingabe.gericht.titel', 'Grillteller')
        ->assertSet('eingabe.gericht.brief', 'Sommerlich')
        ->assertSet('eingabe.gericht.creative_mode', 'hybrid')
        ->assertSet('regler.gericht.pax', '80')
        ->assertSet('regler.gericht.voll_anreichern', true)        // fehlende Schlüssel aus dem Standardsatz
        ->assertNotSet('regler.gericht.unbekannt', 'x');
});

it('Brief: nach einem späteren Go gelten die Leitplanken des Go, die Texte des Entwurfs bleiben', function () {
    $this->svc->speichereEntwurf($this->rootTeam, $this->session->id, [
        'gericht' => ['titel' => 'Grillteller', 'brief' => 'Sommerlich', 'creative_mode' => 'hybrid', 'regler' => ['pax' => '80']],
    ]);
    $this->travel(5)->minutes();
    FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'done', 'planning_session_id' => $this->session->id]);

    Livewire::test(Brief::class, ['sessionId' => $this->session->id])
        ->assertSet('eingabe.gericht.brief', 'Sommerlich')
        ->assertNotSet('regler.gericht.pax', '80');
});

it('Index: Name der Planung editierbar, Leer-Meldung am Feld, „ungespeichert“ bis zur Bestätigung, Speichern löst das Event aus', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->assertSeeHtml('id="planung-name"')
        ->assertSet('ungespeichert', false)
        ->set('form.title', '')
        ->assertSet('ungespeichert', true)
        ->call('speichern')
        ->assertHasErrors(['form.title'])
        ->assertSeeHtml('id="planung-name-fehler"')
        ->set('form.title', 'Sommerfest 2027')
        ->call('speichern')
        ->assertHasNoErrors()
        ->assertDispatched('planung.speichern')
        ->assertSet('ungespeichert', true)                         // bis das Kind den Entwurf bestätigt
        ->dispatch('planung.entwurf-gespeichert')
        ->assertSet('ungespeichert', false);

    expect(FoodAlchemistPlanningSession::find($this->session->id)->title)->toBe('Sommerfest 2027');
});

it('MCP: planung_session.GET liefert den Entwurf mit', function () {
    $this->svc->speichereEntwurf($this->rootTeam, $this->session->id, ['concept' => ['brief' => 'Buffet', 'regler' => ['pax' => '120']]]);
    $r = app(ToolRegistry::class)->get('foodalchemist.planung_session.GET')
        ->execute(['id' => $this->session->id], new ToolContext($this->user, $this->rootTeam));

    expect($r->success)->toBeTrue()
        ->and($r->data['session']['entwurf']['concept']['brief'])->toBe('Buffet')
        ->and($r->data['session']['entwurf']['concept']['regler']['pax'])->toBe('120');
});
