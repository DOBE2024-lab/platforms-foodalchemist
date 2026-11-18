<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            $table->string('name', 120)->nullable();
            // null = unveränderter Bestand; [] = bewusst keine aktive Arbeit.
            $table->json('arbeitszeiten')->nullable();
            $table->unsignedBigInteger('ausgabe_station_id')->nullable();
            $table->boolean('menge_vom_standard')->default(false);
            $table->decimal('komponenten_referenz_g', 12, 3)->nullable();
            $table->index(['recipe_id', 'serving_form_id'], 'fa_darreichung_rezept_form');
            $table->dropUnique('fa_recipe_darreichungen_recipe_form_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('foodalchemist_recipe_presentations')->select('recipe_id', 'serving_form_id')
            ->groupBy('recipe_id', 'serving_form_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new \RuntimeException('Rückbau nicht möglich: mehrere Varianten derselben Servierform. Zuerst mit Backup fachlich auflösen.');
        }
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            $table->unique(['recipe_id', 'serving_form_id'], 'fa_recipe_darreichungen_recipe_form_unique');
            $table->dropIndex('fa_darreichung_rezept_form');
        });
        Schema::table('foodalchemist_recipe_presentations', fn (Blueprint $table) => $table->dropColumn(['name', 'arbeitszeiten', 'ausgabe_station_id', 'menge_vom_standard', 'komponenten_referenz_g']));
    }
};
