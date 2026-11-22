<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code-Qualität 3.7 (Taxonomie Teil 2): Rollen-Flags an den Warengruppen statt Code-Literale im Code.
 * `is_getraenk`, `is_non_food`, `is_kuehl`, `is_fertig` — gelesen über Support\WgRolle.
 *
 * Backfill nach Code (globale und Team-Zeilen; maßgeblich ist der Basis-Katalog, Team-Kopien erben über den Code).
 * Die Belegung ist hier bewusst als Stand 2026-11-22 eingefroren (eine Migration ändert sich nicht mehr), sie
 * entspricht WgRolle::STANDARD. 13 (Convenience) zählt als fertig (Entscheid Dominique).
 *
 * Additiv, idempotent: Spalten nur anlegen, wenn sie fehlen; der Backfill setzt nur auf true, nimmt nie etwas weg.
 * Trockenlauf vorher: `php artisan foodalchemist:wg-rollen` (ohne --apply).
 */
return new class extends Migration
{
    private const BELEGUNG = [
        'is_getraenk' => ['15'],
        'is_non_food' => ['16'],
        'is_kuehl' => ['01', '02', '03', '04', '05', '06', '13', '14'],
        'is_fertig' => ['10', '11', '13'],
    ];

    public function up(): void
    {
        $tabelle = 'foodalchemist_lookup_commodity_groups';
        foreach (array_keys(self::BELEGUNG) as $spalte) {
            if (! Schema::hasColumn($tabelle, $spalte)) {
                Schema::table($tabelle, function (Blueprint $table) use ($spalte) {
                    $table->boolean($spalte)->default(false);
                });
            }
        }
        foreach (self::BELEGUNG as $spalte => $codes) {
            DB::table($tabelle)->whereIn('code', $codes)->where($spalte, false)->update([$spalte => true]);
        }
    }

    public function down(): void
    {
        $tabelle = 'foodalchemist_lookup_commodity_groups';
        foreach (array_keys(self::BELEGUNG) as $spalte) {
            if (Schema::hasColumn($tabelle, $spalte)) {
                Schema::table($tabelle, function (Blueprint $table) use ($spalte) {
                    $table->dropColumn($spalte);
                });
            }
        }
    }
};
