<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M14-01: Speiseplan — Belegung (Concept/Paket/Gericht), Kosten pro Tag/Woche,
 * Wiederholungs-Check (Mindestabstand).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->plan = app(SpeiseplanService::class);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Tagessuppe', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 3.50, 'ek_total_eur' => 1.00,
    ]);
    // 98 · 2.6c: Paket = kind=paket-Concept (manuell 2,50 €/P, EK 0,80 aus seinem Gericht), im Plan über concept_id
    $concepts = app(\Platform\FoodAlchemist\Services\ConceptService::class);
    $salat = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sb1', 'name' => 'Salatbar-Mix', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 2.50, 'ek_total_eur' => 0.80,
    ]);
    $this->paket = $concepts->createPaket($this->rootTeam, ['name' => 'Salatbar']);
    $concepts->update($this->rootTeam, $this->paket->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 2.50]);
    $concepts->fillSlot($this->rootTeam, $concepts->addSlot($this->rootTeam, $this->paket->id, ['role' => 'Beilage'])->id, ['sales_recipe_id' => $salat->id]);
});

it('M14/v2: Eintrag belegt Zelle mit GENAU EINEM Inhalt; Wochen-Raster + Kosten pro Tag', function () {
    // Speiseplan v2: echtes Datum × Linie × Mahlzeit statt woche/wochentag
    $sp = $this->plan->create($this->rootTeam, ['name' => 'KW 28', 'zyklus_wochen' => 1, 'start_date' => '2026-07-06']);
    $mo = '2026-07-06';                                             // Montag
    $di = '2026-07-07';
    // Mo Mittag: Gericht (3,50) + Paket (2,50)
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $mo, 'mahlzeit' => 'mittag', 'concept_id' => $this->paket->id]);
    // Eintrag mit BEIDEN gesetzt → nur das Konzept gewinnt (genau eines)
    $beide = $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $di, 'mahlzeit' => 'mittag', 'concept_id' => $this->paket->id, 'sales_recipe_id' => $this->gericht->id]);
    expect($beide->concept_id)->toBe($this->paket->id)->and($beide->sales_recipe_id)->toBeNull();
    // 98 · 2.6c: ein Alt-Paket (package_id) wird abgelehnt
    expect(fn () => $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $di, 'mahlzeit' => 'mittag', 'package_id' => 4711]))
        ->toThrow(\RuntimeException::class, 'Alte Pakete');

    $montag = \Illuminate\Support\Carbon::parse($mo);
    $raster = $this->plan->wochenRaster($sp->refresh(), 'mittag', $montag);
    expect($raster[0][$mo])->toHaveCount(2);                        // ohne Linie → Key 0

    $kosten = $this->plan->wochenKosten($sp, 'mittag', $montag);
    expect($kosten['pro_tag'][$mo]['vk'])->toBe(6.0)                // 3,50 + 2,50
        ->and($kosten['pro_tag'][$mo]['ek'])->toBe(1.8)             // 1,00 + 0,80
        ->and($kosten['woche']['vk'])->toBe(8.5);                   // + Di 2,50
});

it('M14/v2: Wiederholungs-Check flaggt zu engen Abstand (echte Tages-Abstände)', function () {
    $sp = $this->plan->create($this->rootTeam, ['name' => 'Zyklus', 'zyklus_wochen' => 2, 'min_abstand_tage' => 5, 'start_date' => '2026-07-06']);
    // Tagessuppe Mo (06.07.) und Mi (08.07.) → Abstand 2 < 5 → Konflikt
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-07-06', 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-07-08', 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);

    $w = collect($this->plan->wiederholungen($sp->refresh()))->firstWhere('key', 'g' . $this->gericht->id);
    expect($w['vorkommen'])->toBe(2)->and($w['min_abstand'])->toBe(2)->and($w['konflikt'])->toBeTrue();
});

it('M14: Owner-Guard — Kind-Team kann geerbten Speiseplan nicht pflegen', function () {
    $sp = $this->plan->create($this->rootTeam, ['name' => 'Root-Plan']);
    expect(fn () => $this->plan->update($this->childA, $sp->id, ['name' => 'Hack']))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->plan->addEintrag($this->childA, $sp->id, ['sales_recipe_id' => $this->gericht->id]))->toThrow(\RuntimeException::class);
});
