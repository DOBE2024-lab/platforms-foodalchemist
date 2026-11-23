<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.6 PR0 — Charakterisierung der drei Positionsarten (Concept-Slot, Foodbook-Block, Angebots-Block) VOR dem
 * gemeinsamen Positions-Kern. Friert den heutigen Stand ein; die bekannten Abweichungen A1–A9 aus dem Plan sind je Test
 * markiert, mit dem entschiedenen Ziel (Kurator/Dominique). Die PRs 1–4 drehen genau diese Erwartungen um — jede
 * Umstellung ist damit sichtbar und gewollt, nichts ändert sich still.
 *
 * Entscheide: P1 Foodbook rechnet mit Team-Kontext wie das Angebot (Festpreise bleiben angebotsspezifisch) ·
 * P2 fehlender VK überall vk_fehlt · P3 Darreichung nur im Konzept preiswirksam · P4 Wahlgruppen als Spanne, Kapitel mit
 * der teuersten · P5 keine Staffel im Angebot · P6 header_preis für neue Slots verboten · P7 Rundung je Zeile 2 Stellen ·
 * P8 Gericht pauschal mit EK · P9 unbekannter Typ überall abgelehnt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->t = $this->rootTeam;
    // Gericht: Ansatz 10 Portionen, EK 30 € → 3 €/Portion; Standard-Darreichung VK 10 €, Buffet-Darreichung VK 12 €
    $this->gericht = $this->makeRecipe($this->t, 'Rinderroulade', ['is_sales_recipe' => true, 'ek_total_eur' => 30.0, 'sales_unit_count' => 10]);
    $this->std = $this->makeStandardDarreichung($this->gericht, 10.0);
    $buffet = FoodAlchemistServierform::create(['team_id' => $this->t->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->buffet = $this->makeStandardDarreichung($this->gericht, 12.0, ['serving_form_id' => $buffet->id, 'is_standard' => false]);
    // Gericht ohne VK (keine Darreichung mit Preis)
    $this->ohneVk = $this->makeRecipe($this->t, 'Ohne Preis', ['is_sales_recipe' => true, 'ek_total_eur' => 20.0, 'sales_unit_count' => 10]);

    $this->fb = $this->makeChapter($this->makeFoodbook($this->t, 'Foodbook 2027', ['status' => 'entwurf']));
    $angebot = app(AngebotService::class)->create($this->t, ['name' => 'Hochzeit']);
    $this->kap = FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $angebot->id, 'title' => 'Hauptgang', 'position' => 0]);
    $this->fbBlock = fn (array $a) => $this->makeFoodbookBlock($this->fb, $a);
    $this->agBlock = fn (array $a) => FoodAlchemistOfferBlock::create(array_merge(['team_id' => $this->t->id, 'chapter_id' => $this->kap->id,
        'position' => 0, 'visible' => true], $a));
    $this->fbPreis = fn (FoodAlchemistFoodbookBlock $b, ?int $pax = null) => app(FoodbookAggregat::class)->blockPreis($b->fresh(), $pax);
    $this->agPreis = fn (FoodAlchemistOfferBlock $b) => app(OfferCompositionService::class)->blockPreis($b->fresh(), null, $this->t);
});

it('Gericht je Person: Foodbook und Angebot rechnen dieselbe Standard-Darreichung × Menge', function () {
    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => 2]));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => 2]));

    expect($fb['vk_pp'])->toBe(20.0)->and($ag['vk_pp'])->toBe(20.0)
        ->and($fb['ek_pp'])->toBe(6.0)
        ->and($fb)->not->toHaveKey('ek_vollstaendig')      // A3/A2: nur das Angebot meldet die EK-Vollständigkeit (Ziel: beide)
        ->and($ag)->toHaveKey('ek_vollstaendig');
});

it('A2/A3 fehlender VK: Foodbook rechnet 0 ohne Merker, Angebot (mit Team) meldet vk_fehlt — Ziel P2: überall vk_fehlt', function () {
    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->ohneVk->id]));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->ohneVk->id]));

    expect($fb['vk_pp'])->toBe(0.0)->and($fb)->not->toHaveKey('vk_fehlt')
        ->and($ag['vk_pp'])->toBe(0.0)->and($ag['vk_fehlt'])->toBeTrue();
});

it('A4 Darreichung: am Angebots-Block gesetzt, aber im Preis ignoriert (Ziel P3: bleibt so, nur Konzept preiswirksam)', function () {
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->buffet->id]));

    expect($ag['vk_pp'])->toBe(10.0);   // Standard, nicht Buffet (12)
});

it('Darreichung im Konzept ist preiswirksam (Referenz für P3)', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'presentation_id' => $this->buffet->id]);

    $c = app(ConceptService::class)->preisCockpit($konzept->fresh(), null, $this->t);

    expect((float) $c['price_per_person'])->toBe(12.0);
});

it('A8 Gericht pauschal: EK fällt in Foodbook und Angebot weg (Ziel P8: EK mitführen)', function () {
    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'price_basis' => 'pauschal']));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'price_basis' => 'pauschal']));

    expect($fb['pauschal'])->toBe(10.0)->and($fb['ek_pp'])->toBe(0.0)->and($fb)->not->toHaveKey('ek_pauschal')
        ->and($ag['pauschal'])->toBe(10.0)->and($ag['ek_pp'])->toBe(0.0)->and($ag)->not->toHaveKey('ek_pauschal');
});

it('Konzept: Foodbook und Angebot rechnen dasselbe Konzept (ohne Fassung) heute gleich — A1 betrifft Team/Festpreis', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]);

    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'concept_ref', 'concept_id' => $konzept->id]));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'concept_ref', 'concept_id' => $konzept->id]));

    expect($fb['vk_pp'])->toBe($ag['vk_pp'])->and($fb['ek_pp'])->toBe($ag['ek_pp'])
        ->and($fb)->not->toHaveKey('ek_vollstaendig')->and($ag['ek_vollstaendig'])->toBeTrue();
});

it('A6 Wahlgruppen: beide Kapitel-Aggregate summieren alle Alternativen (Ziel P4: Spanne, Kapitel mit der teuersten)', function () {
    foreach ([10.0, 14.0] as $i => $preis) {
        ($this->fbBlock)(['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => $preis, 'variant_group_id' => 1, 'position' => $i]);
        ($this->agBlock)(['type' => 'header_preis', 'price_basis' => 'person', 'price_value' => $preis, 'variant_group_id' => 1, 'position' => $i]);
    }

    $fb = app(FoodbookAggregat::class)->kapitelAggregat($this->t, $this->fb->fresh());
    $ag = app(OfferCompositionService::class)->kapitelAggregat($this->t, $this->kap->fresh());

    expect($fb['vk_pro_person'])->toBe(24.0)->and($ag['vk_pro_person'])->toBe(24.0);
});

it('A7 header_preis im Konzept: gespeichert, aber im Preis nicht gezählt (Ziel P6: für neue Slots verboten, Bestand bleibt)', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $slot = app(ConceptService::class)->addBlock($this->t, $konzept->id, 'header_preis', ['label' => 'Aufpreis', 'price_value' => 5]);

    expect($slot)->toBeInstanceOf(FoodAlchemistConceptSlot::class)
        ->and((float) app(ConceptService::class)->preisCockpit($konzept->fresh(), null, $this->t)['price_per_person'])->toBe(10.0);
});

it('Staffel: Foodbook-Preiskopf rechnet die Stufe zur Pax; das Angebot lehnt Staffel ab (Ziel P5: bleibt so)', function () {
    $b = ($this->fbBlock)(['type' => 'header_frei_preis', 'price_basis' => 'staffel']);
    app(FoodbookService::class)->setStaffel($this->t, $b->id, [['min_persons' => 1, 'price' => 30], ['min_persons' => 50, 'price' => 25]]);

    expect(($this->fbPreis)($b, 80)['vk_pp'])->toBe(25.0)->and(($this->fbPreis)($b, 10)['vk_pp'])->toBe(30.0);
    expect(fn () => app(OfferCompositionService::class)->addBlock($this->t, $this->kap->id, ['type' => 'header_preis', 'price_basis' => 'staffel', 'price_value' => 5]))
        ->toThrow(RuntimeException::class);
});

it('A9 unbekannter Typ: Foodbook fällt still auf text, Angebot und Konzept lehnen ab (Ziel P9: überall ablehnen)', function () {
    $fb = app(FoodbookService::class)->addBlock($this->t, $this->fb->id, ['type' => 'quatsch']);
    expect($fb->type)->toBe('text');

    expect(fn () => app(OfferCompositionService::class)->addBlock($this->t, $this->kap->id, ['type' => 'quatsch']))->toThrow(RuntimeException::class);
    expect(fn () => app(ConceptService::class)->addBlock($this->t, $this->makeConcept($this->t, 'K')->id, 'quatsch'))->toThrow(RuntimeException::class);
});

it('A8 Preisbasis: nur Angebot-addBlock prüft sie; Foodbook und Angebot nehmen beim Ändern jede Preisbasis an (Ziel: eine Prüfung im Kern, add und update)', function () {
    $fb = ($this->fbBlock)(['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => 5]);
    app(FoodbookService::class)->updateBlock($this->t, $fb->id, ['price_basis' => 'quatsch']);
    expect($fb->fresh()->price_basis)->toBe('quatsch');

    $ag = ($this->agBlock)(['type' => 'header_preis', 'price_basis' => 'person', 'price_value' => 5]);
    app(OfferCompositionService::class)->updateBlock($this->t, $ag->id, ['price_basis' => 'quatsch']);
    expect($ag->fresh()->price_basis)->toBe('quatsch');

    expect(fn () => app(OfferCompositionService::class)->addBlock($this->t, $this->kap->id, ['type' => 'header_preis', 'price_basis' => 'quatsch', 'price_value' => 5]))
        ->toThrow(RuntimeException::class);
});
