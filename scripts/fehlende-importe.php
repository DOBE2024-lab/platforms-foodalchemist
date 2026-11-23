<?php

/**
 * Fehlende Klassen-Imports finden (98 · 3.3, S3-4b).
 *
 * Wer Code in einen anderen Namespace verschiebt (Zerlegen großer Klassen in Teile), verliert leicht einen `use`:
 * Ein kurzer Klassenname, der im alten Namespace eine Datei hatte, zeigt im neuen ins Leere. `php -l` merkt das nicht,
 * es knallt erst zur Laufzeit (TypeError bei einem Typ-Hinweis, „Class not found“ bei `::` oder `new`).
 * Gefunden so in SpeiseplanAusgabe::buffetKarten (Typ-Hinweis im Closure).
 *
 * Geprüft werden kurze Namen in Code-Positionen: Typ-Hinweise vor einer Variable, Rückgabetypen, `X::`, `new X`,
 * `instanceof X`, `catch (X`, dazu (98 · 3.8) `extends`/`implements`, Trait-`use X;` im Klassenrumpf und Attribute `#[X`. Ein Name ist in Ordnung, wenn er importiert ist oder im eigenen Namespace als Datei
 * unter src/ liegt. Kommentare und Strings zählen nicht; relative Namen (`Rezept\X`) und Konstanten werden übersprungen.
 *
 * Aufruf (im Modul-Root):  php scripts/fehlende-importe.php [datei.php …]
 * Ohne Argumente: alle Dateien unter src/. Exit-Code 1, wenn etwas fehlt.
 */
$modul = dirname(__DIR__);
$dateien = array_slice($argv, 1);
if ($dateien === []) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul.'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $d) {
        if ($d->getExtension() === 'php') {
            $dateien[] = $d->getPathname();
        }
    }
    sort($dateien);
}

$eingebaut = array_flip(array_merge(get_declared_classes(), get_declared_interfaces()));
$befunde = 0;
foreach ($dateien as $datei) {
    $code = (string) file_get_contents($datei);
    $ns = preg_match('/^namespace ([^;]+);/m', $code, $m) ? $m[1] : '';

    $importiert = [];
    preg_match_all('/^use (?!function |const )([^;]+);/m', $code, $u);
    foreach ($u[1] as $zeile) {
        foreach (explode(',', preg_replace('/\{([^}]*)\}/', '$1', $zeile)) as $teil) {
            $teil = trim($teil);
            $alias = preg_match('/\sas\s+(\w+)$/', $teil, $a) ? $a[1] : substr((string) strrchr('\\'.$teil, '\\'), 1);
            $importiert[$alias] = true;
        }
    }

    $nurCode = '';
    foreach (token_get_all($code) as $t) {
        $leer = is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true);
        $nurCode .= is_array($t) ? ($leer ? ' ' : $t[1]) : $t;
    }

    preg_match_all(
        '/(?<![\\\\\w$>])([A-Z]\w*)(?![\\\\\w])(?=::|\s+&?(?:\.\.\.)?\$\w)'      // X::  ·  Typ-Hinweis vor $var
        .'|\b(?:new|instanceof)\s+([A-Z]\w*)(?![\\\\\w])'                         // new X · instanceof X
        .'|catch\s*\(\s*([A-Z]\w*)(?![\\\\\w])'                                    // catch (X
        .'|\)\s*:\s*\??([A-Z]\w*)(?![\\\\\w])(?=\s*[{;])/',                         // Rückgabetyp
        $nurCode, $treffer, PREG_SET_ORDER
    );

    // 98 · 3.8: auch extends/implements-Listen, Trait-use im Klassenrumpf (eingerückt, anders als Importe oben) und Attribute
    $namen = array_map(fn ($t) => ($t[1] ?? '') ?: (($t[2] ?? '') ?: (($t[3] ?? '') ?: ($t[4] ?? ''))), $treffer);
    preg_match_all('/\b(?:extends|implements)\s+([^{]+?)\s*\{/', $nurCode, $listen);
    preg_match_all('/^[ \t]+use\s+([^;{(]+)[;{]/m', $nurCode, $traits);
    foreach (array_merge($listen[1], $traits[1]) as $liste) {
        foreach (preg_split('/\s*,\s*|\s+implements\s+/', trim($liste)) as $teil) {
            if (preg_match('/^([A-Z]\w*)$/', trim($teil), $n)) {
                $namen[] = $n[1];
            }
        }
    }
    preg_match_all('/#\[\s*([A-Z]\w*)(?![\\\\\w])/', $nurCode, $attribute);
    $namen = array_merge($namen, $attribute[1]);

    $fehlt = [];
    foreach ($namen as $name) {
        if ($name === '' || in_array($name, ['self', 'static', 'parent'], true) || isset($importiert[$name]) || isset($eingebaut[$name])
            || preg_match('/^[A-Z0-9_]+$/', $name)) {   // KONSTANTE, kein Klassenname
            continue;
        }
        if ($ns === '' || preg_match('/^(?:class|interface|trait|enum)\s+'.$name.'\b/m', $code)) {
            continue;
        }
        $nsPfad = $modul.'/'.str_replace(['Platform\\FoodAlchemist\\', '\\'], ['src/', '/'], $ns).'/'.$name.'.php';
        if (! str_starts_with($ns, 'Platform\\FoodAlchemist\\') || ! is_file($nsPfad)) {
            $fehlt[$name] = true;
        }
    }
    if ($fehlt !== []) {
        $befunde++;
        $voll = (string) realpath($datei);
        echo str_starts_with($voll, $modul.'/') ? substr($voll, strlen($modul) + 1) : $datei, ': ', implode(', ', array_keys($fehlt)), PHP_EOL;
    }
}

echo $befunde === 0 ? 'Keine fehlenden Imports.'.PHP_EOL : "{$befunde} Datei(en) mit fehlenden Imports.".PHP_EOL;
exit($befunde === 0 ? 0 : 1);
