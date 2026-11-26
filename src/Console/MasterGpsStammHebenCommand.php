<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Services\GpStamm\GpNamingService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Master-GPs in den Stamm heben (Dominique 20.11.2026: „wenn ein Grundprodukt neu angelegt wird, durch die Vererbung, durch das Abo-Paket,
 * sollte es automatisch mit reingehen“; Entscheid: alle Bestands-GPs des Master-Teams, kein Schalter).
 *
 * Neue GPs des Master-Teams legt {@see \Platform\FoodAlchemist\Services\GpStamm\GpNamingService::createGp} seitdem global an. Dieses Kommando hebt den
 * BESTAND: GPs mit team_id = Master werden global (team_id NULL) und Stamm-Paket (stamm_class = stamm_paket), damit Mandanten mit Paket sie sehen.
 *
 * - IDs bleiben: Rezept-Zeilen, Darreichungen (Gefäß), Zubehör-Zeilen, LA-Strukturen und Lead-Artikel zeigen unverändert auf dieselben GPs.
 * - Der Unique-Schlüssel (team_id, gp_key) greift bei team_id NULL in MySQL NICHT: gibt es schon ein globales GP mit gleichem gp_key, wird ein
 *   degenerierter Key („tomate||“) neu gebildet ({@see schluesselKlaeren}, im Trockenlauf ausgewiesen); sonst bricht der Lauf ab, bevor etwas
 *   geschrieben wird (echte Dublette: erst zusammenführen). Mandanten-eigene GPs mit gleichem gp_key sind nur eine Warnung (Doppel beim Kunden).
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
            ->get(['id', 'name', 'gp_key', 'status', 'stamm_class', 'main_ingredient_slug', 'processing', 'form', 'flavor', 'zusatz', 'pflichtangabe',
                'portion', 'condition', 'commodity_group_code', 'herkunft_land', 'namens_msc', 'namens_asc',
                ...(Schema::hasColumn(self::TABELLE, 'deleted_at') ? ['deleted_at'] : [])]);
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

        [$neueKeys, $kollisionen] = $this->schluesselKlaeren($master, $gps);
        foreach ($neueKeys as $id => $k) {
            $g = $gps->firstWhere('id', $id);
            $this->line("  neuer gp_key für GP {$id} „{$g->name}“: {$g->gp_key} → {$k}");
        }
        $warnungen = DB::table(self::TABELLE.' as m')
            ->join(self::TABELLE.' as k', fn ($j) => $j->on('k.gp_key', '=', 'm.gp_key')->whereNotNull('k.team_id')->whereColumn('k.team_id', '!=', 'm.team_id'))
            ->where('m.team_id', $master)->whereNotIn('k.status', ['rejected', 'merged'])
            ->when(Schema::hasColumn(self::TABELLE, 'deleted_at'), fn ($q) => $q->whereNull('k.deleted_at'))
            ->get(['m.id as master_id', 'm.name', 'k.id as kunden_id', 'k.team_id']);
        foreach ($warnungen as $w) {
            $this->warn("  Doppel beim Kunden: GP {$w->master_id} „{$w->name}“ ⇄ GP {$w->kunden_id} in Team {$w->team_id} (nach dem Heben zusammenführen)");
        }
        if ($kollisionen !== []) {
            $this->error('Globales GP mit gleichem gp_key vorhanden — nichts geschrieben. Erst zusammenführen:');
            foreach ($kollisionen as $k) {
                $this->line('  '.$k);
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
            'gps' => $gps->map(fn ($g) => ['id' => (int) $g->id, 'stamm_class' => $g->stamm_class, 'gp_key' => $g->gp_key])->all(),
            'zurueck' => "php artisan foodalchemist:master-gps-stamm-heben --zurueck={$protokoll}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $n = DB::transaction(function () use ($master, $ids, $neueKeys) {
            foreach ($neueKeys as $id => $key) {
                DB::table(self::TABELLE)->where('team_id', $master)->where('id', $id)->update(['gp_key' => $key]);
            }

            return DB::table(self::TABELLE)->where('team_id', $master)->whereIn('id', $ids)
                ->update(['team_id' => null, 'stamm_class' => StammPaket::PAKET, 'updated_at' => now()]);
        });
        StammPaket::flush();
        $this->info("✓ {$n} GPs global gehoben (Stamm-Paket). Protokoll/Rückweg: {$protokoll}");

        return self::SUCCESS;
    }

    /**
     * gp_key-Kollisionen mit dem globalen Bestand (und unter den zu hebenden GPs selbst) klären (Kurator 20.11.2026, Fall 14051 ⇄ 14493: beide
     * „tomate||“, verschiedene Produkte — nicht zusammenführen). Ein degenerierter Key (nur Hauptzutat + leere Segmente) wird neu gebildet:
     * zuerst aus den Feldern wie bei der Neuanlage ({@see GpNamingService::gpKeyAusFeldern}), kollidiert der auch, aus dem Namens-Slug
     * (Bestandsformat älterer GPs, gleiche Ausweichregel wie {@see \Platform\FoodAlchemist\Services\GpStamm\GpUmhaengenService}). Abbruch nur, wenn danach
     * immer noch kollidiert oder der Key nicht degeneriert ist (dann ist es eine echte Dublette: zusammenführen).
     *
     * @return array{0: array<int, string>, 1: list<string>}  [gp_id => neuer gp_key], Kollisionen
     */
    private function schluesselKlaeren(int $master, \Illuminate\Support\Collection $gps): array
    {
        $naming = app(GpNamingService::class);
        $global = DB::table(self::TABELLE)->whereNull('team_id')->whereNotIn('status', ['rejected', 'merged'])
            ->when(Schema::hasColumn(self::TABELLE, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->whereIn('gp_key', $gps->pluck('gp_key')->all())->get(['id', 'name', 'gp_key'])->groupBy('gp_key');
        $belegt = fn (string $key) => DB::table(self::TABELLE)->whereNull('team_id')->where('gp_key', $key)->whereNotIn('status', ['rejected', 'merged'])
            ->when(Schema::hasColumn(self::TABELLE, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->exists();
        $neu = [];
        $kollisionen = [];
        $vergeben = [];   // Keys, die dieser Lauf schon vergibt (zwei Master-GPs dürfen nicht auf denselben Key fallen)
        foreach ($gps as $g) {
            $aktiv = ! in_array($g->status, ['rejected', 'merged'], true) && ($g->deleted_at ?? null) === null;
            $konflikt = $aktiv && ($global->has($g->gp_key) || isset($vergeben[$g->gp_key]));
            if (! $konflikt) {
                $aktiv && $vergeben[$g->gp_key] = true;

                continue;
            }
            $gegen = $global->get($g->gp_key)?->first();
            $gegenText = $gegen !== null ? "global {$gegen->id} „{$gegen->name}“" : 'ein anderes Master-GP';
            if (! self::istDegeneriert((string) $g->gp_key)) {
                $kollisionen[] = "Master {$g->id} „{$g->name}“ ⇄ {$gegenText} (gp_key {$g->gp_key})";

                continue;
            }
            $in = array_map(fn ($v) => $v === '' ? null : $v, [
                'processing' => $g->processing, 'form' => $g->form, 'flavor' => $g->flavor, 'zusatz' => $g->zusatz, 'pflichtangabe' => $g->pflichtangabe,
                'portion' => $g->portion, 'condition' => $g->condition, 'commodity_group_code' => $g->commodity_group_code,
                'herkunft_land' => $g->herkunft_land, 'msc' => (bool) $g->namens_msc, 'asc' => (bool) $g->namens_asc,
            ]);
            $kandidaten = array_values(array_unique(array_filter([
                $naming->gpKeyAusFeldern((string) ($g->main_ingredient_slug ?: $naming->slugify((string) $g->name)), $in),
                $naming->slugify((string) $g->name),
            ])));
            $treffer = null;
            foreach ($kandidaten as $k) {
                if ($k !== $g->gp_key && ! isset($vergeben[$k]) && ! $belegt($k)) {
                    $treffer = $k;

                    break;
                }
            }
            if ($treffer === null) {
                $kollisionen[] = "Master {$g->id} „{$g->name}“ ⇄ {$gegenText} (gp_key {$g->gp_key}, kein freier Ersatz-Key)";

                continue;
            }
            $neu[(int) $g->id] = $treffer;
            $vergeben[$treffer] = true;
        }

        return [$neu, $kollisionen];
    }

    /** Degeneriert = nur Hauptzutat und leere Segmente („tomate||“). */
    public static function istDegeneriert(string $key): bool
    {
        return preg_match('/^[^|]+\|+$/', $key) === 1;
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
                    ->update(['team_id' => (int) $daten['team_id'], 'stamm_class' => $g['stamm_class'] ?? StammPaket::BASIS,
                        ...(isset($g['gp_key']) ? ['gp_key' => $g['gp_key']] : []), 'updated_at' => now()]);
            }
        });
        StammPaket::flush();
        $this->info("✓ {$n} GPs zurück ins Team {$daten['team_id']}.");

        return self::SUCCESS;
    }
}
