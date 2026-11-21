<?php

/**
 * Welle 1, Paket 1.3a — Architektur-Regeln als Sperrklinke (Plan 98, Befunde B3/B9 aus 97).
 *
 * Zwei getrennte Regeln, beide per PHP-Parser (nikic/php-parser), nicht per Textsuche:
 *   livewire_import  src/Services und src/Tools kennen nichts aus Platform\FoodAlchemist\Livewire\
 *                    (use-Zeilen und voll qualifizierte Namen; Doku-Kommentare zählen nicht).
 *   schreibzugriff   src/Livewire und src/Tools schreiben nicht direkt in Models/Query-Builder
 *                    (save/create/update/delete/insert/forceDelete/attach/sync … und DB::table()->…).
 *                    Gezählt wird nur, wenn der EMPFÄNGER als Eloquent erkannt ist — `$service->update()`
 *                    bleibt erlaubt. Lesen (find/where/get …) ist in Tools ausdrücklich erlaubt.
 *
 * Mechanik wie UiRegelnTest:
 *   - Jede Datei darf nicht schlechter werden als ihr eingefrorener Stand
 *     (tests/Fixtures/architektur_regeln_baseline.json). Neue Dateien starten bei null.
 *   - Die Baseline selbst darf gegenüber der eingecheckten Fassung (Abzweig von origin/main)
 *     nur sinken — sonst schlägt „Baseline steigt nicht“ fehl.
 *
 * BASELINE SENKEN (nach einer Verbesserung, z. B. Tool auf Service umgestellt):
 *     FA_ARCH_BASELINE_SCHREIBEN=1 vendor/bin/pest --filter=ArchitekturRegeln
 *   danach die geänderte JSON mit committen. Steigt dabei eine Zahl, fängt das der
 *   Baseline-Test ab — dann nicht die Baseline anheben, sondern den Code in einen Service legen.
 *
 * Grenzen der Erkennung (bewusst, damit es keine Fehlalarme gibt): Empfänger gelten als Eloquent,
 * wenn sie aus einem Model-Klassenaufruf (Model::…, new Model, DB::table) stammen, als Model/Builder/
 * Relation/Collection typisiert sind (Parameter, Eigenschaft, Rückgabetyp in derselben Klasse) oder
 * davon abgeleitet sind (Kette, Zuweisung, foreach, each/map-Closure). Untypisierte Werte aus
 * Service-Aufrufen erkennt die Regel nicht — sie zählt also eher zu wenig als zu viel.
 */

use Platform\FoodAlchemist\Tests\Support\ArchitekturRegeln;
use Platform\FoodAlchemist\Tests\Support\BaselineKlinke;

$code = fn (string $body, string $kopf = '') => "<?php\nnamespace Platform\\FoodAlchemist\\Tools;\n"
    . "use Platform\\FoodAlchemist\\Models\\FoodAlchemistRecipe;\nuse Illuminate\\Support\\Facades\\DB;\n{$kopf}\n"
    . "class X {\n{$body}\n}\n";

it('livewire_import: use-Zeile und voll qualifizierter Name zählen, Doku-Kommentar nicht', function () {
    $quelle = "<?php\nnamespace Platform\\FoodAlchemist\\Tools;\n"
        . "use Platform\\FoodAlchemist\\Livewire\\Settings\\Wissenssteuerung;\n"
        . "use Platform\\FoodAlchemist\\Services\\RecipeService;\n"
        . "/** {@see \\Platform\\FoodAlchemist\\Livewire\\Planung\\Index::WATCHDOG_SEKUNDEN} */\n"
        . "class X { public function a() { return \\Platform\\FoodAlchemist\\Livewire\\Planung\\Index::SEKTOR_OPTIONEN; } }\n";

    expect(ArchitekturRegeln::livewireImporte($quelle))->toHaveCount(2);
});

it('schreibzugriff: Service-Aufrufe mit gleichem Methodennamen zählen nicht', function () use ($code) {
    $quelle = $code(<<<'PHP'
        public function a(\Platform\FoodAlchemist\Services\OutletSettingsService $svc) {
            $row = app(\Platform\FoodAlchemist\Services\OutletSettingsService::class)->update(1, 2, []);
            $svc->delete(3);
            $this->service->save();
            return $row;
        }
    PHP);

    expect(ArchitekturRegeln::schreibzugriffe($quelle))->toBe([]);
});

it('schreibzugriff: Lesen über Models bleibt erlaubt', function () use ($code) {
    $quelle = $code(<<<'PHP'
        public function a(int $id) {
            $r = FoodAlchemistRecipe::query()->where('id', $id)->first();
            $alle = FoodAlchemistRecipe::where('team_id', 1)->get();
            DB::table('x')->where('a', 1)->count();
            return [$r?->name, $alle->count()];
        }
    PHP);

    expect(ArchitekturRegeln::schreibzugriffe($quelle))->toBe([]);
});

it('schreibzugriff: erkennt Model-Static, Ketten, Variablen, Parameter, Relationen und DB', function () use ($code) {
    $quelle = $code(<<<'PHP'
        public ?FoodAlchemistRecipe $rezept = null;

        public function a(FoodAlchemistRecipe $p, int $id) {
            FoodAlchemistRecipe::create(['a' => 1]);                       // 1 Static
            FoodAlchemistRecipe::where('id', $id)->update(['a' => 2]);     // 2 Kette
            $r = FoodAlchemistRecipe::findOrFail($id);
            $r->name = 'x';
            $r->save();                                                    // 3 Variable
            $p->delete();                                                  // 4 typisierter Parameter
            $p->ingredients()->create([]);                                 // 5 Relation
            $this->rezept?->forceDelete();                                 // 6 typisierte Eigenschaft, nullsafe
            DB::table('t')->insert(['a' => 1]);                            // 7 DB-Builder
            DB::statement('DELETE FROM t');                                // 8 DB direkt
            foreach (FoodAlchemistRecipe::where('a', 1)->get() as $e) {
                $e->touch();                                               // 9 foreach-Element
            }
            FoodAlchemistRecipe::where('a', 1)->get()->each(fn ($m) => $m->restore()); // 10 each-Closure
            $neu = new FoodAlchemistRecipe(['a' => 1]);
            $neu->save();                                                  // 11 new Model
            $p->tags()->sync([1, 2]);                                      // 12 Pivot
        }
    PHP);

    expect(ArchitekturRegeln::schreibzugriffe($quelle))->toHaveCount(12);
});

it('schreibzugriff: erkennt Models aus typisierten Service-Rückgaben, der Service-Aufruf selbst zählt nicht', function () use ($code) {
    $quelle = $code(<<<'PHP'
        public function a($team) {
            $svc = app(\Platform\FoodAlchemist\Services\ConceptService::class);
            $c = $svc->create($team, []);                                  // Service: zählt nicht
            $c->update(['occasion' => 'x']);                               // 1 Model aus Service-Rückgabe
            $canvas = app(\Platform\FoodAlchemist\Services\CanvasService::class)->canvasFor($team, 't', 'o', null);
            $canvas->entries()->where('a', 1)->update(['b' => 1]);         // 2 Relation am Rückgabe-Model
        }
    PHP);

    expect(ArchitekturRegeln::schreibzugriffe($quelle))->toHaveCount(2);
});

it('hält Services und Tools frei von neuen Livewire-Importen und Livewire/Tools frei von neuen Schreibzugriffen', function () {
    $ist = ArchitekturRegeln::zaehlen();

    if (getenv('FA_ARCH_BASELINE_SCHREIBEN') === '1') {
        BaselineKlinke::schreiben(ArchitekturRegeln::baselinePfad(), $ist);
        $this->markTestSkipped('Stand neu eingefroren: ' . count($ist) . ' Dateien mit Altlasten.');
    }

    $schlechter = BaselineKlinke::schlechterAls($ist, BaselineKlinke::lesen(ArchitekturRegeln::baselinePfad()));
    $details = [];
    foreach ($schlechter as $pfad => $_) {
        $details[] = $pfad . ":\n    " . implode("\n    ", ArchitekturRegeln::funde($pfad));
    }

    expect($schlechter)->toBe([], "Architektur-Regeln verletzt — Schreiblogik gehört in einen Service, Konstanten nicht in Livewire:\n"
        . implode("\n", $details));
});

it('Architektur-Baseline steigt nicht gegenüber der eingecheckten Fassung', function () {
    $steigt = BaselineKlinke::steigtGegenueberGit(ArchitekturRegeln::baselinePfad());
    if ($steigt === null) {
        $this->markTestSkipped('Keine eingecheckte Vergleichsfassung (kein git oder Datei neu).');
    }

    expect($steigt)->toBe([], "Baseline wurde angehoben — bitte Code verbessern statt Baseline erhöhen:\n" . implode("\n", $steigt));
});

/**
 * Code-Qualität 2.2a: Geld-Helfer zentral. Keine neue Handrechnung für Wareneinsatz % (→ MargeService::wareneinsatzPct),
 * EK je Portion (→ DarreichungResolver::ekProPortion) und Preis je kg → je g (→ Support\Masse::preisJeG). Zielwert 0;
 * die Ausnahmen unten rechnen bewusst etwas anderes und sind mit Grund und exakter Anzahl festgehalten.
 */
function geldHandrechnungen(): array
{
    $wurzel = dirname(__DIR__, 2);
    $ekImZaehler = '/(?:^|[^A-Za-z])ek|[a-z]Ek|EK|Mek|kosten|hk1/';
    $regeln = [
        'wareneinsatz' => function (string $zeile) use ($ekImZaehler): bool {
            if (! preg_match_all('/(?<![-+]\s)(?<![-+])((?:\(float\)\s*)?\$\w+(?:\[[^\]]+\]|->\w+)*)\)?\s*\/\s*\(?(?:\(float\)\s*)?\$\w+(?:\[[^\]]+\]|->\w+)*\)?\s*\)?\s*\*\s*100(?!\d)/', $zeile, $m)) {
                return false;
            }

            return collect($m[1])->contains(fn ($zaehler) => preg_match($ekImZaehler, $zaehler) === 1);
        },
        'ek_je_portion' => fn (string $zeile): bool => preg_match('/ek_total_eur[^;]*?\/\s*(?:max\(\s*1|\$anzahl)/', $zeile) === 1,
        'kg_in_g' => fn (string $zeile): bool => preg_match('/per_kg\w*[\'"]?\]?\)?\s*\/\s*1000/', $zeile) === 1,
    ];

    $treffer = [];
    foreach (['src', 'resources/views'] as $ordner) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel . '/' . $ordner, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $datei) {
            if (! str_ends_with($datei->getFilename(), '.php')) {
                continue;
            }
            $rel = substr($datei->getPathname(), strlen($wurzel) + 1);
            foreach (file($datei->getPathname()) as $zeile) {
                foreach ($regeln as $regel => $pruefe) {
                    if ($pruefe($zeile)) {
                        $treffer[$regel][$rel] = ($treffer[$regel][$rel] ?? 0) + 1;
                    }
                }
            }
        }
    }

    return $treffer;
}

it('Geld-Helfer: keine neuen Handrechnungen für Wareneinsatz, EK je Portion und Preis je kg → g (CQ 2.2a)', function () {
    // Datei => [Anzahl, Grund]. Nur sinken lassen — eine neue Stelle gehört in den Helfer, nicht hierher.
    $ausnahmen = [
        'wareneinsatz' => [
            'src/Services/MargeService.php' => [1, 'der Helfer selbst'],
            'src/Services/BenchmarkService.php' => [1, 'EK-Abdeckung (Anteil Gerichte mit EK), keine Quote'],
            'resources/views/presentation/blocks/chapter_loop.blade.php' => [1, 'VK per Wahrheitswert geprüft (negativer VK zeigte eine Quote) — nicht still vereinheitlicht'],
        ],
        'ek_je_portion' => [
            'src/Services/DarreichungResolver.php' => [1, 'der Helfer selbst'],
            'src/Services/BenchmarkService.php' => [1, 'SQL-Zeile ohne Model, ek_portion kommt schon aus der Abfrage'],
            'src/Services/MoneyTruthReportService.php' => [1, 'Diagnose vergleicht bewusst Ansatz ÷ Portionen mit der Darreichung'],
            'src/Services/SpeiseplanService.php' => [1, 'Darreichung des Eintrags statt Standard, Rückfall 0 statt null'],
            'src/Services/Concept/CockpitZeilen.php' => [1, 'Paket-EK mit PAE-Faktor, Stück-Modus, Rückfall 0'],
            'src/Services/ConceptPreisService.php' => [1, 'Paket-EK mit PAE-Faktor, Stück-Modus, Rückfall 0'],
            'src/Services/ConcepterAggregateService.php' => [1, 'Paket-EK mit PAE-Faktor, Rückfall 0'],
            'src/Services/PaketPreisService.php' => [1, 'Paket-EK mit PAE-Faktor, Rückfall 0'],
            'src/Services/PaketService.php' => [1, 'Paket-EK mit PAE-Faktor, Stück-Modus, Rückfall 0'],
        ],
        'kg_in_g' => [],
    ];

    $ist = geldHandrechnungen();
    $abweichung = [];
    foreach ($ausnahmen as $regel => $erlaubt) {
        foreach (($ist[$regel] ?? []) + array_map(fn () => 0, $erlaubt) as $datei => $anzahl) {
            $soll = $erlaubt[$datei][0] ?? 0;
            if ($anzahl !== $soll) {
                $abweichung[] = "{$regel}: {$datei} hat {$anzahl}, erwartet {$soll}" . ($anzahl < $soll ? ' (Ausnahme abbauen)' : ' (Helfer nutzen)');
            }
        }
    }

    expect($abweichung)->toBe([], implode("\n", $abweichung));
});
