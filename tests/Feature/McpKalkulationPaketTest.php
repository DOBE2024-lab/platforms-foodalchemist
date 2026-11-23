<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1a (K1): kalkulation.GET(package_id) meint dasselbe Paket wie pakete.GET/POST
 * (kind=paket-Concept). Vorher suchte das Tool in der alten Tabelle foodalchemist_packages —
 * gleiche ID, anderes Objekt. Der Übergangsparameter abo_paket_id ist mit 98 · 2.6c entfallen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->kalk = fn (array $args) => app(ToolRegistry::class)->get('foodalchemist.kalkulation.GET')->execute($args, $this->kontext);

    $post = app(ToolRegistry::class)->get('foodalchemist.pakete.POST')->execute(['name' => 'BBQ-Station'], $this->kontext);
    expect($post->success)->toBeTrue();
    $this->paketId = (int) $post->data['paket']['id'];
});

it('package_id: ein Paket aus pakete.POST wird kalkuliert (kind=paket-Concept)', function () {
    $r = ($this->kalk)(['package_id' => $this->paketId]);

    expect($r->success)->toBeTrue()
        ->and($r->data['ziel'])->toBe(['type' => 'paket', 'id' => $this->paketId, 'name' => 'BBQ-Station'])
        ->and($r->data['kalkulation'])->toHaveKey('hk1_pro_person');
});

it('package_id: ein Konzept (kind≠paket) ist kein Paket', function () {
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü', 'kind' => 'concept', 'status' => 'draft']);

    expect(($this->kalk)(['package_id' => $concept->id])->success)->toBeFalse();
});

it('genau ein Ziel-Parameter ist Pflicht — zwei zugleich werden abgelehnt', function () {
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü', 'kind' => 'concept', 'status' => 'active']);

    expect(($this->kalk)(['package_id' => $this->paketId, 'concept_id' => $concept->id])->success)->toBeFalse()
        ->and(($this->kalk)([])->success)->toBeFalse();
});

it('abo_paket_id gibt es nicht mehr (98 · 2.6c) — ohne gültiges Ziel abgelehnt', function () {
    expect(($this->kalk)(['abo_paket_id' => 1])->success)->toBeFalse();
});
