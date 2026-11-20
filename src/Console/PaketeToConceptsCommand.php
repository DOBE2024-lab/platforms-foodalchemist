<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketUmzug\PaketParitaet;
use Platform\FoodAlchemist\Services\PaketUmzug\PaketUmzugService;

/**
 * Alt-Pakete → kind=paket-Concepts (Conceptor-Kaskade Phase 1, 2026-08-24; Code-Qualität 2.6a).
 *
 * Ohne Flag: Trockenlauf — schreibt nichts, zeigt je Paket Ziel, Aktion, ob die vorhandene Kopie veraltet ist, Verweise,
 * Blocker und den Paritäts-Report. `--apply` schreibt in einer Transaktion und legt ein Journal ab, `--rueckweg=<journal>`
 * stellt daraus den Zustand davor wieder her. Die Alt-Tabellen bleiben unangetastet (Shadow). Logik: {@see PaketUmzugService}.
 */
class PaketeToConceptsCommand extends Command
{
    protected $signature = 'foodalchemist:pakete-to-concepts
        {--apply : schreiben (ohne Flag nur Trockenlauf)}
        {--id=* : nur diese Alt-Paket-Ids (z. B. --id=1 --id=2); ohne = alle}
        {--rueckweg= : Journal-Datei eines --apply-Laufs zurückspielen}
        {--json= : Plan + Parität zusätzlich als JSON in diese Datei schreiben}';

    protected $description = 'Migriert Alt-Pakete → kind=paket-Concepts (Trockenlauf, Journal, Rückweg, Paritäts-Report).';

    public function handle(PaketUmzugService $umzug, PaketParitaet $paritaet): int
    {
        if (($datei = $this->option('rueckweg')) !== null) {
            $journal = json_decode((string) @file_get_contents($datei), true);
            if (! is_array($journal)) {
                $this->error("Journal nicht lesbar: {$datei}");

                return self::FAILURE;
            }
            $this->info('Rückweg: '.$umzug->rueckweg($journal).' Pakete zurückgesetzt.');

            return self::SUCCESS;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLY' : 'TROCKENLAUF').' — Alt-Pakete → kind=paket-Concepts');

        if ($apply) {
            try {
                $r = $umzug->anwenden($ids);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $pfad = storage_path('app/foodalchemist/paket-umzug/journal-'.now()->format('Ymd-His').'.json');
            @mkdir(dirname($pfad), 0775, true);
            file_put_contents($pfad, json_encode($r['journal'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Journal: '.$pfad.' (Rückweg: --rueckweg='.$pfad.')');
        }

        $report = $this->bericht($umzug, $paritaet, $ids);
        if (($json = $this->option('json')) !== null) {
            file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON: {$json}");
        }

        $blockiert = collect($report)->filter(fn ($z) => $z['blocker'] !== [])->count();
        $abweichend = collect($report)->filter(fn ($z) => $z['paritaet_ok'] === false)->count();
        $this->info(count($report).' Pakete, '.$blockiert.' blockiert, '.$abweichend.' mit Paritätsabweichung'
            .($apply ? '.' : '. Mit --apply ausführen.'));

        return $blockiert > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function bericht(PaketUmzugService $umzug, PaketParitaet $paritaet, array $ids): array
    {
        $out = [];
        foreach ($umzug->plan($ids) as $z) {
            $this->line('');
            $this->line("#{$z['paket_id']} {$z['name']} (Team {$z['team_id']}) → ".strtoupper($z['aktion'])
                .($z['concept_id'] ? " Concept #{$z['concept_id']}" : '')
                ." · Positionen alt {$z['positionen_alt']}".($z['positionen_kopie'] !== null ? ", Kopie {$z['positionen_kopie']} (Stand {$z['kopie_stand']})" : ''));
            if ($z['veraltet']) {
                $this->warn('  Kopie veraltet: '.implode('; ', $z['veraltet_gruende']));
            }
            $verweise = collect($z['verweise'])->filter()->map(fn ($n, $k) => "{$k} {$n}")->implode(', ');
            $this->line('  Verweise: '.($verweise !== '' ? $verweise : 'keine'));
            foreach ($z['blocker'] as $b) {
                $this->error('  BLOCKER: '.$b);
            }

            $z['paritaet'] = null;
            $z['paritaet_ok'] = null;
            $concept = $z['concept_id'] ? FoodAlchemistConcept::withoutGlobalScopes()->find($z['concept_id']) : null;
            if ($concept !== null) {
                $z['paritaet'] = $paritaet->vergleiche(FoodAlchemistPaket::withoutGlobalScopes()->findOrFail($z['paket_id']), $concept);
                $z['paritaet_ok'] = PaketParitaet::gleich($z['paritaet']);
                foreach ($z['paritaet'] as $feld => $f) {
                    $this->line(sprintf('  %-4s %-24s alt %s | neu %s', $f['ok'] ? 'ok' : 'DIFF', $feld,
                        json_encode($f['alt'], JSON_UNESCAPED_UNICODE), json_encode($f['neu'], JSON_UNESCAPED_UNICODE)));
                }
            }
            $out[] = $z;
        }

        return $out;
    }
}
