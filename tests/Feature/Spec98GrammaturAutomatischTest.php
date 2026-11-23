<?php

use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\GrammaturMessung;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\GrammaturUmstellungService;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\SchreibWache;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B, Schritt 3: `foodalchemist:grammatur-automatisch` auf der Charakterisierungs-Welt (A–D) plus einem nicht ableitbaren
 * Gericht E. Erwartet nach Weg A (Kurator 23.11., vorläufig für Dominique): EK und Produktion bleiben überall, die Grammatur nur
 * dort nicht, wo der Ertrag fest ist (C: Rohgewicht der Bestandteile). Trockenlauf schreibt nichts, Rückweg stellt alles her.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->c->update(['sales_quantity_per_unit_g' => 200]);   // Kopf mit Wert: zieht mit (Kurator 23.11.)
    $this->e = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'leer', 'name' => 'Ohne Mengen',
        'status' => 'approved', 'is_sales_recipe' => true]);
    ($this->darreichung)($this->e, 'teller', ['is_standard' => true, 'menge_vom_standard' => false]);
    $this->dar = fn (FoodAlchemistRecipe $r, string $form) => FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)
        ->whereHas('servingForm', fn ($q) => $q->where('code', $form))->firstOrFail();
    $this->travel(1)->minutes();   // Phasen-Zählung über updated_at: Fixture-Zeilen liegen davor
});

function grammaturBericht(array $b): array
{
    return collect($b['rezepte'])->flatMap(fn ($r) => collect($r['darreichungen'])->map(fn ($d) => $d + ['recipe_id' => $r['recipe_id']]))
        ->keyBy('darreichung_id')->all();
}

it('Trockenlauf: Kategorien wie erwartet, nichts geschrieben', function () {
    $vorher = FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'ek_portion'])->toArray();
    $b = app(GrammaturUmstellungService::class)->lauf(false);
    $z = grammaturBericht($b);

    expect($b['verstoesse'])->toBe([])->and($b['ok'])->toBeTrue();
    // A: Standard 1,2 kg ÷ 300 g = 4 Portionen, Kinder und Doppel über Deltas, „wie Standard“ zieht mit — alles gleich
    expect($z[($this->dar)($this->a, 'teller')->id])->toMatchArray(['aktion' => 'portionen', 'portionen' => 4, 'kategorie' => 'gleich'])
        ->and($z[($this->dar)($this->a, 'kinder')->id])->toMatchArray(['aktion' => 'komposition', 'kategorie' => 'gleich'])
        ->and($z[($this->dar)($this->a, 'doppel')->id]['kategorie'])->toBe('gleich')
        ->and($z[($this->dar)($this->a, 'wie-standard')->id])->toMatchArray(['aktion' => 'folgt_standard', 'kategorie' => 'gleich']);
    // C: fester Ertrag — nur die Grammatur ändert sich (+25 %), EK und Produktion bleiben
    $c = $z[($this->dar)($this->c, 'teller')->id];
    expect($c['kategorie'])->toBe('nicht_verlustfrei')->and($c['unterschiede'])->toBe(['gramm'])
        ->and($c['gramm_prozent'])->toBe(25.0)->and($c['hinweis'])->toContain('Rohgewicht')
        ->and($z[($this->dar)($this->c, 'kinder')->id]['unterschiede'])->toBe(['gramm']);
    // D: unbepreiste Komponente — gleich
    expect($z[($this->dar)($this->d, 'teller')->id]['kategorie'])->toBe('gleich')
        ->and($z[($this->dar)($this->d, 'kinder')->id]['kategorie'])->toBe('gleich');
    // B hat keine freie Grammatur, E ist nicht ableitbar und bleibt draußen
    expect(collect($b['rezepte'])->pluck('recipe_id')->all())->not->toContain($this->b->id)->not->toContain($this->e->id)
        ->and(collect($b['nicht_ableitbar'])->pluck('recipe_id')->all())->toBe([$this->e->id]);
    // Zählung je Phase und Team; Phase 1 nur Umbau-Tabellen
    expect(array_keys($b['zaehlung']))->toBe([0, 1, 2])   // PHP macht aus '0'…'2' Int-Schlüssel
        ->and($b['phasen'][0])->toContain('unabhängig von der Umstellung')   // Kurator 23.11.: Phase 0 klar abgegrenzt
        ->and(array_keys($b['zaehlung']['1']))->each->toBeIn(['foodalchemist_recipes', 'foodalchemist_recipe_presentations', 'foodalchemist_recipe_presentation_deltas']);

    expect(FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'ek_portion'])->toArray())->toBe($vorher);
});

it('--apply stellt um, zieht den Kopf nach, und der Rückweg stellt alles wieder her', function () {
    $vorher = FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'komponenten_referenz_g', 'ek_portion'])->toArray();
    $kopfVorher = FoodAlchemistRecipe::whereIn('id', [$this->a->id, $this->c->id])->orderBy('id')->get(['id', 'sales_unit_count', 'sales_quantity_per_unit_g'])->toArray();

    $this->artisan('foodalchemist:grammatur-automatisch', ['--apply' => true])->assertExitCode(0);

    expect(($this->dar)($this->a, 'teller')->menge_vom_standard)->toBeTrue()
        ->and($this->a->fresh()->sales_unit_count)->toBe(4)
        ->and(($this->dar)($this->a, 'kinder')->deltas()->count())->toBe(2)
        ->and((float) ($this->dar)($this->a, 'kinder')->quantity_per_unit_g)->toBe(150.0)
        ->and((float) $this->c->fresh()->sales_quantity_per_unit_g)->toBe(250.0)      // Kopf = Grammatur des Standards
        ->and((float) ($this->dar)($this->c, 'teller')->ek_portion)->toBe(3.5);       // EK bleibt (Weg A)
    // Nichts Freies mehr außer dem nicht ableitbaren E
    expect(app(GrammaturUmstellungService::class)->plan())->toHaveCount(1);

    $journal = collect(glob(storage_path('app/foodalchemist/grammatur-umstellung/journal-*.json')))->sort()->last();
    $this->artisan('foodalchemist:grammatur-automatisch', ['--rueckweg' => $journal])->assertExitCode(0);
    @unlink($journal);

    expect(FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'komponenten_referenz_g', 'ek_portion'])->toArray())->toBe($vorher)
        ->and(FoodAlchemistRecipe::whereIn('id', [$this->a->id, $this->c->id])->orderBy('id')->get(['id', 'sales_unit_count', 'sales_quantity_per_unit_g'])->toArray())->toBe($kopfVorher)
        ->and(($this->dar)($this->a, 'kinder')->deltas()->count())->toBe(0);
});

it('Fertigungstiefe: Hochstufen nach Inhalt zählt als Aufholen in Phase 0, nicht als Folge der Umstellung', function () {
    // A wird von der Kaskade geführt, steht auf from_scratch, enthält aber Convenience-Ware (WG 13) → der Normalweg stuft hoch
    $this->a->ingredients()->first()->gp()->update(['commodity_group_code' => '13']);
    FoodAlchemistRecipe::whereKey($this->a->id)->update(['production_depth' => 'from_scratch', 'production_depth_source' => 'kaskade']);

    $b = app(GrammaturUmstellungService::class)->lauf(false);

    expect($b['ok'])->toBeTrue()
        ->and($b['phase0_fertigungstiefe_hochgestuft'])->toBe(1)
        ->and($b['fertigungstiefe_hochgestuft'])->toBe(0)
        ->and($this->a->fresh()->production_depth)->toBe('from_scratch');   // Trockenlauf: zurückgerollt
});

it('Verwender außerhalb der Auswahl (je Masse und je Stück) bleiben unverändert und werden geprüft', function () {
    // Master 23.11.: Eltern, die per --rezept ausgenommen sind, rechnet Phase 2 trotzdem mit
    $t = $this->rootTeam->id;
    $stk = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $this->a->update(['yield_pieces' => 4]);
    $eltern = [];
    foreach (['masse' => [300, $this->g->id], 'stueck' => [1, $stk->id]] as $key => [$menge, $einheit]) {
        $p = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'eltern-'.$key, 'name' => 'Menü '.$key, 'status' => 'approved', 'is_sales_recipe' => true]);
        $p->ingredients()->create(['team_id' => $t, 'position' => 0, 'referenced_recipe_id' => $this->a->id, 'raw_text' => 'Bratkartoffeln',
            'quantity' => $menge, 'unit_vocab_id' => $einheit, 'match_method' => 'manual']);
        ($this->darreichung)($p, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 300]);
        app(RecipeRecomputeService::class)->recomputePipeline($p->id);
        $eltern[] = $p->id;
    }

    $b = app(GrammaturUmstellungService::class)->lauf(true, [$this->a->id]);

    expect($b['verstoesse'])->toBe([])->and($b['ok'])->toBeTrue()
        ->and($b['eltern_geprueft'])->toBe(['rezepte' => 2, 'darreichungen' => 2])
        ->and(collect($b['rezepte'])->pluck('recipe_id')->all())->toBe([$this->a->id]);   // Eltern selbst nicht umgestellt
    foreach ($eltern as $id) {
        expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $id)->first()->menge_vom_standard)->toBeFalse();
    }
    @array_map('unlink', glob(storage_path('app/foodalchemist/grammatur-umstellung/journal-*.json')) ?: []);
});

it('Verwender-Prüfung: jede Bewegung einer Eltern-Darreichung ist ein Verstoß', function () {
    $d = ['gramm' => 300.0, 'ek_portion' => 1.0, 'calculated_sales_net' => 4.0, 'sales_net' => 4.0, 'price_mode' => 'auto', 'je_zutat' => [1 => 0.25]];
    $alt = [9 => ['rezept' => [], 'darreichungen' => [5 => $d, 6 => $d]]];
    $neu = [9 => ['rezept' => [], 'darreichungen' => [5 => ['gramm' => 300.05] + $d, 6 => ['ek_portion' => 1.2] + $d]]];
    $v = GrammaturMessung::elternVerstoesse($alt, $neu);
    expect($v)->toHaveCount(1)->and($v[0])->toContain('Darreichung 6')->toContain('ek_portion');
    expect(GrammaturMessung::elternVerstoesse($alt, [9 => ['rezept' => [], 'darreichungen' => [5 => $d]]]))->toHaveCount(1);   // fehlt
});

it('--json legt das Zielverzeichnis an', function () {
    $datei = storage_path('app/foodalchemist/test-'.Str::random(8).'/bericht.json');
    $this->artisan('foodalchemist:grammatur-automatisch', ['--json' => $datei])->assertExitCode(0);
    expect(json_decode((string) file_get_contents($datei), true))->toHaveKeys(['ok', 'rezepte', 'zaehlung', 'nicht_ableitbar']);
    @unlink($datei);
    @rmdir(dirname($datei));
});

it('Phase-0-EK-Sprünge: je Tabelle und Team Zahl der Rezepte, größter Sprung mit Rezept, Zahl ab 20 %', function () {
    $vorher = ['foodalchemist_recipe_presentations' => [1 => [5, 10, 2.0], 2 => [5, 10, 4.0], 3 => [5, 11, 1.0], 4 => [6, 12, 3.0], 5 => [6, 13, null]]];
    $nachher = ['foodalchemist_recipe_presentations' => [1 => [5, 10, 2.002], 2 => [5, 10, 5.0], 3 => [5, 11, 1.5], 4 => [6, 12, 3.0], 5 => [6, 13, 2.0]]];
    expect(GrammaturMessung::ekSpruenge($vorher, $nachher))->toBe(['foodalchemist_recipe_presentations' => [
        '5' => ['rezepte' => 2, 'max_prozent' => 50.0, 'max_recipe_id' => 11, 'ab_warnschwelle' => 2],   // R10 +25 %, R11 +50 %; 0,2 ct unter Toleranz zählt nicht
        '6' => ['rezepte' => 1, 'max_prozent' => null, 'max_recipe_id' => null, 'ab_warnschwelle' => 0],   // leer → 2 €: geändert, ohne Prozent
    ]]);
});

it('Schreibwache: fremde Tabelle, fremde Rezept-Spalte und fremdes Rezept sind Verstöße; fremde Warnungen nicht', function () {
    $w = new SchreibWache;
    $w->phase('1', [7]);
    $w->pruefe('update "foodalchemist_recipes" set "sales_unit_count" = ?, "updated_at" = ? where "id" = ?', [4, 'x', 7]);
    expect($w->verstoesse())->toBe([]);
    $w->pruefe('update "foodalchemist_recipes" set "ek_per_kg_eur" = ? where "id" = ?', [1, 7]);
    $w->pruefe('update "foodalchemist_recipes" set "sales_unit_count" = ? where "id" = ?', [4, 8]);
    $w->pruefe('insert into "foodalchemist_concepts" ("name") values (?)', ['x']);
    $w->phase('2');
    $w->pruefe('delete from "foodalchemist_price_change_audits" where "id" = ?', [1]);
    $w->pruefe('select * from "foodalchemist_concepts"', []);
    expect($w->verstoesse())->toHaveCount(4);
});
