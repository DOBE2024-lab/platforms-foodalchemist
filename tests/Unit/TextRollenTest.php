<?php

use Platform\FoodAlchemist\Support\Ui;

/**
 * Welle 1, Paket 1.4a (Plan 98) — Text-Rollen als Klassen statt Typografie-Literale.
 *
 * Warum: ~4.800 Stellen schreiben Größe und Tinte einzeln aus
 * (`text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]`), dazu 58 lokale `$leise`-Kürzel.
 * Die Rollen bündeln das an EINER Stelle: Klassen in resources/css/foodalchemist-text.css (@layer components,
 * damit eine Utility wie `text-[var(--fa-crit)]` die Rolle im Einzelfall noch übersteuert),
 * Namen nur über Ui::maps()['textRolle'], Baustein x-fa::text.
 *
 * Eigene Datei statt foodalchemist-pass.css: io (eigenständige Hülle) baut sein CSS bei Modul-Deploys nicht
 * neu. Die Datei geht unverändert nach resources/dist und wird über _platform/fa-assets in BEIDEN Hüllen
 * geladen (Muster foodalchemist-observatorium.css) — der Codemod 1.4b hängt so nicht am Host-Build.
 */
$basis = dirname(__DIR__, 2);

it('kennt genau die sieben Rollen, Klassennamen voll ausgeschrieben (Scanner in build-css.mjs)', function () {
    expect(Ui::maps()['textRolle'])->toBe([
        'h1' => 'fa-t-h1',
        'h2' => 'fa-t-h2',
        'h3' => 'fa-t-h3',
        'strong' => 'fa-t-strong',
        'body' => 'fa-t-body',
        'label' => 'fa-t-label',
        'muted' => 'fa-t-muted',
    ]);
});

it('definiert jede Rolle in foodalchemist-text.css innerhalb von @layer components, nur mit Tokens', function () use ($basis) {
    $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($basis.'/resources/css/foodalchemist-text.css'));
    preg_match('/@layer components\s*\{(.*)\}/s', $css, $m);
    $komponenten = $m[1] ?? '';

    foreach (Ui::maps()['textRolle'] as $klasse) {
        expect(preg_match('/\.'.preg_quote($klasse, '/').'\s*\{([^}]*)\}/', $komponenten, $regel))->toBe(1, "{$klasse} fehlt in @layer components");
        expect($regel[1])->toContain('font-size: var(--fa-text-')
            ->toContain('color: var(--fa-ink')
            ->not->toMatch('/#[0-9a-f]{3,6}|\d+px|!important/i');
    }
});

it('trifft die Werte der bestehenden Bausteine, damit der Codemod (1.4b) nichts verschiebt', function () use ($basis) {
    $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($basis.'/resources/css/foodalchemist-text.css'));
    $regel = function (string $klasse) use ($css) {
        preg_match('/\.'.preg_quote($klasse, '/').'\s*\{([^}]*)\}/', $css, $m);

        return preg_replace('/\s+/', ' ', trim($m[1] ?? ''));
    };

    // x-fa::page-header (Seitentitel) und x-fa::section (Abschnittstitel)
    expect($regel('fa-t-h1'))->toContain('var(--fa-text-3xl)')->toContain('font-weight: 600')->toContain('color: var(--fa-ink);')
        ->and($regel('fa-t-h2'))->toContain('var(--fa-text-lg)')->toContain('font-weight: 600')
        ->and($regel('fa-t-h3'))->toContain('var(--fa-text-base)')->toContain('font-weight: 600')
        ->and($regel('fa-t-strong'))->toContain('var(--fa-text-md)')->toContain('font-weight: 500')->toContain('color: var(--fa-ink);')
        ->and($regel('fa-t-body'))->toContain('var(--fa-text-md)')->toContain('color: var(--fa-ink);')->not->toContain('font-weight')
        ->and($regel('fa-t-label'))->toContain('var(--fa-text-sm)')->toContain('font-weight: 500')->toContain('var(--fa-ink-2)')
        ->and($regel('fa-t-muted'))->toContain('var(--fa-text-sm)')->toContain('var(--fa-ink-3)')->not->toContain('font-weight');
});

it('liefert die dist-Kopie identisch zur Quelle aus (npm run build:css nicht vergessen)', function () use ($basis) {
    $dist = $basis.'/resources/dist/foodalchemist-text.css';

    expect(is_file($dist))->toBeTrue()
        ->and(file_get_contents($dist))->toBe(file_get_contents($basis.'/resources/css/foodalchemist-text.css'));
});

it('definiert die Rollen nur an einer Stelle (nicht zusätzlich in foodalchemist-pass.css)', function () use ($basis) {
    expect(file_get_contents($basis.'/resources/css/foodalchemist-pass.css'))->not->toContain('.fa-t-');
});

it('zeigt jede Rolle auf der Designsystem-Seite', function () use ($basis) {
    $view = file_get_contents($basis.'/resources/views/livewire/ui-katalog.blade.php');

    expect($view)->toContain("Ui::maps()['textRolle']")->toContain('<x-fa::text');
});
