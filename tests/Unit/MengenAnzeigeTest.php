<?php

use Platform\FoodAlchemist\Support\Hochrechnung;

/**
 * Eine Anzeige-Regel für Mengen (Dominique 2026-11-19, Befund Handbuch „80000 Gramm"): unter 1000 g „g" (ganz,
 * unter 10 g eine Stelle), ab 1000 g „kg" (unter 10 kg zwei Stellen, sonst eine), ml/l analog, immer Kurzeinheit.
 * Dieselbe Liste prüft tests/Unit/handbuch-format.test.cjs gegen die JS-Fassung im Handbuch.
 */
dataset('mengen', [
    '80000 g' => [80000, 'g', '80 kg'],
    '18000 Gramm' => [18000, 'Gramm', '18 kg'],
    '5000 g' => [5000, 'g', '5 kg'],
    '1500 g' => [1500, 'g', '1,5 kg'],
    '1234 g' => [1234, 'g', '1,23 kg'],
    '12345 g' => [12345, 'g', '12,3 kg'],
    '1234567 g mit Tausenderpunkt' => [1234567, 'g', '1.234,6 kg'],
    '1000 g' => [1000, 'g', '1 kg'],
    '999,6 g rundet über die Grenze' => [999.6, 'g', '1 kg'],
    '520 g' => [520, 'g', '520 g'],
    '0,4 g' => [0.4, 'g', '0,4 g'],
    '1,25 Gramm eine Stelle' => [1.25, 'Gramm', '1,3 g'],
    '0,015 Kilogramm' => [0.015, 'Kilogramm', '15 g'],
    '2 kg' => [2, 'kg', '2 kg'],
    '9,999 kg' => [9.999, 'kg', '10 kg'],
    '2500 ml' => [2500, 'ml', '2,5 l'],
    '1000 Milliliter' => [1000, 'Milliliter', '1 l'],
    '0,25 Liter' => [0.25, 'Liter', '250 ml'],
    '250 ml' => [250, 'ml', '250 ml'],
    'Stück bleibt' => [3, 'Stück', '3 Stück'],
    'Bund bleibt' => [12.5, 'Bund', '12,5 Bund'],
    'EL bleibt' => [1500, 'EL', '1.500 EL'],
]);

it('formatiert Mengen nach der einen Regel', function (float $wert, string $einheit, string $erwartet) {
    expect(Hochrechnung::menge($wert, $einheit))->toBe($erwartet);
})->with('mengen');

it('kurz() kürzt deutsch formatierte Mengentexte nach derselben Regel, Fremdes bleibt', function () {
    expect(Hochrechnung::kurz('80.000 Gramm'))->toBe('80 kg')
        ->and(Hochrechnung::kurz('80000 Gramm'))->toBe('80 kg')
        ->and(Hochrechnung::kurz('1.500 Gramm'))->toBe('1,5 kg')
        ->and(Hochrechnung::kurz('0,015 Kilogramm'))->toBe('15 g')
        ->and(Hochrechnung::kurz('1,234 Kilogramm'))->toBe('1,23 kg')
        ->and(Hochrechnung::kurz('2.500 Milliliter'))->toBe('2,5 l')
        ->and(Hochrechnung::kurz('0,25 Liter'))->toBe('250 ml')
        ->and(Hochrechnung::kurz('520 g'))->toBe('520 g')
        ->and(Hochrechnung::kurz('3 Stück'))->toBe('3 Stück')
        ->and(Hochrechnung::kurz('1.5 Kilogramm'))->toBe('1.5 Kilogramm')   // nicht eindeutig deutsch → unverändert
        ->and(Hochrechnung::kurz('etwa 2 kg'))->toBe('etwa 2 kg');
});

it('mengeKurz() rechnet hoch und kürzt: 2000 g × 40 → 80 kg', function () {
    expect(Hochrechnung::mengeKurz(2000, (object) ['slug' => 'g', 'display_de' => 'Gramm'], 40.0))->toBe('80 kg');
});

it('Einheit kurz und Erkennung Masse/Volumen', function () {
    expect(Hochrechnung::einheitKurz('Gramm'))->toBe('g')
        ->and(Hochrechnung::einheitKurz('Kilogramm'))->toBe('kg')
        ->and(Hochrechnung::einheitKurz('Milliliter'))->toBe('ml')
        ->and(Hochrechnung::einheitKurz('Liter'))->toBe('l')
        ->and(Hochrechnung::einheitKurz('Stück'))->toBe('Stück')
        ->and(Hochrechnung::einheitKurz(null))->toBeNull()
        ->and(Hochrechnung::istMasseOderVolumen('Gramm'))->toBeTrue()
        ->and(Hochrechnung::istMasseOderVolumen('Bund'))->toBeFalse();
});

it('anzeige() lässt die Feld-Einheit stehen (g bleibt g), der Anzeigetext dazu kommt aus menge()', function () {
    $g = (object) ['slug' => 'g', 'display_de' => 'Gramm'];
    $a = Hochrechnung::anzeige(80000, $g);
    expect($a)->toBe(['wert' => 80000.0, 'einheit' => 'g', 'je' => 1.0])
        ->and(Hochrechnung::menge($a['wert'], $a['einheit']))->toBe('80 kg')
        // Speichern bleibt unverändert
        ->and(Hochrechnung::speicherWert(1500.4, $g))->toBe(['wert' => 1500.0, 'pruefen' => false]);
});
