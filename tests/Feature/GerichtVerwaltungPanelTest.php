<?php

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Verwaltung im Detail-Panel des Gerichts (ersetzt „Wo verwendet?“): Bilanz mit Namen → Ziel wählen → Vorschau je
 * Bereich → Ersetzen → Löschen frei. Dasselbe Partial wie beim Basisrezept (recipes/partials/verwaltung).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->alt = $this->makeRecipe($this->rootTeam, 'Rinderroulade', ['is_sales_recipe' => true]);
    $this->neu = $this->makeRecipe($this->rootTeam, 'Kalbsbäckchen', ['is_sales_recipe' => true]);
    $this->basis = $this->makeRecipe($this->rootTeam, 'Kalbsfond');   // Basisrezept: kein Ersatz-Ziel für ein Gericht
    $this->slot = $this->makeConceptSlot($this->makeConcept($this->rootTeam, 'Herbstmenü'), ['sales_recipe_id' => $this->alt->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('zeigt die aktive Verwendung mit Namen und sperrt das Löschen', function () {
    Livewire::test(DetailPanel::class)->call('zeige', $this->alt->id)
        ->assertSee('Verwaltung')
        ->assertSee('Konzept „Herbstmenü“')
        ->assertSee('Löschen nicht möglich')
        ->assertDontSee('Gericht löschen');
});

it('schlägt nur Gerichte als Ersatz vor', function () {
    Livewire::test(DetailPanel::class)->call('zeige', $this->alt->id)
        ->set('tauschSuche', 'Kalb')
        ->assertSee('Kalbsbäckchen')
        ->assertDontSee('Kalbsfond');
});

it('Vorschau → Ersetzen → Löschen', function () {
    $c = Livewire::test(DetailPanel::class)->call('zeige', $this->alt->id)
        ->call('gerichtTauschVorbereiten', $this->neu->id)
        ->assertSee('„Rinderroulade“ ersetzen durch „Kalbsbäckchen“')
        ->assertSee('Konzepte und Pakete: 1');
    expect((int) $this->slot->fresh()->sales_recipe_id)->toBe($this->alt->id);   // Vorschau schreibt nichts

    $c->call('gerichtErsetzen')
        ->assertSet('fehlerTausch', null)
        ->assertSee('Auf „Kalbsbäckchen“ umgehängt')
        ->assertSee('Gericht löschen');
    expect((int) $this->slot->fresh()->sales_recipe_id)->toBe($this->neu->id);

    $c->call('rezeptLoeschen')->assertSet('fehlerTausch', null);
    expect(FoodAlchemistRecipe::find($this->alt->id))->toBeNull()
        ->and(FoodAlchemistRecipe::withTrashed()->find($this->alt->id)?->trashed())->toBeTrue()
        ->and(FoodAlchemistConceptSlot::where('sales_recipe_id', $this->alt->id)->count())->toBe(0);
});
