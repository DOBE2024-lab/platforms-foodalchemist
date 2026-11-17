<?php

use Platform\FoodAlchemist\Support\ProduktpassFelder;

/**
 * Natrium aus dem Produktpass: die Spalte am Artikel führt mg je 100 g (GL-08). Die Fälle stammen aus dem ersten
 * Sammel-Upload vom 16.11.2026 — dort stand bei 26 Artikeln der Gramm-Wert des Passes unverändert im mg-Feld.
 * Spalten: gelesener Eintrag · Salz g desselben Passes · erwarteter Wert · erwartete Einheit · erwartete Konfidenz.
 */
dataset('natrium', [
    'Gramm ohne Zusatz (Goedeken 10291)' => [['wert' => 0.264, 'einheit' => 'g', 'konfidenz' => 1.0], null, 264.0, 'mg', 1.0],
    'Gramm je 100 g (Goedeken 10133)' => [['wert' => 0.72, 'einheit' => 'g/100 g', 'konfidenz' => 1.0], null, 720.0, 'mg', 1.0],
    'Gramm groß geschrieben' => [['wert' => 0.6, 'einheit' => 'G', 'konfidenz' => 0.9], null, 600.0, 'mg', 0.9],
    'Milligramm bleibt' => [['wert' => 500, 'einheit' => 'mg', 'konfidenz' => 0.9], null, 500.0, 'mg', 0.9],
    'mg/100g bleibt' => [['wert' => 548, 'einheit' => 'mg/100g', 'konfidenz' => 1.0], null, 548.0, 'mg', 1.0],
    'ohne Einheit, Salz belegt mg' => [['wert' => 680, 'konfidenz' => 1.0], 1.7, 680.0, 'mg', 1.0],
    'ohne Einheit, Salz belegt g' => [['wert' => 0.68, 'konfidenz' => 1.0], 1.7, 680.0, 'mg', 1.0],
    'ohne Einheit und ohne Salz: nur Vorschlag' => [['wert' => 648, 'konfidenz' => 1.0], null, 648.0, null, 0.5],
    'unmöglich hoch in g (Goedeken 12204)' => [['wert' => 329, 'einheit' => 'g/100 g', 'konfidenz' => 0.99], null, 329.0, 'g/100 g', 0.5],
    'Einheit g, widerspricht dem Salz: nur Vorschlag' => [['wert' => 1.7, 'einheit' => 'g', 'konfidenz' => 0.99], 0.4, 1700.0, 'mg', 0.5],
    'null bleibt null' => [['wert' => 0, 'einheit' => 'g', 'konfidenz' => 1.0], null, 0.0, 'mg', 1.0],
]);

it('bringt Natrium auf mg je 100 g; unklare Einheit bleibt unter der Direkt-Schwelle', function (array $eintrag, ?float $salz, float $wert, ?string $einheit, float $konfidenz) {
    $aus = ProduktpassFelder::natriumInMg($eintrag, $salz);

    expect((float) $aus['wert'])->toBe($wert)
        ->and($aus['einheit'] ?? null)->toBe($einheit)
        ->and((float) $aus['konfidenz'])->toBe($konfidenz);
})->with('natrium');

it('behält die Angabe des Passes als „im Dokument“ und reicht Zitat und Quelle durch', function () {
    $aus = ProduktpassFelder::natriumInMg(['wert' => 0.264, 'einheit' => 'g', 'konfidenz' => 1.0, 'zitat' => 'Natrium (g) 0,264', 'url' => null]);

    expect($aus['im_dokument'])->toBe('0,264 g')->and($aus['zitat'])->toBe('Natrium (g) 0,264');
    // Gegenprobe: ein Milligramm-Wert bekommt keinen „im Dokument“-Zusatz
    expect(ProduktpassFelder::natriumInMg(['wert' => 500, 'einheit' => 'mg', 'konfidenz' => 1.0]))->not->toHaveKey('im_dokument');
});

it('ein unklarer Wert liegt sicher unter der Direkt-Schwelle', function () {
    expect(ProduktpassFelder::KONFIDENZ_EINHEIT_UNKLAR)->toBeLessThan(ProduktpassFelder::KONFIDENZ_DIREKT);
});
