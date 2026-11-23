<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-Politur: Verwendungsnachweis (Wo verwendet?), Park-Flow Menge-bei-Einfügen,
 * Sektor-Eignung-Pflege am Concept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->concepts = app(ConceptService::class);
    $this->foodbooks = app(FoodbookService::class);

    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Green Power',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $this->paket = $this->concepts->createPaket($this->rootTeam, ['name' => 'Salad Wall']);   // 98 · 2.6c: Paket = kind=paket-Concept
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $this->paket->id]);
});

it('Wo verwendet?: Concept → Foodbooks, die es referenzieren', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Portfolio 2026']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['name' => 'Buffets']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $treffer = $this->concepts->verwendetInFoodbooks($this->rootTeam, $this->concept->id);

    expect($treffer)->toHaveCount(1)
        ->and($treffer->first()->label)->toBe('Portfolio 2026');

    // Anderes Concept ist in keinem Foodbook.
    $leer = $this->concepts->create($this->rootTeam, ['name' => 'Ungenutzt']);
    expect($this->concepts->verwendetInFoodbooks($this->rootTeam, $leer->id))->toHaveCount(0);
});

it('DetailPanel rendert „Wo verwendet?" für ein ausgewähltes Paket', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->assertSee('Wo verwendet')
        ->assertSee('Grill-Buffet');                                  // das nutzende Concept
});

it('Sektor-Eignung: zuweisen, reaktivieren, entfernen', function () {
    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'kita_schule');
    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'care');
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toEqualCanonicalizing(['kita_schule', 'care']);

    // Entfernen (soft-delete) + erneutes Zuweisen reaktiviert dieselbe Zeile (kein Dublett).
    $this->concepts->entferneSektorEignung($this->rootTeam, $this->concept->id, 'kita_schule');
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toBe(['care']);

    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'kita_schule');
    expect($this->concept->sektorEignungen()->count())->toBe(2);          // reaktiviert, nicht doppelt
});

it('CQ 2.5a: Sektor-Eignung prüft gegen das Sektor-Vokabular, Altwerte werden umgeschlüsselt', function () {
    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'Catering');            // Alias
    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, ' betriebsgastronomie ');
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toEqualCanonicalizing(['event_privat', 'business']);

    expect(fn () => $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'Kita'))
        ->toThrow(RuntimeException::class, 'Unbekannter Sektor „Kita“');
    expect($this->concept->sektorEignungen()->count())->toBe(2);
});

it('CQ 2.5a: alter Freitext bleibt stehen und lässt sich entfernen', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistConceptSektorEignung::create([
        'team_id' => $this->concept->team_id, 'concept_id' => $this->concept->id, 'sector_slug' => 'Klinik', 'source' => 'manual',
    ]);
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toBe(['Klinik']);

    $this->concepts->entferneSektorEignung($this->rootTeam, $this->concept->id, 'Klinik');
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toBe([]);
});

it('Editor: Sektor über die Auswahl hinzufügen/entfernen', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('setTab', 'notes')                                   // der Block steht im Reiter Notizen
        ->assertSee('Sektor hinzufügen')
        ->set('neuerSektor', 'event_privat')
        ->call('sektorHinzu')
        ->assertSet('neuerSektor', '')
        ->assertSee('Sektor event_privat entfernen')                // Chip steht (Slugs werden im Reiter Notizen geladen)
        ->assertSee('Catering und Event');

    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toContain('event_privat');
});
