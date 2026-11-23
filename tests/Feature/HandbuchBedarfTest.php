<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\HandbuchService;
use Platform\FoodAlchemist\Services\HandbuchZutatenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Tomate-Mascarpone');
    $this->makeIngredient($this->sauce, 'Tomaten', $this->makeGp($this->rootTeam, 'Tomaten'), '800');
    $this->makeIngredient($this->sauce, 'Mascarpone', $this->makeGp($this->rootTeam, 'Mascarpone'), '200', 2);
    $this->sauce->update(['yield_kg' => 1, 'yield_kg_manual' => 1, 'notes_manual' => 'NUR INTERN']);
    $this->pasta = $this->makeRecipe($this->rootTeam, 'Pasta: Linguine', ['yield_kg' => 1]);
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Linguine mit Soße', ['is_sales_recipe' => true]);
    $this->sauceZeile = $this->makeIngredient($this->gericht, 'Soße', null, '80');
    $this->sauceZeile->update(['referenced_recipe_id' => $this->sauce->id]);
    $this->makeIngredient($this->gericht, 'Linguine', null, '120', 2)->update(['referenced_recipe_id' => $this->pasta->id]);
    $this->gericht->update(['yield_kg' => .2, 'yield_kg_manual' => .2, 'sales_unit_count' => 1]);
    $this->dar = $this->makeStandardDarreichung($this->gericht, null, ['quantity_per_unit_g' => 200]);
    $this->svc = app(HandbuchZutatenService::class);
});

it('trägt den Basisrezeptfaktor je Gerichtsportion aus der zentralen Hochrechnung', function () {
    $r = $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect($r['basis'])->toBe('portion')->and($r['zeilen'][0]['menge'])->toBe(80.0)
        ->and($r['zeilen'][0]['sub_faktor'])->toBe(.08);
    $b = $this->svc->basisrezepte()['b'.$this->sauce->id];
    expect($b['zeilen'][0]['menge'])->toBe(800.0)->and($b['zeilen'][1]['menge'])->toBe(200.0)
        ->and($b['ansatz_kg'])->toBe(1.0);
    // 10 Portionen benötigen 800 g Soße: 640 g Tomaten + 160 g Mascarpone.
    expect($b['zeilen'][0]['menge'] * $r['zeilen'][0]['sub_faktor'] * 10)->toBe(640.0);
});

it('friert die eigenen Basisrezept-Allergene getrennt von Gericht und Spuren ein und kennzeichnet Unbekanntes', function () {
    $this->gericht->update(['allergen_gluten' => 'enthalten']);
    $this->sauce->update(['allergen_milk' => 'enthalten', 'allergen_soy' => 'spuren', 'allergen_tree_nuts' => 'unbekannt']);
    $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    $k = $this->svc->basisrezepte()['b'.$this->sauce->id]['kennzeichnung'];
    expect(array_column($k['enthalten'], 'code'))->toBe(['G'])
        ->and(array_column($k['spuren'], 'code'))->toBe(['F'])
        ->and($k['enthalten'][0]['label'])->toBe('Milch')
        ->and($k['offen'])->toBeTrue()
        ->and(json_encode($k))->not->toContain('Gluten');
    $this->sauce->update(['allergen_tree_nuts' => 'nicht_enthalten']);
    expect(HandbuchService::kennzeichnungSnapshot($this->sauce->fresh())['offen'])->toBeFalse();
});

it('berücksichtigt eigene Komponentenmengen der Standard-Darreichung statt proportional alles zu verkleinern', function () {
    app(DarreichungService::class)->setzeDelta($this->rootTeam, $this->dar->id, $this->sauceZeile->id, 40, false);
    $r = $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect($r['zeilen'][0]['menge'])->toBe(40.0)->and($r['zeilen'][0]['sub_faktor'])->toBe(.04)
        ->and($r['zeilen'][1]['menge'])->toBe(120.0);
});

it('bevorzugt die gepflegte Ausbeute des Basisrezepts und erfindet ohne Ausbeute keinen Faktor', function () {
    $this->sauce->update(['yield_kg_manual' => .5]);
    $r = $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect($r['zeilen'][0]['sub_faktor'])->toBe(.16)
        ->and($this->svc->basisrezepte()['b'.$this->sauce->id]['ansatz_kg'])->toBe(.5);
    $this->svc->zuruecksetzen();
    $this->sauce->update(['yield_kg' => null, 'yield_kg_manual' => null]);
    $r = $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect($r['zeilen'][0]['sub_faktor'])->toBeNull();
});

it('übernimmt Rezept- und Schrittbilder als Identifier und löst URLs erst beim Lesen auf', function () {
    $step = FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->sauce->id, 'position' => 1, 'ebene' => 'produktion', 'text' => 'Tomaten garen.']);
    $photo = FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->sauce->id, 'pfad' => 'handbuch/schritt.webp', 'caption' => 'Soße pürieren']);
    $step->photos()->attach($photo->id, ['position' => 1]);
    FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->sauce->id, 'pfad' => 'handbuch/ergebnis.webp', 'caption' => 'Fertige Soße', 'is_result' => true]);
    $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    $basis = $this->svc->basisrezepte(); $b = $basis['b'.$this->sauce->id];
    expect($b['foto']['path'])->toBe('handbuch/ergebnis.webp')
        ->and($b['zubereitung'][0]['fotos'][0]['path'])->toBe('handbuch/schritt.webp')
        ->and(json_encode($basis))->not->toContain('NUR INTERN', 'https://', 'ek_', 'sales_net');
    $media = Mockery::mock(FoodAlchemistMediaService::class);
    $media->shouldReceive('url')->andReturnUsing(fn ($id, $path) => 'https://bilder.test/'.basename($path));
    app()->instance(FoodAlchemistMediaService::class, $media);
    $live = app(HandbuchService::class)->hydrateImages(['basisrezepte' => $basis]);
    expect($live['basisrezepte']['b'.$this->sauce->id]['foto_url'])->toBe('https://bilder.test/ergebnis.webp')
        ->and($live['basisrezepte']['b'.$this->sauce->id]['zubereitung'][0]['fotos'][0]['url'])->toBe('https://bilder.test/schritt.webp');
    $this->sauce->stepPhotos()->update(['is_result' => false]);
    $photo->update(['is_result' => true]);
    expect(HandbuchService::rezeptFotoSnapshot($this->sauce->fresh())['path'])->toBe('handbuch/schritt.webp');
});

it('erhält Format und Konzepte als getrennte Navigationsebenen im Handbuch-Snapshot', function () {
    $formatSvc = app(FormatService::class);
    $format = $formatSvc->create($this->rootTeam, ['name' => 'Menü Test and Fly']);
    foreach (['Italien', 'Dessert'] as $name) {
        $concept = $this->makeConcept($this->rootTeam, $name);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $this->gericht->id, 'position' => 1]);
        $formatSvc->slotConceptEinfuegen($this->rootTeam, $format->id, $concept->id);
    }
    $fb = $this->makeFoodbook($this->rootTeam, 'Testbuch');
    app(FoodbookService::class)->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);
    $snap = app(HandbuchService::class)->buildSnapshot($this->rootTeam, $fb->fresh(), 'foodbook');
    expect($snap['sections'][0]['title'])->toBe('Menü Test and Fly')
        ->and(array_column($snap['sections'][0]['groups'], 'title'))->toBe(['Italien', 'Dessert'])
        ->and($snap['sections'][0]['groups'][0]['items'][0]['key'])->toBe('r'.$this->gericht->id);
});

it('liest vollständige Basisrezepte auch über mehrere weitere Ebenen ohne feste Tiefengrenze', function () {
    $parent = $this->sauce;
    foreach (['Fond', 'Röstgemüse', 'Gemüsefond', 'Gewürzansatz'] as $name) {
        $kind = $this->makeRecipe($this->rootTeam, $name, ['yield_kg' => 1]);
        $this->makeIngredient($parent, $name, null, '100', 3)->update(['referenced_recipe_id' => $kind->id]);
        $parent = $kind;
    }
    $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect(array_column($this->svc->basisrezepte(), 'name'))->toContain('Gewürzansatz');
});

it('erhält verschachtelte Pakete, echte Verteilung und getrennte Gerichtskarten je Position ohne Preisfelder', function () {
    $paket = $this->makeConcept($this->rootTeam, 'Deutschland');
    foreach ([.33, 1, .33] as $i => $menge) {
        $this->makeConceptSlot($paket, ['sales_recipe_id' => $this->gericht->id, 'quantity' => $menge, 'position' => $i, 'wording' => 'Gang '.$i]);
    }
    $innen = $this->makeConcept($this->rootTeam, 'Länderauswahl');
    $this->makeConceptSlot($innen, ['type' => 'paket', 'embedded_concept_id' => $paket->id]);
    $concept = $this->makeConcept($this->rootTeam, 'Taste & Fly');
    $this->makeConceptSlot($concept, ['type' => 'paket', 'embedded_concept_id' => $innen->id]);
    $format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Flugmenü']);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $format->id, $concept->id);
    $fb = $this->makeFoodbook($this->rootTeam, 'Baumbuch');
    app(FoodbookService::class)->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);
    $snap = app(HandbuchService::class)->buildSnapshot($this->rootTeam, $fb->fresh(), 'foodbook');
    $gruppe = $snap['sections'][0]['groups'][0];
    expect($gruppe['nodes'][0]['label'])->toBe('Länderauswahl')
        ->and($gruppe['nodes'][0]['children'][0]['label'])->toBe('Deutschland')
        ->and(array_column($gruppe['items'], 'menge_pro_gast'))->toBe([.33, 1.0, .33])
        ->and(array_column($gruppe['items'], 'portionen_pro_gast'))->toBe([.33, 1.0, .33])
        ->and(count(array_unique(array_column($gruppe['items'], 'key'))))->toBe(3)
        ->and(count($snap['gerichte']))->toBe(3)
        ->and(json_encode($snap))->not->toContain('sales_net', 'ek_', 'price', 'presentation_id', 'recipe_id');
});

it('verwendet die gewählte Darreichung für Mengenanzeige und Rezeptbedarf einer Position', function () {
    $dar = app(DarreichungService::class)->anlegen($this->rootTeam, $this->gericht->id, $this->dar->serving_form_id);
    $dar = app(DarreichungService::class)->setzeAnteil($this->rootTeam, $dar->id, 50);   // Plan 98 B: Variante als Anteil vom Standard (statt freier Grammatur): 100 von 200 g
    $concept = $this->makeConcept($this->rootTeam, 'Kleines Glas');
    $slot = $this->makeConceptSlot($concept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 50, 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'presentation_id' => $dar->id]);
    $menge = app(\Platform\FoodAlchemist\Services\HandbuchPositionsMenge::class)->fuer($slot);
    expect($menge['portionen_pro_gast'])->toBe(.5);
    $karte = app(HandbuchService::class)->gerichtKarte($this->rootTeam, $this->gericht->fresh(), 'Kleines Glas', true, $dar);
    expect((float) $karte['portion']['gramm'])->toBe(100.0)
        ->and($karte['zutaten']['zeilen'][0]['menge'])->toBe(40.0)
        ->and($karte['zutaten']['zeilen'][0]['sub_faktor'])->toBe(.04);
    $slot->update(['quantity' => null]);
    expect(app(\Platform\FoodAlchemist\Services\HandbuchPositionsMenge::class)->fuer($slot->fresh())['portionen_pro_gast'])->toBeNull();
});

it('übernimmt aus einer fehlerhaften Verknüpfung keine Gerichtskarte eines nicht sichtbaren Teams', function () {
    $fremd = $this->makeRecipe($this->childA, 'NICHT SICHTBARES GERICHT', ['is_sales_recipe' => true]);
    $concept = $this->makeConcept($this->rootTeam, 'Sichtbares Konzept');
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $fremd->id, 'quantity' => .33]);
    $format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Sichtbares Format']);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $format->id, $concept->id);
    $fb = $this->makeFoodbook($this->rootTeam, 'Buch');
    app(FoodbookService::class)->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);
    $snap = app(HandbuchService::class)->buildSnapshot($this->rootTeam, $fb->fresh(), 'foodbook');
    expect($snap['gerichte'])->toBe([])->and($snap['sections'])->toBe([])
        ->and(json_encode($snap))->not->toContain('NICHT SICHTBARES GERICHT');
});

it('beendet einen zyklischen Paketverweis im Handbuch-Mengenpfad', function () {
    $a = $this->makeConcept($this->rootTeam, 'A'); $b = $this->makeConcept($this->rootTeam, 'B');
    $this->makeConceptSlot($a, ['type' => 'paket', 'embedded_concept_id' => $b->id]);
    $this->makeConceptSlot($b, ['type' => 'paket', 'embedded_concept_id' => $a->id]);
    $rows = app(\Platform\FoodAlchemist\Services\WordingResolver::class)->gerichtZeilen($a, null, false, true);
    expect(count($rows))->toBe(2);
});

it('rechnet ein stückbezogenes Basisrezept über dessen Stückausbeute', function () {
    $stueck = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $this->sauce->update(['yield_pieces' => 10]);
    $this->sauceZeile->update(['quantity' => 2, 'unit_vocab_id' => $stueck->id]);
    $this->gericht->update(['yield_kg' => .32, 'yield_kg_manual' => .32]);
    $this->dar->update(['quantity_per_unit_g' => 320]);
    $r = $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect($r['zeilen'][0]['menge'])->toBe(2.0)->and($r['zeilen'][0]['sub_faktor'])->toBe(.2);
});
