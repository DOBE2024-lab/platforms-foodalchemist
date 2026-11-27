<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Support\Datenmodell\Fremdschluessel;

/**
 * Plan 98 · 3.5 Schritt 5 · Fremdschlüssel: Ausgabe-Blöcke und Kalkulation.
 *
 * Nur Spalten ohne FK (Test-Schema und io gleich, io-Abfrage 27.11.2026: 0 Verweise ins Leere). Idempotent; bricht ab, wenn eine Zeile
 * ins Leere zeigt (auch soft-gelöschte), statt zu löschen. ON DELETE restrict, Selbstbezug Fassung/Kopie set null, nie cascade.
 * Trockenlauf: `php artisan foodalchemist:diagnose:waisen` (bzw. `migrate --pretend`). Rückweg: down() entfernt genau diese FKs.
 * Details: {@see Fremdschluessel}, Plan docs/PLANUNG/98_3-5_Plan_Datenmodell.md.
 */
return new class extends Migration
{
    /** SQLite-Testbasis: originalgetreuer Tabellen-Umbau braucht abgeschaltete FK-Prüfung, die wirkt nur ohne Transaktion. */
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> Tabelle, Spalte, Ziel, ON DELETE */
    private const FKS = [
        ['foodalchemist_foodbook_blocks', 'presentation_id', 'foodalchemist_recipe_presentations', Fremdschluessel::RESTRICT],
        ['foodalchemist_foodbook_chapters', 'serving_form_id', 'foodalchemist_serving_forms', Fremdschluessel::RESTRICT],
        ['foodalchemist_menu_card_items', 'presentation_id', 'foodalchemist_recipe_presentations', Fremdschluessel::RESTRICT],
        ['foodalchemist_menu_card_sections', 'format_id', 'foodalchemist_formats', Fremdschluessel::RESTRICT],
        ['foodalchemist_offer_blocks', 'presentation_id', 'foodalchemist_recipe_presentations', Fremdschluessel::RESTRICT],
        ['foodalchemist_offer_chapters', 'format_id', 'foodalchemist_formats', Fremdschluessel::RESTRICT],
        ['foodalchemist_offer_chapters', 'serving_form_id', 'foodalchemist_serving_forms', Fremdschluessel::RESTRICT],
        ['foodalchemist_angebot_einfrier_zeilen', 'markup_class_id', 'foodalchemist_markup_classes', Fremdschluessel::RESTRICT],
        ['foodalchemist_angebot_einfrier_zeilen', 'presentation_id', 'foodalchemist_recipe_presentations', Fremdschluessel::RESTRICT],
        ['foodalchemist_angebot_einfrier_zeilen', 'recipe_id', 'foodalchemist_recipes', Fremdschluessel::RESTRICT],
        ['foodalchemist_konfigurator_leistungen', 'markup_class_id', 'foodalchemist_markup_classes', Fremdschluessel::RESTRICT],
        ['foodalchemist_leistung_vorlagen', 'markup_class_id', 'foodalchemist_markup_classes', Fremdschluessel::RESTRICT],
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
