<?php

use Platform\FoodAlchemist\Support\Sektor;

/**
 * Code-Qualität 2.5a: ein Sektor-Vokabular. Ziel sind die sechs Sektoren der Rezept-Eignung (Entscheid
 * Dominique 2026-11-22); die alten Planungswerte laufen über eine Alias-Liste, gespeicherte Daten bleiben.
 */
it('kennt genau die sechs Sektoren der Rezept-Eignung, in dieser Reihenfolge', function () {
    expect(Sektor::slugs())->toBe(['business', 'care', 'crew', 'event_privat', 'kita_schule', 'restaurant'])
        ->and(array_keys(Sektor::LABELS))->toBe(Sektor::slugs());
});

it('jedes Label ist gesetzt und eindeutig', function () {
    expect(array_filter(Sektor::LABELS, fn ($l) => trim($l) === ''))->toBe([])
        ->and(array_unique(Sektor::LABELS))->toHaveCount(6);
});

it('Alias-Liste: alte Planungswerte → Eignungs-Slug', function () {
    expect(Sektor::ALIASE)->toBe([
        'betriebsgastronomie' => 'business',
        'catering' => 'event_privat',
        'schule_kita' => 'kita_schule',
    ]);
    foreach (Sektor::ALIASE as $alt => $neu) {
        expect(Sektor::gueltig($neu))->toBeTrue()->and(Sektor::gueltig($alt))->toBeFalse();
    }
});

it('kanonisch(): Slug bleibt, Alias wird umgeschlüsselt, Unbekanntes und Leeres → null', function () {
    expect(Sektor::kanonisch('business'))->toBe('business')
        ->and(Sektor::kanonisch(' Care '))->toBe('care')
        ->and(Sektor::kanonisch('CATERING'))->toBe('event_privat')
        ->and(Sektor::kanonisch('betriebsgastronomie'))->toBe('business')
        ->and(Sektor::kanonisch('schule_kita'))->toBe('kita_schule')
        ->and(Sektor::kanonisch('fine_dining'))->toBeNull()
        ->and(Sektor::kanonisch('Kita'))->toBeNull()
        ->and(Sektor::kanonisch(''))->toBeNull()
        ->and(Sektor::kanonisch(null))->toBeNull();
});

it('schreibweisen(): der Slug plus seine alten Aliase (für Altdaten-Lookups)', function () {
    expect(Sektor::schreibweisen('business'))->toBe(['business', 'betriebsgastronomie'])
        ->and(Sektor::schreibweisen('catering'))->toBe(['event_privat', 'catering'])
        ->and(Sektor::schreibweisen('crew'))->toBe(['crew'])
        ->and(Sektor::schreibweisen('fine_dining'))->toBe([]);
});

it('optionen(): Auswahlliste mit optionalem Leer-Eintrag', function () {
    expect(Sektor::optionen())->toBe(Sektor::LABELS)
        ->and(Sektor::optionen('Egal'))->toBe(['' => 'Egal'] + Sektor::LABELS);
});

/*
 * Sperre (CQ 2.5a): keine neue Sektor-Liste neben Support/Sektor. Gezählt werden je Datei die verschiedenen
 * Sektor-Werte (Slugs + Altwerte) als String-Literal; ab drei ist es eine Liste. Ausnahmen mit Höchstzahl:
 * Planung/Index (Werte in den Start-Vorlagen, keine Liste) und zwei fremde Achsen, die zufällig dieselben
 * Wörter tragen (Observatorium-Branchen, Küchentyp/Kundentypen).
 */
it('Sperre: keine Sektor-Liste außerhalb von Support/Sektor', function () {
    $basis = dirname(__DIR__, 2);
    $muster = "/'(".implode('|', [...Sektor::SLUGS, ...array_keys(Sektor::ALIASE)]).")'/";
    $erlaubt = [
        'src/Support/Sektor.php' => PHP_INT_MAX,
        'src/Livewire/Planung/Index.php' => 5,
        'src/Support/ObservatoriumInhalt.php' => 4,
        'src/Services/TeamSettingsService.php' => 3,
    ];
    $funde = [];
    foreach (['src', 'resources'] as $ordner) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis.'/'.$ordner, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $datei) {
            if (! str_ends_with($datei->getFilename(), '.php')) {
                continue;
            }
            preg_match_all($muster, (string) file_get_contents($datei->getPathname()), $m);
            $n = count(array_unique($m[1]));
            $rel = substr($datei->getPathname(), strlen($basis) + 1);
            if ($n >= 3 && $n > ($erlaubt[$rel] ?? 2)) {
                $funde[] = "{$rel} ({$n})";
            }
        }
    }
    expect($funde)->toBe([]);
});
