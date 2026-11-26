<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Services\GpStamm\GpNamingService;

/**
 * Degenerierte gp_keys nachziehen (Kurator/Dominique 20.11.2026; Fall „Tomate: trocken, geschwefelt“ ⇄ „Tomate: konserviert, Pulpe / (Bio)“,
 * beide „tomate||“). Ein Schlüssel aus nur der Hauptzutat bekommt den Merkmal-Slot aus Zustand, Pflichtangabe und Bio
 * ({@see GpNamingService::merkmalSlot}, wie für Neuanlagen) an den ALTEN Schlüssel angehängt („tomate||“ → „tomate|||m-trocken_geschwefelt“).
 *
 * - Nur degenerierte Schlüssel („x||“); jeder andere Schlüssel bleibt. GPs ohne Zustand/Pflichtangabe/Bio behalten ihren Schlüssel.
 * - Kollisionen nach dem Neubilden (gleicher neuer Schlüssel im selben Team bzw. global, auch mit gelöschten/gemergten Zeilen, weil der
 *   DB-Unique sie mitzählt) werden NICHT zusammengeführt und brechen nicht ab: die Beteiligten behalten den alten Schlüssel und stehen auf der
 *   Review-Liste (Dubletten-Kandidaten).
 * - Der GP-Paket-Import findet die GPs unter dem alten Schlüssel weiter (Alias, {@see \Platform\FoodAlchemist\Services\GpPaket\GpPaketValidator::kontext}).
 * - Schreibt per Query-Builder; Protokoll mit alten Schlüsseln, `--zurueck=<Protokoll>` setzt genau sie zurück.
 *
 * Ablauf auf io: Backup → Trockenlauf (Default) → --apply → bei Bedarf --zurueck.
 */
class GpKeyMerkmaleCommand extends Command
{
    private const TABELLE = 'foodalchemist_gps';

    protected $signature = 'foodalchemist:gp-key-merkmale
        {--apply : wirklich schreiben (ohne dies nur rechnen und Review-Liste schreiben)}
        {--zurueck= : Pfad zum Protokoll eines --apply-Laufs — setzt genau diese Schlüssel zurück}
        {--beispiele=25 : Anzahl Beispiele alt → neu in der Ausgabe}';

    protected $description = 'Degenerierte gp_keys („x||“) um Zustand/Pflichtangabe/Bio ergänzen; Kollisionen auf die Review-Liste statt zusammenführen';

    public function handle(GpNamingService $naming): int
    {
        if ($this->option('apply') && $this->option('zurueck') !== null) {
            $this->error('--apply und --zurueck schliessen sich aus.');

            return self::FAILURE;
        }
        if ($this->option('zurueck') !== null) {
            return $this->zurueck((string) $this->option('zurueck'));
        }

        $plan = $this->plane($naming);
        $this->line('Degenerierte Schlüssel: '.$plan['degeneriert'].' · neu gebildet: '.count($plan['neu'])
            .' · bleiben (kein Merkmal): '.$plan['bleibt'].' · Kollisionen (Review): '.count($plan['review']));
        foreach (array_slice($plan['neu'], 0, max(0, (int) $this->option('beispiele'))) as $z) {
            $this->line("  {$z['id']} „{$z['name']}“: {$z['alt']} → {$z['neu']}");
        }
        foreach ($plan['review'] as $r) {
            $this->warn("  Review: {$r['id']} „{$r['name']}“ {$r['alt']} → {$r['neu']} kollidiert mit ".$r['mit']);
        }
        $ordner = storage_path('app/fa-gp-key-merkmale');
        File::ensureDirectoryExists($ordner);
        $stempel = now()->format('Ymd_His');
        $review = $ordner."/{$stempel}_review.json";
        file_put_contents($review, (string) json_encode($plan['review'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Review-Liste: {$review}");

        if (! $this->option('apply')) {
            $this->info('Trockenlauf — nichts geschrieben. Mit --apply schreiben (vorher Backup).');

            return self::SUCCESS;
        }
        $protokoll = $ordner."/{$stempel}_protokoll.json";
        file_put_contents($protokoll, (string) json_encode([
            'zeitpunkt' => now()->toIso8601String(),
            'gps' => array_map(fn ($z) => ['id' => $z['id'], 'alt' => $z['alt'], 'neu' => $z['neu']], $plan['neu']),
            'zurueck' => "php artisan foodalchemist:gp-key-merkmale --zurueck={$protokoll}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $n = 0;
        foreach (array_chunk($plan['neu'], 500) as $teil) {
            DB::transaction(function () use ($teil, &$n) {
                foreach ($teil as $z) {
                    $n += DB::table(self::TABELLE)->where('id', $z['id'])->where('gp_key', $z['alt'])->update(['gp_key' => $z['neu']]);
                }
            });
        }
        $this->info("✓ {$n} Schlüssel ergänzt. Protokoll/Rückweg: {$protokoll}");

        return self::SUCCESS;
    }

    /**
     * @return array{degeneriert: int, bleibt: int, neu: list<array{id:int, name:string, alt:string, neu:string}>, review: list<array<string,mixed>>}
     */
    public function plane(GpNamingService $naming): array
    {
        $geloescht = Schema::hasColumn(self::TABELLE, 'deleted_at');
        $kandidaten = [];
        foreach (DB::table(self::TABELLE)->where('gp_key', 'like', '%|')->when($geloescht, fn ($q) => $q->whereNull('deleted_at'))->orderBy('id')->cursor() as $g) {
            if (! GpNamingService::istDegeneriert((string) $g->gp_key)) {
                continue;
            }
            $in = [
                'processing' => $g->processing ?? null, 'form' => $g->form ?? null, 'flavor' => $g->flavor ?? null, 'zusatz' => $g->zusatz ?? null,
                'pflichtangabe' => $g->pflichtangabe ?? null, 'portion' => $g->portion ?? null, 'condition' => $g->condition ?? null,
                'commodity_group_code' => $g->commodity_group_code ?? null, 'herkunft_land' => $g->herkunft_land ?? null,
                'msc' => (bool) ($g->namens_msc ?? false), 'asc' => (bool) ($g->namens_asc ?? false), 'bio' => ($g->bio ?? null) !== null && $g->bio !== '',
            ];
            // Der alte Schlüssel bleibt vorne stehen, nur der Slot kommt dazu: so bleibt er als Präfix erkennbar (Alias im GP-Paket-Import) und ein
            // Schlüssel, der nicht zum heutigen Namen passt (umbenannte GPs), wird nicht neu erfunden.
            $slot = $naming->merkmalSlot(array_map(fn ($v) => $v === '' ? null : $v, $in), true);
            $kandidaten[] = ['id' => (int) $g->id, 'team' => $g->team_id === null ? null : (int) $g->team_id, 'name' => (string) $g->name,
                'alt' => (string) $g->gp_key, 'neu' => $slot === '' ? (string) $g->gp_key : $g->gp_key.'|m-'.$slot];
        }
        $aendern = array_values(array_filter($kandidaten, fn ($k) => $k['neu'] !== $k['alt']));

        // Kollisionen: neuer Schlüssel existiert schon im selben Bereich (Team bzw. global, alle Zeilen) oder zwei Kandidaten fallen zusammen
        $bereich = fn (?int $t) => $t === null ? 'global' : 'team '.$t;
        $belegt = [];
        foreach (array_chunk(array_values(array_unique(array_column($aendern, 'neu'))), 500) as $teil) {
            foreach (DB::table(self::TABELLE)->whereIn('gp_key', $teil)->get(['id', 'team_id', 'gp_key', 'name']) as $r) {
                $belegt[$bereich($r->team_id === null ? null : (int) $r->team_id).'#'.$r->gp_key] = "{$r->id} „{$r->name}“";
            }
        }
        $gruppen = [];
        foreach ($aendern as $k) {
            $gruppen[$bereich($k['team']).'#'.$k['neu']][] = $k;
        }
        $neu = [];
        $review = [];
        foreach ($gruppen as $schluessel => $ks) {
            $mit = $belegt[$schluessel] ?? null;
            if ($mit === null && count($ks) === 1) {
                $neu[] = ['id' => $ks[0]['id'], 'name' => $ks[0]['name'], 'alt' => $ks[0]['alt'], 'neu' => $ks[0]['neu']];

                continue;
            }
            foreach ($ks as $k) {
                $andere = array_map(fn ($o) => "{$o['id']} „{$o['name']}“", array_filter($ks, fn ($o) => $o['id'] !== $k['id']));
                $review[] = ['id' => $k['id'], 'name' => $k['name'], 'team_id' => $k['team'], 'alt' => $k['alt'], 'neu' => $k['neu'],
                    'mit' => implode(', ', array_filter([$mit, ...$andere]))];
            }
        }
        usort($neu, fn ($a, $b) => $a['id'] <=> $b['id']);

        return ['degeneriert' => count($kandidaten), 'bleibt' => count($kandidaten) - count($aendern), 'neu' => $neu, 'review' => $review];
    }

    private function zurueck(string $pfad): int
    {
        $daten = is_file($pfad) ? json_decode((string) file_get_contents($pfad), true) : null;
        if (! is_array($daten) || ! isset($daten['gps'])) {
            $this->error("Protokoll nicht lesbar: {$pfad}");

            return self::FAILURE;
        }
        $n = 0;
        DB::transaction(function () use ($daten, &$n) {
            foreach ($daten['gps'] as $z) {
                $n += DB::table(self::TABELLE)->where('id', (int) $z['id'])->where('gp_key', $z['neu'])->update(['gp_key' => $z['alt']]);
            }
        });
        $this->info("✓ {$n} Schlüssel zurückgesetzt.");

        return self::SUCCESS;
    }
}
