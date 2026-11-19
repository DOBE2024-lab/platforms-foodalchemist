<?php

/**
 * Tailwind-Klassen müssen voll ausgeschrieben im Quelltext stehen: der Scanner in build-css.mjs (und der
 * Host-Build auf io) sieht nur ganze Wörter. `'bg-[var(--fa-'.$ton.'-soft)]'` erzeugt deshalb keine Regel —
 * die Farbe fehlt still. Befund 19.11. (Codemod 1.4b): components/kombination.blade.php.
 * Richtig: feste Zuordnung, z. B. ['crit' => 'bg-[var(--fa-crit-soft)]', …][$ton].
 */
it('setzt keine Token-Klassen aus Variablen zusammen (var(--fa-'.'…) per Verkettung oder Blade-Ausdruck)', function () {
    $basis = dirname(__DIR__, 2);
    $funde = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis.'/resources/views', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if (! str_ends_with($datei->getFilename(), '.blade.php')) {
            continue;
        }
        foreach (file($datei->getPathname()) as $nr => $zeile) {
            // --fa-'.$x  ·  --fa-".$x  ·  --fa-{{ $x }}
            if (preg_match('/--fa-(?:[\'"]\s*\.|\{\{)/', $zeile)) {
                $funde[] = str_replace($basis.'/', '', $datei->getPathname()).':'.($nr + 1);
            }
        }
    }

    expect($funde)->toBe([], "Zusammengesetzte Token-Klassen (Scanner erzeugt sie nicht):\n".implode("\n", $funde));
});
