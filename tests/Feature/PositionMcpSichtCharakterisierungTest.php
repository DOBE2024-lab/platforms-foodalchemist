<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\Fassung\FassungService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Plattform\AboService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ConceptSlotsPostTool;
use Platform\FoodAlchemist\Tools\FoodbookBlocksPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.6 PR5: MCP und Dienst prüfen die Sicht auf das Gericht gleich — das abonnierte Gericht (Abo-Fassung) geht überall
 * durch, ein fremdes wird überall mit NOT_FOUND abgelehnt. Wächter dafür, dass die Tools ihre Vorab-Prüfung an den Dienst
 * abgeben können, ohne dass sich Ergebnis oder Fehlercode ändern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    $kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->koch = $this->makeUser($this->broich, 'Broich-Koch', 'admin');

    $gericht = $this->makeRecipe($this->master, 'Master-Roulade', ['is_sales_recipe' => true, 'ek_total_eur' => 30.0, 'sales_unit_count' => 10]);
    $inhalte = app(InhaltsFreigabeService::class);
    $sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $kurator);
    $inhalte->sammlungHinzu($this->master, $sammlung, 'recipe', [$gericht->id], $kurator);
    app(AboService::class)->abonnieren($this->master, $this->broich, $sammlung, $kurator);
    app(FassungService::class)->veroeffentlichen($this->master, $gericht->id, 'Erst', $kurator);
    $this->fassung = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $gericht->id)->firstOrFail();

    $this->fremd = $this->makeRecipe($this->childB, 'Fremdes Gericht', ['is_sales_recipe' => true, 'ek_total_eur' => 10.0, 'sales_unit_count' => 1]);
    $this->actingAs($this->koch);
    $this->konzept = app(ConceptService::class)->create($this->broich, ['name' => 'Broich-Menü']);
});

it('Konzept-Slot: abonniertes Gericht überall angenommen, fremdes per Dienst und ConceptSlotsPost abgelehnt (NOT_FOUND)', function () {
    $ctx = new ToolContext($this->koch, $this->broich);
    $slot = app(ConceptService::class)->addSlot($this->broich, $this->konzept->id, ['role' => 'Hauptgang']);

    expect(app(ConceptService::class)->fillSlot($this->broich, $slot->id, ['sales_recipe_id' => $this->fassung->id])->sales_recipe_id)->toBe($this->fassung->id)
        ->and(app(ConceptSlotsPostTool::class)->execute(['concept_id' => $this->konzept->id, 'sales_recipe_id' => $this->fassung->id], $ctx)->success)->toBeTrue()
        ->and(fn () => app(ConceptService::class)->fillSlot($this->broich, $slot->id, ['sales_recipe_id' => $this->fremd->id]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $res = app(ConceptSlotsPostTool::class)->execute(['concept_id' => $this->konzept->id, 'sales_recipe_id' => $this->fremd->id], $ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND')
        ->and($this->konzept->slots()->count())->toBe(2);   // keine leere Position übrig
});

it('Konzept-Slot PUT: abonniertes Gericht angenommen, fremdes abgelehnt (NOT_FOUND)', function () {
    $ctx = new ToolContext($this->koch, $this->broich);
    $slot = app(ConceptService::class)->addSlot($this->broich, $this->konzept->id, ['role' => 'Hauptgang']);

    expect(app(\Platform\FoodAlchemist\Tools\ConceptSlotsPutTool::class)->execute(['slot_id' => $slot->id, 'felder' => ['sales_recipe_id' => $this->fassung->id]], $ctx)->success)->toBeTrue();
    $res = app(\Platform\FoodAlchemist\Tools\ConceptSlotsPutTool::class)->execute(['slot_id' => $slot->id, 'felder' => ['sales_recipe_id' => $this->fremd->id, 'role' => 'Dessert']], $ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND')->and($slot->fresh()->sales_recipe_id)->toBe($this->fassung->id)
        ->and($slot->fresh()->role)->toBe('Hauptgang');   // abgelehnt heißt: nichts geschrieben, auch nicht die Rolle
});

it('Foodbook-Block: abonniertes Gericht überall angenommen, fremdes per Dienst und FoodbookBlocksPut abgelehnt (NOT_FOUND)', function () {
    $ctx = new ToolContext($this->koch, $this->broich);
    $kap = $this->makeChapter($this->makeFoodbook($this->broich, 'Broich-Foodbook', ['status' => 'entwurf']));
    $block = app(FoodbookService::class)->addBlock($this->broich, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->fassung->id]);

    expect(app(FoodbookBlocksPutTool::class)->execute(['block_id' => $block->id, 'felder' => ['sales_recipe_id' => $this->fassung->id]], $ctx)->success)->toBeTrue();
    $res = app(FoodbookBlocksPutTool::class)->execute(['block_id' => $block->id, 'felder' => ['sales_recipe_id' => $this->fremd->id]], $ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND')->and($block->fresh()->sales_recipe_id)->toBe($this->fassung->id);
});
