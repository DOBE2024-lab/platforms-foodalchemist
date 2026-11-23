<?php

namespace Platform\FoodAlchemist\Console\Concerns;

/**
 * Journal- und Report-Dateien von Umstellungsbefehlen (2.6a, Plan 98 B): legt das Zielverzeichnis an und meldet einen
 * Schreibfehler laut statt still. Befund io 23.11.2026: `--json` in einen neuen Ordner endete mit Exit 1, weil
 * file_put_contents kein Verzeichnis anlegt.
 */
trait SchreibtLaufDatei
{
    /** @return bool true, wenn die Datei geschrieben ist; sonst steht der Fehler schon in der Ausgabe */
    protected function schreibeLaufDatei(string $pfad, mixed $daten): bool
    {
        $ordner = dirname($pfad);
        if (! is_dir($ordner) && ! @mkdir($ordner, 0775, true) && ! is_dir($ordner)) {
            $this->error("Verzeichnis nicht anlegbar: {$ordner}");

            return false;
        }
        if (@file_put_contents($pfad, json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            $this->error("Datei nicht schreibbar: {$pfad}");

            return false;
        }

        return true;
    }
}
