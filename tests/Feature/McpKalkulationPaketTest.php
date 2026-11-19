<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1a (K1): kalkulation.GET(package_id) meint dasselbe Paket wie pakete.GET/POST
 * (kind=paket-Concept). Vorher suchte das Tool in der alten Tabelle foodalchemist_packages —
 * gleiche ID, anderes Objekt. Das alte Abo-Paket rechnet bis Paket 2.6 über abo_paket_id.
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

it('package_id: kollidierende ID (in beiden Tabellen) meint immer das Concept, nie das Alt-Paket', function () {
    $alt = new FoodAlchemistPaket(['team_id' => $this->rootTeam->id, 'name' => 'Altes Abo-Paket', 'role' => 'Hauptgang']);
    $alt->id = $this->paketId;
    $alt->save();
    expect(FoodAlchemistPaket::whereKey($this->paketId)->exists())->toBeTrue();

    $r = ($this->kalk)(['package_id' => $this->paketId]);

    expect($r->success)->toBeTrue()
        ->and($r->data['ziel']['name'])->toBe('BBQ-Station');
});

it('package_id: eine ID nur aus der alten Tabelle liefert NOT_FOUND mit Hinweis auf abo_paket_id', function () {
    $alt = new FoodAlchemistPaket(['team_id' => $this->rootTeam->id, 'name' => 'Nur alt', 'role' => 'Hauptgang']);
    $alt->id = 900001;   // sicher außerhalb der Concept-IDs
    $alt->save();
    expect(FoodAlchemistConcept::withoutGlobalScopes()->whereKey($alt->id)->exists())->toBeFalse();

    $r = ($this->kalk)(['package_id' => $alt->id]);

    expect($r->success)->toBeFalse()
        ->and($r->errorCode)->toBe('NOT_FOUND')
        ->and($r->error)->toContain('abo_paket_id');
});

it('package_id: ein Konzept (kind≠paket) ist kein Paket', function () {
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü', 'kind' => 'concept', 'status' => 'draft']);

    expect(($this->kalk)(['package_id' => $concept->id])->success)->toBeFalse();
});

it('abo_paket_id: das alte Abo-Paket rechnet weiter (Übergang bis 2.6)', function () {
    $alt = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Abo Mittag', 'role' => 'Hauptgang']);

    $r = ($this->kalk)(['abo_paket_id' => $alt->id]);

    expect($r->success)->toBeTrue()
        ->and($r->data['ziel'])->toBe(['type' => 'abo_paket', 'id' => $alt->id, 'name' => 'Abo Mittag']);
});

it('genau ein Ziel-Parameter ist Pflicht — zwei zugleich werden abgelehnt', function () {
    $alt = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Abo', 'role' => 'Hauptgang']);

    expect(($this->kalk)(['package_id' => $this->paketId, 'abo_paket_id' => $alt->id])->success)->toBeFalse()
        ->and(($this->kalk)([])->success)->toBeFalse();
});
