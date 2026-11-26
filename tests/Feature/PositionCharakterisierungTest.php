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
 * Umstellung ist damit sichtbar und gewollt, nichts ändert sich still. PR1 (Prüfung): P6, P9, Preisbasis beim Ändern, Staffelform.
 * PR2 (Preis): P1 Team-Kontext im Foodbook, P2 vk_fehlt, P8 ek_pauschal — Foodbook und Angebot liefern dieselbe Zeile.
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

    expect($fb)->toBe($ag)                                  // PR2 (P1): eine Regel, Team-Kontext in beiden
        ->and($fb['vk_pp'])->toBe(20.0)->and($fb['ek_pp'])->toBe(6.0)->and($fb['ek_vollstaendig'])->toBeTrue();
});

it('P2 fehlender VK: Foodbook und Angebot melden vk_fehlt (vorher Foodbook still 0)', function () {
    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->ohneVk->id]));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->ohneVk->id]));

    expect($fb['vk_pp'])->toBe(0.0)->and($fb['vk_fehlt'])->toBeTrue()
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

it('P8 Gericht pauschal: Foodbook und Angebot führen den EK als ek_pauschal mit (vorher fiel er weg)', function () {
    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'price_basis' => 'pauschal']));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'price_basis' => 'pauschal']));

    expect($fb)->toBe($ag)
        ->and($fb['pauschal'])->toBe(10.0)->and($fb['ek_pp'])->toBe(0.0)->and($fb['ek_pauschal'])->toBe(3.0);
});

it('Konzept: Foodbook und Angebot rechnen dasselbe Konzept identisch (PR2: Team-Kontext in beiden)', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]);

    $fb = ($this->fbPreis)(($this->fbBlock)(['type' => 'concept_ref', 'concept_id' => $konzept->id]));
    $ag = ($this->agPreis)(($this->agBlock)(['type' => 'concept_ref', 'concept_id' => $konzept->id]));

    expect($fb)->toBe($ag)->and($fb['vk_pp'])->toBe(10.0);
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

it('P6 header_preis im Konzept: neue Blöcke werden abgelehnt (er rechnete nie mit); Bestand bleibt im Preis ungezählt', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]);
    expect(fn () => app(ConceptService::class)->addBlock($this->t, $konzept->id, 'header_preis', ['title' => 'Aufpreis', 'price_value' => 5]))
        ->toThrow(RuntimeException::class, 'Unbekannter Block-Typ');

    FoodAlchemistConceptSlot::create(['team_id' => $this->t->id, 'concept_id' => $konzept->id, 'type' => 'header_preis', 'position' => 9,
        'price_value' => 5, 'price_basis' => 'person']);   // Bestand (auf io: 0 Zeilen)
    expect((float) app(ConceptService::class)->preisCockpit($konzept->fresh(), null, $this->t)['price_per_person'])->toBe(10.0);
});

it('Staffel: Foodbook-Preiskopf rechnet die Stufe zur Pax; das Angebot lehnt Staffel ab (Ziel P5: bleibt so)', function () {
    $b = ($this->fbBlock)(['type' => 'header_frei_preis', 'price_basis' => 'staffel']);
    app(FoodbookService::class)->setStaffel($this->t, $b->id, [['min_persons' => 1, 'price' => 30], ['min_persons' => 50, 'price' => 25]]);

    expect(($this->fbPreis)($b, 80)['vk_pp'])->toBe(25.0)->and(($this->fbPreis)($b, 10)['vk_pp'])->toBe(30.0);
    expect(fn () => app(OfferCompositionService::class)->addBlock($this->t, $this->kap->id, ['type' => 'header_preis', 'price_basis' => 'staffel', 'price_value' => 5]))
        ->toThrow(RuntimeException::class, 'Staffelpreise gibt es nur im Foodbook');
    expect(fn () => app(FoodbookService::class)->setStaffel($this->t, $b->id, [['min_persons' => 10, 'price' => 30], ['min_persons' => 10, 'price' => 25]]))
        ->toThrow(RuntimeException::class, 'doppelt');   // 98 · 3.6 PR1: Staffelform geprüft
});

it('P9 unbekannter Typ: alle drei Arten lehnen ab (vorher fiel das Foodbook still auf text zurück)', function () {
    expect(fn () => app(FoodbookService::class)->addBlock($this->t, $this->fb->id, ['type' => 'quatsch']))->toThrow(RuntimeException::class, 'Unbekannter Block-Typ');
    expect(fn () => app(OfferCompositionService::class)->addBlock($this->t, $this->kap->id, ['type' => 'quatsch']))->toThrow(RuntimeException::class, 'Unbekannter Block-Typ');
    expect(fn () => app(ConceptService::class)->addBlock($this->t, $this->makeConcept($this->t, 'K')->id, 'quatsch'))->toThrow(RuntimeException::class, 'Unbekannter Block-Typ');
});

it('Preisbasis: eine Prüfung beim Anlegen UND Ändern in Foodbook und Angebot; Aliasse pro_person/pro_stueck kanonisch', function () {
    $fb = ($this->fbBlock)(['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => 5]);
    expect(fn () => app(FoodbookService::class)->updateBlock($this->t, $fb->id, ['price_basis' => 'quatsch']))->toThrow(RuntimeException::class, 'Unbekannte Preis-Basis');
    app(FoodbookService::class)->updateBlock($this->t, $fb->id, ['price_basis' => 'pro_stueck']);
    expect($fb->fresh()->price_basis)->toBe('pauschal');

    $ag = ($this->agBlock)(['type' => 'header_preis', 'price_basis' => 'person', 'price_value' => 5]);
    expect(fn () => app(OfferCompositionService::class)->updateBlock($this->t, $ag->id, ['price_basis' => 'quatsch']))->toThrow(RuntimeException::class, 'Unbekannte Preis-Basis');
    expect(fn () => app(OfferCompositionService::class)->addBlock($this->t, $this->kap->id, ['type' => 'header_preis', 'price_basis' => 'quatsch', 'price_value' => 5]))
        ->toThrow(RuntimeException::class, 'Unbekannte Preis-Basis');
});
