<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S1): Rauchtest vor dem Zerlegen von PlanningCascadeService. Jedes MCP-Tool, das PlanningCascadeService nutzt, läuft
 * einmal mit gültigen Eingaben und liefert success — so fällt ein beim Schnitt verlorener Fassaden-Delegator sofort auf.
 * Die KI-Generierung läuft über Queue-Jobs: Queue::fake() hält sie an, geprüft wird nur Start/Status/Tor, nie ein echter KI-Lauf.
 * Kein Fachtest: Inhalte prüfen PlanningCascadeTest, PlanungKaskade*McpTest und die *PlanFromBriefMcpTest.
 */

/** Provider-Stub für das Gerüst-JSON der …PlanFromBrief-Tools (eindeutiger Name gegen Parallel-Redeclare). */
function bindeRauchKaskadeGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string { return 'rauch-kaskade-stub'; }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array { return ['stub']; }

        public function getDefaultModel(): string { return 'stub'; }

        public function isAvailable(): bool { return true; }
    });
}

beforeEach(function () {
    Queue::fake();   // Start/Freigabe/Anreichern dispatchen Generator-Jobs — nicht real laufen lassen
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Planung');
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->rauch = function (string $tool, array $args) {
        $r = app($tool)->execute($args, $this->ctx);
        expect($r->success)->toBeTrue(class_basename($tool).': '.($r->error ?? json_encode($r->errorCode)));

        return $r;
    };
});

it('Kaskade-Tools: Start legt einen Lauf an, Status, Letzte und KI-Aufträge lesen ihn', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Rauch-Menü', 'brief' => 'Ein Teller.']);

    $start = $r(Tools\PlanungKaskadeStartPostTool::class, ['session_id' => (int) $session->id, 'scope' => 'gericht']);
    $runId = (int) $start->data['lauf']['id'];
    expect($runId)->toBeGreaterThan(0);

    $r(Tools\PlanungKaskadeStatusGetTool::class, ['run_id' => $runId]);
    $r(Tools\PlanungKaskadeLetzteGetTool::class, []);
    $r(Tools\KiAuftraegeGetTool::class, ['run_id' => $runId]);
});

it('Kaskade-Tools: Weiter und Freigabe an einem Lauf mit fertigem und geplantem Schritt', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => false, 'brief' => 'x']);
    $fertig = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'label' => 'Sud', 'sort' => 1]);
    $geplant = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'geplant', 'label' => 'Jus', 'sort' => 2]);

    $r(Tools\PlanungKaskadeWeiterTool::class, ['run_id' => (int) $run->id]);                // Tor: fertiger Entwurf
    $r(Tools\PlanungKaskadeFreigabePostTool::class, ['step_id' => (int) $fertig->id]);      // done → freigegeben
    $r(Tools\PlanungKaskadeFreigabePostTool::class, ['step_id' => (int) $geplant->id]);     // geplant → erzeugt (Job gefakt)
});

it('Kaskade-Tools: Sync gleicht die Kapitel eines gestuften Foodbook-Laufs ab', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Rauch-Buch');
    $frames = app(PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'foodbook', (int) $fb->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Empfang', 'slot_type' => 'kapitel']);
    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'foodbook', 'owner_id' => (int) $fb->id]);

    ($this->rauch)(Tools\PlanungKaskadeSyncPostTool::class, ['run_id' => (int) $run->id]);
});

it('Kaskade-Tools: Rezept anreichern startet den Anreicherungs-Lauf', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Fond: Rauch');

    ($this->rauch)(Tools\RecipesEnrichTool::class, ['recipe_id' => (int) $rezept->id]);
});

it('Kaskade-Tools: …PlanFromBrief legt Hülle, Gerüst und Kaskade an (Foodbook, Speisekarte, Speiseplan, Angebot, Format)', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    bindeRauchKaskadeGeruestStub(['name' => 'Rauch', 'slots' => [['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1]]]);

    $r(Tools\FoodbookPlanFromBriefTool::class, ['brief' => 'Sommer-Gala für 60 Gäste, gehoben.', 'label' => 'Rauch-Buch']);
    $r(Tools\SpeisekartePlanFromBriefTool::class, ['brief' => 'Bistro, regional, 8 Gerichte.', 'label' => 'Rauch-Karte']);
    $r(Tools\SpeiseplanPlanFromBriefTool::class, ['brief' => 'Betriebsrestaurant, 2 Wochen, ausgewogen.', 'label' => 'Rauch-Plan']);
    $r(Tools\AngebotPlanFromBriefTool::class, ['brief' => 'Sommerfest für 50 Gäste, 3-Gänge-Menü.', 'label' => 'Rauch-Angebot']);
    $r(Tools\FormatPlanFromBriefTool::class, ['brief' => 'Flying-Fingerfood-Konzept für Empfänge.', 'label' => 'Rauch-Format']);
});

it('Alle Tools, die PlanningCascadeService nutzen, sind hier abgedeckt', function () {
    $abgedeckt = collect(file(__FILE__))->flatMap(fn ($z) => preg_match_all('/Tools\\\\(\w+Tool)::class/', $z, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    $nutzen = collect(glob(dirname(__DIR__, 2).'/src/Tools/*Tool.php'))
        ->filter(fn ($f) => preg_match('/\bPlanningCascadeService\b/', (string) file_get_contents($f)))
        ->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();

    expect(array_values(array_diff($nutzen, $abgedeckt)))->toBe([]);
});
