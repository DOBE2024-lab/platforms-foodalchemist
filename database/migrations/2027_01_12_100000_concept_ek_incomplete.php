<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EK-Lücke am Konzept-Cache (Master-Rundgang io 26.11.): Merker, dass ek_per_person_cache nur die Summe der BEKANNTEN Positionen
 * ist (≥1 Gericht ohne EK, auch in einem eingebetteten Paket). Gegenstück zu price_incomplete: geschrieben nur von
 * ConceptService::refreshCache, gelesen von CockpitZeilen an der Paket-Zeile — damit die Lücke eines Pakets ohne Abfragen je
 * Paket ins Elternkonzept durchzieht.
 *
 * null = noch nicht berechnet; das Cockpit rechnet dann live nach (Bestand bis zum Nachrechnen:
 * `php artisan foodalchemist:konzept-preise-nachrechnen --apply`). Additiv, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_concepts', 'ek_incomplete')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->boolean('ek_incomplete')->nullable()->after('price_incomplete');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concepts', 'ek_incomplete')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropColumn('ek_incomplete');
            });
        }
    }
};
