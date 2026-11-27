<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Support\Datenmodell\Fremdschluessel;

/**
 * Plan 98 · 3.5 Schritt 5 · Fremdschlüssel: Rezept-Kette.
 *
 * Nur Spalten ohne FK (Test-Schema und io gleich, io-Abfrage 27.11.2026: 0 Verweise ins Leere). Idempotent; bricht ab, wenn eine Zeile
 * ins Leere zeigt (auch soft-gelöschte), statt zu löschen. ON DELETE restrict, Selbstbezug Fassung/Kopie set null, nie cascade.
 * Trockenlauf: `php artisan foodalchemist:diagnose:waisen` (bzw. `migrate --pretend`). Rückweg: down() entfernt genau diese FKs.
 * Details: {@see Fremdschluessel}, Plan docs/PLANUNG/98_3-5_Plan_Datenmodell.md.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> Tabelle, Spalte, Ziel, ON DELETE */
    private const FKS = [
        ['foodalchemist_recipes', 'dish_main_group_id', 'foodalchemist_dish_main_groups', Fremdschluessel::RESTRICT],
        ['foodalchemist_recipes', 'fassung_von_id', 'foodalchemist_recipes', Fremdschluessel::NULL_SETZEN],
        ['foodalchemist_recipes', 'kopie_von_id', 'foodalchemist_recipes', Fremdschluessel::NULL_SETZEN],
        ['foodalchemist_recipes', 'variant_source_recipe_id', 'foodalchemist_recipes', Fremdschluessel::NULL_SETZEN],
        ['foodalchemist_recipe_taste_vectors', 'recipe_id', 'foodalchemist_recipes', Fremdschluessel::RESTRICT],
        ['foodalchemist_recipe_textures', 'recipe_id', 'foodalchemist_recipes', Fremdschluessel::RESTRICT],
        ['foodalchemist_recipe_team_kosten', 'recipe_id', 'foodalchemist_recipes', Fremdschluessel::RESTRICT],
        ['foodalchemist_recipe_presentations', 'vessel_gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
    ];

    public function up(): void
    {
        foreach (self::FKS as [$tabelle, $spalte, $ziel, $beimLoeschen]) {
            Fremdschluessel::ergaenze($tabelle, $spalte, $ziel, $beimLoeschen);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::FKS) as [$tabelle, $spalte, $ziel]) {
            Fremdschluessel::entferne($tabelle, $spalte, $ziel);
        }
    }
};
