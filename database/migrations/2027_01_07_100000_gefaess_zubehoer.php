<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zubehör am Gefäß-Grundprodukt (Entscheid Dominique 20.11.2026, „Weg 2“): am Gefäß-GP hängt „gehört dazu: n × <anderes Non-Food-GP>“
 * (z. B. Schraubglas → 1 × Deckel 82 mm, später Etikett, Löffel). Einmal am Glas gepflegt, rechnet jede Darreichung mit diesem Gefäß das
 * Zubehör mit — jedes Teil mit eigenem Preis und eigener Kostenart (`foodalchemist_gp_gefaess_kostenarten`, gleiche Logik wie das Gefäß).
 *
 *  - je Team wie die Kostenart: es gilt die Liste des eigenen Teams, sonst die des nächsten Oberteams (bis zur Kundengrenze).
 *    Gelöschte Zeilen bleiben als Markierung stehen: ein Team, das alles entfernt hat, hat eine eigene LEERE Liste und erbt nicht mehr.
 *  - eine Ebene: Zubehör hat kein eigenes Zubehör, ein Gefäß ist nicht Zubehör (der Service verhindert Zyklen und Selbstbezug).
 *
 * Additiv, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_gp_gefaess_zubehoer')) {
            return;
        }
        Schema::create('foodalchemist_gp_gefaess_zubehoer', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('gefaess_gp_id')->index();      // das Gefäß (Non-Food-GP)
            $table->unsignedBigInteger('zubehoer_gp_id')->index();     // gehört dazu (Non-Food-GP), z. B. Deckel
            $table->decimal('menge', 10, 3)->default(1);               // Stück je Gefäß
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'gefaess_gp_id', 'zubehoer_gp_id'], 'fa_gp_gef_zub_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_gefaess_zubehoer');
    }
};
