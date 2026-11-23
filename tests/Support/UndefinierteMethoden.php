<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * 98 · 3.2 Wächter: `$this->methode(…)` ohne passende Methode (gefunden: FassungBasis v2 verlor pivotIds/pivotsUebernehmen,
 * Konzept/Format riefen sie weiter auf — php -l und fehlende-importe.php merken das nicht, es knallt erst zur Laufzeit).
 *
 * Je Datei unter src/: Klasse über den Autoloader laden, alle `$this->name(` aus den Tokens (Kommentare und Strings zählen
 * nicht) gegen ReflectionClass::hasMethod prüfen — das deckt Vererbung, Traits und abstrakte Methoden ab. Übersprungen:
 * Traits und Interfaces (der Nutzer entscheidet), Klassen mit `__call` (Modelle, Livewire-Magie), nicht ladbare Dateien.
 * Abstrakte Klassen (Template-Basis, z. B. FoodAlchemistTool::getSchema): ein Aufruf zählt, wenn JEDE konkrete Unterklasse unter
 * src/ die Methode hat; ohne konkrete Unterklasse ist er ein Befund.
 * Kein Ersatz für PHPStan; prüft nur genau dieses Fehlerbild.
 */
final class UndefinierteMethoden
{
    /**
     * @param  list<string>  $dateien  leer ⇒ alle PHP-Dateien unter `$src`
     * @return list<string> Befunde „pfad:zeile Klasse::methode()“
     */
    public static function finden(string $src, array $dateien = []): array
    {
        if ($dateien === []) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $d) {
                if ($d->getExtension() === 'php') {
                    $dateien[] = $d->getPathname();
                }
            }
            sort($dateien);
        }
        $befunde = [];
        $abstrakt = [];   // [ReflectionClass, name, befund]
        foreach ($dateien as $datei) {
            $code = (string) file_get_contents($datei);
            $klasse = self::klasse($code);
            if ($klasse === null) {
                continue;
            }
            try {
                if (! class_exists($klasse) && ! enum_exists($klasse)) {
                    continue;
                }
                $rc = new \ReflectionClass($klasse);
            } catch (\Throwable) {
                continue;
            }
            if ($rc->hasMethod('__call')) {
                continue;
            }
            foreach (self::aufrufe($code) as [$name, $zeile]) {
                if (! $rc->hasMethod($name)) {
                    $befund = str_replace($src.'/', '', $datei).":{$zeile} {$rc->getShortName()}::{$name}()";
                    $rc->isAbstract() ? $abstrakt[] = [$rc, $name, $befund] : $befunde[] = $befund;
                }
            }
        }

        foreach ($abstrakt as [$rc, $name, $befund]) {
            $kinder = array_filter(get_declared_classes(), fn ($k) => is_subclass_of($k, $rc->getName()) && ! (new \ReflectionClass($k))->isAbstract());
            if ($kinder === [] || array_filter($kinder, fn ($k) => ! method_exists($k, $name)) !== []) {
                $befunde[] = $befund;
            }
        }

        return $befunde;
    }

    /** Voller Name der ersten Klasse/Enum der Datei, null bei Trait/Interface/ohne Klasse. */
    private static function klasse(string $code): ?string
    {
        $ns = preg_match('/^namespace ([^;]+);/m', $code, $m) ? $m[1].'\\' : '';
        $tokens = token_get_all($code);
        foreach ($tokens as $i => $t) {
            if (! is_array($t)) {
                continue;
            }
            if (in_array($t[0], [T_TRAIT, T_INTERFACE], true)) {
                return null;
            }
            if (in_array($t[0], [T_CLASS, T_ENUM], true)) {
                $vor = self::nachbar($tokens, $i, -1);
                if ($vor === T_DOUBLE_COLON || $vor === T_NEW) {
                    continue;   // Foo::class, new class
                }
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return $ns.$tokens[$j][1];
                    }
                }
            }
        }

        return null;
    }

    /** @return list<array{0: string, 1: int}> `$this->name(` als [name, zeile] */
    private static function aufrufe(string $code): array
    {
        $tokens = array_values(array_filter(token_get_all($code), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $treffer = [];
        foreach ($tokens as $i => $t) {
            if (! is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$this') {
                continue;
            }
            $op = $tokens[$i + 1] ?? null;
            $name = $tokens[$i + 2] ?? null;
            $klammer = $tokens[$i + 3] ?? null;
            if (is_array($op) && in_array($op[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && is_array($name) && $name[0] === T_STRING && $klammer === '(') {
                $treffer[] = [$name[1], $name[2]];
            }
        }

        return $treffer;
    }

    private static function nachbar(array $tokens, int $i, int $richtung): int|string|null
    {
        for ($j = $i + $richtung; isset($tokens[$j]); $j += $richtung) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
        }

        return null;
    }
}
