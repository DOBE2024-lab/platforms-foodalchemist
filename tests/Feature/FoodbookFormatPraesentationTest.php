<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Concepter\FormatService;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationService;
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
        ->and($ed['price'])->toBe(['pp' => 29.5, 'pauschal' => 0.0, 'unit' => 'gast', 'vorlaeufig' => false])
        ->and(collect($ed['items'])->pluck('label')->all())->toContain('Bacalhau mit Kichererbsen');
    // Allowlist: keine Interna in den Items
    expect(array_keys($ed['items'][0]))->toBe(['kind', 'label', 'indent', 'price', 'codes', 'image']);
});

it('Präsentation: Hinweistexte der Edition („serviert im Schraubglas“) kommen auch im Format-Kapitel an (Fix 2026-11-19)', function () {
    $this->makeConceptSlot($this->edition, ['type' => 'text', 'title' => null, 'text_content' => 'serviert im Schraubglas', 'position' => 0]);

    $snap = app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id);
    $ed = collect(collect($snap['content']['sections'])->firstWhere('title', 'Taste and Fly')['blocks'])->firstWhere('kind', 'concept_ref');
    $text = collect($ed['items'])->firstWhere('kind', 'text');

    expect($text)->not->toBeNull()->and($text['label'])->toBe('serviert im Schraubglas');
    // Editor-Ansicht des Formats bleibt ohne Texte (nur die Präsentation fragt sie an).
    expect(collect(app(\Platform\FoodAlchemist\Services\Concepter\FormatKapitelService::class)->editionen($this->format->fresh())[0]['gerichte'] ?? [])->pluck('type')->all())->not->toContain('text');
});

it('Interne Vorschau mit EK: die Edition im Format-Kapitel trägt ihren EK pro Person, der Kundenlink nicht (Fix 2026-11-20)', function () {
    $this->edition->update(['ek_per_person_cache' => 8.4]);

    $ed = fn (array $snap) => collect(collect($snap['content']['sections'])->firstWhere('title', 'Taste and Fly')['blocks'])->firstWhere('kind', 'concept_ref');
    $intern = $ed(app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id, null, true));
    $kunde = $ed(app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id));

    expect($intern['ek'] ?? null)->toBe(['pp' => 8.4])
        ->and($kunde)->not->toHaveKey('ek');
    // Editionen ohne intern-Flag tragen gar kein EK-Feld (Dokument/PDF für Kunden).
    expect(app(\Platform\FoodAlchemist\Services\Concepter\FormatKapitelService::class)->editionen($this->format->fresh())[0])->not->toHaveKey('ek_pp');
});

it('Interne Vorschau mit EK: eingebettetes Paket zeigt seinen EK je Person, der Kundenlink nicht (2026-11-20)', function () {
    $paket = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Italien', 'kind' => 'paket', 'status' => 'active',
        'price_per_person_cache' => 7.0, 'ek_per_person_cache' => 2.1]);
    $this->makeConceptSlot($this->edition, ['position' => 2, 'embedded_concept_id' => $paket->id]);
    // Caches direkt setzen (ein leeres Paket rechnet beim Speichern seinen EK neu)
    \Illuminate\Support\Facades\DB::table('foodalchemist_concepts')->where('id', $paket->id)->update(['price_per_person_cache' => 7.0, 'ek_per_person_cache' => 2.1]);

    $zeile = fn (array $snap) => collect(collect(collect($snap['content']['sections'])->firstWhere('title', 'Taste and Fly')['blocks'])
        ->firstWhere('kind', 'concept_ref')['items'])->firstWhere('kind', 'paket');
    $intern = $zeile(app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id, null, true));
    $kunde = $zeile(app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id));

    expect($intern['label'])->toBe('Italien')->and($intern['price'])->toBe(7.0)->and($intern['ek'] ?? null)->toBe(2.1)
        ->and($kunde)->not->toHaveKey('ek');
    $html = view('foodalchemist::presentation.show', ['snapshot' => app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id, null, true)])->render();
    expect($html)->toContain('EK 2,10 € · 30 %');
});

it('Sprungmenü: mit „Kapitel und Pakete“ führt es die Pakete als Unterpunkte mit Anker, ohne Option nicht (2026-11-20)', function () {
    $paket = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Italien', 'kind' => 'paket', 'status' => 'active']);
    $this->makeConceptSlot($this->edition, ['position' => 2, 'embedded_concept_id' => $paket->id]);
    $snap = app(PresentationService::class)->previewData($this->rootTeam, 'foodbook', $this->buch->id);
    $snap['resolved_design']['layout'] = [['block_type' => 'chapter_loop', 'style' => []]];
    $anker = 'k' . $this->formatKapitel->id . '-p1';

    $render = function (array $tokens) use ($snap) {
        $snap['resolved_design']['tokens'] = $tokens + ($snap['resolved_design']['tokens'] ?? []);

        return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
    };
    $mit = $render(['nav' => 'anchor', 'nav_umfang' => 'pakete']);
    expect($mit)->toContain('id="' . $anker . '"')
        ->toMatch('/data-pt-navlink="' . $anker . '" class="pt-d1">Italien</');

    $ohne = $render(['nav' => 'anchor', 'nav_umfang' => 'kapitel']);
    expect($ohne)->not->toContain($anker);
    expect(fn () => \Platform\FoodAlchemist\Support\PraesentationsDesignWerte::pruefe(['nav_umfang' => 'alles'], []))->toThrow('nav_umfang muss kapitel|pakete sein');
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

it('★ Handbuch eines Foodbooks: Format-Kapitel liefert die Gerichte der Editionen (io 18.11.: Handbuch zeigte 0 Kapitel, 0 Speisen)', function () {
    $quelle = app(\Platform\FoodAlchemist\Services\Ausgabe\HandbuchQuellenService::class)
        ->lies($this->rootTeam, \Platform\FoodAlchemist\Services\Ausgabe\HandbuchService::TYPE_FOODBOOK, $this->buch->fresh());
    $items = collect($quelle['sections'])->flatMap(fn ($s) => $s['items']);

    expect($items)->not->toBeEmpty()
        ->and($items->pluck('label')->all())->toContain('Bacalhau mit Kichererbsen');
});
