<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1c (K3): fillSlot prüft Sichtbarkeit fürs Team für alle drei Zielarten
 * (eingebettetes Paket, Alt-Paket, Rezept) und den Typ (Gericht vs. Basisrezept). Dazu das
 * Lese-Leck: Slot-Beziehungen lösen ohne Teamsicht auf — fremde Ziele, die schon in der DB
 * stehen, dürfen in Detail, Kalkulation und Export nicht erscheinen.
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

// ── Lesen: fremde Ziele, die schon in der DB stehen ───────────────────────

function cqFremdeSlotsEinschleusen($test): void
{
    // An der Service-Prüfung vorbei (Altbestand / anderer Schreibweg).
    FoodAlchemistConceptSlot::whereKey($test->slot->id)->update(['type' => 'gericht', 'sales_recipe_id' => $test->fremd->id]);
    FoodAlchemistConceptSlot::create(['team_id' => $test->childA->id, 'concept_id' => $test->concept->id, 'role' => 'Station',
        'position' => 1, 'type' => 'paket', 'embedded_concept_id' => $test->fremdPaket->id]);
    FoodAlchemistConceptSlot::create(['team_id' => $test->childA->id, 'concept_id' => $test->concept->id, 'role' => 'Alt',
        'position' => 2, 'type' => 'paket', 'package_id' => $test->fremdAltPaket->id]);
}

it('Detail zeigt keine fremden Slot-Ziele', function () {
    cqFremdeSlotsEinschleusen($this);

    $c = $this->concepts->detail($this->childA, $this->concept->id);

    expect($c->slots->pluck('dish')->filter())->toBeEmpty()
        ->and($c->slots->pluck('embeddedConcept')->filter())->toBeEmpty()
        ->and($c->slots->pluck('package')->filter())->toBeEmpty();
});

it('Kalkulation (Preis-Cockpit, Aggregat, Diät-Rollup) zählt keine fremden Slot-Ziele', function () {
    cqFremdeSlotsEinschleusen($this);
    $c = FoodAlchemistConcept::find($this->concept->id);

    $hk = app(KalkulationService::class)->conceptHk($this->childA, $c);
    $cockpit = $this->concepts->preisCockpit($c->fresh());
    $rollup = $this->concepts->allergenRollup($c->fresh());

    expect(json_encode($hk))->not->toContain('Fremdes')
        ->and(json_encode($cockpit))->not->toContain('Fremdes')
        ->and((float) ($hk['hk1_pro_person'] ?? 0))->toBe(0.0)
        ->and($rollup['n_gerichte'])->toBe(0);
});

it('Export (Konzept-Report) zeigt keine fremden Slot-Ziele', function () {
    cqFremdeSlotsEinschleusen($this);

    $daten = app(ReportExportService::class)->conceptDaten($this->childA, $this->concept->id, []);

    expect(json_encode($daten, JSON_UNESCAPED_UNICODE))->not->toContain('Fremdes');
});
