<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\Planung\VokabularCodes;
use Symfony\Component\Uid\UuidV7;

/**
 * Code-Qualität 2.5b · additiv und idempotent: Code-Spalte an Saison, Einsatzmoment und Eventtyp, neues Vokabular „Service-Art“
 * (Tellerservice, Buffet, Flying, Stehempfang, Boxed; global, getrennt von den Darreichungs-Servierformen), Codes aus dem Namen
 * nachgetragen ({@see VokabularCodes}). Trockenlauf vorher: `php artisan foodalchemist:vokabular-codes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_seasons', 'foodalchemist_service_moments', 'foodalchemist_event_types'] as $tabelle) {
            if (Schema::hasTable($tabelle) && ! Schema::hasColumn($tabelle, 'code')) {
                Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                    $table->string('code', 64)->nullable()->after('name');
                    $table->index(['team_id', 'code'], 'fa_' . str_replace('foodalchemist_', '', $tabelle) . '_team_code_idx');
                });
            }
        }

        if (! Schema::hasTable('foodalchemist_service_arten')) {
            Schema::create('foodalchemist_service_arten', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('name');
                $table->string('code', 64)->nullable();
                $table->integer('sort_order')->default(100);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'name'], 'fa_service_arten_team_name_unique');
                $table->index(['team_id', 'code'], 'fa_service_arten_team_code_idx');
            });
        }
        $i = 0;
        foreach (VokabularCodes::SERVICE_ARTEN as $code => $name) {
            $i += 10;
            if (! DB::table('foodalchemist_service_arten')->whereNull('team_id')->where('code', $code)->exists()) {
                DB::table('foodalchemist_service_arten')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'name' => $name,
                    'code' => $code, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        VokabularCodes::fuellen();
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_service_arten');
        foreach (['foodalchemist_seasons', 'foodalchemist_service_moments', 'foodalchemist_event_types'] as $tabelle) {
            if (Schema::hasColumn($tabelle, 'code')) {
                Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                    $table->dropIndex('fa_' . str_replace('foodalchemist_', '', $tabelle) . '_team_code_idx');
                    $table->dropColumn('code');
                });
            }
        }
    }
};
