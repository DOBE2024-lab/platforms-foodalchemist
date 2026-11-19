<?php

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * 98 · 1.2d · Command-Liste (`src/Console/_liste.php`) und Zeitplan (`src/Console/_zeitplan.php`) als eigene
 * Dateien statt im ServiceProvider. Muster und Prüfungen wie `Spec84ToolListeTest`.
 */
beforeEach(function () {
    // Kein globaler Helfer in der Testdatei (paralleler Lauf, s. fa_test.sh).
    $this->liste = array_map(fn ($k) => ltrim($k, '\\'), require dirname(__DIR__, 2) . '/src/Console/_liste.php');
});

it('1.2d · Command-Liste ist alphabetisch und ohne Dubletten', function () {
    $klassen = $this->liste;
    $sortiert = $klassen;
    usort($sortiert, fn ($a, $b) => strcasecmp($a, $b));

    expect($klassen)->toBe($sortiert, 'Bitte alphabetisch einsortieren (eine Zeile je Command)')
        ->and(array_values(array_unique($klassen)))->toBe($klassen);
});

it('1.2d · jede konkrete Command-Klasse unter src/Console steht in der Liste, jede Listen-Zeile ist ein Command', function () {
    $dir = dirname(__DIR__, 2) . '/src/Console';
    $konkret = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->getExtension() !== 'php' || str_starts_with($datei->getFilename(), '_')) {
            continue;   // _liste.php, _zeitplan.php
        }
        $rel = substr($datei->getPathname(), strlen($dir) + 1, -4);
        $klasse = 'Platform\\FoodAlchemist\\Console\\' . str_replace('/', '\\', $rel);
        if (! class_exists($klasse)) {
            continue;
        }
        $r = new ReflectionClass($klasse);
        if (! $r->isAbstract() && $r->isSubclassOf(Command::class)) {
            $konkret[] = $klasse;
        }
    }
    $liste = $this->liste;

    expect(array_values(array_diff($konkret, $liste)))->toBe([], 'Command-Klasse fehlt in src/Console/_liste.php')
        ->and(array_values(array_diff($liste, $konkret)))->toBe([], 'Listen-Zeile ohne konkrete Command-Klasse');
});

it('1.2d · nach dem Boot ist jeder Command der Liste bei Artisan registriert', function () {
    $registriert = collect(Artisan::all())->map(fn ($c) => $c::class)->values()->all();

    expect(array_values(array_diff($this->liste, $registriert)))->toBe([]);
});

it('1.2d · jeder geplante Lauf ruft einen Command aus der Liste', function () {
    $signaturen = collect($this->liste)->map(fn ($k) => app($k)->getName())->all();
    $geplant = collect(app(Schedule::class)->events())
        ->map(fn ($e) => (string) $e->command)
        ->filter(fn ($c) => str_contains($c, 'foodalchemist:'))
        ->map(fn ($c) => preg_match('/(foodalchemist:[\w:-]+)/', $c, $m) ? $m[1] : $c)
        ->unique()->values();

    expect($geplant)->not->toBeEmpty()
        ->and($geplant->reject(fn ($s) => in_array($s, $signaturen, true))->values()->all())->toBe([]);
});
