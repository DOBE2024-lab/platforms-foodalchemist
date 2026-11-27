<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (KI-Strang c) PR0 — Charakterisierung von PairingService vor dem Zerlegen. Ergänzt PairingNetzTest und
 * KombinationslogikTest (fachliche Aussagen) um den vollständigen Ist-Stand der öffentlichen Methoden je Bereich, auf
 * EINEM Anker-Graphen: Slug/Anker-Auflösung, Rezept-Anker, Kohäsion und Menü, Suggest, Nachbarn/Bridge, GP-Anker-
 * Schreibpfade, Panels, Pairing-Netz (Gericht, Basisrezept, Composer), aroma-treue Substitution.
 *
 * Zeitstempel und UUIDs sind ausgeblendet, sonst bit-gleich. Ändert ein Schnitt hier etwas, war er nicht mechanisch.
 * Neu schreiben nur mit Begründung: PAIRING_GOLDEN_SCHREIBEN=1.
 */
const PAIRING_CHARAKTER_GOLDEN = __DIR__.'/../Fixtures/golden/pairing_charakter.json';

function pairingCharakterNormal(mixed $wert): mixed
{
    if ($wert instanceof \Illuminate\Support\Collection) {
        $wert = $wert->all();
    }
    if ($wert instanceof \Illuminate\Database\Eloquent\Model) {
        $wert = $wert->attributesToArray();
    }
    if (is_object($wert)) {
        $wert = (array) $wert;
    }
    if (! is_array($wert)) {
        return $wert;
    }
    $aus = [];
    foreach ($wert as $k => $v) {
        if (is_string($k) && (str_ends_with($k, '_at') || $k === 'uuid')) {
            continue;
        }
        $aus[$k] = pairingCharakterNormal($v);
    }

    return $aus;
}

function pairingCharakterGolden(string $schluessel, mixed $ist): void
{
    $ist = json_decode(json_encode(pairingCharakterNormal($ist), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
    $alle = is_file(PAIRING_CHARAKTER_GOLDEN) ? json_decode((string) file_get_contents(PAIRING_CHARAKTER_GOLDEN), true) : [];
    if (getenv('PAIRING_GOLDEN_SCHREIBEN')) {
        $alle[$schluessel] = $ist;
        ksort($alle);
        file_put_contents(PAIRING_CHARAKTER_GOLDEN, json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        test()->markTestSkipped('Golden neu geschrieben: '.$schluessel);
    }
    expect($alle)->toHaveKey($schluessel);
    expect($ist)->toBe($alle[$schluessel]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root'));
    $this->svc = app(PairingService::class);

    $anker = function (string $slug, string $display) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $display,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $kante = function (int $a, int $b, string $typ, ?int $level = null) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            \Platform\FoodAlchemist\Tests\Support\Harmonie::ausFixture(['anchor_a_id' => $x, 'anchor_b_id' => $y, 'type' => $typ, 'level' => $level]);
        }
    };
    $this->a = [];
    foreach (['kichererbse' => 'Kichererbse', 'tahin' => 'Tahin', 'knoblauch' => 'Knoblauch', 'granatapfel' => 'Granatapfel',
        'minze' => 'Minze', 'zitrone' => 'Zitrone', 'tomate' => 'Tomate', 'basilikum' => 'Basilikum', 'neutral' => 'Neutral'] as $slug => $d) {
        $this->a[$slug] = $anker($slug, $d);
    }
    $kante($this->a['kichererbse'], $this->a['knoblauch'], 'aroma', 3);
    $kante($this->a['tahin'], $this->a['knoblauch'], 'aroma', 3);
    $kante($this->a['kichererbse'], $this->a['granatapfel'], 'aroma', 2);
    $kante($this->a['tahin'], $this->a['minze'], 'aroma', 2);
    $kante($this->a['tahin'], $this->a['zitrone'], 'aroma', 3);
    $kante($this->a['tomate'], $this->a['basilikum'], 'erprobt');
    $kante($this->a['tomate'], $this->a['knoblauch'], 'aroma', 2);

    $rezept = fn (string $key, string $name, bool $vk = false) => FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id,
        'recipe_key' => $key, 'name' => $name, 'status' => 'approved', 'is_sales_recipe' => $vk]);
    $this->hummus = $rezept('hummus', 'Creme: Hummus');
    \Platform\FoodAlchemist\Tests\Support\RezeptAnker::gib($this->hummus, $this->a['kichererbse']);
    \Platform\FoodAlchemist\Tests\Support\RezeptAnker::gib($this->hummus, $this->a['tahin']);
    $this->aioli = $rezept('aioli', 'Sauce: Aioli');
    \Platform\FoodAlchemist\Tests\Support\RezeptAnker::gib($this->aioli, $this->a['knoblauch']);
    $this->sugo = $rezept('sugo', 'Sauce: Tomatensugo');
    \Platform\FoodAlchemist\Tests\Support\RezeptAnker::gib($this->sugo, $this->a['tomate']);
    \Platform\FoodAlchemist\Tests\Support\RezeptAnker::gib($this->sugo, $this->a['knoblauch']);
    $this->teller = $rezept('teller', '[HG] Orientteller', true);

    $this->gpTahin = $this->makeGp($this->rootTeam, 'Tahin: Sesammus');
    $this->gpSesam = $this->makeGp($this->rootTeam, 'Sesampaste: hell');
    $this->gpTomate = $this->makeGp($this->rootTeam, 'Tomaten: frisch');
});

it('Slug- und Anker-Auflösung', function () {
    $s = $this->svc;
    pairingCharakterGolden('aufloesung', [
        'normalizeAnkerSlug' => [$s->normalizeAnkerSlug('Knob-Lauch Zehe'), $s->normalizeAnkerSlug(' Granat_apfel ')],
        'ankerSlugMatches' => [$s->ankerSlugMatches('knoblauch', 'Knoblauch'), $s->ankerSlugMatches('tomate', 'tomaten'), $s->ankerSlugMatches('minze', 'zitrone')],
        'fold' => [$s->fold('Größe Äpfel'), $s->fold('  Crème  ')],
        'resolveByName' => [$s->resolveByName('Knoblauch'), $s->resolveByName('Tomaten, frisch'), $s->resolveByName('Gibtsnicht')],
        'lexicalAnkerKandidaten' => $s->lexicalAnkerKandidaten('Tomate mit Basilikum', 5),
        'ankerSlugExakt' => [$s->ankerSlugExakt('tomate'), $s->ankerSlugExakt('Tomaten'), $s->ankerSlugExakt('nix')],
        'ankerIdExakt' => [$s->ankerIdExakt('Knoblauch'), $s->ankerIdExakt(null), $s->ankerIdExakt('nix')],
        'ankerAufgeloest' => [$s->ankerAufgeloest('Minze'), $s->ankerAufgeloest(null), $s->ankerAufgeloest('Zitronen')],
        'neutralAnker' => $s->neutralAnker() === $this->a['neutral'],
        'neighborsForName' => [$s->neighborsForName('tahin'), $s->neighborsForName('Tomate', null, 3), $s->neighborsForName('nix')],
        'ankerNeighbors' => [$s->ankerNeighbors('knoblauch'), $s->ankerNeighbors('tahin', null, 2)],
    ]);
});

it('Rezept-Anker, Kohäsion, Menü, Suggest, Bridge', function () {
    $s = $this->svc;
    $komp = $s->resolveRecipeAnchors($this->hummus);
    pairingCharakterGolden('rezept_kohaesion_menue', [
        'resolveRecipeAnchors' => $komp,
        'resolveRecipeAnchorsMany' => $s->resolveRecipeAnchorsMany([$this->hummus, $this->sugo, $this->teller]),
        'anchorsForRecipe' => $s->anchorsForRecipe($this->sugo),
        'flacheAnker' => $s->flacheAnker($komp),
        'cohesionFor' => $s->cohesionFor($komp),
        'recipeCohesion' => [$s->recipeCohesion($this->hummus), $s->recipeCohesion($this->teller)],
        'edgesFor' => $s->edgesFor([$this->a['kichererbse'], $this->a['tahin'], $this->a['knoblauch'], $this->a['tomate']]),
        'menuRepetitions' => [$s->menuRepetitions([$this->aioli->id, $this->sugo->id]), $s->menuRepetitions([$this->aioli->id])],
        'menuCohesion' => $kh = $s->menuCohesion([$this->hummus, $this->aioli, $this->sugo]),
        'menuKohaesionWarnung' => [$s->menuKohaesionWarnung($kh), $s->menuKohaesionWarnung(['score' => 10, 'kanten' => 0])],
        'menueAromaProfil' => [$s->menueAromaProfil([$this->hummus, $this->sugo], 4), $s->menueAromaProfil([])],
        'componentSuggestions' => $s->componentSuggestions($this->hummus, 4),
        'passtZurDiaet' => [$s->passtZurDiaet((object) ['slug' => 'knoblauch'], 'vegan'), $s->passtZurDiaet((object) ['slug' => 'kombu_dashi'], 'vegan')],
        'recipesSharingPairings' => $s->recipesSharingPairings($this->rootTeam, $this->hummus->id, 1, 5),
    ]);
});

it('GP-Anker-Schreibpfade und Lesewege', function () {
    $s = $this->svc;
    $gp = $this->gpTahin->id;
    $s->setGpAnker($this->rootTeam, $gp, $this->a['tahin']);
    $s->setGpAnker($this->rootTeam, $gp, $this->a['zitrone'], 'neben', 'bridge_alt_neu+exact', 0.8, 'Test');
    $s->setGpAnkerStaerke($this->rootTeam, $gp, $this->a['tahin'], 'stark');
    $inferenz = [$s->setGpAnkerInference($this->rootTeam, $this->gpSesam->id, $this->a['tahin'], 0.91, 'KI'),
        $s->setGpAnkerInference($this->rootTeam, $this->gpSesam->id, $this->a['tahin'], 0.5, 'KI nochmal')];
    $s->setGpAnker($this->rootTeam, $this->gpTomate->id, $this->a['tomate']);
    $vorher = ['gpAnkers' => $s->gpAnkers($gp), 'gpAnkerAlle' => $s->gpAnkerAlle($gp)];
    $s->removeGpAnker($this->rootTeam, $gp, $this->a['zitrone']);
    $nachRemove = $s->gpAnkerAlle($gp);
    $fehler = null;
    try {
        $s->setGpAnker($this->rootTeam, $gp, $this->a['minze'], 'kern', str_repeat('x', 40));
    } catch (\RuntimeException $e) {
        $fehler = $e->getMessage();
    }

    pairingCharakterGolden('gp_anker', [
        'vorher' => $vorher, 'inferenz' => $inferenz, 'nach_remove' => $nachRemove, 'zu_lange_quelle' => $fehler,
        'gpKernAnker' => $s->gpKernAnker([$gp, $this->gpSesam->id, $this->gpTomate->id]),
        'gpAnkerZuordnungen' => $s->gpAnkerZuordnungen([$gp, $this->gpSesam->id]),
        'gpsForAnkerIds' => $s->gpsForAnkerIds($this->rootTeam, [$this->a['tahin'], $this->a['tomate']])->map(fn ($g) => [$g->id, $g->name])->all(),
        'darfAnkerPflegen' => $s->darfAnkerPflegen($this->gpTahin->refresh(), $this->rootTeam),
        'panelGp' => $s->panelGp($gp),
        'aromaTrueSubstitutes' => $s->aromaTrueSubstitutes($this->rootTeam, $gp, 5),
        'clear' => (function () use ($s, $gp) {
            $s->clearGpAnker($this->rootTeam, $gp);

            return $s->gpAnkerAlle($gp);
        })(),
    ]);
});

it('Panels, Pairing-Netz und Composer', function () {
    $s = $this->svc;
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->teller->id,
        'position' => 1, 'raw_text' => 'Creme: Hummus', 'referenced_recipe_id' => $this->hummus->id, 'quantity' => 100,
        'unit_vocab_id' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
            ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1])->id]);
    $ids = [$this->a['kichererbse'], $this->a['tahin']];

    pairingCharakterGolden('netz', [
        'panelRecipe' => [$s->panelRecipe($this->hummus), $s->panelRecipe($this->teller)],
        'pairingNetz_basis' => $s->pairingNetz($this->rootTeam, $this->hummus->id),
        'pairingNetz_gericht' => $s->pairingNetz($this->rootTeam, $this->teller->id),
        'pairingNetzForAnkers' => $s->pairingNetzForAnkers($this->rootTeam, $ids, 'Probe'),
        'composerCohesion' => [$s->composerCohesion($ids), $s->composerCohesion([])],
        'composerAnkerBrowse' => [$s->composerAnkerBrowse($this->rootTeam, '', null, $ids, 50),
            $s->composerAnkerBrowse($this->rootTeam, 'to', null, [], 10, $this->a['tomate'])],
    ]);
});
