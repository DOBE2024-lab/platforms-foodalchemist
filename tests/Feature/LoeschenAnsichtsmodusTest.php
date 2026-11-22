<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel as ConcepterDetail;
use Platform\FoodAlchemist\Livewire\Concepter\Editor as ConcepterEditor;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel as GpDetailPanel;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetail;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4d (U9): Löschen ist auch im Ansichtsmodus erreichbar, ohne Ausnahme von Spec 65. Die Aktion holt die
 * Sperre für ihre Dauer (holen, löschen, freigeben); hält sie jemand anderes, kommt eine klare Absage.
 * Je Bereich: keine Sperre → gelöscht und frei; fremde Sperre → Absage, Datensatz bleibt.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->ben = $this->makeUser($this->rootTeam, 'Ben');
    $this->svc = app(BearbeitungssperreService::class);
});

// ── Concept ────────────────────────────────────────────────────────────────

it('Concept-Detailspalte: ohne „Bearbeiten“ gelöscht, Sperre danach frei', function () {
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $this->actingAs($this->anna);

    Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $c->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->call('loeschen')
        ->assertDispatched('concepter-geloescht');

    expect(FoodAlchemistConcept::find($c->id))->toBeNull()
        ->and($this->svc->haelt('concept', $c->id, $this->anna->id))->toBeFalse();
});

it('Concept-Detailspalte: fremde Sperre gibt Absage, Concept bleibt', function () {
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $this->svc->sperren('concept', $c->id, $this->ben->id, 'Ben');
    $this->actingAs($this->anna);

    Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $c->id)->call('loeschen')
        ->assertDispatched('fa-saved', message: 'Wird gerade von Ben bearbeitet — erst danach möglich.', type: 'error')
        ->assertNotDispatched('concepter-geloescht');

    expect(FoodAlchemistConcept::find($c->id))->not->toBeNull()
        ->and($this->svc->haelt('concept', $c->id, $this->ben->id))->toBeTrue();
});

it('Concept-Editor: Löschen steht im Menü und löscht im Ansichtsmodus; fremde Sperre gibt Absage', function () {
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $frei = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Fingerfood']);
    $this->svc->sperren('concept', $c->id, $this->ben->id, 'Ben');
    $this->actingAs($this->anna);

    Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $c->id)
        ->assertSeeHtml('data-concepter-loeschen')
        ->call('loeschen')->assertNotDispatched('concepter-geloescht');
    expect(FoodAlchemistConcept::find($c->id))->not->toBeNull();

    Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $frei->id)
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->call('loeschen')
        ->assertDispatched('concepter-geloescht')->assertDispatched('modal.close', name: 'concepter-editor');
    expect(FoodAlchemistConcept::find($frei->id))->toBeNull()
        ->and($this->svc->haelt('concept', $frei->id, $this->anna->id))->toBeFalse();
});

it('Concept-Detailspalte und -Editor nennen Drucken gleich', function () {
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $this->actingAs($this->anna);

    foreach ([Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $c->id)->html(),
        Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $c->id)->html()] as $html) {
        expect($html)->toContain('Kundenkarte drucken')->toContain('Technischen Bericht öffnen');
    }
});

// ── Rezept ─────────────────────────────────────────────────────────────────

it('Rezept-Editor: ohne „Bearbeiten“ gelöscht, Sperre frei; fremde Sperre gibt Absage', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $gesperrt = $this->makeRecipe($this->rootTeam, 'Gemüsefond');
    $this->svc->sperren('recipe', $gesperrt->id, $this->ben->id, 'Ben');
    $this->actingAs($this->anna);

    Livewire::test(RecipeModal::class)->call('oeffnen', $gesperrt->id)->call('loeschen')
        ->assertDispatched('fa-saved', message: 'Wird gerade von Ben bearbeitet — erst danach möglich.', type: 'error');
    expect(FoodAlchemistRecipe::find($gesperrt->id))->not->toBeNull();

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->call('loeschen')
        ->assertDispatched('recipe-gespeichert');
    expect(FoodAlchemistRecipe::find($r->id))->toBeNull()
        ->and($this->svc->haelt('recipe', $r->id, $this->anna->id))->toBeFalse();
});

it('Rezept-Detailspalte (Verwaltung): ohne „Bearbeiten“ gelöscht; fremde Sperre gibt Absage', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: unbenutzt');
    $gesperrt = $this->makeRecipe($this->rootTeam, 'Fond: gesperrt');
    $this->svc->sperren('recipe', $gesperrt->id, $this->ben->id, 'Ben');
    $this->actingAs($this->anna);

    Livewire::test(RezeptDetail::class)->call('zeige', $gesperrt->id)->call('rezeptLoeschen');
    expect(FoodAlchemistRecipe::find($gesperrt->id))->not->toBeNull();

    Livewire::test(RezeptDetail::class)->call('zeige', $r->id)->call('rezeptLoeschen')->assertSet('recipeId', null);
    expect(FoodAlchemistRecipe::find($r->id))->toBeNull()
        ->and($this->svc->haelt('recipe', $r->id, $this->anna->id))->toBeFalse();
});

// ── Grundprodukt ───────────────────────────────────────────────────────────

it('GP-Detailspalte: ohne „Bearbeiten“ gelöscht, Sperre frei; fremde Sperre gibt Absage', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $gesperrt = $this->makeGp($this->rootTeam, 'Hechtfilet');
    $this->svc->sperren('gp', $gesperrt->id, $this->ben->id, 'Ben');
    $this->actingAs($this->anna);

    Livewire::test(GpDetailPanel::class, ['gpId' => $gesperrt->id])->call('gpLoeschen')
        ->assertDispatched('fa-saved', message: 'Wird gerade von Ben bearbeitet — erst danach möglich.', type: 'error')
        ->assertNotDispatched('gp-geloescht');
    expect(FoodAlchemistGp::find($gesperrt->id))->not->toBeNull();

    Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id])
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->call('gpLoeschen')
        ->assertDispatched('gp-geloescht');
    expect(FoodAlchemistGp::find($gp->id))->toBeNull()
        ->and($this->svc->haelt('gp', $gp->id, $this->anna->id))->toBeFalse();
});

it('kurzsperrMethoden: nur die Lösch-Aktionen sind freigeschaltet, Speichern bleibt gesperrt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->actingAs($this->anna);
    $panel = Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id])->instance();

    expect($panel->sperrFreieMethoden())->toContain('gpLoeschen')
        ->and($panel->sperreErlaubt('gpLoeschen'))->toBeTrue()
        ->and($panel->sperreErlaubt('gpErsetzen'))->toBeFalse();
});
