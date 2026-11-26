<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Exceptions\FreigabeGesperrtException;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RezeptBrowser;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Services\Rezept\FreigabeSperre;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1f · Freigabe-Sperre (E1, Dominique 19.11.2026): Rezept/Gericht/Concept nur freigeben, wenn alle Komponenten rekursiv
 * freigegeben sind — zentral im Service, also für UI, Mehrfachauswahl, MCP, Concept, Kaskade und Anreicherung. „Alles mitfreigeben“ nur
 * mit Rolle Kuratieren; Platzhalter/Veraltet/geerbt blockieren weiter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(RecipeService::class);
    $this->g = $this->unitG($this->rootTeam);
    $this->sub = fn (FoodAlchemistRecipe $von, FoodAlchemistRecipe $nach) => FoodAlchemistRecipeIngredient::create(['team_id' => $von->team_id,
        'recipe_id' => $von->id, 'referenced_recipe_id' => $nach->id, 'raw_text' => $nach->name, 'quantity' => '100',
        'unit_vocab_id' => $this->g->id, 'position' => 1, 'match_method' => 'recipe_ref']);
    // Gericht → Basis (Entwurf) → Fond (Entwurf)
    $this->fond = $this->makeRecipe($this->rootTeam, 'Fond: Kalb', ['status' => 'draft']);
    $this->basis = $this->makeRecipe($this->rootTeam, 'Sauce: Kalbsjus', ['status' => 'draft']);
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Kalbsrücken', ['is_sales_recipe' => true, 'status' => 'draft']);
    ($this->sub)($this->basis, $this->fond);
    ($this->sub)($this->gericht, $this->basis);
});

it('Service: Gericht mit Entwurfs-Komponente (rekursiv) wird abgelehnt — mit Liste der offenen Komponenten', function () {
    try {
        $this->svc->setStatus($this->rootTeam, $this->gericht->id, 'approved');
        $this->fail('Freigabe hätte gesperrt sein müssen.');
    } catch (FreigabeGesperrtException $e) {
        expect(collect($e->offen)->pluck('id')->all())->toEqualCanonicalizing([$this->basis->id, $this->fond->id])
            ->and($e->getMessage())->toContain('Sauce: Kalbsjus (Entwurf)')->toContain('Fond: Kalb (Entwurf)');
    }
    expect($this->gericht->refresh()->status->value)->toBe('draft');

    // Auch unter einer freigegebenen Komponente wird weiter gesucht
    $this->basis->update(['status' => 'approved']);
    expect(fn () => $this->svc->setStatus($this->rootTeam, $this->gericht->id, 'approved'))->toThrow(FreigabeGesperrtException::class, 'Fond: Kalb');

    $this->fond->update(['status' => 'approved']);
    $this->svc->setStatus($this->rootTeam, $this->gericht->id, 'approved');
    expect($this->gericht->refresh()->status->value)->toBe('approved');
});

it('Service: andere Status bleiben frei setzbar; der Editor-Update-Weg (status im Formular) sperrt nur den Wechsel auf freigegeben', function () {
    $this->svc->setStatus($this->rootTeam, $this->gericht->id, 'review');
    expect($this->gericht->refresh()->status->value)->toBe('review');
    expect(fn () => $this->svc->update($this->rootTeam, $this->gericht->id, ['status' => 'approved']))->toThrow(FreigabeGesperrtException::class);

    // schon freigegeben (Altbestand vor der Sperre): Speichern mit unverändertem Status bleibt möglich
    $this->gericht->update(['status' => 'approved']);
    $this->svc->update($this->rootTeam, $this->gericht->id, ['status' => 'approved', 'description' => 'neu']);
    expect($this->gericht->refresh()->description)->toBe('neu');
});

it('UI Detail: Freigabe zeigt die Sperr-Meldung; „Alles mitfreigeben“ gibt Komponenten und Gericht frei', function () {
    // Das Rezept-Detail zeigt Basisrezepte (Gerichte laufen über den Verkaufs-Browser): Basis mit Entwurfs-Fond
    Livewire::test(DetailPanel::class, ['recipeId' => $this->basis->id])
        ->call('statusSetzen', 'approved')
        ->assertSet('freigabeSperre.art', 'rezept')
        ->assertSee('Freigabe gesperrt')->assertSee('Alles mitfreigeben')
        ->call('allesMitfreigeben')
        ->assertSet('freigabeSperre', null);

    expect($this->gericht->refresh()->status->value)->toBe('draft')
        ->and($this->basis->refresh()->status->value)->toBe('approved')
        ->and($this->fond->refresh()->status->value)->toBe('approved');
});

it('Mehrfachauswahl: nur das Gericht → abgelehnt; Gericht samt Komponenten in einer Auswahl → freigegeben', function () {
    expect(fn () => $this->svc->bulkStatus($this->rootTeam, [$this->gericht->id], 'approved'))->toThrow(FreigabeGesperrtException::class);
    expect($this->gericht->refresh()->status->value)->toBe('draft');

    Livewire::test(RezeptBrowser::class)->set('auswahl', [$this->basis->id => true])->call('bulkStatus', 'approved')
        ->assertSet('freigabeSperre.art', 'rezept')->assertSee('Fond: Kalb');
    expect($this->basis->refresh()->status->value)->toBe('draft');

    expect($this->svc->bulkStatus($this->rootTeam, [$this->gericht->id, $this->basis->id, $this->fond->id], 'approved'))->toBe(3);
});

it('Mehrfachauswahl prüft jetzt die Rolle Kuratieren (vorher nur setStatus)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'gast'));
    expect(fn () => $this->svc->bulkStatus($this->rootTeam, [$this->fond->id], 'review'))->toThrow(FaRechtFehltException::class);
});

it('MCP: Status-Tools melden FREIGABE_GESPERRT mit offenen Komponenten; MITFREIGEBEN zeigt Vorschau und gibt frei', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $r = $reg->get('foodalchemist.verkaufsrezepte.STATUS')->execute(['id' => $this->gericht->id, 'status' => 'approved'], $ctx);
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FREIGABE_GESPERRT')
        ->and(collect($r->metadata['offen'])->pluck('id')->all())->toEqualCanonicalizing([$this->basis->id, $this->fond->id]);
    $r = $reg->get('foodalchemist.recipes.STATUS')->execute(['ids' => [$this->basis->id], 'status' => 'approved'], $ctx);
    expect($r->errorCode)->toBe('FREIGABE_GESPERRT');

    $platzhalter = $this->makeRecipe($this->rootTeam, 'Gel: Petersilie', ['status' => 'stub']);
    ($this->sub)($this->fond, $platzhalter);
    $v = $reg->get('foodalchemist.freigabe.MITFREIGEBEN')->execute(['art' => 'rezept', 'id' => $this->gericht->id], $ctx);
    expect(collect($v->data['freigebbar'])->pluck('id')->all())->toEqualCanonicalizing([$this->basis->id, $this->fond->id])
        ->and(collect($v->data['blockiert'])->pluck('id')->all())->toBe([$platzhalter->id]);
    $r = $reg->get('foodalchemist.freigabe.MITFREIGEBEN')->execute(['art' => 'rezept', 'id' => $this->gericht->id, 'ausfuehren' => true], $ctx);
    expect($r->errorCode)->toBe('FREIGABE_GESPERRT')->and($this->gericht->refresh()->status->value)->toBe('draft');   // Platzhalter blockiert weiter

    FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $platzhalter->id)->delete();
    $r = $reg->get('foodalchemist.freigabe.MITFREIGEBEN')->execute(['art' => 'rezept', 'id' => $this->gericht->id, 'ausfuehren' => true], $ctx);
    expect($r->success)->toBeTrue()->and($this->gericht->refresh()->status->value)->toBe('approved');
});

it('„Alles mitfreigeben“ nur mit Rolle Kuratieren; geerbte Komponenten blockieren weiter', function () {
    $gast = $this->makeUser($this->rootTeam, 'Gast', 'gast');
    $this->actingAs($gast);
    expect(fn () => app(FreigabeSperre::class)->mitFreigeben($this->rootTeam, 'rezept', $this->gericht->id))->toThrow(FaRechtFehltException::class);
    expect($this->basis->refresh()->status->value)->toBe('draft');

    // Kind-Team: Gericht im Kind, Basisrezept geerbt (Root) → blockiert, nicht freigebbar
    $this->actingAs($this->user);
    $kindGericht = $this->makeRecipe($this->childA, 'Kind-Gericht', ['is_sales_recipe' => true, 'status' => 'draft']);
    ($this->sub)($kindGericht, $this->basis);
    $v = app(FreigabeSperre::class)->vorschau($this->childA, 'rezept', $kindGericht->id);
    expect($v['freigebbar'])->toBe([])->and(collect($v['blockiert'])->pluck('id')->all())->toEqualCanonicalizing([$this->basis->id, $this->fond->id]);
});

it('Concept: Aktivieren mit Entwurfs-Gericht im Slot wird abgelehnt (setStatus und Update-Weg)', function () {
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Buffet', 'status' => 'draft']);
    $c->slots()->create(['team_id' => $this->rootTeam->id, 'role' => 'Hauptgang', 'title' => 'Hauptgang', 'position' => 0, 'sales_recipe_id' => $this->gericht->id]);

    expect(fn () => app(ConceptService::class)->setStatus($this->rootTeam, $c->id, 'active'))->toThrow(FreigabeGesperrtException::class, 'Kalbsrücken');
    expect(fn () => app(ConceptService::class)->update($this->rootTeam, $c->id, ['status' => 'active']))->toThrow(FreigabeGesperrtException::class);
    expect($c->refresh()->status)->toBe('draft');

    app(FreigabeSperre::class)->mitFreigeben($this->rootTeam, 'concept', $c->id);
    app(ConceptService::class)->setStatus($this->rootTeam, $c->id, 'active');
    expect($c->refresh()->status)->toBe('active')->and($this->gericht->refresh()->status->value)->toBe('approved');
});

it('Kaskade: Freigabe eines Gerichts mit offenen Komponenten → „Prüfen“ statt freigegeben, Hinweis am Step', function () {
    Queue::fake();
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => false]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $this->gericht->id]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    expect($this->gericht->refresh()->status->value)->toBe('review')
        ->and($step->refresh()->deferred['freigabe_gesperrt'] ?? [])->toContain('Sauce: Kalbsjus (Entwurf)');
});

it('Anreicherung (gestuft): wird nur grün, wenn alle Komponenten freigegeben sind — sonst bleibt es still bei „Prüfen“', function () {
    Queue::fake();
    $this->gericht->update(['status' => 'review']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $this->gericht->id, 'deferred' => ['freigabe_nach_anreicherung' => true]]);
    $abschliessen = fn () => Closure::bind(fn () => $this->freigabeAbschliessen(), new EnrichRecipeJob($this->rootTeam->id, auth()->id(), $this->gericht->id, null, false, $step->id), EnrichRecipeJob::class)();

    $abschliessen();
    expect($this->gericht->refresh()->status->value)->toBe('review');

    $this->basis->update(['status' => 'approved']);
    $this->fond->update(['status' => 'approved']);
    $step->refresh()->update(['deferred' => ['freigabe_nach_anreicherung' => true]]);
    $abschliessen();
    expect($this->gericht->refresh()->status->value)->toBe('approved');
});
