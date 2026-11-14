<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Verwaltete Unterkategorie „02.8 Obstsortiment“ in WG 02 (Georg/Dominique 14.11.2026): Obst-Sortimente (Obstkorb, Obst sortiert); 02.7 bleibt Obstpüree.
 * Globaler Eintrag (team_id NULL), Muster wie 2026_12_28_200000_subkategorien_regelwerk_v310. Idempotent: vorhandener Eintrag bleibt, ein soft
 * gelöschter wird reaktiviert. GP-Werte (`gps.sub_category`) hängt der GP-Lauf um (foodalchemist:gp-felder), nicht diese Migration.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_commodity_group_subcategories';

    private const WG = '02';

    private const NAME = '02.8 Obstsortiment';

    private const POSITION = 8;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELLE)) {
            return;
        }
        $jetzt = now();
        $gibtEs = DB::table(self::TABELLE)->whereNull('team_id')->where('commodity_group_code', self::WG)->where('name', self::NAME)->first();
        if ($gibtEs === null) {
            DB::table(self::TABELLE)->insert(['uuid' => (string) Str::uuid(), 'team_id' => null, 'commodity_group_code' => self::WG, 'name' => self::NAME,
                'position' => self::POSITION, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
        } elseif ($gibtEs->deleted_at !== null) {
            DB::table(self::TABELLE)->where('id', $gibtEs->id)->update(['deleted_at' => null, 'position' => self::POSITION, 'updated_at' => $jetzt]);
        }
    }

    public function down(): void
    {
        // Bewusst leer: der Stand ist das Regelwerk (Obstsortiment, 14.11.2026). Entfernen über die Einstellungen.
    }
};
