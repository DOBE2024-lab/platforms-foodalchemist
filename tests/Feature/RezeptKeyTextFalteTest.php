<?php

use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Code-Qualität 2.2b: RecipeService::rezeptKey läuft jetzt über Text::falte(SLUG). Der Key wird gespeichert und
 * verglichen — er muss für jeden Namen exakt der alte bleiben (Regelwerk Basisrezepte §1.7).
 */
it('rezeptKey: alt gegen neu für Umlaute, ß, Akzente, Mehrfach-Leerzeichen und Satzzeichen', function () {
    $alt = function (string $name): string {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);

        return trim(preg_replace('/_+/', '_', $s), '_');
    };
    $namen = ['Karottenpüree', 'Kartoffelgratin (Sahne)', 'Weißweinsauce', '  Grüne   Soße  ', 'Crème brûlée',
        'Purée de Pommes', 'Jus: Kalb, dunkel / 2. Ansatz', 'Öl-Emulsion & Kräuter', 'Bärlauch-Pesto!', '__x__', '', 'ẞ-Test', 'Rösti 10x10'];
    $svc = app(RecipeService::class);
    foreach ($namen as $n) {
        expect($svc->rezeptKey($n))->toBe($alt($n), $n);
    }
    expect($svc->rezeptKey('Karottenpüree'))->toBe('karottenpueree')
        ->and($svc->rezeptKey('Crème brûlée'))->toBe('crème_brûlée');
});
