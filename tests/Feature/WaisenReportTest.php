<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Plattform\WaisenReportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 3.5 Schritt 2 · Waisen-Report als Wächter. Abnahme 3.5: „Waisen-Report 0“ — im Test-Schema mit einer kleinen,
 * stimmigen Welt; auf io per `foodalchemist:diagnose:waisen`. Der Gegenfall zeigt, dass ein Verweis ins Leere gefunden wird
 * (Exit 1) und ein Verweis auf eine soft-gelöschte Elternzeile nur als Information zählt (Exit 0).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'waisen|butter', 'name' => 'Butter']);
    $this->fond = $this->makeRecipe($this->rootTeam, 'Fond');
    $this->makeIngredient($this->fond, 'Butter', $butter, '50');
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['is_sales_recipe' => true]);
    $this->zeile = $this->makeIngredient($this->gericht, 'Fond', null, '200');
    $this->zeile->update(['referenced_recipe_id' => $this->fond->id]);
    $cs = app(ConceptService::class);
    $this->paket = $cs->createPaket($this->rootTeam, ['name' => 'Paket']);
    $this->konzept = $cs->create($this->rootTeam, ['name' => 'Menü']);
    $this->slot = $cs->addSlot($this->rootTeam, $this->konzept->id, ['role' => 'Vorspeise']);
    $cs->fillSlot($this->rootTeam, $this->slot->id, ['embedded_concept_id' => $this->paket->id]);
});

function waisenJson(): array
{
    $code = Artisan::call('foodalchemist:diagnose:waisen', ['--json' => true]);

    return [$code, json_decode(Artisan::output(), true)];
}

it('deckt die Kernkette ab (Spalten aus dem Schema, Wächter läuft nicht ins Leere)', function () {
    $spalten = collect(app(WaisenReportService::class)->spalten())->map(fn ($s) => $s['tabelle'].'.'.$s['spalte']);

    expect($spalten->count())->toBeGreaterThan(100)
        ->and($spalten)->toContain('foodalchemist_recipe_ingredients.referenced_recipe_id')
        ->toContain('foodalchemist_recipe_ingredients.gp_id')
        ->toContain('foodalchemist_concept_slots.embedded_concept_id')
        ->toContain('foodalchemist_concept_slots.sales_recipe_id')
        ->toContain('foodalchemist_recipes.fassung_von_id')
        ->toContain('foodalchemist_recipe_presentations.vessel_gp_id')
        ->and($spalten->filter(fn ($s) => str_starts_with($s, 'foodalchemist_package')))->toBeEmpty();   // Alt-Pakete: eigener Abbau
});

it('stimmige Welt: 0 Waisen, Exit 0', function () {
    [$code, $json] = waisenJson();

    expect($code)->toBe(0)->and($json['ins_leere'])->toBe(0)->and($json['auf_geloescht'])->toBe(0)->and($json['zeilen'])->toBe([]);
});

it('Gegenfall: Verweis ins Leere → Exit 1 mit Tabelle und Spalte; Verweis auf soft-gelöschte Elternzeile nur als Information', function () {
    // Seit 3.5 Schritt 5 trägt die Spalte einen FK: die Waise nur mit abgeschalteter Prüfung anlegen (Tests laufen ohne Transaktion,
    // PRAGMA foreign_keys wirkt). So sieht der Report genau den Altbestand, den ein FK sonst verhindern würde.
    Schema::withoutForeignKeyConstraints(fn () => DB::table('foodalchemist_concept_slots')->where('id', $this->slot->id)->update(['embedded_concept_id' => 999999]));
    [$code, $json] = waisenJson();

    expect($code)->toBe(1)->and($json['ins_leere'])->toBe(1)
        ->and($json['zeilen'][0])->toMatchArray(['tabelle' => 'foodalchemist_concept_slots', 'spalte' => 'embedded_concept_id', 'ins_leere' => 1]);

    DB::table('foodalchemist_concept_slots')->where('id', $this->slot->id)->update(['embedded_concept_id' => $this->paket->id]);
    DB::table('foodalchemist_recipes')->where('id', $this->fond->id)->update(['deleted_at' => now()]);   // soft-gelöscht (ohne Observer-Folgen)
    [$code, $json] = waisenJson();

    expect($code)->toBe(0)->and($json['ins_leere'])->toBe(0)->and($json['auf_geloescht'])->toBeGreaterThanOrEqual(1)
        ->and(collect($json['zeilen'])->firstWhere('spalte', 'referenced_recipe_id'))->toMatchArray(['tabelle' => 'foodalchemist_recipe_ingredients', 'auf_geloescht' => 1]);
});
