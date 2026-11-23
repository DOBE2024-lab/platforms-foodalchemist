<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel as GpDetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Preise\Fruehere;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Plattform\BearbeitungssperreService;
use Platform\FoodAlchemist\Tests\Support\LesemodusDom;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · Lesemodus-Anzeige (Spec 65): Reine Anzeige-Bedienung bleibt ohne „Bearbeiten“ nutzbar. Erster Fall: Preisverlauf
 * (Zeitraum 3 M/6 M/12 M/Alles/von–bis, „Alle Artikel“) in GP-Detailspalte, GP-Dialog und LA-Dialog. Bearbeiten-Stellen bleiben
 * gesperrt. Geprüft im gerenderten DOM (Vorfahr fieldset[disabled]), nicht über den Livewire-State.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->actingAs($this->anna);

    $this->gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'article_number' => '4711',
        'designation' => 'Zanderfilet mit Haut 1 kg', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'gp_id' => $this->gp->id]);
    $this->gp->update(['n_las_total' => 1, 'lead_la_supplier_item_id' => $this->la->id]);
    // ein früherer Stand (Necta-Historie), damit „Frühere Preise (1)“ erscheint
    $this->standId = DB::table('foodalchemist_price_history')->insertGetId(['uuid' => (string) UuidV7::generate(), 'price_id' => 1, 'supplier_item_id' => $this->la->id,
        'team_id' => $this->rootTeam->id, 'status' => '0', 'price' => 12.5, 'status_valid_from' => '2026-01-01', 'valid_to' => '2026-03-01', 'is_blocked' => 0,
        'quelle' => 'necta_abgleich', 'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
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
        ->and(LesemodusDom::gesperrt($html, '//input[@id="la-preis-neu"]'))->toBe([true])
        ->and(LesemodusDom::gesperrt($html, '//*[@data-fruehere-wurzel]'))->toBe([false]);   // Kind steht außerhalb der Dialog-Sperre

    $frei = $c->call('bearbeitenStarten')->assertSeeHtml('data-fa-lesemodus="0"')->html();
    expect(LesemodusDom::gesperrt($frei, '//*[@data-preis-neu-toggle]'))->toBe([false])
        ->and(LesemodusDom::gesperrt($frei, '//*[@data-sektion="preisverlauf"]'))->toBe([false]);
});

it('Frühere Preise: Aufklapper im Lesemodus frei, Ein-/Ausblenden gesperrt (DOM) und serverseitig abgewiesen; mit Sperre frei', function () {
    $html = Livewire::test(Fruehere::class, ['itemId' => $this->la->id, 'gesperrt' => true])->html();

    expect(LesemodusDom::gesperrt($html, '//*[@data-fruehere-toggle]'))->toBe([false])
        ->and(LesemodusDom::gesperrteAnzeigeBedienung($html))->toBe(0)
        ->and(LesemodusDom::gesperrt($html, '//*[@data-fruehere-ausblenden-oeffnen]'))->toBe([true])
        ->and(LesemodusDom::gesperrt($html, '//*[@data-fruehere-ausblenden]'))->toBe([true]);

    // Server-Guard: ohne gehaltene Sperre des Lieferantenartikels läuft ausblenden nicht (call-Haken, Spec 65)
    $c = Livewire::test(Fruehere::class, ['itemId' => $this->la->id, 'gesperrt' => true])
        ->set('gruende.'.$this->standId, 'Tippfehler')->call('ausblenden', $this->standId);
    expect($c->instance()->sperreErlaubt('ausblenden'))->toBeFalse()
        ->and($c->instance()->sperreErlaubt('neuLaden'))->toBeTrue()
        ->and(LesemodusDom::gesperrt($c->html(), '//*[@data-ausgeblendet-badge]'))->toBe([]);

    // „Bearbeiten“ im Dialog hält die Sperre des Artikels → Kind schreibt, Knöpfe frei
    app(BearbeitungssperreService::class)->sperren('supplier_item', $this->la->id, (int) $this->anna->id, 'Anna', $this->rootTeam->id);
    $frei = Livewire::test(Fruehere::class, ['itemId' => $this->la->id, 'gesperrt' => false]);
    expect(LesemodusDom::gesperrt($frei->html(), '//*[@data-fruehere-ausblenden]'))->toBe([false]);
    $frei->set('gruende.'.$this->standId, 'Tippfehler')->call('ausblenden', $this->standId);
    expect(LesemodusDom::gesperrt($frei->html(), '//*[@data-ausgeblendet-badge]'))->toBe([false]);
});
