<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 98 · 3.5 Schritt 4: `sales_recipe_id` → `recipe_id` an den sechs Tabellen, die ein Gericht oder Basisrezept referenzieren.
 * Reine Umbenennung (RENAME COLUMN), die Daten bleiben. Idempotent: eine Tabelle, die schon `recipe_id` trägt oder kein
 * `sales_recipe_id` mehr hat, wird übersprungen. `foodalchemist_package_dishes` (Alt-Paket) bleibt außen vor; auf MySQL ist sie
 * seit 98 · 3.1 gelöscht.
 *
 * Index- und FK-Namen werden aus dem Schema gelesen (wie in 98 · 3.1) und mitbenannt, damit spätere Migrationen die
 * Laravel-Standardnamen (`…_recipe_id_foreign`, `…_recipe_id_index`) treffen. Auf MySQL: FK lösen, Spalte umbenennen, Index
 * umbenennen, FK mit gleicher Regel (on delete/update) neu setzen. Auf SQLite (Testbasis) zieht RENAME COLUMN die FK-Definition
 * selbst nach; dort werden nur die Indizes umbenannt.
 *
 * down(): derselbe Weg zurück auf `sales_recipe_id`.
 */
return new class extends Migration
{
    public const TABELLEN = [
        'foodalchemist_concept_slots',
        'foodalchemist_foodbook_blocks',
        'foodalchemist_offer_blocks',
        'foodalchemist_menu_card_items',
        'foodalchemist_menu_plan_entries',
        'foodalchemist_dish_ideas',
    ];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            $this->benenne($tabelle, 'sales_recipe_id', 'recipe_id');
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            $this->benenne($tabelle, 'recipe_id', 'sales_recipe_id');
        }
    }

    private function benenne(string $tabelle, string $alt, string $neu): void
    {
        if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, $alt) || Schema::hasColumn($tabelle, $neu)) {
            return;
        }
        $mysql = DB::connection()->getDriverName() !== 'sqlite';

        $fks = $mysql ? array_values(array_filter(Schema::getForeignKeys($tabelle), fn ($fk) => $fk['columns'] === [$alt])) : [];
        foreach ($fks as $fk) {
            Schema::table($tabelle, fn (Blueprint $t) => $t->dropForeign($fk['name']));
        }
        $indizes = array_values(array_filter(Schema::getIndexes($tabelle), fn ($i) => ! $i['primary'] && in_array($alt, $i['columns'], true)));

        Schema::table($tabelle, fn (Blueprint $t) => $t->renameColumn($alt, $neu));

        foreach ($indizes as $i) {
            $neuName = str_replace($alt, $neu, $i['name']);
            if ($neuName !== $i['name']) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->renameIndex($i['name'], $neuName));
            }
        }
        foreach ($fks as $fk) {
            $name = str_contains($fk['name'], $alt) ? str_replace($alt, $neu, $fk['name']) : "{$tabelle}_{$neu}_foreign";
            Schema::table($tabelle, function (Blueprint $t) use ($fk, $neu, $name) {
                $f = $t->foreign($neu, $name)->references($fk['foreign_columns'])->on($fk['foreign_table']);
                if (($fk['on_delete'] ?? null) !== null && strtolower($fk['on_delete']) !== 'no action') {
                    $f->onDelete($fk['on_delete']);
                }
                if (($fk['on_update'] ?? null) !== null && strtolower($fk['on_update']) !== 'no action') {
                    $f->onUpdate($fk['on_update']);
                }
            });
        }
    }
};
