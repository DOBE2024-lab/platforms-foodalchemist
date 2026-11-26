<?php

namespace Platform\FoodAlchemist\Services\GrammaturUmstellung;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Plan 98 B · Grammatur-Umstellung: wacht über jeden Schreibzugriff des Laufs (freigegeben vom Kurator 23.11.2026).
 *
 * - Phase 1 (Umbau): nur Darreichungen, Deltas und am Rezept die Spalten sales_unit_count / sales_quantity_per_unit_g der
 *   umgestellten Standards.
 * - Phase 2 (Neuberechnung über den Normalweg): die Erlaubt-Liste unten; Audits und Activity-Log nur INSERT.
 * - Infrastruktur ({@see infrastrukturTabellen}): der Datenbank-Cache des Frameworks (`cache`, `cache_locks`) ist in jeder Phase
 *   erlaubt. Auf io läuft CACHE_STORE=database; jedes Cache::remember/lock im Normalweg schreibt dorthin (Trockenlauf 23.11.: Abbruch
 *   in Phase 0 wegen DELETE/INSERT auf `cache`). Das ist kein fachlicher Schreibzugriff und wird mit der Transaktion zurückgerollt.
 *   Gezählt wird es trotzdem ({@see anweisungen}).
 * - Warnungen: nur die bekannten Meldungen des Recompute-Wegs zählen ({@see WARN_PRAEFIXE}) — eine fremde Deprecation kippt
 *   den Lauf nicht, ein geschluckter Fehler der Kaskade schon.
 *
 * Es wird nie geworfen (der Normalweg schluckt Ausnahmen in try/catch): Verstöße werden gesammelt, der Lauf prüft sie nach
 * jeder Phase und rollt dann zurück.
 */
final class SchreibWache
{
    public const PHASE1 = [
        'foodalchemist_recipe_presentations' => ['update'],
        'foodalchemist_recipe_presentation_deltas' => ['insert', 'update'],
        'foodalchemist_recipes' => ['update'],
        // Delta- und Darreichungsmodell tragen LogsActivity: jedes Speichern protokolliert (nur INSERT)
        'activity_log_activities' => ['insert'],
    ];

    public const PHASE1_REZEPT_SPALTEN = ['sales_unit_count', 'sales_quantity_per_unit_g', 'updated_at'];

    public const PHASE2 = [
        'foodalchemist_recipes' => ['update'],
        'foodalchemist_recipe_presentations' => ['update'],
        'foodalchemist_price_change_audits' => ['insert'],
        'foodalchemist_concepts' => ['update'],
        'foodalchemist_offers' => ['update'],
        'foodalchemist_recipe_team_kosten' => ['insert', 'update'],
        'foodalchemist_recipe_profile' => ['insert', 'update'],
        'foodalchemist_recipe_profile_anker' => ['insert', 'delete'],
        'activity_log_activities' => ['insert'],
    ];

    /** Warn-/Fehlermeldungen des Recompute-Wegs (RecipeRecomputeService::recomputeMany und seine Listener). */
    public const WARN_PRAEFIXE = [
        'Recompute-Propagation', 'Preis-Kaskade', 'Aromenprofil nach Recompute', 'Grammatur-Umstellung',
    ];

    private ?string $phase = null;

    /** @var array<string, array<int, true>> erlaubte Rezept-Ids in Phase 1 */
    private array $rezeptIdsPhase1 = [];

    /** @var list<string> */
    private array $verstoesse = [];

    /** @var array<string, array<string, array<string, int>>> phase → tabelle → op → Anweisungen */
    private array $anweisungen = [];

    private bool $aktiv = false;

    public function starten(): void
    {
        $this->aktiv = true;
        if ($this->registriert) {
            return;
        }
        $this->registriert = true;
        DB::listen(fn (QueryExecuted $q) => $this->aktiv && $this->phase !== null ? $this->pruefe($q->sql, $q->bindings) : null);
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            if (! $this->aktiv || $this->phase === null || ! in_array($m->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }
            foreach (self::WARN_PRAEFIXE as $p) {
                if (str_starts_with($m->message, $p)) {
                    $this->verstoesse[] = "Phase {$this->phase}: Warnung des Recompute-Wegs — {$m->message}";
                }
            }
        });
    }

    private bool $registriert = false;

    public function beenden(): void
    {
        $this->aktiv = false;
        $this->phase = null;
    }

    /** @param  list<int>  $rezeptIds  Rezepte, deren Kopf Phase 1 schreiben darf */
    public function phase(?string $phase, array $rezeptIds = []): void
    {
        $this->phase = $phase;
        $this->rezeptIdsPhase1 = array_fill_keys(array_map('intval', $rezeptIds), true);
    }

    public function verstoss(string $text): void
    {
        $this->verstoesse[] = $text;
    }

    /** @return list<string> */
    public function verstoesse(): array
    {
        return $this->verstoesse;
    }

    /** @return array<string, array<string, array<string, int>>> */
    public function anweisungen(): array
    {
        return $this->anweisungen;
    }

    /** Öffentlich für den Test der Regeln ohne Datenbank. */
    public function pruefe(string $sql, array $bindings): void
    {
        if (! preg_match('/^\s*(insert(?:\s+or\s+\w+)?\s+into|replace\s+into|update|delete\s+from)\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            return;   // Lesen, Savepoints, Pragmas
        }
        $op = strtolower(strtok($m[1], ' '));
        $op = $op === 'replace' ? 'insert' : $op;
        $tabelle = $m[2];
        $this->anweisungen[$this->phase][$tabelle][$op] = ($this->anweisungen[$this->phase][$tabelle][$op] ?? 0) + 1;
        if (in_array($tabelle, self::infrastrukturTabellen(), true)) {
            return;
        }

        $erlaubt = ($this->phase === '1' ? self::PHASE1 : self::PHASE2)[$tabelle] ?? null;
        if ($erlaubt === null || ! in_array($op, $erlaubt, true)) {
            $this->verstoesse[] = "Phase {$this->phase}: ".strtoupper($op)." auf {$tabelle} nicht erlaubt — ".mb_substr($sql, 0, 160);

            return;
        }
        if ($this->phase === '1' && $tabelle === 'foodalchemist_recipes') {
            $this->pruefeRezeptKopf($sql, $bindings);
        }
    }

    /**
     * Tabellen des Framework-Caches (Store `database`), Namen aus der Konfiguration samt Standardnamen. Andere Stores (redis, file,
     * array) schreiben nicht in die Datenbank und brauchen keinen Eintrag.
     *
     * @return list<string>
     */
    public static function infrastrukturTabellen(): array
    {
        return array_values(array_unique(array_filter([
            'cache', 'cache_locks',
            (string) config('cache.stores.database.table', 'cache'),
            (string) config('cache.stores.database.lock_table', 'cache_locks'),
        ])));
    }

    private function pruefeRezeptKopf(string $sql, array $bindings): void
    {
        preg_match('/\bset\s+(.*?)\s+where\s+(.*)$/is', $sql, $teile);
        preg_match_all('/[`"]?(\w+)[`"]?\s*=\s*\?/', $teile[1] ?? '', $spalten);
        $fremd = array_diff($spalten[1], self::PHASE1_REZEPT_SPALTEN);
        if ($fremd !== []) {
            $this->verstoesse[] = 'Phase 1: Rezept-Spalten außerhalb der Whitelist — '.implode(', ', $fremd);
        }
        // Erwartete Form: … where "id" = ? (Bindung nach den SET-Werten)
        $id = preg_match('/^[`"]?id[`"]?\s*=\s*\?\s*$/i', trim($teile[2] ?? '')) ? (int) ($bindings[count($spalten[1])] ?? 0) : null;
        if ($id === null || ! isset($this->rezeptIdsPhase1[$id])) {
            $this->verstoesse[] = 'Phase 1: Rezept-Kopf außerhalb der umgestellten Standards — '.mb_substr($sql, 0, 160);
        }
    }
}
