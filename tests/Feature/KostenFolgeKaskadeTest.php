<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\KostenFolgeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kosten-Folge durch alle Kaskaden (Befund 18.11.2026: Achenbachglas + Cocos-Vanille-Bliss in der Sandbox; Dominique: „das müsste ja durch alle
 * Kaskaden sein“). Kette: Zutaten-GP → Basisrezept → Gericht → Darreichung (ek_portion) → Paket (ek_per_person, auch manuell bepreist) → Konzept
 * (ek_per_person_cache). Auslöser: neuer Preis, Lead-Wechsel, Lieferanten-/Preis-Freigabe im Stamm-Paket; dieselbe Kette mit einem Gefäß-GP.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $this->dar = app(DarreichungService::class);

    // Kette in Team $t: Zutaten-GP $gp (Artikel $la, 1 kg) → Basisrezept (1000 g GP) → Gericht (500 g Basis) → Darreichung 100 g → Paket + Konzept
    $this->kette = function (Team $t, FoodAlchemistGp $gp) {
        $basis = $this->makeRecipe($t, 'Basis Vanillecreme');
        $this->makeIngredient($basis, $gp->name, $gp, '1000');
        $gericht = $this->makeRecipe($t, 'Panna Cotta', ['is_sales_recipe' => true]);
        FoodAlchemistRecipeIngredient::create(['team_id' => $t->id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $basis->id,
            'raw_text' => 'Basis Vanillecreme', 'quantity' => '500', 'unit_vocab_id' => $this->unitG($t)->id, 'position' => 1]);
        app(RecipeRecomputeService::class)->recomputeMany([$basis->id]);
        $d = $this->dar->ensureStandard($t, $gericht->id);
        $d = $this->dar->aktualisieren($t, $d->id, ['quantity_per_unit_g' => 100, 'unit_count' => 1]);
        $paket = FoodAlchemistPaket::create(['team_id' => $t->id, 'name' => 'Dessert-Paket', 'price_mode' => 'auto']);
        $paket->gerichte()->create(['team_id' => $t->id, 'sales_recipe_id' => $gericht->id, 'position' => 0, 'quantity' => 1]);
        $manuell = FoodAlchemistPaket::create(['team_id' => $t->id, 'name' => 'Dessert-Paket fix', 'price_mode' => 'manuell', 'price_per_person' => 9.0]);
        $manuell->gerichte()->create(['team_id' => $t->id, 'sales_recipe_id' => $gericht->id, 'position' => 0, 'quantity' => 1]);
        $konzept = FoodAlchemistConcept::create(['team_id' => $t->id, 'name' => 'Buffet', 'status' => 'draft']);
        $konzept->slots()->create(['team_id' => $t->id, 'role' => 'Dessert', 'title' => 'Dessert', 'position' => 0, 'package_id' => $paket->id]);
        foreach ([$paket, $manuell] as $p) {
            app(PaketService::class)->recomputePrice($p);
        }
        app(ConceptService::class)->recomputeCache($konzept);

        return (object) ['basis' => $basis, 'gericht' => $gericht, 'dar' => $d, 'paket' => $paket, 'manuell' => $manuell, 'konzept' => $konzept];
    };
    $this->stand = fn (object $k) => [
        'basis' => ($v = $k->basis->fresh()->ek_per_kg_eur) !== null ? round((float) $v, 2) : null,
        'gericht' => ($v = $k->gericht->fresh()->ek_per_kg_eur) !== null ? round((float) $v, 2) : null,
        'dar' => ($v = $k->dar->fresh()->ek_portion) !== null ? round((float) $v, 4) : null,
        'paket' => ($v = $k->paket->fresh()->ek_per_person) !== null ? round((float) $v, 4) : null,
        'manuell' => ($v = $k->manuell->fresh()->ek_per_person) !== null ? round((float) $v, 4) : null,
        'manuell_preis' => (float) $k->manuell->fresh()->price_per_person,
        'konzept' => ($v = $k->konzept->fresh()->ek_per_person_cache) !== null ? round((float) $v, 4) : null,
    ];
    $this->artikel = function (Team $t, string $name, ?float $preis, ?FoodAlchemistSupplier $sup = null, array $gpAttrs = [], float $qty = 1.0, string $einheit = 'kg') {
        $gp = $this->makeGp($t, $name);
        $gpAttrs !== [] && $gp->update($gpAttrs);
        $sup ??= FoodAlchemistSupplier::firstOrCreate(['team_id' => $t->id, 'name' => 'Großhandel']);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $sup->team_id, 'supplier_id' => $sup->id, 'designation' => $name, 'qty' => $qty, 'unit_code' => $einheit]
            + ($sup->team_id === null ? ['stamm_class' => 'stamm_paket'] : []));
        FoodAlchemistSupplierItemStructure::create(['team_id' => $sup->team_id ?? $t->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($preis !== null) {
            FoodAlchemistPrice::create(['team_id' => $t->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        }
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
});

it('★ neuer Artikelpreis an einem Zutaten-GP: Basisrezept, Gericht, Darreichung, Paket (auch manuell bepreist) und Konzept ziehen nach', function () {
    [$gp, $la] = ($this->artikel)($this->rootTeam, 'Vanillemilch', 4.0);
    $k = ($this->kette)($this->rootTeam, $gp);
    $vorher = ($this->stand)($k);
    // Plausibilität: die Kette rechnet wirklich (4 €/kg Basis ⇒ Gericht 4 €/kg, 100 g ⇒ 0,40 €)
    expect($vorher['basis'])->toBe(4.0)->and($vorher['gericht'])->toBe(4.0)->and($vorher['dar'])->toBe(0.4)->and($vorher['paket'])->toBe(0.4)
        ->and($vorher['manuell'])->toBe(0.4)->and($vorher['konzept'])->toBe(0.4);

    app(PriceService::class)->createFor($this->rootTeam, $la, 6.0);

    $nachher = ($this->stand)($k);
    expect($nachher['basis'])->toBe(6.0)->and($nachher['gericht'])->toBe(6.0)->and($nachher['dar'])->toBe(0.6)->and($nachher['paket'])->toBe(0.6)
        ->and($nachher['manuell'])->toBe(0.6)->and($nachher['manuell_preis'])->toBe(9.0)   // manueller Preis bleibt, nur EK frisch
        ->and($nachher['konzept'])->toBe(0.6);
});

it('★ dieselbe Kette mit einem Gefäß-GP: neuer Artikelpreis und Lead-Wechsel rechnen Darreichung, Paket und Konzept neu', function () {
    [$gp] = ($this->artikel)($this->rootTeam, 'Vanillemilch', 4.0);
    $k = ($this->kette)($this->rootTeam, $gp);
    [$glas, $glasLa] = ($this->artikel)($this->rootTeam, 'Achenbachglas: Broich', 1.20, null,
        ['commodity_group_code' => '16', 'sub_category' => NonFoodRegeln::SUB_GESCHIRR], 12.0, 'Stk');   // 0,10 € je Stück
    $this->dar->aktualisieren($this->rootTeam, $k->dar->id, ['vessel_gp_id' => $glas->id]);
    app(PaketService::class)->recomputePrice($k->paket->fresh());
    app(ConceptService::class)->recomputeCache($k->konzept->fresh());
    expect(($this->stand)($k)['dar'])->toBe(0.5)->and(($this->stand)($k)['paket'])->toBe(0.5)->and(($this->stand)($k)['konzept'])->toBe(0.5);

    app(PriceService::class)->createFor($this->rootTeam, $glasLa, 2.40);   // 0,20 € je Stück
    expect(($this->stand)($k))->toMatchArray(['gericht' => 4.0, 'dar' => 0.6, 'paket' => 0.6, 'manuell' => 0.6, 'konzept' => 0.6]);

    // Lead-Wechsel auf einen günstigeren Artikel (0,05 € je Stück)
    $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel']);
    $la2 = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Achenbachglas günstig', 'qty' => 12.0, 'unit_code' => 'Stk']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la2->id, 'gp_id' => $glas->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la2->id, 'price' => 0.60, 'status' => '0']);
    app(LeadLaService::class)->setLeadLa($this->rootTeam, $glas->refresh(), $la2->id);
    expect(($this->stand)($k))->toMatchArray(['dar' => 0.45, 'paket' => 0.45, 'konzept' => 0.45]);
});

it('★ Verleih-Gefäß (extern, Automatik) rechnet mit dem Lead-Artikelpreis; ein neuer Preis zieht durch', function () {
    app(\Platform\FoodAlchemist\Services\SupplierGroupService::class)->einrichten(true);
    $verleih = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierGroup::whereNull('team_id')->where('code', 'VERLEIH')->firstOrFail();
    $profimiet = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Verleih Köln', 'group_id' => $verleih->id]);
    [$gp] = ($this->artikel)($this->rootTeam, 'Vanillemilch', 4.0);
    $k = ($this->kette)($this->rootTeam, $gp);
    [$glas, $glasLa] = ($this->artikel)($this->rootTeam, 'Achenbachglas: Broich', 0.28, $profimiet,
        ['commodity_group_code' => '16', 'sub_category' => NonFoodRegeln::SUB_GESCHIRR], 1.0, 'Stk');
    $this->dar->aktualisieren($this->rootTeam, $k->dar->id, ['vessel_gp_id' => $glas->id]);
    $g = $this->dar->gefaessKosten($k->dar->fresh(), $this->rootTeam);
    expect($g['kostenart'])->toBe('extern')->and($g['kostenart_quelle'])->toBe('automatik')->and($g['kosten'])->toBe(0.28)
        ->and($g['preis_quelle'])->toBe('artikel')->and(($this->stand)($k)['dar'])->toBe(0.68);

    app(PriceService::class)->createFor($this->rootTeam, $glasLa, 0.35);
    expect(($this->stand)($k))->toMatchArray(['dar' => 0.75, 'paket' => 0.75, 'konzept' => 0.75]);
});

it('★ Stamm-Paket: Lieferanten-/Preis-Freigabe rechnet die Kette des Mandanten neu (vorher EK NULL bis Recompute von Hand)', function () {
    $master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    MasterTeam::flush();
    StammPaket::flush();
    $admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    $soul = Team::create(['name' => 'Sandbox', 'user_id' => 1, 'personal_team' => false]);
    $svc = app(StammPaketService::class);
    $svc->buchen($soul, $admin);

    $achenbach = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Rudolf Achenbach', 'stamm_class' => 'stamm_paket']);
    $andere = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Andere', 'stamm_class' => 'stamm_paket']);
    // GP + globaler Stamm-Artikel, Preis nur im Master (Necta) — sichtbar erst nach Freigabe mit „Preise mitgeben“
    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'cocos-vanille-bliss|test', 'name' => 'Cocos-Vanille-Bliss', 'status' => 'approved']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $achenbach->id, 'designation' => 'Cocos-Vanille-Bliss', 'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => 'stamm_paket']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(), 'supplier_item_id' => $la->id, 'team_id' => $master->id, 'status' => '0', 'price' => 15.75,
        'is_blocked' => false, 'status_valid_from' => '2026-11-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    $k = ($this->kette)($soul, $gp->refresh());
    expect(($this->stand)($k)['gericht'])->toBeNull()->and(($this->stand)($k)['dar'])->toBeNull();   // ohne Freigabe kein Preis

    $svc->setzeLieferanten($soul, [$achenbach->id], $admin);
    $svc->setzePreisFreigabe($soul, [$achenbach->id], $admin);
    expect(($this->stand)($k))->toMatchArray(['basis' => 15.75, 'gericht' => 15.75, 'dar' => 1.575, 'paket' => 1.575, 'konzept' => 1.575]);

    // Rücknahme der Preis-Freigabe wirkt ebenso durch (kein Preis mehr sichtbar)
    $svc->setzePreisFreigabe($soul, 'keine', $admin);
    expect(($this->stand)($k)['gericht'])->toBeNull()->and(($this->stand)($k)['dar'])->toBeNull();

    // Freigabe eines Lieferanten ohne Artikel an diesen GPs: kein Recompute der Kette (gezielt, kein Vollrecompute)
    Queue::fake();
    $svc->setzeLieferanten($soul, [$achenbach->id, $andere->id], $admin);
    Queue::assertPushed(KostenFolgeJob::class, fn (KostenFolgeJob $j) => $j->supplierIds === [$andere->id] && $j->teamId === (int) $soul->id && $j->nurTeamKette);
    expect(KostenFolgeJob::gpIds([], [$andere->id]))->toBe([]);
});

it('Gezielt: der Job rechnet nur Rezepte und Darreichungen der Team-Kette, fremde Teams bleiben unberührt', function () {
    [$gpA, $laA] = ($this->artikel)($this->childA, 'Vanillemilch A', 4.0);
    $kA = ($this->kette)($this->childA, $gpA);
    [$gpB, $laB] = ($this->artikel)($this->childB, 'Vanillemilch B', 4.0);
    $kB = ($this->kette)($this->childB, $gpB);
    // Preise ohne Auslöser ändern (direkt in die Tabelle), dann den Job nur für die Kette von childA laufen lassen — mit beiden GPs
    FoodAlchemistPrice::where('supplier_item_id', $laA->id)->update(['price' => 8.0]);
    FoodAlchemistPrice::where('supplier_item_id', $laB->id)->update(['price' => 8.0]);
    (new KostenFolgeJob((int) $this->childA->id, [(int) $gpA->id, (int) $gpB->id]))
        ->handle(app(RecipeRecomputeService::class), app(\Platform\FoodAlchemist\Services\KostenCacheService::class), $this->dar);
    expect(($this->stand)($kA))->toMatchArray(['gericht' => 8.0, 'dar' => 0.8, 'paket' => 0.8])
        ->and(($this->stand)($kB))->toMatchArray(['gericht' => 4.0, 'dar' => 0.4, 'paket' => 0.4]);
});

it('Sammellauf (Preisliste/Datei-Import): ein Job je Team und Reichweite am Ende, ohne Rezepte (die rechnet die Import-Kette)', function () {
    [, $la1] = ($this->artikel)($this->rootTeam, 'Vanillemilch', 4.0);
    [, $la2] = ($this->artikel)($this->rootTeam, 'Kokosmilch', 3.0);
    Queue::fake();
    \Platform\FoodAlchemist\Services\KostenCacheService::gesammelt(function () use ($la1, $la2) {
        app(PriceService::class)->createFor($this->rootTeam, $la1, 5.0);
        app(PriceService::class)->createFor($this->rootTeam, $la2, 3.5);
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(KostenFolgeJob::class, 1);
    Queue::assertPushed(KostenFolgeJob::class, fn (KostenFolgeJob $j) => count($j->gpIds) === 2 && ! $j->mitRezepten && $j->teamId === (int) $this->rootTeam->id);

    app(PriceService::class)->createFor($this->rootTeam, $la1, 5.5);   // außerhalb: sofort, mit Rezepten
    Queue::assertPushed(KostenFolgeJob::class, fn (KostenFolgeJob $j) => $j->mitRezepten && count($j->gpIds) === 1);
});
