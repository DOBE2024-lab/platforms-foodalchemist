<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platzhalter-GPs: Deklaration von Hand (Entscheid Dominique 19.11.2026, „nur bei Platzhalter“).
 *
 * Platzhalter (`is_platzhalter = 1`, z. B. „Leitungswasser (neutral)“) haben keine Lieferantenartikel. Allergene und Nährwerte haben am GP
 * schon eine Hand-Ebene (`allergen_*` als Override, `nutri_*` mit `nutri_source = manual`). Für Zusatzstoffe fehlte sie, und bei den
 * Nährwerten fehlten Zucker und gesättigte Fettsäuren:
 * - `deklaration_manuell` (JSON): {stoff: 1|3} — Roh-Domäne der Artikel-Deklaration (1 = nein, 3 = ja). Nur bei Platzhaltern gesetzt,
 *   {@see \Platform\FoodAlchemist\Services\GpStamm\PlatzhalterDeklarationService}.
 * - `nutri_sugar_g_per_100g`, `nutri_saturated_fat_g_per_100g`: ergänzen den bestehenden nutri_*-Block wie am Rezept (2026_07_02_000002).
 * Bestand: NULL. Idempotent (hasColumn je Spalte).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gps')) {
            return;
        }
        $spalten = [
            'deklaration_manuell' => fn (Blueprint $t) => $t->json('deklaration_manuell')->nullable()->comment('Nur Platzhalter: Zusatzstoffe von Hand {stoff: 1|3}'),
            'nutri_sugar_g_per_100g' => fn (Blueprint $t) => $t->decimal('nutri_sugar_g_per_100g', 8, 2)->nullable(),
            'nutri_saturated_fat_g_per_100g' => fn (Blueprint $t) => $t->decimal('nutri_saturated_fat_g_per_100g', 8, 2)->nullable(),
        ];
        foreach ($spalten as $spalte => $def) {
            if (! Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', $def);
            }
        }
    }

    public function down(): void
    {
        foreach (['deklaration_manuell', 'nutri_sugar_g_per_100g', 'nutri_saturated_fat_g_per_100g'] as $spalte) {
            if (Schema::hasTable('foodalchemist_gps') && Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropColumn($spalte));
            }
        }
    }
};
