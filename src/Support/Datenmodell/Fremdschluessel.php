<?php

namespace Platform\FoodAlchemist\Support\Datenmodell;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 98 · 3.5 Schritt 5 · Fremdschlüssel der Kernkette nachziehen — idempotent und mit Waisen-Riegel.
 *
 * - Idempotent: Tabelle/Spalte fehlt oder ein FK auf (Spalte → Ziel) besteht schon ⇒ nichts tun (io hat z. B. vessel_gp_id längst).
 * - Riegel: zeigt auch nur eine Zeile ins Leere (Elternzeile fehlt), bricht die Migration mit Tabelle, Spalte und Zahl ab, statt zu
 *   löschen. Verweise auf soft-gelöschte Eltern sind erlaubt (Soft-Delete bleibt wiederherstellbar; ein FK sieht es nicht).
 *   Gleiche Zählung wie {@see \Platform\FoodAlchemist\Services\Plattform\WaisenReportService}.
 * - ON DELETE: `restrict` (Standard; die App löscht Eltern nur weich) oder `set null` (Selbstbezug Fassung/Kopie). Nie `cascade`.
 * - FK-Name nach Laravel-Standard (`{tabelle}_{spalte}_foreign`, ≤ 64 Zeichen), damit `down()` ihn über die Spalte wiederfindet.
 */
final class Fremdschluessel
{
    public const RESTRICT = 'restrict';

    public const NULL_SETZEN = 'set null';

    /** @return bool true = FK angelegt, false = übersprungen (fehlt oder besteht schon) */
    public static function ergaenze(string $tabelle, string $spalte, string $ziel, string $beimLoeschen = self::RESTRICT): bool
    {
        if (! in_array($beimLoeschen, [self::RESTRICT, self::NULL_SETZEN], true)) {
            throw new \InvalidArgumentException("ON DELETE {$beimLoeschen} ist hier nicht erlaubt (nur restrict / set null).");
        }
        if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, $spalte) || ! Schema::hasTable($ziel)) {
            return false;
        }
        if (self::besteht($tabelle, $spalte, $ziel)) {
            return false;
        }
        $waisen = self::waisen($tabelle, $spalte, $ziel);
        if ($waisen['gesamt'] > 0) {
            throw new \RuntimeException("Fremdschlüssel {$tabelle}.{$spalte} → {$ziel} nicht angelegt: {$waisen['gesamt']} Zeile(n) zeigen ins Leere, "
                ."davon {$waisen['geloescht']} selbst soft-gelöscht (der FK prüft auch die). Erst bereinigen, dann erneut migrieren.");
        }
        if (SqliteTabellenUmbau::aktiv()) {   // Testbasis: originalgetreuer Umbau statt Laravels (verliert partielle Indizes)
            SqliteTabellenUmbau::umbauen($tabelle, fn (string $sql) => SqliteTabellenUmbau::mitFk($sql, $spalte, $ziel, $beimLoeschen));

            return true;
        }
        Schema::table($tabelle, function (Blueprint $t) use ($spalte, $ziel, $beimLoeschen) {
            $fk = $t->foreign($spalte)->references('id')->on($ziel);
            $beimLoeschen === self::NULL_SETZEN ? $fk->nullOnDelete() : $fk->restrictOnDelete();
        });

        return true;
    }

    /** Rückweg: FK auf (Spalte → Ziel) entfernen, falls vorhanden. */
    public static function entferne(string $tabelle, string $spalte, string $ziel): void
    {
        if (! Schema::hasTable($tabelle) || ! self::besteht($tabelle, $spalte, $ziel)) {
            return;
        }
        if (SqliteTabellenUmbau::aktiv()) {
            SqliteTabellenUmbau::umbauen($tabelle, fn (string $sql) => SqliteTabellenUmbau::ohneFk($sql, $spalte));

            return;
        }
        // Namen aus dem Schema: nach einem Spalten-Rename trägt der FK noch den alten Namen (z. B. dish_ideas …_sales_recipe_id_foreign)
        $name = self::name($tabelle, $spalte, $ziel);
        Schema::table($tabelle, fn (Blueprint $t) => $name !== null ? $t->dropForeign($name) : $t->dropForeign([$spalte]));
        // MySQL legt für einen FK ohne passenden Index implizit einen Index mit dem FK-Namen an; dropForeign lässt ihn stehen.
        // Laravel benennt eigene Indizes nie „…_foreign“ — ein Index mit genau diesem Namen stammt also vom FK (vorher kein Index
        // auf der Spalte, sonst hätte MySQL den vorhandenen genutzt). Den nehmen wir für einen exakten Rückweg mit.
        if ($name !== null && collect(Schema::getIndexes($tabelle))->contains(fn ($i) => $i['name'] === $name)) {
            Schema::table($tabelle, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }

    public static function besteht(string $tabelle, string $spalte, string $ziel): bool
    {
        foreach (Schema::getForeignKeys($tabelle) as $fk) {
            if ($fk['columns'] === [$spalte] && self::ohneSchema($fk['foreign_table']) === $ziel) {
                return true;
            }
        }

        return false;
    }

    private static function name(string $tabelle, string $spalte, string $ziel): ?string
    {
        foreach (Schema::getForeignKeys($tabelle) as $fk) {
            if ($fk['columns'] === [$spalte] && self::ohneSchema($fk['foreign_table']) === $ziel) {
                return $fk['name'];
            }
        }

        return null;
    }

    /**
     * Zeilen, deren Verweis auf keine Elternzeile zeigt — ALLE, auch selbst soft-gelöschte: MySQL prüft beim Anlegen des FK jede Zeile.
     * (Der Waisen-Report zählt soft-gelöschte Kinder nicht mit; deshalb hier eigens.)
     *
     * @return array{gesamt: int, geloescht: int}
     */
    public static function waisen(string $tabelle, string $spalte, string $ziel): array
    {
        $q = fn () => DB::table($tabelle.' as c')->leftJoin($ziel.' as p', 'p.id', '=', 'c.'.$spalte)->whereNotNull('c.'.$spalte)->whereNull('p.id');
        $gesamt = (int) $q()->count();
        $geloescht = $gesamt > 0 && Schema::hasColumn($tabelle, 'deleted_at') ? (int) $q()->whereNotNull('c.deleted_at')->count() : 0;

        return ['gesamt' => $gesamt, 'geloescht' => $geloescht];
    }

    private static function ohneSchema(string $tabelle): string
    {
        return str_contains($tabelle, '.') ? substr($tabelle, strrpos($tabelle, '.') + 1) : $tabelle;
    }
}
