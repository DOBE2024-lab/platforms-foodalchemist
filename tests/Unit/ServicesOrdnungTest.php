<?php

use Platform\FoodAlchemist\Tests\Support\ServicesUmzuege;

/**
 * 98 · 3.8 Ordnung: Dienste wandern aus dem Root von src/Services in Fachordner (scripts/services-umzug.php).
 *  · Kurz-Klassennamen unter src/Services bleiben eindeutig — AbhaengigkeitsGraph schlüsselt Kanten und Schichten nach
 *    Kurznamen; ein doppelter Name würde dort still überschrieben.
 *  · Kein Code nutzt mehr einen alten Klassennamen aus ServicesUmzuege (kein Alias-Lader: Jobs lösen Dienste in handle()
 *    auf, gespeicherte Klassennamen von Diensten gibt es nicht; geprüft in PR 0).
 *  · Der Root von src/Services schrumpft nur (Sperrklinke, Ziel < 50).
 *  · Jedes dirname(__DIR__, N) unter src/Services zeigt auf src/ oder den Modul-Root — eine Ebene tiefer verschoben, zeigte es
 *    sonst ins Leere (CI-Fund 3.8: Schriften der ShareCard, DarreichungResolver::aufrufer).
 *  Alte Klassennamen werden auch in Strings gefunden (ein oder zwei Backslashes), nicht nur in use-Zeilen.
 */
const SERVICES_ROOT_MAX = 77;

function servicesOrdnungModul(): string
{
    return dirname(__DIR__, 2);
}

it('Kurz-Klassennamen unter src/Services sind eindeutig (AbhaengigkeitsGraph schlüsselt danach)', function () {
    $namen = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(servicesOrdnungModul().'/src/Services', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->getExtension() === 'php'
            && preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', (string) file_get_contents($datei->getPathname()), $m)) {
            $namen[$m[1]][] = substr($datei->getPathname(), strlen(servicesOrdnungModul()) + 1);
        }
    }

    expect(array_filter($namen, fn ($pfade) => count($pfade) > 1))->toBe([]);
});

it('kein Code nutzt einen alten Klassennamen eines verschobenen Dienstes', function () {
    $alt = array_keys(ServicesUmzuege::klassen());
    if ($alt === []) {
        expect(ServicesUmzuege::KARTE)->toBe([]);

        return;
    }
    // im Code einfacher, in Strings doppelter Backslash
    $muster = '/(?:'.implode('|', array_map(fn ($k) => implode('\\\\{1,2}', array_map(fn ($t) => preg_quote($t, '/'), explode('\\', $k))), $alt)).')(?![\\\\\w])/';
    $funde = [];
    foreach (['src', 'tests', 'resources', 'routes', 'config', 'database'] as $ordner) {
        $pfad = servicesOrdnungModul().'/'.$ordner;
        if (! is_dir($pfad)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS)) as $datei) {
            if ($datei->getExtension() !== 'php' || str_ends_with($datei->getPathname(), 'Support/ServicesUmzuege.php')) {
                continue;
            }
            if (preg_match_all($muster, (string) file_get_contents($datei->getPathname()), $m)) {
                $funde[] = substr($datei->getPathname(), strlen(servicesOrdnungModul()) + 1).': '.implode(', ', array_unique($m[0]));
            }
        }
    }

    expect($funde)->toBe([]);
});

it('der Root von src/Services schrumpft nur; verschobene Dateien liegen nicht mehr am alten Ort', function () {
    $root = glob(servicesOrdnungModul().'/src/Services/*.php');
    $nochDa = array_filter(array_keys(ServicesUmzuege::KARTE), fn ($p) => is_file(servicesOrdnungModul().'/src/'.$p));
    $fehlt = array_filter(ServicesUmzuege::KARTE, fn ($p) => ! is_file(servicesOrdnungModul().'/src/'.$p));

    expect(count($root))->toBeLessThanOrEqual(SERVICES_ROOT_MAX)
        ->and($nochDa)->toBe([])
        ->and($fehlt)->toBe([]);
});

it('jedes dirname(__DIR__, N) unter src/Services zeigt auf src/ oder den Modul-Root', function () {
    $modul = realpath(servicesOrdnungModul());
    $falsch = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul.'/src/Services', FilesystemIterator::SKIP_DOTS)) as $datei) {
        if ($datei->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/dirname\(__DIR__(?:,\s*(\d+))?\)/', (string) file_get_contents($datei->getPathname()), $m, PREG_SET_ORDER);
        foreach ($m as $treffer) {
            $ziel = dirname($datei->getPath(), (int) ($treffer[1] ?? 1));
            if (! in_array($ziel, [$modul, $modul.'/src'], true)) {
                $falsch[] = substr($datei->getPathname(), strlen($modul) + 1).': '.$treffer[0].' → '.$ziel;
            }
        }
    }

    expect($falsch)->toBe([]);
});

/**
 * 3.8-Rest (CI-Fund): Tests lesen Quelldateien auch über zusammengesetzte Pfade (__DIR__ . '/../../src/Services/X.php'). Nach einem
 * Umzug zeigten zwei davon ins Leere. Jeder Pfad-String 'src/Services/….php' unter tests/ muss auf eine vorhandene Datei zeigen.
 * Ausnahme: Tests, die gerade prüfen, dass eine Datei weg ist.
 */
const SERVICES_PFAD_SOLL_FEHLEN = [
    'tests/Feature/WissenSectionsGedropptTest.php' => ['src/Services/Knowledge/KnowledgeSectionizer.php', 'src/Services/Knowledge/KnowledgeChunker.php'],
];

it('Quell-Pfade src/Services/….php in Tests zeigen auf vorhandene Dateien (Umzüge ziehen sie mit)', function () {
    $modul = dirname(__DIR__, 2);
    $tot = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul.'/tests', FilesystemIterator::SKIP_DOTS)) as $datei) {
        $rel = substr($datei->getPathname(), strlen($modul) + 1);
        if ($datei->getExtension() !== 'php' || $rel === 'tests/Support/ServicesUmzuege.php' || $rel === 'tests/Unit/ServicesOrdnungTest.php') {
            continue;
        }
        preg_match_all('#src/Services/[A-Za-z0-9_/]+\.php#', (string) file_get_contents($datei->getPathname()), $m);
        foreach (array_unique($m[0]) as $pfad) {
            if (! is_file($modul.'/'.$pfad) && ! in_array($pfad, SERVICES_PFAD_SOLL_FEHLEN[$rel] ?? [], true)) {
                $tot[] = "$rel: $pfad";
            }
        }
    }

    expect($tot)->toBe([]);
});
