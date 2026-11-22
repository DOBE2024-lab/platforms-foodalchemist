<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Livewire\Produktion\TagesplanAnleitung;
use Platform\FoodAlchemist\Livewire\Produktion\TagesplanFeedback;
use Platform\FoodAlchemist\Livewire\Produktion\TagesplanWall;
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
    $GLOBALS['tagesplanKinder'] = [
        'tagesplan-feedback' => fn (?int $offen) => Livewire::test(TagesplanFeedback::class, ['lineId' => $offen])->html(),
        'tagesplan-anleitung' => fn (?int $offen) => tagesplanAnleitung($offen)->html(),
    ];
});

/**
 * Livewire rendert ein Kind nur beim ersten Render mit; nach einer weiteren Aktion am Parent steht im Test-HTML nur die
 * leere Hülle (im Browser bleibt das Kind stehen). Solche Hüllen ersetzt das Netz durch ein frisch gerendertes Kind.
 *
 * @var array<string, \Closure(?int): string> Kind-Alias (ohne foodalchemist.produktion.) => frisches HTML zur offenen Zeile
 */
$GLOBALS['tagesplanKinder'] = [];

/** @return array<string, int> Marker => Anzahl, sortiert. */
function tagesplanMarker(string $html, ?int $zeile = null, array $kindHtml = []): array
{
    // Hüllen rekursiv ersetzen (Anleitung → darin Feedback); $kindHtml gibt für einen Alias ein bestimmtes HTML vor.
    for ($tiefe = 0; $tiefe < 3; $tiefe++) {
        $vorher = $html;
        $html = preg_replace_callback('/<(\w+) wire:id="[^"]+" wire:name="foodalchemist\.produktion\.([a-z-]+)"[^>]*><\/\1>/',
            fn ($m) => $kindHtml[$m[2]] ?? (isset($GLOBALS['tagesplanKinder'][$m[2]]) ? ($GLOBALS['tagesplanKinder'][$m[2]])($zeile) : $m[0]), $html);
        if ($html === $vorher) {
            break;
        }
    }
    preg_match_all('/\bdata-tagesplan[a-z0-9-]*/', $html, $m);
    $zaehler = array_count_values($m[0]);
    ksort($zaehler);

    return $zaehler;
}

function tagesplanAnleitung(?int $zeile): \Livewire\Features\SupportTesting\Testable
{
    $kind = Livewire::test(TagesplanAnleitung::class, ['von' => '2026-08-20', 'bis' => '2026-09-02']);

    return $zeile === null ? $kind : $kind->call('oeffneAnleitung', $zeile);
}

function tagesplanModi(object $t): array
{
    $basis = ['von' => '2026-08-20'];
    $wand = $basis + ['display' => 'wall'];
    // Seit S6 PR4 ist der Küchenmonitor ein Kind; die Hülle (Tagesplan, Wandmodus) zeigt es beim ersten Render voll.
    $kind = fn () => Livewire::test(TagesplanWall::class, ['von' => '2026-08-20', 'bis' => '2026-09-02']);
    $grund = array_key_first((array) config('foodalchemist.feedback_gruende', [])) ?? 'zu_salzig';

    return [
        'dashboard' => fn () => Livewire::test(Tagesplan::class, $basis),
        'editor' => fn () => Livewire::test(Tagesplan::class, $basis)->set('modus', 'editor'),
        'wall_lanes' => fn () => Livewire::test(Tagesplan::class, $wand),
        'wall_mise' => fn () => $kind()->call('wallAnsichtSetzen', 'mise'),
        'wall_basis' => fn () => $kind()->call('wallGruppenFilterSetzen', 'basis'),
        // Seit S6 PR3 ist die Anleitung ein Kind: der Tagesplan öffnet sie per Event, das Netz setzt das geöffnete Kind ein.
        'wall_anleitung' => fn () => tagesplanMarker($kind()
            ->call('oeffneAnleitung', $t->zeile->id)->assertDispatched('tagesplan-anleitung-oeffnen')->html(), $t->zeile->id),
        'wall_anleitung_gestartet' => fn () => tagesplanMarker($kind()
            ->call('oeffneAnleitung', $t->zeile->id)->html(), $t->zeile->id,
            ['tagesplan-anleitung' => tagesplanAnleitung($t->zeile->id)->call('anleitungStarten')->call('anleitungStepUmschalten', 0)->html()]),
        // Seit S6 PR2 ist der Bogen ein Kind: Seite = Overlay mit frischem Bogen, dessen Marker gegen den gespeicherten getauscht.
        'wall_feedback_gespeichert' => function () use ($kind, $t, $grund) {
            $seite = tagesplanMarker($kind()->call('oeffneAnleitung', $t->zeile->id)->html(), $t->zeile->id);
            $frisch = tagesplanMarker(Livewire::test(TagesplanFeedback::class, ['lineId' => $t->zeile->id])->html());
            $gespeichert = tagesplanMarker(Livewire::test(TagesplanFeedback::class, ['lineId' => $t->zeile->id])
                ->call('feedbackGrundUmschalten', $grund)->call('feedbackSetzen', 'geschmack', 4)->call('feedbackSpeichern')->html());
            foreach ($frisch as $m => $n) {
                $seite[$m] -= $n;
            }
            foreach ($gespeichert as $m => $n) {
                $seite[$m] = ($seite[$m] ?? 0) + $n;
            }
            $seite = array_filter($seite);
            ksort($seite);

            return $seite;
        },
        'wall_gericht' => function () use ($kind) {
            $w = $kind();
            $key = $w->viewData('wallPostenGruppen')->flatten(1)->first(fn ($g) => str_contains((string) $g->key, 'Rinderbraten'))?->key;
            expect($key)->not->toBeNull();

            return $w->call('oeffneGericht', $key);
        },
    ];
}

it('jeder Modus rendert dieselben data-tagesplan-Marker wie vor dem Schnitt', function () {
    $ist = [];
    foreach (tagesplanModi($this) as $modus => $baue) {
        $r = $baue();
        $ist[$modus] = is_array($r) ? $r : tagesplanMarker($r->assertOk()->html());
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
