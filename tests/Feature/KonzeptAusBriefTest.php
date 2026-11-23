<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser as ConcepterBrowser;
use Platform\FoodAlchemist\Livewire\Planung\Brief as PlanungBrief;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 2.6c: „Konzept aus Brief“ im Konzepter übergibt an die Planung (Kurator-Entscheid: kein neuer KI-Aufrufer).
 * Dialog mit dem Brief (Anlege-Muster 2.4c) → Brief in die Session → Planung, Reiter Konzept, Brief vorbelegt.
 * Plan und Go laufen dort wie bisher; im ganzen Weg entsteht kein KI-Aufruf und kein Job.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Http::fake();
    Queue::fake();
});

it('Knopf „Konzept aus Brief“ steht im Reiter Konzepte, nicht im Reiter Pakete', function () {
    Livewire::test(ConcepterBrowser::class)->assertSeeHtml('data-konzept-aus-brief')->assertSee('Konzept aus Brief')
        ->call('wechselTab', 'pakete')->assertDontSeeHtml('data-konzept-aus-brief');
});

it('Dialog: leerer Brief zeigt einen Feldfehler, legt nichts an und leitet nicht weiter', function () {
    Livewire::test(ConcepterBrowser::class)
        ->call('ausBrief')->assertDispatched('modal.open', name: 'fa-konzept-brief')
        ->set('briefText', '   ')->call('ausBriefWeiter')
        ->assertSet('briefFehler', 'Bitte einen Brief eingeben.')
        ->assertNoRedirect();

    expect(session()->has(ConcepterBrowser::BRIEF_SESSION))->toBeFalse()
        ->and(FoodAlchemistConcept::count())->toBe(0)
        ->and(FoodAlchemistPlanningSession::count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('mit Brief: Weiterleitung in die Planung (Reiter Konzept), dort steht der Brief im Feld, danach ist die Session leer', function () {
    $brief = 'Sommerfest für 120 Gäste, mediterran, Buffet';
    Livewire::test(ConcepterBrowser::class)
        ->call('ausBrief')->set('briefText', $brief)->call('ausBriefWeiter')
        ->assertSet('briefFehler', null)
        ->assertRedirect(route('foodalchemist.planung.index', ['tab' => 'concept']));
    expect(session(ConcepterBrowser::BRIEF_SESSION))->toBe($brief);   // in der Session, nicht in der URL

    // Planung\Index startet die freie Konzept-Planung (Reiter Konzept) …
    $p = Livewire::withQueryParams(['tab' => 'concept'])->test(PlanungIndex::class)
        ->assertSet('editorTab', 'concept');
    $sessionId = FoodAlchemistPlanningSession::sole()->id;
    expect(FoodAlchemistPlanningSession::sole()->created_via)->toBe('cockpit_frei')   // derselbe freie Konzept-Start wie in der Planung
        ->and($p->get('sessionId'))->toBe($sessionId)
        ->and(FoodAlchemistConcept::count())->toBe(0);   // noch kein Konzept: Plan und Go macht erst die Planung

    // … das Kind Planung\Brief belegt das Briefing vor und leert die Session (einmalig gelesen)
    session()->put(ConcepterBrowser::BRIEF_SESSION, $brief);   // unabhängig davon, ob das Kind oben schon mitgerendert wurde
    Livewire::withQueryParams(['tab' => 'concept'])->test(PlanungBrief::class, ['sessionId' => $sessionId])
        ->assertSet('eingabe.concept.brief', $brief);
    expect(session()->has(ConcepterBrowser::BRIEF_SESSION))->toBeFalse();
    Http::assertNothingSent();
    Queue::assertNothingPushed();

    // erneutes Öffnen ohne Brief in der Session: nichts wird vorbelegt, keine weitere Planung
    Livewire::withQueryParams(['tab' => 'concept'])->test(PlanungIndex::class);
    Livewire::withQueryParams(['tab' => 'concept'])->test(PlanungBrief::class, ['sessionId' => $sessionId])->assertSet('eingabe.concept.brief', '');
    expect(FoodAlchemistPlanningSession::count())->toBe(1);
});
