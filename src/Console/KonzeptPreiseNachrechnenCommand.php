<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Concepter\KonzeptPreiseNachrechnen;

/**
 * CQ price_incomplete: Preis-Cache aller Konzepte und Pakete neu rechnen, damit `price_incomplete` gesetzt ist. Standard ist ein
 * Trockenlauf, der nichts schreibt; `--apply` rechnet über ConceptService::recomputeCache nach (eingebettete Pakete zuerst).
 */
class KonzeptPreiseNachrechnenCommand extends Command
{
    protected $signature = 'foodalchemist:konzept-preise-nachrechnen
        {--team= : nur Konzepte dieses Teams}
        {--apply : Preis-Cache wirklich neu schreiben (ohne: Trockenlauf)}';

    protected $description = 'Konzept-Preise neu rechnen und „Preis unvollständig“ setzen (Standard: Trockenlauf)';

    public function handle(KonzeptPreiseNachrechnen $nachrechnen): int
    {
        $apply = (bool) $this->option('apply');
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;
        $r = $nachrechnen->lauf($team, $apply);

        $this->line($apply ? 'Nachgerechnet:' : 'Trockenlauf (schreibt nichts; eingebettete Pakete mit gespeichertem Stand):');
        $this->line("  Konzepte und Pakete: {$r['anzahl']}");
        $this->line("  Preis unvollständig: {$r['unvollstaendig']}");
        $this->line("  Merker neu oder geändert: {$r['flag_neu']}");
        $this->line("  Preis je Person ändert sich: {$r['preis_aendert']}");
        if ($r['beispiele'] !== []) {
            $this->table(['ID', 'Name', 'Art', 'Preis alt', 'Preis neu', 'unvollständig'], array_map(fn ($b) => [
                $b['concept_id'], $b['name'], $b['kind'] === 'paket' ? 'Paket' : 'Konzept',
                $b['preis_alt'] ?? '–', $b['preis_neu'] ?? '–', $b['unvollstaendig'] ? 'ja' : 'nein',
            ], $r['beispiele']));
        }
        foreach ($r['fehler'] as $f) {
            $this->error("Fehler bei #{$f['concept_id']} {$f['name']}: {$f['fehler']}");
        }
        if (! $apply) {
            $this->line('Schreiben mit --apply.');
        }

        return $r['fehler'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
