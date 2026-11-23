<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S6): Render-Netz für das Zerlegen von Produktion/Tagesplan „nach Modus“. Je Modus werden die gerenderten
 * `data-tagesplan*`-Marker samt Anzahl gegen eine Golden-Datei geprüft. Jeder Schnitt (Abfrage-Service, Kinder Anleitung,
 * Feedback, Wall) muss dieselben Marker liefern, sonst ist ein Blade-Teil verloren gegangen oder doppelt.
 *
 * Golden neu schreiben (nur bei gewollter Änderung, im PR begründen): TAGESPLAN_GOLDEN_SCHREIBEN=1.
 */
const TAGESPLAN_GOLDEN = __DIR__.'/../Fixtures/tagesplan_modus_marker.json';

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));
    $svc = app(ProductionOrderService::class);
    $mk = fn (string $key, string $name, bool $vk) => FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
        'status' => 'approved', 'is_sales_recipe' => $vk, 'yield_kg' => 2.0, 'work_time_min' => 60,
        'preparation' => "1. Knochen rösten\n2. Ansetzen\n3. Passieren"]);
    $fond = $mk('fond', 'Brauner Fond', false);
    $braten = $mk('braten', '[HG] Rinderbraten', true);
    $order = $svc->saveNew($this->rootTeam, '2026-08-20', 'Render-Tagesplan', [
        ['source_ref' => 'r:fond', 'recipe_id' => $fond->id, 'amount_kg' => 6.0],
        ['source_ref' => 'r:braten', 'recipe_id' => $braten->id, 'amount_kg' => 4.0],
    ]);
    $svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::InProgress);
    $this->zeile = Line::where('production_order_id', $order->id)->where('recipe_id', $fond->id)->first();
});

/** @return array<string, int> Marker => Anzahl, sortiert. */
function tagesplanMarker(string $html): array
{
    preg_match_all('/\bdata-tagesplan[a-z0-9-]*/', $html, $m);
    $zaehler = array_count_values($m[0]);
    ksort($zaehler);

    return $zaehler;
}

function tagesplanModi(object $t): array
{
    $basis = ['von' => '2026-08-20'];
    $wand = $basis + ['display' => 'wall'];
    $grund = array_key_first((array) config('foodalchemist.feedback_gruende', [])) ?? 'zu_salzig';

    return [
        'dashboard' => fn () => Livewire::test(Tagesplan::class, $basis),
        'editor' => fn () => Livewire::test(Tagesplan::class, $basis)->set('modus', 'editor'),
        'wall_lanes' => fn () => Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor'),
        'wall_mise' => fn () => Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor')->call('wallAnsichtSetzen', 'mise'),
        'wall_basis' => fn () => Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor')->call('wallGruppenFilterSetzen', 'basis'),
        'wall_anleitung' => fn () => Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor')->call('oeffneAnleitung', $t->zeile->id),
        'wall_anleitung_gestartet' => fn () => Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor')
            ->call('oeffneAnleitung', $t->zeile->id)->call('anleitungStarten')->call('anleitungStepUmschalten', 0),
        'wall_feedback_gespeichert' => fn () => Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor')
            ->call('oeffneAnleitung', $t->zeile->id)->call('feedbackGrundUmschalten', $grund)->call('feedbackSetzen', 'geschmack', 4)
            ->call('feedbackSpeichern'),
        'wall_gericht' => function () use ($wand) {
            $w = Livewire::test(Tagesplan::class, $wand)->set('modus', 'editor');
            $key = $w->viewData('wallPostenGruppen')->flatten(1)->first(fn ($g) => str_contains((string) $g->key, 'Rinderbraten'))?->key;
            expect($key)->not->toBeNull();

            return $w->call('oeffneGericht', $key);
        },
    ];
}

it('jeder Modus rendert dieselben data-tagesplan-Marker wie vor dem Schnitt', function () {
    $ist = [];
    foreach (tagesplanModi($this) as $modus => $baue) {
        $ist[$modus] = tagesplanMarker($baue()->assertOk()->html());
    }

    if (getenv('TAGESPLAN_GOLDEN_SCHREIBEN')) {
        file_put_contents(TAGESPLAN_GOLDEN, json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->markTestSkipped('Golden neu geschrieben: '.TAGESPLAN_GOLDEN);
    }

    expect(file_exists(TAGESPLAN_GOLDEN))->toBeTrue();
    $soll = json_decode((string) file_get_contents(TAGESPLAN_GOLDEN), true);
    foreach ($soll as $modus => $marker) {
        expect($ist[$modus] ?? null)->toBe($marker, "Modus {$modus}");
    }
    expect(array_keys($ist))->toBe(array_keys($soll));
});

it('das Golden deckt die Modus-Teile ab (Wall, Mise, Anleitung, Feedback, Gericht)', function () {
    $soll = json_decode((string) file_get_contents(TAGESPLAN_GOLDEN), true);
    $nur = fn (string $a, string $b) => array_keys(array_diff_key($soll[$a], $soll[$b]));

    // Dashboard/Editor und Anleitung offen/gestartet tragen dieselben Marker (Unterschied im Inhalt, nicht in den Markern).
    expect($nur('wall_lanes', 'dashboard'))->not->toBe([])
        ->and($nur('wall_mise', 'wall_lanes'))->not->toBe([])
        ->and($nur('wall_anleitung', 'wall_lanes'))->not->toBe([])
        ->and($nur('wall_gericht', 'wall_lanes'))->not->toBe([])
        ->and($soll['wall_anleitung'])->toHaveKey('data-tagesplan-wall-feedback')
        ->and($soll['wall_feedback_gespeichert'])->toHaveKey('data-tagesplan-wall-feedback-ok')
        ->and($soll['dashboard'])->not->toHaveKey('data-tagesplan-wall');
});
