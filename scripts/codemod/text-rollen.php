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

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS));
$original = [];
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), '.blade.php')) {
        $original[substr($f->getPathname(), strlen($basis) + 1)] = file_get_contents($f->getPathname());
    }
}
ksort($original);
$stand = $original; // Arbeitsstand im Speicher: Includes sehen immer den aktuellen Stand, nicht die Platte

// @include('foodalchemist::a.b') → a/b.blade.php; fremde Namensräume gelten als nicht prüfbar.
$includeLeser = function (string $view) use (&$stand): ?string {
    if (! str_starts_with($view, 'foodalchemist::')) {
        return null;
    }

    return $stand[str_replace('.', '/', substr($view, strlen('foodalchemist::'))).'.blade.php'] ?? null;
};
$codemod = new TextRollenCodemod(includeLeser: $includeLeser);
$summe = ['umgestellt' => [], 'ohne_rolle' => [], 'leise' => [], 'pruefen' => [], 'leise_aufgeloest' => 0];

// Durchläufe bis zum Fixpunkt: löst ein Partial sein eigenes $leise auf, darf im nächsten Durchlauf das
// Elternteil folgen (vorher erbte das Partial die Definition). Zählungen summieren, Prüfliste/ohne Rolle/
// $leise-Werte gelten aus dem letzten bzw. ersten Durchlauf.
for ($durchlauf = 1; $durchlauf <= 5; $durchlauf++) {
    $geaendert = false;
    $pruefen = [];
    $ohneRolle = [];
    foreach ($stand as $pfad => $alt) {
        if (str_starts_with($pfad, 'components/')) {
            continue; // Bausteine sind die Quelle, nicht das Ziel (wie UiRegeln::REGELN_AUSSER_BAUSTEINE)
        }
        $r = $codemod->umwandeln($alt, $pfad);
        foreach ($r['umgestellt'] as $rolle => $n) {
            $summe['umgestellt'][$rolle] = ($summe['umgestellt'][$rolle] ?? 0) + $n;
        }
        foreach ($r['ohne_rolle'] as $k => $n) {
            $ohneRolle[$k] = ($ohneRolle[$k] ?? 0) + $n;
        }
        if ($durchlauf === 1) {
            foreach ($r['leise'] as $k => $n) {
                $summe['leise'][$k] = ($summe['leise'][$k] ?? 0) + $n;
            }
        }
        array_push($pruefen, ...$r['pruefen']);
        $summe['leise_aufgeloest'] += $r['leise_aufgeloest'];
        if ($r['html'] !== $alt) {
            $stand[$pfad] = $r['html'];
            $geaendert = true;
        }
    }
    $summe['pruefen'] = $pruefen;
    $summe['ohne_rolle'] = $ohneRolle;
    if (! $geaendert) {
        break;
    }
}

$jeDatei = [];
foreach ($stand as $pfad => $neu) {
    if ($neu === $original[$pfad]) {
        continue;
    }
    $jeDatei[$pfad] = count(array_diff(explode("\n", $original[$pfad]), explode("\n", $neu)));
    if ($schreiben) {
        file_put_contents($basis.'/'.$pfad, $neu);
    }
}

arsort($summe['umgestellt']);
arsort($summe['ohne_rolle']);
arsort($summe['leise']);
arsort($jeDatei);

$z = [];
$z[] = '# Codemod Text-Rollen — '.($schreiben ? 'GESCHRIEBEN' : 'Trockenlauf').' ('.date('Y-m-d H:i').')';
$z[] = '';
$z[] = sprintf('Umgestellt: **%d Stellen** in **%d Dateien** (Views ohne components/), %d Durchläufe bis zum Fixpunkt. Prüfliste: %d. Ohne Rollen-Treffer: %d.',
    array_sum($summe['umgestellt']), count($jeDatei), min($durchlauf, 5), count($summe['pruefen']), array_sum($summe['ohne_rolle']));
$z[] = '';
$z[] = '## Je Rolle';
foreach ($summe['umgestellt'] as $rolle => $n) {
    $z[] = "- {$rolle}: {$n}";
}
$z[] = '';
$z[] = '## $leise-Definitionen';
$z[] = "- aufgelöst (Definition entfernt, Wert in class eingesetzt): {$summe['leise_aufgeloest']}";
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
$z[] = '## Je Datei: geänderte Zeilen (Top 30)';
foreach (array_slice($jeDatei, 0, 30, true) as $pfad => $n) {
    $z[] = "- {$pfad}: {$n}";
}

$text = implode("\n", $z)."\n";
echo $text;
if ($berichtDatei !== null) {
    file_put_contents($berichtDatei, $text);
}
