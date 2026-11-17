<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 2026-10-12: „hier fehlen KI-Knöpfe" — fünf Stellen in drei Editoren. Jeder Knopf dockt an einen
 * bestehenden Dienst/Prompt an, füllt nur leere Felder (Override-First) und schreibt erst mit Speichern —
 * Ausnahme Aroma-Anker, der wie das Verknüpfen von Hand sofort gesetzt wird, mit Provenienz ai_inferred.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.bearbeitungssperre' => false]);     // Knöpfe direkt, ohne „Bearbeiten"-Klick
    $this->mockKi = function (array $antworten) {
        $this->mock(AiGatewayService::class, function ($mock) use ($antworten) {
            foreach ($antworten as $key => $werte) {
                $mock->shouldReceive('propose')->with($key, \Mockery::any(), \Mockery::any())
                    ->andReturn(new AiProposal($werte, 0.8, 'Mock', [], $key));
                $mock->shouldReceive('propose')->with($key, \Mockery::any())
                    ->andReturn(new AiProposal($werte, 0.8, 'Mock', [], $key));
            }
        });
    };
    // 17.11.2026 „Curry: Brokkoli": GP mit drei manuellen Kern-Ankern; liefert [GP, Anker-Ids nach Slug]
    $this->gpMitDreiKernAnkern = function (): array {
        $gp = $this->makeGp($this->rootTeam, 'Curry: Brokkoli, frisch / (Planet V) / (Bio)');
        $ids = [];
        foreach (['boiled_broccoli' => 'Brokkoli, gekocht', 'curry_powder' => 'Currypulver', 'tofu_2485' => 'Tofu', 'coconut_milk' => 'Kokosmilch'] as $slug => $de) {
            $ids[$slug] = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
                'uuid' => (string) Str::uuid7(), 'slug' => $slug, 'display_de' => $de, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['boiled_broccoli', 'curry_powder', 'tofu_2485'] as $slug) {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->setGpAnker($this->rootTeam, $gp->id, $ids[$slug], 'kern');
        }
        // Geschwister (gleiche Hauptzutat) tragen alle vier Anker als Kern → alle vier stünden in der Vorauswahl
        $gp->update(['main_ingredient_slug' => 'curry']);
        $geschwister = $this->makeGp($this->rootTeam, 'Curry: Kokos, frisch');
        $geschwister->update(['main_ingredient_slug' => 'curry']);
        foreach (['boiled_broccoli', 'curry_powder', 'coconut_milk'] as $slug) {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->setGpAnker($this->rootTeam, $geschwister->id, $ids[$slug], 'kern');
        }

        return [$gp, $ids];
    };
});

it('GP: „Verluste schätzen" füllt nur leere Vorgaben und schreibt nichts in die DB', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zwiebeln: frisch, ganz');
    ($this->mockKi)(['gp.verluste' => ['cooking_loss_default_pct' => 12, 'trimming_loss_default_pct' => 8.5, 'piece_default_g' => 150]]);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->set('defaults.piece_default_g', '10')                       // gepflegt = Entscheidung
        ->call('verlusteKiSchaetzen')
        ->assertSet('defaults.cooking_loss_default_pct', '12')
        ->assertSet('defaults.trimming_loss_default_pct', '8.5')
        ->assertSet('defaults.piece_default_g', '10')
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Speichern übernimmt'))
        ->assertSeeHtml('data-ki-action="verlusteKiSchaetzen"');

    expect($gp->fresh()->cooking_loss_default_pct)->toBeNull();      // Vorschlag allein schreibt nicht
});

it('GP: „Anker vorschlagen" setzt den Kern-Anker aus dem Vokabular mit Provenienz ai_inferred', function () {
    $gp = $this->makeGp($this->rootTeam, 'Basilikum: frisch');
    $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'slug' => 'basilikum', 'display_de' => 'Basilikum', 'display_en' => 'basil',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    ($this->mockKi)(['gp.anker' => ['anchor_slug' => 'basilikum']]);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->call('ankerKiVorschlagen')
        ->assertSet('gpAnkerFehler', null)
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'basilikum'))
        ->assertSeeHtml('data-ki-action="ankerKiVorschlagen"');

    $zeile = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->whereNull('deleted_at')->first();
    expect($zeile)->not->toBeNull()
        ->and((int) $zeile->anchor_id)->toBe($ankerId)
        ->and($zeile->source)->toBe('ai_inferred')
        ->and($zeile->role)->toBe('kern');
});

it('GP: „Anker vorschlagen" — neutral setzt nichts, sagt es aber', function () {
    $gp = $this->makeGp($this->rootTeam, 'Wasser: Leitung');
    ($this->mockKi)(['gp.anker' => ['anchor_slug' => 'neutral']]);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->call('ankerKiVorschlagen')
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'neutral'));
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->count())->toBe(0);
});

// ── 17.11.2026 „Curry: Brokkoli": 3 Kern-Anker gesetzt, „Anker vorschlagen" hat nicht geklappt ──────────────────────────────────

it('GP: „Anker vorschlagen" bei voller Kern-Grenze fragt nach einem NEBENANKER (vorhandene raus aus dem Vokabular) und setzt ihn als neben', function () {
    [$gp, $ids] = ($this->gpMitDreiKernAnkern)();
    $gefragt = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$gefragt) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext) use (&$gefragt) {
            $gefragt = $kontext;

            return new AiProposal(['anchor_slug' => 'coconut_milk', 'art' => 'eigen'], 0.8, 'Kokos prägt das Curry', [], $key);
        });
    });

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->call('ankerKiVorschlagen')
        ->assertSet('gpAnkerFehler', null)
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Nebenanker') && str_contains((string) $h, 'coconut_milk'));

    expect($gefragt['gesucht'] ?? '')->toContain('Nebenanker')
        ->and($gefragt['vorhandene_anker'])->toHaveCount(3)
        ->and($gefragt['vokabular'])->toContain('coconut_milk')->not->toContain('boiled_broccoli')->not->toContain('curry_powder')->not->toContain('tofu_2485');
    $zeile = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('anchor_id', $ids['coconut_milk'])->whereNull('deleted_at')->first();
    expect($zeile)->not->toBeNull()->and($zeile->role)->toBe('neben')->and($zeile->source)->toBe('ai_inferred');
    // die drei Kern-Anker von Hand bleiben unberührt
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('role', 'kern')->where('source', 'manual')->whereNull('deleted_at')->count())->toBe(3);
});

it('GP: „Anker vorschlagen" bei voller Kern-Grenze — neutral bzw. ein schon verknüpfter Anker melden sich, nichts still', function () {
    [$gp] = ($this->gpMitDreiKernAnkern)();
    ($this->mockKi)(['gp.anker' => ['anchor_slug' => 'neutral']]);
    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen')
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'keinen weiteren Aroma-Träger'));
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->whereNull('deleted_at')->count())->toBe(3);
});

it('GP: „Anker vorschlagen" nennt einen von Hand verknüpften Anker — Meldung „schon verknüpft" statt „gesetzt"', function () {
    $gp = $this->makeGp($this->rootTeam, 'Basilikum: frisch');
    $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'slug' => 'basilikum', 'display_de' => 'Basilikum', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(\Platform\FoodAlchemist\Services\PairingService::class)->setGpAnker($this->rootTeam, $gp->id, $ankerId, 'kern');
    ($this->mockKi)(['gp.anker' => ['anchor_slug' => 'basilikum']]);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen')
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'schon verknüpft'));
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->value('source'))->toBe('manual');
});

it('GP: „Anker vorschlagen" bei 3 Kern- und 6 Nebenankern fragt die KI gar nicht und sagt warum', function () {
    [$gp] = ($this->gpMitDreiKernAnkern)();
    foreach (range(1, 6) as $i) {
        $a = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId(['uuid' => (string) Str::uuid7(), 'slug' => "neben_{$i}", 'display_de' => "Neben {$i}", 'created_at' => now(), 'updated_at' => now()]);
        app(\Platform\FoodAlchemist\Services\PairingService::class)->setGpAnker($this->rootTeam, $gp->id, $a, 'neben');
    }
    $this->mock(AiGatewayService::class, fn ($mock) => $mock->shouldNotReceive('propose'));

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen')
        ->assertSet('gpAnkerFehler', fn ($f) => str_contains((string) $f, 'Alle Plätze belegt'));
});

it('Basisrezept: „Regeneration vorschlagen" füllt das Formular, gespeichert wird erst mit Speichern', function () {
    $geraet = DB::table('foodalchemist_vocab_regeneration_devices')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'slug' => 'konvektomat_t',
        'name' => 'Konvektomat (Test)', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $r = $this->makeRecipe($this->rootTeam, 'Jus: Kalb', ['status' => 'draft', 'preparation' => 'Reduzieren.']);
    ($this->mockKi)(['recipe.regeneration' => ['kalt' => false, 'geraet_id' => $geraet, 'temp_c' => 140, 'duration_min' => 12, 'core_temp_c' => null, 'note' => 'abgedeckt']]);

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)->call('tabLaden', 'regeneration')
        ->call('kiRegeneration')
        ->assertSet('regenForm.device_vocab_id', (string) $geraet)
        ->assertSet('regenForm.temp_c', '140')
        ->assertSet('regenForm.duration_min', '12')
        ->assertSet('regenForm.note', 'abgedeckt')
        ->assertSet('regenMeldung', fn ($m) => str_contains((string) $m, 'Noch nicht gespeichert'))
        ->assertSeeHtml('data-ki-action="kiRegeneration"');

    expect(DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $r->id)->count())->toBe(0);
});

it('Basisrezept: „kalt servieren" ist eine Entscheidung — Gerät leer, Zahlen leer', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Dressing: Vinaigrette', ['status' => 'draft']);
    ($this->mockKi)(['recipe.regeneration' => ['kalt' => true, 'geraet_id' => null]]);

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)->call('tabLaden', 'regeneration')
        ->set('regenForm.temp_c', '90')
        ->call('kiRegeneration')
        ->assertSet('regenForm.device_vocab_id', '')
        ->assertSet('regenForm.temp_c', '')
        ->assertSet('regenMeldung', fn ($m) => str_contains((string) $m, 'kalt servieren'));
});

it('Gericht: „Einordnung vorschlagen" setzt Klasse, Hauptgruppe, Geschmack und Fertigungstiefe — nur leere Felder', function () {
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgang', 'is_inactive' => false]);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => null, 'code' => 'FLEISCH', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-einordnung', 'name' => '[HG] Senfrostbraten | Jus',
        'status' => 'draft', 'is_sales_recipe' => true, 'taste_direction' => 'herzhaft',   // Geschmack gepflegt
    ]);
    ($this->mockKi)([
        'vk.speisen_klasse' => ['dish_class_id' => $klasse->id, 'klasse_name' => 'Fleisch', 'dish_main_group_id' => $hg->id],
        'recipe.geschmack' => ['taste_direction' => 'suess'],
        'recipe.production_depth' => ['production_depth' => 'teilfertig'],
    ]);

    Livewire::test(VkModal::class)->call('oeffnen', $vk->id)
        ->call('ki', 'einordnung')
        ->assertSet('form.dish_class_id', (string) $klasse->id)
        ->assertSet('form.dish_main_group_id', (string) $hg->id)
        ->assertSet('form.taste_direction', 'herzhaft')                 // gepflegt bleibt
        ->assertSet('form.production_depth', 'teilfertig')
        ->assertSet('kiMeldung', fn ($m) => str_contains((string) $m, 'Klasse Fleisch') && str_contains((string) $m, 'Hauptgruppe'))
        ->assertSeeHtml('data-ki-einordnung');

    expect($vk->fresh()->dish_class_id)->toBeNull();                 // Speichern übernimmt
});

it('Gericht: „Behälter vorschlagen" füllt Behälter je Zweck, Skalierung und Referenzmenge — nur leere Felder', function () {
    $gn = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65', 'name' => 'GN 1/1 65mm', 'sort_order' => 1, 'familie' => 'GN',
        'laenge_mm' => 530, 'breite_mm' => 325, 'tiefe_mm' => 65, 'volumen_l' => 8.8,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-behaelter', 'name' => '[HG] Gulasch', 'status' => 'draft',
        'is_sales_recipe' => true, 'yield_kg' => 10,
    ]);
    ($this->mockKi)(['recipe.dichteklasse' => [
        'dichteklasse' => 'dicht', 'skalierung' => 'tiefer_fuellbar',
        'behaelter_je_zweck' => ['abfuellen' => $gn, 'regenerieren' => $gn, 'transport' => 999999],
        'referenz_menge_kg_je_zweck' => ['abfuellen' => 7.5],
    ]]);

    Livewire::test(VkModal::class)->call('oeffnen', $vk->id)
        ->set('behaelterForm.regenerieren.skalierung', 'lagenware')     // von Hand entschieden
        ->call('kiBehaelter')
        ->assertSet('behaelterForm.abfuellen.container_vocab_id', (string) $gn)
        ->assertSet('behaelterForm.abfuellen.skalierung', 'tiefer_fuellbar')
        ->assertSet('behaelterForm.abfuellen.referenz_menge_kg', '7,5')
        ->assertSet('behaelterForm.regenerieren.skalierung', 'lagenware')
        ->assertSet('behaelterForm.transport.container_vocab_id', '')   // unbekannte Id verworfen
        ->assertSet('kiMeldung', fn ($m) => str_contains((string) $m, 'GN 1/1 65mm'))
        ->assertSeeHtml('data-ki-action="kiBehaelter"');

    expect(DB::table('foodalchemist_recipe_containers')->where('recipe_id', $vk->id)->count())->toBe(0);
});

/**
 * Dominique 2026-10-13: „nimmt der da nur den Zustand?" — der KI-Vorschlag der Einordnung betraf nur den Zustand.
 * Der neue Knopf setzt Warengruppe + Unterkategorie (streng aus der eigenen Taxonomie) und Zustand ins Formular.
 */
it('GP: „Einordnung vorschlagen" setzt Warengruppe, Unterkategorie und Zustand aus der Taxonomie — Speichern übernimmt', function () {
    $wg = \Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '15', 'name' => '15 Getraenke', 'sort_order' => 15]);
    \Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie::create(['team_id' => $this->rootTeam->id, 'commodity_group_code' => '15', 'name' => '15.2 Bier & Cidre', 'position' => 2]);
    $gp = $this->makeGp($this->rootTeam, 'Altbier: konserviert');
    ($this->mockKi)(['gp.suggest' => ['commodity_group_code' => '15', 'sub_category' => '15.2 bier & cidre', 'condition' => 'konserviert']]);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->call('einordnungKiVorschlagen')
        ->assertSet('builder.commodity_group_code', '15')
        ->assertSet('builder.sub_category', '15.2 Bier & Cidre')              // Schreibweise aus der Taxonomie
        ->assertSet('builder.condition', 'konserviert')
        ->assertSet('einordnungHinweis', fn ($h) => str_contains((string) $h, 'Speichern übernimmt'))
        ->assertSeeHtml('data-ki-action="einordnungKiVorschlagen"');

    expect($gp->fresh()->commodity_group_code)->not->toBe('15');            // Vorschlag allein schreibt nicht
});

it('Einstellungen: Zeilen-Aktionen nutzen die ungeschichtete Modul-Klasse, nicht group-hover-Utilities (demo-Ebenen)', function () {
    foreach (['warengruppen', 'vk-taxonomie', 'taxonomie'] as $view) {
        $s = file_get_contents(dirname(__DIR__, 2)."/resources/views/livewire/settings/{$view}.blade.php");
        expect($s)->toContain('fa-zeilen-aktionen')->not->toContain('group-hover:pointer-events-auto');
    }
    expect(file_get_contents(dirname(__DIR__, 2).'/resources/dist/foodalchemist.css'))->toMatch('/\.group:hover>\.fa-zeilen-aktionen/');
});
