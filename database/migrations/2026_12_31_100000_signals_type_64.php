<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `foodalchemist_signals.type` war varchar(32), der SignalTyp `trendradar_mcp_quelle_ueberfaellig` hat 34 Zeichen.
 * Der Detektor-Lauf (foodalchemist:signale-detektor) brach auf io daran ab („Data too long for column 'type'“, 15.11.2026),
 * alle Signale danach blieben stehen. Spalte auf 64 Zeichen; der Index bleibt (change() behält ihn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_signals')) {
            return;
        }
        Schema::table('foodalchemist_signals', function (Blueprint $table) {
            $table->string('type', 64)->change();
        });
    }

    public function down(): void
    {
        // bewusst kein Zurückkürzen: längere Typen würden abgeschnitten
    }
};
