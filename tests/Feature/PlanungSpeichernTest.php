<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Planung speichern“ (Planung/Index::speichern): ein leerer Titel warf eine ungefangene RuntimeException aus
 * PlanningSessionService::update. Jetzt: Meldung an form.title und neben dem Knopf, nichts geschrieben. Und: speichern
 * schreibt nie das Formular einer anderen Planung (Formular nach Wechsel nicht geladen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $svc = app(PlanningSessionService::class);
    $this->a = $svc->create($this->rootTeam, ['title' => 'Sommerfest', 'brief' => 'Brief A']);
    $this->b = $svc->create($this->rootTeam, ['title' => 'Weihnachtsfeier', 'brief' => 'Brief B']);
});

it('leerer Titel: Feldfehler und Meldung statt Exception, nichts geschrieben', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->a->id)
        ->set('form.title', '   ')
        ->set('form.brief', 'neu')
        ->call('speichern')
        ->assertHasErrors(['form.title'])
        ->assertSet('meldung', null)
        ->assertSet('fehler', 'Titel darf nicht leer sein.');

    expect(FoodAlchemistPlanningSession::find($this->a->id)->title)->toBe('Sommerfest')
        ->and(FoodAlchemistPlanningSession::find($this->a->id)->brief)->toBe('Brief A');
});

it('nach Wechsel ohne geladenes Formular schreibt speichern nicht die andere Planung', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->a->id)
        ->call('waehle', $this->b->id)
        ->call('speichern')
        ->assertSet('meldung', null)
        ->assertNotSet('fehler', null);

    expect(FoodAlchemistPlanningSession::find($this->b->id)->title)->toBe('Weihnachtsfeier')
        ->and(FoodAlchemistPlanningSession::find($this->b->id)->brief)->toBe('Brief B');
});

it('normaler Fall speichert wie bisher', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->a->id)
        ->set('form.brief', 'Brief A neu')
        ->call('speichern')
        ->assertHasNoErrors()
        ->assertSet('meldung', 'Gespeichert.');

    expect(FoodAlchemistPlanningSession::find($this->a->id)->brief)->toBe('Brief A neu');
});
