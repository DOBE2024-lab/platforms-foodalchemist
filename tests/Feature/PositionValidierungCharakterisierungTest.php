<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.6 PR5: heutiger Stand der Schreibprüfung für Menge, Einheit, Darreichung, Wahlgruppe und Staffel — in Konzept-Slot,
 * Foodbook- und Angebots-Block. B5–B9 sind bekannte Lücken, über die Dominique entscheidet (Kurator 26.11.); bis dahin friert
 * dieser Test sie ein, damit PR5 (gemeinsame Prüfung ohne Verhaltensänderung) nichts still verschiebt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->t = $this->rootTeam;
    $this->gericht = $this->makeRecipe($this->t, 'Roulade', ['is_sales_recipe' => true, 'ek_total_eur' => 30.0, 'sales_unit_count' => 10]);
    $this->dar = $this->makeStandardDarreichung($this->gericht, 10.0);
    $this->anderes = $this->makeRecipe($this->t, 'Zander', ['is_sales_recipe' => true, 'ek_total_eur' => 40.0, 'sales_unit_count' => 10]);
    $this->makeStandardDarreichung($this->anderes, 14.0);
    $this->gramm = FoodAlchemistVocabEinheit::create(['team_id' => $this->t->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->konzepte = app(ConceptService::class);
    $this->konzept = $this->konzepte->create($this->t, ['name' => 'Menü']);
    $this->slot = $this->konzepte->addSlot($this->t, $this->konzept->id, ['role' => 'Hauptgang']);

    $this->fb = app(FoodbookService::class);
    $this->fbKap = $this->makeChapter($this->makeFoodbook($this->t, 'Foodbook', ['status' => 'entwurf']));
    $this->fbKap2 = $this->makeChapter($this->fbKap->foodbook, ['title' => 'Zweites']);

    $this->ag = app(OfferCompositionService::class);
    $angebot = app(AngebotService::class)->create($this->t, ['name' => 'Angebot']);
    $this->agKap = FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $angebot->id, 'title' => 'Kapitel', 'position' => 0]);
    $this->agKap2 = FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $angebot->id, 'title' => 'Zweites', 'position' => 1]);
});

it('B5 Menge: 0 und negativ gehen heute überall durch — negativ ergibt einen negativen Preis', function () {
    $slot = $this->konzepte->fillSlot($this->t, $this->slot->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => -1]);
    $this->konzepte->setSlotMengeEinheit($this->t, $slot->id, 0.0);
    $fbBlock = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => -1]);
    $agBlock = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => -2]);

    expect((float) $slot->fresh()->quantity)->toBe(0.0)
        ->and(app(FoodbookAggregat::class)->blockPreis($fbBlock->fresh(), null, null, $this->t)['vk_pp'])->toBe(-10.0)
        ->and($this->ag->blockPreis($agBlock->fresh(), null, $this->t)['vk_pp'])->toBe(-20.0);
});

it('B6 Einheit: Gericht-Blöcke nehmen jede sichtbare Einheit an, gerechnet wird in Portionen (200 g = 200 Portionen)', function () {
    $fbBlock = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => 200, 'unit_vocab_id' => $this->gramm->id]);
    $agBlock = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => 200, 'unit_vocab_id' => $this->gramm->id]);

    expect($fbBlock->fresh()->unit_vocab_id)->toBe($this->gramm->id)->and($agBlock->fresh()->unit_vocab_id)->toBe($this->gramm->id)
        ->and(app(FoodbookAggregat::class)->blockPreis($fbBlock->fresh(), null, null, $this->t)['vk_pp'])->toBe(2000.0);
});

it('B7 Darreichung: beim Gerichtswechsel bleibt sie stehen (Slot, Angebot), im Foodbook wird sie still verworfen', function () {
    $this->konzepte->fillSlot($this->t, $this->slot->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $this->konzepte->setSlotDarreichung($this->t, $this->slot->id, $this->dar->id);
    $slot = $this->konzepte->fillSlot($this->t, $this->slot->id, ['sales_recipe_id' => $this->anderes->id, 'quantity' => 1]);

    $agBlock = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->dar->id]);
    $agBlock = $this->ag->updateBlock($this->t, $agBlock->id, ['sales_recipe_id' => $this->anderes->id]);
    $fbBlock = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->dar->id]);

    expect($slot->presentation_id)->toBe($this->dar->id)                 // Darreichung des alten Gerichts
        ->and($this->konzepte->preisCockpit($this->konzept->fresh(), null, $this->t)['zeilen'][0]['price'])->toBe(10.0)   // rechnet mit ihr (Zander: 14)
        ->and($agBlock->fresh()->presentation_id)->toBe($this->dar->id)
        ->and($fbBlock->fresh()->presentation_id)->toBeNull();
});

it('B8 Wahlgruppe: Blöcke aus zwei Kapiteln und Struktur-Blöcke lassen sich gruppieren', function () {
    $fb1 = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
    $fbText = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'text', 'label' => 'Hinweis']);
    $fbAnders = $this->fb->addBlock($this->t, $this->fbKap2->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->anderes->id]);
    $this->fb->setVariantGroup($this->t, [$fb1->id, $fbText->id, $fbAnders->id], 1);

    $ag1 = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
    $agPos = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'position', 'label' => 'Service', 'price_value' => 50]);
    $agAnders = $this->ag->addBlock($this->t, $this->agKap2->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->anderes->id]);
    $this->ag->setVariantGroup($this->t, [$ag1->id, $agPos->id, $agAnders->id], 1);

    expect([$fb1->fresh()->variant_group_id, $fbText->fresh()->variant_group_id, $fbAnders->fresh()->variant_group_id])->toBe([1, 1, 1])
        ->and([$ag1->fresh()->variant_group_id, $agPos->fresh()->variant_group_id, $agAnders->fresh()->variant_group_id])->toBe([1, 1, 1]);
});

it('B9 Staffel: Stufen lassen sich an jedem Foodbook-Block speichern, gerechnet werden sie nur am Preis-Kopf mit Basis staffel', function () {
    $block = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
    $this->fb->setStaffel($this->t, $block->id, [['min_persons' => 1, 'price' => 99.0]]);

    expect($block->fresh()->staffel()->count())->toBe(1)
        ->and(app(FoodbookAggregat::class)->blockPreis($block->fresh(), 50, null, $this->t)['vk_pp'])->toBe(10.0);
});
