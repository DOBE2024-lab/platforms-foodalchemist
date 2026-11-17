<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Zeilenart „Medium“ (Regelwerk Basisrezepte §18.7, Code-Freigabe 17.11.2026): setzbar im Zutaten-Editor und per MCP (recipe_ingredients.PUT),
 * lesbar in recipes.GET; GP-Merkmal is_kochmedium als Vorschlag im GP-Modal, per gps.PUT/GET und per Vorbelegungsbefehl.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.semantic_search.enabled' => false]);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'], ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->oel = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Frittieröl', 'gp_key' => 'km-1||',
        'status' => 'approved', 'commodity_group_code' => '05', 'main_ingredient_slug' => 'frittieroel']);
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Pommes: frittiert', ['status' => 'draft']);
    $this->tool = fn (string $name, array $args) => app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($this->user, $this->rootTeam));
});

it('MCP: recipe_ingredients.PUT setzt die Zeilenart, recipes.GET liest sie samt wirksamem Wert; weglassen behält, Unsinn wird abgelehnt', function () {
    $res = ($this->tool)('foodalchemist.recipe_ingredients.PUT', ['recipe_id' => $this->rezept->id, 'zutaten' => [
        ['name' => 'Frittieröl', 'gp_id' => $this->oel->id, 'quantity' => 2000, 'unit' => 'g', 'cooking_loss_pct' => 95, 'zeilenart' => 'medium'],
    ]]);
    expect($res->success)->toBeTrue($res->error ?? '');
    $zeile = $this->rezept->ingredients()->first();
    expect($zeile->zeilenart)->toBe('medium');

    // Weglassen (Voll-Sync mit id) behält die Zeilenart
    ($this->tool)('foodalchemist.recipe_ingredients.PUT', ['recipe_id' => $this->rezept->id, 'zutaten' => [
        ['id' => $zeile->id, 'name' => 'Frittieröl', 'gp_id' => $this->oel->id, 'quantity' => 1800, 'unit' => 'g'],
    ]]);
    expect($zeile->fresh()->zeilenart)->toBe('medium');

    $get = ($this->tool)('foodalchemist.recipes.GET', ['id' => $this->rezept->id]);
    expect($get->data['zutaten'][0]['zeilenart'])->toBe('medium')->and($get->data['zutaten'][0]['ist_kochmedium'])->toBeTrue();

    $falsch = ($this->tool)('foodalchemist.recipe_ingredients.PUT', ['recipe_id' => $this->rezept->id, 'zutaten' => [
        ['id' => $zeile->id, 'name' => 'Frittieröl', 'gp_id' => $this->oel->id, 'quantity' => 1800, 'unit' => 'g', 'zeilenart' => 'lake'],
    ]]);
    expect($falsch->success)->toBeFalse();

    // null hebt auf → ungesetzt (GP-Vorschlag gilt)
    ($this->tool)('foodalchemist.recipe_ingredients.PUT', ['recipe_id' => $this->rezept->id, 'zutaten' => [
        ['id' => $zeile->id, 'name' => 'Frittieröl', 'gp_id' => $this->oel->id, 'quantity' => 1800, 'unit' => 'g', 'zeilenart' => null],
    ]]);
    expect($zeile->fresh()->zeilenart)->toBeNull();
});

it('MCP: gps.PUT schreibt is_kochmedium, gps.GET liest es; ohne Parameter bleibt der Wert', function () {
    $res = ($this->tool)('foodalchemist.gps.PUT', ['id' => $this->oel->id, 'name' => 'Frittieröl', 'is_kochmedium' => true]);
    expect($res->success)->toBeTrue($res->error ?? '')->and($res->data['is_kochmedium'])->toBeTrue();

    ($this->tool)('foodalchemist.gps.PUT', ['id' => $this->oel->id, 'name' => 'Frittieröl']);
    expect((bool) $this->oel->fresh()->is_kochmedium)->toBeTrue()
        ->and(($this->tool)('foodalchemist.gps.GET', ['id' => $this->oel->id])->data['is_kochmedium'])->toBeTrue();
});

it('Zutaten-Editor: Zeilenart „Medium“ wird angezeigt und gespeichert, leer = ungesetzt', function () {
    $zeile = $this->makeIngredient($this->rezept, 'Frittieröl', $this->oel, '2000', 1);
    $zeile->update(['unit_vocab_id' => $this->g->id]);
    $this->oel->update(['is_kochmedium' => true]);

    $html = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->html();
    expect($html)->toContain('data-zeile-medium')->toContain('_gp_kochmedium');

    Livewire::test(IngredientEditor::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('speichern', [
            ['id' => $zeile->id, 'gp_id' => $this->oel->id, 'raw_text' => 'Frittieröl', 'quantity' => '2000', 'unit_vocab_id' => $this->g->id, 'zeilenart' => 'medium'],
        ])
        ->assertSet('fehler', null);
    expect($zeile->fresh()->zeilenart)->toBe('medium');

    Livewire::test(IngredientEditor::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('speichern', [
            ['id' => $zeile->id, 'gp_id' => $this->oel->id, 'raw_text' => 'Frittieröl', 'quantity' => '2000', 'unit_vocab_id' => $this->g->id, 'zeilenart' => ''],
        ]);
    expect($zeile->fresh()->zeilenart)->toBeNull();
});

it('GP-Modal: Kochmedium-Vorschlag wird geladen und gespeichert, Verluste bleiben', function () {
    $this->oel->update(['cooking_loss_default_pct' => 90]);

    Livewire::test(GpModal::class)->call('oeffnen', $this->oel->id)
        ->assertSet('istKochmedium', false)
        ->assertSeeHtml('data-gp-kochmedium')
        ->set('istKochmedium', true)
        ->call('speichern')
        ->assertSet('fehler', null);

    $gp = $this->oel->fresh();
    expect((bool) $gp->is_kochmedium)->toBeTrue()->and((float) $gp->cooking_loss_default_pct)->toBe(90.0);
    Livewire::test(GpModal::class)->call('oeffnen', $this->oel->id)->assertSet('istKochmedium', true);
});

it('Vorbelegung: Trockenlauf ändert nichts, --apply setzt Speisesalz, Frittierfett, Kochwasser (nicht Gewürzsalz, nicht Mineralwasser), --zurueck nimmt zurück', function () {
    $dir = sys_get_temp_dir() . '/kochmedium_' . uniqid();
    File::ensureDirectoryExists($dir);
    $salz = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Salz: trocken, jodiert', 'gp_key' => 'km-2||', 'main_ingredient_slug' => 'salz']);
    $gewuerz = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Gewürzsalz: Gyros, trocken', 'gp_key' => 'km-3||', 'main_ingredient_slug' => 'gewuerzsalz']);
    $wasser = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Leitungswasser', 'gp_key' => 'km-4||', 'main_ingredient_slug' => 'leitungswasser']);
    $mineral = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Mineralwasser: still', 'gp_key' => 'km-5||', 'main_ingredient_slug' => 'mineralwasser']);
    $wert = fn (FoodAlchemistGp $gp) => (bool) DB::table('foodalchemist_gps')->where('id', $gp->id)->value('is_kochmedium');

    $this->artisan('foodalchemist:kochmedium-vorbelegen', ['--bericht' => $dir])->assertSuccessful()->expectsOutputToContain('Trockenlauf');
    expect($wert($salz))->toBeFalse();
    $this->artisan('foodalchemist:kochmedium-vorbelegen', ['--bericht' => $dir, '--apply' => true])->assertFailed();   // ohne Backup

    $backup = $dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));
    $this->artisan('foodalchemist:kochmedium-vorbelegen', ['--bericht' => $dir, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();
    expect($wert($salz))->toBeTrue()->and($wert($this->oel))->toBeTrue()
        ->and($wert($wasser))->toBeTrue()
        ->and($wert($gewuerz))->toBeFalse()->and($wert($mineral))->toBeFalse();

    $this->artisan('foodalchemist:kochmedium-vorbelegen', ['--zurueck' => glob($dir . '/*_kochmedium_protokoll.json')[0]])->assertSuccessful();
    expect($wert($salz))->toBeFalse()->and($wert($this->oel))->toBeFalse();
    File::deleteDirectory($dir);
});
