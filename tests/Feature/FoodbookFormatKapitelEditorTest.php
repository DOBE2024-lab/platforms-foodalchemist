<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\LeitstelleService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Foodbook-Editor nach „Format einfügen": das Kapitel ist ein LEBENDES Format-Kapitel (Entscheid B
 * 2026-08-31, nur `format_id`, keine Blöcke). Vorher zeigte der Editor „0 Einträge / Noch kein Inhalt",
 * Board „Leer/Textkapitel" und „Kapitel fertig 0 von 1". Jetzt: Editionen schreibgeschützt aus dem
 * Format (dieselbe Quelle wie Dokument/PDF), Hinweis + Link zum Format, Preisspanne, fertig ab 1 Edition.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));
    $concepts = app(ConceptService::class);
    $formate = app(FormatService::class);

    $mkEdition = function (string $name, float $preis) use ($concepts) {
        $c = $concepts->create($this->rootTeam, ['name' => $name]);
        $c->update(['status' => 'active', 'consumer_name' => $name . ' Edition', 'price_per_person_cache' => $preis]);
        $recipe = $this->makeRecipe($this->rootTeam, $name . '-Bowl', ['is_sales_recipe' => true, 'status' => 'approved']);
        $this->makeConceptSlot($c, ['position' => 1, 'sales_recipe_id' => $recipe->id, 'wording' => $name . ' Bowl mit Kräutern']);

        return $c;
    };
    $this->format = $formate->create($this->rootTeam, ['name' => 'TASTE & FLY', 'consumer_name' => 'Taste and Fly', 'story' => 'Abflug ins Menü.']);
    $formate->slotConceptEinfuegen($this->rootTeam, $this->format->id, $mkEdition('Lissabon', 29.5)->id);
    $formate->slotConceptEinfuegen($this->rootTeam, $this->format->id, $mkEdition('Kyoto', 34.0)->id);

    $this->buch = $this->makeFoodbook($this->rootTeam, 'Kundenbuch');
    $this->formatKapitel = app(FoodbookService::class)->insertFormatAlsKapitel($this->rootTeam, $this->buch->id, $this->format->id);
});

it('★ Editor: Format-Kapitel zeigt die Editionen aus dem Format, Hinweis und Link statt „Noch kein Inhalt"', function () {
    $html = Livewire::test(Index::class)
        ->call('waehle', $this->buch->id)
        ->call('kapitelWaehle', $this->formatKapitel->id)
        ->html();

    expect($html)->toContain('data-fb-format-kapitel')
        ->toContain('Lissabon Edition')
        ->toContain('Kyoto Edition')
        ->toContain('29,50 € pro Gast')
        ->toContain('Kommt live aus dem Format')
        ->toContain('2 Editionen')
        ->toContain('29,50 – 34,00 € pro Gast')
        ->toContain('data-fb-format-link')
        ->toContain('sel=' . $this->format->id)
        ->not->toContain('Noch kein Inhalt')
        ->not->toContain('0 Einträge');
});

it('Gegenprobe: normales Kapitel ohne Inhalt zeigt weiter „Noch kein Inhalt" und keine Format-Ansicht', function () {
    $normal = $this->makeChapter($this->buch, ['title' => 'Dessert']);

    $html = Livewire::test(Index::class)
        ->call('waehle', $this->buch->id)
        ->call('kapitelWaehle', $normal->id)
        ->html();

    expect($html)->toContain('Noch kein Inhalt')
        ->toContain('0 Einträge')
        ->not->toContain('data-fb-format-kapitel');
});

it('Board: Format-Kapitel mit Edition gilt als fertig und trägt Editionen + Preisspanne; normales Kapitel bleibt manuell', function () {
    $normal = $this->makeChapter($this->buch, ['title' => 'Dessert']);

    $board = collect(app(LeitstelleService::class)->kapitelBoard($this->rootTeam, $this->buch->fresh()))->keyBy('kapitel_id');
    $f = $board->get((int) $this->formatKapitel->id);
    $n = $board->get((int) $normal->id);

    expect($f['fortschritt'])->toBe('fertig')
        ->and($f['fortschritt_auto'])->toBeTrue()
        ->and($f['format']['editionen_n'])->toBe(2)
        ->and($f['format']['preis_range'])->toBe(['min' => 29.5, 'max' => 34.0])
        ->and($n['fortschritt'])->toBe('offen')
        ->and($n['fortschritt_auto'])->toBeFalse()
        ->and($n['format'])->toBeNull();
});

it('Kopf-Kennzahlen: Kapitel fertig zählt das Format-Kapitel, Preisspanne der Formate, Speisen aus den Editionen', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->buch->id);
    // Speisen-Kennzahl kommt (wie bisher) aus der gespeicherten Vorschau.
    app(FoodbookService::class)->vorschauSnapshotAktualisieren($this->rootTeam, $this->buch->id);
    $html = $t->call('waehle', $this->buch->id)->html();

    $kpi = function (string $key) use ($html): ?string {
        return preg_match('/data-kpi="' . preg_quote($key, '/') . '".*?<dd[^>]*>\s*(.*?)\s*<\/dd>/s', $html, $m) ? trim(strip_tags($m[1])) : null;
    };

    expect($kpi('fertig'))->toBe('1 von 1')
        ->and($kpi('format-preis'))->toBe('29,50 – 34,00 € pro Gast')
        ->and($kpi('speisen'))->toBe('2');
});

it('Format ohne Edition: kein Auto-fertig, Editor zeigt den Leerzustand des Formats', function () {
    $leer = app(FormatService::class)->create($this->rootTeam, ['name' => 'LEER.FORMAT']);
    $kap = app(FoodbookService::class)->insertFormatAlsKapitel($this->rootTeam, $this->buch->id, $leer->id);

    $board = collect(app(LeitstelleService::class)->kapitelBoard($this->rootTeam, $this->buch->fresh()))->keyBy('kapitel_id');
    expect($board->get((int) $kap->id)['fortschritt'])->toBe('offen')
        ->and($board->get((int) $kap->id)['fortschritt_auto'])->toBeFalse();

    $html = Livewire::test(Index::class)->call('waehle', $this->buch->id)->call('kapitelWaehle', $kap->id)->html();
    expect($html)->toContain('Das Format hat noch keine Editionen')->not->toContain('Noch kein Inhalt');
});

it('Editor und Dokument lesen dieselbe Editionen-Quelle (formatEditionen)', function () {
    $svc = app(FoodbookService::class);
    $ansicht = $svc->formatKapitelAnsicht($this->formatKapitel->fresh());
    $dok = collect($svc->dokumentDaten($this->rootTeam, $this->buch->fresh())['kapitel'])->firstWhere('ist_format', true);

    expect(collect($ansicht['editionen'])->pluck('name')->all())->toBe(collect($dok['editionen'])->pluck('name')->all())
        ->and($ansicht['editionen_n'])->toBe(2)
        ->and($ansicht['speisen_n'])->toBe(2);
});

it('Dokument (Druck-HTML) zeigt die Editionen des Format-Kapitels', function () {
    $this->get(route('foodalchemist.foodbooks.dokument', $this->buch->id))
        ->assertOk()
        ->assertSee('Lissabon Edition')
        ->assertSee('Kyoto Edition');
});
