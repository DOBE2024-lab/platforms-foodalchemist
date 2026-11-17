<?php

use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Nährwerte je 100 g AUSBEUTE + Zeilenart „Medium“ (Entscheid Dominique 17.11.2026, Regelwerk Basisrezepte §18.7).
 *
 * Anlass: Sandbox Team 4, Rezept 191 „Pasta: Linguine“ — Linguine 500 g, Leitungswasser 5000 ml (90 % Garverlust), Salz 50 g (90 %).
 * yield 1,005 kg war richtig, das Salz stand aber bei ~9 g/100 g. Richtig: 5 g Rest-Salz / 1,005 kg ≈ 0,5 g/100 g.
 * Garverlust heißt bei Lebensmitteln Verdampfen (Nährstoffe bleiben), beim Kochmedium Verwerfen (Nährstoffe gehen mit).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->ml = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1]);

    // Kuratierte GP-Nährwerte (nutri_source=manual) — der Rezept-Pfad liest sie ohne LA (Salz-Fix)
    $this->gpMit = function (string $name, float $kcal, float $salz = 0.0, float $fett = 0.0) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['nutri_kcal_per_100g' => $kcal, 'nutri_protein_g_per_100g' => 0, 'nutri_fat_g_per_100g' => $fett,
            'nutri_carbs_g_per_100g' => 0, 'nutri_salt_g_per_100g' => $salz, 'nutri_source' => 'manual']);

        return $gp;
    };
    $this->linguine = ($this->gpMit)('Linguine: trocken', 350);
    $this->wasser = $this->makeGp($this->rootTeam, 'Leitungswasser');   // ohne Nährwerte → verdünnt nur
    $this->salz = ($this->gpMit)('Salz: trocken, jodiert', 0, 100);

    // Entscheid 17.11. (d): Kochwasser ist Medium, das Salz darin Medium mit demselben Prozentsatz
    $this->linguineRezept = function (?string $salzZeilenart, ?string $wasserZeilenart = 'medium') {
        $r = $this->svc->create($this->rootTeam, ['name' => 'Pasta: Linguine']);

        return $this->svc->syncIngredients($this->rootTeam, $r->id, [
            ['gp_id' => $this->linguine->id, 'raw_text' => 'Linguine', 'quantity' => '500', 'unit_vocab_id' => $this->g->id, 'cooking_loss_pct' => 0],
            ['gp_id' => $this->wasser->id, 'raw_text' => 'Wasser', 'quantity' => '5000', 'unit_vocab_id' => $this->ml->id, 'cooking_loss_pct' => 90, 'zeilenart' => $wasserZeilenart],
            ['gp_id' => $this->salz->id, 'raw_text' => 'Salz', 'quantity' => '50', 'unit_vocab_id' => $this->g->id, 'cooking_loss_pct' => 90, 'zeilenart' => $salzZeilenart],
        ]);
    };
});

it('Linguine: Kochwasser + Salz als Medium (90 %) → ≈ 0,5 g Salz je 100 g Ausbeute, yield 1,005 kg', function () {
    $r = ($this->linguineRezept)('medium');

    // Masse: 500 + 5000 × 0,1 + 50 × 0,1 = 1005 g; Salz: 50 × 0,1 = 5 g → 0,498 g/100 g; kcal: 1750 / 1005 × 100
    expect((float) $r->yield_kg)->toBe(1.005)
        ->and((float) $r->nutri_salt_g_per_100g)->toEqualWithDelta(0.5, 0.01)
        ->and((float) $r->nutri_kcal_per_100g)->toEqualWithDelta(174.1, 0.1)
        ->and($r->ingredients->firstWhere('gp_id', $this->salz->id)->zeilenart)->toBe('medium');
});

it('ungesetzte Zeile übernimmt den GP-Vorschlag is_kochmedium; gesetzte Zeilenart „zutat“ übersteuert ihn', function () {
    $this->salz->update(['is_kochmedium' => true]);

    $vorschlag = ($this->linguineRezept)(null);
    expect($vorschlag->ingredients->firstWhere('gp_id', $this->salz->id)->zeilenart)->toBeNull()
        ->and((float) $vorschlag->nutri_salt_g_per_100g)->toEqualWithDelta(0.5, 0.01);

    // Übersteuerung Zutat > GP: Salz zählt voll, nur die Masse sinkt (Verdampfen-Lesart) → 50 / 1005 × 100
    $zutat = ($this->linguineRezept)('zutat');
    expect((float) $zutat->nutri_salt_g_per_100g)->toEqualWithDelta(4.975, 0.01)
        ->and((float) $zutat->yield_kg)->toBe(1.005);
});

it('GP-Vorschlag ohne Garverlust ändert nichts: Salz zum Würzen zählt voll', function () {
    $this->salz->update(['is_kochmedium' => true]);
    $r = $this->svc->create($this->rootTeam, ['name' => 'Sole: Würzsalz']);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, [
        ['gp_id' => $this->salz->id, 'raw_text' => 'Salz', 'quantity' => '10', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $this->wasser->id, 'raw_text' => 'Wasser', 'quantity' => '990', 'unit_vocab_id' => $this->ml->id],
    ]);

    expect((float) $r->nutri_salt_g_per_100g)->toBe(1.0)->and((float) $r->yield_kg)->toBe(1.0);
});

it('Fleisch mit 30 % Garverlust, kein Medium: Nährstoffe bleiben, die Dichte steigt', function () {
    $fleisch = ($this->gpMit)('Rinderschulter: frisch', 200, 0.2, 10);
    $r = $this->svc->create($this->rootTeam, ['name' => 'Fleisch: Schmorbraten']);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, [
        ['gp_id' => $fleisch->id, 'raw_text' => 'Rind', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id, 'cooking_loss_pct' => 30],
    ]);

    // 1000 g × 200 kcal/100 g = 2000 kcal auf 700 g Ausbeute → 285,7 kcal/100 g (vorher Rohmasse: 200)
    expect((float) $r->yield_kg)->toBe(0.7)
        ->and((float) $r->nutri_kcal_per_100g)->toBe(285.7)
        ->and((float) $r->nutri_fat_g_per_100g)->toBe(14.29);
});

it('Medium ohne Garverlust ist ganz verworfen (§18.7 Confit): weder Ausbeute noch Nährwerte', function () {
    $fisch = ($this->gpMit)('Thunfisch: frisch', 100);
    $oel = ($this->gpMit)('Olivenöl: nativ extra', 900, 0, 100);
    $r = $this->svc->create($this->rootTeam, ['name' => 'Confit: Thunfisch']);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, [
        ['gp_id' => $fisch->id, 'raw_text' => 'Thunfisch', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $oel->id, 'raw_text' => 'Öl am Fisch', 'quantity' => '150', 'unit_vocab_id' => $this->ml->id],
        ['gp_id' => $oel->id, 'raw_text' => 'Öl zum Garen', 'quantity' => '1350', 'unit_vocab_id' => $this->ml->id, 'zeilenart' => 'medium'],
    ]);

    // (1000 × 100 + 150 × 900) / 1150 × 100 = 204,3 kcal; Ausbeute 1,15 kg
    expect((float) $r->yield_kg)->toBe(1.15)
        ->and((float) $r->nutri_kcal_per_100g)->toBe(204.3)
        ->and($r->ingredients->firstWhere('raw_text', 'Öl zum Garen')->cooking_loss_pct)->toBeNull();   // nur gerechnet, nicht geschrieben
});

it('Medium ohne Garverlust-Vorschlag (cooking_loss NULL) gilt als 100 % verworfen — Ausbeute und Nährwerte; der Vorschlag am GP gilt vorher', function () {
    $r = $this->svc->create($this->rootTeam, ['name' => 'Pasta: ohne Vorschlag']);
    $zeilen = fn () => [
        ['gp_id' => $this->linguine->id, 'raw_text' => 'Linguine', 'quantity' => '500', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $this->wasser->id, 'raw_text' => 'Wasser', 'quantity' => '5000', 'unit_vocab_id' => $this->ml->id, 'zeilenart' => 'medium'],
        ['gp_id' => $this->salz->id, 'raw_text' => 'Salz', 'quantity' => '50', 'unit_vocab_id' => $this->g->id, 'zeilenart' => 'medium'],
    ];
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, $zeilen());
    expect((float) $r->yield_kg)->toBe(0.5)
        ->and((float) $r->nutri_salt_g_per_100g)->toBe(0.0)
        ->and((float) $r->nutri_kcal_per_100g)->toBe(350.0);

    // GP-Default-Garverlust ist ein Vorschlag und gilt vor der 100-%-Regel
    $this->salz->update(['cooking_loss_default_pct' => 90]);
    $this->wasser->update(['cooking_loss_default_pct' => 90]);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, $zeilen());
    expect((float) $r->yield_kg)->toBe(1.005)->and((float) $r->nutri_salt_g_per_100g)->toEqualWithDelta(0.5, 0.01);
});

it('Sub-Rezept-Vererbung: Eltern-Rezept rechnet mit den Werten je 100 g Ausbeute des Unterrezepts', function () {
    $sub = ($this->linguineRezept)('medium');
    $sosse = ($this->gpMit)('Tomatensauce: konserviert', 50, 1.0);
    $r = $this->svc->create($this->rootTeam, ['name' => 'Pasta: Linguine Pomodoro']);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, [
        ['referenced_recipe_id' => $sub->id, 'raw_text' => 'Linguine gegart', 'quantity' => '200', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $sosse->id, 'raw_text' => 'Sauce', 'quantity' => '100', 'unit_vocab_id' => $this->g->id],
    ]);

    $subSalz = (float) $sub->fresh()->nutri_salt_g_per_100g;
    // (200 × subSalz + 100 × 1,0) / 300 × 100
    expect((float) $r->nutri_salt_g_per_100g)->toEqualWithDelta((200 * $subSalz + 100) / 300, 0.002)
        ->and((float) $r->nutri_salt_g_per_100g)->toBeLessThan(1.0);
});

it('Gegenprobe ohne Verluste: Bezugsmasse = Rohmasse, Werte unverändert zur alten Rechnung', function () {
    $r = $this->svc->create($this->rootTeam, ['name' => 'Mischung: ohne Verlust']);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, [
        ['gp_id' => $this->linguine->id, 'raw_text' => 'Linguine', 'quantity' => '300', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $this->salz->id, 'raw_text' => 'Salz', 'quantity' => '6', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $this->wasser->id, 'raw_text' => 'Wasser', 'quantity' => '694', 'unit_vocab_id' => $this->ml->id],
    ]);

    expect((float) $r->nutri_salt_g_per_100g)->toBe(0.6)
        ->and((float) $r->nutri_kcal_per_100g)->toBe(105.0)
        ->and((float) $r->yield_kg)->toBe(1.0);
});

it('Zeilenart wird normiert (leer/unbekannt = ungesetzt) und beim Duplizieren mitgenommen', function () {
    expect(FoodAlchemistRecipeIngredient::zeilenartNormieren(' Medium '))->toBe('medium')
        ->and(FoodAlchemistRecipeIngredient::zeilenartNormieren(''))->toBeNull()
        ->and(FoodAlchemistRecipeIngredient::zeilenartNormieren('lake'))->toBeNull();

    $r = ($this->linguineRezept)('medium');
    $kopie = $this->svc->duplicate($this->rootTeam, $r->id, 'Pasta: Linguine Kopie');
    expect($kopie->ingredients()->where('gp_id', $this->salz->id)->value('zeilenart'))->toBe('medium');
});

it('Sensorik-Grundlage rechnet mit derselben Regel: Salz im Kochwasser verworfen, Bezug Ausbeute', function () {
    $lf = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $la = function (int $gpId, array $werte) use ($lf) {
        $i = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lf->id, 'designation' => 'LA ' . $gpId . uniqid()]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $i->id, 'gp_id' => $gpId]);
        FoodAlchemistItemNutritional::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $i->id, ...$werte]);
    };
    $la($this->linguine->id, ['sodium' => 0, 'energy_kcal' => 350]);
    $la($this->salz->id, ['sodium' => 40000, 'energy_kcal' => 0]);                  // 100 g Salz/100 g
    $r = ($this->linguineRezept)('medium');
    $sensorik = app(SensorikService::class);

    // Wasser ohne deklarierten Gehalt (Leer-Label-Guard: alle Kernwerte 0 = „nicht deklariert“) bleibt unbekannt:
    // Schnitt über die bekannte Ausbeute (505 g) = 5 g / 505 g ≈ 0,99, ehrlich mit 50 % Abdeckung (vorher 49,4 / 550 ≈ 9,0)
    $ohneWasser = $sensorik->rezeptNaehrwerte($r->id);
    expect($ohneWasser['werte']['salzig'])->toEqualWithDelta(0.99, 0.01)
        ->and($ohneWasser['abdeckung']['salzig'])->toEqualWithDelta(0.502, 0.001);

    // Wasser mit deklariertem Natrium (1 mg/100 ml) zählt mit: Kochwasser ist Medium, auch seine Mineralien gehen zu 90 % mit
    // → (5 g + 500 × 0,0025 g/100 g) / 1005 g ≈ 0,5 — dieselbe Zahl wie die gespeicherten Rezept-Nährwerte
    $la($this->wasser->id, ['sodium' => 1, 'energy_kcal' => 0]);
    $mitWasser = $sensorik->rezeptNaehrwerte($r->id);
    expect($mitWasser['werte']['salzig'])->toEqualWithDelta(0.5, 0.005)
        ->and($mitWasser['abdeckung']['salzig'])->toBe(1.0);
});

it('Speisesalz ohne kcal am Artikel (nur Natrium, nicht kuratiert) zählt mit — Salz ist kalorienfrei (io 17.11.: Linguine 0,004 statt 0,5)', function () {
    $lf = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $salzRoh = $this->makeGp($this->rootTeam, 'Salz: trocken, unjodiert');   // keine kuratierten GP-Werte
    $i = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lf->id, 'designation' => 'Speisesalz 25kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $i->id, 'gp_id' => $salzRoh->id]);
    FoodAlchemistItemNutritional::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $i->id, 'sodium' => 39483.2]);   // kcal NULL wie bei Necta
    $r = $this->svc->create($this->rootTeam, ['name' => 'Pasta: Linguine']);
    $r = $this->svc->syncIngredients($this->rootTeam, $r->id, [
        ['gp_id' => $this->linguine->id, 'raw_text' => 'Linguine', 'quantity' => '500', 'unit_vocab_id' => $this->g->id, 'cooking_loss_pct' => 0],
        ['gp_id' => $this->wasser->id, 'raw_text' => 'Wasser', 'quantity' => '5000', 'unit_vocab_id' => $this->ml->id, 'cooking_loss_pct' => 90, 'zeilenart' => 'medium'],
        ['gp_id' => $salzRoh->id, 'raw_text' => 'Salz', 'quantity' => '50', 'unit_vocab_id' => $this->g->id, 'cooking_loss_pct' => 90, 'zeilenart' => 'medium'],
    ]);

    // 5 g Salz-Ware × 98,7 % Salz ≈ 4,93 g / 1005 g ≈ 0,49 g je 100 g; kcal bleibt bei der Pasta
    expect((float) $r->nutri_salt_g_per_100g)->toEqualWithDelta(0.49, 0.01)
        ->and((float) $r->nutri_kcal_per_100g)->toEqualWithDelta(174.1, 0.1);

    // Gegenprobe: Ware mit wenig Salz und ohne kcal bleibt „ohne Werte“ (keine erfundene 0-kcal-Zeile)
    expect(\Platform\FoodAlchemist\Services\Rezept\NaehrwertAusbeuteRechner::SALZWARE_AB_G)->toBe(50.0);
});
