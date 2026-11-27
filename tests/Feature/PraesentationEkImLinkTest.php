<?php

use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationDesignService;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Präsentation 2026-11-20 — Design-Haken „EK-Ansicht im Link“ (tokens_json.ek_im_link):
 * ohne Haken bleibt der veröffentlichte Link wie bisher (kein EK, kein Knopf);
 * mit Haken zeigt der Link den Knopf „EK einblenden“ und mit ?ek=1 den EK, ohne ?ek=1 keinen EK-Wert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'EK-Link'));
    $this->pres = app(PresentationService::class);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Gala 2027', 'personen' => 50]);
    $comp = app(OfferCompositionService::class);
    $kap = $comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Dinner']);
    $dish = $this->makeRecipe($this->rootTeam, 'Rinderfilet', ['is_sales_recipe' => true, 'status' => 'approved', 'ek_total_eur' => 9.0, 'sales_unit_count' => 1]);
    $this->makeStandardDarreichung($dish, 30.0);
    $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'recipe_id' => $dish->id]);

    $this->veroeffentlichen = function (bool $haken): string {
        $d = app(PresentationDesignService::class)->create($this->rootTeam, [
            'name' => 'EK-Test', 'base_slug' => 'editorial',
            'layout_json' => [['block_type' => 'chapter_loop', 'style' => ['show_kapitel_preis' => true]]],
            'tokens_json' => ['ek_im_link' => $haken, 'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 1.0]],
        ]);

        return $this->pres->publish($this->rootTeam, 'angebot', $this->angebot->id, [
            'design' => 'design:' . $d->id, 'price_display' => true, 'expires_at' => now()->addDays(7)->toDateString(),
        ])['url'];
    };
});

it('ohne Haken: Link wie bisher, kein EK im Snapshot, kein Knopf, auch nicht mit ?ek=1', function () {
    $url = ($this->veroeffentlichen)(false);
    expect(json_encode($this->angebot->fresh()->presentation_snapshot_json))->not->toContain('"ek"');

    auth()->logout();
    foreach (['', '?ek=1'] as $q) {
        expect($this->get($url . $q)->assertOk()->getContent())
            ->not->toContain('data-pt-ek-schalter')->not->toContain('data-pt-intern')->not->toContain('EK 9,00');
    }
});

it('mit Haken: Link zeigt den Knopf, EK nur nach dem Umschalten (ohne Anmeldung)', function () {
    $url = ($this->veroeffentlichen)(true);
    auth()->logout();

    $ohne = $this->get($url)->assertOk()->getContent();
    expect($ohne)->toContain('data-pt-ek-schalter')->not->toContain('data-pt-intern')->not->toContain('EK 9,00');

    $mit = $this->get($url . '?ek=1')->assertOk()->getContent();
    expect($mit)->toContain('data-pt-intern')->toContain('EK 9,00 €')->toContain('EK ausblenden');
});

it('eingebautes Design hat den Haken nie', function () {
    expect(\Platform\FoodAlchemist\Support\PraesentationsEkLink::imDesign('editorial'))->toBeFalse();
});
