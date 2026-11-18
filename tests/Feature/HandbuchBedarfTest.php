<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
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

it('rechnet ein stückbezogenes Basisrezept über dessen Stückausbeute', function () {
    $stueck = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $this->sauce->update(['yield_pieces' => 10]);
    $this->sauceZeile->update(['quantity' => 2, 'unit_vocab_id' => $stueck->id]);
    $this->gericht->update(['yield_kg' => .32, 'yield_kg_manual' => .32]);
    $this->dar->update(['quantity_per_unit_g' => 320]);
    $r = $this->svc->fuerGericht($this->rootTeam, $this->gericht->fresh());
    expect($r['zeilen'][0]['menge'])->toBe(2.0)->and($r['zeilen'][0]['sub_faktor'])->toBe(.2);
});
