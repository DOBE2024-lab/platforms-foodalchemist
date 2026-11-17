<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lebendes Format-Kapitel (B 2026-08-31) in Präsentation und Editor:
 *  - die Foodbook-Präsentation (Vorschau im Editor + öffentlicher Link) zeigt die Editionen des Formats
 *    wie das Angebot (vorher nur Titel/Text, normalizeFoodbook las nur `bloecke`);
 *  - ein Format-Kapitel nimmt keine eigenen Blöcke: Concept-/Paket-Katalog entfällt, addBlock sperrt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));
    $concepts = app(ConceptService::class);
    $formate = app(FormatService::class);

    $this->edition = $concepts->create($this->rootTeam, ['name' => 'Lissabon']);
    $this->edition->update(['status' => 'active', 'consumer_name' => 'Lissabon Edition', 'price_per_person_cache' => 29.5]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Bacalhau-Bowl', ['is_sales_recipe' => true, 'status' => 'approved']);
    $this->makeConceptSlot($this->edition, ['position' => 1, 'sales_recipe_id' => $recipe->id, 'wording' => 'Bacalhau mit Kichererbsen']);

    $this->format = $formate->create($this->rootTeam, ['name' => 'TASTE & FLY', 'consumer_name' => 'Taste and Fly']);
    $formate->slotBlockEinfuegen($this->rootTeam, $this->format->id, 'header', ['title' => 'Abflug Süd']);
    $formate->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->edition->id);

    $this->buch = $this->makeFoodbook($this->rootTeam, 'Kundenbuch');
    $this->formatKapitel = app(FoodbookService::class)->insertFormatAlsKapitel($this->rootTeam, $this->buch->id, $this->format->id);
});

it('★ Präsentations-Daten: Format-Kapitel trägt die Editionen als Blöcke (Überschrift, Edition mit Preis, Gericht-Zeile)', function () {
    $snap = app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id);
    $sektion = collect($snap['content']['sections'])->firstWhere('title', 'Taste and Fly');

    expect($sektion)->not->toBeNull();
    $bloecke = collect($sektion['blocks']);
    expect($bloecke->firstWhere('kind', 'header')['label'] ?? null)->toBe('Abflug Süd');
    $ed = $bloecke->firstWhere('kind', 'concept_ref');
    expect($ed)->not->toBeNull()
        ->and($ed['label'])->toBe('Lissabon Edition')
        ->and($ed['price'])->toBe(['pp' => 29.5, 'pauschal' => 0.0, 'unit' => 'gast'])
        ->and(collect($ed['items'])->pluck('label')->all())->toContain('Bacalhau mit Kichererbsen');
    // Allowlist: keine Interna in den Items
    expect(array_keys($ed['items'][0]))->toBe(['kind', 'label', 'indent', 'price', 'codes', 'image']);
});

it('★ Präsentation im Editor (Vorschau-Route) und öffentlicher Link zeigen die Edition', function () {
    $this->get(route('foodalchemist.foodbooks.praesentation', $this->buch->id))
        ->assertOk()->assertSee('Lissabon Edition')->assertSee('Bacalhau mit Kichererbsen');

    $res = app(PresentationService::class)->publish($this->rootTeam, 'foodbook', $this->buch->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    auth()->logout();
    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()->assertSee('Lissabon Edition')->assertSee('Bacalhau mit Kichererbsen');
});

it('★ addBlock auf ein Format-Kapitel wird mit verständlicher Meldung gesperrt; normales Kapitel geht weiter', function () {
    $svc = app(FoodbookService::class);

    expect(fn () => $svc->addBlock($this->rootTeam, $this->formatKapitel->id, ['type' => 'concept_ref', 'concept_id' => $this->edition->id]))
        ->toThrow(\RuntimeException::class, 'kommt live aus dem Format „TASTE & FLY“');
    expect(FoodAlchemistFoodbookBlock::where('chapter_id', $this->formatKapitel->id)->count())->toBe(0);

    $normal = $this->makeChapter($this->buch, ['title' => 'Dessert']);
    $block = $svc->addBlock($this->rootTeam, $normal->id, ['type' => 'concept_ref', 'concept_id' => $this->edition->id]);
    expect((int) $block->chapter_id)->toBe((int) $normal->id);
});

it('★ Editor: bei Format-Kapitel fehlen Concept- und Paket-Katalog, „Formate" bleibt; Gegenprobe normales Kapitel', function () {
    $normal = $this->makeChapter($this->buch, ['title' => 'Dessert']);
    $t = Livewire::test(Index::class)->call('waehle', $this->buch->id);

    $html = $t->call('kapitelWaehle', $this->formatKapitel->id)->html();
    expect($html)->toContain('data-fb-katalog')
        ->not->toContain('data-fb-kat="concept"')
        ->not->toContain('data-fb-katalog-concept')
        ->not->toContain('data-fb-kat="paket"')
        ->toContain('data-fb-katalog-format');

    $html = $t->call('kapitelWaehle', $normal->id)->html();
    expect($html)->toContain('data-fb-kat="concept"')
        ->toContain('data-fb-kat="paket"')
        ->toContain('data-fb-katalog-concept');
});
