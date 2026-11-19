<?php

/**
 * Welle 1, Paket 1.4b — Codemod Typografie-Literale → Text-Rollen (scripts/codemod/text-rollen.php).
 * Geprüft wird die reine Umwandlung; das Skript selbst läuft standardmäßig als Trockenlauf.
 */
require_once dirname(__DIR__, 2).'/scripts/codemod/TextRollenCodemod.php';

$m = fn (string $html) => (new TextRollenCodemod())->umwandeln($html, 'livewire/test.blade.php')['html'];

it('stellt exakte Rollen-Kombinationen um und behält alle übrigen Klassen samt Reihenfolge', function () use ($m) {
    expect($m('<span class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">x</span>'))
        ->toBe('<span class="mt-1 fa-t-muted tabular-nums">x</span>')
        ->and($m('<p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">x</p>'))
        ->toBe('<p class="fa-t-label">x</p>')
        ->and($m('<div class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">x</div>'))
        ->toBe('<div class="fa-t-body">x</div>')
        ->and($m('<div class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] truncate">x</div>'))
        ->toBe('<div class="fa-t-strong truncate">x</div>')
        ->and($m('<h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">x</h3>'))
        ->toBe('<h3 class="fa-t-h2">x</h3>')
        ->and($m('<h1 class="text-[length:var(--fa-text-3xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)]">x</h1>'))
        ->toBe('<h1 class="fa-t-h1">x</h1>');
});

it('lässt unvollständige oder abweichende Kombinationen unverändert (keine Optik-Verschiebung)', function () use ($m) {
    foreach ([
        '<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">x</span>',            // sm+ink-2 ohne 500: keine Rolle
        '<span class="text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-3)]">x</span>', // muted + Gewicht
        '<span class="text-[length:var(--fa-text-lg)] font-semibold">x</span>',                       // ohne Tinte (erbt Farbe)
        '<span class="text-[var(--fa-ink-3)]">x</span>',                                              // nur Tinte
        '<h1 class="text-[length:var(--fa-text-3xl)] font-semibold text-[var(--fa-ink)]">x</h1>',     // h1 ohne tracking-tight
    ] as $html) {
        expect($m($html))->toBe($html);
    }
});

it('rührt Varianten (md:, hover:, !) und Blade-Teile nicht an, gebundene :class auch nicht', function () use ($m) {
    expect($m('<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] md:text-[length:var(--fa-text-md)] hover:text-[var(--fa-ink)]">x</p>'))
        ->toBe('<p class="fa-t-muted md:text-[length:var(--fa-text-md)] hover:text-[var(--fa-ink)]">x</p>')
        ->and($m('<p class="{{ $a }} text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">x</p>'))
        ->toBe('<p class="{{ $a }} fa-t-muted">x</p>')
        ->and($m('<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-{{ $n }})]">x</p>'))
        ->toBe('<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-{{ $n }})]">x</p>')
        ->and($m('<p :class="\'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]\'">x</p>'))
        ->toBe('<p :class="\'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]\'">x</p>');
});

it('stellt Elemente mit Kollisionsgefahr in @layer components nicht um, sondern meldet sie', function () {
    $c = new TextRollenCodemod();
    foreach ([
        '<th class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">x</th>',
        '<dt class="kpi-label text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">x</dt>',
        '<input class="fa-control text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">',
    ] as $html) {
        $r = $c->umwandeln($html, 'livewire/test.blade.php');
        expect($r['html'])->toBe($html)->and($r['pruefen'])->toHaveCount(1);
    }
});

it('stellt lokale $leise-Definitionen mit Rollen-Wert um', function () use ($m) {
    expect($m("@php \$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'; @endphp"))
        ->toBe("@php \$leise = 'fa-t-muted'; @endphp")
        ->and($m("@php(\$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums')"))
        ->toBe("@php(\$leise = 'fa-t-muted tabular-nums')")
        ->and($m("@php \$leise = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]'; @endphp"))
        ->toBe("@php \$leise = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]'; @endphp");
});

it('ist idempotent: ein zweiter Lauf ändert nichts mehr', function () {
    $c = new TextRollenCodemod();
    $html = '<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">a</span><p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">b</p>';
    $einmal = $c->umwandeln($html, 'x.blade.php')['html'];

    expect($c->umwandeln($einmal, 'x.blade.php')['html'])->toBe($einmal);
});
