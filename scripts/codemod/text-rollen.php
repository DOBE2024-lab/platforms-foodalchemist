<?php

/**
 * Welle 1, Paket 1.4b — Codemod Typografie-Literale → Text-Rollen. Wiederholbar und idempotent.
 *
 *   php scripts/codemod/text-rollen.php                       Trockenlauf: Bericht auf stdout, nichts geändert
 *   php scripts/codemod/text-rollen.php --bericht=datei.md    Trockenlauf, Bericht zusätzlich als Datei
 *   php scripts/codemod/text-rollen.php --schreiben           Views wirklich umstellen (erst nach Freigabe 1.4b)
 *
 * Danach (nur mit --schreiben): FA_UI_BASELINE_SCHREIBEN=1 … --filter=UiRegeln (Zahlen sinken),
 * npm run build:css, Screenshot-Vergleich laut Plan 98 1.4b. Regeln und Grenzen: TextRollenCodemod.php.
 */
require __DIR__.'/TextRollenCodemod.php';

$schreiben = in_array('--schreiben', $argv, true);
$berichtDatei = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--bericht=')) {
        $berichtDatei = substr($arg, 10);
    }
}

$basis = realpath(__DIR__.'/../../resources/views');
$codemod = new TextRollenCodemod();
$summe = ['umgestellt' => [], 'ohne_rolle' => [], 'leise' => [], 'pruefen' => []];
$jeDatei = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS));
$dateien = [];
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), '.blade.php')) {
        $dateien[] = $f->getPathname();
    }
}
sort($dateien);

foreach ($dateien as $voll) {
    $pfad = substr($voll, strlen($basis) + 1);
    if (str_starts_with($pfad, 'components/')) {
        continue; // Bausteine sind die Quelle, nicht das Ziel (wie UiRegeln::REGELN_AUSSER_BAUSTEINE)
    }
    $alt = file_get_contents($voll);
    $r = $codemod->umwandeln($alt, $pfad);
    foreach (['umgestellt', 'ohne_rolle', 'leise'] as $k) {
        foreach ($r[$k] as $schluessel => $n) {
            $summe[$k][$schluessel] = ($summe[$k][$schluessel] ?? 0) + $n;
        }
    }
    array_push($summe['pruefen'], ...$r['pruefen']);
    $n = array_sum($r['umgestellt']);
    if ($n > 0 || $r['html'] !== $alt) {
        $jeDatei[$pfad] = $n;
        if ($schreiben && $r['html'] !== $alt) {
            file_put_contents($voll, $r['html']);
        }
    }
}

arsort($summe['umgestellt']);
arsort($summe['ohne_rolle']);
arsort($summe['leise']);
arsort($jeDatei);

$z = [];
$z[] = '# Codemod Text-Rollen — '.($schreiben ? 'GESCHRIEBEN' : 'Trockenlauf').' ('.date('Y-m-d H:i').')';
$z[] = '';
$z[] = sprintf('Umgestellt: **%d Stellen** in **%d Dateien** (Views ohne components/). Prüfliste: %d. Ohne Rollen-Treffer: %d.',
    array_sum($summe['umgestellt']), count($jeDatei), count($summe['pruefen']), array_sum($summe['ohne_rolle']));
$z[] = '';
$z[] = '## Je Rolle';
foreach ($summe['umgestellt'] as $rolle => $n) {
    $z[] = "- {$rolle}: {$n}";
}
$z[] = '';
$z[] = '## $leise-Definitionen';
foreach ($summe['leise'] as $wert => $n) {
    $z[] = "- {$wert}: {$n}";
}
$z[] = '';
$z[] = '## Ohne Rollen-Treffer (Größe Tinte Gewicht; bleibt unverändert) — Top 20';
foreach (array_slice($summe['ohne_rolle'], 0, 20, true) as $kombi => $n) {
    $z[] = "- `{$kombi}`: {$n}";
}
$z[] = '';
$z[] = '## Prüfliste: Kollisionsgefahr in @layer components (nicht umgestellt)';
foreach ($summe['pruefen'] as $p) {
    $z[] = "- {$p}";
}
$z[] = '';
$z[] = '## Je Datei (Top 30)';
foreach (array_slice($jeDatei, 0, 30, true) as $pfad => $n) {
    $z[] = "- {$pfad}: {$n}";
}

$text = implode("\n", $z)."\n";
echo $text;
if ($berichtDatei !== null) {
    file_put_contents($berichtDatei, $text);
}
