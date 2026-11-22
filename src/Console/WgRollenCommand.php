<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\WgRolle;

/**
 * Code-Qualität 3.7: Trockenlauf-Report der Warengruppen-Rollen. **Default ist Probelauf.**
 *
 * Zeigt je Warengruppen-Zeile die gesetzten Rollen-Flags gegen die Standard-Belegung (WgRolle::STANDARD) und welche
 * Flags der Backfill setzen würde. Mit --apply setzt er sie (nur auf true, nimmt nie etwas weg) — dasselbe, was die
 * Migration `2027_01_08_100000_warengruppen_rollen` beim Deploy tut. Fehlen die Spalten noch, meldet er das und
 * zeigt, was die Migration setzen wird.
 */
class WgRollenCommand extends Command
{
    protected $signature = 'foodalchemist:wg-rollen
        {--apply : fehlende Standard-Flags wirklich setzen (ohne dieses Flag nur Probelauf)}';

    protected $description = 'CQ 3.7: Rollen-Flags der Warengruppen (Getränk, Non-Food, kühl, fertig) prüfen und nachziehen.';

    private const TABELLE = 'foodalchemist_lookup_commodity_groups';

    public function handle(): int
    {
        $spalten = array_values(WgRolle::SPALTEN);
        $fehlen = array_values(array_filter($spalten, fn ($s) => ! Schema::hasColumn(self::TABELLE, $s)));
        if ($fehlen !== [] && $this->option('apply')) {
            $this->error('Spalten fehlen ('.implode(', ', $fehlen).') — zuerst migrieren.');

            return self::FAILURE;
        }

        $zeilen = DB::table(self::TABELLE)->whereNull('deleted_at')->orderBy('code')->orderBy('team_id')
            ->get(['id', 'team_id', 'code', 'name', ...($fehlen === [] ? $spalten : [])]);
        $tabelle = [];
        $zuSetzen = [];
        foreach ($zeilen as $z) {
            $soll = WgRolle::standardFlags($z->code);
            $ist = [];
            $neu = [];
            foreach ($spalten as $s) {
                $hat = $fehlen === [] && (bool) $z->{$s};
                $ist[] = $hat ? substr($s, 3) : null;
                if ($soll[$s] && ! $hat) {
                    $neu[] = substr($s, 3);
                    $zuSetzen[$s][] = (int) $z->id;
                }
            }
            if (array_filter($ist) !== [] || $neu !== []) {
                $tabelle[] = [$z->code, $z->team_id ?? 'global', $z->name, implode(', ', array_filter($ist)) ?: '—', implode(', ', $neu) ?: '—'];
            }
        }
        if ($fehlen !== []) {
            $this->warn('Spalten fehlen noch ('.implode(', ', $fehlen).'). Die Migration setzt beim Deploy:');
        }
        $this->table(['Code', 'Team', 'Name', 'gesetzt', 'würde setzen'], $tabelle);
        $n = array_sum(array_map('count', $zuSetzen));
        $this->line("{$zeilen->count()} Warengruppen-Zeilen, {$n} Flag(s) zu setzen.");

        if (! $this->option('apply') || $n === 0) {
            if ($n > 0) {
                $this->line('Probelauf — nichts geschrieben. Mit --apply setzen.');
            }

            return self::SUCCESS;
        }
        DB::transaction(function () use ($zuSetzen) {
            foreach ($zuSetzen as $spalte => $ids) {
                DB::table(self::TABELLE)->whereIn('id', $ids)->update([$spalte => true]);
            }
        });
        WgRolle::vergessen();
        $this->info("{$n} Flag(s) gesetzt.");

        return self::SUCCESS;
    }
}
