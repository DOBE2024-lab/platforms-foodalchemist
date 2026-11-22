<?php

/**
 * fa-pass Welle 0 — Design-Regeln als Sperrklinke.
 *
 * Warum: Die Oberfläche war in 234 Views auseinandergelaufen (536 Hex-Farben, 16 Schriftgrößen,
 * 290 Emoji, 43 × !important). Bausteine allein halten das nicht auf — dieser Test tut es.
 *
 * Mechanik:
 *   - Bausteine unter components/fa/ müssen JEDE Regel zu 100 % einhalten.
 *   - Alle anderen Views dürfen nicht SCHLECHTER werden als ihr eingefrorener Stand
 *     (tests/Fixtures/ui_regeln_baseline.json). Neue Dateien starten bei null.
 *   - Wird eine View umgebaut, sinken ihre Zähler — danach den Stand neu einfrieren:
 *       FA_UI_BASELINE_SCHREIBEN=1 vendor/bin/pest --filter=UiRegeln
 *     So wird die Klinke mit jeder Welle enger, nie lockerer.
 *   - Die Baseline selbst darf gegenüber der eingecheckten Fassung (Abzweig von origin/main) nicht
 *     steigen (Ersatz für eine CI-Prüfung). Wer die Baseline neu schreibt und dabei eine Zahl anhebt,
 *     bekommt hier Rot — dann den Baustein nutzen, nicht die Zahl.
 *
 * Welle 1 (1.4d, Plan 98) ergänzt Regeln für die Tailwind-Umstellung (UiRegeln::REGELN_AUSSER_BAUSTEINE):
 *   typo_groesse / typo_tinte (Typografie-Literale → künftig .fa-t-*), leise_definition (lokale $leise),
 *   roher_button, rohe_checkbox, alte_palette (text-gray-* usw.). Sie gelten nicht in den Baustein-Dateien
 *   unter components/ — die SIND die eine Stelle, an der ein <button> oder eine Checkbox stehen darf.
 */

use Platform\FoodAlchemist\Tests\Support\BaselineKlinke;
use Platform\FoodAlchemist\Tests\Support\UiRegeln;

it('hält die Bausteine unter components/fa vollständig regelkonform', function () {
    $verstoesse = array_filter(UiRegeln::zaehlen(), fn ($_, $pfad) => str_starts_with($pfad, 'components/fa/'), ARRAY_FILTER_USE_BOTH);

    expect($verstoesse)->toBe([], 'Bausteine verletzen Design-Regeln: ' . json_encode($verstoesse, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
});

it('lässt keine View schlechter werden als ihren eingefrorenen Stand', function () {
    $ist = UiRegeln::zaehlen();

    if (getenv('FA_UI_BASELINE_SCHREIBEN') === '1') {
        file_put_contents(UiRegeln::baselinePfad(), json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->markTestSkipped('Stand neu eingefroren: ' . count($ist) . ' Dateien mit Altlasten.');
    }

    $soll = json_decode((string) @file_get_contents(UiRegeln::baselinePfad()), true) ?? [];
    $schlechter = [];
    foreach ($ist as $pfad => $regeln) {
        foreach ($regeln as $regel => $anzahl) {
            $erlaubt = $soll[$pfad][$regel] ?? 0;
            if ($anzahl > $erlaubt) {
                $schlechter[] = "{$pfad}: {$regel} {$anzahl} (erlaubt {$erlaubt})";
            }
        }
    }

    expect($schlechter)->toBe([], "Design-Regeln verletzt — Token/Baustein nutzen statt Einzelwert:\n" . implode("\n", $schlechter));
});

/**
 * Unverändert verschobene Blade-Teile (neue Datei => Quelldatei). Ihre Treffer zählen für die Klinke weiter bei der
 * Quelle, die Summe darf nicht steigen. Nur für reine Umzüge; wer dabei umschreibt, senkt stattdessen die Zahlen.
 */
const UI_UMZUEGE = [
    'livewire/produktion/tagesplan-feedback.blade.php' => 'livewire/produktion/tagesplan.blade.php',   // 98 · 3.3 S6
    'livewire/produktion/tagesplan-anleitung.blade.php' => 'livewire/produktion/tagesplan.blade.php',  // 98 · 3.3 S6
    'livewire/produktion/tagesplan-wall.blade.php' => 'livewire/produktion/tagesplan.blade.php',       // 98 · 3.3 S6
    'src/Support/Produktion/WallStil.php' => 'livewire/produktion/tagesplan.blade.php',                // 98 · 3.3 S6
];

it('Umzüge: verschobene Treffer zählen bei der Quelle, ein Anstieg in der Summe bleibt rot (Selbsttest)', function () {
    $alt = ['quelle.blade.php' => ['roher_button' => 5]];
    $umzug = ['ziel.blade.php' => 'quelle.blade.php'];

    expect(BaselineKlinke::gestiegen(['quelle.blade.php' => ['roher_button' => 3], 'ziel.blade.php' => ['roher_button' => 2]], $alt, $umzug))->toBe([])
        ->and(BaselineKlinke::gestiegen(['quelle.blade.php' => ['roher_button' => 3], 'ziel.blade.php' => ['roher_button' => 3]], $alt, $umzug))
        ->toBe(['quelle.blade.php: roher_button 5 → 6'])
        ->and(BaselineKlinke::gestiegen(['quelle.blade.php' => ['roher_button' => 3], 'ziel.blade.php' => ['roher_button' => 2]], $alt))
        ->toBe(['ziel.blade.php: roher_button 0 → 2']);   // ohne Umzugsliste: neue Datei steigt
});

it('UI-Baseline steigt nicht gegenüber der eingecheckten Fassung', function () {
    $steigt = BaselineKlinke::steigtGegenueberGit(UiRegeln::baselinePfad(), UI_UMZUEGE);
    if ($steigt === null) {
        $this->markTestSkipped('Keine eingecheckte Vergleichsfassung (kein git oder Datei neu).');
    }

    expect($steigt)->toBe([], "UI-Baseline wurde angehoben — bitte Baustein/Token nutzen statt Baseline erhöhen:\n" . implode("\n", $steigt));
});

it('Welle-1-Regeln (1.4d) gelten nicht in den Baustein-Dateien unter components/', function () {
    $ist = UiRegeln::zaehlen();
    $inBausteinen = [];
    foreach ($ist as $pfad => $regeln) {
        if (str_starts_with($pfad, 'components/')) {
            $inBausteinen += array_intersect_key($regeln, UiRegeln::REGELN_AUSSER_BAUSTEINE);
        }
    }

    expect($inBausteinen)->toBe([]);
});

it('1.4d: erkennt Typografie-Literale, $leise, rohe Knöpfe/Checkboxen und alte Paletten-Klassen', function () {
    $zahl = fn (string $regel, string $html) => preg_match_all(UiRegeln::REGELN_AUSSER_BAUSTEINE[$regel], $html);

    expect($zahl('typo_groesse', '<p class="text-[length:var(--fa-text-sm)] md:text-[length:var(--fa-text-lg)]">'))->toBe(2)
        ->and($zahl('typo_tinte', '<p class="text-[var(--fa-ink-3)] text-[var(--fa-ink)] text-[var(--fa-ink-2)]">'))->toBe(3)
        ->and($zahl('typo_tinte', '<p class="text-[var(--fa-crit)] bg-[var(--fa-ink-3)]">'))->toBe(0)          // Status-Farbe, Hintergrund
        ->and($zahl('leise_definition', "@php \$leise = 'text-sm'; @endphp <span class=\"{{ \$leise }}\">"))->toBe(1)
        ->and($zahl('roher_button', '<button type="button" wire:click="x"><x-fa::button>'))->toBe(1)
        ->and($zahl('rohe_checkbox', '<input type="checkbox" wire:model="a"> <input type="text">'))->toBe(1)
        ->and($zahl('rohe_checkbox', "<input\n  wire:model=\"a\"\n  @checked(\$r->aktiv)\n  type='checkbox'>"))->toBe(1)
        ->and($zahl('alte_palette', '<p class="text-gray-500 hover:bg-slate-100 border-red-200/50 ring-blue-500">'))->toBe(4)
        ->and($zahl('alte_palette', '<p class="text-[var(--fa-ink)] bg-white text-gray text-sm-red-500 grid-cols-3">'))->toBe(0);
});

it('style_statisch: reine Grid-Platzierung ist Layout-Geometrie (#520), alles andere im selben style zählt weiter', function () {
    $zahl = fn (string $html) => preg_match_all(UiRegeln::REGELN['style_statisch'], $html);

    expect($zahl('<div style="grid-column: 1; grid-row: 2;"></div>'))->toBe(0)
        ->and($zahl('<div style="grid-area: plot"></div>'))->toBe(0)
        ->and($zahl('<div style="grid-column: 2; grid-row: 1; color: #ff0000;"></div>'))->toBe(1)      // Grid + Farbe: verboten
        ->and($zahl('<svg style="grid-column: 2; grid-row: 1; width: 100%;"></svg>'))->toBe(1)       // Grid + Breite: verboten
        ->and($zahl('<div style="margin-top: 1rem"></div>'))->toBe(1)
        ->and($zahl('<div style="height: {{ $h }}px"></div>'))->toBe(0);                             // Laufzeitwert wie bisher
});
