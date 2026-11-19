<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1c (K3): fillSlot prüft Sichtbarkeit fürs Team für alle drei Zielarten
 * (eingebettetes Paket, Alt-Paket, Rezept) und den Typ (Gericht vs. Basisrezept). Sicht heißt
 * die bestehende Regel inkl. Abo-Fassungen (Mandanten abonnieren Inhalte) — nie „eigenes Team“.
 * Lesen bleibt unverändert (Slot-Beziehungen lösen absichtlich auch Fassungen auf, Spec 82).
 *
 * Teams: childA und childB sind Geschwister unter root — B ist für A fremd, root ist geerbt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $this->concepts = app(ConceptService::class);

    $mk = fn ($team, string $key, string $name, bool $vk = true) => FoodAlchemistRecipe::create([
        'team_id' => $team->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => $vk, 'ek_total_eur' => 1.00, 'sales_quantity_per_unit_g' => 200,
        'yield_kg' => 1.0, 'allergens_confidence' => 'high',
    ]);
    $this->eigen = $mk($this->childA, 'eigen', 'Eigenes Gericht');
    $this->geerbt = $mk($this->rootTeam, 'geerbt', 'Geerbtes Gericht');
    $this->fremd = $mk($this->childB, 'fremd', 'Fremdes Geheimgericht');
    $this->basis = $mk($this->childA, 'basis', 'Eigene Grundsauce', false);

    $this->concept = $this->concepts->create($this->childA, ['name' => 'Menü A']);
    $this->slot = $this->concepts->addSlot($this->childA, $this->concept->id, ['role' => 'Hauptgang']);

    // Fremdes Paket (neues Modell) in Team B, mit fremdem Gericht.
    $this->fremdPaket = FoodAlchemistConcept::create(['team_id' => $this->childB->id, 'name' => 'Fremdes Paket', 'kind' => 'paket', 'status' => 'active']);
    FoodAlchemistConceptSlot::create(['team_id' => $this->childB->id, 'concept_id' => $this->fremdPaket->id, 'role' => 'x',
        'position' => 0, 'type' => 'gericht', 'sales_recipe_id' => $this->fremd->id]);
    $this->fremdAltPaket = FoodAlchemistPaket::create(['team_id' => $this->childB->id, 'name' => 'Fremdes Alt-Paket', 'role' => 'x']);
});

// ── Schreiben ────────────────────────────────────────────────────────────

it('fillSlot: eigenes und geerbtes Gericht sind erlaubt', function () {
    expect($this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->eigen->id])->sales_recipe_id)->toBe($this->eigen->id)
        ->and($this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->geerbt->id])->sales_recipe_id)->toBe($this->geerbt->id);
});

it('fillSlot: fremdes Gericht wird abgelehnt', function () {
    expect(fn () => $this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->fremd->id]))
        ->toThrow(ModelNotFoundException::class);
    expect($this->slot->fresh()->sales_recipe_id)->toBeNull();
});

it('fillSlot: fremdes eingebettetes Paket wird abgelehnt', function () {
    expect(fn () => $this->concepts->fillSlot($this->childA, $this->slot->id, ['embedded_concept_id' => $this->fremdPaket->id]))
        ->toThrow(ModelNotFoundException::class);
    expect($this->slot->fresh()->embedded_concept_id)->toBeNull();
});

it('fillSlot: ein Konzept (kind≠paket) kann nicht als Paket eingebettet werden', function () {
    $anderes = $this->concepts->create($this->childA, ['name' => 'Anderes Menü']);

    expect(fn () => $this->concepts->fillSlot($this->childA, $this->slot->id, ['embedded_concept_id' => $anderes->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('fillSlot: fremdes Alt-Paket wird abgelehnt', function () {
    expect(fn () => $this->concepts->fillSlot($this->childA, $this->slot->id, ['package_id' => $this->fremdAltPaket->id]))
        ->toThrow(ModelNotFoundException::class);
    expect($this->slot->fresh()->package_id)->toBeNull();
});

it('fillSlot: Paket bettet sich selbst nicht ein, und ein Zyklus über zwei Pakete wird abgelehnt', function () {
    $p1 = $this->concepts->createPaket($this->childA, ['name' => 'P1']);
    $p2 = $this->concepts->createPaket($this->childA, ['name' => 'P2']);
    $s1 = $this->concepts->addSlot($this->childA, $p1->id, ['role' => 'x']);
    $s2 = $this->concepts->addSlot($this->childA, $p2->id, ['role' => 'x']);

    expect(fn () => $this->concepts->fillSlot($this->childA, $s1->id, ['embedded_concept_id' => $p1->id]))
        ->toThrow(\RuntimeException::class);

    $this->concepts->fillSlot($this->childA, $s1->id, ['embedded_concept_id' => $p2->id]);   // P1 ⊃ P2
    expect(fn () => $this->concepts->fillSlot($this->childA, $s2->id, ['embedded_concept_id' => $p1->id]))   // P2 ⊃ P1 → Zyklus
        ->toThrow(\RuntimeException::class);
    expect($s2->fresh()->embedded_concept_id)->toBeNull();
});

// ── Typ: Gericht vs. Basisrezept ──────────────────────────────────────────

it('fillSlot: ohne Typ wird er aus dem Rezept abgeleitet', function () {
    expect($this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->eigen->id])->type)->toBe('gericht')
        ->and($this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->basis->id])->type)->toBe('basisrezept');
});

it('fillSlot: ein Typ, der nicht zum Rezept passt, wird abgelehnt', function () {
    expect(fn () => $this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->basis->id, 'type' => 'gericht']))
        ->toThrow(\RuntimeException::class, 'basisrezept');
    expect(fn () => $this->concepts->fillSlot($this->childA, $this->slot->id, ['sales_recipe_id' => $this->eigen->id, 'type' => 'basisrezept']))
        ->toThrow(\RuntimeException::class, 'gericht');
});

// ── Abo: Verweis auf eine abonnierte Fassung ist gewollte Fachlogik ─────────

it('Abo: der Mandant setzt eine abonnierte Fassung ein; ein Master-Rezept ohne Abo wird abgelehnt', function () {
    $master = \Platform\Core\Models\Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    $mandant = \Platform\Core\Models\Team::create(['name' => 'Mandant', 'user_id' => 1, 'personal_team' => false]);
    \Platform\FoodAlchemist\Support\TeamAncestryRegistry::flushAll();
    \Platform\FoodAlchemist\Support\StammPaket::freischalten($mandant);   // Abo setzt ein gebuchtes Stamm-Paket voraus
    $kurator = $this->makeUser($master, 'Kurator', 'owner');
    $this->actingAs($this->makeUser($mandant, 'Mandant Chef', 'owner'));

    $jus = $this->makeRecipe($master, 'Kalbsjus', ['is_sales_recipe' => true]);
    $jusF = $this->makeRecipe($master, 'Kalbsjus', ['is_sales_recipe' => true, 'ist_fassung' => true, 'fassung_von_id' => $jus->id]);
    $geheim = $this->makeRecipe($master, 'Master-Geheimrezept', ['is_sales_recipe' => true]);
    $inhalte = app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class);
    $sammlung = $inhalte->sammlungAnlegen($master, 'Basis', null, $kurator);
    $inhalte->sammlungHinzu($master, $sammlung, 'recipe', [$jus->id], $kurator);
    app(\Platform\FoodAlchemist\Services\AboService::class)->abonnieren($master, $mandant, $sammlung, $kurator);

    $c = $this->concepts->create($mandant, ['name' => 'Mandanten-Menü']);
    $slot = $this->concepts->addSlot($mandant, $c->id, ['role' => 'Hauptgang']);

    expect($this->concepts->fillSlot($mandant, $slot->id, ['sales_recipe_id' => $jusF->id])->sales_recipe_id)->toBe($jusF->id);
    expect(fn () => $this->concepts->fillSlot($mandant, $slot->id, ['sales_recipe_id' => $geheim->id]))
        ->toThrow(ModelNotFoundException::class);
});
