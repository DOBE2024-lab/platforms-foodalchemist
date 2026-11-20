<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * Etappe 1 / P3 — GP-Allergen-Metadaten-Backfill.
 *
 * Persistiert die on-read gerechnete Allergen-Aggregation als GP-Metadaten:
 * NUR `allergens_confidence` (kategorial→numerisch), `allergens_source`,
 * `allergens_aggregated_at`. Die 14 `allergen_*`-WERT-Spalten sind die OVERRIDE-
 * Schicht und werden NIE geschrieben (sonst würde jeder GP als Override eingefroren
 * und die Derivat-LIVE-Vererbung + „LA fixen → GP heilt"-Kaskade zerstört).
 *
 * Respektiert `allergens_source='manual'` (menschliche Kuratierung bleibt unangetastet).
 * Derivate erben die Konfidenz LIVE von der Mutter (source='derivat_inherited').
 * Konflikte (enthalten↔nicht_enthalten ohne spuren) → Sammel-Signal in die Inbox.
 *
 * Default = dry-run; --apply schreibt. Idempotent. Unabhängig vom Recompute
 * (Rezepte lösen Allergene ohnehin live über die LAs auf).
 */
class GpAllergenBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:gp-allergen-backfill
        {--team= : Team-ID (default: alle Teams)}
        {--chunk=500 : Chunk-Größe}
        {--apply : Metadaten schreiben; ohne = dry-run (nur zählen)}';

    protected $description = 'P3: persistiert GP-Allergen-Konfidenz/-Quelle (nur Metadaten, nie die Wert-Spalten).';

    public function handle(GpAggregateService $agg, SignalService $signals): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(50, (int) $this->option('chunk'));

        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen (vorher Backup!).');
        }

        foreach ($teams as $team) {
            $stats = ['high' => 0, 'medium' => 0, 'low' => 0, 'none' => 0];
            $derivate = 0;
            $konflikt = 0;

            // menschlich/KI kuratierte Quellen bleiben unangetastet (Provenienz-Schutz)
            $manuellUebersprungen = FoodAlchemistGp::visibleToTeam($team)->lebensmittel()
                ->where('status', 'approved')->whereIn('allergens_source', ['manual', 'ki'])->count();

            FoodAlchemistGp::visibleToTeam($team)->lebensmittel()
                ->where('status', 'approved')
                ->where(fn ($w) => $w->whereNull('allergens_source')->orWhereNotIn('allergens_source', ['manual', 'ki']))
                ->orderBy('id')
                ->chunkById($chunk, function ($gps) use (&$stats, &$derivate, &$konflikt, $agg, $apply) {
                    foreach ($gps as $gp) {
                        // Single source: Write-/Derivat-/Provenienz-Logik lebt im Service (auch SignalFixService nutzt sie).
                        $r = $agg->backfillAllergenKonfidenz($gp, $apply);
                        if ($r['source'] === 'derivat') {
                            $derivate++;
                        }
                        $stats[$r['confidence']] = ($stats[$r['confidence']] ?? 0) + 1;
                        if ($r['needs_review']) {
                            $konflikt++;
                        }
                    }
                }, 'id');

            $bearbeitet = array_sum($stats);
            $this->info("Team {$team->id} ({$team->name}) — {$bearbeitet} approved-GPs" . ($apply ? ' aggregiert:' : ' (dry-run):'));
            $this->table(
                ['Konfidenz', 'Anzahl'],
                [
                    ['high', $stats['high']],
                    ['medium', $stats['medium']],
                    ['low (inkl. Konflikt)', $stats['low']],
                    ['none (keine LA-Daten)', $stats['none']],
                    ['— davon Derivat (Mutter-Erbe)', $derivate],
                    ['— Konflikt (enthalten↔nicht) → Signal', $konflikt],
                    ['manuell übersprungen', $manuellUebersprungen],
                ]
            );

            // Konflikte meldet seit dem Detektor `gp_allergen_widerspruch` (SignalDetektorService) EIN Signal mit Beleg
            // je GP. Das frühere Summen-Signal von hier (`dq-gp-allergen-konflikt`) war eine Dublette ohne auflösbare
            // Objekte — ein noch offenes wird beim nächsten --apply geschlossen.
            if ($apply) {
                $signals->schliesseGemessen($team, SignalTyp::DatenqualitaetGpLa, 'dq-gp-allergen-konflikt', 'data-quality',
                    'Ersetzt durch „Grundprodukte mit Allergen-Widerspruch“ (Detektor, mit Beleg je Grundprodukt)');
            }
            if ($konflikt > 0) {
                $this->line("  → {$konflikt} GPs mit Konflikt; gemeldet werden sie vom Detektor (gp_allergen_widerspruch).");
            }
        }

        return self::SUCCESS;
    }
}
