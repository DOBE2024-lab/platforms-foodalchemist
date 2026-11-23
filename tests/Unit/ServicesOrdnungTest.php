<?php

use Platform\FoodAlchemist\Tests\Support\ServicesUmzuege;

/**
 * 98 · 3.8 Ordnung: Dienste wandern aus dem Root von src/Services in Fachordner (scripts/services-umzug.php).
 *  · Kurz-Klassennamen unter src/Services bleiben eindeutig — AbhaengigkeitsGraph schlüsselt Kanten und Schichten nach
 *    Kurznamen; ein doppelter Name würde dort still überschrieben.
 *  · Kein Code nutzt mehr einen alten Klassennamen aus ServicesUmzuege (kein Alias-Lader: Jobs lösen Dienste in handle()
 *    auf, gespeicherte Klassennamen von Diensten gibt es nicht; geprüft in PR 0).
 *  · Der Root von src/Services schrumpft nur (Sperrklinke, Ziel < 50).
 */
const SERVICES_ROOT_MAX = 251;

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
