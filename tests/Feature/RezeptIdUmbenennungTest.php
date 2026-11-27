<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Support\RezeptIdAlias;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 3.5 Schritt 4: `sales_recipe_id` → `recipe_id`. Hält fest: (1) nach dem Migrieren trägt keine der sechs Tabellen
 * mehr den alten Namen; (2) down() und up() sind ein verlustfreier Rundlauf — Daten, Fremdschlüssel (Ziel, ON DELETE) und
 * Indizes bleiben, nur der Name wechselt (auch ein später ergänzter FK wie der aus Schritt 5 an dish_ideas); (3) die MCP-Tools
 * nehmen den alten Namen bis vier Wochen nach dem Deploy als Alias an und geben beide Namen aus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->migration = require __DIR__ . '/../../database/migrations/2027_01_15_100000_rename_sales_recipe_id_to_recipe_id.php';
});

/** Spalten-Zustand je Tabelle, mit dem Spaltennamen als Platzhalter (damit alt und neu vergleichbar sind). */
function rezeptIdZustand(string $spalte): array
{
    $z = [];
    foreach (DB::connection()->getSchemaBuilder()->getTables() as $t) {
        $n = $t['name'];
        if (! in_array($n, [
            'foodalchemist_concept_slots', 'foodalchemist_foodbook_blocks', 'foodalchemist_offer_blocks',
            'foodalchemist_menu_card_items', 'foodalchemist_menu_plan_entries', 'foodalchemist_dish_ideas',
        ], true)) {
            continue;
        }
        $fks = collect(Schema::getForeignKeys($n))->filter(fn ($f) => in_array($spalte, $f['columns'], true))
            ->map(fn ($f) => [$f['foreign_table'], strtolower((string) $f['on_delete'])])->sort()->values()->all();
        $idx = collect(Schema::getIndexes($n))->filter(fn ($i) => in_array($spalte, $i['columns'], true))
            ->map(fn ($i) => [str_replace($spalte, 'X', $i['name']), str_replace($spalte, 'X', implode(',', $i['columns'])), $i['unique']])->sort()->values()->all();
        $z[$n] = ['spalte' => Schema::hasColumn($n, $spalte), 'fks' => $fks, 'indizes' => $idx];
    }
    ksort($z);

    return $z;
}

it('nach dem Migrieren heißt die Spalte überall recipe_id', function () {
    foreach ($this->migration::TABELLEN as $t) {
        expect(Schema::hasColumn($t, 'recipe_id'))->toBeTrue($t)
            ->and(Schema::hasColumn($t, 'sales_recipe_id'))->toBeFalse($t);
    }
});

it('down() und up() sind ein verlustfreier Rundlauf: Daten, FKs und Indizes bleiben', function () {
    $svc = app(ConceptService::class);
    $concept = $svc->create($this->rootTeam, ['name' => 'Rundlauf']);
    $gericht = $this->makeRecipe($this->rootTeam, 'DES: Rundlauf', ['is_sales_recipe' => true]);
    $slot = $svc->fillSlot($this->rootTeam, $svc->addSlot($this->rootTeam, $concept->id, ['role' => 'Gang'])->id, ['recipe_id' => $gericht->id]);
    $neu = rezeptIdZustand('recipe_id');

    $this->migration->down();
    $alt = rezeptIdZustand('sales_recipe_id');
    expect(DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->value('sales_recipe_id'))->toBe($gericht->id)
        ->and($alt)->toBe($neu);

    $this->migration->up();
    $this->migration->up();   // idempotent
    expect(rezeptIdZustand('recipe_id'))->toBe($neu)
        ->and(FoodAlchemistConceptSlot::find($slot->id)->recipe_id)->toBe($gericht->id)
        ->and(collect($neu)->every(fn ($t) => $t['spalte']))->toBeTrue();
});

it('Alias: Eingabe sales_recipe_id wird recipe_id, auch verschachtelt; recipe_id gewinnt', function () {
    expect(RezeptIdAlias::eingabe(['sales_recipe_id' => 5, 'felder' => ['sales_recipe_id' => 6], 'items' => [['sales_recipe_id' => 7, 'quantity' => 1]]]))
        ->toBe(['recipe_id' => 5, 'felder' => ['recipe_id' => 6], 'items' => [['recipe_id' => 7, 'quantity' => 1]]])
        ->and(RezeptIdAlias::eingabe(['recipe_id' => 1, 'sales_recipe_id' => 2]))->toBe(['recipe_id' => 1])
        ->and(RezeptIdAlias::ausgabe(['a' => ['recipe_id' => 3]]))->toBe(['a' => ['recipe_id' => 3, 'sales_recipe_id' => 3]]);
});

it('MCP: concept_slots.PUT/POST füllen mit dem alten Namen, die Antwort trägt beide Namen', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new ToolContext($user, $this->rootTeam);
    $registry = app(ToolRegistry::class);
    $svc = app(ConceptService::class);
    $concept = $svc->create($this->rootTeam, ['name' => 'Alias']);
    $gericht = $this->makeRecipe($this->rootTeam, 'DES: Alias', ['is_sales_recipe' => true]);
    $slot = $svc->addSlot($this->rootTeam, $concept->id, ['role' => 'Gang']);

    $put = $registry->get('foodalchemist.concept_slots.PUT')->execute(['slot_id' => $slot->id, 'felder' => ['sales_recipe_id' => $gericht->id]], $kontext);
    expect($put->success)->toBeTrue()
        ->and(FoodAlchemistConceptSlot::find($slot->id)->recipe_id)->toBe($gericht->id)
        ->and($registry->get('foodalchemist.concept_slots.POST')->getSchema()['properties'])->toHaveKeys(['recipe_id', 'sales_recipe_id']);

    $post = $registry->get('foodalchemist.concept_slots.POST')->execute(['concept_id' => $concept->id, 'role' => 'Gang 2', 'sales_recipe_id' => $gericht->id], $kontext);
    expect($post->success)->toBeTrue()
        ->and($post->data['slot']['recipe_id'] ?? $post->data['recipe_id'] ?? null)->toBe($gericht->id)
        ->and($post->data['slot']['sales_recipe_id'] ?? $post->data['sales_recipe_id'] ?? null)->toBe($gericht->id);
});
