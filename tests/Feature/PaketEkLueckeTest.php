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
    $this->mitEk = $this->makeRecipe($this->t, 'Bowl', ['is_sales_recipe' => true, 'ek_total_eur' => 8.0, 'sales_unit_count' => 10]);
    $this->makeStandardDarreichung($this->mitEk, 6.0);
    $this->ohneEk = $this->makeRecipe($this->t, 'Wrap', ['is_sales_recipe' => true, 'sales_unit_count' => 10]);
    $this->makeStandardDarreichung($this->ohneEk, 5.0);

    $this->konzepte = app(ConceptService::class);
    $this->paket = $this->makeConcept($this->t, 'Grünes Paket', ['kind' => 'paket']);
    $this->makeConceptSlot($this->paket, ['sales_recipe_id' => $this->mitEk->id, 'quantity' => 1]);
    $this->makeConceptSlot($this->paket, ['sales_recipe_id' => $this->ohneEk->id, 'quantity' => 1, 'position' => 2]);
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
    $this->makeConceptSlot($voll, ['sales_recipe_id' => $this->mitEk->id, 'quantity' => 1]);
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
            $this->makeConceptSlot($p, ['sales_recipe_id' => $this->mitEk->id, 'quantity' => 1]);
            $this->makeConceptSlot($p, ['sales_recipe_id' => $this->ohneEk->id, 'quantity' => 1, 'position' => 2]);
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
