<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'mengen-schalter', 'name' => 'Curry mit Reis',
        'is_sales_recipe' => true, 'status' => 'draft', 'yield_kg' => .3,
    ]);
    $this->a = $this->makeIngredient($this->gericht, 'Curry', $this->makeGp($this->rootTeam, 'Curry'), '200');
    $this->b = $this->makeIngredient($this->gericht, 'Reis', $this->makeGp($this->rootTeam, 'Reis'), '100', 2);
    $this->form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->svc = app(DarreichungService::class);
    $this->dar = $this->svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id);
});

it('folgt mit Standardmengen der Rezeptur und berechnet die Portionssumme nach Zutatenänderungen neu', function () {
    expect($this->dar->menge_vom_standard)->toBeTrue()->and($this->dar->quantity_per_unit_g)->toBe(300.0);
    $this->a->update(['quantity' => 250]);
    $this->svc->recomputeFuerRezept($this->gericht->id);
    $dar = $this->dar->fresh();
    $anteil = $this->svc->produktionsAnteil($this->gericht->fresh(), $dar, 2, $this->rootTeam);
    expect($dar->quantity_per_unit_g)->toBe(350.0)
        ->and($anteil['je_zutat'][$this->a->id])->toBe(2.0)
        ->and($anteil['gramm_je_portion'])->toBe(350.0);
});

it('startet normale Gerichte aus der Rezeptur und weist fehlende Mengen weiterhin als unbekannt aus', function () {
    $empty = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'mengen-leer', 'name' => 'Noch ohne Zutaten',
        'is_sales_recipe' => true, 'status' => 'draft',
    ]);
    $std = $this->svc->ensureStandard($this->rootTeam, $empty->id);
    expect($std->menge_vom_standard)->toBeTrue()->and($std->quantity_per_unit_g)->toBeNull();
    $this->makeIngredient($empty, 'Curry', $this->a->gp, '200');
    $this->svc->recomputeFuerRezept($empty->id);
    expect($std->fresh()->quantity_per_unit_g)->toBe(200.0);
    $empty->ingredients()->delete();
    $empty->update(['yield_kg' => 0]);
    $this->svc->recomputeFuerRezept($empty->id);
    expect($std->fresh()->quantity_per_unit_g)->toBe(0.0);
});

it('schaltet über Livewire eigene Komponenten frei und setzt bewusst auf die Quelle zurück', function () {
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    $lw->call('darStandardmenge', $this->dar->id, false)->assertSet('fehler', null);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(300.0)->and($this->dar->fresh()->deltas)->toHaveCount(2);
    $lw->call('darDeltaMenge', $this->dar->id, $this->a->id, '150')->assertSet('fehler', null);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(250.0);
    $lw->call('darDeltaWeg', $this->dar->id, $this->b->id)->assertSet('fehler', null);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(150.0);
    $lw->call('darStandardmenge', $this->dar->id, true)->assertSet('fehler', null);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(300.0)->and($this->dar->fresh()->deltas)->toBeEmpty();
});

it('erhält eigene Varianten und lässt geerbte Varianten dem neuen Standard folgen', function () {
    $geerbt = $this->svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id);
    $eigen = $this->svc->anlegen($this->rootTeam, $this->gericht->id, $this->form->id);
    $this->svc->setzeStandardmenge($this->rootTeam, $eigen->id, false);
    $this->svc->setzeDelta($this->rootTeam, $eigen->id, $this->a->id, 50, false);
    $this->svc->setzeStandardmenge($this->rootTeam, $this->dar->id, false);
    $this->svc->setzeDelta($this->rootTeam, $this->dar->id, $this->a->id, 180, false);
    expect($geerbt->fresh()->quantity_per_unit_g)->toBe(280.0)->and($eigen->fresh()->quantity_per_unit_g)->toBe(150.0);
    $this->svc->setzeStandard($this->rootTeam, $eigen->id);
    expect($geerbt->fresh()->quantity_per_unit_g)->toBe(150.0)->and($this->dar->fresh()->quantity_per_unit_g)->toBe(280.0);
    $this->svc->setzeStandardmenge($this->rootTeam, $eigen->id, true);
    expect($eigen->fresh()->quantity_per_unit_g)->toBe(300.0)->and($geerbt->fresh()->quantity_per_unit_g)->toBe(300.0);
});

it('stellt Bestandsmengen beim Öffnen nicht um und schützt die Umschaltung vor fremden Teams', function () {
    $this->grammaturAltbestand($this->dar, 120);   // Plan 98 B: Grammatur als Altbestand-Fixture (freie Grammatur ist über den Service gesperrt)
    Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(120.0)->and($this->dar->fresh()->menge_vom_standard)->toBeFalse();
    expect(fn () => $this->svc->setzeStandardmenge($this->childA, $this->dar->id, true))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::class);
    $this->actingAs($this->makeUser($this->childB));
    Livewire::test(VkModal::class)->call('darStandardmenge', $this->dar->id, true);
    expect($this->dar->fresh()->quantity_per_unit_g)->toBe(120.0);
});

it('zeigt ein kompaktes Formular ohne zweite KPI-Leiste und ohne freie Gesamtgrammatur', function () {
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    $html = $lw->html();
    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    expect($xp->query('//*[@data-vk-editor-kpis]')->length)->toBe(1)
        ->and($xp->query('//*[@data-vk-kalkulation-kpis]')->length)->toBe(0)
        ->and($xp->query('//*[@data-dar-portionsmenge]//input[not(@type="checkbox")]')->length)->toBe(0)
        ->and($xp->query('//*[@data-dar-standardmenge and @checked]')->length)->toBe(1)
        ->and($xp->query('//details[@data-vk-eigenschaften and not(@open)]')->length)->toBe(1)
        ->and($xp->query('//*[@data-dar-delta]//input')->length)->toBe(0)
        ->and($xp->query('//section[@aria-label="Portion und Verkauf"]//*[@data-dar-gefaess-info]')->length)->toBe(1)
        ->and($xp->query('//details//*[@data-dar-gefaess-info]')->length)->toBe(0);
    if ($path = getenv('FA_DAR_KOMPAKT_PREVIEW_PATH')) file_put_contents($path, $html);
    $lw->call('darStandardmenge', $this->dar->id, false)->assertSet('fehler', null);
    if ($path = getenv('FA_DAR_KOMPAKT_EIGEN_PREVIEW_PATH')) file_put_contents($path, $lw->html());
    $dom->loadHTML($lw->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($dom))->query('//*[@data-dar-delta]//input[@inputmode="decimal"]')->length)->toBe(2);
});
