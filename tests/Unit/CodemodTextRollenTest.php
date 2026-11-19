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

it('stellt lokale $leise-Definitionen mit Rollen-Wert um (Schritt 1, ohne Auflösen)', function () {
    $m = fn (string $html) => (new TextRollenCodemod(leiseAufloesen: false))->umwandeln($html, 'livewire/test.blade.php')['html'];

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

// ── Schritt 2: $leise auflösen (Kurator 19.11.): nur bei genau einer Definition mit Rollen-Wert und
// ausschließlich {{ $leise }} in statischen class-Attributen; @include erbt Variablen → Partials prüfen.

$leiseDatei = fn (string $koerper, string $def = "@php(\$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]')\n") => $def.$koerper;

it('löst $leise auf: Nutzungen in class bekommen den Wert, die Definition verschwindet', function () use ($leiseDatei) {
    $c = new TextRollenCodemod();
    $r = $c->umwandeln($leiseDatei('<span class="mt-1 {{ $leise }}">a</span><p class="{{$leise}} tabular-nums">b</p>'), 'livewire/x.blade.php');

    expect($r['html'])->toBe('<span class="mt-1 fa-t-muted">a</span><p class="fa-t-muted tabular-nums">b</p>')
        ->and($r['leise_aufgeloest'])->toBe(1);
});

it('entfernt die Zeile aus einem @php-Block und lässt die übrigen Anweisungen stehen', function () {
    $html = "@php\n    \$a = 1;\n    \$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';\n    \$b = 2;\n@endphp\n<i class=\"{{ \$leise }}\">x</i>";

    expect((new TextRollenCodemod())->umwandeln($html, 'x.blade.php')['html'])
        ->toBe("@php\n    \$a = 1;\n    \$b = 2;\n@endphp\n<i class=\"fa-t-muted\">x</i>");
});

it('entfernt einen dadurch leeren @php-Block ganz', function () {
    $html = "@php\n    \$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';\n@endphp\n<i class=\"{{ \$leise }}\">x</i>";

    expect((new TextRollenCodemod())->umwandeln($html, 'x.blade.php')['html'])->toBe("<i class=\"fa-t-muted\">x</i>");
});

it('lässt die Definition stehen und meldet sie, wenn $leise anders genutzt wird', function () use ($leiseDatei) {
    foreach ([
        '<span class="{{ $t ? $leise : \'\' }}">a</span>',                 // ternär
        '<span :class="$leise">a</span><span class="{{ $leise }}">b</span>', // gebunden
        '<x-fa::text :class="$leise" rolle="body">a</x-fa::text>',           // an Baustein übergeben
        "@include('foodalchemist::x', ['leise' => \$leise])",                // weitergereicht
    ] as $koerper) {
        $r = (new TextRollenCodemod())->umwandeln($leiseDatei($koerper), 'livewire/x.blade.php');
        expect($r['html'])->toContain('$leise = \'fa-t-muted\'')
            ->and($r['leise_aufgeloest'])->toBe(0)
            ->and(implode("\n", $r['pruefen']))->toContain('$leise');
    }
});

it('lässt die Definition stehen, wenn ein eingebundenes Partial $leise erbt oder nicht auflösbar ist', function () use ($leiseDatei) {
    $koerper = '<span class="{{ $leise }}">a</span>@include(\'foodalchemist::livewire.teil\')';
    $mitNutzung = new TextRollenCodemod(includeLeser: fn (string $view) => '<b class="{{ $leise }}">x</b>');
    $unbekannt = new TextRollenCodemod(includeLeser: fn (string $view) => null);
    $ohneNutzung = new TextRollenCodemod(includeLeser: fn (string $view) => '<b class="fa-t-body">x</b>');

    expect($mitNutzung->umwandeln($leiseDatei($koerper), 'x.blade.php')['leise_aufgeloest'])->toBe(0)
        ->and($unbekannt->umwandeln($leiseDatei($koerper), 'x.blade.php')['leise_aufgeloest'])->toBe(0)
        ->and($ohneNutzung->umwandeln($leiseDatei($koerper), 'x.blade.php')['leise_aufgeloest'])->toBe(1);
});

it('löst nur Rollen-Werte auf; zwei Definitionen oder Nicht-Rollen-Werte bleiben', function () {
    $c = new TextRollenCodemod();
    $zwei = "@php(\$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]')\n@php(\$leise = 'opacity-50')\n<i class=\"{{ \$leise }}\">x</i>";
    $keineRolle = "@php(\$leise = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]')\n<i class=\"{{ \$leise }}\">x</i>";

    expect($c->umwandeln($zwei, 'x.blade.php')['leise_aufgeloest'])->toBe(0)
        ->and($c->umwandeln($keineRolle, 'x.blade.php')['html'])->toBe($keineRolle);
});

it('Schritt 2 ist idempotent', function () use ($leiseDatei) {
    $c = new TextRollenCodemod();
    $einmal = $c->umwandeln($leiseDatei('<span class="{{ $leise }}">a</span>'), 'x.blade.php')['html'];

    expect($c->umwandeln($einmal, 'x.blade.php')['html'])->toBe($einmal);
});

it('entfernt die Definition auch aus einem einzeiligen @php-Block mit weiteren Anweisungen', function () {
    $c = new TextRollenCodemod();
    $einzeln = "@php \$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'; @endphp\n<i class=\"{{ \$leise }}\">x</i>";
    $gemischt = "@php \$a = 1; \$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'; \$b = 2; @endphp\n<i class=\"{{ \$leise }}\">x</i>";

    expect($c->umwandeln($einzeln, 'x.blade.php')['html'])->toBe("<i class=\"fa-t-muted\">x</i>")
        ->and($c->umwandeln($gemischt, 'x.blade.php')['html'])->toBe("@php \$a = 1; \$b = 2; @endphp\n<i class=\"fa-t-muted\">x</i>");
});

it('zählt nur, was wirklich aufgelöst wurde; lässt sich die Definition nicht entfernen, bleibt alles wie es war', function () {
    // Definition in einer Form, die keine der Entfern-Regeln trifft (Zuweisung ohne Semikolon im @php-Ausdruck mit Klammern)
    $html = "@php(\$x = [\$leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'])\n<i class=\"{{ \$leise }}\">x</i>";
    $r = (new TextRollenCodemod())->umwandeln($html, 'x.blade.php');

    expect($r['leise_aufgeloest'])->toBe(0)
        ->and($r['html'])->toContain('class="{{ $leise }}"')
        ->and(implode("\n", $r['pruefen']))->toContain('nicht entfernbar');
});
