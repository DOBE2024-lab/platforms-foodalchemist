<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\GpLaufFelder;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Untergruppe „02.8 Obstsortiment“ (Georg/Dominique 14.11.2026): global, idempotent, im GP-Lauf als verwaltete Untergruppe gültig. */
beforeEach(fn () => $this->seedTeamHierarchy());

it('Migration legt „02.8 Obstsortiment“ global an, 2× up gleich; der GP-Lauf akzeptiert sie in WG 02', function () {
    $t = 'foodalchemist_commodity_group_subcategories';
    $m = require dirname(__DIR__, 2) . '/database/migrations/2026_12_30_100000_subkategorie_obstsortiment.php';
    DB::table($t)->where('name', '02.8 Obstsortiment')->delete();

    $m->up();
    $m->up();
    $zeilen = DB::table($t)->where('commodity_group_code', '02')->where('name', '02.8 Obstsortiment')->get();
    expect($zeilen)->toHaveCount(1)->and($zeilen[0]->team_id)->toBeNull()
        ->and(GpLaufFelder::untergruppeVerwaltet('02', '02.8 Obstsortiment', null))->toBeTrue()
        ->and(GpLaufFelder::untergruppeVerwaltet('11', '02.8 Obstsortiment', null))->toBeFalse();
});
