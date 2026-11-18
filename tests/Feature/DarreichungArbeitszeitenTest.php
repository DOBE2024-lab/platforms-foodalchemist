<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\ProductionTimeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'glaszeit', 'name' => 'Salat',
        'is_sales_recipe' => true, 'status' => 'draft', 'yield_kg' => .12,
        'variable_work_time_min' => 1, 'variable_work_time_basis' => 'portion',
    ]);
    $this->form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->dar = app(DarreichungService::class)->anlegen($this->rootTeam, $this->gericht->id, $this->form->id, ['unit_count' => 1]);
    $this->zeiten = [
        ['use_case' => 'abfuellen', 'basis' => 'einheit', 'ort' => 'kueche', 'wert' => '20'],
        ['use_case' => 'pauschale', 'basis' => 'auftragszeile', 'ort' => 'kueche', 'wert' => '5'],
    ];
    $this->rechne = fn () => app(ProductionTimeService::class)->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
});

it('rechnet 100 Buffetgläser ohne Ausgabeplatten und ersetzt den Gerichtsaltwert vollständig', function () {
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $this->dar->id, ['arbeitszeiten' => $this->zeiten]);
    $r = ($this->rechne)();
    expect($r['variable_minutes'])->toBe(38.333)->and($r['active_person_minutes'])->toBe(38.333)
        ->and($r['bloecke']['anrichten']['formen'][0]['posten'][0]['menge'])->toBe(100.0)
        ->and($r['warnings'])->toBeEmpty();
});

it('erhält die Bestandszählweise, zählt Pauschalen einmal und Vor-Ort-Zeit ohne Küchenlohn', function () {
    $zeilen = $this->zeiten;
    $zeilen[] = ['use_case' => 'anrichten_teller', 'basis' => 'portion', 'ort' => 'vor_ort', 'wert' => 30];
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $this->dar->id, ['unit_count' => 2, 'arbeitszeiten' => $zeilen]);
    $r = ($this->rechne)();
    expect($r['active_person_minutes'])->toBe(71.667)
        ->and($r['bloecke']['anrichten']['vor_ort_minuten'])->toBe(50.0);
});

it('unterscheidet bewusst leere Zeitliste von unbearbeitetem Bestand', function () {
    $vorher = ($this->rechne)();
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $this->dar->id, ['arbeitszeiten' => []]);
    $nachher = ($this->rechne)();
    expect($vorher['warnings'])->not->toBeEmpty()->and($nachher['warnings'])->toBeEmpty()
        ->and($nachher['active_person_minutes'])->toBe(0.0);
});

it('zeigt fehlende Ausgabeplatten als unvollständige Berechnung', function () {
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $this->dar->id, ['arbeitszeiten' => [
        ['use_case' => 'portionieren_platte', 'basis' => 'behaelter', 'ort' => 'kueche', 'wert' => 2],
    ]]);
    $r = ($this->rechne)();
    expect($r['bloecke']['anrichten']['formen'][0]['vollstaendig'])->toBeFalse()
        ->and($r['bloecke']['anrichten']['formen'][0]['posten'][0]['minuten'])->toBeNull()
        ->and($r['warnings'])->not->toBeEmpty();
});

it('verweigert ungültige Werte sowie Änderungen an geerbten und fremden Darreichungen', function () {
    $svc = app(DarreichungService::class);
    $bad = $this->zeiten; $bad[0]['wert'] = -1;
    expect(fn () => $svc->aktualisieren($this->rootTeam, $this->dar->id, ['arbeitszeiten' => $bad]))->toThrow(RuntimeException::class);
    expect(fn () => $svc->aktualisieren($this->childA, $this->dar->id, ['arbeitszeiten' => $this->zeiten]))->toThrow(\Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::class);
    $other = FoodAlchemistRecipe::create(['team_id' => $this->childB->id, 'recipe_key' => 'fremd-zeit', 'name' => 'Fremd', 'is_sales_recipe' => true]);
    $dar = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->childB->id, 'recipe_id' => $other->id, 'serving_form_id' => $this->form->id]);
    expect(fn () => $svc->aktualisieren($this->childA, $dar->id, ['arbeitszeiten' => $this->zeiten]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and($this->dar->fresh()->arbeitszeiten)->toBeNull()->and($dar->fresh()->arbeitszeiten)->toBeNull();
});

it('erlaubt mehrere Buffetvarianten und bewahrt genau einen wechselbaren Standard', function () {
    $svc = app(DarreichungService::class);
    $zweite = $svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id, ['name' => 'Kleines Glas', 'quantity_per_unit_g' => 80]);
    expect($zweite->is_standard)->toBeFalse();
    $svc->setzeStandard($this->rootTeam, $zweite->id);
    expect($this->gericht->standardPresentation()->first()->id)->toBe($zweite->id)
        ->and($this->gericht->presentations()->where('is_standard', true)->count())->toBe(1)
        ->and($this->dar->fresh()->is_standard)->toBeFalse();
});

it('zeigt die Arbeitsschritte zentral und erhält die drei Prozess-Tabs', function () {
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    $html = $lw->html();
    expect($html)->toContain('data-dar-editor', 'Komponenten &amp; Mengen', 'Arbeitsschritte &amp; Zeiten', 'Regeneration', 'Fertigstellen', 'Anrichten')
        ->not->toContain('Fertigstellungszeit (min) · wird nicht gerechnet', 'Batchgrenze kg');
    $lw->set('darZeitForm.'.$this->dar->id, $this->zeiten)->call('darZeitenSpeichern', $this->dar->id)->assertSet('fehler', null);
    expect((float) $this->dar->fresh()->arbeitszeiten[0]['wert'])->toBe(20.0);
});

it('weist eine manipulierte Livewire-Darreichungs-ID ab', function () {
    $this->actingAs($this->makeUser($this->childA));
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    $lw->set('darZeitForm.'.$this->dar->id, $this->zeiten)->call('darZeitenSpeichern', $this->dar->id);
    expect($this->dar->fresh()->arbeitszeiten)->toBeNull();
});

it('ordnet nur Küchenarbeit dem expliziten Ausgabe-Posten zu und weist fremde Posten ab', function () {
    $svc = app(DarreichungService::class);
    $station = \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'glas', 'name' => 'Abfüllung']);
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::create(['team_id' => $this->childB->id, 'slug' => 'fremd', 'name' => 'Fremd']);
    $svc->aktualisieren($this->rootTeam, $this->dar->id, ['ausgabe_station_id' => $station->id, 'arbeitszeiten' => $this->zeiten]);
    $r = ($this->rechne)();
    expect($r['bloecke']['anrichten']['aufteilung'][0]['station_id'])->toBe($station->id)
        ->and($r['bloecke']['anrichten']['aufteilung'][0]['minuten'])->toBe(38.333);
    expect(fn () => $svc->aktualisieren($this->rootTeam, $this->dar->id, ['ausgabe_station_id' => $fremd->id]))->toThrow(RuntimeException::class);
});

it('stellt KI-Zeiten nur zur Übernahme bereit und bewahrt ihre Herkunft', function () {
    $gateway = Mockery::mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class);
    $gateway->shouldReceive('propose')->once()->with('darreichung.zeiten', Mockery::type('array'), Mockery::type('array'))
        ->andReturn(new \Platform\FoodAlchemist\Services\Ai\AiProposal(['arbeitszeiten' => $this->zeiten], .7));
    app()->instance(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, $gateway);
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->call('darZeitVorschlagen', $this->dar->id)->assertSet('fehler', null);
    expect($this->dar->fresh()->arbeitszeiten)->toBeNull();
    $lw->call('darZeitenSpeichern', $this->dar->id)->assertSet('fehler', null);
    expect($this->dar->fresh()->arbeitszeiten[0]['quelle'])->toBe('ki_vorschlag');
    $lw->set('darZeitForm.'.$this->dar->id.'.0.wert', '25')->call('darZeitenSpeichern', $this->dar->id)->assertSet('fehler', null);
    expect($this->dar->fresh()->arbeitszeiten[0])->not->toHaveKey('quelle');
});

it('schreibt und liest die Arbeitszeiten über denselben MCP-Pfad', function () {
    $user = \Illuminate\Support\Facades\Auth::user();
    $ctx = new \Platform\Core\Contracts\ToolContext(user: $user, team: $this->rootTeam);
    $reg = app(\Platform\Core\Tools\ToolRegistry::class);
    $res = $reg->get('foodalchemist.recipe_darreichung.PUT')->execute([
        'presentation_id' => $this->dar->id, 'attrs' => ['arbeitszeiten' => $this->zeiten],
    ], $ctx);
    expect($res->success)->toBeTrue();
    $get = $reg->get('foodalchemist.verkaufsrezepte.GET')->execute(['id' => $this->gericht->id], $ctx);
    expect($get->success)->toBeTrue()->and($get->data['presentations'][0]['arbeitszeiten'][0]['basis'])->toBe('einheit');
});

it('vererbt neue Standardmengen und erhält eigene Grammaturen und Komponenten', function () {
    $this->gericht->update(['yield_kg' => .2, 'ek_per_kg_eur' => 10]);
    $a = $this->makeIngredient($this->gericht, 'A', $this->makeGp($this->rootTeam, 'A'), '100', 1);
    $b = $this->makeIngredient($this->gericht, 'B', $this->makeGp($this->rootTeam, 'B'), '100', 2);
    $svc = app(DarreichungService::class);
    $svc->aktualisieren($this->rootTeam, $this->dar->id, ['quantity_per_unit_g' => 120]);
    $erbt = $svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id, ['name' => 'Erbt']);
    $teil = $svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id, ['name' => 'A nur 10 g']);
    $eigen = $svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id, ['name' => 'Eigene 80 g', 'quantity_per_unit_g' => 80]);
    $svc->setzeDelta($this->rootTeam, $teil->id, $a->id, 10, false);
    expect($erbt->fresh()->quantity_per_unit_g)->toBe(120.0)->and($teil->fresh()->quantity_per_unit_g)->toBe(70.0);
    $svc->setzeStandard($this->rootTeam, $eigen->id);
    expect($erbt->fresh()->quantity_per_unit_g)->toBe(80.0)
        ->and($teil->fresh()->quantity_per_unit_g)->toBe(50.0)
        ->and($this->dar->fresh()->quantity_per_unit_g)->toBe(120.0);
    $svc->setzeStandard($this->rootTeam, $teil->id);
    expect($teil->fresh()->quantity_per_unit_g)->toBe(50.0)->and($erbt->fresh()->quantity_per_unit_g)->toBe(50.0)
        ->and($eigen->fresh()->quantity_per_unit_g)->toBe(80.0);
    $anteil = $svc->produktionsAnteil($this->gericht->fresh(), $erbt->fresh(), 100, $this->rootTeam);
    expect($anteil['gramm_je_portion'])->toBe(50.0)->and($anteil['je_zutat'][$a->id])->toBe(10.0)
        ->and($anteil['je_zutat'][$b->id])->toBe(40.0);
});

it('hält die Referenz einer angepassten Standardportion stabil und gibt sie korrekt zurück', function () {
    $this->gericht->update(['yield_kg' => .2, 'ek_per_kg_eur' => 10]);
    $a = $this->makeIngredient($this->gericht, 'A', $this->makeGp($this->rootTeam, 'A'), '100', 1);
    $b = $this->makeIngredient($this->gericht, 'B', $this->makeGp($this->rootTeam, 'B'), '100', 2);
    $svc = app(DarreichungService::class);
    $svc->aktualisieren($this->rootTeam, $this->dar->id, ['quantity_per_unit_g' => 120]);
    $erbt = $svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id);
    $svc->setzeDelta($this->rootTeam, $this->dar->id, $a->id, 30, false);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(90.0)->and($erbt->fresh()->quantity_per_unit_g)->toBe(90.0);
    $svc->setzeDelta($this->rootTeam, $this->dar->id, $a->id, 20, false);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(80.0)->and($erbt->fresh()->quantity_per_unit_g)->toBe(80.0);
    $svc->entferneDelta($this->rootTeam, $this->dar->id, $a->id);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(120.0)->and($erbt->fresh()->quantity_per_unit_g)->toBe(120.0);
});
