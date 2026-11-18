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
        ->and($x->query('//article[@data-key="r1"]//details[contains(@class,"spuren")]')->length)->toBe(1)
        ->and($x->query('//article[@data-key="r1"]//div[contains(@class,"allergene")]//span')->length)->toBe(2);
    expect($html)->toContain('data-b="120"', 'data-b="100"', 'data-faktor="0.08"');
    if ($pfad = getenv('FA_HANDBUCH_PREVIEW_PATH')) {
        file_put_contents($pfad, $html);
    }
});
