<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Preis\CatalogPricingService;

/**
 * Einmalig (18.11.2026): Der Preis-Recompute schrieb bis #843 den AUFGELÖSTEN MwSt-Schlüssel in `vat_profile_key` der Darreichung zurück —
 * die Wahl „aus Preisklasse“ (leer) ging so beim ersten Recompute verloren. Dieser Befehl leert die Schlüssel, die genau dem entsprechen,
 * was „aus Preisklasse“ ergibt (preisneutral: gleicher Satz, gleicher Brutto). Abweichende Schlüssel sind eine ausdrückliche Wahl und bleiben.
 * Trockenlauf = Default; --apply braucht --backup-nachweis; --zurueck setzt die Schlüssel aus dem Protokoll wieder.
 */
class DarreichungMwstLeerenCommand extends Command
{
    protected $signature = 'foodalchemist:darreichung-mwst-leeren
        {--apply : wirklich schreiben}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--bericht= : Ordner für das Protokoll (Standard: storage/app/fa-darreichung-mwst)}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'MwSt an Darreichungen auf „aus Preisklasse“ zurücksetzen, wo der Recompute den Schlüssel nur festgeschrieben hatte';

    public function handle(CatalogPricingService $pricing): int
    {
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-darreichung-mwst'));
        File::ensureDirectoryExists($bericht);

        if (($z = $this->option('zurueck')) !== null) {
            $p = json_decode((string) file_get_contents((string) $z), true);
            foreach (($p['vorher'] ?? []) as $id => $alt) {
                DB::table('foodalchemist_recipe_presentations')->where('id', (int) $id)->update(['vat_profile_key' => $alt]);
            }
            $this->info('Zurückgenommen: ' . count($p['vorher'] ?? []) . ' Darreichungen.');

            return self::SUCCESS;
        }

        $plan = [];
        $bleibt = 0;
        $teams = [];
        FoodAlchemistRecipeDarreichung::query()->whereNotNull('vat_profile_key')->orderBy('id')
            ->chunkById(500, function ($zeilen) use ($pricing, &$plan, &$bleibt, &$teams) {
                foreach ($zeilen as $d) {
                    $team = $teams[$d->team_id] ??= Team::find($d->team_id);
                    if ($team === null) {
                        continue;
                    }
                    if ($pricing->vatKeyAusPreisklasse($team, $d) === $d->vat_profile_key) {
                        $plan[(int) $d->id] = $d->vat_profile_key;
                    } else {
                        $bleibt++;
                    }
                }
            });

        $this->line('Leeren (= aus Preisklasse, preisneutral): ' . count($plan));
        $this->line('Bleiben (ausdrückliche Wahl, weicht ab): ' . $bleibt);
        if (! $this->option('apply')) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }
        if (! $this->backupOk()) {
            return self::FAILURE;
        }
        foreach (array_chunk(array_keys($plan), 1000) as $teil) {
            DB::table('foodalchemist_recipe_presentations')->whereIn('id', $teil)->update(['vat_profile_key' => null]);
        }
        $protokoll = $bericht . '/' . now()->format('Ymd_His') . '_darreichung_mwst_protokoll.json';
        file_put_contents($protokoll, json_encode(['vorher' => $plan, 'zeit' => now()->toIso8601String()]));
        $this->info('Geleert: ' . count($plan) . " Darreichungen. Protokoll: {$protokoll}");

        return self::SUCCESS;
    }

    private function backupOk(): bool
    {
        $b = (string) $this->option('backup-nachweis');
        if ($b === '' || ! is_file($b) || filesize($b) < 1024) {
            $this->error('--apply braucht --backup-nachweis=<Dump-Datei> (existiert, nicht leer).');

            return false;
        }
        if (filemtime($b) < now()->subDay()->getTimestamp()) {
            $this->error('Backup ist älter als 24 h — bitte frisch ziehen.');

            return false;
        }

        return true;
    }
}
