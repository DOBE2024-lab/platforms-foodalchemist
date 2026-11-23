<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeService;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3e — die letzten Livewire-Schreibwege (Planung, Wissens-Browser, Voice) laufen über Services.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('PlanningSessionService::setzePlanConcept hängt den Plan an und löst ihn — nur an eigenen Sessions', function () {
    $svc = app(PlanningSessionService::class);
    $session = FoodAlchemistPlanningSession::create(['team_id' => $this->rootTeam->id, 'title' => 'Herbst', 'status' => 'divergenz']);

    $svc->setzePlanConcept($this->rootTeam, $session->id, 4711);
    expect((int) $session->refresh()->plan_concept_id)->toBe(4711);
    $svc->setzePlanConcept($this->rootTeam, $session->id, null);
    expect($session->refresh()->plan_concept_id)->toBeNull();

    expect(fn () => $svc->setzePlanConcept($this->childA, $session->id, 1))->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('KnowledgeRunService::starteFuerRezept bindet nur eigene Basisrezepte an einen neuen Wissenslauf', function () {
    $svc = app(KnowledgeRunService::class);
    $eigen = $this->makeRecipe($this->rootTeam, 'Rinderfond');
    $run = $svc->starteFuerRezept($this->rootTeam, $eigen);
    expect($run)->not->toBeNull()
        ->and($eigen->refresh()->knowledge_run_id)->toBe($run->id);

    $fremd = $this->makeRecipe($this->childA, 'Kalbsjus');
    expect($svc->starteFuerRezept($this->rootTeam, $fremd))->toBeNull()
        ->and($fremd->refresh()->knowledge_run_id)->toBeNull();
});

it('KnowledgeService::aktivUmschalten schaltet eigenes Wissen um und lehnt fremdes ab', function () {
    $doc = fn (?int $teamId, string $slug) => DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'title' => $slug, 'category' => 'domain',
        'content_md' => '# ' . $slug, 'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 10,
        'active' => 1, 'source_path' => null, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $eigen = $doc($this->childA->id, 'kind-a');
    $fremd = $doc($this->childB->id, 'kind-b');
    $svc = app(KnowledgeService::class);

    expect($svc->aktivUmschalten($this->childA, $eigen))->toBeFalse()
        ->and((bool) DB::table('foodalchemist_knowledge_documents')->where('id', $eigen)->value('active'))->toBeFalse()
        ->and($svc->aktivUmschalten($this->childA, 999999))->toBeNull();
    expect(fn () => $svc->aktivUmschalten($this->childA, $fremd))->toThrow(GeerbtNurLesenException::class, 'anderen Team');
    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('id', $fremd)->value('active'))->toBeTrue();
});

it('AiGatewayService::protokolliereZeile schreibt genau eine Audit-Zeile', function () {
    $vorher = DB::table('foodalchemist_ai_call_log')->count();
    app(AiGatewayService::class)->protokolliereZeile([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'user_id' => auth()->id(),
        'feature' => 'voice.schreibaktion', 'tier' => 'D', 'prompt_hash' => hash('sha256', 'test'),
        'response_summary' => 'test — ausgeführt', 'elapsed_ms' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe($vorher + 1);
});
