<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Art und Preis des Gefäßes (Entscheid Dominique 17.11.2026, Pilot TASTE & FLY): je Team am Gefäß-GP „Einweg, Mehrweg oder externer
 * Artikel“ plus ein Preis je Stück. einweg = Preis am GP, sonst Lead-Artikelpreis; mehrweg/extern = Preis am GP (fehlt er: Hinweis +
 * unvollständig). Die Kosten gehen wie bisher in den Wareneinsatz der Darreichung.
 *
 *  - `foodalchemist_gp_gefaess_kostenarten`: eine Zeile je Team und GP (Muster wie `gp_lagerartikel`). Dasselbe Glas kann bei Broich
 *    Mehrweg und bei einem anderen Mandanten Einweg sein. Ohne Zeile: heutiges Verhalten (Einweg zum Artikelpreis, Verleih → Miete).
 *  - `recipe_presentations.vessel_cost_type`: Art je Darreichung übersteuern (NULL = Eintrag des Teams).
 *
 * Additiv, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gp_gefaess_kostenarten')) {
            Schema::create('foodalchemist_gp_gefaess_kostenarten', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('gp_id')->index();
                $table->string('kostenart', 16);                   // einweg | mehrweg | extern
                $table->decimal('preis_stk', 12, 4)->nullable();   // € netto je Stück; einweg ohne Preis = Lead-Artikelpreis
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'gp_id']);
            });
        }
        if (Schema::hasTable('foodalchemist_recipe_presentations') && ! Schema::hasColumn('foodalchemist_recipe_presentations', 'vessel_cost_type')) {
            Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
                $table->string('vessel_cost_type', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_presentations') && Schema::hasColumn('foodalchemist_recipe_presentations', 'vessel_cost_type')) {
            Schema::table('foodalchemist_recipe_presentations', fn (Blueprint $table) => $table->dropColumn('vessel_cost_type'));
        }
        Schema::dropIfExists('foodalchemist_gp_gefaess_kostenarten');
    }
};
