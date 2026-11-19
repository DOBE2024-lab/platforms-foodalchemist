<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Jod (`iodized`) für den Bestand nachfüllen. Lieferant 19491 (aktiv): Zeile 1001 Jod NULL (mit Necta-Stand), 1002 Jod schon 3
 * (Necta sagt 1 → bleibt), 1003 Jod NULL, Necta ohne Jod-Wert; Necta liefert zusätzlich 1009 ohne FA-Zeile. Lieferant 20000
 * (aktiv): 2001 Jod NULL. Lieferant 16555 ist bei uns inaktiv: 5001 bleibt NULL.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $zeile = function (int $lieferant, int $legacy, array $werte) {
        $l = FoodAlchemistSupplier::firstOrCreate(['legacy_id' => $lieferant], ['team_id' => null, 'name' => "Lieferant {$lieferant}",
            'is_inactive' => $lieferant === 16555, 'stamm_class' => StammPaket::PAKET]);
        $id = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => $legacy, 'supplier_id' => $l->id, 'designation' => "Artikel {$legacy}",
            'stamm_class' => StammPaket::PAKET])->id;
        DB::table('foodalchemist_item_declarations')->insert($werte + ['uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'legacy_id' => $legacy, 'supplier_item_id' => $id, 'with_dye' => 1, 'created_at' => now(), 'updated_at' => now()]);
    };
    $zeile(19491, 1001, []);
    $zeile(19491, 1002, ['iodized' => 3]);
    $zeile(19491, 1003, []);
    $zeile(20000, 2001, []);
    $zeile(16555, 5001, []);
    DB::table('foodalchemist_necta_stand')->insert(['objekt' => 'item_declaration', 'legacy_id' => 1001,
        'werte' => json_encode(['with_dye' => 1]), 'created_at' => now(), 'updated_at' => now()]);

    $dk = fn (int $legacy, ?int $jod) => ['supplierItemId' => $legacy, 'declarationWithDye' => 1, 'declarationIodhTlig' => $jod, 'declarationReserve' => 0];
    $this->attrappe = new NectaAttrappe(['supplier-item-declaration-substances' => [
        $dk(1001, 3), $dk(1002, 1), $dk(1003, null), $dk(1009, 3), $dk(2001, 1), $dk(5001, 3),
    ]], [1001 => 19491, 1002 => 19491, 1003 => 19491, 1009 => 19491, 2001 => 20000, 5001 => 16555]);
    $this->app->instance(NectaQuelle::class, $this->attrappe);
    $this->jod = fn (int $legacy) => DB::table('foodalchemist_item_declarations')->where('legacy_id', $legacy)->value('iodized');
});

it('Jod ist freiwillig: nie in den Pflicht-Stoffen (Legende, Nummerierung, Kennzeichnung)', function () {
    expect(FoodAlchemistItemDeclaration::STOFFE)->not->toHaveKey('iodized')->toHaveCount(18)
        ->and(FoodAlchemistItemDeclaration::FREIWILLIG)->toHaveKey('iodized');
});
