<?php

use Platform\FoodAlchemist\Exceptions\PaketAltNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\OrderTemplateService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\PaketUmzug\PaketUmzugService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.6b: Nach dem Umzug Alt-Paket → Concept ist die alte Paket-Persistenz nur noch lesbar (Schalter
 * foodalchemist.paket_alt_nur_lesen). Model-Hooks sperren jede Änderung an Paket und Position; neue package_id-Verweise
 * (Concept-Slot, Speiseplan, Bestellvorlage) werden fachlich abgelehnt; automatische Nachrechnungen lassen alte Pakete aus.
 * Schalter aus = Verhalten wie vorher (Rückweg).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'green-power', 'name' => 'Green Power',
        'status' => 'approved', 'is_sales_recipe' => true, 'ek_total_eur' => 0.60, 'sales_quantity_per_unit_g' => 250]);
    $this->paket = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Green & Tasty', 'role' => 'Hauptgang',
        'price_per_person' => 12.50, 'price_mode' => 'auto']);
    $this->position = FoodAlchemistPaketGericht::create(['team_id' => $this->rootTeam->id, 'package_id' => $this->paket->id,
        'sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'position' => 0]);
    config(['foodalchemist.paket_alt_nur_lesen' => true]);
});

it('Schalter an: Paket und Position lassen sich weder anlegen, ändern, löschen noch wiederherstellen', function () {
    $team = $this->rootTeam->id;
    expect(fn () => FoodAlchemistPaket::create(['team_id' => $team, 'name' => 'Neu']))->toThrow(PaketAltNurLesenException::class)
        ->and(fn () => $this->paket->update(['name' => 'Umbenannt']))->toThrow(PaketAltNurLesenException::class)
        ->and(fn () => $this->paket->delete())->toThrow(PaketAltNurLesenException::class)
        ->and(fn () => FoodAlchemistPaketGericht::create(['team_id' => $team, 'package_id' => $this->paket->id, 'sales_recipe_id' => $this->gericht->id]))
            ->toThrow(PaketAltNurLesenException::class)
        ->and(fn () => $this->position->update(['quantity' => 2]))->toThrow(PaketAltNurLesenException::class)
        ->and(fn () => $this->position->delete())->toThrow(PaketAltNurLesenException::class);

    config(['foodalchemist.paket_alt_nur_lesen' => false]);
    $this->paket->delete();
    config(['foodalchemist.paket_alt_nur_lesen' => true]);
    expect(fn () => $this->paket->restore())->toThrow(PaketAltNurLesenException::class);

    expect($this->paket->fresh()?->name ?? FoodAlchemistPaket::withTrashed()->find($this->paket->id)->name)->toBe('Green & Tasty')
        ->and((float) $this->position->fresh()->quantity)->toBe(1.0);
});

it('Schalter an: Paket-Service-Wege (Ändern, Reihenfolge per Massen-Update) werden abgelehnt', function () {
    $svc = app(PaketService::class);
    expect(fn () => $svc->update($this->rootTeam, $this->paket->id, ['name' => 'X']))->toThrow(PaketAltNurLesenException::class)
        ->and(fn () => $svc->reorderGerichte($this->rootTeam, $this->paket->id, [$this->position->id]))->toThrow(PaketAltNurLesenException::class)
        ->and($svc->markStaleForRecipe($this->gericht->id))->toBe(0);
});

it('Schalter an: Lesen bleibt möglich (Detail, Positionen)', function () {
    $detail = app(PaketService::class)->detail($this->rootTeam, $this->paket->id);

    expect($detail->name)->toBe('Green & Tasty')
        ->and(app(PaketUmzugService::class)->altPositionen($this->paket))->toHaveCount(1);
});

it('Schalter an: Concept-Slot nimmt kein Alt-Paket mehr, ein Paket-Concept schon', function () {
    $concepts = app(ConceptService::class);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Mittag']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Hauptgang']);

    expect(fn () => $concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $this->paket->id]))
        ->toThrow(PaketAltNurLesenException::class, 'embedded_concept_id');

    $paketConcept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Green & Tasty', 'kind' => 'paket']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $paketConcept->id]);
    expect($slot->fresh()->embedded_concept_id)->toBe($paketConcept->id)
        ->and($slot->fresh()->package_id)->toBeNull();
});

it('Schalter an: Speiseplan und Bestellvorlage nehmen kein Alt-Paket mehr auf', function () {
    $sp = app(SpeiseplanService::class);
    $plan = $sp->create($this->rootTeam, ['name' => 'KW 49', 'zyklus_wochen' => 1, 'start_date' => '2026-11-30']);
    expect(fn () => $sp->addEintrag($this->rootTeam, $plan->id, ['entry_date' => '2026-11-30', 'mahlzeit' => 'mittag', 'package_id' => $this->paket->id]))
        ->toThrow(PaketAltNurLesenException::class);

    $tpl = app(OrderTemplateService::class);
    $vorlage = $tpl->anlegen($this->rootTeam, ['name' => 'Buffet']);
    expect(fn () => $tpl->positionSetzen($this->rootTeam, $vorlage->id, 'paket', $this->paket->id, 50))->toThrow(PaketAltNurLesenException::class);
});

it('Schalter an: Preis-Kaskade lässt alte Pakete aus, statt abzubrechen', function () {
    $cascade = app(PricingCascadeService::class);

    expect($cascade->recomputeTeam($this->rootTeam)['packages'])->toBe(0)
        ->and($cascade->recomputeRecipes([$this->gericht->id])['packages'] ?? 0)->toBe(0);
});

it('Schalter aus (Rückweg): alles wie vorher', function () {
    config(['foodalchemist.paket_alt_nur_lesen' => false]);

    $this->paket->update(['name' => 'Green & Tasty 2']);
    app(PaketService::class)->reorderGerichte($this->rootTeam, $this->paket->id, [$this->position->id]);
    $concepts = app(ConceptService::class);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Mittag']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $this->paket->id]);

    expect($this->paket->fresh()->name)->toBe('Green & Tasty 2')
        ->and($slot->fresh()->package_id)->toBe($this->paket->id)
        ->and(app(PricingCascadeService::class)->recomputeTeam($this->rootTeam)['packages'])->toBe(1);
});

it('Der Umzug selbst schreibt nur die Concept-Seite und läuft auch mit Schalter an', function () {
    $r = app(PaketUmzugService::class)->anwenden([$this->paket->id]);

    expect($r['journal']['pakete'])->toHaveCount(1)
        ->and(FoodAlchemistConcept::withoutGlobalScopes()->where('uuid', $this->paket->uuid)->value('kind'))->toBe('paket');
});

/**
 * Architektur: Massen-Schreibzugriffe (Query-Builder oder Eloquent-Bulk) auf die Alt-Tabellen laufen an den Model-Hooks
 * vorbei. Neue gibt es nicht; die bestehenden stehen in Dateien, die den Schalter prüfen. Neue package_id-/paket_id-Werte in
 * Schreibanweisungen: Bestand darf nur sinken.
 */
it('Architektur: keine neuen Schreibwege auf die alte Paket-Persistenz und auf package_id', function () {
    $wurzel = dirname(__DIR__, 2).'/src';
    $bulk = [];
    $verweise = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->getExtension() !== 'php' || str_contains($datei->getPathname(), '/PaketUmzug/')) {
            continue;
        }
        $rel = substr($datei->getPathname(), strlen($wurzel) + 1);
        $code = file_get_contents($datei->getPathname());
        foreach (explode(';', $code) as $anweisung) {
            if (preg_match("/'foodalchemist_package(s|_dishes)'|FoodAlchemistPaket(Gericht)?::/", $anweisung)
                && preg_match('/->(update|insert|insertGetId|delete|forceDelete|increment|decrement|upsert|updateOrInsert)\(/', $anweisung)) {
                $bulk[$rel] = ($bulk[$rel] ?? 0) + 1;
            }
            if (preg_match("/'(package_id|paket_id)'\s*=>/", $anweisung)
                && preg_match('/->(create|update|forceFill|insert|fill|updateOrCreate|firstOrCreate)\(|::create\(/', $anweisung)) {
                $verweise[$rel] = ($verweise[$rel] ?? 0) + 1;
            }
        }
        if (isset($bulk[$rel])) {
            expect(str_contains($code, 'PaketAltNurLesen'))->toBeTrue("{$rel}: Massen-Schreibzugriff auf Alt-Pakete ohne Schalterprüfung");
        }
    }
    ksort($bulk);
    ksort($verweise);

    // Bestand 22.11.2026. PaketService: reorderGerichte + markStaleForRecipe (beide geschützt).
    expect($bulk)->toBe(['Services/PaketService.php' => 2]);

    // Bestand 22.11.2026: Kopien vorhandener Werte (Fassungen, Kündigungskopien, Speiseplan-Kopien), Leeren auf null,
    // geschützte Eingänge (ConceptService::fillSlot; Speiseplan über den Eintrag-Hook) und Konfigurator-Pakete (eigene Tabelle, nicht betroffen).
    $bestand = [
        'Services/AboKuendigungService.php' => 2, 'Services/ConceptFassungService.php' => 1, 'Services/ConceptService.php' => 6,
        'Services/KonfiguratorPricingService.php' => 1, 'Services/PaketFassungService.php' => 1, 'Services/SpeiseplanService.php' => 5,
    ];
    foreach ($verweise as $datei => $n) {
        expect($n)->toBeLessThanOrEqual($bestand[$datei] ?? 0, "{$datei}: neuer Schreibweg mit package_id/paket_id ({$n})");
    }

    // Die fachlichen Eingänge prüfen den Schalter
    foreach (['Services/ConceptService.php', 'Models/FoodAlchemistSpeiseplanEintrag.php', 'Services/OrderTemplateService.php'] as $eingang) {
        expect(file_get_contents("{$wurzel}/{$eingang}"))->toContain('PaketAltNurLesen::pruefe(');
    }
});
