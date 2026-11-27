<?php

use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 PR0 · Charakterisierung des Angebots-Editors vor dem Zerlegen (Ziel: unter 1.500 Zeilen).
 * Hält die Haupt-Abläufe fest, so wie sie heute laufen: Kapitel-Baum, Blöcke anlegen/bearbeiten/speichern
 * samt Ablehnungs-Toast (#1007), Block-Reihenfolge, Board-Daten, Präsentation, KI-Vorschau.
 * Das Zerlegen darf hier nichts ändern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Charakter-Angebot', 'personen' => 20, 'occasion' => 'Sommerfest']);
    $this->comp = app(OfferCompositionService::class);
    $this->kapitelIds = fn () => FoodAlchemistOfferChapter::where('offer_id', $this->angebot->id)->whereNull('parent_id')->orderBy('position')->pluck('id')->all();
});

it('Kapitel-Baum: anlegen, wählen, hoch/runter, einrücken/ausrücken, Drag & Drop', function () {
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)
        ->set('neuesKapitelTitel', 'Vorspeise')->call('kapitelNeu')
        ->assertSet('neuesKapitelTitel', '')
        ->call('kapitelNeu')->call('kapitelNeu');
    [$a, $b, $c] = ($this->kapitelIds)();
    expect(FoodAlchemistOfferChapter::find($a)->title)->toBe('Vorspeise')
        ->and(FoodAlchemistOfferChapter::find($b)->title)->toBe('Neues Kapitel');
    $t->assertSet('selectedKapitelId', $c);

    $t->call('kapitelWaehle', $a)->assertSet('selectedKapitelId', $a)->assertSet('kapitelForm.title', 'Vorspeise')->assertDispatched('angebot-goto');
    $t->call('kapitelRunter', $a);
    expect(($this->kapitelIds)())->toBe([$b, $a, $c]);
    $t->call('kapitelHoch', $c);
    expect(($this->kapitelIds)())->toBe([$b, $c, $a]);
    $t->call('kapitelHoch', $b);                                    // schon oben: nichts
    expect(($this->kapitelIds)())->toBe([$b, $c, $a]);

    $t->call('kapitelEinruecken', $c);                              // unter den Vorgänger
    expect(FoodAlchemistOfferChapter::find($c)->parent_id)->toBe($b)->and(($this->kapitelIds)())->toBe([$b, $a]);
    $t->call('kapitelEinruecken', $b);                              // erstes Geschwister: nichts
    expect(FoodAlchemistOfferChapter::find($b)->parent_id)->toBeNull();
    $t->call('kapitelAusruecken', $c);                              // zurück, ans Ende
    expect(FoodAlchemistOfferChapter::find($c)->parent_id)->toBeNull()->and(($this->kapitelIds)())->toBe([$b, $a, $c]);

    $t->call('kapitelVerschiebenAuf', $c, $b);                      // direkt vor das Ziel
    expect(($this->kapitelIds)())->toBe([$c, $b, $a]);
    $t->call('kapitelNeu', $b);                                     // Unterkapitel
    expect(FoodAlchemistOfferChapter::where('parent_id', $b)->value('title'))->toBe('Neues Unterkapitel');

    $t->call('kapitelLoeschen', $a);
    expect(FoodAlchemistOfferChapter::find($a))->toBeNull();
});

it('Blöcke: anlegen, bearbeiten, speichern; Ablehnung als Toast, Formular bleibt offen (#1007)', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Extras']);
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)->call('kapitelWaehle', $kap->id)
        ->call('blockBasis', 'position')
        ->call('presetHinzu', 'header_frei_preis', 'buffet', 'Buffet', null, true);
    $bloecke = FoodAlchemistOfferBlock::where('chapter_id', $kap->id)->orderBy('position')->get();
    expect($bloecke->pluck('type')->all())->toBe(['position', 'header_preis'])   // das Angebot führt den Preis-Kopf als header_preis
        ->and($bloecke[1]->price_basis)->toBe('person')->and($bloecke[1]->header_source)->toBe('buffet')->and($bloecke[1]->label)->toBe('Buffet');

    $pos = $bloecke[0];
    $t->call('blockBearbeiten', $pos->id)->assertSet('editBlockId', $pos->id)->assertSet('blockForm.price_basis', 'pauschal')
        ->set('blockForm.label', 'Garderobe')->set('blockForm.price_value', '2.5')->set('blockForm.quantity', '4')
        ->call('blockSpeichern')->assertSet('editBlockId', null)->assertNotDispatched('fa-saved', type: 'error');
    expect($pos->fresh()->label)->toBe('Garderobe')->and((float) $pos->fresh()->price_value)->toBe(2.5)->and((float) $pos->fresh()->quantity)->toBe(4.0);

    $t->call('blockBearbeiten', $pos->id)->set('blockForm.quantity', '-1')->call('blockSpeichern')
        ->assertDispatched('fa-saved', type: 'error')->assertSet('editBlockId', $pos->id);
    expect((float) $pos->fresh()->quantity)->toBe(4.0);

    $t->call('blockSichtbar', $pos->id);
    expect($pos->fresh()->visible)->toBeFalse();
    $t->call('blockRaus', $pos->id);
    expect(FoodAlchemistOfferBlock::find($pos->id))->toBeNull();
});

it('Blöcke: Reihenfolge per hoch/runter und Drag & Drop, Wahlgruppe bilden und aufheben', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü']);
    $k1 = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü A', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 30.0, 'ek_per_person_cache' => 9.0]);
    $k2 = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü B', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 40.0, 'ek_per_person_cache' => 12.0]);
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)->call('kapitelWaehle', $kap->id)
        ->call('conceptHinzu', $k1->id)->call('conceptHinzu', $k2->id)->call('blockBasis', 'text');
    $ids = fn () => FoodAlchemistOfferBlock::where('chapter_id', $kap->id)->orderBy('position')->pluck('id')->all();
    [$b1, $b2, $b3] = $ids();

    $t->call('blockRunter', $b1);
    expect($ids())->toBe([$b2, $b1, $b3]);
    $t->call('blockHoch', $b3);
    expect($ids())->toBe([$b2, $b3, $b1]);
    $t->call('blockVerschiebenAuf', $b2, $b1);                       // hinter das Ziel
    expect($ids())->toBe([$b3, $b1, $b2]);

    $t->call('markiere', $b1)->call('markiere', $b2)->assertSet('markiert', [$b1, $b2])->call('wahlGruppeBilden')->assertSet('markiert', []);
    $gruppe = FoodAlchemistOfferBlock::find($b1)->variant_group_id;
    expect($gruppe)->not->toBeNull()->and(FoodAlchemistOfferBlock::find($b2)->variant_group_id)->toBe($gruppe);
    $t->call('wahlGruppeAufheben', $b1);
    expect(FoodAlchemistOfferBlock::find($b1)->variant_group_id)->toBeNull();
});

it('Board-Daten: Form und Zahlen je Kapitel', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Hauptgang']);
    $leer = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Leer']);
    $gericht = $this->makeRecipe($this->rootTeam, 'Roulade', ['is_sales_recipe' => true, 'ek_total_eur' => 30.0, 'sales_unit_count' => 10]);
    $konzepte = app(\Platform\FoodAlchemist\Services\Concepter\ConceptService::class);
    $k = $konzepte->create($this->rootTeam, ['name' => 'Grillmenü', 'status' => 'active']);
    $konzepte->fillSlot($this->rootTeam, $konzepte->addSlot($this->rootTeam, $k->id, ['role' => 'Haupt'])->id, ['sales_recipe_id' => $gericht->id]);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $k->id]);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'header', 'label' => 'Zwischenzeile']);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $gericht->id]);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'position', 'label' => 'Service', 'price_value' => 10, 'ek_value' => 4, 'quantity' => 1]);
    $this->comp->kapitelFortschritt($this->rootTeam, $kap->id, 'in_arbeit');

    $board = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)->viewData('kapitelBoard');

    expect($board)->toHaveCount(2)
        ->and(array_keys($board[0]))->toBe(['kapitel_id', 'titel', 'parent_id', 'depth', 'fortschritt', 'is_struktur', 'pricing_mode', 'hat_ziele', 'positionen', 'positionen_count', 'hat_inhalt', 'bepreist', 'aggregat', 'wareneinsatz'])
        ->and($board[0])->toMatchArray(['kapitel_id' => $kap->id, 'titel' => 'Hauptgang', 'parent_id' => null, 'depth' => 0, 'fortschritt' => 'in_arbeit', 'is_struktur' => false, 'pricing_mode' => 'auto', 'hat_ziele' => false, 'positionen_count' => 3, 'hat_inhalt' => true])
        ->and($board[0]['positionen'][0])->toBe(['art' => 'paket', 'label' => 'Grillmenü', 'ek' => 3.0, 'vk' => 0.0, 'preis_einheit' => 'gast', 'we_pct' => null])
        ->and($board[0]['positionen'][1])->toBe(['art' => 'einzel', 'label' => 'Roulade', 'ek' => 3.0, 'vk' => 0.0, 'preis_einheit' => 'position', 'we_pct' => null])
        ->and($board[0]['positionen'][2])->toBe(['art' => 'paket', 'label' => 'Service', 'ek' => 0.0, 'vk' => 0.0, 'preis_einheit' => 'pauschal', 'we_pct' => null])
        ->and($board[0]['aggregat'])->toBe(['vk_pro_person' => 0.0, 'ek_per_person' => 6.0, 'pauschal' => 10.0, 'wahl_spanne' => null, 'wahl_gruppen' => []])
        ->and($board[0]['wareneinsatz'])->toBe(['status' => 'unbekannt', 'ist_pct' => null, 'ziel_pct' => 30.0])
        ->and($board[0]['bepreist'])->toBeFalse()
        ->and($board[1])->toMatchArray(['kapitel_id' => $leer->id, 'titel' => 'Leer', 'fortschritt' => 'offen', 'positionen' => [], 'positionen_count' => 0, 'hat_inhalt' => false, 'bepreist' => false]);
});

it('Präsentation: Felder laden beim Öffnen, veröffentlichen, zurückziehen, Betriebs-Link ohne Profil', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü']);
    $k = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Präsi-Menü', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 15.0, 'ek_per_person_cache' => 5.0]);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $k->id]);

    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)
        ->assertSet('presentationDesign', 'editorial')->assertSet('presentationPreisAnzeige', true)->assertSet('presentationLoadedId', $this->angebot->id)
        ->assertViewHas('presentationInfo', fn ($i) => $i['enabled'] === false && $i['live'] === false)
        ->assertViewHas('presentationLink', null);

    $t->set('presentationGueltigBis', now()->addDays(30)->toDateString())->set('presentationPreisAnzeige', false)
        ->call('veroeffentlichen')
        ->assertSet('presentationFehler', null)->assertSet('presentationHinweis', 'Veröffentlicht — der Kundenlink ist aktiv.')
        ->assertSet('presentationLoadedId', $this->angebot->id)       // render() hat neu geladen
        ->assertSet('presentationPreisAnzeige', false)
        ->assertViewHas('presentationInfo', fn ($i) => $i['enabled'] === true && $i['live'] === true)
        ->assertViewHas('presentationLink', fn ($l) => str_contains((string) $l, '/p/angebot/'));
    expect($this->angebot->fresh()->isPresentationLive())->toBeTrue();

    $t->call('betriebVeroeffentlichen')->assertSet('presentationFehler', 'Bitte zuerst ein Kalkulationsprofil wählen.');

    $t->call('zuruckziehen')->assertSet('presentationHinweis', 'Veröffentlichung zurückgezogen — der Link ist jetzt inaktiv (404).');
    expect($this->angebot->fresh()->isPresentationLive())->toBeFalse();
});

it('KI-Vorschau: holen, Übernehmen füllt das Feld der richtigen Ebene; ohne Provider leere Vorschau ohne Hinweis', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü']);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    app()->instance(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['text' => 'Ein Fest im Grünen.'], 'confidence' => 0.8]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'stub', 'tool_calls' => null];
        }
    });
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)
        ->call('kiEinleitung')->assertSet('kiTextZiel', 'angebot')->assertSet('kiTextVorschau', 'Ein Fest im Grünen.')
        ->assertSet('form.brief', '')
        ->call('kiTextUebernehmen')
        ->assertSet('form.brief', 'Ein Fest im Grünen.')->assertSet('kiTextVorschau', null)
        ->assertSet('kiTextHinweis', 'Text steht im Feld — noch nicht gespeichert („Speichern" oben).');
    expect($this->angebot->fresh()->brief)->toBeNull();

    $t->call('kapitelWaehle', $kap->id)->call('kiKapitelText')->assertSet('kiTextZiel', 'kapitel')->assertSet('kiTextVorschau', 'Ein Fest im Grünen.')
        ->call('kiTextUebernehmen')->assertSet('kapitelForm.description', 'Ein Fest im Grünen.')
        ->assertSet('kiTextHinweis', 'Text steht im Kapitel-Feld — noch nicht gespeichert (Feld verlassen speichert).')
        ->call('kiTextVerwerfen')->assertSet('kiTextHinweis', null);

    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => throw new \Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException());
    $t->call('kiEinleitung')->assertSet('kiTextVorschau', '')->assertSet('kiTextHinweis', null);   // der Dienst fängt den Ausfall selbst ab
});

it('Kopf: Formular lädt beim Öffnen, speichern schreibt', function () {
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id)
        ->assertSet('form.name', 'Charakter-Angebot')->assertSet('form.occasion', 'Sommerfest')
        ->set('form.location', 'Rheinterrasse')->call('speichern');
    expect($this->angebot->fresh()->location)->toBe('Rheinterrasse');
});
