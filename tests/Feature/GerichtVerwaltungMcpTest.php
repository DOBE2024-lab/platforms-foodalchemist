<?php

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Verwaltung im MCP-Lockstep: recipes.REPLACE mit preview/bereiche/auch_aktive_foodbooks für Gerichte (derselbe
 * Dienst wie das Web-Panel), verkaufsrezepte.DELETE mit derselben Sperre (aktive Verwendungen sperren, historische nicht).
 * Ohne die neuen Argumente bleibt recipes.REPLACE wie bisher (nur Komponenten-Zeilen).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $this->seedTeamHierarchy();
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new ToolContext($user, $this->rootTeam);
    $this->run = fn (string $name, array $a) => app(ToolRegistry::class)->get($name)->execute($a, $kontext);
    $this->alt = $this->makeRecipe($this->rootTeam, 'Rinderroulade', ['is_sales_recipe' => true]);
    $this->neu = $this->makeRecipe($this->rootTeam, 'Kalbsbäckchen', ['is_sales_recipe' => true]);
    $this->slot = $this->makeConceptSlot($this->makeConcept($this->rootTeam, 'Herbstmenü'), ['sales_recipe_id' => $this->alt->id]);
    $this->aktivesFb = $this->makeFoodbookBlock($this->makeChapter($this->makeFoodbook($this->rootTeam, 'Foodbook 2026')), ['sales_recipe_id' => $this->alt->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('preview braucht kein confirm und schreibt nichts', function () {
    $r = ($this->run)('foodalchemist.recipes.REPLACE', ['from_id' => $this->alt->id, 'to_id' => $this->neu->id, 'preview' => true]);

    expect($r->success)->toBeTrue()
        ->and($r->data['bereiche']['konzepte']['anzahl'])->toBe(1)
        ->and($r->data['haken_noetig'])->toBeTrue()
        ->and((int) $this->slot->fresh()->sales_recipe_id)->toBe($this->alt->id);
});

it('bereiche ohne confirm wird abgewiesen; mit confirm getauscht, danach löscht verkaufsrezepte.DELETE', function () {
    $ohne = ($this->run)('foodalchemist.recipes.REPLACE', ['from_id' => $this->alt->id, 'to_id' => $this->neu->id, 'bereiche' => ['konzepte']]);
    expect($ohne->success)->toBeFalse();

    $gesperrt = ($this->run)('foodalchemist.verkaufsrezepte.DELETE', ['id' => $this->alt->id, 'confirm' => true]);
    expect($gesperrt->success)->toBeFalse();

    $r = ($this->run)('foodalchemist.recipes.REPLACE', ['from_id' => $this->alt->id, 'to_id' => $this->neu->id,
        'bereiche' => [], 'auch_aktive_foodbooks' => true, 'confirm' => true]);
    expect($r->success)->toBeTrue()
        ->and($r->data['getauscht']['konzepte'])->toBe(1)
        ->and($r->data['getauscht']['foodbooks'])->toBe(1)
        ->and($r->data['loeschen']['frei'])->toBeTrue();

    $d = ($this->run)('foodalchemist.verkaufsrezepte.DELETE', ['id' => $this->alt->id, 'confirm' => true]);
    expect($d->success)->toBeTrue()
        ->and(FoodAlchemistRecipe::find($this->alt->id))->toBeNull()
        ->and($this->aktivesFb->fresh()->dish->name)->toBe('Kalbsbäckchen');
});

it('bereiche/preview für ein Basisrezept werden abgewiesen', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $r = ($this->run)('foodalchemist.recipes.REPLACE', ['from_id' => $basis->id, 'to_id' => $this->neu->id, 'preview' => true]);

    expect($r->success)->toBeFalse();
});
