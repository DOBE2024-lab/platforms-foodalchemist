<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * Gemeinsame Mechanik der Sperrklinken (UiRegeln, ArchitekturRegeln): Baseline lesen/schreiben,
 * Ist gegen Baseline vergleichen und prüfen, dass die Baseline selbst nicht steigt.
 *
 * Format jeder Baseline: { "pfad": { "regel": anzahl, … }, … } — fehlender Eintrag = 0.
 */
final class BaselineKlinke
{
    public static function lesen(string $pfad): array
    {
        return json_decode((string) @file_get_contents($pfad), true) ?? [];
    }

    public static function schreiben(string $pfad, array $ist): void
    {
        ksort($ist);
        file_put_contents($pfad, json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** @return array<string, list<string>> pfad => ["regel ist (erlaubt soll)"] */
    public static function schlechterAls(array $ist, array $soll): array
    {
        $schlechter = [];
        foreach ($ist as $pfad => $regeln) {
            foreach ($regeln as $regel => $anzahl) {
                $erlaubt = $soll[$pfad][$regel] ?? 0;
                if ($anzahl > $erlaubt) {
                    $schlechter[$pfad][] = "{$regel} {$anzahl} (erlaubt {$erlaubt})";
                }
            }
        }

        return $schlechter;
    }

    /**
     * Vergleicht die Baseline-Datei im Arbeitsstand mit der eingecheckten Fassung am Abzweig
     * von origin/main (Fallback: HEAD). Ersatz für eine CI-Prüfung, die es nicht gibt.
     *
     * Regeln, die in der Vergleichsfassung noch gar nicht vorkommen, gelten als neu eingeführt
     * und dürfen ihre Ausgangszählung mitbringen.
     *
     * $umzuege (neue Datei => Quelldatei): Code, der unverändert aus der Quelle in eine neue Datei gezogen wurde
     * (98 · 3.3, Zerlegen großer Klassen/Views). Für den Vergleich zählen seine Treffer bei der Quelle — die Summe
     * darf nicht steigen, nur der Ort wechselt. Gilt nur für Dateien, die es in der Vergleichsfassung noch nicht gibt.
     *
     * @param  array<string, string>  $umzuege
     * @return list<string>|null null = nicht prüfbar (kein git, Datei in der Vergleichsfassung nicht vorhanden)
     */
    public static function steigtGegenueberGit(string $pfad, array $umzuege = []): ?array
    {
        $pfad = realpath($pfad) ?: $pfad;
        $wurzel = self::git(dirname($pfad), 'rev-parse --show-toplevel');
        if ($wurzel === null || ! is_file($pfad)) {
            return null;
        }
        $relativ = ltrim(str_replace(realpath($wurzel) ?: $wurzel, '', $pfad), '/');
        $ref = self::git($wurzel, 'merge-base HEAD origin/main') ?? 'HEAD';
        $alt = self::git($wurzel, 'show ' . escapeshellarg($ref . ':' . $relativ));
        if ($alt === null) {
            return null;
        }

        return self::gestiegen(self::lesen($pfad), json_decode($alt, true) ?? [], $umzuege);
    }

    /** @return list<string> */
    public static function gestiegen(array $neu, array $alt, array $umzuege = []): array
    {
        foreach ($umzuege as $ziel => $quelle) {
            if (isset($alt[$ziel]) || ! isset($neu[$ziel])) {
                continue;   // Ziel gab es schon (dann normaler Vergleich) oder hat keine Treffer
            }
            foreach ($neu[$ziel] as $regel => $anzahl) {
                $neu[$quelle][$regel] = ($neu[$quelle][$regel] ?? 0) + $anzahl;
            }
            unset($neu[$ziel]);
        }

        $bekannteRegeln = [];
        foreach ($alt as $regeln) {
            $bekannteRegeln += array_fill_keys(array_keys($regeln), true);
        }

        $gestiegen = [];
        foreach ($neu as $datei => $regeln) {
            foreach ($regeln as $regel => $anzahl) {
                if (! isset($bekannteRegeln[$regel])) {
                    continue; // Regel neu eingeführt
                }
                $vorher = $alt[$datei][$regel] ?? 0;
                if ($anzahl > $vorher) {
                    $gestiegen[] = "{$datei}: {$regel} {$vorher} → {$anzahl}";
                }
            }
        }

        return $gestiegen;
    }

    private static function git(string $verzeichnis, string $befehl): ?string
    {
        $ausgabe = [];
        $code = 1;
        @exec('git -C ' . escapeshellarg($verzeichnis) . ' ' . $befehl . ' 2>/dev/null', $ausgabe, $code);

        return $code === 0 ? trim(implode("\n", $ausgabe)) : null;
    }
}
