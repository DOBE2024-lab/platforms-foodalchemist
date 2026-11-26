<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpStamm\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Degenerierte gp_keys (Kurator/Dominique 20.11.2026): „Tomate: trocken, geschwefelt“ und „Tomate: konserviert, Pulpe / (Bio)“ trugen beide
 * „tomate||“ — der Schlüssel kannte weder Zustand noch Pflichtangabe noch Bio. Jetzt: nur im degenerierten Fall ein Merkmal-Slot daraus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küche'));
    $this->n = app(GpNamingService::class);
});

it('★ degenerierter Schlüssel bekommt Zustand, Pflichtangabe und Bio — jeder andere Schlüssel bleibt exakt wie bisher', function () {
    expect($this->n->gpKeyAusFeldern('tomate', ['condition' => 'trocken', 'pflichtangabe' => 'geschwefelt']))->toBe('tomate|||m-trocken_geschwefelt')
        ->and($this->n->gpKeyAusFeldern('tomate', ['condition' => 'konserviert', 'pflichtangabe' => 'Pulpe', 'bio' => true]))->toBe('tomate|||m-konserviert_pulpe_bio')
        ->and($this->n->gpKeyAusFeldern('tomate', ['condition' => 'TK']))->toBe('tomate|||m-tk')
        ->and($this->n->gpKeyAusFeldern('golden_syrup', []))->toBe('golden_syrup||')   // nichts zu unterscheiden: bleibt
        ->and($this->n->gpKeyAusFeldern('sojasauce', ['flavor' => 'süß', 'condition' => 'konserviert']))->toBe('sojasauce_suess|||m-konserviert')   // Format-Regel: auch mit Geschmack im 1. Segment gleich wie der Bestandslauf
        ->and($this->n->gpKeyAusFeldern('moehre', ['processing' => 'Scheiben 4 mm', 'condition' => 'frisch']))->toBe('moehre|scheiben_4_mm|')   // nicht degeneriert: unverändert
        ->and($this->n->gpKeyAusFeldern('tomate', ['condition' => 'frisch', 'portion' => '100 g']))->toBe('tomate|||m-frisch_100_g')
        ->and(GpNamingService::istDegeneriert('tomate||'))->toBeTrue()
        ->and(GpNamingService::istDegeneriert('tomate|||m-tk'))->toBeFalse();
});

it('★ „Tomate: TK“ lässt sich neben „Tomate: frisch“ (alter Schlüssel tomate||) ohne force anlegen', function () {
    FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'tomate||', 'name' => 'Tomate: frisch', 'status' => 'approved', 'condition' => 'frisch']);

    $tk = $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Tomate', 'condition' => 'TK', 'commodity_group_code' => '01']);
    expect($tk->gp_key)->toBe('tomate|||m-tk');
});

it('★ Kommando: Trockenlauf schreibt nichts, --apply ergänzt, Kollisionen bleiben alt und stehen auf der Review-Liste, --zurueck stellt her', function () {
    $a = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'tomate||', 'name' => 'Tomate: trocken, geschwefelt', 'status' => 'approved',
        'condition' => 'trocken', 'pflichtangabe' => 'geschwefelt', 'commodity_group_code' => '01']);
    $b = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'tomate||', 'name' => 'Tomate: konserviert, Pulpe / (Bio)', 'status' => 'approved',
        'condition' => 'konserviert', 'pflichtangabe' => 'Pulpe', 'bio' => 'bio', 'commodity_group_code' => '01']);
    $umbenannt = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'sojasauce_suess_weizen||', 'name' => 'Sojasauce: süß, Weizen, konserviert', 'status' => 'approved',
        'flavor' => 'süß, Weizen', 'condition' => 'konserviert']);
    $sirup = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'golden_syrup||', 'name' => 'Golden Syrup', 'status' => 'approved']);
    $voll = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'moehre|scheiben_4_mm|', 'name' => 'Möhre: Scheiben 4 mm, frisch', 'status' => 'approved', 'condition' => 'frisch']);
    // Kollision: zwei globale Spinat-GPs, die auf denselben neuen Schlüssel fallen würden, und einer, dessen neuer Schlüssel schon belegt ist
    $s1 = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'spinat||', 'name' => 'Spinat: TK', 'status' => 'approved', 'condition' => 'TK']);
    FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mais|||m-tk', 'name' => 'Mais: TK', 'status' => 'approved', 'condition' => 'TK']);
    $m1 = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mais||', 'name' => 'Mais: TK (alt)', 'status' => 'approved', 'condition' => 'TK']);

    $this->artisan('foodalchemist:gp-key-merkmale')->expectsOutputToContain('tomate|| → tomate|||m-trocken_geschwefelt')->assertSuccessful();
    expect($a->fresh()->gp_key)->toBe('tomate||');

    $this->artisan('foodalchemist:gp-key-merkmale', ['--apply' => true])->assertSuccessful();
    expect($a->fresh()->gp_key)->toBe('tomate|||m-trocken_geschwefelt')
        ->and($b->fresh()->gp_key)->toBe('tomate|||m-konserviert_pulpe_bio')
        ->and($umbenannt->fresh()->gp_key)->toBe('sojasauce_suess_weizen|||m-konserviert')   // alter Schlüssel bleibt vorne, nur der Slot kommt dazu
        ->and($sirup->fresh()->gp_key)->toBe('golden_syrup||')
        ->and($voll->fresh()->gp_key)->toBe('moehre|scheiben_4_mm|')
        ->and($s1->fresh()->gp_key)->toBe('spinat|||m-tk')
        ->and($m1->fresh()->gp_key)->toBe('mais||');   // neuer Schlüssel belegt → bleibt, Review
    $review = json_decode(file_get_contents(collect(glob(storage_path('app/fa-gp-key-merkmale/*_review.json')))->sort()->last()), true);
    expect(array_column($review, 'id'))->toContain($m1->id);

    $protokoll = collect(glob(storage_path('app/fa-gp-key-merkmale/*_protokoll.json')))->sort()->last();
    $this->artisan('foodalchemist:gp-key-merkmale', ['--zurueck' => $protokoll])->assertSuccessful();
    expect($a->fresh()->gp_key)->toBe('tomate||')->and($s1->fresh()->gp_key)->toBe('spinat||');
    array_map('unlink', glob(storage_path('app/fa-gp-key-merkmale/*.json')));
});

it('zwei Kandidaten mit gleichem neuen Schlüssel im selben Bereich: beide bleiben alt, beide auf der Review-Liste', function () {
    $x = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'erbse||', 'name' => 'Erbse: TK', 'status' => 'approved', 'condition' => 'TK']);
    $y = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'erbse||', 'name' => 'Erbsen: TK', 'status' => 'approved', 'condition' => 'TK']);

    $plan = (new \Platform\FoodAlchemist\Console\GpKeyMerkmaleCommand)->plane($this->n);
    expect(array_column($plan['review'], 'id'))->toEqualCanonicalizing([$x->id, $y->id])->and($plan['neu'])->toBe([]);
});
