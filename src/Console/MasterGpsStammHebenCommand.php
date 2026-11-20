<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Master-GPs in den Stamm heben (Dominique 20.11.2026: „wenn ein Grundprodukt neu angelegt wird, durch die Vererbung, durch das Abo-Paket,
 * sollte es automatisch mit reingehen“; Entscheid: alle Bestands-GPs des Master-Teams, kein Schalter).
 *
 * Neue GPs des Master-Teams legt {@see \Platform\FoodAlchemist\Services\GpNamingService::createGp} seitdem global an. Dieses Kommando hebt den
 * BESTAND: GPs mit team_id = Master werden global (team_id NULL) und Stamm-Paket (stamm_class = stamm_paket), damit Mandanten mit Paket sie sehen.
 *
 * - IDs bleiben: Rezept-Zeilen, Darreichungen (Gefäß), Zubehör-Zeilen, LA-Strukturen und Lead-Artikel zeigen unverändert auf dieselben GPs.
 * - Der Unique-Schlüssel (team_id, gp_key) greift bei team_id NULL in MySQL NICHT: gibt es schon ein globales GP mit gleichem gp_key, bricht der
 *   Lauf ab, bevor etwas geschrieben wird (erst zusammenführen). Mandanten-eigene GPs mit gleichem gp_key sind nur eine Warnung (Doppel beim Kunden).
 * - Soft-gelöschte Master-GPs gehen mit (sonst bliebe Schrott im Master hängen).
 * - Schreibt per Query-Builder (keine Modell-Ereignisse, kein fa-fremdschreiben-Log).
 *
 * Ablauf auf io: Backup → Trockenlauf (Default, listet id/Name/Status/Bezüge) → --apply → --verify. Rückweg: --zurueck=<Protokoll>.
 */
class MasterGpsStammHebenCommand extends Command
{
    private const TABELLE = 'foodalchemist_gps';

    /** Tabellen mit Verweis auf ein GP (nur Anzeige im Trockenlauf: IDs bleiben, nichts davon wird geändert). */
    public const BEZUEGE = [
        'foodalchemist_recipe_ingredients' => ['gp_id', 'Rezept-Zeilen'],
        'foodalchemist_recipe_presentations' => ['vessel_gp_id', 'Darreichungen (Gefäß)'],
        'foodalchemist_gp_gefaess_zubehoer' => ['zubehoer_gp_id', 'Zubehör-Zeilen'],
        'foodalchemist_supplier_item_structures' => ['gp_id', 'LA-Strukturen'],
    ];

    protected $signature = 'foodalchemist:master-gps-stamm-heben
        {--apply : wirklich schreiben (ohne dies nur auflisten und prüfen)}
        {--verify : nach dem Heben prüfen (keine GPs mehr im Master-Team, keine globalen gp_key-Doppel)}
        {--zurueck= : Pfad zum Protokoll eines --apply-Laufs — setzt genau diese GPs zurück ins Master-Team}';

    protected $description = 'Hebt die GPs des Master-Teams in den globalen Stamm (team_id NULL, stamm_paket), damit Mandanten mit Stamm-Paket sie sehen';

    public function handle(): int
    {
        if ((int) (bool) $this->option('apply') + (int) (bool) $this->option('verify') + (int) ($this->option('zurueck') !== null) > 1) {
            $this->error('--apply, --verify und --zurueck schliessen sich aus.');

            return self::FAILURE;
        }
        if ($this->option('zurueck') !== null) {
            return $this->zurueck((string) $this->option('zurueck'));
        }
        $master = MasterTeam::id();
        if ($master === null) {
            $this->error('Kein Master-Team konfiguriert.');

            return self::FAILURE;
        }

        return $this->option('verify') ? $this->verify($master) : $this->heben($master, (bool) $this->option('apply'));
    }

    private function heben(int $master, bool $apply): int
    {
        $gps = DB::table(self::TABELLE)->where('team_id', $master)->orderBy('name')
            ->get(['id', 'name', 'gp_key', 'status', 'stamm_class', ...(Schema::hasColumn(self::TABELLE, 'deleted_at') ? ['deleted_at'] : [])]);
        if ($gps->isEmpty()) {
            $this->info("Team {$master} trägt keine GPs — nichts zu tun.");

            return self::SUCCESS;
        }
        $ids = $gps->pluck('id')->map(fn ($i) => (int) $i)->all();
        $bezuege = [];
        foreach (self::BEZUEGE as $tabelle => [$spalte]) {
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, $spalte)) {
                continue;
            }
            $q = DB::table($tabelle)->whereIn($spalte, $ids);
            if (Schema::hasColumn($tabelle, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            foreach ($q->selectRaw("{$spalte} as gp, COUNT(*) as n")->groupBy($spalte)->get() as $z) {
                $bezuege[(int) $z->gp][$tabelle] = (int) $z->n;
            }
        }
        $this->table(['id', 'Name', 'Status', 'gelöscht', ...array_column(self::BEZUEGE, 1)], $gps->map(fn ($g) => [
            $g->id, $g->name, $g->status, ($g->deleted_at ?? null) !== null ? 'ja' : '',
            ...array_map(fn ($t) => $bezuege[(int) $g->id][$t] ?? 0, array_keys(self::BEZUEGE)),
        ])->all());

        $kollisionen = DB::table(self::TABELLE.' as m')
            ->join(self::TABELLE.' as g', fn ($j) => $j->on('g.gp_key', '=', 'm.gp_key')->whereNull('g.team_id'))
            ->where('m.team_id', $master)->whereNotIn('g.status', ['rejected', 'merged'])
            ->when(Schema::hasColumn(self::TABELLE, 'deleted_at'), fn ($q) => $q->whereNull('g.deleted_at'))
            ->get(['m.id as master_id', 'm.name as master_name', 'g.id as global_id', 'g.name as global_name']);
        $warnungen = DB::table(self::TABELLE.' as m')
            ->join(self::TABELLE.' as k', fn ($j) => $j->on('k.gp_key', '=', 'm.gp_key')->whereNotNull('k.team_id')->whereColumn('k.team_id', '!=', 'm.team_id'))
            ->where('m.team_id', $master)->whereNotIn('k.status', ['rejected', 'merged'])
            ->when(Schema::hasColumn(self::TABELLE, 'deleted_at'), fn ($q) => $q->whereNull('k.deleted_at'))
            ->get(['m.id as master_id', 'm.name', 'k.id as kunden_id', 'k.team_id']);
        foreach ($warnungen as $w) {
            $this->warn("  Doppel beim Kunden: GP {$w->master_id} „{$w->name}“ ⇄ GP {$w->kunden_id} in Team {$w->team_id} (nach dem Heben zusammenführen)");
        }
        if ($kollisionen->isNotEmpty()) {
            $this->error('Globales GP mit gleichem gp_key vorhanden — nichts geschrieben. Erst zusammenführen:');
            foreach ($kollisionen as $k) {
                $this->line("  Master {$k->master_id} „{$k->master_name}“ ⇄ global {$k->global_id} „{$k->global_name}“");
            }

            return self::FAILURE;
        }
        if (! $apply) {
            $this->info(count($ids)." GPs würden global (Stamm-Paket). Trockenlauf — nichts geschrieben. Mit --apply schreiben (vorher Backup).");

            return self::SUCCESS;
        }

        $protokoll = storage_path('logs/master-gps-stamm-heben-'.now()->format('Ymd-His').'.json');
        file_put_contents($protokoll, (string) json_encode([
            'team_id' => $master,
            'zeitpunkt' => now()->toIso8601String(),
            'gps' => $gps->map(fn ($g) => ['id' => (int) $g->id, 'stamm_class' => $g->stamm_class])->all(),
            'zurueck' => "php artisan foodalchemist:master-gps-stamm-heben --zurueck={$protokoll}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $n = DB::transaction(fn () => DB::table(self::TABELLE)->where('team_id', $master)->whereIn('id', $ids)
            ->update(['team_id' => null, 'stamm_class' => StammPaket::PAKET, 'updated_at' => now()]));
        StammPaket::flush();
        $this->info("✓ {$n} GPs global gehoben (Stamm-Paket). Protokoll/Rückweg: {$protokoll}");

        return self::SUCCESS;
    }

    private function verify(int $master): int
    {
        $rest = DB::table(self::TABELLE)->where('team_id', $master)->count();
        $doppel = DB::table(self::TABELLE)->whereNull('team_id')->whereNotIn('status', ['rejected', 'merged'])
            ->when(Schema::hasColumn(self::TABELLE, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->select('gp_key')->groupBy('gp_key')->havingRaw('COUNT(*) > 1')->pluck('gp_key');
        $this->line("GPs im Master-Team: {$rest} · globale gp_key-Doppel: {$doppel->count()}");
        foreach ($doppel->take(20) as $k) {
            $this->warn("  doppelt global: {$k}");
        }

        return $rest === 0 && $doppel->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function zurueck(string $pfad): int
    {
        $daten = is_file($pfad) ? json_decode((string) file_get_contents($pfad), true) : null;
        if (! is_array($daten) || ! isset($daten['team_id'], $daten['gps'])) {
            $this->error("Protokoll nicht lesbar: {$pfad}");

            return self::FAILURE;
        }
        $n = 0;
        DB::transaction(function () use ($daten, &$n) {
            foreach ($daten['gps'] as $g) {
                $n += DB::table(self::TABELLE)->where('id', (int) $g['id'])->whereNull('team_id')
                    ->update(['team_id' => (int) $daten['team_id'], 'stamm_class' => $g['stamm_class'] ?? StammPaket::BASIS, 'updated_at' => now()]);
            }
        });
        StammPaket::flush();
        $this->info("✓ {$n} GPs zurück ins Team {$daten['team_id']}.");

        return self::SUCCESS;
    }
}
