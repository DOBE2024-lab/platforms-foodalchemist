<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\Concepter\ConcepterBewertungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-3 / Doc 15 §10.8: deterministische Menü-Bewertung (Struktur · Niveau ·
 * Diät · Preis-Korridor · Allergen-Konfidenz). Keine KI — reine Regeln.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;   // öffentliche dyn. Prop für die Helper unten
    $this->concepts = app(ConceptService::class);
    $this->agg = app(ConcepterAggregateService::class);
    $this->bew = app(ConcepterBewertungService::class);

    $veg = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'v', 'name' => 'Veganer Salat',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.50, 'ek_total_eur' => 1.40,
        'sales_quantity_per_unit_g' => 200, 'nutri_kcal_per_100g' => 120, 'nutri_confidence' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergens_confidence' => 'high',
    ]);
    // 98 · 2.6c: Paket = kind=paket-Concept (manueller Preis), eingebettet statt package_id
    $this->paket = $this->concepts->createPaket($this->rootTeam, ['name' => 'Salad Wall', 'level' => 'klassisch']);
    $this->concepts->update($this->rootTeam, $this->paket->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 4.50]);
    $this->concepts->fillSlot($this->rootTeam, $this->concepts->addSlot($this->rootTeam, $this->paket->id, ['role' => 'Vorspeise'])->id, ['sales_recipe_id' => $veg->id]);

    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Mini-Menü', 'level' => 'klassisch']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $this->paket->id]);
});

function bewertungFuer($self): array
{
    $c = $self->concepts->detail($self->team, $self->concept->id);
    $cockpit = $self->concepts->preisCockpit($c);
    $aggregat = $self->agg->conceptAggregat($c);

    return $self->bew->bewerten($c, $cockpit, $aggregat);
}

function statusVon(array $bew, string $key): string
{
    return collect($bew['checks'])->firstWhere('key', $key)['status'] ?? 'missing';
}

it('befülltes Menü, einheitliches Niveau, hohe Konfidenz → Struktur/Niveau/Allergen ok', function () {
    $b = bewertungFuer($this);

    expect(statusVon($b, 'struktur'))->toBe('ok')
        ->and(statusVon($b, 'level'))->toBe('ok')
        ->and(statusVon($b, 'allergen'))->toBe('ok')
        ->and($b['score'])->toBeGreaterThanOrEqual(80);
});

it('leere Position → Struktur warn', function () {
    $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Dessert']);   // unbefüllt

    expect(statusVon(bewertungFuer($this), 'struktur'))->toBe('warn');
});

it('Diät-Vorgabe vegan wird gegen den Rollup geprüft', function () {
    $this->concepts->update($this->rootTeam, $this->concept->id, ['diet_requirement' => 'vegan']);
    expect(statusVon(bewertungFuer($this), 'diaet'))->toBe('ok');           // einziges Gericht ist vegan
});

it('Preis-Korridor: Ziel weit daneben → fail, Ziel nah → ok', function () {
    $this->concepts->update($this->rootTeam, $this->concept->id, ['target_price_per_person' => 10.00]);
    expect(statusVon(bewertungFuer($this), 'price'))->toBe('fail');         // 4,50 vs 10,00 = 55 % Abw.

    $this->concepts->update($this->rootTeam, $this->concept->id, ['target_price_per_person' => 4.60]);
    expect(statusVon(bewertungFuer($this), 'price'))->toBe('ok');           // ~2 % Abw.
});

// 98 · 2.6c: Niveau-Konsistenz liest das Niveau der eingebetteten Paket-Concepts (kind=paket, level)
it('Niveau-Konsistenz: Paket-Niveau weicht vom Konzept-Niveau ab → warn', function () {
    $this->concepts->update($this->rootTeam, $this->paket->id, ['level' => 'gehoben']);

    expect(statusVon(bewertungFuer($this), 'level'))->toBe('warn');
});

it('Niveau-Konsistenz: zwei Pakete mit unterschiedlichem Niveau → warn (gemischt)', function () {
    $zweites = $this->concepts->createPaket($this->rootTeam, ['name' => 'Dessert-Station', 'level' => 'haute_cuisine']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $zweites->id]);

    $b = bewertungFuer($this);
    expect(statusVon($b, 'level'))->toBe('warn')
        ->and(collect($b['checks'])->firstWhere('key', 'level')['detail'])->toContain('Gemischte Niveaus');
});

it('Niveau-Konsistenz: ohne Paket (nur Gerichte) → info', function () {
    $ohne = $this->concepts->create($this->rootTeam, ['name' => 'Nur Gerichte', 'level' => 'klassisch']);
    $this->concept = $ohne;

    expect(statusVon(bewertungFuer($this), 'level'))->toBe('info');
});
