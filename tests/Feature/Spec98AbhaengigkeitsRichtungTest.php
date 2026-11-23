<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Events\AromenprofileVeraltet;
use Platform\FoodAlchemist\Events\GerichtePreisVeraltet;
use Platform\FoodAlchemist\Events\KaskadenSchrittAbgeschlossen;
use Platform\FoodAlchemist\Events\RezeptKostenGeaendert;
use Platform\FoodAlchemist\Events\TeamKostenGeaendert;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\Kaskade\KaskadenSchritt;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\AbhaengigkeitsGraph;
use Platform\FoodAlchemist\Tests\Support\BaselineKlinke;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.4: Abhängigkeitsrichtung Basis → Rezept → Concepter → Ausgabe (Foodbook) → Orchestrierung (Planung, Preis-Kaskade),
 * Schichten und Zuordnung in {@see AbhaengigkeitsGraph}. Aufgelöst:
 *  - Kreis PlanningCascadeService ↔ RecipeDependencyWorkflowService (und ↔ IdeenService/ConceptVorgabenService): der Step-Status
 *    liegt in KaskadenSchritt (Extraktion, kein Event: synchroner Schreiber mit Lauf-Sperre, Kurator 22.11.); die Folge eines
 *    abgeschlossenen Steps hängt am synchronen Event KaskadenSchrittAbgeschlossen.
 *  - Rezept → Preisweitergabe (RecipeRecompute, Darreichung → PricingCascade/PaketService) über synchrone Events.
 * 3.3 S7: große Kreis-Komponente 112 → 12 (Rest Wissen/Pairing), Rückkanten 2 → 0 (P5). Mit K2 (P6) sind GP-Stamm, Lieferanten,
 * Preise, Lager/Inventur, Wissen/KI und Matching der Basis zugeordnet; die dabei sichtbaren 6 Rückkanten stehen als Bestand in der
 * Baseline, die Abbau-Liste in {@see AbhaengigkeitsGraph}. Die Klinken halten den Stand.
 */
function richtungSrc(): string
{
    return dirname(__DIR__, 2).'/src';
}

it('Scanner: zählt ::class, statische Aufrufe, Typ-Hinweise und new; Kommentare nicht', function () {
    $bekannt = ['A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true, 'F' => true];
    $code = <<<'PHP'
        <?php
        // app(F::class) im Kommentar zählt nicht
        /** {@see F::x()} auch nicht */
        class X { public function __construct(private B $b) {}
            public function f() { app(A::class)->g(); C::h(); new \Foo\D(); $x = 1; } // E::class am Zeilenende nicht
        }
        PHP;

    expect(AbhaengigkeitsGraph::nutzt($code, $bekannt, 'X'))->toBe(['A', 'B', 'C', 'D']);
});

it('Scanner: findet Kreise (Tarjan), auch über mehrere Klassen', function () {
    $kreise = AbhaengigkeitsGraph::kreise(['A' => ['B'], 'B' => ['C'], 'C' => ['A'], 'D' => ['A'], 'E' => ['E']]);

    expect($kreise)->toBe([['A', 'B', 'C']]);
});

it('Kreis-Test: Planung, Rezept-Workflow, Ideen und Concept-Vorgaben liegen in keinem gemeinsamen Kreis', function () {
    $kanten = AbhaengigkeitsGraph::kanten(richtungSrc());
    $benannt = ['PlanningCascadeService', 'RecipeDependencyWorkflowService', 'IdeenService', 'ConceptVorgabenService'];

    foreach (AbhaengigkeitsGraph::kreise($kanten) as $kreis) {
        expect(count(array_intersect($benannt, $kreis)))->toBeLessThan(2, 'Kreis: '.implode(' → ', array_intersect($benannt, $kreis)));
    }
    expect($kanten['RecipeDependencyWorkflowService'])->not->toContain('PlanningCascadeService')
        ->and($kanten['ConceptVorgabenService'])->not->toContain('PlanningCascadeService')
        ->and($kanten['KaskadenSchritt'])->not->toContain('PlanningCascadeService');
});

it('Kreis-Test: Rezept-Neuberechnung und Darreichung rufen keine Concepter-, Ausgabe- oder Orchestrierungs-Dienste', function () {
    $kanten = AbhaengigkeitsGraph::kanten(richtungSrc());

    foreach (['RecipeRecomputeService', 'DarreichungService'] as $rezept) {
        $oben = array_values(array_filter($kanten[$rezept], fn ($b) => (AbhaengigkeitsGraph::schicht($b) ?? 0) > 1));
        expect($oben)->toBe([], "{$rezept} → ".implode(', ', $oben));
    }
});

it('Klinke: Rückkanten gegen die Schichtrichtung und Größe der Kreis-Komponente steigen nicht', function () {
    $ist = AbhaengigkeitsGraph::zaehlen(richtungSrc());
    if (getenv('FA_RICHTUNG_BASELINE_SCHREIBEN') === '1') {
        BaselineKlinke::schreiben(AbhaengigkeitsGraph::baselinePfad(), $ist);
        $this->markTestSkipped('Stand neu eingefroren.');
    }

    expect(BaselineKlinke::schlechterAls($ist, BaselineKlinke::lesen(AbhaengigkeitsGraph::baselinePfad())))->toBe([]);

    $steigt = BaselineKlinke::steigtGegenueberGit(AbhaengigkeitsGraph::baselinePfad());
    if ($steigt !== null) {
        expect($steigt)->toBe([], 'Baseline darf nur sinken');
    }
});

it('Listener sind registriert: ohne Provider-Eintrag stünde die Preisweitergabe still', function () {
    foreach ([RezeptKostenGeaendert::class, TeamKostenGeaendert::class, GerichtePreisVeraltet::class, KaskadenSchrittAbgeschlossen::class, AromenprofileVeraltet::class] as $event) {
        expect(Event::hasListeners($event))->toBeTrue("kein Listener für {$event}");
    }
});

describe('Preisweitergabe per Event', function () {
    beforeEach(function () {
        $this->seedTeamHierarchy();
        $this->actingAs($this->makeUser($this->rootTeam));
        $this->gericht = $this->makeRecipe($this->rootTeam, 'Rinderroulade', ['is_sales_recipe' => true]);
        $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Hochzeit']);
        $this->concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü', 'ek_per_person_cache' => 99.99,
            'offer_id' => $this->angebot->id]);
        FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $this->concept->id, 'type' => 'gericht',
            'sales_recipe_id' => $this->gericht->id, 'position' => 0]);
        FoodAlchemistAngebot::whereKey($this->angebot->id)->update(['status' => 'anfrage', 'price_mode' => 'auto', 'price_calculated_at' => null]);
    });

    it('Rezept-Neuberechnung aktualisiert Concept- und Angebotspreis im selben Aufruf', function () {
        app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);

        expect((float) $this->concept->fresh()->ek_per_person_cache)->not->toBe(99.99)
            ->and($this->angebot->fresh()->price_calculated_at)->not->toBeNull();
    });

    it('Wirft die Preis-Kaskade, verhält sich der Aufrufer wie vorher: Warnung im Log, Rezept-Neuberechnung bleibt', function () {
        app()->instance(PricingCascadeService::class, new class extends PricingCascadeService
        {
            public function __construct() {}

            public function recomputeRecipes(array $recipeIds): array
            {
                throw new RuntimeException('kaputt');
            }
        });
        Log::spy();

        app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'Preis-Kaskade für Rezept') && str_contains((string) $m, 'kaputt'));
        expect((float) $this->concept->fresh()->ek_per_person_cache)->toBe(99.99);
    });
});

describe('KaskadenSchritt', function () {
    beforeEach(function () {
        $this->seedTeamHierarchy();
        $this->run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running']);
        $this->step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->run->id,
            'kind' => 'rezept', 'status' => 'running']);
    });

    it('markStepDone setzt Step und Lauf wie vorher und meldet den Abschluss synchron', function () {
        Event::fake([KaskadenSchrittAbgeschlossen::class]);
        $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');

        app(PlanningCascadeService::class)->markStepDone($this->step->id, 'recipe', $rezept->id);   // Fassade

        expect($this->step->fresh()->status)->toBe('done')
            ->and($this->step->fresh()->label)->toBe('Kalbsfond')
            ->and($this->run->fresh()->status)->toBe('review');
        Event::assertDispatched(KaskadenSchrittAbgeschlossen::class, fn ($e) => $e->step->id === $this->step->id);
    });

    it('markStepFailed und setzePhase schreiben wie vorher; ein abgebrochener Lauf meldet keinen Abschluss', function () {
        Event::fake([KaskadenSchrittAbgeschlossen::class]);
        $schritt = app(KaskadenSchritt::class);

        $schritt->setzePhase($this->step->id, 'Komponenten werden geplant …');
        expect($this->step->fresh()->phase)->toBe('Komponenten werden geplant …');

        $schritt->markStepFailed($this->step->id, 'Bestands-Basisrezept nicht mehr vorhanden.');
        expect($this->step->fresh()->status)->toBe('failed')->and($this->run->fresh()->status)->toBe('failed');
        Event::assertDispatchedTimes(KaskadenSchrittAbgeschlossen::class, 1);

        $this->run->update(['params' => [KaskadenSchritt::ABBRUCH_KEY => now()->toIso8601String()]]);
        $schritt->markStepDone($this->step->id, 'recipe', 1);
        Event::assertDispatchedTimes(KaskadenSchrittAbgeschlossen::class, 1);
        expect(PlanningCascadeService::ABBRUCH_KEY)->toBe(KaskadenSchritt::ABBRUCH_KEY)
            ->and(app(PlanningCascadeService::class)->istAbgebrochen($this->run->id))->toBeTrue();
    });
});
