<?php

use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\KeinPlatzhalterException;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Services\GpStamm\GpAggregateService;
use Platform\FoodAlchemist\Services\GpStamm\GpService;
use Platform\FoodAlchemist\Services\GpStamm\PlatzhalterDeklarationService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Platzhalter-GPs: Allergene, Zusatzstoffe und Nährwerte von Hand (Entscheid Dominique 19.11.2026: „… händisch setzen. Aber nur bei Platzhalter.“).
 * Befund io: „Leitungswasser (neutral)“ ohne Artikel → 14 von 14 Allergenen ohne Angabe, Konfidenz none, 18 Zusatzstoffe ohne Angabe, keine
 * Nährwerte; jedes Rezept mit Wasser auf LOW gedeckelt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->wasser = app(GpService::class)->createPlatzhalter($this->rootTeam, 'Leitungswasser');
    $this->dienst = app(PlatzhalterDeklarationService::class);
    $this->agg = app(GpAggregateService::class);
    $this->wieWasser = [
        'alle_allergene' => 'nicht_enthalten', 'alle_zusatzstoffe' => 'nein',
        'naehrwerte' => ['kcal' => 0, 'protein_g' => 0, 'fat_g' => 0, 'saturated_fat_g' => 0, 'carbs_g' => 0, 'sugar_g' => 0, 'salt_g' => 0],
    ];
});

it('Ausgangslage: Platzhalter ohne Werte hat Konfidenz none, 14 Allergene unbekannt, Zusatzstoffe und Nährwerte leer', function () {
    expect($this->agg->allergenKonfidenz($this->wasser)['confidence'])->toBe('none')
        ->and(collect($this->agg->allergene($this->wasser))->every(fn ($a) => $a['value']->value === 'unbekannt'))->toBeTrue()
        ->and(array_filter($this->agg->zusatzstoffe($this->wasser), fn ($v) => $v !== null))->toBe([])
        ->and($this->agg->naehrwerte($this->wasser, mitKiFallback: true)['energy_kcal']['avg'])->toBeNull();
});

it('Platzhalter: Werte von Hand gesetzt → belegt (high, Quelle manuell), Zusatzstoffe nein, Nährwerte 0, Quelle manual', function () {
    $r = $this->dienst->setzen($this->rootTeam, $this->wasser->id, $this->wieWasser);
    $gp = $this->wasser->fresh();

    expect($r['allergene_vollstaendig'])->toBeTrue()->and($r['zusatzstoffe_vollstaendig'])->toBeTrue()->and($r['naehrwerte_vollstaendig'])->toBeTrue()
        ->and($gp->allergens_source)->toBe('manual')->and($gp->nutri_source)->toBe('manual')
        ->and($gp->allergens_confidence)->toBeNull();   // live gerechnet, keine Zahl (sonst ✨-KI-Marker)

    $konf = $this->agg->allergenKonfidenz($gp);
    expect($konf['confidence'])->toBe('high')->and($konf['quelle'])->toBe('manuell')->and($konf['needs_review'])->toBeFalse();
    foreach ($this->agg->allergene($gp) as $a) {
        expect($a['value']->value)->toBe('nicht_enthalten')->and($a['source'])->toBe('override');
    }
    expect(array_unique(array_values($this->agg->zusatzstoffe($gp))))->toBe([1]);
    $n = $this->agg->naehrwerte($gp, mitKiFallback: true);
    expect($n['energy_kcal']['avg'])->toBe(0.0)->and($n['sugar']['avg'])->toBe(0.0)->and($n['saturated_fat']['avg'])->toBe(0.0)->and($n['source'])->toBe('manual');
});

it('Platzhalter teilweise gepflegt: Konfidenz bleibt none (nur 14 von 14 gilt als belegt)', function () {
    $this->dienst->setzen($this->rootTeam, $this->wasser->id, ['allergene' => ['gluten' => 'nicht_enthalten', 'milk' => 'nicht_enthalten']]);

    expect($this->agg->allergenKonfidenz($this->wasser->fresh())['confidence'])->toBe('none');
});

it('Teil-Update ändert nur übergebene Felder; leer leert ein Feld', function () {
    $this->dienst->setzen($this->rootTeam, $this->wasser->id, $this->wieWasser);
    $r = $this->dienst->setzen($this->rootTeam, $this->wasser->id, ['allergene' => ['sulphites' => 'spuren', 'milk' => ''], 'zusatzstoffe' => ['with_dye' => 'ja', 'waxed' => null]]);

    expect($r['allergene']['sulphites'])->toBe('spuren')->and($r['allergene']['milk'])->toBeNull()->and($r['allergene']['gluten'])->toBe('nicht_enthalten')
        ->and($r['zusatzstoffe']['with_dye'])->toBe('ja')->and($r['zusatzstoffe']['waxed'])->toBeNull()->and($r['zusatzstoffe']['blackened'])->toBe('nein')
        ->and($r['naehrwerte']['kcal'])->toBe(0.0)
        ->and($this->agg->allergenKonfidenz($this->wasser->fresh())['confidence'])->toBe('none');
});

it('ungültige Werte → RuntimeException, nichts geschrieben', function () {
    expect(fn () => $this->dienst->setzen($this->rootTeam, $this->wasser->id, ['allergene' => ['gluten' => 'vielleicht']]))->toThrow(RuntimeException::class)
        ->and(fn () => $this->dienst->setzen($this->rootTeam, $this->wasser->id, ['naehrwerte' => ['kcal' => -1]]))->toThrow(RuntimeException::class)
        ->and(fn () => $this->dienst->setzen($this->rootTeam, $this->wasser->id, ['zusatzstoffe' => ['erfunden' => 'ja']]))->toThrow(RuntimeException::class);
    expect($this->wasser->fresh()->allergenOverrides())->toBe([]);
});

it('Nicht-Platzhalter: Dienst lehnt typisiert ab (KeinPlatzhalterException), nichts geschrieben, Aggregation unverändert', function () {
    $mehl = $this->makeGp($this->rootTeam, 'Weizenmehl Type 405');

    expect(fn () => $this->dienst->setzen($this->rootTeam, $mehl->id, $this->wieWasser))->toThrow(KeinPlatzhalterException::class);
    $mehl->refresh();
    expect($mehl->allergenOverrides())->toBe([])->and($mehl->nutri_source)->toBeNull()->and($mehl->deklaration_manuell)->toBeNull();

    // Eine (fremd geschriebene) Hand-Zusatzstoff-Zeile wirkt bei Nicht-Platzhaltern nicht
    $mehl->forceFill(['deklaration_manuell' => ['with_dye' => 3]])->save();
    expect($this->agg->zusatzstoffe($mehl->fresh())['with_dye'])->toBeNull();
    // Vollständige Overrides an einem Nicht-Platzhalter machen keine Platzhalter-Konfidenz
    $mehl->update(array_combine(array_map(fn ($f) => "allergen_{$f}", FoodAlchemistGp::ALLERGEN_FIELDS), array_fill(0, 14, 'nicht_enthalten')));
    expect($this->agg->allergenKonfidenz($mehl->fresh())['confidence'])->toBe('none')
        ->and($this->agg->allergenKonfidenz($mehl->fresh()))->not->toHaveKey('quelle');
});

it('fremdes Team: Platzhalter eines anderen Teams nicht setzbar', function () {
    expect(fn () => $this->dienst->setzen($this->childA, $this->wasser->id, $this->wieWasser))->toThrow(RuntimeException::class);
    expect($this->wasser->fresh()->allergenOverrides())->toBe([]);
});

it('Rezept übernimmt Allergene, Zusatzstoffe, Nährwerte und Konfidenz vom gepflegten Platzhalter (Neuberechnung durch den Dienst)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Gemüsefond');
    $this->makeIngredient($rezept, 'Wasser', $this->wasser, '1000');
    app(RecipeRecomputeService::class)->recomputePipeline($rezept->id);
    $vorher = $rezept->fresh();
    expect($vorher->allergen_gluten)->toBe('unbekannt')->and($vorher->allergens_confidence)->not->toBe('high')
        ->and($vorher->nutri_kcal_per_100g)->toBeNull();

    $r = $this->dienst->setzen($this->rootTeam, $this->wasser->id, $this->wieWasser);
    $nachher = $rezept->fresh();

    expect($r['rezepte_neu_gerechnet'])->toBeGreaterThanOrEqual(1);
    foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $f) {
        expect($nachher->{"allergen_{$f}"})->toBe('nicht_enthalten');
    }
    expect($nachher->allergens_confidence)->toBe('high')
        ->and($nachher->spec_is_gluten_free)->toBeTrue()
        ->and((int) $nachher->additive_with_dye)->toBe(1)
        ->and((float) $nachher->nutri_kcal_per_100g)->toBe(0.0)
        ->and($nachher->nutri_confidence)->toBe('high');
});

it('Rezept: von Hand „enthalten“ am Platzhalter geht ALL-MAXIMAL ins Rezept', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Sud');
    $this->makeIngredient($rezept, 'Wasser', $this->wasser, '500');
    $this->dienst->setzen($this->rootTeam, $this->wasser->id, [...$this->wieWasser, 'allergene' => ['sulphites' => 'enthalten'], 'zusatzstoffe' => ['with_preservative' => 'ja']]);
    $r = $rezept->fresh();

    expect($r->allergen_sulphites)->toBe('enthalten')->and($r->allergen_gluten)->toBe('nicht_enthalten')
        ->and((int) $r->additive_with_preservative)->toBe(3);
});

it('GP-Detail: Platzhalter zeigt den Bereich, speichert über den Dienst, danach keine Warnungen mehr', function () {
    $lw = Livewire::test(DetailPanel::class, ['gpId' => $this->wasser->id])
        ->assertSeeHtml('data-sektion="platzhalter-deklaration"')
        ->assertSee('Platzhalter haben keine Lieferantenartikel, Werte werden hier gepflegt.')
        ->assertSee('14 von 14 Allergenen ohne Angabe.')->assertSee('Nährwerte fehlen.');

    $lw->call('bearbeitenStarten')->call('platzhalterAllesFreiVorbelegen');
    foreach (array_keys(PlatzhalterDeklarationService::NAEHRWERTE) as $k) {
        $lw->set("platzhalterForm.naehrwerte.{$k}", '0');
    }
    $lw->call('platzhalterDeklarationSpeichern')->assertSet('platzhalterFehler', null)
        ->assertDontSee('von 14 Allergenen ohne Angabe')->assertDontSee('Nährwerte fehlen.')
        ->assertDontSeeHtml('data-allergene-unbekannt')->assertDontSeeHtml('data-zusatz-unbekannt')->assertDontSeeHtml('data-allergene-ki-marker')
        ->assertSeeHtml('data-allergene-platzhalter-manuell')->assertSee('Allergene von Hand gepflegt (Platzhalter)')
        ->assertSeeHtml('data-zusatz-frei');

    expect($this->agg->allergenKonfidenz($this->wasser->fresh())['confidence'])->toBe('high');
});

it('GP-Detail: Nicht-Platzhalter ohne Bereich; der Aufruf am Nicht-Platzhalter wird vom Dienst abgewiesen', function () {
    $mehl = $this->makeGp($this->rootTeam, 'Weizenmehl Type 405');
    $lw = Livewire::test(DetailPanel::class, ['gpId' => $mehl->id])->assertDontSeeHtml('data-sektion="platzhalter-deklaration"');

    $lw->call('bearbeitenStarten')->set('platzhalterForm', ['allergene' => ['gluten' => 'nicht_enthalten']])->call('platzhalterDeklarationSpeichern')
        ->assertSet('platzhalterFehler', fn ($f) => is_string($f) && $f !== '');
    expect($mehl->fresh()->allergenOverrides())->toBe([]);
});

it('MCP: platzhalter_deklaration.PUT setzt am Platzhalter, lehnt Nicht-Platzhalter mit NOT_PLACEHOLDER ab; gps.GET liest mit', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $ok = $reg->get('foodalchemist.platzhalter_deklaration.PUT')->execute(['id' => $this->wasser->id, ...$this->wieWasser], $ctx);
    expect($ok->success)->toBeTrue()->and($ok->data['allergene_vollstaendig'])->toBeTrue();

    $get = $reg->get('foodalchemist.gps.GET')->execute(['id' => $this->wasser->id], $ctx);
    expect($get->data['platzhalter_deklaration']['allergene']['gluten'])->toBe('nicht_enthalten')
        ->and($get->data['platzhalter_deklaration']['naehrwerte']['kcal'])->toBe(0.0)
        ->and($get->data['allergen_konfidenz'])->toBe('high');

    $mehl = $this->makeGp($this->rootTeam, 'Weizenmehl Type 405');
    $nein = $reg->get('foodalchemist.platzhalter_deklaration.PUT')->execute(['id' => $mehl->id, 'alle_allergene' => 'nicht_enthalten'], $ctx);
    expect($nein->success)->toBeFalse()->and($nein->errorCode)->toBe('NOT_PLACEHOLDER')
        ->and($mehl->fresh()->allergenOverrides())->toBe([])
        ->and($reg->get('foodalchemist.gps.GET')->execute(['id' => $mehl->id], $ctx)->data['platzhalter_deklaration'])->toBeNull();

    expect($reg->get('foodalchemist.platzhalter_deklaration.PUT')->execute(['id' => $this->wasser->id, 'allergene' => ['gluten' => 'quatsch']], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($reg->get('foodalchemist.platzhalter_deklaration.PUT')->execute(['id' => 999999, 'alle_allergene' => 'nicht_enthalten'], $ctx)->errorCode)->toBe('NOT_FOUND');
});

it('Vorbelegung: Trockenlauf schreibt nichts, --apply nur mit Backup, belegt nur Leitungswasser, --zurueck stellt her', function () {
    $fett = app(GpService::class)->createPlatzhalter($this->rootTeam, 'Fett');
    $rezept = $this->makeRecipe($this->rootTeam, 'Brühe');
    $this->makeIngredient($rezept, 'Wasser', $this->wasser, '1000');
    $bericht = storage_path('framework/testing/platzhalter-vorbelegen-' . uniqid());

    $this->artisan('foodalchemist:platzhalter-deklaration-vorbelegen', ['--bericht' => $bericht])->assertSuccessful();
    expect($this->wasser->fresh()->allergenOverrides())->toBe([]);

    $this->artisan('foodalchemist:platzhalter-deklaration-vorbelegen', ['--apply' => true, '--bericht' => $bericht])->assertFailed();
    expect($this->wasser->fresh()->allergenOverrides())->toBe([]);

    $backup = $bericht . '/dump.sql';
    File::ensureDirectoryExists($bericht);
    file_put_contents($backup, str_repeat('x', 2048));
    $this->artisan('foodalchemist:platzhalter-deklaration-vorbelegen', ['--apply' => true, '--backup-nachweis' => $backup, '--bericht' => $bericht])->assertSuccessful();

    $w = $this->wasser->fresh();
    expect(count($w->allergenOverrides()))->toBe(14)->and($w->nutri_source)->toBe('manual')
        ->and(count(PlatzhalterDeklarationService::zusatzstoffeManuell($w)))->toBe(count(FoodAlchemistItemDeclaration::STOFFE))
        ->and($fett->fresh()->allergenOverrides())->toBe([])->and($fett->fresh()->nutri_source)->toBeNull()
        ->and($rezept->fresh()->allergens_confidence)->toBe('high');

    // zweiter Lauf: schon gepflegt, bleibt
    $this->artisan('foodalchemist:platzhalter-deklaration-vorbelegen', ['--bericht' => $bericht])->expectsOutputToContain('Vorbelegen: 0 von 2')->assertSuccessful();

    $protokoll = collect(File::files($bericht))->first(fn ($f) => str_ends_with($f->getFilename(), '_protokoll.json'));
    $this->artisan('foodalchemist:platzhalter-deklaration-vorbelegen', ['--zurueck' => $protokoll->getPathname()])->assertSuccessful();
    $w = $this->wasser->fresh();
    expect($w->allergenOverrides())->toBe([])->and($w->nutri_source)->toBeNull()->and($w->deklaration_manuell)->toBeNull()
        ->and($rezept->fresh()->allergen_gluten)->toBe('unbekannt');
    File::deleteDirectory($bericht);
});
