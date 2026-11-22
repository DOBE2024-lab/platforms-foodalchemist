<?php

use Platform\FoodAlchemist\Support\Text;

/**
 * Code-Qualität 2.2b: ein Text-Normalisierer mit benannten Varianten statt der Kopien. Jede Variante muss exakt
 * das liefern, was die abgelösten Stellen vorher von Hand gerechnet haben — sonst verschiebt sich das Matching.
 * Die Referenz-Formeln unten sind die alten Kopien wörtlich.
 */
function textKorpus(): array
{
    return [
        '', '   ', 'Äpfel', 'ÄPFEL', 'Karottenpüree', 'Karottenpueree', ' Großer  Würfel ', 'Weiß-Kohl_roh',
        'Purée de Pommes', 'Crème fraîche', 'Fisch & Meeresfrüchte', 'Öl, nativ (kaltgepresst).', "Tab\tund\nZeile",
        'Ü-Ei 10x10 mm', '__Salz__', 'ẞ groß', 'Café au lait', 'Zitronen-Abrieb', 'Rind: frisch, ganz / 2,5 kg',
    ];
}

it('UMSCHRIFT und umschrift(): ä/ö/ü/ß → ae/oe/ue/ss, sonst nichts', function () {
    expect(Text::UMSCHRIFT)->toBe(['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])
        ->and(Text::umschrift('grüße aus köln'))->toBe('gruesse aus koeln')
        ->and(Text::umschrift('Äpfel'))->toBe('Äpfel');   // erwartet Kleinschrift, Großbuchstaben bleiben
});

it('grundlaut(): ä/ö/ü → a/o/u, ß → ss', function () {
    expect(Text::grundlaut('grüße aus köln'))->toBe('grusse aus koln');
});

it('falte() STANDARD = umschrift(mb_strtolower(trim)) wie die alten Kopien', function () {
    foreach (textKorpus() as $s) {
        $alt = strtr(mb_strtolower(trim($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        expect(Text::falte($s))->toBe($alt, $s)->and(Text::falte($s, Text::STANDARD))->toBe($alt, $s);
    }
});

it('falte() ZEILE kollabiert Leerraum vorher (GpSammelware, GpPaketValidator)', function () {
    foreach (textKorpus() as $s) {
        $alt = strtr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        expect(Text::falte($s, Text::ZEILE))->toBe($alt, $s);
    }
});

it('falte() KOMPAKT wirft alles außer a-z0-9 weg (Import-Header, Equipment)', function () {
    foreach (textKorpus() as $s) {
        $t = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower(trim($s)));
        expect(Text::falte($s, Text::KOMPAKT))->toBe((string) preg_replace('/[^a-z0-9]/', '', $t), $s);
    }
});

it('falte() WOERTER: Nicht-a-z0-9 → ein Leerzeichen, getrimmt (MerkmalsDiff, SalesImport)', function () {
    foreach (textKorpus() as $s) {
        $t = strtr(mb_strtolower(trim($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
        expect(Text::falte($s, Text::WOERTER))->toBe(trim((string) preg_replace('/\s+/', ' ', $t)), $s);
    }
});

it('falte() SLUG: Unicode-Buchstaben bleiben, Rest → _ (GpNamingService::slugify, KnowledgeImport)', function () {
    foreach (textKorpus() as $s) {
        $t = strtr(mb_strtolower(trim($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', '_', $t);
        expect(Text::falte($s, Text::SLUG))->toBe(trim(preg_replace('/_+/', '_', $t), '_'), $s);
    }
    expect(Text::falte('Karottenpüree', Text::SLUG))->toBe(Text::falte('Karottenpueree', Text::SLUG))
        ->and(Text::falte('Purée de Pommes', Text::SLUG))->toBe('purée_de_pommes');
});

it('Varianten bleiben verschieden, wo die alten Kopien verschieden waren', function () {
    expect(Text::falte('Purée', Text::KOMPAKT))->toBe('pure')
        ->and(Text::falte('Purée', Text::SLUG))->toBe('purée')
        ->and(Text::grundlaut('püree'))->not->toBe(Text::umschrift('püree'));
});

it('falte(): unbekannte Variante ist ein Programmierfehler', function () {
    Text::falte('x', 'gibt_es_nicht');
})->throws(InvalidArgumentException::class);
