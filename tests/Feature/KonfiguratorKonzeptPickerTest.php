<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Folge aus 2.4f · Konfigurator: Lunch-/Dinner-Konzept per Auswahl statt ID-Eingabe. Liste = aktive Konzepte und Entwürfe, die das Team
 * sieht (Archivierte nur, wenn schon gesetzt); „Konzept“ statt „Concept“ in sichtbaren Texten. Die Prüfung beim Speichern bleibt im Service.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $mk = fn ($team, string $name, string $status = 'active', string $kind = 'concept') => FoodAlchemistConcept::create(
        ['team_id' => $team->id, 'name' => $name, 'kind' => $kind, 'status' => $status, 'price_per_person_cache' => 30, 'ek_per_person_cache' => 9]);
    $this->eigen = $mk($this->childA, 'Grillbuffet');
    $this->master = $mk($this->rootTeam, 'Lunch Classic');
    $this->entwurf = $mk($this->childA, 'Herbstmenü', 'draft');
    $this->paket = $mk($this->childA, 'Salatbar', 'active', 'paket');
    $this->archiv = $mk($this->childA, 'Altes Buffet', 'archiviert');
    $this->fremd = $mk($this->childB, 'Fremdes Menü');

    $this->k = app(KonfiguratorService::class)->anlegen($this->childA, ['name' => 'Sommerfest']);
    $this->kz = app(KonfiguratorService::class)->konzeptHinzu($this->childA, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $this->eigen->id, 'kunden_titel' => 'Grill']);
});

it('zeigt beim Bearbeiten eigene und geerbte Konzepte mit Art und Status, keine fremden, keine archivierten', function () {
    $t = Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)->assertViewHas('conceptAuswahl', []);   // ohne Bearbeitung: nichts geladen
    $auswahl = $t->call('konzeptBearbeiten', $this->kz->id)->viewData('conceptAuswahl');

    expect($auswahl)->toMatchArray([
        $this->eigen->id => 'Grillbuffet · Konzept · Aktiv',
        $this->master->id => 'Lunch Classic · Konzept · Aktiv',
        $this->entwurf->id => 'Herbstmenü · Konzept · Entwurf',
        $this->paket->id => 'Salatbar · Paket · Aktiv',
    ])->and($auswahl)->not->toHaveKey($this->fremd->id)->not->toHaveKey($this->archiv->id);
    $t->assertSeeHtml('data-kf-concept-auswahl="lunch"')->assertSee('Lunch-Konzept')->assertDontSee('Lunch-Concept (Id)');
});

it('ein schon gesetztes archiviertes Konzept bleibt in der Auswahl sichtbar', function () {
    $this->kz->update(['lunch_concept_id' => $this->archiv->id]);

    expect(Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)->call('konzeptBearbeiten', $this->kz->id)->viewData('conceptAuswahl'))
        ->toHaveKey($this->archiv->id, 'Altes Buffet · Konzept · Archiviert');
});

it('Auswahl übernehmen setzt das Lunch-Konzept; „keins“ leert es wieder', function () {
    $t = Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)->call('konzeptBearbeiten', $this->kz->id)
        ->set('konzeptForm.lunch_concept_id', (string) $this->master->id)->call('konzeptSpeichern')->assertSet('fehler', null);
    expect($this->kz->fresh()->lunch_concept_id)->toBe($this->master->id);

    $t->call('konzeptBearbeiten', $this->kz->id)->set('konzeptForm.lunch_concept_id', '')->call('konzeptSpeichern')->assertSet('fehler', null);
    expect($this->kz->fresh()->lunch_concept_id)->toBeNull();
});

it('eine eingeschleuste fremde ID weist die Prüfung im Service ab', function () {
    Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)->call('konzeptBearbeiten', $this->kz->id)
        ->set('konzeptForm.lunch_concept_id', (string) $this->fremd->id)->call('konzeptSpeichern')->assertNotSet('fehler', null);

    expect($this->kz->fresh()->lunch_concept_id)->toBeNull();
});

it('Getränkepaket: Tag und Abend wählen aus derselben Liste', function () {
    $g = app(KonfiguratorService::class)->konzeptHinzu($this->childA, $this->k->id, ['rolle' => 'getraenk', 'dinner_concept_id' => $this->paket->id,
        'getraenk_abrechnung' => 'pauschale', 'getraenk_dauer' => 'event', 'kunden_titel' => 'Softdrinks']);

    Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)->call('konzeptBearbeiten', $g->id)
        ->assertSeeHtml('data-kfg-concept-auswahl="lunch"')->assertSee('Tag: Lunch-Konzept')
        ->set('konzeptForm.lunch_concept_id', (string) $this->eigen->id)->call('konzeptSpeichern')->assertSet('fehler', null);

    expect($g->fresh()->lunch_concept_id)->toBe($this->eigen->id);
});
