<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.1d (Befund aus 2.3d): `RecipeService::create/update` übernahmen `equipment_ids` ohne Sichtprüfung. Jetzt prüft der
 * Service zentral über {@see \Platform\FoodAlchemist\Support\Verweisziel}: global und aus der Master-Kette ja, fremdes Team nein.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $this->global = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'topf', 'name' => 'Topf']);
    $this->master = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);
    $this->fremd = FoodAlchemistVocabKochequipment::create(['team_id' => $this->childB->id, 'slug' => 'pacojet', 'name' => 'Pacojet (Kind B)']);
    $this->svc = app(RecipeService::class);
});

it('create: globales und geerbtes Gerät ja (source manual), fremdes Team nein — und dann kein Rezept', function () {
    $r = $this->svc->create($this->childA, ['name' => 'Kürbissuppe', 'equipment_ids' => [$this->global->id, (string) $this->master->id]]);
    expect($r->equipment()->pluck('equipment_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([$this->global->id, $this->master->id])
        ->and($r->equipment()->first()->pivot->source)->toBe('manual');

    expect(fn () => $this->svc->create($this->childA, ['name' => 'Wildjus', 'equipment_ids' => [$this->global->id, $this->fremd->id]]))
        ->toThrow(RuntimeException::class, 'Gerät #' . $this->fremd->id);
    expect(FoodAlchemistRecipe::where('name', 'Wildjus')->exists())->toBeFalse();
});

it('update: fremdes Gerät nein; ein schon verknüpfter Altbestand blockiert keine andere Änderung', function () {
    $r = $this->svc->create($this->childA, ['name' => 'Kürbissuppe', 'equipment_ids' => [$this->global->id]]);

    expect(fn () => $this->svc->update($this->childA, $r->id, ['equipment_ids' => [$this->global->id, $this->fremd->id]]))
        ->toThrow(RuntimeException::class, 'Gerät #' . $this->fremd->id);
    expect($r->equipment()->pluck('equipment_id')->map(fn ($id) => (int) $id)->all())->toBe([$this->global->id]);

    // Altbestand (vor der Prüfung gespeichert): bleibt beim Speichern erhalten, ohne dass das Speichern scheitert
    DB::table('foodalchemist_recipe_equipment')->insert(['recipe_id' => $r->id, 'equipment_id' => $this->fremd->id, 'source' => 'manual']);
    $this->svc->update($this->childA, $r->id, ['name' => 'Kürbiscremesuppe', 'equipment_ids' => [$this->global->id, $this->fremd->id, $this->master->id]]);
    expect(FoodAlchemistRecipe::find($r->id)->name)->toBe('Kürbiscremesuppe')
        ->and($r->equipment()->count())->toBe(3);
});
