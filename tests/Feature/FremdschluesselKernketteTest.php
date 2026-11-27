<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\Datenmodell\Fremdschluessel;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

// Schema anlegen wie die übrigen Schema-Tests — sonst hängt der Test daran, dass vorher im selben Prozess schon migriert wurde
// (allein oder als erster im Prozess fand er keine Tabelle; Befund Paul).
beforeEach(fn () => $this->seedTeamHierarchy());

/**
 * Plan 98 · 3.5 Schritt 5 · Fremdschlüssel der Kernkette (Migrationen 2027_01_14_10000x) und ihr Riegel ({@see Fremdschluessel}).
 */
function fkMigrationsListe(): array
{
    $liste = [];
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/2027_01_14_10000*_add_fks_*.php') as $datei) {
        $migration = require $datei;
        foreach ((new ReflectionClassConstant($migration, 'FKS'))->getValue() as $fk) {
            $liste[] = $fk;
        }
        if ((new ReflectionClass($migration))->hasConstant('NUR_SQLITE')) {   // vessel_gp_id: auf io vorbestehend, Testbasis ergänzt
            array_push($liste, ...(new ReflectionClassConstant($migration, 'NUR_SQLITE'))->getValue());
        }
    }

    return $liste;
}

it('legt die 49 Fremdschlüssel der Kernkette an (Test-Schema = io nach der Migration)', function () {
    $liste = fkMigrationsListe();

    // 50 Einträge: dish_ideas steht mit sales_recipe_id UND recipe_id drin (Rename 3.5 Schritt 4) — es gibt immer nur eine der Spalten
    expect($liste)->toHaveCount(50);
    $vorhanden = array_values(array_filter($liste, fn ($fk) => Schema::hasColumn($fk[0], $fk[1])));
    $fehlt = array_values(array_filter($vorhanden, fn ($fk) => ! Fremdschluessel::besteht($fk[0], $fk[1], $fk[2])));
    expect($vorhanden)->toHaveCount(49)->and($fehlt)->toBe([]);
});

it('nie cascade: nur restrict oder set null, set null nur auf nullbaren Selbstbezügen und Varianten-Quellen', function () {
    foreach (fkMigrationsListe() as [$t, $s, $z, $art]) {
        expect($art)->toBeIn([Fremdschluessel::RESTRICT, Fremdschluessel::NULL_SETZEN]);
        if ($art === Fremdschluessel::NULL_SETZEN) {
            expect($s)->toBeIn(['fassung_von_id', 'kopie_von_id', 'variant_source_recipe_id', 'vessel_gp_id']);   // vessel_gp_id: wie auf io (nullOnDelete)
        }
    }
    expect(fn () => Fremdschluessel::ergaenze('foodalchemist_recipes', 'kopie_von_id', 'foodalchemist_recipes', 'cascade'))
        ->toThrow(InvalidArgumentException::class);
});

describe('Riegel und Rückweg', function () {
    beforeEach(function () {
        Schema::dropIfExists('fa_fk_probe_kind');
        Schema::dropIfExists('fa_fk_probe_eltern');
        Schema::create('fa_fk_probe_eltern', fn (Blueprint $t) => $t->id());
        Schema::create('fa_fk_probe_kind', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('eltern_id')->nullable();
            $t->softDeletes();
        });
        DB::table('fa_fk_probe_eltern')->insert(['id' => 1]);
    });

    afterEach(function () {
        Schema::dropIfExists('fa_fk_probe_kind');
        Schema::dropIfExists('fa_fk_probe_eltern');
    });

    it('bricht bei einer Waise ab — auch wenn die Kind-Zeile selbst soft-gelöscht ist (MySQL prüft beim FK jede Zeile)', function () {
        DB::table('fa_fk_probe_kind')->insert(['eltern_id' => 1, 'deleted_at' => null]);
        DB::table('fa_fk_probe_kind')->insert(['eltern_id' => 999, 'deleted_at' => now()]);   // Waise, selbst soft-gelöscht

        expect(Fremdschluessel::waisen('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))->toBe(['gesamt' => 1, 'geloescht' => 1])
            ->and(fn () => Fremdschluessel::ergaenze('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))
            ->toThrow(RuntimeException::class, '1 Zeile(n) zeigen ins Leere, davon 1 selbst soft-gelöscht')
            ->and(Fremdschluessel::besteht('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))->toBeFalse();
    });

    it('legt an, ist idempotent, restrict sperrt das harte Löschen, down() entfernt', function () {
        DB::table('fa_fk_probe_kind')->insert(['eltern_id' => 1]);

        expect(Fremdschluessel::ergaenze('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))->toBeTrue()
            ->and(Fremdschluessel::ergaenze('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))->toBeFalse()   // zweiter Lauf: nichts
            ->and(Fremdschluessel::besteht('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))->toBeTrue();
        expect(fn () => DB::table('fa_fk_probe_eltern')->where('id', 1)->delete())->toThrow(QueryException::class);

        Fremdschluessel::entferne('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern');
        Fremdschluessel::entferne('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern');   // zweiter Rückweg: nichts
        expect(Fremdschluessel::besteht('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern'))->toBeFalse();
    });

    it('set null: hartes Löschen der Elternzeile leert den Verweis', function () {
        DB::table('fa_fk_probe_kind')->insert(['eltern_id' => 1]);
        Fremdschluessel::ergaenze('fa_fk_probe_kind', 'eltern_id', 'fa_fk_probe_eltern', Fremdschluessel::NULL_SETZEN);

        DB::table('fa_fk_probe_eltern')->where('id', 1)->delete();
        expect(DB::table('fa_fk_probe_kind')->value('eltern_id'))->toBeNull();
    });

    it('überspringt fehlende Tabelle oder Spalte (Migration läuft auf jedem Stand)', function () {
        expect(Fremdschluessel::ergaenze('fa_fk_probe_gibts_nicht', 'eltern_id', 'fa_fk_probe_eltern'))->toBeFalse()
            ->and(Fremdschluessel::ergaenze('fa_fk_probe_kind', 'gibts_nicht_id', 'fa_fk_probe_eltern'))->toBeFalse();
    });
});
