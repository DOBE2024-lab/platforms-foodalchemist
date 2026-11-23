<?php

use Illuminate\Support\Facades\Artisan;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · Diät-Fix (Befund Kurator 23.11.2026, io, BPC Rhein Ruhr): „[HG] Confierte Regenbogenforelle“ stand als
 * vegan, laktosefrei, halal — bei „Deklaration nicht bewertet“. Ursache: `mergeAssure([])` gab true, ein Rezept ohne
 * Pflicht-Zutat sicherte also alles zu, und das Gericht erbte es vom leeren Sub. Regel ab jetzt: ohne bewertete
 * Deklaration ist die Eignung unbekannt (NULL), nie erfüllt. Eine belegte Verletzung (Fisch ⇒ nicht vegan) bleibt false.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeRecomputeService::class);
    $this->zusicherung = ['spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_lactose_free'];
    $this->gpMit = function (string $name, array $tags): FoodAlchemistGp {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->forceFill($tags)->save();

        return $gp;
    };
    $this->veganGp = ($this->gpMit)('Kichererbse', ['tag_is_vegan' => true, 'tag_is_vegetarian' => true, 'tag_is_halal' => true,
        'tag_is_lactose_free' => true, 'tag_contains_pork' => false, 'tag_contains_beef' => false]);
    $this->flags = fn (FoodAlchemistRecipe $r) => $r->fresh()->only($this->zusicherung);
    $this->rechne = function (FoodAlchemistRecipe $r) {
        $this->svc->recomputePipeline($r->id, false);

        return $r->fresh();
    };
    $this->alleNull = array_fill_keys(['spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_lactose_free'], null);
});

it('Gegenprobe: bekannte vegane Zutat sichert weiter zu', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Hummus');
    $this->makeIngredient($r, 'Kichererbsen', $this->veganGp);

    expect(($this->flags)(($this->rechne)($r)))->toBe(array_fill_keys($this->zusicherung, true));
});

it('leeres Rezept: alles unbekannt, auch Schwein/Rind', function () {
    $r = ($this->rechne)($this->makeRecipe($this->rootTeam, 'Stub'));

    expect(($this->flags)($r))->toBe($this->alleNull)
        ->and($r->spec_contains_pork)->toBeNull()
        ->and($r->spec_contains_beef)->toBeNull();
});

it('nur optionale oder nur ignorierte Zeilen: unbekannt', function () {
    $opt = $this->makeRecipe($this->rootTeam, 'Nur optional');
    $this->makeIngredient($opt, 'Kichererbsen', $this->veganGp)->update(['is_optional' => true]);
    $ign = $this->makeRecipe($this->rootTeam, 'Nur ignoriert');
    $this->makeIngredient($ign, 'Kichererbsen', $this->veganGp)->update(['match_method' => MatchMethod::Ignored]);

    expect(($this->flags)(($this->rechne)($opt)))->toBe($this->alleNull)
        ->and(($this->flags)(($this->rechne)($ign)))->toBe($this->alleNull);
});

it('Gericht aus leerem Sub-Stub erbt kein „vegan“ (Forellen-Fall)', function () {
    $stub = ($this->rechne)($this->makeRecipe($this->rootTeam, 'Meerrettich-Beurre-blanc'));
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Confierte Regenbogenforelle');
    $this->makeIngredient($gericht, 'Beurre blanc')->update(['referenced_recipe_id' => $stub->id, 'match_method' => MatchMethod::Manual]);
    $this->makeIngredient($gericht, 'Kichererbsen', $this->veganGp, '50', 2);

    expect(($this->flags)(($this->rechne)($gericht)))->toBe($this->alleNull);
});

it('belegte Verletzung bleibt false: Fisch ist nicht vegan, auch neben unbekannter Zeile', function () {
    $forelle = ($this->gpMit)('Forelle', ['tag_is_vegan' => false, 'tag_is_vegetarian' => false, 'tag_is_halal' => true, 'tag_is_lactose_free' => true]);
    $r = $this->makeRecipe($this->rootTeam, 'Forellen-Rillette');
    $this->makeIngredient($r, 'Forelle', $forelle);
    $this->makeIngredient($r, 'Gewürz ohne GP', null, '5', 2);

    $f = ($this->flags)(($this->rechne)($r));
    expect($f['spec_is_vegan'])->toBeFalse()->and($f['spec_is_vegetarian'])->toBeFalse()
        ->and($f['spec_is_halal'])->toBeNull()->and($f['spec_is_lactose_free'])->toBeNull();
});

it('KI-Vorschlag unter 0.85 sichert nichts zu, ab 0.85 schon (wie bei den Allergenen)', function () {
    $unsicher = $this->makeRecipe($this->rootTeam, 'Unsicher');
    $this->makeIngredient($unsicher, 'Kichererbsen', $this->veganGp)->update(['match_method' => MatchMethod::GeminiProposed, 'match_confidence' => 0.6]);
    $sicher = $this->makeRecipe($this->rootTeam, 'Sicher');
    $this->makeIngredient($sicher, 'Kichererbsen', $this->veganGp)->update(['match_method' => MatchMethod::GeminiProposed, 'match_confidence' => 0.9]);

    expect(($this->flags)(($this->rechne)($unsicher)))->toBe($this->alleNull)
        ->and(($this->flags)(($this->rechne)($sicher)))->toBe(array_fill_keys($this->zusicherung, true));
});

it('Deklaration: unbewertet deckelt true auf unbekannt, Milch macht nicht laktosefrei', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Hummus');
    $this->makeIngredient($r, 'Kichererbsen', $this->veganGp);
    ($this->rechne)($r);

    // Deckelung: gespeicherte Deklaration unbewertet (Altbestand), Zutaten sauber → Zusicherung unbekannt
    $r->forceFill(['allergens_confidence' => 'unknown'])->saveQuietly();
    $this->svc->specFlagsNeuRechnen($r->id, true);
    expect(($this->flags)($r))->toBe($this->alleNull);

    // Milch enthalten/Spuren ⇒ laktosefrei false, die übrigen Zusicherungen bleiben
    $r->forceFill(['allergens_confidence' => 'high', 'allergen_milk' => 'spuren'])->saveQuietly();
    $this->svc->specFlagsNeuRechnen($r->id, true);
    expect(($this->flags)($r))->toBe(['spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_halal' => true, 'spec_is_lactose_free' => false]);
});

it('Konzept-Anzeige: NULL ist unbekannt, weder erfüllt noch „nicht geeignet“', function () {
    $agg = app(ConcepterAggregateService::class);
    $bekannt = (object) ['id' => 1, 'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_halal' => true,
        'spec_is_gluten_free' => true, 'spec_is_lactose_free' => true, 'spec_contains_pork' => false, 'spec_contains_beef' => false,
        'allergens_confidence' => 'high'];
    $offen = (object) ['id' => 2, 'spec_is_vegan' => null, 'spec_is_vegetarian' => null, 'spec_is_halal' => null,
        'spec_is_gluten_free' => null, 'spec_is_lactose_free' => null, 'spec_contains_pork' => null, 'spec_contains_beef' => null,
        'allergens_confidence' => 'unknown'];

    $rollup = $agg->allergenRollupFromGerichte(collect([$bekannt, $offen]));
    expect($rollup['is_vegan'])->toBeFalse()
        ->and($rollup['fleisch_belegt'])->toBeFalse()
        ->and($rollup['offen']['is_vegan'])->toBe(1);
});

it('Befehl: Trockenlauf schreibt nichts, --apply schreibt mit Journal, --rueckweg stellt her', function () {
    $stub = $this->makeRecipe($this->rootTeam, 'Altlast-Stub');
    // Altbestand: leeres Rezept mit „vegan“ aus der alten Regel
    $stub->forceFill(array_fill_keys($this->zusicherung, true) + ['spec_n_total' => 0])->saveQuietly();
    $ordner = storage_path('framework/testing/diaet-'.uniqid());

    Artisan::call('foodalchemist:diaet-eignung-neu', ['--rezept' => [$stub->id]]);
    expect(($this->flags)($stub))->toBe(array_fill_keys($this->zusicherung, true));

    Artisan::call('foodalchemist:diaet-eignung-neu', ['--rezept' => [$stub->id], '--apply' => true, '--journal-ordner' => $ordner]);
    expect(($this->flags)($stub))->toBe($this->alleNull);
    $journal = glob($ordner.'/journal-*.json');
    expect($journal)->toHaveCount(1);

    Artisan::call('foodalchemist:diaet-eignung-neu', ['--rueckweg' => $journal[0]]);
    expect(($this->flags)($stub))->toBe(array_fill_keys($this->zusicherung, true));
    array_map('unlink', $journal);
    @rmdir($ordner);
});
