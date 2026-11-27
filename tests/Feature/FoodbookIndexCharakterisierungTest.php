<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 PR0 · Charakterisierung des Foodbook-Editors vor dem Zerlegen (Ziel: unter 1.500 Zeilen).
 * Hält die Haupt-Abläufe fest, so wie sie heute laufen: Foodbook-Liste samt Suchfilter, Wählen, Kapitel-Baum,
 * Blöcke anlegen/bearbeiten/speichern, Wahlgruppe samt Ablehnungs-Toast (#1007), Branding, Präsentation,
 * KI-Vorschau. Das Zerlegen darf hier nichts ändern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(FoodbookService::class);
    $this->fb = $this->svc->create($this->rootTeam, ['label' => 'Charakter-Buch']);
    $this->kapitelIds = fn () => FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->fb->id)->whereNull('parent_id')->orderBy('position')->pluck('id')->all();
});

it('Liste: zeigt die Foodbooks, Suche filtert, Wählen lädt den Kopf', function () {
    $this->svc->create($this->rootTeam, ['label' => 'Sommerfest 2027']);
    $t = Livewire::test(Index::class)
        ->assertViewHas('foodbooks', fn ($p) => collect($p->items())->pluck('label')->sort()->values()->all() === ['Charakter-Buch', 'Sommerfest 2027'])
        ->set('search', 'Sommer')
        ->assertViewHas('foodbooks', fn ($p) => collect($p->items())->pluck('label')->all() === ['Sommerfest 2027'])
        ->set('search', '');

    $t->call('waehle', $this->fb->id)->assertSet('selectedId', $this->fb->id)->assertSet('form.label', 'Charakter-Buch')
        ->assertSet('selectedKapitelId', null)->assertViewHas('fb', fn ($fb) => $fb?->id === $this->fb->id);
});

it('Kapitel-Baum: anlegen, wählen, hoch/runter, einrücken/ausrücken, Drag & Drop, löschen', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id)
        ->set('neuesKapitelTitel', 'Vorspeise')->call('kapitelNeu')->assertSet('neuesKapitelTitel', '')
        ->call('kapitelNeu')->call('kapitelNeu');
    [$a, $b, $c] = ($this->kapitelIds)();
    expect(FoodAlchemistFoodbookKapitel::find($a)->title)->toBe('Vorspeise');

    $t->call('kapitelWaehle', $a)->assertSet('selectedKapitelId', $a)->assertSet('kapitelForm.title', 'Vorspeise');
    $t->call('kapitelRunter', $a);
    expect(($this->kapitelIds)())->toBe([$b, $a, $c]);
    $t->call('kapitelHoch', $c);
    expect(($this->kapitelIds)())->toBe([$b, $c, $a]);
    $t->call('kapitelHoch', $b);
    expect(($this->kapitelIds)())->toBe([$b, $c, $a]);

    $t->call('kapitelEinruecken', $c);
    expect(FoodAlchemistFoodbookKapitel::find($c)->parent_id)->toBe($b)->and(($this->kapitelIds)())->toBe([$b, $a]);
    $t->call('kapitelEinruecken', $b);
    expect(FoodAlchemistFoodbookKapitel::find($b)->parent_id)->toBeNull();
    $t->call('kapitelAusruecken', $c);
    expect(FoodAlchemistFoodbookKapitel::find($c)->parent_id)->toBeNull()->and(($this->kapitelIds)())->toBe([$b, $a, $c]);

    $t->call('kapitelVerschiebenAuf', $c, $b);
    expect(($this->kapitelIds)())->toBe([$c, $b, $a]);

    $t->call('kapitelLoeschen', $a);
    expect(FoodAlchemistFoodbookKapitel::find($a))->toBeNull();
});

it('Blöcke: anlegen, bearbeiten, speichern, Reihenfolge; Wahlgruppe, Ablehnung als Toast (#1007)', function () {
    $kap = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü']);
    $konzepte = app(ConceptService::class);
    $k1 = $konzepte->create($this->rootTeam, ['name' => 'Menü A']);
    $k2 = $konzepte->create($this->rootTeam, ['name' => 'Menü B']);
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id)->call('kapitelWaehle', $kap->id)
        ->call('conceptHinzu', $k1->id)->call('conceptHinzu', $k2->id)
        ->call('presetHinzu', 'header_frei_preis', 'buffet', 'Buffet', null, true);
    $ids = fn () => FoodAlchemistFoodbookBlock::where('chapter_id', $kap->id)->orderBy('position')->pluck('id')->all();
    [$b1, $b2, $b3] = $ids();
    $kopf = FoodAlchemistFoodbookBlock::find($b3);
    expect($kopf->type)->toBe('header_frei_preis')->and($kopf->price_basis)->toBe('person')->and($kopf->label)->toBe('Buffet');

    $t->call('blockBearbeiten', $b1)->assertSet('editBlockId', $b1)
        ->set('blockForm.wording', 'Unser Menü A')->call('blockSpeichern')->assertSet('editBlockId', null);
    expect(FoodAlchemistFoodbookBlock::find($b1)->wording)->toBe('Unser Menü A');

    $t->call('blockRunter', $b1);
    expect($ids())->toBe([$b2, $b1, $b3]);
    $t->call('blockHoch', $b3);
    expect($ids())->toBe([$b2, $b3, $b1]);
    $t->call('blockVerschiebenAuf', $b2, $b1);
    expect($ids())->toBe([$b3, $b1, $b2]);

    $t->call('markiere', $b1)->call('markiere', $b2)->call('wahlGruppeBilden')->assertSet('markiert', []);
    $gruppe = FoodAlchemistFoodbookBlock::find($b1)->variant_group_id;
    expect($gruppe)->not->toBeNull()->and(FoodAlchemistFoodbookBlock::find($b2)->variant_group_id)->toBe($gruppe);
    $t->call('wahlGruppeAufheben', $b1);
    expect(FoodAlchemistFoodbookBlock::find($b1)->variant_group_id)->toBeNull();

    $text = $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'text']);
    $t->set('markiert', [$b1, $text->id])->call('wahlGruppeBilden')->assertDispatched('fa-saved', type: 'error');
    expect(FoodAlchemistFoodbookBlock::find($b1)->variant_group_id)->toBeNull();

    $t->call('blockSichtbar', $b2);
    expect(FoodAlchemistFoodbookBlock::find($b2)->visible)->toBeFalse();
    $t->call('blockRaus', $b2);
    expect(FoodAlchemistFoodbookBlock::find($b2))->toBeNull();
});

it('Branding: Felder laden beim Wählen, speichern, Fehler bei ungültiger Farbe', function () {
    $this->svc->setBranding($this->rootTeam, $this->fb->id, ['brand_color' => '#112233', 'band_color' => '', 'footer_text' => 'Fuß']);
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id)
        ->assertSet('brandingForm.brand_color', '#112233')->assertSet('brandingForm.footer_text', 'Fuß')->assertSet('brandingLoadedId', $this->fb->id)
        ->set('brandingForm.brand_color', '#445566')->call('brandingSpeichern')
        ->assertSet('brandingGespeichert', true)->assertSet('brandingFehler', null);
    expect($this->fb->fresh()->brand_color)->toBe('#445566');

    $t->set('brandingForm.brand_color', 'lila')->call('brandingSpeichern')->assertSet('brandingGespeichert', false);
    expect($t->get('brandingFehler'))->not->toBeNull()->and($this->fb->fresh()->brand_color)->toBe('#445566');
});

it('Präsentation: Felder laden, veröffentlichen, zurückziehen, Betriebs-Link ohne Profil', function () {
    $kap = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü']);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => app(ConceptService::class)->create($this->rootTeam, ['name' => 'Präsi-Menü'])->id]);

    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id)
        ->assertSet('presentationDesign', 'editorial')->assertSet('presentationLoadedId', $this->fb->id)
        ->assertViewHas('presentationInfo', fn ($i) => $i['enabled'] === false && $i['live'] === false)
        ->assertViewHas('presentationLink', null)->assertViewHas('betriebsLinks', []);

    $t->set('presentationGueltigBis', now()->addDays(30)->toDateString())->set('presentationPreisAnzeige', false)
        ->call('veroeffentlichen')
        ->assertSet('presentationFehler', null)->assertSet('presentationHinweis', 'Veröffentlicht — der Kundenlink ist aktiv.')
        ->assertSet('presentationLoadedId', $this->fb->id)->assertSet('presentationPreisAnzeige', false)
        ->assertViewHas('presentationInfo', fn ($i) => $i['enabled'] === true && $i['live'] === true)
        ->assertViewHas('presentationLink', fn ($l) => str_contains((string) $l, '/p/foodbook/'));

    $t->call('betriebVeroeffentlichen')->assertSet('presentationFehler', 'Bitte zuerst ein Kalkulationsprofil wählen.');
    $t->call('zuruckziehen')->assertSet('presentationHinweis', 'Veröffentlichung zurückgezogen — der Link ist jetzt inaktiv (404).');
    expect($this->fb->fresh()->presentation_enabled)->toBeFalse();
});

it('KI-Vorschau: Übernehmen füllt das Feld der richtigen Ebene, Verwerfen leert', function () {
    $kap = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü']);
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id)
        ->set('kiTextVorschau', 'Ein Fest im Grünen.')->call('kiTextUebernehmen')
        ->assertSet('form.description', 'Ein Fest im Grünen.')->assertSet('kiTextVorschau', null);
    expect($t->get('kiTextHinweis'))->not->toBeNull();

    $t->call('kapitelWaehle', $kap->id)->set('kiTextZiel', 'kapitel')->set('kiTextVorschau', 'Hinführung.')->call('kiTextUebernehmen')
        ->assertSet('kapitelForm.description', 'Hinführung.')
        ->call('kiTextVerwerfen')->assertSet('kiTextHinweis', null)->assertSet('kiTextVorschau', null);
});
