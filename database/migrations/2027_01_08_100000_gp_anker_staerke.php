<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gp_anchor_mappings', function (Blueprint $table) {
            $table->string('staerke', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gp_anchor_mappings', function (Blueprint $table) {
            $table->dropColumn('staerke');
        });
    }
};
