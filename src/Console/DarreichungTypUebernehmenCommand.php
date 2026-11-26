<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;

/**
 * Einmalig (Entscheid Dominique 18.11.2026, Servier-Typ an EINER Stelle): Typ und Gefäß stehen an der Darreichung, das Feld am Gericht ist nur noch
 * abgeleitet. Bestand: hat das Gericht ein Servier-Vehikel und die Standard-Darreichung keinen wirksamen Typ (weder eigenen noch aus dem Gefäß),
 * geht das Vehikel des Gerichts als Typ an die Standard-Darreichung. Weicht der Typ des Gerichts vom wirksamen Typ der Darreichung ab, gewinnt
 * die Darreichung (sie ist die Stelle, die Planungsblatt und Handbuch schon lesen) — das Gericht wird angeglichen.
 * Trockenlauf = Default; --apply braucht --backup-nachweis; --zurueck stellt beide Seiten aus dem Protokoll wieder her.
 */
class DarreichungTypUebernehmenCommand extends Command
{
    protected $signature = 'foodalchemist:darreichung-typ-uebernehmen
        {--apply : wirklich schreiben}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--bericht= : Ordner für das Protokoll (Standard: storage/app/fa-darreichung-typ)}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'Servier-Vehikel des Gerichts an die Standard-Darreichung übernehmen (Typ und Gefäß an einer Stelle)';

    public function handle(DarreichungService $svc): int
    {
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-darreichung-typ'));
        File::ensureDirectoryExists($bericht);

        if (($z = $this->option('zurueck')) !== null) {
            $p = json_decode((string) file_get_contents((string) $z), true);
            foreach (($p['darreichung'] ?? []) as $id => $alt) {
                DB::table('foodalchemist_recipe_presentations')->where('id', (int) $id)->update(['serving_vehicle_vocab_id' => $alt]);
            }
            foreach (($p['gericht'] ?? []) as $id => $alt) {
                DB::table('foodalchemist_recipes')->where('id', (int) $id)->update(['serving_vehicle_vocab_id' => $alt['typ'], 'serving_vehicle_source' => $alt['source']]);
            }
            $this->info('Zurückgenommen: ' . count($p['darreichung'] ?? []) . ' Darreichungen, ' . count($p['gericht'] ?? []) . ' Gerichte.');

            return self::SUCCESS;
        }

        $anDarreichung = [];   // darreichung_id => [neuer Typ, alter Wert]
        $insGericht = [];      // recipe_id => [neuer Typ, alter Typ, alte Quelle]
        FoodAlchemistRecipeDarreichung::query()->where('is_standard', true)->orderBy('id')
            ->chunkById(500, function ($zeilen) use ($svc, &$anDarreichung, &$insGericht) {
                $gerichte = DB::table('foodalchemist_recipes')->whereIn('id', $zeilen->pluck('recipe_id'))->whereNull('deleted_at')
                    ->get(['id', 'serving_vehicle_vocab_id', 'serving_vehicle_source'])->keyBy('id');
                foreach ($zeilen as $d) {
                    $g = $gerichte->get($d->recipe_id);
                    if ($g === null) {
                        continue;
                    }
                    $gTyp = $g->serving_vehicle_vocab_id !== null ? (int) $g->serving_vehicle_vocab_id : null;
                    $dTyp = $svc->vehikelId($d);
                    if ($dTyp === null && $gTyp !== null) {
                        $anDarreichung[(int) $d->id] = [$gTyp, $d->serving_vehicle_vocab_id];
                    } elseif ($dTyp !== null && $dTyp !== $gTyp) {
                        $insGericht[(int) $g->id] = [$dTyp, $gTyp, $g->serving_vehicle_source];
                    }
                }
            });

        $this->line('Typ vom Gericht an die Standard-Darreichung: ' . count($anDarreichung));
        $this->line('Gericht an den wirksamen Typ der Darreichung angleichen: ' . count($insGericht));
        if (! $this->option('apply')) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }
        if (! $this->backupOk()) {
            return self::FAILURE;
        }
        foreach ($anDarreichung as $id => [$typ]) {
            DB::table('foodalchemist_recipe_presentations')->where('id', $id)->update(['serving_vehicle_vocab_id' => $typ, 'updated_at' => now()]);
        }
        foreach ($insGericht as $id => [$typ]) {
            DB::table('foodalchemist_recipes')->where('id', $id)->update(['serving_vehicle_vocab_id' => $typ, 'serving_vehicle_source' => 'manual', 'serving_vehicle_ai_confidence' => null]);
        }
        $protokoll = $bericht . '/' . now()->format('Ymd_His') . '_darreichung_typ_protokoll.json';
        file_put_contents($protokoll, json_encode([
            'darreichung' => array_map(fn ($v) => $v[1], $anDarreichung),
            'gericht' => array_map(fn ($v) => ['typ' => $v[1], 'source' => $v[2]], $insGericht),
            'zeit' => now()->toIso8601String(),
        ]));
        $this->info('Übernommen: ' . count($anDarreichung) . ' Darreichungen, ' . count($insGericht) . " Gerichte angeglichen. Protokoll: {$protokoll}");

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
