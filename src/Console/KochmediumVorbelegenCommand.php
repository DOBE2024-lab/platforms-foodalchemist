<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Vorbelegung des GP-Merkmals `is_kochmedium` (Regelwerk Basisrezepte §18.7, Entscheid Dominique 17.11.2026): Vorschlag der
 * Zeilenart „Medium“ für Rezeptzeilen ohne eigene Zeilenart. Wirkt in der Rechnung nur, wenn die Zeile einen Garverlust hat —
 * darum reicht die schmale Auswahl:
 *   · Speisesalz (Hauptzutat salz/speisesalz/kochsalz/siedesalz/meersalz/steinsalz) — Salz im Koch-/Blanchierwasser.
 *     Gewürzsalze, Pökelsalz, Finishing-Salze (Meersalz grün/rot/schwarz) bleiben draußen.
 *   · Frittierfett / Frittieröl.
 *   · Koch-/Leitungswasser (Entscheid 17.11.: Kochwasser ist Medium, Salz darin mit demselben Prozentsatz). Wasser in Suppe/Teig
 *     ohne Garverlust zählt weiter voll; Mineralwasser (Getränk) bleibt draußen.
 * Trockenlauf = Default (Bericht als CSV); --apply braucht --backup-nachweis; --zurueck setzt die im Protokoll gesetzten GPs zurück.
 * Danach ist ein Recompute der Rezepte nötig (siehe PR), sonst bleiben die gespeicherten Nährwerte alt.
 */
class KochmediumVorbelegenCommand extends Command
{
    /** Hauptzutat-Slugs für Speisesalz (exakt, keine Teiltreffer). */
    public const SALZ_SLUGS = ['salz', 'speisesalz', 'kochsalz', 'siedesalz', 'meersalz', 'steinsalz'];

    /** Hauptzutat-Slugs für Frittiermedien. */
    public const FRITTIER_SLUGS = ['frittierfett', 'frittieroel'];

    /** Hauptzutat-Slugs für Kochwasser. */
    public const WASSER_SLUGS = ['wasser', 'leitungswasser', 'trinkwasser'];

    protected $signature = 'foodalchemist:kochmedium-vorbelegen
        {--apply : wirklich schreiben}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--bericht= : Ordner für Bericht und Protokoll (Standard: storage/app/fa-kochmedium)}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'GP-Merkmal Kochmedium (Vorschlag Zeilenart „Medium“, §18.7) für Speisesalz, Frittierfett und Kochwasser vorbelegen';

    public function handle(): int
    {
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-kochmedium'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        if (($z = $this->option('zurueck')) !== null) {
            $p = json_decode((string) file_get_contents((string) $z), true);
            $ids = array_map('intval', $p['gp_ids'] ?? []);
            foreach (array_chunk($ids, 1000) as $teil) {
                DB::table('foodalchemist_gps')->whereIn('id', $teil)->update(['is_kochmedium' => false]);
            }
            $this->info('Zurückgenommen: ' . count($ids) . ' GPs.');

            return self::SUCCESS;
        }

        $gps = DB::table('foodalchemist_gps')->whereNull('deleted_at')->where('is_kochmedium', false)
            ->whereIn('main_ingredient_slug', [...self::SALZ_SLUGS, ...self::FRITTIER_SLUGS, ...self::WASSER_SLUGS])
            ->orderBy('id')->get(['id', 'name', 'main_ingredient_slug', 'team_id', 'status']);

        $h = fopen("{$bericht}/{$stempel}_kochmedium_auswahl.csv", 'wb');
        fputcsv($h, ['gp_id', 'name', 'hauptzutat', 'team_id', 'status'], ';', '"', '');
        foreach ($gps as $g) {
            fputcsv($h, [$g->id, $g->name, $g->main_ingredient_slug, $g->team_id, $g->status], ';', '"', '');
        }
        fclose($h);

        $this->table(['Gruppe', 'GPs'], [
            ['Speisesalz', $gps->whereIn('main_ingredient_slug', self::SALZ_SLUGS)->count()],
            ['Frittierfett/-öl', $gps->whereIn('main_ingredient_slug', self::FRITTIER_SLUGS)->count()],
            ['Kochwasser', $gps->whereIn('main_ingredient_slug', self::WASSER_SLUGS)->count()],
        ]);
        $this->line("Auswahl: {$bericht}/{$stempel}_kochmedium_auswahl.csv");

        if (! $this->option('apply')) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }
        if (! $this->backupOk()) {
            return self::FAILURE;
        }
        $ids = $gps->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (array_chunk($ids, 1000) as $teil) {
            DB::table('foodalchemist_gps')->whereIn('id', $teil)->update(['is_kochmedium' => true]);
        }
        $protokoll = "{$bericht}/{$stempel}_kochmedium_protokoll.json";
        file_put_contents($protokoll, json_encode(['gp_ids' => $ids, 'zeit' => now()->toIso8601String()]));
        $this->info('Gesetzt: ' . count($ids) . " GPs. Protokoll: {$protokoll}");

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
