<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Support\GpAnkerVorauswahl;
use Platform\FoodAlchemist\Support\HauptzutatenAusListe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * io 18.11.2026, GP 13361 „Curry: Brokkoli, frisch / (Planet V) / (Bio)" (3 Kern-Anker + Kokosmilch neben): „Anker vorschlagen" fragte einen
 * Nebenanker, die KI sagte neutral (ai_call_log 18533). Der Prompt kannte nur Name/Zustand/Warengruppe, die Vorauswahl nur Wörter aus dem Namen —
 * Möhre, Rote Linse, Mandel, Zwiebel standen nirgends. Dominique: „sie müsste die Stammdaten des Artikels bekommen, um das überhaupt zu bewerten."
 * Fixture = die echte Zutatenliste des Lead-LA 320821 auf io.
 */
const IO_ZUTATEN_320821 = 'Wasser, Brokkoli* 23 %, Möhren*, Rote Linsen*, Tofu* (Quellwasser, SOJABOHNEN* Gerinnungsmittel: Nigari [Magnesiumchlorid]), '
    .'Kokosmilch* (Kokosnussextrakt* 70%, Wasser), Zwiebeln*, MANDELN*, Gemüsebrühe* (Meersalz, Maisstärke*, Gemüse 9 % [Zwiebel*, SELLERIE*, '
    .'Lauch*, Karotte*, Petersilie*, Pastinake*], Liebstöckel*, Muskat*, Sonnenblumenöl*, Pfeffer*, Curcuma*), Sonnenblumenöl*, Meersalz, '
    .'Gewürze*, Kräuter* *aus kontrolliert biologischem Anbau Das Produkt kann Spuren von Gluten, Senf und Sesamsamen enthalten.';

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->anker = [];
    foreach ([
        'neutral' => 'Neutral', 'boiled_broccoli' => 'Brokkoli, gekocht', 'curry_powder' => 'Currypulver', 'tofu_2485' => 'Tofu',
        'coconut_milk' => 'Kokosmilch', 'coconut_cream' => 'Kokoscreme', 'red_curry_paste' => 'Rote Currypaste', 'green_curry_paste' => 'Grüne Currypaste',
        'carrot' => 'Karotte', 'boiled_carrot' => 'Karotte, gekocht', 'lentil' => 'Linse', 'puy_lentil' => 'Puy-Linse', 'green_lentil' => 'Grüne Linse',
        'brown_lentil' => 'Braune Linse', 'black_lentil' => 'Schwarze Linse', 'boiled_red_lentil' => 'Rote Linse, gekocht', 'almond' => 'Mandel',
        'onion' => 'Zwiebel', 'bulb_onion' => 'Speisezwiebel', 'boiled_pearl_onion' => 'Perlzwiebel, gekocht', 'caramelized_onion' => 'Karamellisierte Zwiebel',
        'maui_onion' => 'Maui-Zwiebel', 'oven_baked_red_onion' => 'Rote Zwiebel, im Ofen gebacken', 'onion_powder' => 'Zwiebelpulver',
    ] as $slug => $de) {
        $this->anker[$slug] = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId(['uuid' => (string) Str::uuid7(), 'slug' => $slug,
            'display_de' => $de, 'created_at' => now(), 'updated_at' => now()]);
    }
    GpAnkerVorauswahl::flush();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    // GP (WG 13) + optional Lead-LA mit Zutatenliste und Allergenen
    $this->curry = function (bool $mitLa = true, string $name = 'Curry: Brokkoli, frisch / (Planet V) / (Bio)') {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['commodity_group_code' => '13', 'main_ingredient_slug' => 'curry', 'status' => 'approved']);
        if ($mitLa) {
            $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id,
                'designation' => 'Planet V BIO Brokkoli-Curry  3,5kg', 'brand' => 'Planet V', 'qty' => 3.5, 'unit_code' => 'kg',
                'ingredients_supplier' => IO_ZUTATEN_320821]);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
            FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $la->id, 'allergen_soy' => 'enthalten',
                'allergen_tree_nuts' => 'enthalten', 'allergen_celery' => 'enthalten', 'allergen_gluten' => 'spuren', 'allergen_milk' => 'nicht_enthalten']);
            $gp->update(['lead_la_supplier_item_id' => $la->id]);
        }

        return $gp->refresh();
    };
    $this->dreiKernEinNeben = function ($gp) {
        $pairing = app(PairingService::class);
        foreach (['boiled_broccoli', 'curry_powder', 'tofu_2485'] as $slug) {
            $pairing->setGpAnker($this->rootTeam, $gp->id, $this->anker[$slug], 'kern');
        }
        $pairing->setGpAnker($this->rootTeam, $gp->id, $this->anker['coconut_milk'], 'neben');
    };
    $this->fangeKontext = function (array &$gefragt, string $antwort = 'carrot') {
        $this->mock(AiGatewayService::class, function ($mock) use (&$gefragt, $antwort) {
            $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext) use (&$gefragt, $antwort) {
                $gefragt[] = $kontext;

                return new AiProposal(['anchor_slug' => $antwort, 'art' => 'eigen'], 0.8, 'Mock', [], $key);
            });
        });
    };
});

afterEach(fn () => GpAnkerVorauswahl::flush());                          // Wortindex je Prozess nicht an den nächsten Test im Worker weitergeben

it('Hauptzutaten aus der io-Liste: Klammern, Prozente, Sternchen, Fußnote, Wasser/Salz/Öl/Gewürze/Kräuter raus, erste 8 in Reihenfolge', function () {
    expect(HauptzutatenAusListe::liste(IO_ZUTATEN_320821))
        ->toBe(['Brokkoli', 'Möhren', 'Rote Linsen', 'Tofu', 'Kokosmilch', 'Zwiebeln', 'Mandeln', 'Gemüsebrühe'])
        ->and(HauptzutatenAusListe::liste('Zutaten: Weizenmehl, Butter (18 %), Zucker, Eier, Säuerungsmittel: Citronensäure, E 330, Salz. Kann Spuren von Nüssen enthalten.'))
        ->toBe(['Weizenmehl', 'Butter', 'Eier'])
        ->and(HauptzutatenAusListe::liste('Milch, Salz, Käsereikulturen, mikrobielles Lab'))->toBe(['Milch'])
        ->and(HauptzutatenAusListe::liste(null))->toBe([])
        ->and(HauptzutatenAusListe::liste(''))->toBe([]);
});

it('Curry-Fall: die Vorauswahl trägt Kokosmilch, Karotte (Synonym Möhre), Rote Linse, Mandel, Zwiebel — ohne Leitartikel fehlen sie', function () {
    $svc = app(GpAnkerVorauswahl::class);
    $vorher = $svc->fuer(($this->curry)(false, 'Curry: Brokkoli, frisch / (Planet V)'), $this->rootTeam);
    $nachher = $svc->fuer(($this->curry)(true), $this->rootTeam);

    expect($vorher)->not->toContain('carrot')->not->toContain('boiled_red_lentil')->not->toContain('almond')->not->toContain('onion')
        ->not->toContain('coconut_milk')
        ->and($nachher)->toContain('coconut_milk')->toContain('carrot')->toContain('boiled_red_lentil')->toContain('almond')->toContain('onion')
        ->and(end($nachher))->toBe('neutral')
        ->and(count($nachher))->toBeLessThanOrEqual(GpAnkerVorauswahl::MAX + 1);
});

it('je Hauptzutat höchstens JE_ZUTAT Anker, mehr getroffene Wörter zuerst („Rote Linsen" → „Rote Linse, gekocht")', function () {
    $q = app(GpAnkerVorauswahl::class)->quellen(($this->curry)(true), $this->rootTeam);
    $zwiebeln = array_values(array_filter($q['artikel'], fn ($s) => str_contains($s, 'onion')));

    expect($q['artikel'])->toContain('boiled_red_lentil')
        ->and(count($zwiebeln))->toBeLessThanOrEqual(GpAnkerVorauswahl::JE_ZUTAT)
        ->and($q['artikel'])->toBe(collect($q['artikel'])->sort(SORT_STRING)->values()->all());   // nach Slug sortiert (Fingerabdruck)
});

it('Nebenanker (volle Kern-Grenze): Prompt trägt den Artikel-Block mit Hauptzutaten, Vokabular Karotte/Linse/Mandel/Zwiebel, keine vorhandenen', function () {
    $gp = ($this->curry)(true);
    ($this->dreiKernEinNeben)($gp);
    $gefragt = [];
    ($this->fangeKontext)($gefragt, 'carrot');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen')->assertSet('gpAnkerFehler', null);

    $k = $gefragt[0];
    expect($k['gesucht'] ?? '')->toContain('Nebenanker')
        ->and($k['artikel']['zutaten'])->toBe(['Brokkoli', 'Möhren', 'Rote Linsen', 'Tofu', 'Kokosmilch', 'Zwiebeln', 'Mandeln', 'Gemüsebrühe'])
        ->and($k['artikel']['bezeichnung'])->toBe('Planet V BIO Brokkoli-Curry 3,5kg')
        ->and($k['artikel']['marke'])->toBe('Planet V')
        ->and($k['artikel']['allergene'])->toBe(['Soja', 'Schalenfrüchte', 'Sellerie'])               // nur „enthalten", nicht Spuren
        ->and($k['artikel_hinweis'])->toContain('Reihenfolge des Anteils')
        ->and(mb_strlen(json_encode($k['artikel'], JSON_UNESCAPED_UNICODE)))->toBeLessThan(600)
        ->and($k['vokabular'])->toContain('carrot')->toContain('boiled_red_lentil')->toContain('almond')->toContain('onion')
        ->not->toContain('coconut_milk')->not->toContain('boiled_broccoli')->not->toContain('tofu_2485');
    $neben = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('anchor_id', $this->anker['carrot'])->whereNull('deleted_at')->first();
    expect($neben?->role)->toBe('neben');
});

it('Kern-Rolle: der Artikel-Block geht auch in den Kern-Vorschlag', function () {
    $gp = ($this->curry)(true);
    $gefragt = [];
    ($this->fangeKontext)($gefragt, 'boiled_broccoli');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen');

    expect($gefragt[0])->not->toHaveKey('gesucht')
        ->and($gefragt[0]['artikel']['zutaten'])->toContain('Möhren')
        ->and(array_keys($gefragt[0]))->toBe(['name', 'condition', 'commodity_group', 'artikel', 'artikel_hinweis', 'vokabular']);
});

it('Gegenprobe: GP ohne Leitartikel — Prompt byte-gleich wie bisher (kern und neben), Vorauswahl ohne Artikel-Quelle', function () {
    $gp = ($this->curry)(false);
    $gefragt = [];
    ($this->fangeKontext)($gefragt, 'neutral');
    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen');
    ($this->dreiKernEinNeben)($gp);
    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen');

    expect(array_keys($gefragt[0]))->toBe(['name', 'condition', 'commodity_group', 'vokabular'])
        ->and(array_keys($gefragt[1]))->toBe(['name', 'condition', 'commodity_group', 'gesucht', 'vorhandene_anker', 'vokabular'])
        ->and(app(GpAnkerVorauswahl::class)->quellen($gp, $this->rootTeam)['artikel'])->toBe([]);
});

it('Rohware mit Leitartikel: Stammdaten auch außerhalb WG 13, die Eigenschaft nach dem Doppelpunkt bleibt WG 13 vorbehalten (Mettenden)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Mettenden: geräuchert, frisch');
    $gp->update(['commodity_group_code' => '04']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => 'Mettenden geräuchert 10x100g',
        'qty' => 1, 'unit_code' => 'kg', 'ingredients_supplier' => 'Schweinefleisch, Speck, Nitritpökelsalz, Gewürze, Dextrose, Antioxidationsmittel: Ascorbinsäure']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    $s = app(GpAnkerVorauswahl::class)->artikelStammdaten($gp->refresh(), $this->rootTeam);

    expect($s['zutaten'])->toBe(['Schweinefleisch', 'Speck'])
        ->and($s)->not->toHaveKey('allergene')->not->toHaveKey('marke')
        ->and(app(GpAnkerVorauswahl::class)->quellen($gp, $this->rootTeam)['lexikalisch'])->not->toContain('caramelized_onion');
});
