<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel as GpDetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\LesemodusDom;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · Lesemodus-Anzeige (Spec 65): Reine Anzeige-Bedienung bleibt ohne „Bearbeiten“ nutzbar. Erster Fall: Preisverlauf
 * (Zeitraum 3 M/6 M/12 M/Alles/von–bis, „Alle Artikel“) in GP-Detailspalte, GP-Dialog und LA-Dialog. Bearbeiten-Stellen bleiben
 * gesperrt. Geprüft im gerenderten DOM (Vorfahr fieldset[disabled]), nicht über den Livewire-State.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Anna'));

    $this->gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'article_number' => '4711',
        'designation' => 'Zanderfilet mit Haut 1 kg', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'gp_id' => $this->gp->id]);
    $this->gp->update(['n_las_total' => 1, 'lead_la_supplier_item_id' => $this->la->id]);
});

it('GP-Detailspalte im Lesemodus: Preisverlauf genau einmal und bedienbar, Artikel-Vorschlag bleibt gesperrt', function () {
    $html = Livewire::test(GpDetailPanel::class, ['gpId' => $this->gp->id])
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->html();

    expect(LesemodusDom::gesperrt($html, '//*[@data-sektion="preisverlauf"]'))->toBe([false])
        ->and(LesemodusDom::gesperrt($html, '//*[@data-zeitraum="3m"]'))->toBe([false])
        ->and(LesemodusDom::gesperrteAnzeigeBedienung($html))->toBe(0)
        ->and(LesemodusDom::gesperrt($html, '//*[@data-la-ki-vorschlag]'))->not->toBeEmpty()->each->toBeTrue();
});

it('GP-Detailspalte eingebettet (section las): kein eigener Preisverlauf, den zeigt der GP-Dialog', function () {
    $html = Livewire::test(GpDetailPanel::class, ['gpId' => $this->gp->id, 'embedded' => true, 'section' => 'las'])->html();

    expect(LesemodusDom::gesperrt($html, '//*[@data-sektion="preisverlauf"]'))->toBe([])
        ->and($html)->toContain('data-sektion="las"');
});

it('GP-Dialog im Lesemodus: Preisverlauf genau einmal (x-slot:frei) und bedienbar, Stückgewicht bleibt gesperrt', function () {
    $html = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->html();

    expect(LesemodusDom::gesperrt($html, '//*[@data-sektion="preisverlauf"]'))->toBe([false])
        ->and(LesemodusDom::gesperrt($html, '//*[@data-zeitraum="6m"]'))->toBe([false])
        ->and(LesemodusDom::gesperrteAnzeigeBedienung($html))->toBe(0)
        ->and(LesemodusDom::gesperrt($html, '//input[@id="gp-stk"]'))->toBe([true]);
});

it('LA-Dialog im Lesemodus: Preisverlauf bedienbar, Preis setzen und Preiszeilen bleiben gesperrt; mit „Bearbeiten“ frei', function () {
    $c = Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)->assertSeeHtml('data-fa-lesemodus="1"');
    $html = $c->html();

    expect(LesemodusDom::gesperrt($html, '//*[@data-preise-rechts]'))->toBe([false])
        ->and(LesemodusDom::gesperrt($html, '//*[@data-zeitraum="12m"]'))->toBe([false])
        ->and(LesemodusDom::gesperrteAnzeigeBedienung($html))->toBe(0)
        ->and(LesemodusDom::gesperrt($html, '//*[@data-preis-neu-toggle]'))->toBe([true])
        ->and(LesemodusDom::gesperrt($html, '//input[@id="la-preis-neu"]'))->toBe([true]);

    $frei = $c->call('bearbeitenStarten')->assertSeeHtml('data-fa-lesemodus="0"')->html();
    expect(LesemodusDom::gesperrt($frei, '//*[@data-preis-neu-toggle]'))->toBe([false])
        ->and(LesemodusDom::gesperrt($frei, '//*[@data-sektion="preisverlauf"]'))->toBe([false]);
});
