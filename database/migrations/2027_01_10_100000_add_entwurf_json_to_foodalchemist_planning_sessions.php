<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Briefing mitspeichern“ (Dominique/Kurator 23.11.2026): der gespeicherte Entwurf der Planungs-Reiter — je Reiter
 * (rezept|gericht|concept) Titel, Brief, Kreativ-Modus und Leitplanken (Regler) — plus `gespeichert_at`. Geschrieben nur
 * über „Planung speichern“ (PlanningSessionService::speichereEntwurf), gelesen beim Öffnen der Planung (Planung\Brief)
 * und im MCP-GET. Additiv, nullable, kein Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_planning_sessions', 'entwurf_json')) {
                $table->json('entwurf_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_planning_sessions', 'entwurf_json')) {
            Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
                $table->dropColumn('entwurf_json');
            });
        }
    }
};
