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
