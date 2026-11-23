<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Console\Concerns\SchreibtLaufDatei;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\GrammaturMessung;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\GrammaturUmstellungService;

/**
 * Plan 98 B · freie Grammaturen auf „automatisch“ (Entscheide Dominique 22.11.2026). Logik und Regeln:
 * {@see GrammaturUmstellungService}.
 *
 * Ohne Flag: Trockenlauf — rechnet die Umstellung in einer Transaktion wirklich durch und rollt zurück. `--apply` schreibt nur,
 * wenn kein Verstoß auftritt (fremde Tabelle, Zeile außerhalb der Verwender, Fehler/Warnung des Recompute-Wegs, unerwartete
 * Änderung), und legt ein Journal ab; `--rueckweg=<journal>` stellt den Zustand davor wieder her.
 */
class GrammaturAutomatischCommand extends Command
{
    use SchreibtLaufDatei;

    protected $signature = 'foodalchemist:grammatur-automatisch
        {--apply : schreiben (ohne Flag nur Trockenlauf mit Rollback)}
        {--rezept=* : nur diese Rezept-Ids; ohne = alle}
        {--rueckweg= : Journal-Datei eines --apply-Laufs zurückspielen}
        {--json= : Report zusätzlich als JSON in diese Datei schreiben}';

    protected $description = 'Plan 98 B: freie Grammaturen → Summe der Bestandteile, Gericht = eine Portion (Trockenlauf, Journal, Rückweg, Report, Entscheidungsliste).';

    public function handle(GrammaturUmstellungService $umstellung): int
    {
        if (($datei = $this->option('rueckweg')) !== null) {
            $journal = json_decode((string) @file_get_contents($datei), true);
            if (! is_array($journal)) {
                $this->error("Journal nicht lesbar: {$datei}");

                return self::FAILURE;
            }
            $this->info('Rückweg: '.$umstellung->rueckweg($journal).' Darreichungen zurückgesetzt und neu gerechnet.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLY' : 'TROCKENLAUF').' — freie Grammaturen → automatisch');
        $bericht = $umstellung->lauf($apply, array_values(array_filter(array_map('intval', (array) $this->option('rezept')))));
        $this->ausgeben($bericht);

        if ($apply && $bericht['ok'] && $bericht['journal'] !== null) {
            $pfad = storage_path('app/foodalchemist/grammatur-umstellung/journal-'.now()->format('Ymd-His').'.json');
            if (! $this->schreibeLaufDatei($pfad, $bericht['journal'])) {
                $this->error('Umstellung ist geschrieben, aber ohne Journal — Rückweg nur über das Datenbank-Backup.');

                return self::FAILURE;
            }
            $this->info('Journal: '.$pfad.' (Rückweg: --rueckweg='.$pfad.')');
        }
        if (($json = $this->option('json')) !== null) {
            $bericht['journal'] = $bericht['journal'] !== null ? '(siehe Journal-Datei)' : null;
            if (! $this->schreibeLaufDatei($json, $bericht)) {
                return self::FAILURE;
            }
            $this->info("JSON: {$json}");
        }

        return $bericht['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function ausgeben(array $b): void
    {
        $zeilen = collect($b['rezepte'])->flatMap(fn ($r) => collect($r['darreichungen'])->map(fn ($d) => $d + ['rezept' => $r]));
        // Sortiert nach der Größe der Grammatur-Abweichung (Kurator: alt → neu, %, absteigend)
        foreach ($zeilen->sortByDesc(fn ($d) => abs($d['gramm_prozent'] ?? 0)) as $d) {
            $r = $d['rezept'];
            $preis = fn (?array $m) => $m === null ? '–' : sprintf('%s g · EK %s · VK %s', $m['gramm'] ?? '–', $m['ek_portion'] ?? '–', $m['sales_net'] ?? '–');
            $this->line(sprintf('%-17s R%d T%d %s · D%d%s · %s%s', strtoupper($d['kategorie']), $r['recipe_id'], $r['team_id'], $r['name'],
                $d['darreichung_id'], $d['standard'] ? ' (Standard)' : '', $d['aktion'],
                $d['standard'] && $d['fester_ertrag'] ? ' · FESTER ERTRAG → gegart' : ''));
            $this->line(sprintf('    alt %s → neu %s · Grammatur %s %% · Preismodus %s → %s',
                $preis($d['alt']), $preis($d['neu']), $d['gramm_prozent'] ?? '–', $d['alt']['price_mode'] ?? '–', $d['neu']['price_mode']));
            if ($d['unterschiede'] !== []) {
                $this->line('    Unterschiede: '.implode(', ', $d['unterschiede']).($d['hinweis'] ? ' — '.$d['hinweis'] : ''));
            }
            $k = $r['kennzahlen_alt'];
            if ($d['standard']) {
                $this->line(sprintf('    Rezept: Bestandteile %s g · Ertrag %s g%s · Portionen %s · Kopf %s g → %s g · %d Zutaten, %d unbepreist',
                    $k['b_g'], $k['y_g'] ?? '–', $k['fester_ertrag'] ? ' (fest)' : '', $k['portionen'] ?? '–', $k['kopf_g'] ?? '–',
                    $r['kennzahlen_neu']['kopf_g'] ?? '–', $k['zutaten'], $k['unbepreist']));
            }
        }
        foreach ($b['nicht_ableitbar'] as $n) {
            $this->warn(sprintf('NICHT ABLEITBAR   R%d T%d %s · D%d%s — %s (Zutaten %d, Bestandteile %s g)', $n['recipe_id'], $n['team_id'], $n['name'],
                $n['darreichung_id'], $n['standard'] ? ' (Standard)' : '', $n['grund'], $n['zutaten'], $n['b_g']));
        }
        foreach ($b['zaehlung'] as $phase => $tabellen) {
            // Kurator 23.11.: Phase 0 klar abgrenzen — sie holt nur veraltete Caches nach und ist nicht Teil der Umstellung
            $this->line((string) $phase === '0'
                ? 'Phase 0 (Aufholen veralteter Caches, unabhängig von der Umstellung) — geschriebene Zeilen je Tabelle und Team:'
                : "Phase {$phase} — geschriebene Zeilen je Tabelle und Team:");
            foreach ($tabellen as $t => $teams) {
                $this->line(sprintf('    %-44s %s', $t, collect($teams)->map(fn ($n, $team) => "T{$team}: {$n}")->implode(', ')));
            }
        }
        // Master 23.11.: EK-Sprünge durch Phase 0 je Tabelle und Team — bei vielen großen Sprüngen vor dem Apply anhalten
        foreach ($b['phase0_ek'] ?? [] as $t => $teams) {
            foreach ($teams as $team => $s) {
                $zeile = sprintf('Phase 0 EK  %-36s T%s: %d Rezepte geändert · größter Sprung %s %% (R%s) · %d ab %s %%',
                    $t, $team, $s['rezepte'], $s['max_prozent'] ?? '–', $s['max_recipe_id'] ?? '–', $s['ab_warnschwelle'],
                    GrammaturMessung::SPRUNG_WARN_PROZENT);
                $s['ab_warnschwelle'] > 0 ? $this->warn($zeile) : $this->line($zeile);
            }
        }
        // Kurator 23.11.: Entscheidungsliste für Dominique — Standards, deren freie Grammatur von der Summe der Bestandteile abweicht
        $liste = $zeilen->filter(fn ($d) => $d['standard'] && in_array('gramm', $d['unterschiede'], true));
        if ($liste->isNotEmpty()) {
            $this->warn('Entscheidungsliste — freie Grammatur ≠ Summe der Bestandteile (Gericht = eine Portion):');
            foreach ($liste as $d) {
                $this->line(sprintf('    R%d T%d %s: Grammatur %s g → Summe %s g · EK je Portion %s → %s%s', $d['rezept']['recipe_id'], $d['rezept']['team_id'],
                    $d['rezept']['name'], $d['alt']['gramm'] ?? '–', $d['neu']['gramm'] ?? '–', $d['alt']['ek_portion'] ?? '–', $d['neu']['ek_portion'] ?? '–',
                    $d['fester_ertrag'] ? ' · fester Ertrag → gegart' : ''));
            }
        }
        $kat = $zeilen->countBy('kategorie');
        $this->info(sprintf('%d gleich, %d nicht verlustfrei, %d nicht ableitbar, %d Fertigungstiefen durch die Umstellung hochgestuft.',
            $kat['gleich'] ?? 0, $kat['nicht_verlustfrei'] ?? 0, count($b['nicht_ableitbar']), $b['fertigungstiefe_hochgestuft']));
        $this->line(sprintf('Verwender außerhalb der Auswahl geprüft: %d Rezepte, %d Darreichungen (jede Bewegung wäre ein Verstoß).',
            $b['eltern_geprueft']['rezepte'] ?? 0, $b['eltern_geprueft']['darreichungen'] ?? 0));
        $this->line(sprintf('Phase 0 (Aufholen, unabhängig von der Umstellung): %d Fertigungstiefen nach Inhalt hochgestuft.',
            $b['phase0_fertigungstiefe_hochgestuft'] ?? 0));
        foreach ($b['verstoesse'] as $v) {
            $this->error('VERSTOSS: '.$v);
        }
        $this->info($b['ok']
            ? ($b['apply'] ? 'Geschrieben.' : 'Trockenlauf zurückgerollt. Mit --apply ausführen.')
            : 'ABGEBROCHEN, alles zurückgerollt.');
    }
}
