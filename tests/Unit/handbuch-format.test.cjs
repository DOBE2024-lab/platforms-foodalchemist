const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Prüft die tatsächlich ausgelieferte Funktion; kein zweiter Formatter im Test.
const view = fs.readFileSync(path.join(__dirname, '../../resources/views/handbuch/show.blade.php'), 'utf8');
const source = view.match(/function fmt\(v, u\) \{[\s\S]*?\n    \}/);
assert(source, 'Mengenformatter der Handbuchansicht vorhanden');
const fmt = vm.runInNewContext('(' + source[0] + ')');

test('Handbuch erhält endständige Nullen ganzer Grammzahlen und schreibt Kurzeinheiten', () => {
    for (const value of [0, 1, 5, 10, 20, 80, 100, 120, 200, 520, 999]) {
        assert.equal(fmt(value, 'Gramm'), `${value} g`);
        assert.equal(fmt(value, 'g'), `${value} g`);
    }
});
test('Handbuch: ab 1000 g in kg, ml/l analog — dieselbe Regel wie Hochrechnung::menge', () => {
    const faelle = [
        [80000, 'Gramm', '80 kg'], [18000, 'Gramm', '18 kg'], [5000, 'Gramm', '5 kg'], [1500, 'Gramm', '1,5 kg'],
        [1000, 'g', '1 kg'], [1200, 'g', '1,2 kg'], [1234, 'g', '1,23 kg'], [12345, 'g', '12,3 kg'], [1234567, 'g', '1.234,6 kg'],
        [0.4, 'g', '0,4 g'], [1.2, 'Gramm', '1,2 g'], [999.6, 'g', '1 kg'], [0.015, 'Kilogramm', '15 g'], [2, 'kg', '2 kg'],
        [2500, 'ml', '2,5 l'], [1000, 'Milliliter', '1 l'], [250, 'ml', '250 ml'], [0.25, 'Liter', '250 ml'],
    ];
    for (const [wert, einheit, erwartet] of faelle) {
        assert.equal(fmt(wert, einheit), erwartet, `${wert} ${einheit}`);
    }
});
test('Handbuch lässt andere Einheiten unverändert', () => {
    assert.equal(fmt(3, 'Stück'), '3 Stück');
    assert.equal(fmt(1, 'Teile'), '1 Teil');
    assert.equal(fmt(12.5, 'Bund'), '12,5 Bund');
});
