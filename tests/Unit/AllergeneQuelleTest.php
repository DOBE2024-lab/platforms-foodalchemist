<?php

use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Support\Allergene;

/** Code-Qualität 2.2b: eine Quelle für die Allergen-Labels (vorher 3 Karten mit abweichendem Wortlaut). */
it('LANG und KURZ: dieselben 14 Schlüssel in LMIV-Reihenfolge, deckungsgleich mit den GP-Allergenfeldern', function () {
    $schluessel = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk', 'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];
    expect(array_keys(Allergene::LANG))->toBe($schluessel)
        ->and(array_keys(Allergene::KURZ))->toBe($schluessel)
        ->and(array_map(fn ($f) => preg_replace('/^allergen_/', '', $f), FoodAlchemistGp::ALLERGEN_FIELDS))->toEqualCanonicalizing($schluessel);
});

it('Modell, Planung und Fassungs-Signale lesen aus derselben Quelle', function () {
    expect(FoodAlchemistItemAllergen::ALLERGENE)->toBe(Allergene::LANG)
        ->and(PlanungIndex::ALLERGEN_LABELS)->toBe(Allergene::KURZ)
        ->and(Allergene::LANG['gluten'])->toBe('Glutenhaltiges Getreide')
        ->and(Allergene::KURZ['sulphites'])->toBe('Sulfite')
        ->and(Allergene::KURZ['lupin'])->toBe('Lupinen');   // Planung schrieb vorher „Lupine“
});
