<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\AltPaketRueckbau;

/**
 * Plan 98 · 3.1: Alt-Pakete endgültig entfernen. Seit 98 · 2.6c ist ein Paket ein Konzept der Art Paket (`kind=paket`, am Slot
 * `embedded_concept_id`); kein Code liest die Alt-Tabellen und -Spalten mehr. Es fallen `foodalchemist_package_dishes`,
 * `foodalchemist_packages` und die Spalte `package_id` an Konzept-Slots und Speiseplan-Einträgen (samt FKs und Indizes).
 *
 * RIEGEL ({@see AltPaketRueckbau}): bricht ab, solange ein nicht gelöschter Slot/Eintrag eine `package_id` trägt oder ein Alt-Paket
 * ohne Gegenstück (gleicher Name, `kind=paket`, gleiches Team) existiert. Vor dem Lauf auf io: Dump (Rückweg der Daten).
 *
 * Auf SQLite (nur die Testbasis) bleiben Tabellen und Spalten stehen, wie in 2026_11_08_100000_drop_geschirr_db: ein Spalten-Drop
 * dort baut die Tabelle neu, und SQLite verweigert jeden INSERT in eine Tabelle, deren FK-Definition auf eine fehlende Elterntabelle
 * zeigt. Der Riegel läuft trotzdem, damit er in den Tests greift.
 *
 * down(): legt die leeren Strukturen wieder an (ohne FKs). Die Daten kommen nur aus dem Dump zurück.
 */
return new class extends Migration
{
    public function up(): void
    {
        AltPaketRueckbau::pruefeOderAbbrechen();

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::dropIfExists('foodalchemist_package_dishes');
        foreach (array_keys(AltPaketRueckbau::SPALTEN) as $tabelle) {
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'package_id')) {
                continue;
            }
            // FK-Namen aus dem Schema lesen: concept_slots trägt noch den Namen aus der Zeit vor der Umbenennung (…_baustein_id_foreign).
            foreach (Schema::getForeignKeys($tabelle) as $fk) {
                if (in_array('package_id', $fk['columns'], true)) {
                    Schema::table($tabelle, fn (Blueprint $t) => $t->dropForeign($fk['name']));
                }
            }
            Schema::table($tabelle, fn (Blueprint $t) => $t->dropColumn('package_id'));   // der Index fällt mit der Spalte
        }
        // Übrige Verweise auf die Paket-Tabelle (falls ein Altstand noch einen trägt), dann die Tabelle selbst.
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if (! str_starts_with($name, 'foodalchemist_') || $name === 'foodalchemist_packages') {
                continue;
            }
            foreach (Schema::getForeignKeys($name) as $fk) {
                if ($fk['foreign_table'] === 'foodalchemist_packages') {
                    Schema::table($name, fn (Blueprint $b) => $b->dropForeign($fk['name']));
                }
            }
        }
        Schema::dropIfExists('foodalchemist_packages');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('foodalchemist_packages')) {
            Schema::create('foodalchemist_packages', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('team_id')->nullable()->index();
                $t->string('name');
                $t->string('role')->nullable()->index();
                $t->string('level')->nullable();
                $t->decimal('price_per_person', 10, 2)->nullable();
                $t->decimal('ek_per_person', 10, 2)->nullable();
                $t->decimal('food_cost_percent', 6, 2)->nullable();
                $t->string('price_mode')->default('manuell');
                $t->timestamp('price_calculated_at')->nullable();
                $t->boolean('price_stale')->default(false);
                $t->text('description')->nullable();
                $t->text('note')->nullable();
                $t->boolean('is_inactive')->default(false);
                $t->string('class')->nullable()->index();
                $t->string('consumer_name')->nullable();
                $t->integer('work_time_min_cache')->nullable();
                $t->text('nutrition_cache')->nullable();
                $t->unsignedBigInteger('offer_id')->nullable()->index();
                $t->decimal('calculated_price_per_person', 10, 2)->nullable();
                $t->string('price_calculation_source')->nullable();
                $t->string('price_calculation_version')->nullable();
                $t->text('price_override_reason')->nullable();
                $t->unsignedBigInteger('price_override_user_id')->nullable();
                $t->timestamp('price_override_at')->nullable();
                $t->timestamp('price_override_expires_at')->nullable();
                $t->boolean('ist_fassung')->default(false)->index('fa_package_ist_fassung');
                $t->unsignedBigInteger('fassung_von_id')->nullable()->index('fa_package_fassung_von');
                $t->integer('fassung_nr')->nullable();
                $t->string('inhalt_hash')->nullable();
                $t->timestamp('veroeffentlicht_at')->nullable();
                $t->unsignedBigInteger('veroeffentlicht_von')->nullable();
                $t->text('aenderungsnotiz')->nullable();
                $t->boolean('fassung_allergen_geaendert')->default(false);
                $t->unsignedBigInteger('kopie_von_id')->nullable();
                $t->timestamp('kopie_stand_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('foodalchemist_package_dishes')) {
            Schema::create('foodalchemist_package_dishes', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('team_id')->nullable()->index();
                $t->unsignedBigInteger('package_id');
                $t->unsignedBigInteger('sales_recipe_id');
                $t->decimal('quantity', 12, 3)->nullable();
                $t->unsignedBigInteger('unit_vocab_id')->nullable();
                $t->integer('position')->default(0);
                $t->unsignedBigInteger('presentation_id')->nullable();
                $t->unsignedBigInteger('tableware_item_id')->nullable()->index();
                $t->unsignedBigInteger('tableware_alt_item_id')->nullable()->index();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['package_id', 'sales_recipe_id'], 'fa_baustein_gericht_uq');
            });
        }
        foreach (array_keys(AltPaketRueckbau::SPALTEN) as $tabelle) {
            if (Schema::hasTable($tabelle) && ! Schema::hasColumn($tabelle, 'package_id')) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->unsignedBigInteger('package_id')->nullable()->index());
            }
        }
    }
};
