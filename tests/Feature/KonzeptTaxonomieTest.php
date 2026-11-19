<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKlasse;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Konzept-Taxonomie (Service): Baum-Vertrag (ancestors/has_children) und D1-Isolation.
 * Der Einstellungs-Schirm `Settings/KonzeptTaxonomie` ist entfallen (98 · 1.2a, toter Code).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ConceptService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('categoriesFlat/klassenFlat liefern Tiefe, Vorfahren-Kette und Kinder-Flag (Tree-Vertrag)', function () {
    $vor = $this->svc->createCategory($this->rootTeam, 'Buffets');
    $this->svc->createCategory($this->rootTeam, 'Lunchbuffet', $vor->id);

    $flat = collect($this->svc->categoriesFlat($this->rootTeam));
    $eltern = $flat->firstWhere('name', 'Buffets');
    $kind = $flat->firstWhere('name', 'Lunchbuffet');

    expect($eltern['depth'])->toBe(0)
        ->and($eltern['has_children'])->toBeTrue()
        ->and($eltern['ancestors'])->toBe([])
        ->and($kind['depth'])->toBe(1)
        ->and($kind['has_children'])->toBeFalse()
        ->and($kind['ancestors'])->toBe([$eltern['id']]);
});

it('D1: Geschwister-Team sieht fremde Kategorie/Klasse nicht; Eltern-Katalog schon', function () {
    // Root legt Katalog an
    $rootKat = $this->svc->createCategory($this->rootTeam, 'Root-Linie');
    $this->svc->createKlasse($this->rootTeam, 'Root-Klasse');
    // Kind A legt eigene an
    $eigeneKat = $this->svc->createCategory($this->childA, 'Nur-A-Kategorie');
    $this->svc->createKlasse($this->childA, 'Nur-A-Klasse');

    $aKat = collect($this->svc->categoriesFlat($this->childA))->pluck('name');
    $bKat = collect($this->svc->categoriesFlat($this->childB))->pluck('name');
    $bKlasse = collect($this->svc->klassenFlat($this->childB))->pluck('name');

    expect($aKat)->toContain('Root-Linie')->toContain('Nur-A-Kategorie')   // eigene + geerbt
        ->and($bKat)->toContain('Root-Linie')->not->toContain('Nur-A-Kategorie') // Geschwister blind
        ->and($bKlasse)->toContain('Root-Klasse')->not->toContain('Nur-A-Klasse');
});

it('verweigert Pflege geerbter Knoten (D1-Guard)', function () {
    $rootKat = $this->svc->createCategory($this->rootTeam, 'Root-Linie');
    $rootKlasse = $this->svc->createKlasse($this->rootTeam, 'Root-Klasse');

    expect(fn () => $this->svc->renameCategory($this->childA, $rootKat->id, 'Gekapert'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->svc->renameKlasse($this->childA, $rootKlasse->id, 'Gekapert'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});
