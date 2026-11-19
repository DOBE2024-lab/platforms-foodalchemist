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
