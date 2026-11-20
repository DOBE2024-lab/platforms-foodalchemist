<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;

/**
 * Paket „Grundeinstellungen“ automatisch nachziehen (Dominique 20.11.2026: „wir erweitern ja ständig und die haben ja dieses Paket gebucht“).
 * Holt bei jedem Mandanten mit gebuchtem Paket nur die Master-Zeilen nach, die neu dazugekommen sind — nie überschreiben, bewusst Gelöschtes
 * bleibt gelöscht ({@see PaketGrundeinstellungenService::nachziehen}). Läuft stündlich (Schalter `foodalchemist.scheduler.grundeinstellungen_aktiv`).
 */
class GrundeinstellungenNachziehenCommand extends Command
{
    protected $signature = 'foodalchemist:grundeinstellungen-nachziehen {--team= : nur dieser Mandant (Haupt-Team-ID)}';

    protected $description = 'Paket „Grundeinstellungen“: neue Master-Zeilen bei allen Mandanten mit gebuchtem Paket nachziehen (idempotent)';

    public static function planen(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        $schedule->command(self::class)
            ->hourlyAt(40)
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Paket „Grundeinstellungen“ bei gebuchten Mandanten nachziehen');
    }

    public function handle(PaketGrundeinstellungenService $paket): int
    {
        if ($this->option('team') !== null) {
            $team = Team::find((int) $this->option('team'));
            if ($team === null) {
                $this->error('Team nicht gefunden.');

                return self::FAILURE;
            }
            $r = $paket->nachziehen($team);
            $this->line("Team {$r['team_id']}: {$r['neu']} neu");

            return self::SUCCESS;
        }
        $fehler = 0;
        foreach ($paket->nachziehenAlle() as $r) {
            if (isset($r['fehler'])) {
                $fehler++;
                $this->error("Team {$r['team_id']}: {$r['fehler']}");
            } elseif ($r['neu'] > 0) {
                $this->line("Team {$r['team_id']}: {$r['neu']} neu");
            }
        }

        return $fehler === 0 ? self::SUCCESS : self::FAILURE;
    }
}
