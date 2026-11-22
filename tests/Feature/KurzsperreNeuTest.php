<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel as ConcepterDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4d Nachtrag (Kurator-Review): Die Kurzsperre beim Löschen gibt nur frei, was sie selbst angelegt hat.
 * Sperren gehören der Person, nicht dem Tab. Holt ein zweiter Tab derselben Person die Sperre genau zwischen der
 * Prüfung (darfSchreiben) und dem Holen, darf ein fehlgeschlagenes Löschen diese Sperre nicht wegräumen.
 * sperren() meldet dafür `neu` (angelegt) — Verlängern oder ein eigener Vorläufer zählen nicht.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->svc = app(BearbeitungssperreService::class);
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);

    // Löschen schlägt fehl wie „noch in Foodbooks verwendet“ — der Datensatz bleibt, die Frage ist nur die Sperre.
    $this->app->instance(ConceptService::class, new class extends ConceptService
    {
        public function __construct() {}

        public function delete(...$args): void
        {
            throw new \RuntimeException('Noch in einem Foodbook verwendet.');
        }
    });
});

it('sperren() meldet neu nur beim Anlegen, nicht beim Verlängern und nicht bei fremder Sperre', function () {
    $ben = $this->makeUser($this->rootTeam, 'Ben');

    expect($this->svc->sperren('concept', 1, $this->anna->id, 'Anna')['neu'])->toBeTrue()
        ->and($this->svc->sperren('concept', 1, $this->anna->id, 'Anna')['neu'])->toBeFalse()
        ->and($this->svc->sperren('concept', 1, $ben->id, 'Ben'))->toMatchArray(['ok' => false, 'neu' => false]);
});

it('a) eigene Sperre aus dem anderen Tab: Löschen scheitert, Sperre bleibt', function () {
    $this->svc->sperren('concept', $this->concept->id, $this->anna->id, 'Anna');   // Tab B bearbeitet
    $this->actingAs($this->anna);

    Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $this->concept->id)->call('loeschen')
        ->assertDispatched('fa-saved', message: 'Noch in einem Foodbook verwendet.', type: 'error');

    expect(FoodAlchemistConcept::find($this->concept->id))->not->toBeNull()
        ->and($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeTrue();
});

it('b) Wettlauf: Tab B holt die Sperre zwischen Prüfung und Holen — scheitert das Löschen, bleibt sie stehen', function () {
    $echt = $this->svc;
    $anna = $this->anna;
    // Stellvertreter (der Service ist final): beim ersten sperren() kommt Tab B um Millisekunden zuvor.
    $this->app->instance(BearbeitungssperreService::class, new class($echt, $anna)
    {
        private bool $tabBWarSchneller = false;

        public function __construct(private BearbeitungssperreService $echt, private $anna) {}

        public function sperren(...$args): array
        {
            if (! $this->tabBWarSchneller) {
                $this->tabBWarSchneller = true;
                $this->echt->sperren('concept', $args[1], $this->anna->id, 'Anna (Tab B)');
            }

            return $this->echt->sperren(...$args);
        }

        public function __call(string $name, array $args)
        {
            return $this->echt->{$name}(...$args);
        }
    });
    $this->actingAs($this->anna);

    Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $this->concept->id)->call('loeschen')
        ->assertDispatched('fa-saved', message: 'Noch in einem Foodbook verwendet.', type: 'error');

    expect(FoodAlchemistConcept::find($this->concept->id))->not->toBeNull()
        ->and($echt->haelt('concept', $this->concept->id, $this->anna->id))->toBeTrue();
});

it('c) frisch geholte Sperre: Löschen scheitert, Sperre ist danach wieder frei', function () {
    $this->actingAs($this->anna);

    Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $this->concept->id)->call('loeschen')
        ->assertDispatched('fa-saved', message: 'Noch in einem Foodbook verwendet.', type: 'error');

    expect(FoodAlchemistConcept::find($this->concept->id))->not->toBeNull()
        ->and($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();
});
