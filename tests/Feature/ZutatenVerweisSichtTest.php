<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1j (Mandanten-Prüfungen) · 1: syncIngredients nimmt als Unterrezept, Grundprodukt und Einheit nur, was das Team SIEHT
 * (visibleToTeam: Master-Kette, Abo-Fassungen, Stamm-Paket-Opt-in) — fremde IDs werden abgelehnt (Kosten/Allergene, Gästesicherheit).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(RecipeService::class);
    $this->zeile = fn (array $ziel, Team $t) => [['raw_text' => 'Zeile', 'quantity' => 100, 'unit_vocab_id' => $this->unitG($t)->id, 'auto_ground' => false] + $ziel];
});

it('Unterrezept: Master-Kette erlaubt, Geschwister-Team abgelehnt; Altbestand bleibt speicherbar', function () {
    $rootBasis = $this->makeRecipe($this->rootTeam, 'Fond: Root');
    $fremd = $this->makeRecipe($this->childB, 'Fond: Geschwister');
    $kind = $this->makeRecipe($this->childA, 'Sauce: Kind');

    $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['referenced_recipe_id' => $rootBasis->id], $this->rootTeam));
    expect(fn () => $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['referenced_recipe_id' => $fremd->id], $this->rootTeam)))
        ->toThrow(RuntimeException::class, 'Unterrezept nicht sichtbar');

    // Altbestand (per DB eingeschleust) blockiert das Speichern nicht
    FoodAlchemistRecipeIngredient::where('recipe_id', $kind->id)->update(['referenced_recipe_id' => $fremd->id]);
    $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['referenced_recipe_id' => $fremd->id], $this->rootTeam));
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $kind->id)->value('referenced_recipe_id'))->toBe($fremd->id);
});

it('Grundprodukt und Einheit: fremdes Team abgelehnt', function () {
    $kind = $this->makeRecipe($this->childA, 'Sauce: Kind');
    $gpFremd = $this->makeGp($this->childB, 'Geschwister-Butter');
    expect(fn () => $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['gp_id' => $gpFremd->id], $this->rootTeam)))
        ->toThrow(RuntimeException::class, 'Grundprodukt nicht sichtbar');
    $gpRoot = $this->makeGp($this->rootTeam, 'Root-Butter');
    $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['gp_id' => $gpRoot->id], $this->rootTeam));

    expect(fn () => $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['gp_id' => $gpRoot->id], $this->childB)))
        ->toThrow(RuntimeException::class, 'Einheit nicht sichtbar');
});

it('Grundprodukt aus dem Stamm-Paket: mit Opt-in erlaubt, ohne abgelehnt', function () {
    $gp = FoodAlchemistGp::create(['team_id' => null, 'stamm_class' => 'stamm_paket', 'gp_key' => 'stamm_butter', 'name' => 'Butter: Stamm',
        'status' => 'approved', 'commodity_group_code' => '04', 'requires_la' => true]);
    $kind = $this->makeRecipe($this->childA, 'Sauce: Kind');
    expect(fn () => $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['gp_id' => $gp->id], $this->rootTeam)))
        ->toThrow(RuntimeException::class, 'Grundprodukt nicht sichtbar');

    StammPaket::freischalten($this->childA);
    TeamAncestryRegistry::flushAll();
    $this->svc->syncIngredients($this->childA, $kind->id, ($this->zeile)(['gp_id' => $gp->id], $this->rootTeam));
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $kind->id)->value('gp_id'))->toBe($gp->id);
});

it('Abo: die abonnierte Fassung ist als Unterrezept einsetzbar, die Master-Arbeitszeile nicht; Freigabe des eigenen Gerichts geht durch', function () {
    $master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    $mandant = Team::create(['name' => 'Mandant', 'user_id' => 1, 'personal_team' => false]);
    StammPaket::freischalten($mandant);   // Abo setzt ein gebuchtes Stamm-Paket voraus (Spec 82)
    TeamAncestryRegistry::flushAll();
    $kurator = $this->makeUser($master, 'Kurator', 'owner');
    $chef = $this->makeUser($mandant, 'Chef', 'owner');
    $jus = $this->makeRecipe($master, 'Kalbsjus', ['status' => 'approved']);
    $jusF = $this->makeRecipe($master, 'Kalbsjus', ['ist_fassung' => true, 'fassung_von_id' => $jus->id, 'status' => 'draft']);   // Status der Fassung egal: veröffentlicht
    $inhalte = app(InhaltsFreigabeService::class);
    $sammlung = $inhalte->sammlungAnlegen($master, 'Basis', null, $kurator);
    $inhalte->sammlungHinzu($master, $sammlung, 'recipe', [$jus->id], $kurator);
    app(AboService::class)->abonnieren($master, $mandant, $sammlung, $kurator);

    $this->actingAs($chef);
    $gericht = $this->makeRecipe($mandant, 'Mandanten-Gericht', ['is_sales_recipe' => true, 'status' => 'draft']);
    expect(fn () => $this->svc->syncIngredients($mandant, $gericht->id, ($this->zeile)(['referenced_recipe_id' => $jus->id], $mandant)))
        ->toThrow(RuntimeException::class, 'Unterrezept nicht sichtbar');
    $this->svc->syncIngredients($mandant, $gericht->id, ($this->zeile)(['referenced_recipe_id' => $jusF->id], $mandant));

    $this->svc->setStatus($mandant, $gericht->id, 'approved');
    expect($gericht->refresh()->status->value)->toBe('approved');
});
