<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1j „Mandanten-Prüfungen“: Schreibwege, die IDs aus Formular/Tool annehmen,
 * prüfen Teamsicht und Typ; der Concept-Export ist vollständig (eingebettete Pakete).
 * Teams: childA und childB sind Geschwister unter root — B ist für A fremd.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $this->concepts = app(ConceptService::class);

    $this->mk = fn ($team, string $key, string $name, bool $vk = true, array $extra = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $team->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => $vk, 'ek_total_eur' => 1.00, 'sales_quantity_per_unit_g' => 200,
        'yield_kg' => 1.0, 'allergens_confidence' => 'high',
    ], $extra));
    $this->eigen = ($this->mk)($this->childA, 'eigen', 'Eigenes Gericht');
    $this->fremd = ($this->mk)($this->childB, 'fremd', 'Fremdes Geheimgericht');
});

// ── 4. Export: eingebettete Pakete vollständig ───────────────────────────

it('Export: Gerichte eines eingebetteten Pakets stehen im Concept-Report (auch verschachtelt)', function () {
    $pork = ($this->mk)($this->childA, 'pork', 'Pulled Pork', true, ['allergen_gluten' => 'enthalten']);
    $innen = $this->concepts->createPaket($this->childA, ['name' => 'Smoker']);
    $is = $this->concepts->addSlot($this->childA, $innen->id, ['role' => 'x']);
    $this->concepts->fillSlot($this->childA, $is->id, ['sales_recipe_id' => $pork->id]);
    $aussen = $this->concepts->createPaket($this->childA, ['name' => 'BBQ-Station']);
    $as = $this->concepts->addSlot($this->childA, $aussen->id, ['role' => 'x']);
    $this->concepts->fillSlot($this->childA, $as->id, ['sales_recipe_id' => $this->eigen->id]);
    $as2 = $this->concepts->addSlot($this->childA, $aussen->id, ['role' => 'y']);
    $this->concepts->fillSlot($this->childA, $as2->id, ['embedded_concept_id' => $innen->id]);

    $c = $this->concepts->create($this->childA, ['name' => 'Sommerfest']);
    $cs = $this->concepts->addSlot($this->childA, $c->id, ['role' => 'Station']);
    $this->concepts->fillSlot($this->childA, $cs->id, ['embedded_concept_id' => $aussen->id]);

    $slot = app(ReportExportService::class)->conceptDaten($this->childA, $c->id, [])['concept']['slots'][0];
    $namen = collect($slot['gerichte'])->map(fn ($g) => $g['recipe']['name'] ?? null)->all();

    expect($slot['type'])->toBe('paket')
        ->and($slot['package']['name'])->toBe('BBQ-Station')
        ->and($namen)->toContain('Eigenes Gericht', 'Pulled Pork')
        ->and(collect($slot['gerichte'])->pluck('quelle')->unique()->all())->toBe(['paket']);
});

it('Export: ein Kreis im eingebetteten Paket hängt nicht', function () {
    $p = $this->concepts->createPaket($this->childA, ['name' => 'Kreis']);
    $ps = $this->concepts->addSlot($this->childA, $p->id, ['role' => 'x']);
    $this->concepts->fillSlot($this->childA, $ps->id, ['sales_recipe_id' => $this->eigen->id]);
    FoodAlchemistConceptSlot::create(['team_id' => $this->childA->id, 'concept_id' => $p->id, 'role' => 'selbst',
        'position' => 5, 'type' => 'paket', 'embedded_concept_id' => $p->id]);
    $c = $this->concepts->create($this->childA, ['name' => 'Menü']);
    $cs = $this->concepts->addSlot($this->childA, $c->id, ['role' => 'x']);
    $this->concepts->fillSlot($this->childA, $cs->id, ['embedded_concept_id' => $p->id]);

    $gerichte = app(ReportExportService::class)->conceptDaten($this->childA, $c->id, [])['concept']['slots'][0]['gerichte'];

    expect($gerichte)->toHaveCount(1);
});

it('Export: fremdes Gericht in einem eingebetteten Paket bleibt ausgeblendet', function () {
    $p = $this->concepts->createPaket($this->childA, ['name' => 'Station']);
    FoodAlchemistConceptSlot::create(['team_id' => $this->childA->id, 'concept_id' => $p->id, 'role' => 'x',
        'position' => 0, 'type' => 'gericht', 'sales_recipe_id' => $this->fremd->id]);
    $c = $this->concepts->create($this->childA, ['name' => 'Menü']);
    $cs = $this->concepts->addSlot($this->childA, $c->id, ['role' => 'x']);
    $this->concepts->fillSlot($this->childA, $cs->id, ['embedded_concept_id' => $p->id]);

    $daten = app(ReportExportService::class)->conceptDaten($this->childA, $c->id, []);

    expect(json_encode($daten, JSON_UNESCAPED_UNICODE))->not->toContain('Fremdes');
});

// ── 1./2. Foodbook- und Angebots-Blöcke ──────────────────────────────────

function cqFoodbookKapitel(\Platform\Core\Models\Team $team)
{
    $fb = app(\Platform\FoodAlchemist\Services\FoodbookService::class)->create($team, ['label' => 'FB A']);

    return app(\Platform\FoodAlchemist\Services\FoodbookService::class)->addKapitel($team, $fb->id, ['title' => 'Menü']);
}

function cqAngebotKapitel(\Platform\Core\Models\Team $team)
{
    $a = \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::create(['team_id' => $team->id, 'name' => 'Sommerfest', 'status' => 'anfrage', 'personen' => 50]);

    return app(\Platform\FoodAlchemist\Services\OfferCompositionService::class)->addKapitel($team, $a->id, ['title' => 'Menü']);
}

it('Foodbook: concept_ref mit fremdem Concept wird beim Anlegen und Ändern abgelehnt, eigenes Paket geht', function () {
    $svc = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $k = cqFoodbookKapitel($this->childA);
    $fremdC = FoodAlchemistConcept::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'kind' => 'concept', 'status' => 'active']);
    $paket = $this->concepts->createPaket($this->childA, ['name' => 'Eigenes Paket']);

    expect(fn () => $svc->addBlock($this->childA, $k->id, ['type' => 'concept_ref', 'concept_id' => $fremdC->id]))->toThrow(\RuntimeException::class);
    $b = $svc->addBlock($this->childA, $k->id, ['type' => 'concept_ref', 'concept_id' => $paket->id]);
    expect(fn () => $svc->updateBlock($this->childA, $b->id, ['concept_id' => $fremdC->id]))->toThrow(\RuntimeException::class);
    expect($b->fresh()->concept_id)->toBe($paket->id);
});

it('Foodbook: Typwechsel auf concept_ref ohne sichtbares Concept wird abgelehnt', function () {
    $svc = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $b = $svc->addBlock($this->childA, cqFoodbookKapitel($this->childA)->id, ['type' => 'text', 'label' => 'x']);

    expect(fn () => $svc->updateBlock($this->childA, $b->id, ['type' => 'concept_ref']))->toThrow(\RuntimeException::class);
});

it('Angebot: updateBlock prüft concept_id wie addBlock', function () {
    $svc = app(\Platform\FoodAlchemist\Services\OfferCompositionService::class);
    $eigenC = $this->concepts->create($this->childA, ['name' => 'Eigen']);
    $fremdC = FoodAlchemistConcept::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'kind' => 'concept', 'status' => 'active']);
    $b = $svc->addBlock($this->childA, cqAngebotKapitel($this->childA)->id, ['type' => 'concept_ref', 'concept_id' => $eigenC->id]);

    expect(fn () => $svc->updateBlock($this->childA, $b->id, ['concept_id' => $fremdC->id]))->toThrow(\RuntimeException::class);
    expect($b->fresh()->concept_id)->toBe($eigenC->id);
});

it('Angebot: presentation_id muss zum Gericht des Blocks gehören', function () {
    $svc = app(\Platform\FoodAlchemist\Services\OfferCompositionService::class);
    $anderes = ($this->mk)($this->childA, 'anderes', 'Anderes Gericht');
    $sf = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'kantine', 'label' => 'Kantine']);
    $fremdeForm = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::create(['team_id' => $this->childA->id, 'recipe_id' => $anderes->id, 'serving_form_id' => $sf->id, 'is_standard' => false, 'quantity_per_unit_g' => 200, 'unit_count' => 1]);
    $eigeneForm = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::create(['team_id' => $this->childA->id, 'recipe_id' => $this->eigen->id, 'serving_form_id' => $sf->id, 'is_standard' => false, 'quantity_per_unit_g' => 200, 'unit_count' => 1]);
    $b = $svc->addBlock($this->childA, cqAngebotKapitel($this->childA)->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->eigen->id]);

    expect(fn () => $svc->updateBlock($this->childA, $b->id, ['presentation_id' => $fremdeForm->id]))->toThrow(\RuntimeException::class, 'Darreichung');
    expect($svc->updateBlock($this->childA, $b->id, ['presentation_id' => $eigeneForm->id])->presentation_id)->toBe($eigeneForm->id);
});

// ── 3. Alt-Paket ─────────────────────────────────────────────────────────

it('Alt-Paket: syncGerichte lehnt ein fremdes Gericht ab, eigenes und Bestand gehen', function () {
    $svc = app(\Platform\FoodAlchemist\Services\PaketService::class);
    $p = $svc->create($this->childA, ['name' => 'Alt', 'role' => 'Hauptgang', 'price_mode' => 'auto']);
    $svc->syncGerichte($this->childA, $p->id, [['sales_recipe_id' => $this->eigen->id]]);

    expect(fn () => $svc->syncGerichte($this->childA, $p->id, [['sales_recipe_id' => $this->eigen->id], ['sales_recipe_id' => $this->fremd->id]]))
        ->toThrow(\RuntimeException::class, 'nicht sichtbar');
    expect($p->fresh()->dishes()->pluck('sales_recipe_id')->all())->toBe([$this->eigen->id]);
});
