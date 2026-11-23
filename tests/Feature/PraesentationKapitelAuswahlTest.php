<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationDesignService;
use Platform\FoodAlchemist\Services\Ausgabe\PresentationService;
use Platform\FoodAlchemist\Support\KapitelAuswahl;
use Platform\FoodAlchemist\Support\KapitelPreis;
use Platform\FoodAlchemist\Support\PraesentationsDesignWerte;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Präsentation 2026-11-19 — Kapitel einzeln setzen (Kapitel-Auswahl alle | bereich | rest) und Preis je Kapitel,
 * für Foodbook UND Angebot; Preis-Aufschlüsselung auch im Foodbook. Design gilt für viele Dokumente → Position zählt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Kapitel'));
    $this->pres = app(PresentationService::class);

    $this->baueFb = function (?int $personen = 10) {
        $fb = $this->makeFoodbook($this->rootTeam, 'Sommerfest', ['personen' => $personen]);
        foreach ([['Aperitif', 12.5], ['Hauptgang', 36.0], ['Dessert', 9.0]] as $pos => [$titel, $preis]) {
            $kap = $this->makeChapter($fb, ['title' => $titel, 'consumer_title' => $titel, 'position' => $pos + 1,
                'price_mode' => 'manuell', 'price_per_person' => $preis]);
            $this->makeFoodbookBlock($kap, ['type' => 'text', 'customer_text' => 'Text ' . $titel, 'position' => 1]);
        }

        return $fb->refresh();
    };
    $this->html = function (array $snap, array $layout) {
        $snap['resolved_design']['layout'] = $layout;

        return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
    };
});

it('zählt Hauptkapitel samt Unterkapiteln und filtert Bereich und Rest', function () {
    $sections = [
        ['title' => 'A', 'depth' => 0], ['title' => 'A1', 'depth' => 1],
        ['title' => 'B', 'depth' => 0], ['title' => 'C', 'depth' => 0],
    ];
    expect(KapitelAuswahl::nummern($sections))->toBe([0 => 1, 1 => 1, 2 => 2, 3 => 3]);

    $bereich = ['kapitel_auswahl' => 'bereich', 'kapitel_von' => 1, 'kapitel_bis' => 1];
    expect(array_column(KapitelAuswahl::filtern($sections, $bereich), 'title'))->toBe(['A', 'A1']);
    // bis leer = bis zum Ende; vertauscht = gedreht
    expect(array_column(KapitelAuswahl::filtern($sections, ['kapitel_auswahl' => 'bereich', 'kapitel_von' => '2', 'kapitel_bis' => '']), 'title'))->toBe(['B', 'C']);
    expect(array_column(KapitelAuswahl::filtern($sections, ['kapitel_auswahl' => 'bereich', 'kapitel_von' => 3, 'kapitel_bis' => 2]), 'title'))->toBe(['B', 'C']);

    $vergeben = KapitelAuswahl::vergeben([['block_type' => 'chapter_loop', 'style' => $bereich]], $sections);
    expect(array_column(KapitelAuswahl::filtern($sections, ['kapitel_auswahl' => 'rest'], $vergeben), 'title'))->toBe(['B', 'C']);
    expect(KapitelAuswahl::filtern($sections, []))->toBe($sections);

    expect(KapitelAuswahl::titel($bereich))->toBe('Kapitel 1')
        ->and(KapitelAuswahl::titel(['kapitel_auswahl' => 'bereich', 'kapitel_von' => 2, 'kapitel_bis' => 4]))->toBe('Kapitel 2 bis 4')
        ->and(KapitelAuswahl::titel(['kapitel_auswahl' => 'bereich', 'kapitel_von' => 2]))->toBe('Kapitel ab 2')
        ->and(KapitelAuswahl::titel(['kapitel_auswahl' => 'rest']))->toBe('Übrige Kapitel')
        ->and(KapitelAuswahl::titel([]))->toBe('Alle Kapitel');
});

it('Foodbook: einzelnes Kapitel vor die übrigen setzen, jedes Kapitel genau einmal', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(), 'foodbook', []);
    $html = ($this->html)($snap, [
        ['block_type' => 'chapter_loop', 'style' => ['kapitel_auswahl' => 'bereich', 'kapitel_von' => 2, 'kapitel_bis' => 2]],
        ['block_type' => 'heading', 'style' => ['text' => 'Dazwischen']],
        ['block_type' => 'chapter_loop', 'style' => ['kapitel_auswahl' => 'rest']],
    ]);

    $pos = fn (string $t) => strpos($html, 'data-pt-title="' . $t . '"');
    expect($pos('Hauptgang'))->toBeLessThan(strpos($html, 'Dazwischen'))
        ->and(strpos($html, 'Dazwischen'))->toBeLessThan($pos('Aperitif'))
        ->and($pos('Aperitif'))->toBeLessThan($pos('Dessert'));
    foreach (['Aperitif', 'Hauptgang', 'Dessert'] as $t) {
        expect(substr_count($html, 'data-pt-title="' . $t . '"'))->toBe(1);
    }
});

it('Preis je Kapitel: Foodbook zeigt VK pro Person im Kapitelkopf, nur mit Schalter und Preisanzeige', function () {
    $fb = ($this->baueFb)();
    $snap = $this->pres->buildSnapshot($this->rootTeam, $fb, 'foodbook', []);
    expect($snap['content']['sections'][1]['preis'])->toBe(['pp' => 36.0, 'min' => null, 'max' => null]);

    $mit = ($this->html)($snap, [['block_type' => 'chapter_loop', 'style' => ['show_kapitel_preis' => true, 'kapitel_preis_label' => 'je Gast']]]);
    expect($mit)->toContain('data-pt-kapitel-preis')->toContain('36,00 €')->toContain('je Gast');
    expect(($this->html)($snap, [['block_type' => 'chapter_loop', 'style' => []]]))->not->toContain('data-pt-kapitel-preis');

    $ohnePreise = $this->pres->buildSnapshot($this->rootTeam, $fb, 'foodbook', ['price_display' => false]);
    expect($ohnePreise['content']['sections'][1]['preis'])->toBeNull();
});

it('Preis je Kapitel: Spanne bei Alternativen, nichts bei 0', function () {
    expect(KapitelPreis::text(KapitelPreis::bauen(null, ['min' => 38.0, 'max' => 52.0], true)))->toBe('38,00 – 52,00 €')
        ->and(KapitelPreis::bauen(0.0))->toBeNull()
        ->and(KapitelPreis::bauen(null, ['min' => null, 'max' => null], true))->toBeNull()
        ->and(KapitelPreis::text(KapitelPreis::bauen(48.5)))->toBe('48,50 €');
});

it('Preis je Kapitel wird wie die Bausteinpreise eingefroren (Preis-Schutz beim erneuten Veröffentlichen)', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(), 'foodbook', []);
    $pfade = $this->pres->preisPfade($snap);
    expect($pfade)->toHaveKey('s1.pp');
    expect($pfade['s1.pp']['net'])->toBe(36.0);

    $neu = $this->pres->setzePreisePfade($snap, ['s1.pp' => 40.0]);
    expect($neu['content']['sections'][1]['preis']['pp'])->toBe(40.0);
});

it('Foodbook: Preis-Aufschlüsselung mit Gästezahl (Summen, brutto) und ohne (nur pro Person)', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(10), 'foodbook', []);
    $pa = $snap['content']['preis_aufschluesselung'];
    expect(array_column($pa['zeilen'], 'titel'))->toBe(['Aperitif', 'Hauptgang', 'Dessert'])
        ->and($pa['zeilen'][1]['gesamt'])->toBe(360.0)
        ->and($pa['netto'])->toBe(575.0)
        ->and($snap['content']['total']['vk_pro_person_brutto'])->not->toBeNull();

    $html = ($this->html)($snap, [['block_type' => 'preis_aufschluesselung', 'style' => ['titel' => 'Ihre Preise']]]);
    expect($html)->toContain('Ihre Preise')->toContain('Gesamt (netto)')->toContain('575,00 €')->toContain('Gesamt (brutto)')
        ->toContain('pt-preis-tabelle')->not->toContain('style="display:flex');

    $ohnePax = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(null), 'foodbook', []);
    expect($ohnePax['content']['preis_aufschluesselung']['netto'])->toBeNull();
    $html2 = ($this->html)($ohnePax, [['block_type' => 'preis_aufschluesselung', 'style' => ['preis_anzeige' => 'netto']]]);
    expect($html2)->toContain('pt-preis-tabelle--ohne-summe')->toContain('Gesamt pro Person')->toContain('57,50 €')->not->toContain('Gesamt (netto)');
});

it('Speiseplan: Kapitel-Auswahl aus dem Design wird beim einen Inhaltsblock entfernt', function () {
    $r = new ReflectionMethod(PresentationService::class, 'adaptLayoutForType');
    $out = $r->invoke($this->pres, 'speiseplan', [
        ['block_type' => 'chapter_loop', 'style' => ['kapitel_auswahl' => 'bereich', 'kapitel_von' => 2, 'show_codes' => true]],
    ], 'liste');
    expect($out[0]['style'])->toBe(['show_codes' => true]);
});

it('Design-Werte: Kapitel-Auswahl, Kapitelpreis und Titel werden geprüft (UI und MCP)', function () {
    $ok = [['block_type' => 'chapter_loop', 'style' => ['kapitel_auswahl' => 'bereich', 'kapitel_von' => '2', 'kapitel_bis' => '', 'show_kapitel_preis' => true]],
        ['block_type' => 'preis_aufschluesselung', 'style' => ['titel' => 'Preise', 'preis_anzeige' => 'brutto']]];
    PraesentationsDesignWerte::pruefe(null, $ok);

    expect(fn () => PraesentationsDesignWerte::pruefe(null, [['block_type' => 'chapter_loop', 'style' => ['kapitel_auswahl' => 'einige']]]))
        ->toThrow(RuntimeException::class, 'kapitel_auswahl');
    expect(fn () => PraesentationsDesignWerte::pruefe(null, [['block_type' => 'chapter_loop', 'style' => ['kapitel_von' => 0]]]))
        ->toThrow(RuntimeException::class, 'kapitel_von');
    expect(fn () => PraesentationsDesignWerte::pruefe(null, [['block_type' => 'chapter_loop', 'style' => ['show_kapitel_preis' => 'ja']]]))
        ->toThrow(RuntimeException::class, 'show_kapitel_preis');
    expect(PresentationDesignService::layoutVocabDoc())->toContain('kapitel_auswahl')->toContain('preis_aufschluesselung')->toContain('show_kapitel_preis');
});

it('Builder: „Einzelnes Kapitel“ legt fortlaufende Kapitel-Blöcke an, benennt sie und speichert', function () {
    $c = Livewire::test(PraesentationsDesigns::class)
        ->call('blockHinzufuegen', PraesentationsDesigns::EINZELNES_KAPITEL)
        ->call('blockHinzufuegen', PraesentationsDesigns::EINZELNES_KAPITEL);

    $layout = $c->get('layout');
    $letzte = array_slice($layout, -2);
    expect($letzte[0]['style'])->toMatchArray(['kapitel_auswahl' => 'bereich', 'kapitel_von' => 1, 'kapitel_bis' => 1])
        ->and($letzte[1]['style'])->toMatchArray(['kapitel_von' => 2, 'kapitel_bis' => 2]);
    $c->assertSee('Einzelnes Kapitel')->assertSee('Kapitel 2');

    $c->set('name', 'Kapitelweise')->call('speichern')->assertSet('fehler', null);
    expect($c->get('selectedId'))->not->toBeNull();
});

it('Konzeptpreis hervorheben: in der Zeile, groß unter dem Namen oder groß nach den Gerichten', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(), 'foodbook', []);
    $snap['content']['sections'][0]['blocks'] = [[
        'kind' => 'concept_ref', 'label' => 'Fingerfood Sommer', 'subtitle' => null, 'is_header' => false, 'per_item_price' => false,
        'price' => ['pp' => 24.5, 'pauschal' => 0.0, 'unit' => 'gast'], 'codes' => [],
        'items' => [['kind' => 'gericht', 'label' => 'Tatar vom Rind', 'indent' => 0, 'price' => null, 'codes' => [], 'image' => null]],
    ]];
    $render = fn (array $stil) => ($this->html)($snap, [['block_type' => 'chapter_loop', 'style' => ['kapitel_auswahl' => 'bereich', 'kapitel_von' => 1, 'kapitel_bis' => 1] + $stil]]);

    $zeile = $render([]);
    expect($zeile)->toContain('class="pt-line pt-line--concept"')->toContain('24,50 € /Pers.')->not->toContain('data-pt-konzept-preis');

    $oben = $render(['konzept_preis' => 'betont']);
    expect($oben)->toContain('pt-concept-price--oben')->toContain('24,50 €')->toContain('pro Person')->not->toContain('class="pt-line pt-line--concept"');
    expect(strpos($oben, 'class="pt-concept-price pt-concept-price--oben"'))->toBeLessThan(strpos($oben, 'Tatar vom Rind'));

    $ende = $render(['konzept_preis' => 'ende', 'konzept_preis_label' => 'je Gast']);
    expect($ende)->toContain('pt-concept-price--ende')->toContain('je Gast');
    expect(strpos($ende, 'Tatar vom Rind'))->toBeLessThan(strpos($ende, 'class="pt-concept-price pt-concept-price--ende"'));

    expect(fn () => PraesentationsDesignWerte::pruefe(null, [['block_type' => 'chapter_loop', 'style' => ['konzept_preis' => 'riesig']]]))
        ->toThrow(RuntimeException::class, 'konzept_preis');
});

it('Design-Werte wirken sichtbar: Schnitt auf der Titelseite, Abstände, Lesebreite, Zentriert (Fix 2026-11-19)', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(), 'foodbook', []);
    $basis = app(PresentationDesignService::class)->builtins()['editorial'];
    $render = function (array $tokens) use ($snap, $basis) {
        $snap['resolved_design']['layout'] = $basis['layout'];
        $snap['resolved_design']['tokens'] = array_replace_recursive($basis['tokens'], $tokens);

        return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
    };

    $standard = $render([]);
    // „normal“ bleibt der bisherige Look
    expect($standard)->toContain('--pt-zeilen: 760px')->toContain('--pt-kapitel-abstand: 84px')->not->toContain('.pt-hero .pt-hero-title { font-weight');

    expect($render(['typography' => ['heading_weight' => 'leicht']]))->toContain('.pt-hero .pt-hero-title { font-weight: 500; }');
    expect($render(['spacing' => 'compact']))->toContain('--pt-kapitel-abstand: 40px');
    expect($render(['spacing' => 'comfortable']))->toContain('--pt-kapitel-abstand: 64px');
    expect($render(['text_width' => 'schmal']))->toContain('--pt-zeilen: 640px')->toContain('--pt-lese: 58ch');
    expect($render(['text_width' => 'breit']))->toContain('--pt-zeilen: 920px');
    expect($render(['text_align' => 'zentriert']))->toContain('class="pt-nav-anchor pt-text-zentriert"')
        ->toContain('.pt-text-zentriert .pt-section-head { max-width: var(--pt-lese); text-align: center; }');
});

it('Hinweistexte im Konzept („serviert im Schraubglas“) kursiv bzw. kursiv und leiser per Design-Wert', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, ($this->baueFb)(), 'foodbook', []);
    $snap['content']['sections'][0]['blocks'] = [['kind' => 'concept_ref', 'label' => 'Paket', 'subtitle' => null, 'is_header' => false, 'per_item_price' => false, 'price' => null, 'codes' => [],
        'items' => [['kind' => 'header', 'label' => 'Vorspeise', 'indent' => 0], ['kind' => 'text', 'title' => null, 'label' => 'serviert im Schraubglas', 'indent' => 0]]]];
    $render = function (array $typo) use ($snap) {
        $snap['resolved_design']['layout'] = [['block_type' => 'chapter_loop', 'style' => []]];
        $snap['resolved_design']['tokens']['typography'] = $typo + ($snap['resolved_design']['tokens']['typography'] ?? []);

        return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
    };
    expect($render([]))->toContain('serviert im Schraubglas')->not->toContain('.pt-text-block--konzept .pt-text-block-body { font-style: italic');
    expect($render(['konzept_text' => 'kursiv']))->toContain('.pt-text-block--konzept .pt-text-block-body { font-style: italic; }');
    expect($render(['konzept_text' => 'kursiv_leise']))->toContain('font-style: italic; color: var(--pt-muted)');
    expect(fn () => PraesentationsDesignWerte::pruefe(['typography' => ['konzept_text' => 'fett']], null))->toThrow(RuntimeException::class, 'konzept_text');
});

it('Foodbook-Link zeigt Text-Bausteine des Konzepts unter der Gang-Überschrift (vorher nur im Angebot)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Fingerfood-Paket', ['kind' => 'concept']);
    $this->makeConceptSlot($concept, ['type' => 'header', 'title' => 'Vorspeise', 'position' => 1]);
    $this->makeConceptSlot($concept, ['type' => 'text', 'title' => null, 'text_content' => 'serviert im Schraubglas', 'position' => 2]);
    $dish = $this->makeRecipe($this->rootTeam, 'Tatar', ['is_sales_recipe' => true]);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Tatar vom Rind', 'position' => 3]);
    $fb = $this->makeFoodbook($this->rootTeam, 'Glasbuch', ['personen' => 10]);
    $kap = $this->makeChapter($fb, ['title' => 'Fingerfood', 'consumer_title' => 'Fingerfood', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $fb->refresh(), 'foodbook', []);
    $items = $snap['content']['sections'][0]['blocks'][0]['items'];
    expect(array_column($items, 'kind'))->toBe(['header', 'text', 'gericht'])
        ->and($items[1]['label'])->toBe('serviert im Schraubglas');

    $snap['resolved_design']['layout'] = [['block_type' => 'chapter_loop', 'style' => []]];
    expect(view('foodalchemist::presentation.show', ['snapshot' => $snap])->render())
        ->toContain('pt-text-block--konzept')->toContain('serviert im Schraubglas');
});

it('Foodbook: Text aus einem PAKET im Konzept („Vorspeise“ → „serviert im Schraubglas“) erscheint eingerückt unter dem Paket', function () {
    $paket = $this->makeConcept($this->rootTeam, 'Italien', ['kind' => 'paket', 'consumer_name' => 'ITALIEN']);
    $this->makeConceptSlot($paket, ['type' => 'header', 'title' => 'Vorspeise', 'position' => 1]);
    $this->makeConceptSlot($paket, ['type' => 'text', 'title' => null, 'text_content' => 'serviert im Schraubglas', 'position' => 2]);
    $dish = $this->makeRecipe($this->rootTeam, 'Perlcouscous', ['is_sales_recipe' => true]);
    $this->makeConceptSlot($paket, ['sales_recipe_id' => $dish->id, 'wording' => 'Salat von Perlcouscous', 'position' => 3]);
    $konzept = $this->makeConcept($this->rootTeam, 'Taste & Fly', ['kind' => 'concept']);
    $this->makeConceptSlot($konzept, ['type' => 'paket', 'embedded_concept_id' => $paket->id, 'sales_recipe_id' => null, 'wording' => null, 'position' => 1]);
    $fb = $this->makeFoodbook($this->rootTeam, 'Fly', ['personen' => 10]);
    $kap = $this->makeChapter($fb, ['title' => 'Favourites', 'consumer_title' => 'Favourites', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $konzept->id, 'position' => 1]);

    $items = $this->pres->buildSnapshot($this->rootTeam, $fb->refresh(), 'foodbook', [])['content']['sections'][0]['blocks'][0]['items'];
    expect(array_column($items, 'kind'))->toBe(['paket', 'header', 'text', 'gericht'])
        ->and($items[2]['label'])->toBe('serviert im Schraubglas')
        ->and($items[2]['indent'])->toBe(1);
});
