<?php

namespace Platform\FoodAlchemist\Support\Datenmodell;

use Illuminate\Support\Facades\DB;

/**
 * Plan 98 · 3.5 · Originalgetreuer Tabellen-Umbau für SQLite (nur Testbasis; io ist MySQL).
 *
 * SQLite kann Fremdschlüssel nicht nachträglich anlegen oder entfernen. Laravels eigener Umbau baut die Tabelle aus seinen Metadaten
 * neu und verliert dabei, was er nicht abbildet — u. a. die Bedingung partieller Indizes (CI 45984393860: aus
 * `fa_recipe_darreichungen_ein_standard … WHERE is_standard = 1 AND deleted_at IS NULL` wurde ein schlichtes UNIQUE(recipe_id)).
 *
 * Hier stattdessen das 12-Schritte-Verfahren aus der SQLite-Doku: das ORIGINAL-`CREATE TABLE` aus sqlite_master wird nur um die
 * gewünschte Änderung umgeschrieben, die Daten werden kopiert, Indizes und Trigger mit ihrem ORIGINAL-SQL neu angelegt, am Ende
 * `PRAGMA foreign_key_check`. FK-Prüfung während des Umbaus aus — das geht nur außerhalb einer Transaktion, deshalb laufen die
 * Umbau-Migrationen mit `$withinTransaction = false` (auf MySQL ohne Wirkung: DDL ist dort nicht transaktional).
 */
final class SqliteTabellenUmbau
{
    public static function aktiv(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** @param callable(string): string $aendern bekommt das CREATE-TABLE-SQL, liefert das neue */
    public static function umbauen(string $tabelle, callable $aendern): void
    {
        $alt = (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$tabelle])?->sql;
        if ($alt === '') {
            throw new \RuntimeException("SQLite-Umbau: Tabelle {$tabelle} nicht gefunden.");
        }
        $neu = $aendern($alt);
        if ($neu === $alt) {
            return;
        }
        $beiwerk = DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index', 'trigger') AND sql IS NOT NULL", [$tabelle]);
        $spalten = implode(', ', array_map(fn ($c) => '"'.$c->name.'"', DB::select('PRAGMA table_info("'.$tabelle.'")')));
        $temp = '__fa_umbau_'.$tabelle;
        $fkAn = (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        if ($fkAn && DB::transactionLevel() > 0) {
            // In einer Transaktion wirkt `PRAGMA foreign_keys = OFF` nicht; das DROP TABLE löste dann FK-Verstöße verweisender Zeilen aus.
            throw new \RuntimeException("SQLite-Umbau von {$tabelle} in einer Transaktion: Migration mit `public \$withinTransaction = false;` ausführen.");
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $create = preg_replace('/^CREATE TABLE\s+("?)'.preg_quote($tabelle, '/').'\1/i', 'CREATE TABLE "'.$temp.'"', $neu, 1, $ersetzt);
            if ($ersetzt !== 1) {
                throw new \RuntimeException("SQLite-Umbau: CREATE TABLE für {$tabelle} nicht erkannt.");
            }
            DB::statement($create);
            DB::statement('INSERT INTO "'.$temp.'" ('.$spalten.') SELECT '.$spalten.' FROM "'.$tabelle.'"');
            DB::statement('DROP TABLE "'.$tabelle.'"');
            DB::statement('ALTER TABLE "'.$temp.'" RENAME TO "'.$tabelle.'"');
            foreach ($beiwerk as $b) {
                DB::statement($b->sql);
            }
            $verstoss = DB::select('PRAGMA foreign_key_check("'.$tabelle.'")');
            if ($verstoss !== []) {
                throw new \RuntimeException('SQLite-Umbau: '.count($verstoss)." FK-Verstöße in {$tabelle} nach dem Umbau.");
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = '.($fkAn ? 'ON' : 'OFF'));
        }
    }

    /** CREATE-TABLE-SQL um eine FK-Klausel ergänzen (Format wie Laravel). */
    public static function mitFk(string $sql, string $spalte, string $ziel, string $beimLoeschen): string
    {
        $pos = strrpos($sql, ')');

        return substr($sql, 0, $pos).', foreign key("'.$spalte.'") references "'.$ziel.'"("id") on delete '.$beimLoeschen.substr($sql, $pos);
    }

    /** FK-Klausel(n) einer Spalte aus dem CREATE-TABLE-SQL entfernen (Tabellen-Klausel, egal welches Ziel und welche Aktion). */
    public static function ohneFk(string $sql, string $spalte): string
    {
        return (string) preg_replace(
            '/,\s*(constraint\s+"?[\w]+"?\s+)?foreign key\s*\(\s*"?'.preg_quote($spalte, '/').'"?\s*\)\s*references\s*"?[\w.]+"?\s*\(\s*"?\w+"?\s*\)(\s+on\s+(delete|update)\s+(set null|set default|cascade|restrict|no action))*/i',
            '', $sql);
    }
}
