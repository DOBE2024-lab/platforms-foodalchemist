<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor as AngebotEditor;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.6 PR3 (P4, Kurator/Dominique): Wahlgruppen als Spanne — in den Editoren am Kapitel-Kopf „von–bis €, gerechnet:
 * teuerste“ und an der Wahlgruppe ihre eigene Spanne; beim Kunden (Präsentation, Druck, PDF) die Kapitel-Spanne „12,50 – 16,00 €“,
 * gerechnet wird intern mit der teuersten. Die Spanne je Wahlgruppe bleibt intern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->t = $this->rootTeam;
});

it('Foodbook-Editor: Spanne am Kapitel-Kopf und an der Wahlgruppe', function () {
    $fb = $this->makeFoodbook($this->t, 'Foodbook 2027', ['status' => 'entwurf']);
    $kap = $this->makeChapter($fb, ['title' => 'Hauptgang']);
    foreach ([10.0, 14.0] as $i => $p) {
        $this->makeFoodbookBlock($kap, ['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => $p, 'variant_group_id' => 1, 'position' => $i]);
    }
    $this->makeFoodbookBlock($kap, ['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => 5.0, 'position' => 5]);

    Livewire::test(FoodbooksIndex::class)->call('waehle', $fb->id)->set('selectedKapitelId', $kap->id)
        ->assertSeeHtml('data-kapitel-wahl-spanne')
        ->assertSee('15,00–19,00 €, gerechnet: 19,00 €')
        ->assertSeeHtml('data-wahl-gruppe-spanne')
        ->assertSee('10,00–14,00 €');
});

it('Angebot: Spanne im Editor-Kapitel-Kopf und in der Kundensicht (Kapitel), je Wahlgruppe nur intern', function () {
    $angebot = app(AngebotService::class)->create($this->t, ['name' => 'Hochzeit', 'personen' => 40]);
    $kap = FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $angebot->id, 'title' => 'Hauptgang', 'position' => 0]);
    foreach ([10.0, 14.0] as $i => $p) {
        FoodAlchemistOfferBlock::create(['team_id' => $this->t->id, 'chapter_id' => $kap->id, 'type' => 'header_preis', 'price_basis' => 'person',
            'price_value' => $p, 'variant_group_id' => 1, 'position' => $i, 'visible' => true]);
    }
    FoodAlchemistOfferBlock::create(['team_id' => $this->t->id, 'chapter_id' => $kap->id, 'type' => 'header_preis', 'price_basis' => 'person',
        'price_value' => 5.0, 'position' => 5, 'visible' => true]);

    Livewire::test(AngebotEditor::class)->call('oeffnen', $angebot->id)
        ->assertSeeHtml('data-kapitel-wahl-spanne')
        ->assertSee('15,00–19,00 €, gerechnet: 19,00 €');

    $komp = app(\Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService::class)->komposition($this->t, $angebot->fresh(), null, true);
    $kunde = app(\Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService::class)->kundensicht($komp);
    expect($komp['kapitel'][0]['wahl_spanne'])->toBe(['von' => 15.0, 'bis' => 19.0])
        ->and($kunde['kapitel'][0]['wahl_spanne'])->toBe(['von' => 15.0, 'bis' => 19.0])
        ->and($kunde['kapitel'][0])->not->toHaveKey('wahl_gruppen');

    // Präsentation: Kapitelpreis als Spanne, gerechnet (pp) mit der teuersten Wahl
    $preis = \Platform\FoodAlchemist\Support\KapitelPreis::ausKapitel($kunde['kapitel'][0], false);
    expect($preis['pp'])->toBe(19.0)->and(\Platform\FoodAlchemist\Support\KapitelPreis::text($preis))->toBe('15,00 – 19,00 €');
    $snap = app(\Platform\FoodAlchemist\Services\Ausgabe\PresentationService::class)->previewData($this->t, 'angebot', $angebot->id);
    $sektion = collect(data_get($snap, 'content.sections') ?? data_get($snap, 'content.body.sections') ?? [])->firstWhere('preis.wahl', true);
    expect($sektion)->not->toBeNull()->and(data_get($sektion, 'preis.min'))->toBe(15.0)->and(data_get($sektion, 'preis.max'))->toBe(19.0);
});

it('KapitelPreis ohne Wahlgruppe bleibt der eine Preis', function () {
    $p = \Platform\FoodAlchemist\Support\KapitelPreis::ausKapitel(['vk_pro_person' => 19.0, 'wahl_spanne' => null], false);
    expect(\Platform\FoodAlchemist\Support\KapitelPreis::text($p))->toBe('19,00 €')->and($p)->not->toHaveKey('wahl');
});
