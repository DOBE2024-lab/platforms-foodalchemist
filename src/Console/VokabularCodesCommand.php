<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Support\Planung\VokabularCodes;

/**
 * Code-Qualität 2.5b · Trockenlauf-Report für die Codes der Planungs-Vokabulare (Saison, Einsatzmoment, Eventtyp, Service-Art):
 * welche Zeile welchen Code bekäme und wo zwei Einträge eines Teams auf denselben Code fielen. Die Migration
 * `2027_01_08_100000_vokabular_codes_service_arten` schreibt dasselbe; `--apply` füllt fehlende Codes später nach (idempotent).
 */
class VokabularCodesCommand extends Command
{
    protected $signature = 'foodalchemist:vokabular-codes
        {--apply : wirklich schreiben}
        {--grundbestand : statt Codes: Wurzel-Teams ohne sichtbare Saisons/Einsatzmomente/Eventtypen zeigen bzw. (mit --apply) den Grundbestand anlegen}';

    protected $description = 'Codes der Planungs-Vokabulare: Trockenlauf-Report (Standard) oder fehlende Codes setzen';

    public function handle(): int
    {
        if ($this->option('grundbestand')) {
            $offen = \Platform\FoodAlchemist\Support\Planung\VokabularSeed::offeneTeams();
            foreach ($offen as $team) {
                $this->line("  Team {$team->id} «{$team->name}»" . ($this->option('apply') ? ': ' . VokabularCodes::grundbestandAnlegen($team) . ' Einträge angelegt' : ''));
            }
            $this->info(count($offen) . ' Wurzel-Team(s) ohne Grundbestand' . ($this->option('apply') ? ', nachgezogen.' : '. Trockenlauf, nichts geschrieben.'));

            return self::SUCCESS;
        }
        $probleme = 0;
        foreach (VokabularCodes::bericht() as $tabelle => $teil) {
            $this->line("<info>{$tabelle}</info>: " . count($teil['setzen']) . ' Zeile(n) ohne Code');
            foreach (collect($teil['setzen'])->groupBy(fn ($z) => $z['team_id'] ?? 'global') as $teamId => $zeilen) {
                $this->line("  Team {$teamId}: " . $zeilen->map(fn ($z) => "{$z['name']} → {$z['code']}")->implode(' · '));
            }
            foreach ($teil['kollisionen'] as $k) {
                $this->error("  Kollision {$k}");
                $probleme++;
            }
            foreach ($teil['fehler'] as $f) {
                $this->error("  Fehler {$f}");
                $probleme++;
            }
        }
        if (! $this->option('apply')) {
            $this->info('Trockenlauf, nichts geschrieben.' . ($probleme > 0 ? " {$probleme} Problem(e) vor --apply klären." : ''));

            return $probleme > 0 ? self::FAILURE : self::SUCCESS;
        }
        $this->info(VokabularCodes::fuellen() . ' Code(s) gesetzt.');

        return self::SUCCESS;
    }
}
