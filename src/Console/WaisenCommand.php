<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\BildWaisenService;

/**
 * Waisen-Bilddateien (Folge aus dem Bild-Löschschutz #901/#907): Standard ist ein Bericht, der nichts schreibt.
 * `--apply` verschiebt ungenutzte FA-Kontextdateien in Quarantäne (mit Journal), `--rueckweg=<journal>` legt sie zurück.
 * Endgültiges Löschen gibt es hier bewusst nicht — das macht ein Mensch nach Freigabe. Altpfade auf `public` stehen nur als Hinweis im Bericht.
 */
class WaisenCommand extends Command
{
    protected $signature = 'foodalchemist:waisen
        {--aelter-als=30 : nur Dateien, die älter als so viele Tage sind}
        {--team= : nur Kontextdateien dieses Teams (ohne Altpfade)}
        {--limit= : höchstens so viele Kandidaten je Art (zum Testen)}
        {--json : Bericht maschinenlesbar}
        {--apply : ungenutzte Kontextdateien in Quarantäne verschieben (mit Journal)}
        {--rueckweg= : Journal eines --apply-Laufs zurücknehmen}
        {--journal-ordner= : Ablage der Journale (Standard: storage/app/fa-waisen)}';

    protected $description = 'Ungenutzte Bilddateien des FoodAlchemist melden, in Quarantäne legen oder zurückholen (nie löschen)';

    private const LABEL = [
        'kontextdatei' => 'Kontextdateien (FA, ungenutzt)',
        'altpfad' => 'Altpfade auf public — nur Hinweis, wird nicht verschoben',
        'zeile_ohne_datei' => 'Zeilen ohne Datei — nur Information',
    ];

    public function handle(BildWaisenService $waisen): int
    {
        if (($journal = $this->option('rueckweg')) !== null) {
            $r = $waisen->rueckweg((string) $journal);
            $this->info("Zurückgelegt: {$r['zurueck']} Datei(en).");
            foreach ($r['konflikte'] as $k) {
                $this->warn("Konflikt (Ursprungsort belegt, nicht überschrieben): {$k}");
            }
            foreach ($r['fehlend'] as $f) {
                $this->warn("Fehlt in der Quarantäne: {$f}");
            }

            return $r['konflikte'] === [] && $r['fehlend'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $tage = (int) $this->option('aelter-als');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $funde = $waisen->suche($tage, $this->option('team') !== null ? (int) $this->option('team') : null, $limit);
        $bericht = ['aelter_als_tage' => $tage, 'arten' => []];
        foreach ($funde as $art => $liste) {
            $bericht['arten'][$art] = ['anzahl' => count($liste), 'bytes' => array_sum(array_column($liste, 'bytes')),
                'beispiele' => array_slice(array_map(fn ($e) => $e['disk'] . ':' . $e['pfad'], $liste), 0, 5), 'verschiebbar' => $art === 'kontextdatei'];
        }

        if ($this->option('apply')) {
            $q = $waisen->quarantaene($funde['kontextdatei'], (string) ($this->option('journal-ordner') ?: storage_path('app/fa-waisen')));
            $bericht['quarantaene'] = ['lauf' => $q['lauf'], 'journal' => $q['journal'], 'verschoben' => $q['verschoben'], 'bytes' => $q['bytes'],
                'uebersprungen' => count($q['uebersprungen'])];
        }

        if ($this->option('json')) {
            $this->line(json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info("Waisen-Bilddateien, älter als {$tage} Tage:");
        foreach ($bericht['arten'] as $art => $b) {
            $this->line(sprintf('  %s: %d Datei(en), %s', self::LABEL[$art], $b['anzahl'], $this->groesse($b['bytes'])));
            foreach ($b['beispiele'] as $p) {
                $this->line("      {$p}");
            }
        }
        if (isset($bericht['quarantaene'])) {
            $q = $bericht['quarantaene'];
            $this->info("In Quarantäne: {$q['verschoben']} Kontextdatei(en), " . $this->groesse($q['bytes']) . ", übersprungen (wieder genutzt): {$q['uebersprungen']}.");
            $this->line("Journal: {$q['journal']}  — zurück mit --rueckweg={$q['journal']}");
        } else {
            $this->line('Bericht, nichts verschoben. Mit --apply in Quarantäne legen.');
        }

        return self::SUCCESS;
    }

    private function groesse(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
}
