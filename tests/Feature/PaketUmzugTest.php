<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketUmzug\PaketParitaet;
use Platform\FoodAlchemist\Services\PaketUmzug\PaketUmzugService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.6a: Alt-Paket → kind=paket-Concept mit Trockenlauf, Journal und Rückweg. Fall io: drei Alt-Pakete,
 * deren Concept-Kopien (uuid-Reuse) gelöscht sind und veraltet sein können. Der Trockenlauf muss das melden, der Lauf
 * die Kopie wiederherstellen und auf den Stand des Alt-Pakets bringen, der Rückweg exakt zurückdrehen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->umzug = app(PaketUmzugService::class);

    $mk = fn (array $a) => FoodAlchemistRecipe::create(array_merge(['team_id' => $this->rootTeam->id, 'status' => 'approved',
        'is_sales_recipe' => true, 'allergens_confidence' => 'high', 'nutri_confidence' => 'high'], $a));
    $this->salat = $mk(['recipe_key' => 'salat', 'name' => 'Green Power', 'ek_total_eur' => 0.60, 'work_time_min' => 15,
        'sales_quantity_per_unit_g' => 250, 'nutri_kcal_per_100g' => 200, 'spec_is_vegan' => true, 'spec_is_vegetarian' => true]);
    $this->pork = $mk(['recipe_key' => 'pork', 'name' => 'Pulled Pork', 'ek_total_eur' => 0.90, 'work_time_min' => 10,
        'sales_quantity_per_unit_g' => 200, 'nutri_kcal_per_100g' => 150, 'spec_contains_pork' => true]);
    $this->basis = $mk(['recipe_key' => 'dip', 'name' => 'Kräuterdip', 'is_sales_recipe' => false]);

    $this->alt = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Green & Tasty', 'role' => 'Hauptgang',
        'price_per_person' => 12.50, 'ek_per_person' => 3.20, 'price_mode' => 'manuell']);
    $this->position = fn (FoodAlchemistRecipe $r, int $pos, ?float $menge = 1) => FoodAlchemistPaketGericht::create([
        'team_id' => $this->rootTeam->id, 'package_id' => $this->alt->id, 'sales_recipe_id' => $r->id, 'quantity' => $menge, 'position' => $pos]);
    ($this->position)($this->salat, 0);
    ($this->position)($this->basis, 1, 0.5);
});

function umzugConcept(FoodAlchemistPaket $alt): ?FoodAlchemistConcept
{
    return FoodAlchemistConcept::withoutGlobalScopes()->where('uuid', $alt->uuid)->first();
}

it('Trockenlauf ohne Kopie: Aktion neu, schreibt nichts', function () {
    $plan = $this->umzug->plan([$this->alt->id]);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['aktion'])->toBe('neu')
        ->and($plan[0]['positionen_alt'])->toBe(2)
        ->and($plan[0]['blocker'])->toBe([])
        ->and(umzugConcept($this->alt))->toBeNull();
});

it('Anwenden: kind=paket-Concept mit gleicher uuid, Positionen 1:1, Typ aus dem Rezept, Parität alt = neu', function () {
    $this->umzug->anwenden([$this->alt->id]);

    $neu = umzugConcept($this->alt);
    expect($neu->kind)->toBe('paket')
        ->and($neu->name)->toBe('Green & Tasty')
        ->and((float) $neu->price_per_person_manual)->toBe(12.5)
        ->and($this->umzug->slotPositionen($neu))->toBe($this->umzug->altPositionen($this->alt->fresh()))
        ->and(FoodAlchemistConceptSlot::where('concept_id', $neu->id)->orderBy('position')->pluck('type')->all())->toBe(['gericht', 'basisrezept']);

    $report = app(PaketParitaet::class)->vergleiche($this->alt->fresh(), $neu->fresh());
    expect(collect($report)->reject(fn ($f) => $f['ok'])->keys()->all())->toBe(['preis_gerechnet']);

    // Bekannter Modellunterschied für 2.6b: Gerichte ohne VK — alt eine Lücke (null), neu 0 €. Beide als unvollständig ausgewiesen.
    expect($report['preis_gerechnet']['alt'])->toMatchArray(['vk' => null, 'vollstaendig' => false])
        ->and($report['preis_gerechnet']['neu'])->toMatchArray(['vk' => 0.0, 'vollstaendig' => false]);
});

it('gelöschte, veraltete Kopie: Trockenlauf meldet wiederherstellen + veraltet, Lauf stellt dieselbe ID auf den Alt-Stand', function () {
    $this->travel(-90)->days();
    $this->umzug->anwenden([$this->alt->id]);
    $kopie = umzugConcept($this->alt);
    $kopie->delete();
    $this->travelBack();

    ($this->position)($this->pork, 2);   // Alt-Paket nach der Kopie geändert

    $plan = $this->umzug->plan([$this->alt->id])[0];
    expect($plan['aktion'])->toBe('wiederherstellen')
        ->and($plan['concept_id'])->toBe($kopie->id)
        ->and($plan['veraltet'])->toBeTrue()
        ->and(implode(' ', $plan['veraltet_gruende']))->toContain('Positionen alt ≠ Kopie')->toContain('Position geändert');

    $this->umzug->anwenden([$this->alt->id]);
    $neu = umzugConcept($this->alt);
    expect($neu->id)->toBe($kopie->id)
        ->and($neu->trashed())->toBeFalse()
        ->and($this->umzug->plan([$this->alt->id])[0]['veraltet'])->toBeFalse()
        ->and(app(ConceptService::class)->allergenRollup($neu->fresh())['contains_pork'])->toBeTrue();
});

it('Rückweg: stellt gelöschte Kopie, alte Slots und Einbettungen exakt wieder her', function () {
    $this->umzug->anwenden([$this->alt->id]);
    $kopie = umzugConcept($this->alt);
    $kopie->delete();
    ($this->position)($this->pork, 2);

    $host = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommerfest', 'kind' => 'concept', 'status' => 'draft']);
    $einbettung = FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $host->id, 'type' => 'paket',
        'package_id' => $this->alt->id, 'position' => 0]);

    $conceptVorher = (array) DB::table('foodalchemist_concepts')->where('id', $kopie->id)->first();
    $slotsVorher = DB::table('foodalchemist_concept_slots')->where('concept_id', $kopie->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $r = $this->umzug->anwenden([$this->alt->id]);
    expect($einbettung->fresh()->embedded_concept_id)->toBe($kopie->id);

    expect($this->umzug->rueckweg(json_decode(json_encode($r['journal']), true)))->toBe(1);

    expect((array) DB::table('foodalchemist_concepts')->where('id', $kopie->id)->first())->toEqual($conceptVorher)
        ->and(DB::table('foodalchemist_concept_slots')->where('concept_id', $kopie->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toEqual($slotsVorher)
        ->and($einbettung->fresh()->embedded_concept_id)->toBeNull();
});

it('Rückweg eines neu angelegten Concepts entfernt es samt Slots', function () {
    $r = $this->umzug->anwenden([$this->alt->id]);
    $id = umzugConcept($this->alt)->id;

    $this->umzug->rueckweg($r['journal']);

    expect(umzugConcept($this->alt))->toBeNull()
        ->and(DB::table('foodalchemist_concept_slots')->where('concept_id', $id)->count())->toBe(0);
});

it('Blocker (Fassung des Alt-Pakets): Lauf bricht ab und schreibt nichts', function () {
    DB::table('foodalchemist_packages')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'name' => 'Green & Tasty',
        'role' => 'Hauptgang', 'ist_fassung' => true, 'fassung_von_id' => $this->alt->id, 'fassung_nr' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect($this->umzug->plan([$this->alt->id])[0]['blocker'])->toBe(['fassungen: 1'])
        ->and(fn () => $this->umzug->anwenden([$this->alt->id]))->toThrow(RuntimeException::class, 'fassungen: 1')
        ->and(umzugConcept($this->alt))->toBeNull();
});

it('Befehl: Trockenlauf zeigt veraltete Kopie und Parität, --apply legt Journal an, --rueckweg spielt es zurück', function () {
    $this->umzug->anwenden([$this->alt->id]);
    umzugConcept($this->alt)->delete();
    ($this->position)($this->pork, 2);

    $this->artisan('foodalchemist:pakete-to-concepts', ['--id' => [$this->alt->id]])
        ->expectsOutputToContain('WIEDERHERSTELLEN')
        ->expectsOutputToContain('Kopie veraltet')
        ->expectsOutputToContain('DIFF mengen_einheiten')
        ->assertExitCode(0);
    expect(umzugConcept($this->alt)->trashed())->toBeTrue();

    $this->artisan('foodalchemist:pakete-to-concepts', ['--id' => [$this->alt->id], '--apply' => true])->assertExitCode(0);
    expect(umzugConcept($this->alt)->trashed())->toBeFalse();

    $journal = collect(glob(storage_path('app/foodalchemist/paket-umzug/journal-*.json')))->sort()->last();
    $this->artisan('foodalchemist:pakete-to-concepts', ['--rueckweg' => $journal])->assertExitCode(0);
    expect(umzugConcept($this->alt)->trashed())->toBeTrue();
    @unlink($journal);
});
