<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CQ price_incomplete (PR A): Merker am Konzept-Preis-Cache, dass der gespeicherte Preis nur die Summe der BEKANNTEN Positionen
 * ist (≥1 Position ohne VK, auch in einem eingebetteten Paket). Geschrieben nur von ConceptService::refreshCache, gelesen über
 * ConceptPreisService::preisWerte. Ein manuell gesetzter Konzept-Preis gilt als vollständig (Entscheid Dominique).
 *
 * null = noch nicht berechnet (Bestand bis zum Nachrechnen: `php artisan foodalchemist:konzept-preise-nachrechnen --apply`).
 * Additiv, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_concepts', 'price_incomplete')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->boolean('price_incomplete')->nullable()->after('ek_per_person_cache');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concepts', 'price_incomplete')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropColumn('price_incomplete');
            });
        }
    }
};
