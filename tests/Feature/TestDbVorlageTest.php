<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\TestDbVorlage;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Vertrag der Test-DB-Vorlage (Test-Tempo): die Kopie ist dieselbe DB wie eine frisch migrierte — Schema, alle Zeilen,
 * sqlite_sequence —, ohne Fremdschlüssel-Verstöße; der Hash wechselt mit jeder Eingabe; eine nicht leere DB nimmt den
 * alten Weg.
 */
/**
 * $normal: für den Vergleich mit der gemeinsamen Vorlage (anderer Prozess, andere Zeit). Werte, die auch der alte Weg je
 * Test neu würfelt, werden gleichgesetzt: UUIDs → „UUID“, Zeitstempel ±10 min um jetzt → „JETZT“ — auch in JSON-Texten.
 */
function vorlageSchnappschuss(PDO $pdo, bool $normal = false): array
{
    $von = now()->subMinutes(10)->format('Y-m-d H:i:s');
    $bis = now()->addMinutes(10)->format('Y-m-d H:i:s');
    $glaette = function ($v) use ($von, $bis) {
        if (! is_string($v)) {
            return $v;
        }
        $v = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'UUID', $v);

        return preg_replace_callback('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', fn ($m) => $m[0] >= $von && $m[0] <= $bis ? 'JETZT' : $m[0], $v);
    };
    $schema = $pdo->query("select type, name, tbl_name, sql from sqlite_master where sql is not null order by type, name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $tabellen = $pdo->query("select name from sqlite_master where type = 'table' order by name")->fetchAll(PDO::FETCH_COLUMN);
    $daten = [];
    foreach ($tabellen as $t) {
        $zeilen = array_map(fn ($z) => json_encode($normal ? array_map($glaette, $z) : $z),
            $pdo->query('select * from "'.str_replace('"', '""', $t).'"')->fetchAll(PDO::FETCH_ASSOC));
        sort($zeilen);
        $daten[$t] = count($zeilen).':'.sha1(implode("\n", $zeilen));
    }

    return ['schema' => $schema, 'daten' => $daten];
}

function vorlageKopie(string $datei, ?string $jetzt = null): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    TestDbVorlage::kopiereIn($pdo, $datei, $jetzt);

    return $pdo;
}

it('Kopie gleicht einer frisch migrierten DB: Schema, Zeilen, sqlite_sequence, keine FK-Verstöße', function () {
    $pfade = $this->testDbMigrationsPfade();
    $this->migriereTestDb($pfade);                          // alter Weg in die leere :memory:-DB dieses Tests
    $frisch = DB::connection()->getPdo();
    $soll = vorlageSchnappschuss($frisch);

    $datei = sys_get_temp_dir().'/fa-vorlage-vertrag-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    $frisch->exec("VACUUM INTO '".str_replace("'", "''", $datei)."'");
    try {
        $kopie = vorlageKopie($datei);
        $ist = vorlageSchnappschuss($kopie);
        expect(array_keys($ist['daten']))->toBe(array_keys($soll['daten']))
            ->and($ist['schema'])->toBe($soll['schema'])
            ->and($ist['daten'])->toBe($soll['daten'])
            ->and($ist['daten'])->toHaveKey('sqlite_sequence')
            ->and($ist['daten']['migrations'])->toBe($soll['daten']['migrations'])
            ->and($kopie->query('PRAGMA foreign_key_check')->fetchAll())->toBe([]);

        // Die gemeinsame Vorlage (falls schon gebaut, ggf. Stunden alt) ist dieselbe DB — ihre Seed-Zeitstempel
        // rücken beim Laden auf jetzt, wie beim alten Weg.
        if (is_file(TestDbVorlage::datei($pfade))) {
            expect(vorlageSchnappschuss(vorlageKopie(TestDbVorlage::datei($pfade), TestDbVorlage::jetzt()), true))
                ->toBe(vorlageSchnappschuss($frisch, true));
        }
    } finally {
        @unlink($datei);
    }
});

it('seedTeamHierarchy über die Vorlage: gleiche Teams und IDs wie über den alten Weg', function () {
    $this->seedTeamHierarchy();
    $ids = [$this->rootTeam->id, $this->childA->id, $this->childB->id];

    expect($ids)->toBe([1, 2, 3])
        ->and(DB::table('migrations')->count())->toBeGreaterThan(500)
        ->and(DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn())->toBe(1);
});

it('Zeitstempel aus dem Bau-Zeitfenster rücken auf jetzt (Abstände bleiben), feste Werte und Datumsspalten nicht', function () {
    $datei = sys_get_temp_dir().'/fa-vorlage-zeit-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    $q = new PDO('sqlite:'.$datei);
    $q->exec('create table t (id integer primary key, created_at datetime, gueltig_ab datetime, tag date)');
    $q->exec("insert into t values (1, '2020-01-01 10:00:00', '2019-05-05 00:00:00', '2020-01-01'), (2, '2020-01-01 10:00:07', null, null)");
    $q->exec('create table '.TestDbVorlage::META.' (schluessel text primary key, wert text)');
    $q->exec("insert into ".TestDbVorlage::META." values ('start', '2020-01-01 10:00:00'), ('ende', '2020-01-01 10:00:09')");
    $q = null;
    try {
        $kopie = vorlageKopie($datei, '2026-11-23 12:00:00');
        expect($kopie->query('select created_at, gueltig_ab, tag from t order by id')->fetchAll(PDO::FETCH_NUM))->toBe([
            ['2026-11-23 12:00:00', '2019-05-05 00:00:00', '2020-01-01'],
            ['2026-11-23 12:00:07', null, null],
        ])->and($kopie->query("select count(*) from sqlite_master where name = '".TestDbVorlage::META."'")->fetchColumn())->toBe(0);
    } finally {
        @unlink($datei);
    }
});

it('Hash: gleicher Inhalt gleicher Hash, jede Änderung ein neuer', function () {
    $basis = ['a.php' => 'x1', 'laravel/framework' => 'v13.8.0', 'php' => '8.3', 'sqlite' => '3.45'];

    expect(TestDbVorlage::berechneHash($basis))->toBe(TestDbVorlage::berechneHash(array_reverse($basis, true)))
        ->and(TestDbVorlage::berechneHash(['a.php' => 'x2'] + $basis))->not->toBe(TestDbVorlage::berechneHash($basis))
        ->and(TestDbVorlage::berechneHash(['b.php' => 'neu'] + $basis))->not->toBe(TestDbVorlage::berechneHash($basis))
        ->and(TestDbVorlage::berechneHash(['laravel/framework' => 'v13.9.0'] + $basis))->not->toBe(TestDbVorlage::berechneHash($basis));
});

it('nicht leere DB: alter Weg, gezählt', function () {
    if (TestDbVorlage::aus()) {
        $this->markTestSkipped('FA_TEST_OHNE_VORLAGE gesetzt');
    }
    DB::statement('create table vorgearbeitet (id integer primary key)');
    $vorher = TestDbVorlage::zaehler()['alt_nicht_leer'];

    expect(TestDbVorlage::weg($this->testDbMigrationsPfade(), static::class))->toBe('alt')
        ->and(TestDbVorlage::zaehler()['alt_nicht_leer'])->toBe($vorher + 1);
});
