<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Position\PositionFehler;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.6 PR6: Schreibprüfung für Menge, Einheit, Darreichung, Wahlgruppe und Staffel — in Konzept-Slot, Foodbook- und
 * Angebots-Block. Entscheide 26.11. (B7 Dominique; B5, B6, B8, B9 Kurator, Dominique ohne Präferenz; auf io kein Bestand):
 *   B5 Menge: > 0 (Slot, Gericht, Konzept), Angebots-Position ≥ 0, leer = 1, keine Zahl abgelehnt.
 *   B6 Einheit nur an der Angebots-Position; im Slot weiter nur wirksame Einheiten.
 *   B7 Darreichung: beim Gerichtswechsel zurückgesetzt (Slot, Angebots-Block); fuerSlot nimmt nur eine Darreichung des Gerichts;
 *      im Foodbook ausdrücklich abgelehnt.
 *   B8 Wahlgruppe nur für bepreiste Typen eines Kapitels.
 *   B9 Staffel nur am Preis-Kopf (header_frei_preis).
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
    $this->angebot = app(AngebotService::class)->create($this->t, ['name' => 'Angebot']);
    $this->agKap = FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $this->angebot->id, 'title' => 'Kapitel', 'position' => 0]);
    $this->agKap2 = FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $this->angebot->id, 'title' => 'Zweites', 'position' => 1]);
});

it('B5 Menge: 0, negativ und keine Zahl abgelehnt; Angebots-Position darf 0; leer bleibt erlaubt', function () {
    $slot = $this->konzepte->fillSlot($this->t, $this->slot->id, ['recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $gericht = ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id];

    expect(fn () => $this->konzepte->fillSlot($this->t, $slot->id, ['recipe_id' => $this->gericht->id, 'quantity' => -1]))->toThrow(PositionFehler::class, 'Menge muss größer als 0 sein.')
        ->and(fn () => $this->konzepte->setSlotMengeEinheit($this->t, $slot->id, 0.0))->toThrow(PositionFehler::class, 'Menge muss größer als 0 sein.')
        ->and(fn () => $this->fb->addBlock($this->t, $this->fbKap->id, $gericht + ['quantity' => -1]))->toThrow(PositionFehler::class, 'Menge muss größer als 0 sein.')
        ->and(fn () => $this->fb->addBlock($this->t, $this->fbKap->id, $gericht + ['quantity' => 'viel']))->toThrow(PositionFehler::class, 'Menge muss eine Zahl sein.')
        ->and(fn () => $this->ag->addBlock($this->t, $this->agKap->id, $gericht + ['quantity' => 0]))->toThrow(PositionFehler::class, 'Menge muss größer als 0 sein.')
        ->and(fn () => $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'position', 'label' => 'Service', 'price_value' => 5, 'quantity' => -1]))->toThrow(PositionFehler::class, 'Menge darf nicht negativ sein.');

    $null = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'position', 'label' => 'Getränke', 'price_value' => 5, 'quantity' => 0]);
    $leer = $this->fb->addBlock($this->t, $this->fbKap->id, $gericht + ['quantity' => '']);
    expect((float) $null->fresh()->quantity)->toBe(0.0)->and($leer->fresh()->quantity)->toBeNull()
        ->and(app(FoodbookAggregat::class)->blockPreis($leer->fresh(), null, null, $this->t)['vk_pp'])->toBe(10.0)
        ->and((float) $slot->fresh()->quantity)->toBe(1.0);
});

it('B6 Einheit: nur an der Angebots-Position', function () {
    $gericht = ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id, 'quantity' => 200, 'unit_vocab_id' => $this->gramm->id];

    expect(fn () => $this->fb->addBlock($this->t, $this->fbKap->id, $gericht))->toThrow(PositionFehler::class, 'Eine Einheit gibt es nur an einer Angebots-Position.')
        ->and(fn () => $this->ag->addBlock($this->t, $this->agKap->id, $gericht))->toThrow(PositionFehler::class, 'Eine Einheit gibt es nur an einer Angebots-Position.');
    $pos = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'position', 'label' => 'Wein', 'price_value' => 5, 'quantity' => 2, 'unit_vocab_id' => $this->gramm->id]);
    expect($pos->fresh()->unit_vocab_id)->toBe($this->gramm->id);
});

it('B7 Darreichung: Gerichtswechsel setzt sie zurück (Slot, Angebot), fuerSlot nimmt nur eine des Gerichts, Foodbook lehnt sie ab', function () {
    $this->konzepte->fillSlot($this->t, $this->slot->id, ['recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $this->konzepte->setSlotDarreichung($this->t, $this->slot->id, $this->dar->id);
    $gleich = $this->konzepte->fillSlot($this->t, $this->slot->id, ['recipe_id' => $this->gericht->id, 'quantity' => 2]);
    expect($gleich->presentation_id)->toBe($this->dar->id);   // gleiches Gericht: Darreichung bleibt
    $slot = $this->konzepte->fillSlot($this->t, $this->slot->id, ['recipe_id' => $this->anderes->id, 'quantity' => 1]);

    $agBlock = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id, 'presentation_id' => $this->dar->id]);
    $agBlock = $this->ag->updateBlock($this->t, $agBlock->id, ['recipe_id' => $this->anderes->id]);

    expect($slot->presentation_id)->toBeNull()
        ->and($this->konzepte->preisCockpit($this->konzept->fresh(), null, $this->t)['zeilen'][0]['price'])->toBe(14.0)
        ->and($agBlock->fresh()->presentation_id)->toBeNull()
        ->and(fn () => $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id, 'presentation_id' => $this->dar->id]))
        ->toThrow(PositionFehler::class, 'Im Foodbook gibt es keine Darreichung je Block, sie kommt aus dem Konzept.');

    // Altbestand: eine fremde Darreichung am Slot zählt nicht, es gilt wieder Konzept-Form bzw. Standard des Gerichts
    $slot->forceFill(['presentation_id' => $this->dar->id])->save();
    expect(app(\Platform\FoodAlchemist\Services\Rezept\DarreichungResolver::class)->fuerSlot($slot->fresh())?->recipe_id)->toBe($this->anderes->id)
        ->and($this->konzepte->preisCockpit($this->konzept->fresh(), null, $this->t)['zeilen'][0]['price'])->toBe(14.0);
});

it('B8 Wahlgruppe: nur bepreiste Typen eines Kapitels; Aufheben geht immer', function () {
    $fb1 = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id]);
    $fb2 = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->anderes->id]);
    $fbText = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'text', 'label' => 'Hinweis']);
    $fbAnders = $this->fb->addBlock($this->t, $this->fbKap2->id, ['type' => 'recipe_ref', 'recipe_id' => $this->anderes->id]);
    $ag1 = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id]);
    $agPos = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'position', 'label' => 'Service', 'price_value' => 50]);
    $agAnders = $this->ag->addBlock($this->t, $this->agKap2->id, ['type' => 'recipe_ref', 'recipe_id' => $this->anderes->id]);

    expect(fn () => $this->fb->setVariantGroup($this->t, [$fb1->id, $fbText->id], 1))->toThrow(PositionFehler::class, 'In eine Wahlgruppe gehören nur Gerichte, Konzepte und Preis-Köpfe.')
        ->and(fn () => $this->fb->setVariantGroup($this->t, [$fb1->id, $fbAnders->id], 1))->toThrow(PositionFehler::class, 'Eine Wahlgruppe gehört zu genau einem Kapitel.')
        ->and(fn () => $this->ag->setVariantGroup($this->t, [$ag1->id, $agPos->id], 1))->toThrow(PositionFehler::class, 'In eine Wahlgruppe gehören nur Gerichte, Konzepte und Preis-Köpfe.')
        ->and(fn () => $this->ag->setVariantGroup($this->t, [$ag1->id, $agAnders->id], 1))->toThrow(PositionFehler::class, 'Eine Wahlgruppe gehört zu genau einem Kapitel.')
        ->and(fn () => $this->fb->updateBlock($this->t, $fbText->id, ['variant_group_id' => 3]))->toThrow(PositionFehler::class, 'In eine Wahlgruppe gehören nur Gerichte, Konzepte und Preis-Köpfe.');

    $this->fb->setVariantGroup($this->t, [$fb1->id, $fb2->id], 1);
    $this->fb->setVariantGroup($this->t, [$fb1->id], null);
    expect([$fb1->fresh()->variant_group_id, $fb2->fresh()->variant_group_id, $fbText->fresh()->variant_group_id])->toBe([null, 1, null]);
});

it('B8 in der Oberfläche: eine abgelehnte Wahlgruppe zeigt einen Hinweis statt einer Fehlerseite', function () {
    $fb1 = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id]);
    $fbAnders = $this->fb->addBlock($this->t, $this->fbKap2->id, ['type' => 'recipe_ref', 'recipe_id' => $this->anderes->id]);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)->call('waehle', $this->fbKap->foodbook_id)->set('selectedKapitelId', $this->fbKap->id)
        ->set('markiert', [$fb1->id, $fbAnders->id])->call('wahlGruppeBilden')->assertHasNoErrors()->assertDispatched('fa-saved', type: 'error');

    $ag1 = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id]);
    $agAnders = $this->ag->addBlock($this->t, $this->agKap2->id, ['type' => 'recipe_ref', 'recipe_id' => $this->anderes->id]);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Angebote\Editor::class)->call('oeffnen', $this->angebot->id)->set('selectedKapitelId', $this->agKap->id)
        ->set('markiert', [$ag1->id, $agAnders->id])->call('wahlGruppeBilden')->assertDispatched('fa-saved', type: 'error');
    expect($fb1->fresh()->variant_group_id)->toBeNull()->and($ag1->fresh()->variant_group_id)->toBeNull();
});

it('B9 Staffel: nur am Preis-Kopf', function () {
    $gericht = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'recipe_ref', 'recipe_id' => $this->gericht->id]);
    $kopf = $this->fb->addBlock($this->t, $this->fbKap->id, ['type' => 'header_frei_preis', 'label' => 'Buffet', 'price_basis' => 'staffel']);

    expect(fn () => $this->fb->setStaffel($this->t, $gericht->id, [['min_persons' => 1, 'price' => 99.0]]))->toThrow(PositionFehler::class, 'Staffelpreise gibt es nur an einem Preis-Kopf.');
    $this->fb->setStaffel($this->t, $kopf->id, [['min_persons' => 1, 'price' => 20.0], ['min_persons' => 50, 'price' => 18.0]]);
    expect($kopf->fresh()->staffel()->count())->toBe(2)->and($gericht->fresh()->staffel()->count())->toBe(0);
});

it('B5 in der Oberfläche: negative Menge im Angebots-Editor und 0 im Konzept-Editor zeigen eine Meldung, keine Fehlerseite', function () {
    $pos = $this->ag->addBlock($this->t, $this->agKap->id, ['type' => 'position', 'label' => 'Garderobe', 'price_value' => 2, 'quantity' => 3]);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Angebote\Editor::class)->call('oeffnen', $this->angebot->id)
        ->call('blockBearbeiten', $pos->id)->set('blockForm.quantity', '-1')->call('blockSpeichern')
        ->assertDispatched('fa-saved', type: 'error')->assertSet('editBlockId', $pos->id);   // Formular bleibt offen zum Korrigieren
    expect((float) $pos->fresh()->quantity)->toBe(3.0);

    $slot = $this->konzepte->fillSlot($this->t, $this->slot->id, ['recipe_id' => $this->gericht->id, 'quantity' => 1]);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)->call('oeffnen', 'concepts', $this->konzept->id)
        ->set("slotForm.{$slot->id}.quantity", '0')->call('mengeSpeichern', $slot->id)
        ->assertHasErrors("slotForm.{$slot->id}.unit_vocab_id")->assertSee('Menge muss größer als 0 sein.');
    expect((float) $slot->fresh()->quantity)->toBe(1.0);
});
