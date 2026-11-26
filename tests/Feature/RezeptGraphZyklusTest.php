<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\RezeptZyklusException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\GpStamm\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\Fassung\FassungsUmstellung;
use Platform\FoodAlchemist\Services\Pruefung\HardstopResolveService;
use Platform\FoodAlchemist\Services\Planung\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Rezept\RezeptGraphService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1e · EIN Graph-Schreibweg für Kanten Zutat → Unterrezept (Mess-Tests 3 + 4 aus Bericht 97).
 * Jeder Schreibweg lehnt A→A und A→B→A ab; ein per DB::table eingeschleuster Zyklus führt in recomputeMany und
 * explodiere zu definiertem Verhalten statt still falscher Zahlen.
 *
 * Parallelität: SQLite ignoriert `FOR UPDATE` (die Grammatik lässt die Sperre weg; SQLite serialisiert Schreiber ohnehin).
 * Der echte Nachweis gehört auf MySQL und ist hier nur beschrieben: zwei Verbindungen, Bestand B→C und D→A.
 * Verbindung 1: BEGIN; RezeptGraphService::sichereKante(A, B) (nimmt die Zeilensperre `rezeptgraph`) — noch kein Commit.
 * Verbindung 2: BEGIN; sichereKante(C, D) → wartet an der Sperre. Verbindung 1 schreibt A→B, COMMIT.
 * Verbindung 2 läuft weiter, sieht A→B, findet C→D→A→B→C und wirft RezeptZyklusException. Ohne Sperre gingen beide durch (Zyklus).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->graph = app(RezeptGraphService::class);
    $this->mk = fn (string $name, bool $vk = false) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'k_' . uniqid(), 'name' => $name, 'status' => 'draft',
        'is_sales_recipe' => $vk, 'yield_kg' => 1.0, 'sales_unit_count' => $vk ? 10 : null,
    ]);
    // Kante direkt in die DB (an allen Schreibwegen vorbei)
    $this->kante = fn (FoodAlchemistRecipe $von, ?FoodAlchemistRecipe $nach, int $pos = 1) => DB::table('foodalchemist_recipe_ingredients')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $von->id, 'position' => $pos,
        'referenced_recipe_id' => $nach?->id, 'raw_text' => $nach?->name ?? 'offen', 'quantity' => 100, 'unit_vocab_id' => $this->g->id,
        'match_method' => $nach ? 'recipe_ref' : 'unmatched', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->a = ($this->mk)('A');
    $this->b = ($this->mk)('B');
});

it('findeZyklus: Selbstschleife, Zweier-Kreis, Vierer-Kreis, zyklenfrei mit Diamant', function () {
    expect(RezeptGraphService::findeZyklus([1 => [1]]))->toBe([1])
        ->and(RezeptGraphService::findeZyklus([1 => [2], 2 => [1]]))->toBe([1, 2])
        ->and(RezeptGraphService::findeZyklus([1 => [2], 2 => [3], 3 => [4], 4 => [1]]))->toBe([1, 2, 3, 4])
        ->and(RezeptGraphService::findeZyklus([1 => [2, 3], 2 => [4], 3 => [4], 4 => []]))->toBeNull();
});

it('Sperre nur in einer Transaktion: sichereKante ohne Transaktion ist ein Programmierfehler', function () {
    expect(fn () => $this->graph->sichereKante($this->a->id, $this->b->id))->toThrow(LogicException::class);
});

it('syncIngredients (UI, MCP, Generator, Import, Vorlage): A→A und A→B→A abgelehnt, nichts geschrieben', function () {
    $svc = app(RecipeService::class);
    $zeile = fn (FoodAlchemistRecipe $sub) => ['referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name, 'quantity' => 100, 'unit_vocab_id' => $this->g->id, 'auto_ground' => false];

    expect(fn () => $svc->syncIngredients($this->rootTeam, $this->a->id, [$zeile($this->a)]))->toThrow(RezeptZyklusException::class, 'Selbstreferenz');

    $svc->syncIngredients($this->rootTeam, $this->a->id, [$zeile($this->b)]);                     // A→B
    expect(fn () => $svc->syncIngredients($this->rootTeam, $this->b->id, [$zeile($this->a)]))      // B→A schlösse den Kreis
        ->toThrow(RezeptZyklusException::class, 'Zyklus');
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $this->b->id)->count())->toBe(0);

    // Eine bestehende Kante erneut speichern bleibt erlaubt (keine neue Kante, keine Sperre nötig)
    $svc->syncIngredients($this->rootTeam, $this->a->id, [$zeile($this->b)]);
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $this->a->id)->value('referenced_recipe_id'))->toBe($this->b->id);
});

it('tauscheZutat (Ersatz Fertig↔Selbst): Tausch auf ein Unterrezept, das den Kreis schlösse, wird abgelehnt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Fond fertig');
    ($this->kante)($this->a, $this->b);                                                          // A→B
    $zeileB = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->b->id, 'position' => 1,
        'gp_id' => $gp->id, 'raw_text' => 'Fond', 'quantity' => 100, 'unit_vocab_id' => $this->g->id, 'match_method' => 'manual']);
    app(ComponentEquivalentService::class)->verknuepfe($this->rootTeam, 'gp', $gp->id, 'recipe', $this->a->id, 1.0);

    expect(fn () => app(ComponentEquivalentService::class)->tauscheZutat($this->rootTeam, $zeileB->id))
        ->toThrow(RezeptZyklusException::class);
    expect($zeileB->fresh()->referenced_recipe_id)->toBeNull()->and($zeileB->fresh()->gp_id)->toBe($gp->id);

    // Selbstreferenz: B-Zeile auf B tauschen
    $c = ($this->mk)('C');
    $zeileC = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $c->id, 'position' => 1,
        'gp_id' => $gp->id, 'raw_text' => 'Fond', 'quantity' => 100, 'unit_vocab_id' => $this->g->id, 'match_method' => 'manual']);
    \Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent::query()->delete();
    app(ComponentEquivalentService::class)->verknuepfe($this->rootTeam, 'gp', $gp->id, 'recipe', $c->id, 1.0);
    expect(fn () => app(ComponentEquivalentService::class)->tauscheZutat($this->rootTeam, $zeileC->id))
        ->toThrow(RezeptZyklusException::class, 'Selbstreferenz');
});

it('FassungsUmstellung::einRezept (Query-Builder-Update): Umhängen auf ein Rezept, das den Kreis schlösse, wird abgelehnt', function () {
    $alt = ($this->mk)('Alt');
    ($this->kante)($this->b, $alt);                                                              // B→Alt
    ($this->kante)($this->a, $this->b);                                                          // A→B
    expect(fn () => app(FassungsUmstellung::class)->einRezept($this->b, $alt->id, $this->a->id))   // B→A schlösse den Kreis
        ->toThrow(RezeptZyklusException::class);
    expect(fn () => app(FassungsUmstellung::class)->einRezept($this->b, $alt->id, $this->b->id))   // B→B
        ->toThrow(RezeptZyklusException::class, 'Selbstreferenz');
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $this->b->id)->value('referenced_recipe_id'))->toBe($alt->id);

    $neu = ($this->mk)('Neu');
    expect(app(FassungsUmstellung::class)->einRezept($this->b, $alt->id, $neu->id))->toBe(1);
});

it('haengeUm mit Alias-Join (Weg der Abo-Kündigung): ein Zyklus lehnt das ganze Umhängen ab', function () {
    $alt = ($this->mk)('Alt');
    $c = ($this->mk)('C');
    ($this->kante)($this->b, $alt);
    ($this->kante)($c, $alt);
    ($this->kante)($this->a, $this->b);                                                          // A→B: B auf A umhängen schlösse den Kreis
    $q = fn () => DB::table('foodalchemist_recipe_ingredients AS z')->where('z.referenced_recipe_id', $alt->id)->whereNull('z.deleted_at');

    expect(fn () => $this->graph->haengeUm($q(), $this->a->id, [], 'z.recipe_id'))->toThrow(RezeptZyklusException::class);
    expect(FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $alt->id)->count())->toBe(2);   // auch C nicht umgehängt
});

it('HardstopResolve::verknuepfe: offene Zeile auf ein Unterrezept, das den Kreis schlösse → Meldung, nichts gebunden', function () {
    ($this->kante)($this->a, $this->b);                                                          // A→B
    ($this->kante)($this->b, null, 1);                                                           // offene Zeile in B
    $r = app(HardstopResolveService::class)->verknuepfe($this->rootTeam, $this->b->id, 1, 'sub', $this->a->id);
    expect($r['ok'])->toBeFalse()->and($r['meldung'])->toContain('Zyklus');
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $this->b->id)->value('referenced_recipe_id'))->toBeNull();
});

it('ersetzeInVerwendungen: Eltern, die einen Kreis bekämen, bleiben stehen; nur Zyklen → Ablehnung', function () {
    $alt = ($this->mk)('Alt');
    ($this->kante)($this->b, $alt);                                                              // B→Alt
    ($this->kante)($this->a, $this->b);                                                          // A→B
    expect(fn () => app(RecipeService::class)->ersetzeInVerwendungen($this->rootTeam, $alt->id, $this->a->id))
        ->toThrow(RezeptZyklusException::class, 'Zyklus');
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $this->b->id)->value('referenced_recipe_id'))->toBe($alt->id);
});

it('eingeschleuster Zyklus: recomputeMany rechnet den zyklenfreien Teil und meldet die Zyklus-Rezepte als Fehler', function () {
    $blatt = ($this->mk)('Blatt');
    $gericht = ($this->mk)('Gericht', true);
    ($this->kante)($this->a, $this->b);
    ($this->kante)($this->b, $this->a);                                                          // A⇄B eingeschleust
    ($this->kante)($gericht, $this->a);                                                          // Gericht baut auf dem Kreis auf

    app(RecipeRecomputeService::class)->recomputeMany([$this->a->id, $blatt->id], $fehler);

    expect(array_keys($fehler))->toEqualCanonicalizing([$this->a->id, $this->b->id, $gericht->id])
        ->and($fehler[$this->a->id])->toContain('Zyklus')
        ->and($fehler)->not->toHaveKey($blatt->id);
});

it('eingeschleuster Zyklus: explodiere (Produktionsblatt) wirft mit den Rezeptnamen statt still falschen Bedarf zu liefern', function () {
    $gericht = ($this->mk)('Gericht', true);
    ($this->kante)($gericht, $this->a);
    ($this->kante)($this->a, $this->b);
    ($this->kante)($this->b, $this->a, 2);

    expect(fn () => app(PlanungsblattService::class)->produktionsblatt($this->rootTeam, ['recipe_id' => $gericht->id, 'portions' => 10]))
        ->toThrow(RezeptZyklusException::class, 'Zyklus im Rezeptgraph: A → B → A');
});
