<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.6 Abnahme „Positions-Kern“: dieselben Preisfälle laufen durch Foodbook und Angebot — Zeile UND Kapitel-Summe
 * müssen gleich sein (eine Regel, {@see \Platform\FoodAlchemist\Services\Position\PositionPreisService}). Dazu: dasselbe
 * Konzept rechnet in Foodbook und Angebot identisch, auch mit eingebettetem Paket und als geerbtes Konzept eines
 * anderen Teams (Team-Kontext, P1). Gewollte Unterschiede stehen im Profil: Staffel nur Foodbook, Position und Festpreis
 * nur Angebot — die laufen hier nicht als Parität.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->t = $this->rootTeam;
    $this->gericht = $this->makeRecipe($this->t, 'Rinderroulade', ['is_sales_recipe' => true, 'ek_total_eur' => 30.0, 'sales_unit_count' => 10]);
    $this->makeStandardDarreichung($this->gericht, 10.0);
    $this->ohneVk = $this->makeRecipe($this->t, 'Ohne Preis', ['is_sales_recipe' => true, 'ek_total_eur' => 20.0, 'sales_unit_count' => 10]);
    $this->ohneEk = $this->makeRecipe($this->t, 'Ohne EK', ['is_sales_recipe' => true, 'sales_unit_count' => 10]);
    $this->makeStandardDarreichung($this->ohneEk, 8.0);

    $this->paket = $this->makeConcept($this->t, 'Beilagen-Duo', ['kind' => 'paket']);
    $this->makeConceptSlot($this->paket, ['recipe_id' => $this->gericht->id, 'quantity' => 0.5]);
    $this->konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $this->makeConceptSlot($this->konzept, ['recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $this->mitPaket = $this->makeConcept($this->t, 'Menü mit Paket');
    $this->makeConceptSlot($this->mitPaket, ['recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $this->makeConceptSlot($this->mitPaket, ['type' => 'paket', 'embedded_concept_id' => $this->paket->id, 'position' => 2]);

    // 98 · 3.6 PR4: dieselbe Gericht-Zeile als Konzept-Slot (preisCockpit), normiert wie blockZeile()
    $this->slotZeile = function ($team, array $slot): array {
        $k = $this->makeConcept($team, 'Slot-Konzept');
        $this->makeConceptSlot($k, $slot + ['quantity' => 1]);
        $c = app(ConceptService::class)->preisCockpit($k->fresh(), null, $team);
        $z = $c['zeilen'][0];

        return ['vk' => (float) ($z['price'] ?? 0), 'ek' => (float) ($z['ek'] ?? 0), 'vk_fehlt' => (bool) $z['vk_fehlt'], 'ek_luecke' => (bool) $c['hat_ek_luecke']];
    };

    // Ein Kapitel je Seite mit demselben Block, dann Zeile + Kapitel-Summe beider Seiten (Closure: darf die geschützten Helfer rufen).
    $this->paritaet = function ($team, array $block, string $fbTyp, string $agTyp): array {
        $fbKap = $this->makeChapter($this->makeFoodbook($team, 'Foodbook', ['status' => 'entwurf']));
        $fbBlock = $this->makeFoodbookBlock($fbKap, ['type' => $fbTyp] + $block);
        $angebot = app(AngebotService::class)->create($team, ['name' => 'Angebot']);
        $agKap = FoodAlchemistOfferChapter::create(['team_id' => $team->id, 'offer_id' => $angebot->id, 'title' => 'Kapitel', 'position' => 0]);
        $agBlock = FoodAlchemistOfferBlock::create(['team_id' => $team->id, 'chapter_id' => $agKap->id, 'type' => $agTyp, 'position' => 0, 'visible' => true] + $block);

        $fbZeile = app(FoodbookAggregat::class)->blockPreis($fbBlock->fresh(), null, null, $team);
        $agZeile = app(OfferCompositionService::class)->blockPreis($agBlock->fresh(), null, $team);
        $fbSumme = app(FoodbookAggregat::class)->kapitelAggregat($team, $fbKap->fresh());
        $agSumme = app(OfferCompositionService::class)->kapitelAggregat($team, $agKap->fresh());

        return [$fbZeile, $agZeile,
            ['vk' => $fbSumme['vk_pro_person'], 'ek' => $fbSumme['ek_per_person'], 'pauschal' => $fbSumme['pauschal'], 'ek_pauschal' => $fbSumme['ek_pauschal'], 'vk_fehlt' => $fbSumme['vk_fehlt']],
            ['vk' => $agSumme['vk_pro_person'], 'ek' => $agSumme['ek_pro_person'], 'pauschal' => $agSumme['pauschal'], 'ek_pauschal' => $agSumme['ek_pauschal'], 'vk_fehlt' => $agSumme['vk_fehlt']]];
    };
});

it('gleiche Preisfälle: Foodbook und Angebot liefern dieselbe Zeile und dieselbe Kapitel-Summe', function (string $fall) {
    [$block, $fbTyp, $agTyp] = match ($fall) {
        'Gericht je Person' => [['recipe_id' => $this->gericht->id], 'recipe_ref', 'recipe_ref'],
        'Gericht Menge 0,5' => [['recipe_id' => $this->gericht->id, 'quantity' => 0.5], 'recipe_ref', 'recipe_ref'],
        'Gericht Menge 2' => [['recipe_id' => $this->gericht->id, 'quantity' => 2], 'recipe_ref', 'recipe_ref'],
        'Gericht pauschal' => [['recipe_id' => $this->gericht->id, 'price_basis' => 'pauschal'], 'recipe_ref', 'recipe_ref'],
        'Gericht ohne VK' => [['recipe_id' => $this->ohneVk->id], 'recipe_ref', 'recipe_ref'],
        'Gericht ohne EK' => [['recipe_id' => $this->ohneEk->id], 'recipe_ref', 'recipe_ref'],
        'Preis-Kopf je Person' => [['price_basis' => 'person', 'price_value' => 7.5], 'header_frei_preis', 'header_preis'],
        'Preis-Kopf pauschal' => [['price_basis' => 'pauschal', 'price_value' => 150], 'header_frei_preis', 'header_preis'],
        'Konzept' => [['concept_id' => $this->konzept->id], 'concept_ref', 'concept_ref'],
        'Konzept mit eingebettetem Paket' => [['concept_id' => $this->mitPaket->id], 'concept_ref', 'concept_ref'],
        'Paket direkt' => [['concept_id' => $this->paket->id], 'concept_ref', 'concept_ref'],
    };
    [$fbZeile, $agZeile, $fbSumme, $agSumme] = ($this->paritaet)($this->t, $block, $fbTyp, $agTyp);

    expect($fbZeile)->toBe($agZeile)->and($fbSumme)->toBe($agSumme);
})->with(['Gericht je Person', 'Gericht Menge 0,5', 'Gericht Menge 2', 'Gericht pauschal', 'Gericht ohne VK', 'Gericht ohne EK',
    'Preis-Kopf je Person', 'Preis-Kopf pauschal', 'Konzept', 'Konzept mit eingebettetem Paket', 'Paket direkt']);

it('Konzept aus einem anderen Team (geerbt): Foodbook und Angebot rechnen im Kind-Team identisch (Team-Kontext, P1)', function () {
    $this->actingAs($this->makeUser($this->childA));
    [$fbZeile, $agZeile, $fbSumme, $agSumme] = ($this->paritaet)($this->childA, ['concept_id' => $this->konzept->id], 'concept_ref', 'concept_ref');

    expect($fbZeile)->toBe($agZeile)->and($fbSumme)->toBe($agSumme)->and($fbZeile['vk_pp'])->toBeGreaterThan(0.0);
});

it('Werte der Referenzfälle stimmen (nicht nur gleich, auch richtig)', function () {
    [$fb] = ($this->paritaet)($this->t, ['recipe_id' => $this->gericht->id, 'quantity' => 2], 'recipe_ref', 'recipe_ref');
    expect($fb['vk_pp'])->toBe(20.0)->and($fb['ek_pp'])->toBe(6.0)->and($fb['vk_fehlt'])->toBeFalse()->and($fb['ek_vollstaendig'])->toBeTrue();

    [$fb] = ($this->paritaet)($this->t, ['recipe_id' => $this->ohneVk->id], 'recipe_ref', 'recipe_ref');
    expect($fb['vk_fehlt'])->toBeTrue();

    [$fb] = ($this->paritaet)($this->t, ['concept_id' => $this->mitPaket->id], 'concept_ref', 'concept_ref');
    expect($fb['vk_pp'])->toBe((float) app(ConceptService::class)->preisCockpit($this->mitPaket->fresh(), null, $this->t)['price_per_person']);
});

it('Konzept mit eigener Servierform: Kapitel-Summe in Foodbook und Angebot rechnet mit der Konzept-Darreichung wie das Cockpit', function () {
    // Rundgang-Befund PR3: die Eager-Loads der Kapitel-Aggregate luden das Konzept ohne serving_form_id → Standard- statt Konzept-Form.
    $buffet = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->t->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->makeStandardDarreichung($this->gericht, 10.0, ['is_standard' => false, 'serving_form_id' => $buffet->id, 'sales_net' => 4.0]);
    $konzept = $this->makeConcept($this->t, 'Buffet-Menü', ['serving_form_id' => $buffet->id]);
    $this->makeConceptSlot($konzept, ['recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $soll = (float) app(ConceptService::class)->preisCockpit($konzept->fresh(), null, $this->t)['price_per_person'];

    [$fbZeile, $agZeile, $fbSumme, $agSumme] = ($this->paritaet)($this->t, ['concept_id' => $konzept->id], 'concept_ref', 'concept_ref');

    expect($soll)->toBe(4.0)
        ->and($fbZeile['vk_pp'])->toBe(4.0)->and($agZeile['vk_pp'])->toBe(4.0)
        ->and($fbSumme['vk'])->toBe(4.0)->and($agSumme['vk'])->toBe(4.0);

    // Foodbook-Editor: Kapitel-Board der Leitstelle lädt das Konzept eigens — muss dieselbe Form sehen.
    $fb = $this->makeFoodbook($this->t, 'Board', ['status' => 'entwurf']);
    $this->makeFoodbookBlock($this->makeChapter($fb), ['type' => 'concept_ref', 'concept_id' => $konzept->id]);
    $board = app(\Platform\FoodAlchemist\Services\Planung\LeitstelleService::class)->kapitelBoard($this->t, $fb->fresh(), null);
    expect((float) $board[0]['aggregat']['vk_pro_person'])->toBe(4.0);
});

/*
 * 98 · 3.6 PR4: Konzept-Slot als dritte Zeilenart. Dasselbe Gericht rechnet je Portion im Slot (preisCockpit → CockpitZeilen)
 * wie als Gericht-Block (blockPreis). Bekannte Abweichungen mit Entscheid (Kurator/Dominique, 26.11.):
 *   B1 EK mit Beigaben: die geltende Darreichung bringt ihren EK je Portion (ek_portion) mit — für eigene Gerichte überall.
 *   B2 Gericht ohne EK: der Slot markiert die EK-Lücke („Unbekanntes nie als 0“).
 *   B3 explizite Darreichung: preiswirksam nur im Konzept (P3), der Block rechnet die Standard-Darreichung — gewollt.
 *   B4 Kind-Team mit Master-Gericht: der Slot bleibt beim Master-EK, der Block nimmt die Team-Kosten (P1) — vorerst so (Dominique).
 */
function blockZeile(array $p): array
{
    return ['vk' => $p['vk_pp'], 'ek' => $p['ek_pp'], 'vk_fehlt' => $p['vk_fehlt'], 'ek_luecke' => ! $p['ek_vollstaendig']];
}

it('Konzept-Slot: dasselbe Gericht rechnet je Portion wie Foodbook- und Angebots-Block', function (string $fall) {
    [$gericht, $menge] = match ($fall) {
        'Gericht je Person' => [$this->gericht, 1],
        'Menge 0,5' => [$this->gericht, 0.5],
        'Menge 2' => [$this->gericht, 2],
        'Gericht ohne VK' => [$this->ohneVk, 1],
    };
    [$fbZeile, $agZeile] = ($this->paritaet)($this->t, ['recipe_id' => $gericht->id, 'quantity' => $menge], 'recipe_ref', 'recipe_ref');
    $slot = ($this->slotZeile)($this->t, ['recipe_id' => $gericht->id, 'quantity' => $menge]);

    expect($slot)->toBe(blockZeile($fbZeile))->and($slot)->toBe(blockZeile($agZeile));
})->with(['Gericht je Person', 'Menge 0,5', 'Menge 2', 'Gericht ohne VK']);

it('Konzept-Slot B1: EK mit Beigaben (ek_portion der Darreichung)', function () {
    $g = $this->makeRecipe($this->t, 'Mit Beigabe', ['is_sales_recipe' => true, 'ek_total_eur' => 30.0, 'sales_unit_count' => 10]);
    $this->makeStandardDarreichung($g, 12.0, ['ek_portion' => 4.5]);
    [$fbZeile, $agZeile] = ($this->paritaet)($this->t, ['recipe_id' => $g->id], 'recipe_ref', 'recipe_ref');

    $slot = ($this->slotZeile)($this->t, ['recipe_id' => $g->id]);

    expect($slot['ek'])->toBe(4.5)->and($slot)->toBe(blockZeile($fbZeile))->and($slot)->toBe(blockZeile($agZeile));   // B1: überall mit Beigaben
});

it('Konzept-Slot B2: Gericht ohne EK', function () {
    [$fbZeile] = ($this->paritaet)($this->t, ['recipe_id' => $this->ohneEk->id], 'recipe_ref', 'recipe_ref');

    expect(($this->slotZeile)($this->t, ['recipe_id' => $this->ohneEk->id]))->toBe(['vk' => 8.0, 'ek' => 0.0, 'vk_fehlt' => false, 'ek_luecke' => true])   // B2: Lücke markiert
        ->and(($this->slotZeile)($this->t, ['recipe_id' => $this->ohneEk->id]))->toBe(blockZeile($fbZeile));
});

it('Konzept-Slot B3: explizite Darreichung wirkt nur im Konzept (P3, gewollt)', function () {
    $buffet = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->t->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $d = $this->makeStandardDarreichung($this->gericht, 10.0, ['is_standard' => false, 'serving_form_id' => $buffet->id, 'sales_net' => 4.0]);
    [$fbZeile] = ($this->paritaet)($this->t, ['recipe_id' => $this->gericht->id, 'presentation_id' => $d->id], 'recipe_ref', 'recipe_ref');

    expect(($this->slotZeile)($this->t, ['recipe_id' => $this->gericht->id, 'presentation_id' => $d->id])['vk'])->toBe(4.0)
        ->and($fbZeile['vk_pp'])->toBe(10.0);
});

it('Konzept-Slot B4: Kind-Team mit Master-Gericht — Slot Master-EK, Block Team-Kosten (vorerst so)', function () {
    $this->actingAs($this->makeUser($this->childA));
    [$fbZeile] = ($this->paritaet)($this->childA, ['recipe_id' => $this->gericht->id], 'recipe_ref', 'recipe_ref');

    expect(($this->slotZeile)($this->childA, ['recipe_id' => $this->gericht->id])['ek'])->toBe(3.0)
        ->and(blockZeile($fbZeile))->toBe(['vk' => 10.0, 'ek' => 0.0, 'vk_fehlt' => false, 'ek_luecke' => true]);
});

it('Konzept-Slot B2 in der Oberfläche: Detail-Panel nennt den fehlenden EK, nicht nur das Portionsgewicht', function () {
    $k = $this->makeConcept($this->t, 'Ohne-EK-Menü');
    $this->makeConceptSlot($k, ['recipe_id' => $this->ohneEk->id, 'quantity' => 1]);

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\DetailPanel::class)->call('zeige', 'concepts', $k->id)
        ->assertSee('Einkauf unvollständig: einem Gericht fehlt der EK oder das Portionsgewicht.');
    // Positions-Tabelle im Editor: unbekannter EK als „offen“ (null), nicht 0,00 €
    expect(app(ConceptService::class)->preisCockpit($k->fresh(), null, $this->t)['zeilen'][0]['ek'])->toBeNull();
    // Gericht-Karte im Editor: EK fehlt, VK bekannt → Wareneinsatz „offen“, nicht „ohne VK“
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)->call('oeffnen', 'concepts', $k->id)
        ->assertSee('EK fehlt')->assertDontSee('ohne VK');
});
