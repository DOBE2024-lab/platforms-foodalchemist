<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code-Qualität 1.1e (19.11.2026) · Sperr-Zeile für den Rezeptgraph: {@see \Platform\FoodAlchemist\Services\Rezept\RezeptGraphService}
 * sperrt die Zeile `rezeptgraph` per SELECT … FOR UPDATE, bevor eine Kante Zutat → Unterrezept geprüft und geschrieben wird. Eine
 * Zeilensperre endet mit Commit/Rollback der Transaktion (anders als GET_LOCK, das an der Verbindung hängt). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_graph_sperren')) {
            Schema::create('foodalchemist_graph_sperren', function (Blueprint $t) {
                $t->string('schluessel', 64)->primary();
            });
        }
        DB::table('foodalchemist_graph_sperren')->insertOrIgnore(['schluessel' => 'rezeptgraph']);
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_graph_sperren');
    }
};
