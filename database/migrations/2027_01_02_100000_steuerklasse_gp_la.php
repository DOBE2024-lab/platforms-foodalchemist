<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Steuerklasse statt freier MwSt-Zahl (Entscheid Dominique 16.11.2026): GP und Lieferantenartikel tragen einen Schlüssel
 * `vat_profile_key` (regulaer | ermaessigt | ohne), der Prozentsatz kommt aus den Team-Einstellungen (vat_defaults, wie bei
 * Aufschlagsklassen und Darreichungen). Ändert sich ein Steuersatz, wird er einmal in den Einstellungen gepflegt.
 * Das GP belegt die Klasse vor, der Artikel kann abweichen (NULL = erbt vom GP). Die Necta-Zahl `supplier_items.vat` bleibt
 * als Lieferantenangabe zum Abgleich. Additiv, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_gps', 'foodalchemist_supplier_items'] as $tabelle) {
            if (Schema::hasTable($tabelle) && ! Schema::hasColumn($tabelle, 'vat_profile_key')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->string('vat_profile_key', 16)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_gps', 'foodalchemist_supplier_items'] as $tabelle) {
            if (Schema::hasTable($tabelle) && Schema::hasColumn($tabelle, 'vat_profile_key')) {
                Schema::table($tabelle, fn (Blueprint $table) => $table->dropColumn('vat_profile_key'));
            }
        }
    }
};
