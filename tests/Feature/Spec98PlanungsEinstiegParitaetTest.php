<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Planung\AusBrief;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\PlanungsEinstiegService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 1.3d — „X aus Brief" ist EIN Use-Case (PlanungsEinstiegService) für Leitstelle und MCP. Parität: dieselbe
 * Eingabe ergibt in Oberfläche und Tool denselben Lauf (Scope, Stufung, Schritte), dieselbe Session (Brief, Modus,
 * Regler) und dieselbe Hülle — nur die Herkunft (`created_via`) unterscheidet sich.
 */
function bindeParitaetGeruestStub(): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'paritaet-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            $werte = [
                'name' => 'Paritaet',
                'slots' => [
                    ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 2],
                    ['label' => 'Hauptgang', 'slot_type' => 'gang', 'is_pflicht' => true],
                ],
                'branding' => ['claim' => 'Gleich ist gleich'],
            ];

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

/** Vergleichbares Abbild eines Laufs samt Session und Hülle (ohne IDs/Zeiten). */
function einstiegAbbild(FoodAlchemistCascadeRun $run): array
{
    $session = $run->session ?? \Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession::find($run->planning_session_id);
    $schritte = $run->steps()->get()->groupBy(fn ($s) => $s->kind . ':' . $s->status)->map->count()->sortKeys()->all();

    return [
        'scope' => $run->scope,
        'owner_type' => $run->source_owner_type,
        'staged' => (bool) $run->staged,
        'schritte' => $schritte,
        'brief' => $session->brief,
        'modus' => $session->creative_mode,
        'regler' => $session->generation_params,
        'titel' => preg_replace('/#\d+/', '#', (string) $session->title),
        'via' => preg_replace('/^(leitstelle|mcp)_/', '', (string) $session->created_via),
    ];
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    Queue::fake();
    bindeParitaetGeruestStub();
});

dataset('ausgaben', [
    'foodbook' => ['foodbookAusBrief', 'fb', 'foodbook', 'foodbook'],
    'speisekarte' => ['speisekarteAusBrief', 'sk', 'speisekarte', 'speisekarte'],
    'speiseplan' => ['speiseplanAusBrief', 'sp', 'speiseplan', 'speiseplan'],
    'angebot' => ['angebotAusBrief', 'offer', 'angebot', 'offer'],
    'format' => ['formatAusBrief', 'fmt', 'format', 'format'],
]);

it('Oberfläche und Tool ergeben denselben Lauf, dieselbe Session und dieselbe Hülle', function (string $methode, string $praefix, string $tool, string $ownerType) {
    $brief = 'Sommerfest, 80 Gäste, gehobenes Niveau, 4 Wochen Zyklus.';

    Livewire::test(AusBrief::class)   // Spec 85 C1 PR-5: die Ausgabe-Formulare liegen im Kind
        ->set($praefix . 'Titel', 'Paritaet')
        ->set($praefix . 'Brief', $brief)
        ->call($methode)
        ->assertSet($praefix . 'Meldung', null);
    $ui = FoodAlchemistCascadeRun::where('source_owner_type', $ownerType)->latest('id')->first();

    $res = app(ToolRegistry::class)->get("foodalchemist.{$tool}.PLAN_FROM_BRIEF")
        ->execute(['brief' => $brief, 'label' => 'Paritaet'], new ToolContext($this->user, $this->rootTeam));
    expect($res->success)->toBeTrue();
    $mcp = FoodAlchemistCascadeRun::where('source_owner_type', $ownerType)->latest('id')->first();

    expect($mcp->id)->not->toBe($ui->id)
        ->and(einstiegAbbild($mcp))->toBe(einstiegAbbild($ui));
    expect($ui->session?->created_via ?? \Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession::find($ui->planning_session_id)->created_via)
        ->toStartWith('leitstelle_');
})->with('ausgaben');

it('Besitz: eine geerbte Hülle plant weder die Oberfläche noch das Tool', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Master-Foodbook']);
    $kindUser = $this->makeUser($this->childA, 'Kind');
    $this->actingAs($kindUser);
    $vorher = FoodAlchemistCascadeRun::count();

    Livewire::test(AusBrief::class)
        ->set('fbOwnerId', $fb->id)
        ->set('fbBrief', 'Brief')
        ->call('foodbookAusBrief')
        ->assertSet('fbMeldung', fn ($m) => is_string($m) && str_contains($m, 'anderen Team') && str_contains($m, 'eigene Kopie'));

    $res = app(ToolRegistry::class)->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Brief', 'foodbook_id' => $fb->id], new ToolContext($kindUser, $this->childA));
    expect($res->success)->toBeFalse()
        ->and(FoodAlchemistCascadeRun::count())->toBe($vorher);
});

it('Service: leerer Brief und unbekannte Hülle werfen, bevor etwas angelegt wird', function () {
    $svc = app(PlanungsEinstiegService::class);
    expect(fn () => $svc->angebotAusBrief($this->rootTeam, '  ', ['created_via' => 'test']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->formatAusBrief($this->rootTeam, 'Brief', ['owner_id' => 999999, 'created_via' => 'test']))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\NichtGefundenException::class);
});
