<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Befund 17.11. (Sandbox Team 4, Pilot): recipes.POST mit Zubereitung legte 3 Schritte an, recipes.ENRICH im externen Weg
 * beantwortete recipe.steps — danach standen 6 Schritte da. Entscheid Dominique: die KI-Übernahme ERSETZT die Schritte derselben
 * Ebene, die andere Ebene bleibt stehen. Und der Prompt muss den Bestand zeigen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);

    $this->neueSchritte = [['phase' => 'Garen', 'text' => 'Neu eins.'], ['phase' => 'Garen', 'text' => 'Neu zwei.'], ['phase' => null, 'text' => 'Neu drei.']];
    $this->stepPrompts = [];

    // Antwort-Quelle: recipe.steps bekommt drei neue Schritte, alles andere eine neutrale Antwort
    $this->antwort = function (string $feature, array $messages): array {
        if ($feature === 'recipe.steps') {
            $this->stepPrompts[] = json_encode($messages, JSON_UNESCAPED_UNICODE);

            return ['steps' => $this->neueSchritte];
        }

        return ['description' => 'Kräftiger Fond.', 'text' => 'x'];
    };
    $this->beantworteAlle = function (int $maxRunden = 20): void {
        for ($runde = 0; $runde < $maxRunden; $runde++) {
            $offen = $this->registry->get('foodalchemist.ki_auftraege.GET')->execute([], $this->mcp)->data['auftraege'];
            if ($offen === []) {
                return;
            }
            $antworten = [];
            foreach ($offen as $a) {
                $antworten[] = ['auftrag_id' => $a['auftrag_id'], 'werte' => ['werte' => ($this->antwort)($a['feature'], $a['messages']), 'confidence' => 0.8, 'reasoning' => 'stub']];
            }
            $ok = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => $antworten], $this->mcp);
            expect($ok->success)->toBeTrue($ok->error ?? '');
        }
        throw new \RuntimeException("nicht fertig nach {$maxRunden} Runden");
    };

    // Rezept wie im Befund: per recipes.POST mit Klartext-Zubereitung → 3 Produktions-Schritte
    $res = $this->registry->get('foodalchemist.recipes.POST')->execute([
        'name' => 'Fond: Kalb', 'preparation' => "1. Alt eins.\n2. Alt zwei.\n3. Alt drei.",
    ], $this->mcp);
    expect($res->success)->toBeTrue($res->error ?? '');
    $this->recipe = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::findOrFail((int) $res->data['recipe']['id']);
    // die andere Ebene: zwei Anrichte-Schritte, die die Übernahme nicht anfassen darf
    app(RecipeStepService::class)->sync($this->recipe, [['text' => 'Anrichten eins.'], ['text' => 'Anrichten zwei.']], FoodAlchemistRecipeStep::EBENE_ANRICHTEN);

    $this->texte = fn (string $ebene) => FoodAlchemistRecipeStep::where('recipe_id', $this->recipe->id)->ebene($ebene)
        ->orderBy('position')->orderBy('id')->get(['position', 'text'])->map(fn ($s) => $s->position . ' ' . $s->text)->all();

    expect(($this->texte)('produktion'))->toBe(['1 Alt eins.', '2 Alt zwei.', '3 Alt drei.']);
});

it('externer Weg: recipes.ENRICH + Antwort ersetzt die Produktions-Schritte, Anrichten bleibt, Prompt zeigt den Bestand', function () {
    $wirft = new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Plattform-Provider darf im externen Weg nicht gerufen werden');
        }
    };
    app()->instance(LLMProviderContract::class, $wirft);

    $res = $this->registry->get('foodalchemist.recipes.ENRICH')->execute(['recipe_id' => $this->recipe->id, 'complete_coverage' => true], $this->mcp);
    expect($res->success)->toBeTrue($res->error ?? '')->and($res->data['extern'])->toBeTrue();
    expect(DB::table('foodalchemist_ki_auftraege')->where('feature', 'recipe.steps')->exists())->toBeTrue();

    ($this->beantworteAlle)();

    expect(($this->texte)('produktion'))->toBe(['1 Neu eins.', '2 Neu zwei.', '3 Neu drei.'])
        ->and(($this->texte)('anrichten'))->toBe(['1 Anrichten eins.', '2 Anrichten zwei.'])
        ->and($this->stepPrompts)->not->toBeEmpty();
    foreach ($this->stepPrompts as $p) {
        expect($p)->toContain('Alt eins.')->toContain('Alt drei.')->not->toContain('Anrichten eins.');
    }
});

it('Plattformweg: die Anreicherung (One-Shot) ersetzt die Produktions-Schritte, Anrichten bleibt, Prompt zeigt den Bestand der Ebene', function () {
    // Plattform-Provider: recipe.steps bekommt drei neue Schritte (Erkennung an der Kontext-Form), sonst Kontext-Echo
    $provider = new class extends FakeAiProvider
    {
        public array $stepPrompts = [];

        public array $antwort = [];

        public function chat(array $messages, array $options = []): array
        {
            $roh = json_encode($messages, JSON_UNESCAPED_UNICODE);
            if (str_contains($roh, 'schritte_bestand')) {
                $this->stepPrompts[] = $roh;

                return ['content' => json_encode(['werte' => ['steps' => $this->antwort], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake-deterministic-1', 'tool_calls' => null];
            }

            return parent::chat($messages, $options);
        }
    };
    $provider->antwort = $this->neueSchritte;
    app()->instance(LLMProviderContract::class, $provider);

    $ergebnis = app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class)
        ->anreichern($this->rootTeam, $this->recipe->fresh(), completeCoverage: true);

    expect($ergebnis['coverage']['steps']['status'] ?? null)->toBe('aktualisiert', (string) ($ergebnis['coverage']['steps']['fehler'] ?? ''))
        ->and(($this->texte)('produktion'))->toBe(['1 Neu eins.', '2 Neu zwei.', '3 Neu drei.'])
        ->and(($this->texte)('anrichten'))->toBe(['1 Anrichten eins.', '2 Anrichten zwei.'])
        ->and($this->recipe->fresh()->preparation)->toContain('Neu eins.')->not->toContain('Alt eins.')
        ->and($provider->stepPrompts)->not->toBeEmpty();
    foreach ($provider->stepPrompts as $p) {
        expect($p)->toContain('Alt eins.')->not->toContain('Anrichten eins.');
    }
});

it('Schritt-Editor: KI übernehmen ersetzt die Ebene; eine id aus dem Vorschlag dockt nicht an Bestandszeilen an', function () {
    $alt = FoodAlchemistRecipeStep::where('recipe_id', $this->recipe->id)->ebene('produktion')->orderBy('position')->pluck('id')->all();

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\StepEditor::class, ['recipeId' => $this->recipe->id])
        ->set('kiVorschlag', ['steps' => [['id' => $alt[0], 'phase' => null, 'text' => 'Neu eins.'], ['phase' => null, 'text' => 'Neu zwei.']], 'confidence' => 0.8, 'reasoning' => 'x'])
        ->call('kiUebernehmen')
        ->assertSet('fehler', null);

    $neu = FoodAlchemistRecipeStep::where('recipe_id', $this->recipe->id)->ebene('produktion')->pluck('id')->all();
    expect(($this->texte)('produktion'))->toBe(['1 Neu eins.', '2 Neu zwei.'])
        ->and(array_intersect($neu, $alt))->toBe([])                       // ersetzt, keine Bestandszeile umgeschrieben
        ->and(($this->texte)('anrichten'))->toBe(['1 Anrichten eins.', '2 Anrichten zwei.'])
        ->and($this->recipe->fresh()->preparation_source)->toBe('ki');
});

it('ersetze: leerer Vorschlag lässt den Bestand stehen, Positionen laufen 1..n', function () {
    $svc = app(RecipeStepService::class);

    expect($svc->ersetze($this->recipe, [['text' => '  '], ['phase' => 'X']]))->toBe(0)
        ->and(($this->texte)('produktion'))->toBe(['1 Alt eins.', '2 Alt zwei.', '3 Alt drei.']);

    expect($svc->ersetze($this->recipe, [['text' => 'A'], ['text' => ''], ['text' => 'B']]))->toBe(2)
        ->and(($this->texte)('produktion'))->toBe(['1 A', '2 B'])
        ->and(($this->texte)('anrichten'))->toBe(['1 Anrichten eins.', '2 Anrichten zwei.']);
});
