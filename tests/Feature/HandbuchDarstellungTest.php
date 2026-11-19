<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

it('rendert die eingeklappte Format-Konzept-Hierarchie und eine eigene Basisrezeptansicht mit Bildern', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $x = new DOMXPath($dom);
    expect($x->query('//nav//details[@open]')->length)->toBe(0)
        ->and($x->query('//nav//details[contains(@class,"konzept")]')->length)->toBe(2)
        ->and($x->query('//tr[contains(@class,"subrow")]/td[@colspan="2"]')->length)->toBe(2)
        ->and($x->query('//article[@data-key="basis-b2"]//img')->length)->toBe(1)
        ->and($x->query('//article[@data-key="basis-b2"]//div[@aria-label="Enthaltene Allergene"]/span')->length)->toBe(1)
        ->and($x->query('//article[@data-key="basis-b2"]//details[contains(@class,"spuren")][not(@open)]')->length)->toBe(1)
        ->and($x->query('//div[contains(@class,"subbox")]//div[@aria-label="Allergene dieses Basisrezepts"]')->length)->toBe(1)
        ->and($x->query('//article[@data-key="r1"]/div[contains(@class,"head")]//details[contains(@class,"spuren")]')->length)->toBe(1)
        ->and($x->query('//article[@data-key="r1"]/div[contains(@class,"head")]//div[@aria-label="Enthaltene Allergene und Zusatzstoffe"]/span')->length)->toBe(2);
    expect($html)->toContain('data-b="120"', 'data-b="100"', 'data-faktor="0.08"');
    if ($pfad = getenv('FA_HANDBUCH_PREVIEW_PATH')) {
        file_put_contents($pfad, $html);
    }
});

it('rendert Pakete geschlossen, Verteilung je Gericht und zusätzliche Zielmengenfelder', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $r1 = ['kind' => 'gericht', 'key' => 'r1', 'label' => 'Linguine', 'menge_pro_gast' => .33, 'einheit' => 'Teile', 'portionen_pro_gast' => .33];
    $r3 = ['kind' => 'gericht', 'key' => 'r3', 'label' => 'Linguine im Schweizer Paket', 'menge_pro_gast' => 1, 'einheit' => 'Teile', 'portionen_pro_gast' => 1];
    $snapshot['gerichte']['r3'] = $snapshot['gerichte']['r1'];
    $snapshot['gerichte']['r3']['name'] = $r3['label'];
    $snapshot['sections'][0]['items'][] = $r3;
    $snapshot['sections'][0]['groups'][0] = ['title' => 'TASTE & FLY 01', 'items' => [$r1, $r3], 'nodes' => [
        ['kind' => 'paket', 'label' => 'Deutschland', 'children' => [['kind' => 'header', 'label' => 'Vorspeise'], $r1]],
        ['kind' => 'paket', 'label' => 'Schweiz', 'children' => [$r3]],
    ]];
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    $dom = new DOMDocument(); $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING); $x = new DOMXPath($dom);
    expect($x->query('//nav//details[@open]')->length)->toBe(0)
        ->and($x->query('//nav//details[contains(@class,"paket")]')->length)->toBe(2)
        ->and($x->query('//a[@data-key="r1"][@data-portionen-pro-gast="0.33"]')->length)->toBe(1)
        ->and($x->query('//article[@data-key="basis-b2"]//input[contains(@class,"basis-ziel")]')->length)->toBe(1)
        ->and($html)->toContain('0,33 Teile je Gast', 'Hochrechnen auf');
    if ($pfad = getenv('FA_HANDBUCH_BAUM_PREVIEW_PATH')) { file_put_contents($pfad, $html); }
});

it('Basisrezept auf 100 kg: Rezeptmenge und Zutaten ab 1000 g in kg, Kurzeinheiten auch aus alten „Gramm"-Snapshots', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $snapshot['basisrezepte']['b2']['ansatz_kg'] = 104.5;
    $snapshot['basisrezepte']['b2']['zeilen'] = [
        ['name' => 'Tomaten', 'menge' => 80000, 'einheit' => 'Gramm', 'sub' => null],
        ['name' => 'Mascarpone', 'menge' => 18000, 'einheit' => 'Gramm', 'sub' => null],
        ['name' => 'Zwiebeln', 'menge' => 5000, 'einheit' => 'g', 'sub' => null],
        ['name' => 'Olivenöl', 'menge' => 1500, 'einheit' => 'Milliliter', 'sub' => null],
        ['name' => 'Salz', 'menge' => 520, 'einheit' => 'Gramm', 'sub' => null],
        ['name' => 'Lorbeer', 'menge' => 3, 'einheit' => 'Stück', 'sub' => null],
    ];
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $x = new DOMXPath($dom);
    $zellen = array_map(fn ($n) => trim($n->textContent),
        iterator_to_array($x->query('//article[@data-key="basis-b2"]//table[@data-basis-werte]//td[@data-original]')));

    expect($zellen)->toBe(['80 kg', '18 kg', '5 kg', '1,5 l', '520 g', '3 Stück'])
        ->and(trim($x->query('//article[@data-key="basis-b2"]//b[@data-bedarf-menge]')->item(0)->textContent))->toBe('104,5 kg')
        ->and($html)->toContain('Ausbeute des vollständigen Originalansatzes: 104,5 kg')
        ->and($html)->not->toContain('80000 Gramm')
        ->and($html)->not->toContain('104500 g')
        ->and($html)->toContain('data-original="80000"');   // Rohwert fürs Live-Hochrechnen bleibt
    if ($pfad = getenv('FA_HANDBUCH_KG_PREVIEW_PATH')) {
        file_put_contents($pfad, $html);
    }
});
