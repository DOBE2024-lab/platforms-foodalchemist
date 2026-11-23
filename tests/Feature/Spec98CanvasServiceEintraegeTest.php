<?php

use Platform\FoodAlchemist\Services\Concepter\CanvasService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3b — Die Schreibwege des Boards (ManagesCanvas) liegen im CanvasService: Einträge löschen,
 * Häkchen und Verbindlich umschalten — immer nur Einträge DIESES Canvas (Spec 82 §7).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(CanvasService::class);
    $this->oben = $this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id);
    $this->unten = $this->svc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id);
});

it('schaltet Häkchen und Verbindlich an einem Eintrag dieses Canvas um und löscht ihn', function () {
    $e = $this->svc->addEntry($this->oben, 'welten', 'Levante', ['vegan' => false]);

    expect($this->svc->eintragMetaUmschalten($this->oben, $e->id, 'vegan'))->toBeTrue()
        ->and($e->refresh()->meta['vegan'])->toBeTrue();
    expect($this->svc->eintragVerbindlichUmschalten($this->oben, $e->id))->toBeTrue()
        ->and((bool) $e->refresh()->is_binding)->toBeTrue();
    expect($this->svc->eintragLoeschen($this->oben, $e->id))->toBeTrue()
        ->and($this->svc->eintragDesCanvas($this->oben, $e->id))->toBeNull();
});

it('erreicht nie einen Eintrag eines anderen Canvas', function () {
    $e = $this->svc->addEntry($this->oben, 'welten', 'Levante');

    expect($this->svc->eintragLoeschen($this->unten, $e->id))->toBeFalse()
        ->and($this->svc->eintragVerbindlichUmschalten($this->unten, $e->id))->toBeFalse()
        ->and($this->svc->eintragMetaUmschalten($this->unten, $e->id, 'vegan'))->toBeFalse();
    $e->refresh();
    expect((bool) $e->is_binding)->toBeFalse()
        ->and($e->meta['vegan'] ?? null)->toBeNull();
});

it('macht ein Skalar-Feld nur verbindlich, wenn es gefüllt ist', function () {
    expect($this->svc->skalarVerbindlichUmschalten($this->oben, 'kueche'))->toBeFalse();

    $this->svc->setSkalar($this->oben, 'kueche', 'Mediterran');
    expect($this->svc->skalarVerbindlichUmschalten($this->oben, 'kueche'))->toBeTrue()
        ->and((bool) $this->oben->entries()->where('field_key', 'kueche')->first()->is_binding)->toBeTrue();
});
