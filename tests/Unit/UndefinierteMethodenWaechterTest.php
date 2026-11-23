<?php

use Platform\FoodAlchemist\Tests\Support\UndefinierteMethoden;

/**
 * 98 · 3.2 Wächter: kein `$this->methode(…)` ohne passende Methode in src/ (tests/Support/UndefinierteMethoden.php).
 *
 * Anlass: Beim Umbau auf FassungBasis v2 fielen pivotIds()/pivotsUebernehmen() aus der Basis, Konzept- und Format-Fassung
 * riefen sie weiter auf → 500 beim Veröffentlichen. php -l und fehlende-importe.php sehen das nicht. Der Gegenfall zeigt, dass
 * der Wächter genau dieses Bild findet und bei Vererbung, Trait, Template-Basis, __call, Kommentar und String still bleibt.
 */
/** @param array<string, string> $dateien Dateiname → Code (eine Klasse je Datei, wie unter src/); lädt sie in dieser Reihenfolge */
function undefinierteMethodenProbe(array $dateien): string
{
    $verzeichnis = sys_get_temp_dir().'/fa_methoden_'.bin2hex(random_bytes(6));
    mkdir($verzeichnis);
    foreach ($dateien as $name => $code) {
        file_put_contents($verzeichnis.'/'.$name, $code);
        require $verzeichnis.'/'.$name;
    }

    return $verzeichnis;
}

it('src/ ruft keine undefinierten Methoden auf $this auf', function () {
    $befunde = UndefinierteMethoden::finden(dirname(__DIR__, 2).'/src');

    expect($befunde)->toBe([]);
});

it('findet das Fehlerbild aus 3.2 und bleibt bei den bekannten Mustern still', function () {
    $ns = 'FaMethodenProbe'.bin2hex(random_bytes(4));
    $kopf = "<?php\nnamespace {$ns};\n";
    $dateien = [
        'Helfer.php' => $kopf.'trait Helfer { protected function ausTrait(): int { return 1; } }',
        'Basis.php' => $kopf.<<<'PHP'
            abstract class Basis
            {
                use Helfer;

                protected function geerbt(): int { return 1; }

                public function vorlage(): int { return $this->vonKindern() + $this->ausTrait(); }   // Template: jedes Kind hat es
            }
            PHP,
        'Kind.php' => $kopf.<<<'PHP'
            final class Kind extends Basis
            {
                protected function vonKindern(): int { return 2; }

                public function hash(): string
                {
                    // $this->imKommentar() zählt nicht
                    $text = '$this->imString()';

                    return json_encode([$this->geerbt(), $this->pivotIds(), $this?->auchNullsafe()]);
                }
            }
            PHP,
        'Magisch.php' => $kopf.<<<'PHP'
            final class Magisch
            {
                public function __call($n, $a) { return null; }

                public function f(): void { $this->beliebig(); }
            }
            PHP,
    ];
    $verzeichnis = undefinierteMethodenProbe($dateien);

    try {
        $befunde = UndefinierteMethoden::finden($verzeichnis);
    } finally {
        foreach (array_keys($dateien) as $name) {
            @unlink($verzeichnis.'/'.$name);
        }
        @rmdir($verzeichnis);
    }

    expect($befunde)->toHaveCount(2)
        ->and(implode("\n", $befunde))->toContain('Kind::pivotIds()')->toContain('Kind::auchNullsafe()')
        ->not->toContain('vonKindern')->not->toContain('ausTrait')->not->toContain('geerbt')
        ->not->toContain('imKommentar')->not->toContain('imString')->not->toContain('beliebig');
});
