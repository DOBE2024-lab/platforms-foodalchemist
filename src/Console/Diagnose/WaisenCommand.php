<?php

namespace Platform\FoodAlchemist\Console\Diagnose;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Plattform\WaisenReportService;

/**
 * Plan 98 · 3.5 Schritt 2 · Waisen-Report der Kernkette. **Read-only**, schreibt nichts.
 *
 * Exit-Code 1, sobald eine Spalte ins Leere zeigt (Abnahme 3.5: „Waisen-Report 0“). Verweise auf soft-gelöschte Eltern sind
 * nur Information (App-Logik zeigt sie als entfernt), sie lassen den Befehl nicht scheitern.
 */
class WaisenCommand extends Command
{
    protected $signature = 'foodalchemist:diagnose:waisen
        {--alle : auch Spalten ohne Befund zeigen}
        {--json : Maschinen-lesbare Ausgabe}';

    protected $description = 'Waisen-Report der Kernkette (Verweise ins Leere / auf soft-gelöschte Eltern) — read-only.';

    public function handle(WaisenReportService $report): int
    {
        $zeilen = $report->bericht();
        $waisen = array_sum(array_column($zeilen, 'ins_leere'));
        $geloescht = array_sum(array_column($zeilen, 'auf_geloescht'));
        $zeige = $this->option('alle') ? $zeilen : array_values(array_filter($zeilen, fn ($z) => $z['ins_leere'] + $z['auf_geloescht'] > 0));

        if ($this->option('json')) {
            $this->line((string) json_encode(['spalten' => count($zeilen), 'ins_leere' => $waisen, 'auf_geloescht' => $geloescht, 'zeilen' => $zeige],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info(count($zeilen).' Verweis-Spalten geprüft: '.$waisen.' Verweise ins Leere, '.$geloescht.' auf soft-gelöschte Eltern.');
            if ($zeige !== []) {
                $this->table(['Tabelle', 'Spalte', 'Ziel', 'gesetzt', 'ins Leere', 'auf gelöscht'], array_map('array_values', $zeige));
            }
        }

        return $waisen > 0 ? self::FAILURE : self::SUCCESS;
    }
}
