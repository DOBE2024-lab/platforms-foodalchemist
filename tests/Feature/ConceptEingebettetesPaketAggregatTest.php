<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1h (Gästesicherheit): Ein Concept bettet ein Paket des neuen Modells ein
 * (kind=paket-Concept über embedded_concept_id). Diät-/Allergen-Rollup, Deklaration,
 * Nährwerte, EK und Arbeitszeit müssen die Gerichte DIESES Pakets mitzählen — vorher sah
 * nur der Preis das eingebettete Paket, Allergenangaben waren unvollständig.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->concepts = app(ConceptService::class);
    $this->agg = app(ConcepterAggregateService::class);

    $mk = fn (array $attr) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'is_sales_recipe' => true,
    ], $attr));

    // Fest im Concept: veganer Salat.
    $this->salat = $mk([
        'recipe_key' => 'salat', 'name' => 'Green Power', 'ek_total_eur' => 0.60,
        'work_time_min' => 15, 'sales_quantity_per_unit_g' => 250,
        'nutri_kcal_per_100g' => 200, 'nutri_confidence' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergens_confidence' => 'high',
    ]);
    // Nur im eingebetteten Paket: Schwein, nicht vegan.
    $this->pork = $mk([
        'recipe_key' => 'pork', 'name' => 'Pulled Pork', 'ek_total_eur' => 0.90,
        'work_time_min' => 10, 'sales_quantity_per_unit_g' => 200,
        'nutri_kcal_per_100g' => 150, 'nutri_confidence' => 'high',
        'spec_is_vegan' => false, 'spec_is_vegetarian' => false, 'spec_contains_pork' => true,
        'allergens_confidence' => 'high',
    ]);

    // Paket (neues Modell) mit dem Schwein-Gericht.
    $this->paket = $this->concepts->createPaket($this->rootTeam, ['name' => 'BBQ-Station']);
    $ps = $this->concepts->addSlot($this->rootTeam, $this->paket->id, ['role' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $ps->id, ['sales_recipe_id' => $this->pork->id]);

    // Concept: fester Salat + eingebettetes Paket.
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Sommerfest']);
    $s1 = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['sales_recipe_id' => $this->salat->id]);
    $s2 = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $s2->id, ['embedded_concept_id' => $this->paket->id]);
});

it('Diät-Rollup (allergenRollup) zählt die Gerichte des eingebetteten Pakets mit', function () {
    $r = $this->concepts->allergenRollup($this->concept->fresh());

    expect($r['n_gerichte'])->toBe(2)
        ->and($r['contains_pork'])->toBeTrue()
        ->and($r['is_vegan'])->toBeFalse()
        ->and($r['is_vegetarian'])->toBeFalse();
});

it('Concept-Aggregat: Allergene, Nährwerte, EK und Arbeitszeit enthalten das eingebettete Paket', function () {
    $a = $this->agg->conceptAggregat($this->concept->fresh());

    expect($a['n_gerichte'])->toBe(2)
        ->and($a['allergene']['contains_pork'])->toBeTrue()
        ->and($a['allergene']['is_vegan'])->toBeFalse()
        ->and($a['naehrwerte']['kcal'])->toBe(800.0)          // 200×2,5 + 150×2,0
        ->and((float) $a['ek_per_person'])->toBe(1.5)         // 0,60 + 0,90
        ->and($a['work_time_min'])->toBe(25);                 // 15 + 10
});

it('Deklarationsblatt listet das Gericht aus dem eingebetteten Paket', function () {
    $d = $this->agg->conceptDeklaration($this->concept->fresh());

    expect(collect($d['zeilen'])->pluck('name')->all())->toContain('Pulled Pork', 'Green Power');
});

it('Caches am Concept (Nährwerte, EK, Arbeitszeit) enthalten das eingebettete Paket', function () {
    $c = $this->concepts->recomputeCache($this->concept->fresh());

    expect((float) $c->nutrition_cache['kcal'])->toBe(800.0)
        ->and((int) $c->work_time_min_cache)->toBe(25)
        ->and((float) $c->ek_per_person_cache)->toBe(1.5);
});

it('verschachtelt: Paket im Paket wird über mehrere Ebenen aufgelöst', function () {
    $aussen = $this->concepts->createPaket($this->rootTeam, ['name' => 'Grill-Buffet']);
    $as = $this->concepts->addSlot($this->rootTeam, $aussen->id, ['role' => 'Station']);
    $this->concepts->fillSlot($this->rootTeam, $as->id, ['embedded_concept_id' => $this->paket->id]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Firmenfeier']);
    $cs = $this->concepts->addSlot($this->rootTeam, $c->id, ['role' => 'Buffet']);
    $this->concepts->fillSlot($this->rootTeam, $cs->id, ['embedded_concept_id' => $aussen->id]);

    expect($this->concepts->allergenRollup($c->fresh())['contains_pork'])->toBeTrue();
});

it('Zyklus (Concept bettet sich selbst ein) bricht ab statt endlos zu rekursieren', function () {
    // Direkt in die DB: der Zyklus soll auch dann nicht hängen, wenn er an der Service-Sperre vorbei entstand.
    FoodAlchemistConceptSlot::create([
        'team_id' => $this->rootTeam->id, 'concept_id' => $this->paket->id, 'role' => 'Selbst',
        'position' => 9, 'type' => 'paket', 'embedded_concept_id' => $this->paket->id,
    ]);

    $a = $this->agg->conceptAggregat($this->concept->fresh());

    expect($a['n_gerichte'])->toBe(2)
        ->and($a['work_time_min'])->toBe(25);                 // Paket-Gericht genau einmal, nicht vervielfacht
});

it('Speisekarte: Fix-Menü mit eingebettetem Paket zeigt die Allergen-Codes des Paket-Gerichts', function () {
    $this->pork->forceFill(['allergen_gluten' => 'enthalten'])->save();
    $karten = app(\Platform\FoodAlchemist\Services\Ausgabe\SpeisekarteService::class);

    $karte = $karten->create($this->rootTeam, ['name' => 'Sommerkarte']);
    $rubrik = $karten->addRubrik($this->rootTeam, $karte->id);
    $pos = $karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'menue_ref', 'concept_id' => $this->concept->id]);

    expect($karten->positionGerichte($pos->fresh())->pluck('id')->all())->toContain($this->pork->id);

    $zeile = $karten->dokumentDaten($this->rootTeam, $karte->refresh())['rubriken'][0]['positionen'][0];
    $gang = collect($zeile['gaenge'])->firstWhere('recipe_id', $this->pork->id);
    expect($gang)->not->toBeNull()
        ->and($gang['codes'])->toContain('A');              // Gluten = A, auch aus dem eingebetteten Paket
});
