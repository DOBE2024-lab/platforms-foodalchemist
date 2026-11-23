<?php

use Livewire\Attributes\Reactive;
use Livewire\Features\SupportReactiveProps\SupportReactiveProps;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\AusBrief;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Charge 7 (#982, S2-E4) · io-Ausfall: Livewire 4 hängt an JEDE Anfrage der Planung einen `$commit` für jedes Kind mit
 * #[Reactive]-Props und hydriert diese Props aus dem, was der Parent beim Rendern übergeben hat. Fehlt eine Prop, kommt
 * null an. Der Board-AusBrief bekam kein `editorTab` (`string`) → TypeError → jede Planungs-Anfrage endete in 500.
 * Livewire::test rendert Kinder nur als Platzhalter und sieht das nicht — deshalb hier der Hydrate-Pfad direkt.
 * Statischer Wächter für alle Einbindungen: tests/Unit/ReaktivePropsEinbindungTest.php.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

afterEach(function () {
    SupportReactiveProps::$pendingChildParams = [];
});

/** Wie ein Parent-Roundtrip: reaktive Props aus den übergebenen Props hydrieren. */
function hydriereReaktiv(AusBrief $kind, array $uebergeben): void
{
    SupportReactiveProps::$pendingChildParams[$kind->getId()] = $uebergeben;
    foreach ($kind->getAttributes()->whereInstanceOf(Reactive::class) as $attribut) {
        $attribut->hydrate();
    }
}

it('Board-AusBrief übersteht einen Parent-Roundtrip ohne übergebenen Reiter (kein TypeError, keine 500)', function () {
    $kind = Livewire::test(AusBrief::class, ['ort' => 'board'])->instance();

    hydriereReaktiv($kind, ['ort' => 'board']);

    expect($kind->editorTab)->toBeNull()
        ->and($kind->sessionId)->toBeNull()
        ->and($kind->fbAusSession)->toBeNull();
});

it('Reiter-AusBrief übernimmt den Reiter des Parents beim Roundtrip', function () {
    $kind = Livewire::test(AusBrief::class, ['ort' => 'reiter', 'editorTab' => 'gericht'])->instance();

    hydriereReaktiv($kind, ['ort' => 'reiter', 'editorTab' => 'foodbook', 'sessionId' => null, 'fbAusSession' => null]);

    expect($kind->editorTab)->toBe('foodbook');
});
