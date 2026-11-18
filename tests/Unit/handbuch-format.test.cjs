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

test('Handbuch erhält endständige Nullen ganzer Grammzahlen', () => {
    for (const value of [0, 1, 5, 10, 20, 80, 100, 120, 200, 1000, 1200]) {
        assert.equal(fmt(value, 'Gramm'), `${value} Gramm`);
    }
});
test('Handbuch kürzt nur Nachkommastellen und rechnet Kurz-Einheiten korrekt um', () => {
    assert.equal(fmt(1.2, 'Gramm'), '1,2 Gramm');
    assert.equal(fmt(1.25, 'Gramm'), '1,25 Gramm');
    assert.equal(fmt(1000, 'g'), '1 kg');
    assert.equal(fmt(1200, 'g'), '1,2 kg');
    assert.equal(fmt(1000, 'ml'), '1 l');
});
