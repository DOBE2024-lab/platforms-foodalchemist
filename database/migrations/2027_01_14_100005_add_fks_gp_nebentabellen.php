<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Support\Datenmodell\Fremdschluessel;

/**
 * Plan 98 · 3.5 Schritt 5 · Fremdschlüssel: GP-Nebentabellen und Abgleich-Log.
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
        ['foodalchemist_gp_anker_neutral', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
        ['foodalchemist_gp_forms', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
        ['foodalchemist_gp_gefaess_kostenarten', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
        ['foodalchemist_gp_lagerartikel', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
        ['foodalchemist_gp_taste_vectors', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
        ['foodalchemist_gp_textures', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
        ['foodalchemist_abgleich_log', 'gp_id', 'foodalchemist_gps', Fremdschluessel::RESTRICT],
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
