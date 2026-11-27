<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Concepter\KonzeptPreiseNachrechnen;
use Platform\FoodAlchemist\Services\Concepter\ConceptPreisService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ConceptsListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * CQ price_incomplete (PR A): Der Konzept-Preis-Cache merkt sich, ob er nur die Summe der bekannten Positionen ist. Einzige
 * Schreibstelle refreshCache, einzige Lesestelle ConceptPreisService::preisWerte; eingebettete Pakete reichen den Merker nach
 * oben weiter; ein manueller Preis gilt als vollständig (Entscheid Dominique). Bestand: `foodalchemist:konzept-preise-nachrechnen`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->concepts = app(ConceptService::class);
    $t = $this->rootTeam;

    // Paket mit bekanntem Preis (Cache direkt gesetzt: die Kaskade liest ihn wie gespeichert)
    $this->voll = $this->concepts->createPaket($t, ['name' => 'Brotkorb']);
    DB::table('foodalchemist_concepts')->where('id', $this->voll->id)->update(['price_per_person_cache' => 12, 'ek_per_person_cache' => 4, 'price_incomplete' => false]);

    $this->ohneVk = FoodAlchemistRecipe::create(['team_id' => $t->id, 'recipe_key' => 'ohne-vk', 'name' => 'Ohne VK',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1]);

    $this->concept = $this->concepts->create($t, ['name' => 'Sommerfest']);
    $this->einbetten = fn (FoodAlchemistConcept $in, FoodAlchemistConcept $paket) => $this->concepts->fillSlot($t,
        $this->concepts->addSlot($t, $in->id, ['role' => 'Station'])->id, ['embedded_concept_id' => $paket->id]);
    $this->gericht = fn (FoodAlchemistConcept $in, FoodAlchemistRecipe $r) => $this->concepts->fillSlot($t,
        $this->concepts->addSlot($t, $in->id, ['role' => 'Dessert'])->id, ['recipe_id' => $r->id]);
});

it('vollständige Positionen: Merker false; eine Position ohne VK: true, der Preis bleibt die Summe der bekannten', function () {
    ($this->einbetten)($this->concept, $this->voll);
    expect($this->concept->refresh()->price_incomplete)->toBeFalse()
        ->and((float) $this->concept->price_per_person_cache)->toBe(12.0);

    ($this->gericht)($this->concept, $this->ohneVk);
    expect($this->concept->refresh()->price_incomplete)->toBeTrue()
        ->and((float) $this->concept->price_per_person_cache)->toBe(12.0);
});

it('ein manuell gesetzter Konzept-Preis gilt als vollständig, zurück auf auto wieder unvollständig', function () {
    ($this->gericht)($this->concept, $this->ohneVk);
    expect($this->concept->refresh()->price_incomplete)->toBeTrue();

    $this->concepts->update($this->rootTeam, $this->concept->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 20]);
    expect($this->concept->refresh()->price_incomplete)->toBeFalse()
        ->and((float) $this->concept->price_per_person_cache)->toBe(20.0);

    $this->concepts->update($this->rootTeam, $this->concept->id, ['price_mode' => 'auto']);
    expect($this->concept->refresh()->price_incomplete)->toBeTrue();
});

it('ein eingebettetes Paket mit Teil-Preis macht das Konzept unvollständig, sein Wert bleibt sichtbar', function () {
    $teil = $this->concepts->createPaket($this->rootTeam, ['name' => 'Dessertbuffet']);
    ($this->gericht)($teil, $this->ohneVk);
    expect($teil->refresh()->price_incomplete)->toBeTrue();

    ($this->einbetten)($this->concept, $this->voll);
    $slot = ($this->einbetten)($this->concept, $teil);
    $cockpit = $this->concepts->preisCockpit($this->concept->refresh());
    $zeile = collect($cockpit['zeilen'])->firstWhere('slot_id', $slot->id);

    expect($zeile['vk_unvollstaendig'] ?? false)->toBeTrue()
        ->and($zeile['vk_fehlt'])->toBeFalse()
        ->and($cockpit['hat_vk_luecke'])->toBeTrue()
        ->and($this->concept->price_incomplete)->toBeTrue();
});

it('unvollstaendig(): manuell schlägt alles; Team-Kalkulation über vollstaendig; Arbeitszeilen über hat_vk_luecke', function () {
    expect(ConceptPreisService::unvollstaendig(['price_mode' => 'manuell', 'hat_vk_luecke' => true, 'vollstaendig' => false]))->toBeFalse()
        ->and(ConceptPreisService::unvollstaendig(['price_mode' => 'fixed', 'hat_vk_luecke' => true]))->toBeFalse()
        ->and(ConceptPreisService::unvollstaendig(['price_mode' => 'auto', 'vollstaendig' => false]))->toBeTrue()
        ->and(ConceptPreisService::unvollstaendig(['price_mode' => 'auto', 'vollstaendig' => true, 'hat_vk_luecke' => true]))->toBeTrue()
        ->and(ConceptPreisService::unvollstaendig(['price_mode' => 'auto', 'vollstaendig' => true, 'hat_vk_luecke' => false]))->toBeFalse()
        ->and(ConceptPreisService::unvollstaendig(['price_mode' => 'auto', 'hat_vk_luecke' => true]))->toBeTrue()
        ->and(ConceptPreisService::unvollstaendig(['price_mode' => 'auto', 'hat_vk_luecke' => false]))->toBeFalse();
});

it('preisWerte liest den Merker; noch nicht berechnet gilt als vollständig', function () {
    $preis = app(ConceptPreisService::class);
    ($this->gericht)($this->concept, $this->ohneVk);
    expect($preis->preisWerte($this->concept->refresh(), $this->rootTeam)['vollstaendig'])->toBeFalse()
        ->and($preis->preisWerte(FoodAlchemistConcept::query()->select(['id', 'ist_fassung', 'price_per_person_cache', 'ek_per_person_cache'])
            ->find($this->concept->id), $this->rootTeam)['vollstaendig'])->toBeFalse();   // Spalte nicht geladen → aus der DB

    DB::table('foodalchemist_concepts')->where('id', $this->concept->id)->update(['price_incomplete' => null]);
    expect($preis->preisWerte($this->concept->refresh(), $this->rootTeam)['vollstaendig'])->toBeTrue();
});

it('Nachrechnen: Pakete vor ihren Eltern; Trockenlauf schreibt nichts, --apply setzt den Merker durch die Kaskade', function () {
    $teil = $this->concepts->createPaket($this->rootTeam, ['name' => 'Dessertbuffet']);
    ($this->gericht)($teil, $this->ohneVk);
    ($this->einbetten)($this->concept, $teil);
    DB::table('foodalchemist_concepts')->whereIn('id', [$teil->id, $this->concept->id])->update(['price_incomplete' => null]);   // Bestand vor dem Nachrechnen

    $reihe = app(KonzeptPreiseNachrechnen::class)->reihenfolge($this->rootTeam->id);
    expect(array_search($teil->id, $reihe, true))->toBeLessThan(array_search($this->concept->id, $reihe, true));

    $this->artisan('foodalchemist:konzept-preise-nachrechnen')->expectsOutputToContain('Trockenlauf')->assertSuccessful();
    expect($teil->refresh()->price_incomplete)->toBeNull()->and($this->concept->refresh()->price_incomplete)->toBeNull();

    $this->artisan('foodalchemist:konzept-preise-nachrechnen --apply --team=' . $this->rootTeam->id)
        ->expectsOutputToContain('Preis unvollständig: 2')->assertSuccessful();
    expect($teil->refresh()->price_incomplete)->toBeTrue()
        ->and($this->concept->refresh()->price_incomplete)->toBeTrue()
        ->and($this->voll->refresh()->price_incomplete)->toBeFalse();
});

it('MCP: concepts.LIST führt price_incomplete je Zeile', function () {
    ($this->gericht)($this->concept, $this->ohneVk);

    expect(ConceptsListTool::zeile($this->concept->refresh()->loadCount('slots')))->toHaveKey('price_incomplete', true)
        ->and(ConceptsListTool::zeile($this->voll->refresh()->loadCount('slots')))->toHaveKey('price_incomplete', false);
});

it('Zuschlagskalkulation: Summe je Gast ohne bekannten EK zeigt „offen“, nie 0 %', function () {
    $sim = ['pax' => 50, 'catalog_price_per_person' => 30.0, 'price_per_person' => 30.0, 'ek_per_person' => null, 'complete' => false,
        'positionen' => [['role' => 'Dessert', 'label' => 'Ohne VK', 'ek' => null, 'price' => 30.0]]];
    $html = view('foodalchemist::livewire.angebote.partials.zuschlagskalkulation', ['auftragsKalkulation' => $sim])->render();
    expect($html)->toMatch('/data-angebot-fuss-we>\s*offen\s*</');

    $html = view('foodalchemist::livewire.angebote.partials.zuschlagskalkulation', ['auftragsKalkulation' => ['ek_per_person' => 9.0] + $sim])->render();
    expect($html)->toMatch('/data-angebot-fuss-we>\s*30,0 %\s*</');
});
