<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Rezept\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Folge aus CQ 2.1e: `SensorikService::fuerRezepte()` bündelt die Sensorik vieler Gerichte (Menü-Bewertung im Konzepter-Kopf).
 * Pflicht: dasselbe Ergebnis wie `fuerRezept()` je ID — für gespeichertes Profil, Roh-Pfad mit Zutaten/Unterrezept, Basisrezept und leeres Rezept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $t = fn (string $slug) => DB::table('foodalchemist_vocab_textures')->insertGetId(['slug' => $slug, 'display_de' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]);
    $knusprig = $t('knusprig');
    $cremig = $t('cremig');
    $vektor = fn (string $tabelle, string $spalte, int $id, array $dims) => DB::table($tabelle)->insert(array_merge(
        array_fill_keys(SensorikService::DIMS, 0), [$spalte => $id, 'source' => 'gemini', 'created_at' => now(), 'updated_at' => now()], $dims));

    // 1) Gericht mit gespeichertem (KI-)Profil + Texturen
    $this->ki = $this->makeRecipe($this->rootTeam, 'Rinderbacke', ['is_sales_recipe' => true]);
    $vektor('foodalchemist_recipe_taste_vectors', 'recipe_id', $this->ki->id, ['umami' => 0.8, 'salzig' => 0.4]);
    DB::table('foodalchemist_recipe_textures')->insert([['recipe_id' => $this->ki->id, 'texture_vocab_id' => $cremig, 'intensity' => 2, 'created_at' => now(), 'updated_at' => now()]]);

    // 2) Gericht ohne Profil: Zutaten mit GP-Vektoren/Texturen + ein Unterrezept
    $zucker = $this->makeGp($this->rootTeam, 'Zucker');
    $nuss = $this->makeGp($this->rootTeam, 'Haselnuss');
    $vektor('foodalchemist_gp_taste_vectors', 'gp_id', $zucker->id, ['suess' => 0.9]);
    $vektor('foodalchemist_gp_taste_vectors', 'gp_id', $nuss->id, ['fettig' => 0.6, 'bitter' => 0.2]);
    DB::table('foodalchemist_gp_textures')->insert([
        ['gp_id' => $nuss->id, 'texture_vocab_id' => $knusprig, 'intensity' => 3, 'created_at' => now(), 'updated_at' => now()],
        ['gp_id' => $zucker->id, 'texture_vocab_id' => $knusprig, 'intensity' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->krokant = $this->makeRecipe($this->rootTeam, 'Krokant');
    $this->makeIngredient($this->krokant, 'Zucker', $zucker, '200', 1);
    $this->makeIngredient($this->krokant, 'Haselnuss', $nuss, '100', 2);
    $this->dessert = $this->makeRecipe($this->rootTeam, 'Krokant-Parfait', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->dessert, 'Haselnuss', $nuss, '50', 1);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->dessert->id,
        'referenced_recipe_id' => $this->krokant->id, 'raw_text' => 'Krokant', 'quantity' => '80', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 2]);

    // 3) leeres Gericht
    $this->leer = $this->makeRecipe($this->rootTeam, 'Platzhalter', ['is_sales_recipe' => true]);
});

it('liefert je ID dasselbe wie fuerRezept (Profil, Roh-Pfad mit Unterrezept, Basisrezept, leer)', function () {
    $ids = [$this->ki->id, $this->dessert->id, $this->krokant->id, $this->leer->id];
    $einzeln = [];
    foreach ($ids as $id) {
        $einzeln[$id] = app(SensorikService::class)->fuerRezept($id);
    }

    expect(app(SensorikService::class)->fuerRezepte($ids))->toEqual($einzeln)
        ->and($einzeln[$this->dessert->id]['leer'] ?? false)->toBeFalse()             // Fixture deckt den Roh-Pfad wirklich ab
        ->and($einzeln[$this->ki->id]['source'])->toBe('ki');
});

it('rollenChecks entspricht rollenCheck je Gericht', function () {
    $g = ['suess' => 0.1, 'umami' => 0.7];
    $ids = [$this->ki->id, $this->dessert->id];

    expect(app(SensorikService::class)->rollenChecks(array_fill_keys($ids, $g)))
        ->toEqual(array_combine($ids, array_map(fn ($id) => app(SensorikService::class)->rollenCheck($id, $g), $ids)));
});

it('braucht für viele Gerichte eine feste Zahl Abfragen; der Vorrat ist danach leer', function () {
    $svc = app(SensorikService::class);
    $zaehle = function (array $ids) use ($svc) {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $svc->fuerRezepte($ids);

        return $n;
    };
    $weitere = collect(range(1, 6))->map(fn ($i) => $this->makeRecipe($this->rootTeam, "Leer {$i}", ['is_sales_recipe' => true])->id)->all();
    $zwei = $zaehle([$this->ki->id, $this->leer->id]);
    $acht = $zaehle([$this->ki->id, $this->leer->id, ...$weitere]);

    expect($acht)->toBe($zwei)
        ->and((fn () => $this->vorrat)->call($svc))->toBeNull();
});
