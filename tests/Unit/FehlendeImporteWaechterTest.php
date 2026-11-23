<?php

/**
 * 98 · 3.3 Wächter: kein kurzer Klassenname ohne Import in src/ (scripts/fehlende-importe.php).
 *
 * Beim Zerlegen großer Klassen in Teile wandert Code in einen neuen Namespace. Ein vergessener `use` fällt php -l nicht auf
 * und knallt erst zur Laufzeit (gefunden in SpeiseplanAusgabe::buffetKarten: Typ-Hinweis im Closure). Der Wächter lässt das
 * Werkzeug über ganz src/ laufen; der Gegenfall zeigt, dass er bei den bekannten Fehlerbildern anschlägt und bei den
 * bekannten Fehlalarm-Mustern still bleibt.
 */
function fehlendeImportePruefen(array $dateien = []): array
{
    $skript = dirname(__DIR__, 2).'/scripts/fehlende-importe.php';
    $befehl = escapeshellarg(PHP_BINARY).' '.escapeshellarg($skript);
    foreach ($dateien as $d) {
        $befehl .= ' '.escapeshellarg($d);
    }
    exec($befehl.' 2>&1', $ausgabe, $code);

    return [$code, implode("\n", $ausgabe)];
}

function fehlendeImporteProbe(string $code): string
{
    $datei = sys_get_temp_dir().'/fa_import_'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($datei, $code);

    return $datei;
}

it('src/ hat keine kurzen Klassennamen ohne Import', function () {
    [$code, $ausgabe] = fehlendeImportePruefen();

    expect($code)->toBe(0, "Fehlende Imports (php scripts/fehlende-importe.php):\n".$ausgabe);
});

it('schlägt bei den bekannten Fehlerbildern tatsächlich an', function () {
    // Verschobener Code im Teil-Namespace: Typ-Hinweis im Closure, statischer Aufruf, new und Rückgabetyp ohne use.
    $probe = fehlendeImporteProbe(<<<'PHP'
        <?php
        namespace Platform\FoodAlchemist\Services\Speiseplan;

        final class ProbeTeil
        {
            public function karten(): RezeptKarte
            {
                $karte = function (FoodAlchemistRecipe $r): array { return []; };
                $agg = app(ConcepterAggregateService::class);
                $x = new WordingResolver();
            }
        }
        PHP);

    try {
        [$code, $ausgabe] = fehlendeImportePruefen([$probe]);
    } finally {
        @unlink($probe);
    }

    expect($code)->toBe(1)
        ->and($ausgabe)->toContain('FoodAlchemistRecipe')
        ->toContain('ConcepterAggregateService')
        ->toContain('WordingResolver')
        ->toContain('RezeptKarte');
});

it('bleibt still bei Import, Kommentar, String, relativem Namen und Konstante', function () {
    $probe = fehlendeImporteProbe(<<<'PHP'
        <?php
        namespace Platform\FoodAlchemist\Services;

        use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

        // FoodAlchemistAngebot::find() im Kommentar zählt nicht
        final class ProbeOk
        {
            private Rezept\RecomputeVorrat $vorrat;   // relativer Name, Unterordner existiert

            public function f(FoodAlchemistRecipe $r, \DateTimeInterface $d): int
            {
                $text = 'Spalte $x und Basis::wert() im String';
                $n = $r !== null ? PHP_INT_MAX : 0;
                $s = TeamSettingsService::class;   // gleicher Namespace, Datei existiert (bleibt dauerhaft im Services-Root, 98 · 3.8)

                return $n;
            }
        }
        PHP);

    try {
        [$code, $ausgabe] = fehlendeImportePruefen([$probe]);
    } finally {
        @unlink($probe);
    }

    expect($code)->toBe(0, $ausgabe);
});
