<?php

use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Master-Rundgang io 26.11. (T3, Konzept 8 „Green & Tasty“): EK/Person 1,11 € sah vollständig aus, obwohl 9 von 11 Gerichten
 * der eingebetteten Pakete keinen EK haben. Regel „Unbekanntes nie als 0“ (B2) gilt auch durch Pakete hindurch: Die EK-Summe
 * bleibt die Summe der bekannten Werte, aber die Lücke ist sichtbar — im Aggregat (ek_vollstaendig) und im Cockpit (hat_ek_luecke).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->t = $this->rootTeam;
    $this->mitEk = $this->makeRecipe($this->t, 'Bowl', ['is_sales_recipe' => true, 'ek_total_eur' => 0.8]);   // 98 · 3.5 6a: Gericht = eine Portion (fest 1)
    $this->makeStandardDarreichung($this->mitEk, 6.0);
    $this->ohneEk = $this->makeRecipe($this->t, 'Wrap', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($this->ohneEk, 5.0);

    $this->konzepte = app(ConceptService::class);
    $this->paket = $this->makeConcept($this->t, 'Grünes Paket', ['kind' => 'paket']);
    $this->makeConceptSlot($this->paket, ['recipe_id' => $this->mitEk->id, 'quantity' => 1]);
    $this->makeConceptSlot($this->paket, ['recipe_id' => $this->ohneEk->id, 'quantity' => 1, 'position' => 2]);
    $this->konzepte->recomputeCache($this->paket->fresh());

    $this->konzept = $this->makeConcept($this->t, 'Green & Tasty');
    $this->makeConceptSlot($this->konzept, ['type' => 'paket', 'embedded_concept_id' => $this->paket->id]);
});

it('Aggregat: Gericht ohne EK im Paket → ek_vollstaendig false, die Summe bleibt die der bekannten EK', function () {
    $agg = app(ConcepterAggregateService::class)->conceptAggregat($this->konzept->fresh());
    $paketAgg = app(ConcepterAggregateService::class)->conceptAggregat($this->paket->fresh());

    expect($agg['ek_per_person'])->toBe(0.8)->and($agg['ek_vollstaendig'])->toBeFalse()
        ->and($paketAgg['ek_vollstaendig'])->toBeFalse();
});

it('Cockpit: die EK-Lücke des eingebetteten Pakets zieht ins Elternkonzept durch (hat_ek_luecke, Zeile mit Merker)', function () {
    $c = $this->konzepte->preisCockpit($this->konzept->fresh(), null, $this->t);

    expect($c['hat_ek_luecke'])->toBeTrue()
        ->and($c['zeilen'][0]['type'])->toBe('paket')->and($c['zeilen'][0]['ek_unvollstaendig'] ?? false)->toBeTrue()
        ->and((float) $c['ek_per_person'])->toBe(0.8);   // Teilsumme bleibt, ist aber als unvollständig markiert
});

it('auch über zwei Ebenen: Paket im Paket', function () {
    $aussen = $this->makeConcept($this->t, 'Außenpaket', ['kind' => 'paket']);
    $this->makeConceptSlot($aussen, ['type' => 'paket', 'embedded_concept_id' => $this->paket->id]);
    $this->konzepte->recomputeCache($aussen->fresh());
    $k = $this->makeConcept($this->t, 'Zweistufig');
    $this->makeConceptSlot($k, ['type' => 'paket', 'embedded_concept_id' => $aussen->id]);

    expect($this->konzepte->preisCockpit($k->fresh(), null, $this->t)['hat_ek_luecke'])->toBeTrue();
});

it('Paket mit vollständigem EK bleibt ohne Merker', function () {
    $voll = $this->makeConcept($this->t, 'Volles Paket', ['kind' => 'paket']);
    $this->makeConceptSlot($voll, ['recipe_id' => $this->mitEk->id, 'quantity' => 1]);
    $this->konzepte->recomputeCache($voll->fresh());
    $k = $this->makeConcept($this->t, 'Vollständig');
    $this->makeConceptSlot($k, ['type' => 'paket', 'embedded_concept_id' => $voll->id]);
    $c = $this->konzepte->preisCockpit($k->fresh(), null, $this->t);

    expect($c['hat_ek_luecke'])->toBeFalse()->and($c['zeilen'][0]['ek_unvollstaendig'] ?? false)->toBeFalse()
        ->and(app(ConcepterAggregateService::class)->conceptAggregat($k->fresh())['ek_vollstaendig'])->toBeTrue();
});

it('Messtest: die Paket-Lücke kostet keine Abfragen je Paket (Cache-Merker ek_incomplete, wie price_incomplete)', function () {
    $abfragen = function (int $n): array {
        $k = $this->makeConcept($this->t, "K$n");
        for ($i = 0; $i < $n; $i++) {
            $p = $this->makeConcept($this->t, "P$n-$i", ['kind' => 'paket']);
            $this->makeConceptSlot($p, ['recipe_id' => $this->mitEk->id, 'quantity' => 1]);
            $this->makeConceptSlot($p, ['recipe_id' => $this->ohneEk->id, 'quantity' => 1, 'position' => 2]);
            $this->konzepte->recomputeCache($p->fresh());
            $this->makeConceptSlot($k, ['type' => 'paket', 'embedded_concept_id' => $p->id, 'position' => $i]);
        }
        $frisch = $k->fresh();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $c = $this->konzepte->preisCockpit($frisch, null, $this->t);
        $anzahl = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return [$anzahl, $c['hat_ek_luecke']];
    };
    [$eins, $lueckeEins] = $abfragen(1);
    [$vier, $lueckeVier] = $abfragen(4);

    expect($vier)->toBe($eins)->and($lueckeEins)->toBeTrue()->and($lueckeVier)->toBeTrue()
        ->and($this->paket->fresh()->ek_incomplete)->toBeTrue();
});

it('Altbestand ohne Merker (ek_incomplete null): das Cockpit rechnet die Lücke live nach', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistConcept::whereKey($this->paket->id)->update(['ek_incomplete' => null]);

    expect($this->konzepte->preisCockpit($this->konzept->fresh(), null, $this->t)['hat_ek_luecke'])->toBeTrue();
});

it('Foodbook und Angebot: concept_ref mit Paket-Lücke ist EK-unvollständig (Zeile und Kapitel)', function () {
    $fbKap = $this->makeChapter($this->makeFoodbook($this->t, 'Foodbook', ['status' => 'entwurf']));
    $fbBlock = $this->makeFoodbookBlock($fbKap, ['type' => 'concept_ref', 'concept_id' => $this->konzept->id]);
    $angebot = app(\Platform\FoodAlchemist\Services\Ausgabe\AngebotService::class)->create($this->t, ['name' => 'Angebot']);
    $agKap = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::create(['team_id' => $this->t->id, 'offer_id' => $angebot->id, 'title' => 'K', 'position' => 0]);
    $agBlock = \Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock::create(['team_id' => $this->t->id, 'chapter_id' => $agKap->id, 'type' => 'concept_ref', 'concept_id' => $this->konzept->id, 'position' => 0, 'visible' => true]);
    $off = app(\Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService::class);
    $fbAgg = app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat::class);

    expect($fbAgg->blockPreis($fbBlock->fresh(), null, null, $this->t)['ek_vollstaendig'])->toBeFalse()
        ->and($off->blockPreis($agBlock->fresh(), null, $this->t)['ek_vollstaendig'])->toBeFalse()
        ->and($fbAgg->kapitelAggregat($this->t, $fbKap->fresh())['ek_vollstaendig'])->toBeFalse()
        ->and($off->kapitelAggregat($this->t, $agKap->fresh())['ek_vollstaendig'])->toBeFalse();
});

it('Oberfläche (B): Concepter-Detail und Editor-Kopf zeigen den EK mit Merker „unvollständig“, den Wareneinsatz als „offen“', function () {
    $text = fn ($lw) => preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($lw->html())));   // gerenderter Text (die Aufruf-Antwort ist JSON mit „\/“)
    expect($text(\Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\DetailPanel::class)->call('zeige', 'concepts', $this->konzept->id)))
        ->toMatch('/EK\/Person 0,80 € ?unvollständig/u');
    expect($text(\Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)->call('oeffnen', 'concepts', $this->konzept->id)))
        ->toMatch('/Wareneinsatz je Person 0,80 € ?unvollständig/u')->toMatch('/Wareneinsatz offen/u');
});

it('Ausgaben (D): die Paket-Zeile trägt keinen EK, wenn das Paket eine EK-Lücke hat — Teilsumme nie als EK', function () {
    $zeilen = app(\Platform\FoodAlchemist\Services\Concepter\WordingResolver::class)->gerichtZeilen($this->konzept->fresh());
    $paketZeile = collect($zeilen)->firstWhere('type', 'paket');

    expect($paketZeile)->not->toBeNull()->and($paketZeile['ek'])->toBeNull();
});

it('Foodbook: bei EK-Lücke keine Wareneinsatz-Quote — Kapitel, Gesamt, internes Dokument und Detail zeigen „offen“', function () {
    $fb = $this->makeFoodbook($this->t, 'Foodbook', ['status' => 'entwurf']);
    $kap = $this->makeChapter($fb, ['title' => 'Buffet']);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $this->konzept->id]);
    $agg = app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat::class);
    $gesamt = $agg->gesamt($this->t, $fb->fresh());

    expect($agg->kapitelAggregat($this->t, $kap->fresh())['food_cost_percent'])->toBeNull()
        ->and($gesamt['food_cost_percent'])->toBeNull()->and($gesamt['ek_vollstaendig'])->toBeFalse();

    $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
        $this->get(route('foodalchemist.foodbooks.dokument', ['id' => $fb->id, 'intern' => 1]))->assertOk()->getContent())));
    expect($text)->toContain('W offen')->toMatch('/Wareneinsatz % ?offen/u')->toContain('0,80 € (unvollständig)');

    app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookDokument::class)->vorschauSnapshotAktualisieren($this->t, $fb->id);
    $detail = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
        \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)->call('waehle', $fb->id)->html())));
    expect($detail)->toMatch('/Wareneinsatz offen/u')->not->toMatch('/Wareneinsatz fehlt/u');
});

it('Foodbook ohne Lücke: Quote bleibt', function () {
    $voll = $this->makeConcept($this->t, 'Volles Konzept');
    $this->makeConceptSlot($voll, ['recipe_id' => $this->mitEk->id, 'quantity' => 1]);
    $fb = $this->makeFoodbook($this->t, 'Foodbook voll', ['status' => 'entwurf']);
    $kap = $this->makeChapter($fb, ['title' => 'Hauptgang']);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $voll->id]);
    $gesamt = app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregat::class)->gesamt($this->t, $fb->fresh());

    expect($gesamt['food_cost_percent'])->not->toBeNull()->and($gesamt['ek_vollstaendig'])->toBeTrue();
});

it('Folge-PR (B1): die Gericht-Karte im Paket zeigt den EK je Person der Position (Darreichung, Menge), nicht den Ansatz', function () {
    $beigabe = $this->makeRecipe($this->t, 'Hot Dog', ['is_sales_recipe' => true, 'ek_total_eur' => 1.26, 'sales_unit_count' => 1]);
    $this->makeStandardDarreichung($beigabe, 4.5, ['ek_portion' => 0.79]);
    $p = $this->makeConcept($this->t, 'Snack-Paket', ['kind' => 'paket']);
    $this->makeConceptSlot($p, ['recipe_id' => $beigabe->id, 'quantity' => 1]);
    $this->makeConceptSlot($p, ['recipe_id' => $this->mitEk->id, 'quantity' => 2, 'position' => 2]);
    $this->makeConceptSlot($p, ['recipe_id' => $this->ohneEk->id, 'quantity' => 1, 'position' => 3]);
    $this->konzepte->recomputeCache($p->fresh());
    $k = $this->makeConcept($this->t, 'Snack-Menü');
    $this->makeConceptSlot($k, ['type' => 'paket', 'embedded_concept_id' => $p->id]);

    $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
        \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)->call('oeffnen', 'concepts', $k->id)->html())));

    expect($text)->toMatch('/Hot Dog.{0,160}EK 0,79 €/u')->not->toMatch('/Hot Dog.{0,160}EK 1,26 €/u')
        ->toMatch('/Bowl.{0,160}EK 1,60 €/u')                  // 8,00 € ÷ 10 Portionen × Menge 2
        ->toMatch('/Wrap.{0,160}EK EK fehlt/u');
});

it('Folge-PR: Foodbook-Detail zeigt am EK/Person den Merker „unvollständig“', function () {
    $fb = $this->makeFoodbook($this->t, 'Foodbook', ['status' => 'entwurf']);
    $this->makeFoodbookBlock($this->makeChapter($fb, ['title' => 'Buffet']), ['type' => 'concept_ref', 'concept_id' => $this->konzept->id]);
    app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookDokument::class)->vorschauSnapshotAktualisieren($this->t, $fb->id);
    $detail = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
        \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)->call('waehle', $fb->id)->html())));

    expect($detail)->toMatch('/EK\/Person 0,80 € ?unvollständig/u');
});

it('Detail-Hinweis nennt die Zahl der Gerichte ohne EK (Singular bei 1), ohne Abfragen je Paket', function () {
    $einzeln = preg_replace('/\s+/u', ' ', strip_tags(\Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\DetailPanel::class)->call('zeige', 'concepts', $this->konzept->id)->html()));
    expect($einzeln)->toContain('Einkauf unvollständig: einem Gericht fehlt der EK oder das Portionsgewicht.');

    $zweites = $this->makeRecipe($this->t, 'Falafel', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($zweites, 4.0);
    $this->makeConceptSlot($this->paket, ['recipe_id' => $zweites->id, 'quantity' => 1, 'position' => 3]);
    $this->makeConceptSlot($this->konzept, ['recipe_id' => $this->ohneEk->id, 'quantity' => 1, 'position' => 2]);   // dasselbe Gericht zählt einmal
    $this->konzepte->recomputeCache($this->paket->fresh());
    $mehrere = preg_replace('/\s+/u', ' ', strip_tags(\Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\DetailPanel::class)->call('zeige', 'concepts', $this->konzept->id)->html()));

    expect($mehrere)->toContain('Einkauf unvollständig: 2 Gerichten fehlt der EK oder das Portionsgewicht.')
        ->and(app(ConcepterAggregateService::class)->conceptAggregat($this->konzept->fresh())['ek_n_fehlend'])->toBe(2);
});
