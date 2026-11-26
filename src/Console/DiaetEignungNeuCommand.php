<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;

/**
 * Plan 98 · Diät-Fix: rechnet die Diät-/Herkunfts-Flags (spec_is_* / spec_contains_*) des Bestands nach der neuen Regel
 * neu — leeres Rezept, KI-Vorschlag unter 0.85 und unbewertete Deklaration sichern nichts mehr zu, Milch ⇒ nicht laktosefrei.
 *
 * Ohne Flag: Trockenlauf in einer Transaktion, die zurückgerollt wird. `--apply` schreibt und legt ein Journal ab,
 * `--rueckweg=<journal>` stellt die alten Werte wieder her. Allergene, Kosten und Darreichungen werden NICHT neu gerechnet,
 * es läuft keine Preis-Kaskade und kein KI-Aufruf. Kandidaten: Rezepte mit einer Zusicherung = 1 oder ohne gezählte
 * Pflicht-Zutat. Mehrere Runden, bis nichts mehr kippt — so erreicht ein geändertes Sub-Rezept auch Gericht und Eltern.
 */
class DiaetEignungNeuCommand extends Command
{
    protected $signature = 'foodalchemist:diaet-eignung-neu
        {--apply : schreiben (ohne Flag nur Trockenlauf mit Rollback)}
        {--team= : nur Rezepte dieses Teams}
        {--rezept=* : nur diese Rezept-Ids}
        {--rueckweg= : Journal-Datei eines --apply-Laufs zurückspielen}
        {--journal-ordner= : Ablage der Journale (Standard: storage/app/foodalchemist/diaet-eignung)}';

    protected $description = 'Plan 98: Diät-Eignung nach der Regel „ohne bewertete Deklaration unbekannt“ neu rechnen (Trockenlauf, Journal, Rückweg).';

    private const MAX_RUNDEN = 10;

    private const ZUSICHERUNG = ['spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_lactose_free'];

    public function handle(RecipeRecomputeService $recompute): int
    {
        if (($datei = $this->option('rueckweg')) !== null) {
            return $this->rueckweg((string) $datei);
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLY' : 'TROCKENLAUF').' — Diät-Eignung neu rechnen');

        $journal = [];
        $grund = [];
        DB::beginTransaction();
        try {
            $ids = $this->kandidaten();
            $this->line(count($ids).' Kandidaten.');
            for ($runde = 1; $runde <= self::MAX_RUNDEN; $runde++) {
                $geaendert = 0;
                foreach ($ids as $id) {
                    $r = $recompute->specFlagsNeuRechnen($id, true);
                    if ($r === null) {
                        continue;
                    }
                    $geaendert++;
                    $journal[$id] ??= $r['alt'];                   // erster Stand vor dem Lauf
                    $grund[$id] = $r['neu'];
                }
                $this->line("Runde {$runde}: {$geaendert} geändert.");
                if ($geaendert === 0) {
                    break;
                }
            }
            $bericht = $this->bericht($journal, $grund);
            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ABGEBROCHEN, alles zurückgerollt: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->ausgeben($bericht);
        if ($apply && $journal !== []) {
            $ordner = (string) ($this->option('journal-ordner') ?: storage_path('app/foodalchemist/diaet-eignung'));
            $pfad = $ordner.'/journal-'.now()->format('Ymd-His').'.json';
            if (! (is_dir($ordner) || @mkdir($ordner, 0775, true)) || @file_put_contents($pfad, json_encode($journal, JSON_PRETTY_PRINT)) === false) {
                $this->error('Geschrieben, aber ohne Journal — Rückweg nur über das Datenbank-Backup.');

                return self::FAILURE;
            }
            $this->info("Journal: {$pfad} (Rückweg: --rueckweg={$pfad})");
        }
        $this->info($apply ? 'Geschrieben.' : 'Trockenlauf zurückgerollt. Mit --apply ausführen.');

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function kandidaten(): array
    {
        $q = $this->rezepte()
            ->where(function ($q) {
                foreach (self::ZUSICHERUNG as $f) {
                    $q->orWhere($f, true);
                }
                $q->orWhereRaw('COALESCE(spec_n_total, 0) = 0');
            });
        if (($team = $this->option('team')) !== null) {
            $q->where('team_id', (int) $team);
        }
        if (($nur = array_filter(array_map('intval', (array) $this->option('rezept')))) !== []) {
            $q->whereIn('id', $nur);
        }

        return $q->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function rezepte()
    {
        return FoodAlchemistRecipe::withoutGlobalScope(OhneFassungenScope::class)->ohneTeamSicht('recompute');
    }

    /**
     * Je geändertem Rezept der Grund des Wegfalls (für den Bericht an Dominique):
     * leer · deckelung (Zutaten da, Deklaration unbewertet) · milch · sonst (KI-Vorschlag < 0.85 oder vererbt).
     */
    private function bericht(array $journal, array $neu): array
    {
        $zeilen = [];
        $meta = $this->rezepte()->whereIn('id', array_keys($journal))
            ->get(['id', 'team_id', 'name', 'spec_n_total', 'allergens_confidence', 'allergen_milk'])->keyBy('id');
        foreach ($journal as $id => $alt) {
            $m = $meta[$id];
            $wechsel = [];
            foreach ($alt as $f => $v) {
                if ($v !== $neu[$id][$f]) {
                    $wechsel[] = sprintf('%s %s→%s', substr($f, 5), $this->wert($v), $this->wert($neu[$id][$f]));
                }
            }
            $grund = match (true) {
                (int) $m->spec_n_total === 0 => 'leer',
                ($neu[$id]['spec_is_lactose_free'] ?? null) === false && ($alt['spec_is_lactose_free'] ?? null) !== false
                    && in_array($m->allergen_milk, ['enthalten', 'spuren'], true) && count($wechsel) === 1 => 'milch',
                ! in_array($m->allergens_confidence, ['low', 'medium', 'high'], true) => 'deckelung',
                default => 'sonst',
            };
            $zeilen[] = ['id' => $id, 'team_id' => (int) $m->team_id, 'name' => (string) $m->name, 'grund' => $grund, 'wechsel' => $wechsel];
        }

        return $zeilen;
    }

    private function ausgeben(array $zeilen): void
    {
        foreach ($zeilen as $z) {
            $this->line(sprintf('%-9s R%d T%d %s · %s', strtoupper($z['grund']), $z['id'], $z['team_id'], $z['name'], implode(', ', $z['wechsel'])));
        }
        $this->line('Je Grund und Team:');
        foreach (collect($zeilen)->groupBy('grund') as $grund => $g) {
            $this->line(sprintf('    %-9s %s', $grund, $g->countBy('team_id')->map(fn ($n, $t) => "T{$t}: {$n}")->implode(', ')));
        }
        $this->info(count($zeilen).' Rezepte mit geänderter Diät-Eignung.');
    }

    private function rueckweg(string $datei): int
    {
        $journal = json_decode((string) @file_get_contents($datei), true);
        if (! is_array($journal)) {
            $this->error("Journal nicht lesbar: {$datei}");

            return self::FAILURE;
        }
        DB::transaction(function () use ($journal) {
            foreach ($journal as $id => $alt) {
                $this->rezepte()->whereKey((int) $id)->update($alt);
            }
        });
        $this->info('Rückweg: '.count($journal).' Rezepte auf die alten Diät-Flags zurückgesetzt.');

        return self::SUCCESS;
    }

    private function wert(?bool $v): string
    {
        return $v === null ? '?' : ($v ? '1' : '0');
    }
}
