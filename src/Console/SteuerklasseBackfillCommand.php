<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Services\GpStamm\SteuerklasseService;

/**
 * Einmalige Übernahme der Steuerklasse (Entscheid Dominique 16.11.2026): GPs ohne `vat_profile_key` bekommen die Klasse aus der Regel
 * ({@see SteuerklasseService::regelFuerGp}). Artikel bleiben NULL (erben vom GP); nur wo die Necta-Zahl der GP-Klasse widerspricht, steht
 * der Artikel im Bericht — die Necta-Zahl wird nie automatisch zur Artikel-Klasse (sie ist oft falsch oder ausländisch).
 * Trockenlauf = Default; --apply braucht --backup-nachweis; --zurueck setzt die im Protokoll gesetzten GPs wieder auf NULL.
 */
class SteuerklasseBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:steuerklasse-backfill
        {--apply : wirklich schreiben}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--bericht= : Ordner für Bericht und Protokoll (Standard: storage/app/fa-steuerklasse)}
        {--neu-berechnen : auch gesetzte Klassen neu aus der Regel berechnen (nach Regeländerung); nur Abweichungen werden geschrieben}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'Steuerklasse (regulär/ermäßigt) für GPs aus der Regel setzen; Necta-Widersprüche der Artikel berichten';

    public function handle(SteuerklasseService $svc): int
    {
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-steuerklasse'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        if (($z = $this->option('zurueck')) !== null) {
            $p = json_decode((string) file_get_contents((string) $z), true);
            $ids = array_map('intval', $p['gp_ids'] ?? []);
            foreach (array_chunk($ids, 1000) as $teil) {
                DB::table('foodalchemist_gps')->whereIn('id', $teil)->update(['vat_profile_key' => null]);
            }
            foreach (($p['vorher'] ?? []) as $id => $alt) {   // --neu-berechnen: alte Klasse wiederherstellen
                DB::table('foodalchemist_gps')->where('id', (int) $id)->update(['vat_profile_key' => $alt]);
            }
            $this->info('Zurückgenommen: ' . (count($ids) + count($p['vorher'] ?? [])) . ' GPs.');

            return self::SUCCESS;
        }

        $neu = (bool) $this->option('neu-berechnen');
        $gps = DB::table('foodalchemist_gps')->whereNull('deleted_at')
            ->when(! $neu, fn ($q) => $q->whereNull('vat_profile_key'))
            ->get(['id', 'name', 'main_ingredient_display', 'commodity_group_code', 'sub_category', 'vat_profile_key']);
        $plan = [];
        $vorher = [];
        foreach ($gps as $g) {
            $regel = $svc->regelFuerGp($g);
            if ($g->vat_profile_key !== null && $g->vat_profile_key === $regel) {
                continue;
            }
            if ($g->vat_profile_key !== null) {
                $vorher[(int) $g->id] = $g->vat_profile_key;
            }
            $plan[$regel][] = (int) $g->id;
        }

        // Necta-Widersprüche (nur Bericht)
        $klasseJeGp = [];
        foreach ($gps as $g) {
            $klasseJeGp[$g->id] = $svc->regelFuerGp($g);
        }
        $h = fopen("{$bericht}/{$stempel}_necta_widersprueche.csv", 'wb');
        fputcsv($h, ['supplier_item_id', 'lieferant', 'bezeichnung', 'necta_mwst', 'gp_id', 'gp_name', 'gp_klasse'], ';', '"', '');
        $wid = 0;
        DB::table('foodalchemist_supplier_item_structures as s')
            ->join('foodalchemist_supplier_items as i', 'i.id', '=', 's.supplier_item_id')
            ->join('foodalchemist_gps as g', 'g.id', '=', 's.gp_id')
            ->leftJoin('foodalchemist_suppliers as l', 'l.id', '=', 'i.supplier_id')
            ->whereNull('s.deleted_at')->whereNull('i.deleted_at')->whereNotNull('i.vat')->whereNull('i.vat_profile_key')
            ->orderBy('s.id')
            ->select(['i.id', 'i.vat', 'i.designation', 'l.name as lf', 'g.id as gp_id', 'g.name as gp_name', 'g.vat_profile_key', 'g.commodity_group_code', 'g.main_ingredient_display', 'g.sub_category'])
            ->chunk(5000, function ($zeilen) use ($svc, $h, &$wid, $klasseJeGp) {
                foreach ($zeilen as $r) {
                    $gpKlasse = $r->vat_profile_key ?: ($klasseJeGp[$r->gp_id] ?? $svc->regelFuerGp((object) ['name' => $r->gp_name, 'main_ingredient_display' => $r->main_ingredient_display, 'commodity_group_code' => $r->commodity_group_code, 'sub_category' => $r->sub_category]));
                    if ($svc->nectaKlasse($r->vat) !== $gpKlasse) {
                        fputcsv($h, [$r->id, $r->lf, trim((string) $r->designation), $r->vat, $r->gp_id, $r->gp_name, $gpKlasse], ';', '"', '');
                        $wid++;
                    }
                }
            });
        fclose($h);

        if ($neu) {
            $this->line('Davon umgestellt (vorher andere Klasse): ' . count($vorher));
        }
        $this->table(['Klasse', 'GPs'], array_map(fn ($k) => [SteuerklasseService::LABELS[$k] ?? $k, count($plan[$k] ?? [])], SteuerklasseService::KLASSEN));
        $this->line("Artikel, deren Necta-MwSt der GP-Klasse widerspricht: {$wid} → {$bericht}/{$stempel}_necta_widersprueche.csv");

        if (! $this->option('apply')) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }
        if (! $this->backupOk()) {
            return self::FAILURE;
        }
        $gesetzt = [];
        foreach ($plan as $klasse => $ids) {
            foreach (array_chunk($ids, 1000) as $teil) {
                DB::table('foodalchemist_gps')->whereIn('id', $teil)->update(['vat_profile_key' => $klasse]);
            }
            $gesetzt = array_merge($gesetzt, array_values(array_filter($ids, fn ($id) => ! isset($vorher[$id]))));
        }
        $protokoll = "{$bericht}/{$stempel}_steuerklasse_protokoll.json";
        file_put_contents($protokoll, json_encode(['gp_ids' => $gesetzt, 'vorher' => $vorher, 'zeit' => now()->toIso8601String()]));
        $this->info('Gesetzt: ' . count($gesetzt) . " GPs. Protokoll: {$protokoll}");

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
