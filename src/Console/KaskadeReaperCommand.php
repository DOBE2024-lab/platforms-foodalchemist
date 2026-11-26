<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Kaskade\KaskadenReaperSweep;

/**
 * Hängende Kaskaden-Läufe heilen, ohne dass jemand das Board offen haben muss (io 26.11.: Läufe 38, 61, 62, 66, 69 blieben trotz
 * #993 auf `running`, weil der Poll nur den jüngsten Lauf je Session sieht). Details: {@see KaskadenReaperSweep}.
 *
 * `--dry-run` schreibt nichts und zeigt je Kandidat, warum er hängt (Schritt-Status, Anreicherung, Phasen, offene KI-Aufträge).
 * Ohne `--dry-run` heilt der Reaper wie aus dem Board: verwaiste Schritte → failed mit Grund, Anreicherung → failed, Phase geräumt,
 * Lauf neu bewertet. Zeitplan alle 15 min, Standard AUS (Schalter `foodalchemist.scheduler.kaskade_reaper_aktiv`, Einschalten nach Dry-Run und Go).
 */
class KaskadeReaperCommand extends Command
{
    protected $signature = 'foodalchemist:kaskade-reaper
        {--team= : nur Läufe dieses Teams (Besitzer-Team-ID)}
        {--dry-run : nur anzeigen, was hängt und warum (schreibt nichts)}
        {--zeitplan : Aufruf aus dem Scheduler (nur für die Quelle in der Log-Zeile; setzt nur planen())}';

    protected $description = 'Hängende Kaskaden-Läufe heilen (Reaper über alle Teams, unabhängig vom Board)';

    public static function planen(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        $schedule->command(self::class, ['--zeitplan'])
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: hängende Kaskaden-Läufe heilen (Reaper)');
    }

    public function handle(KaskadenReaperSweep $sweep): int
    {
        $team = null;
        if ($this->option('team') !== null) {
            $team = Team::find((int) $this->option('team'));
            if ($team === null) {
                $this->error('Team nicht gefunden.');

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $zeilen = $sweep->diagnose($team);
            if ($zeilen === []) {
                $this->line('Keine hängenden Läufe.');

                return self::SUCCESS;
            }
            $this->table(['Lauf', 'Team', 'Session', 'Status', 'still (min)', 'Schritte', 'Anreicherung', 'Phasen', 'KI offen', 'Hält fest'],
                array_map(fn ($z) => [$z['run_id'], $z['team_id'], $z['session_id'] ?? '–', $z['status'], $z['still_min'], $z['schritte'],
                    $z['anreicherung'] !== '' ? $z['anreicherung'] : '–', $z['phasen'], $z['ki_offen'], $z['grund'] !== '' ? $z['grund'] : '–'], $zeilen));
            $this->line(count($zeilen) . ' Kandidaten (Trockenlauf, nichts geschrieben).');

            return self::SUCCESS;
        }

        // Quelle in der Summenzeile (io 26.11.: der Handstart meldete „Zeitplan“): Handstart = „Befehl“, nur der Scheduler setzt --zeitplan.
        $r = $sweep->heile($team, $this->option('zeitplan') ? 'Zeitplan' : 'Befehl');
        $this->line("{$r['geprueft']} Läufe geprüft, {$r['geheilt']} Heilungen"
            . ($r['weiter_running'] !== [] ? '; weiter running: ' . implode(', ', $r['weiter_running']) : '')
            . ($r['fehler'] !== [] ? '; Fehler bei: ' . implode(', ', $r['fehler']) : ''));

        return $r['fehler'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
