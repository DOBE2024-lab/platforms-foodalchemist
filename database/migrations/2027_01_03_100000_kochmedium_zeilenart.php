<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nährwerte je 100 g Ausbeute + Kochmedium (Entscheid Dominique 17.11.2026, Regelwerk Basisrezepte §18.7):
 * - `foodalchemist_recipe_ingredients.zeilenart` (zutat | medium, NULL = ungesetzt): die Zeilenart „Medium“ für
 *   Kochmedien (Frittieröl, Confit-Öl, Lake, Nudelwasser). Ihre Nährstoffe zählen nur zum Anteil (1 − Garverlust);
 *   ohne Garverlust-Angabe ist die Medium-Zeile ganz verworfen (Garverlust 100 %). Kosten bleiben unverändert.
 * - `foodalchemist_gps.is_kochmedium`: Vorschlag am GP für ungesetzte Zeilen (z. B. Speisesalz, Frittierfett) —
 *   wirkt nur, wenn die Zeile einen Garverlust hat (Salz zum Würzen ohne Garverlust zählt voll).
 * Additiv, idempotent. Vorbelegung des GP-Merkmals separat: `foodalchemist:kochmedium-vorbelegen` (Trockenlauf).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_ingredients') && ! Schema::hasColumn('foodalchemist_recipe_ingredients', 'zeilenart')) {
            Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
                $table->string('zeilenart', 16)->nullable();
            });
        }
        if (Schema::hasTable('foodalchemist_gps') && ! Schema::hasColumn('foodalchemist_gps', 'is_kochmedium')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) {
                $table->boolean('is_kochmedium')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_ingredients') && Schema::hasColumn('foodalchemist_recipe_ingredients', 'zeilenart')) {
            Schema::table('foodalchemist_recipe_ingredients', fn (Blueprint $table) => $table->dropColumn('zeilenart'));
        }
        if (Schema::hasTable('foodalchemist_gps') && Schema::hasColumn('foodalchemist_gps', 'is_kochmedium')) {
            Schema::table('foodalchemist_gps', fn (Blueprint $table) => $table->dropColumn('is_kochmedium'));
        }
    }
};
