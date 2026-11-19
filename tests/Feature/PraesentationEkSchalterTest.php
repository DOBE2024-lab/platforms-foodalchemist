<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Präsentation 2026-11-19 — EK-Schalter: EK (+ Wareneinsatz) hinter dem VK, NUR in der angemeldeten internen Vorschau
 * und im Design-Builder. Der Kundenlink (publish/buildSnapshot ohne Flag) enthält nie EK, auch nicht als Datenfeld.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'EK'));
    $this->pres = app(PresentationService::class);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Gala 2027', 'personen' => 50]);
    $comp = app(OfferCompositionService::class);
    $kap = $comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Dinner']);
    // Gericht mit echtem EK je Portion (9 €) und festem VK (30 €) — das Konzept-Cockpit rechnet live aus Rezepten.
    $dish = $this->makeRecipe($this->rootTeam, 'Rinderfilet', ['is_sales_recipe' => true, 'status' => 'approved', 'ek_total_eur' => 9.0, 'sales_unit_count' => 1]);
    $this->makeStandardDarreichung($dish, 30.0);
    $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id]);
    $this->render = function (array $snap) {
        $snap['resolved_design']['layout'] = [['block_type' => 'chapter_loop', 'style' => ['show_kapitel_preis' => true]]];

        return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
    };
});

it('ohne Schalter: kein EK im Snapshot und nicht im HTML (Kundenlink)', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', []);
    expect($snap)->not->toHaveKey('ansicht');
    expect(json_encode($snap['content']['sections']))->not->toContain('"ek"');
    expect(($this->render)($snap))->not->toContain('data-pt-ek')->not->toContain('data-pt-intern');
});

it('mit Schalter: EK und Wareneinsatz hinter dem VK, deutlich als intern markiert', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', [], true);
    expect($snap['ansicht'])->toBe(['ek' => true]);
    expect($snap['content']['sections'][0]['blocks'][0]['ek'])->toBe(['pp' => 9.0]);

    $html = ($this->render)($snap);
    expect($html)->toContain('data-pt-intern')->toContain('EK 9,00 € · 30 %');
});

it('Schalter wirkt nur für Foodbook und Angebot; ohneEk entfernt jede EK-Angabe', function () {
    $sections = [['ek' => ['pp' => 1.0], 'blocks' => [['ek' => ['pp' => 2.0], 'items' => [['label' => 'x', 'ek' => 3.0]]]]]];
    expect(json_encode(PresentationService::ohneEk($sections)))->not->toContain('"ek"');
});

it('interne Vorschau: ?ek=1 blendet EK ein, ohne zeigt sie den Schalter; nur angemeldet', function () {
    $url = route('foodalchemist.angebote.praesentation', $this->angebot->id);
    $ohne = $this->get($url)->assertOk()->getContent();
    expect($ohne)->toContain('data-pt-ek-schalter')->not->toContain('data-pt-intern');

    $mit = $this->get($url . '?ek=1')->assertOk()->getContent();
    expect($mit)->toContain('data-pt-intern')->toContain('EK 9,00 €');

    auth()->logout();
    $gast = $this->get($url . '?ek=1');
    expect($gast->getStatusCode())->toBeIn([302, 401, 403])->and($gast->getContent())->not->toContain('EK 9,00');
});

it('Design-Builder: „EK einblenden (nur intern)“ zeigt EK nur in der Vorschau und speichert nichts davon', function () {
    $c = Livewire::test(PraesentationsDesigns::class)
        ->set('previewType', 'angebot')
        ->set('previewSourceId', $this->angebot->id);
    expect($c->html())->not->toContain('EK 9,00');

    $c->set('vorschauEk', true);
    expect($c->html())->toContain('EK 9,00')->toContain('data-fa-vorschau-ek');
    $c->assertSet('ungespeichert', false);
});
