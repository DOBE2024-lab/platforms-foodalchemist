<?php

/**
 * Plan 98, 3.9 (U10, E5): Die dunkle Editor-Ebene bleibt; die Kontraste der Token-Farbpaare halten WCAG 2.1 AA ein.
 * Text ≥ 4,5:1 (1.4.3), Ränder von Bedienelementen ≥ 3:1 (1.4.11). Trennlinien (--fa-line, --fa-line-strong) sind
 * Dekoration und ausgenommen. Quelle: resources/css/foodalchemist-pass.css — hell :root, dunkel [data-fa-theme="dark"]
 * (erbt alles, was dort nicht neu gesetzt ist), Navigation (rail) immer dunkel.
 */
function kontrastTokens(string $selektor): array
{
    $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/foodalchemist-pass.css');
    $start = strpos($css, $selektor . ' {');
    expect($start)->not->toBeFalse($selektor);
    $auf = strpos($css, '{', $start);
    $zu = strpos($css, '}', $auf);
    preg_match_all('/(--[\w-]+):\s*([^;]+);/', substr($css, $auf, $zu - $auf), $m, PREG_SET_ORDER);

    return array_column($m, 2, 1);
}

function kontrastWert(string $vorne, string $hinten): float
{
    $rgb = function (string $hex): array {
        $hex = ltrim(trim($hex), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    };
    $lum = function (array $c): float {
        $k = fn ($x) => ($x /= 255) <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $k($c[0]) + 0.7152 * $k($c[1]) + 0.0722 * $k($c[2]);
    };
    [$a, $b] = [$lum($rgb($vorne)), $lum($rgb($hinten))];

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/** [Vordergrund, Hintergrund, Mindestwert] — feste Paarliste je Welt. */
function kontrastPaare(bool $dunkel): array
{
    $paare = [];
    $flaechen = ['--fa-ground', '--fa-surface', '--fa-neutral-soft'];
    if ($dunkel) {
        $flaechen[] = '--fa-field';
    }
    foreach (['--fa-ink', '--fa-ink-2', '--fa-ink-3', '--fa-accent'] as $text) {
        foreach ($flaechen as $flaeche) {
            $paare[] = [$text, $flaeche, 4.5];
        }
    }
    foreach (['ok', 'warn', 'crit', 'info'] as $ton) {
        $paare[] = ["--fa-$ton", '--fa-surface', 4.5];
        $paare[] = ["--fa-$ton", "--fa-$ton-soft", 4.5];
    }
    $paare[] = ['--fa-accent', '--fa-accent-soft', 4.5];
    $paare[] = ['--fa-on-accent', '--fa-accent', 4.5];
    $paare[] = ['--fa-on-accent', '--fa-accent-hover', 4.5];
    $paare[] = ['--fa-control-line', '--fa-surface', 3.0];
    $paare[] = ['--fa-control-line', $dunkel ? '--fa-field' : '--fa-ground', 3.0];
    $paare[] = ['--fa-crit-line', '--fa-surface', 3.0];
    if ($dunkel) {
        // Tailwind-Brücke im Werkbank-Bereich (Altbestand): Grautexte für leisen Text
        foreach (['--color-gray-400', '--color-gray-500', '--color-gray-600', '--color-slate-400'] as $grau) {
            $paare[] = [$grau, '--fa-surface', 4.5];
            $paare[] = [$grau, '--fa-ground', 4.5];
        }
    }

    return $paare;
}

function kontrastVerstoesse(array $tokens, array $paare): array
{
    $verstoesse = [];
    foreach ($paare as [$vorne, $hinten, $min]) {
        expect($tokens)->toHaveKey($vorne)->toHaveKey($hinten);
        $wert = kontrastWert($tokens[$vorne], $tokens[$hinten]);
        if ($wert < $min) {
            $verstoesse[] = sprintf('%s auf %s: %.2f < %.1f', $vorne, $hinten, $wert, $min);
        }
    }

    return $verstoesse;
}

it('helle Welt: Text ≥ 4,5, Bedien-Ränder ≥ 3', function () {
    expect(kontrastVerstoesse(kontrastTokens(':root'), kontrastPaare(false)))->toBe([]);
});

it('dunkle Werkbank: Text ≥ 4,5, Bedien-Ränder ≥ 3, auch die Tailwind-Brücke', function () {
    $dunkel = kontrastTokens(':root') ;
    $dunkel = array_merge($dunkel, kontrastTokens('[data-fa-theme="dark"]'));

    expect(kontrastVerstoesse($dunkel, kontrastPaare(true)))->toBe([]);
});

it('Navigation (immer dunkel): Einträge, leise Einträge und aktiver Eintrag ≥ 4,5', function () {
    $t = kontrastTokens(':root');

    expect(kontrastVerstoesse($t, [['--fa-rail-ink', '--fa-rail', 4.5], ['--fa-rail-muted', '--fa-rail', 4.5], ['--fa-rail-active-ink', '--fa-rail-active', 4.5]]))->toBe([]);
});

it('Bedienelemente nutzen --fa-control-line, Trennlinien bleiben line-strong', function () {
    $wurzel = dirname(__DIR__, 2);
    $css = file_get_contents("$wurzel/resources/css/foodalchemist-pass.css");

    expect($css)->toMatch('/\.fa-control \{[^}]*border: 1px solid var\(--fa-control-line\)/s')
        ->toMatch('/\.fa-chip > span \{[^}]*border: 1px solid var\(--fa-control-line\)/s')
        ->and(file_get_contents("$wurzel/resources/views/components/fa/button.blade.php"))->toContain('border border-[var(--fa-control-line)]')
        ->and(file_get_contents("$wurzel/resources/views/components/fa/upload.blade.php"))->toContain('file:border-[var(--fa-control-line)]');
});

it('Rechnung stimmt: Schwarz auf Weiß 21, gleiche Farbe 1', function () {
    expect(round(kontrastWert('#000000', '#ffffff'), 2))->toBe(21.0)
        ->and(round(kontrastWert('#4a5466', '#4a5466'), 2))->toBe(1.0);
});

it('Modal blendet weich ein und aus, dunkle Editoren mit stärkerem Backdrop', function () {
    $wurzel = dirname(__DIR__, 2);
    $modal = file_get_contents("$wurzel/resources/views/components/fa/modal.blade.php");
    $css = file_get_contents("$wurzel/resources/css/foodalchemist-pass.css");

    expect($modal)->toContain('x-transition:enter="fa-modal-ein" x-transition:enter-start="fa-modal-start" x-transition:leave="fa-modal-aus" x-transition:leave-end="fa-modal-start"')
        ->toContain("\$darkCanvas ? 'fa-modal-backdrop-dunkel' : 'bg-[var(--fa-rail)]/60'")
        ->toContain('fa-modal-panel')
        ->and($css)->toContain('.fa-modal-ein { transition: opacity 180ms ease-out; }')
        ->toContain('.fa-modal-aus { transition: opacity 120ms ease-in; }')
        ->toContain('.fa-modal-backdrop-dunkel { background: rgba(13, 20, 36, 0.7); }')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});

/**
 * 98 · 3.9 (io-Check Master): rounded-[var(--fa-radius-card)] renderte 0px, weil das Token fehlte. Jede in Views, src
 * oder CSS ohne Rückfall verwendete --fa-*-Variable muss in resources/css definiert sein (oder inline gesetzt werden).
 */
/** Bewusst leer: --fa-text-xl/-xs gibt es nicht (Kurator 22.11.) — die Skala ist sm 12 · md 13 · base 14 · lg 16 · 2xl 24 · 3xl 28. */
const TOKEN_OFFEN = [];

it('jede verwendete --fa-*-Variable ohne Rückfall ist definiert; --fa-radius-card ist 10px', function () {
    $wurzel = dirname(__DIR__, 2);
    $dateien = array_merge(glob("$wurzel/resources/css/*.css"), iterator_to_array((function () use ($wurzel) {
        foreach (['resources/views', 'src'] as $ordner) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$wurzel/$ordner", FilesystemIterator::SKIP_DOTS)) as $f) {
                if (str_ends_with($f->getFilename(), '.php')) {
                    yield $f->getPathname();
                }
            }
        }
    })(), false));
    $definiert = [];
    $genutzt = [];
    foreach ($dateien as $datei) {
        $s = file_get_contents($datei);
        preg_match_all('/(--fa-[\w-]+)\s*:/', $s, $d);
        $definiert += array_flip($d[1]);
        preg_match_all('/var\((--fa-[\w-]+)\)/', $s, $n);   // nur ohne Rückfall
        foreach ($n[1] as $token) {
            $genutzt[$token][] = substr($datei, strlen($wurzel) + 1);
        }
    }
    $fehlt = array_diff_key($genutzt, $definiert, TOKEN_OFFEN);

    expect(array_map(fn ($orte) => array_values(array_unique($orte)), $fehlt))->toBe([])
        ->and(kontrastTokens(':root'))->toHaveKey('--fa-radius-card')
        ->and(trim(kontrastTokens(':root')['--fa-radius-card']))->toBe('10px');
});
