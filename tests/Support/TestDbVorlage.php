<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Test-Tempo: einmal je Prozess migrieren, danach je Test eine Kopie.
 *
 * Bisher baute jeder Test seine SQLite-:memory:-DB in {@see SeedsTeamHierarchy::seedTeamHierarchy()} per `artisan migrate`
 * über alle Modul-Migrationen neu auf (einige Sekunden je Test). Jetzt:
 *   1. Der erste Test mit leerer DB migriert wie bisher und legt das Ergebnis per `VACUUM INTO` als Vorlagendatei ab
 *      (`<temp>/fa-test-vorlage/<hash>.sqlite`, atomar per rename — parallele Worker bauen höchstens doppelt).
 *   2. Jeder weitere Test bekommt seine eigene frische :memory:-DB und kopiert die Vorlage hinein (ATTACH, Tabellen
 *      per Original-SQL, Zeilen, sqlite_sequence, danach Indizes/Trigger/Views). Jeder Test startet damit vom identischen
 *      Stand — die Isolation („pro Test frisch“) bleibt, die Test-Connection bleibt sqlite :memory: (Guard in TestCase).
 *
 * Der Hash läuft über alle Eingaben, die das Ergebnis bestimmen (Migrationsdateien, Stub-Definitionen, diese Klasse,
 * laravel/framework aus composer.lock, PHP- und SQLite-Version) und wird einmal je Prozess berechnet. Ändert sich
 * etwas, entsteht ein neuer Dateiname — eine veraltete Vorlage kann nicht greifen.
 *
 * Zeitstempel: Migrationen seeden Zeilen mit now(). In der Vorlage stünde sonst die Bauzeit (ggf. Stunden alt), auf
 * dem alten Weg die Zeit des Tests. Die Vorlage merkt sich deshalb ihr Bau-Zeitfenster (Tabelle _fa_vorlage_meta, wird
 * nicht mitkopiert); beim Laden werden NUR Werte in diesem Fenster um (jetzt − Baubeginn) verschoben — Abstände bleiben,
 * feste Datumswerte (Gültig-ab o. ä.) liegen nie im Fenster und bleiben unberührt. „jetzt“ ist now(), also auch
 * Carbon::setTestNow.
 *
 * Alter Weg (unverändert): DB beim Aufruf nicht leer (Test hat vorher selbst migriert/geseedet) — gezählt — oder
 * `FA_TEST_OHNE_VORLAGE=1` (Vergleich, Notbremse).
 */
final class TestDbVorlage
{
    public const ORDNER = 'fa-test-vorlage';

    public const META = '_fa_vorlage_meta';

    private const FORMAT = 'Y-m-d H:i:s';

    private static ?string $hash = null;

    /** @var array{geladen: int, gebaut: int, alt_nicht_leer: int, aus: int} */
    private static array $zaehler = ['geladen' => 0, 'gebaut' => 0, 'alt_nicht_leer' => 0, 'aus' => 0];

    /** @var array<string, int> Testklasse => Anzahl alter Wege (DB nicht leer) */
    private static array $altWeg = [];

    private static bool $abschlussAngemeldet = false;

    /**
     * Welcher Weg für diesen Aufruf: 'laden' (Vorlage da), 'bauen' (alter Weg, danach Vorlage ablegen) oder 'alt'.
     *
     * @param  list<string>  $pfade  Migrations-Pfade (Dateien oder Ordner) des alten Wegs — gehen in den Hash
     */
    public static function weg(array $pfade, string $testKlasse): string
    {
        self::abschlussAnmelden();
        if (self::aus()) {
            self::$zaehler['aus']++;

            return 'alt';
        }
        if (! self::istLeer(DB::connection()->getPdo())) {
            self::$zaehler['alt_nicht_leer']++;
            self::$altWeg[$testKlasse] = (self::$altWeg[$testKlasse] ?? 0) + 1;

            return 'alt';
        }

        return is_file(self::datei($pfade)) ? 'laden' : 'bauen';
    }

    /** Kopiert die Vorlage in die (leere) Test-DB. */
    public static function lade(array $pfade): void
    {
        // Dieselben Gesamt-Events wie der Migrator auf dem alten Weg — Listener (z. B. VkAltspaltenBruecke, die nach jeder
        // Migration die Spaltenliste des Rezept-Modells ergänzt) sehen damit dasselbe. Einzel-Events je Migration entfallen.
        event(new MigrationsStarted('up', []));
        self::kopiereIn(DB::connection()->getPdo(), self::datei($pfade), now()->format(self::FORMAT));
        event(new MigrationsEnded('up', []));
        self::$zaehler['geladen']++;
    }

    /** Zeitpunkt im Format der gespeicherten Zeitstempel (für das Bau-Zeitfenster). */
    public static function jetzt(): string
    {
        return now()->format(self::FORMAT);
    }

    /**
     * Legt die eben frisch migrierte Test-DB als Vorlage ab (atomar) und räumt alte Vorlagen auf.
     * $start/$ende: Bau-Zeitfenster (vor/nach dem Migrieren, {@see jetzt()}).
     */
    public static function speichere(array $pfade, string $start, string $ende): void
    {
        $ziel = self::datei($pfade);
        $ordner = \dirname($ziel);
        if (! is_dir($ordner)) {
            @mkdir($ordner, 0775, true);
        }
        $tmp = $ziel.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
        DB::connection()->getPdo()->exec('VACUUM INTO '.self::zitat($tmp));
        $meta = new PDO('sqlite:'.$tmp);
        $meta->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $meta->exec('CREATE TABLE '.self::META.' (schluessel TEXT PRIMARY KEY, wert TEXT)');
        $eintrag = $meta->prepare('INSERT INTO '.self::META.' VALUES (?, ?)');
        $eintrag->execute(['start', $start]);
        $eintrag->execute(['ende', $ende]);
        $meta = $eintrag = null;
        if (! @rename($tmp, $ziel)) {
            @unlink($tmp);
        }
        self::$zaehler['gebaut']++;
        self::raeumeAuf($ordner, basename($ziel));
    }

    public static function aus(): bool
    {
        $wert = getenv('FA_TEST_OHNE_VORLAGE');

        return $wert !== false && $wert !== '' && $wert !== '0';
    }

    /** @param list<string> $pfade */
    public static function datei(array $pfade): string
    {
        return sys_get_temp_dir().'/'.self::ORDNER.'/'.self::hash($pfade).'.sqlite';
    }

    /**
     * Einmal je Prozess. Die Pfadliste ist je Prozess fest (sie hängt nur am installierten Host), deshalb reicht der
     * erste Aufruf.
     *
     * @param  list<string>  $pfade
     */
    public static function hash(array $pfade): string
    {
        if (self::$hash !== null) {
            return self::$hash;
        }
        $teile = [];
        foreach ($pfade as $p) {
            foreach (is_dir($p) ? self::dateienIn($p) : [$p] as $datei) {
                $teile[$datei] = is_file($datei) ? (string) sha1_file($datei) : 'fehlt';
            }
        }
        foreach ([__DIR__.'/SeedsTeamHierarchy.php', __FILE__] as $datei) {
            $teile[basename($datei)] = (string) sha1_file($datei);
        }
        $teile['laravel/framework'] = self::laravelVersion();
        $teile['php'] = PHP_VERSION;
        $teile['sqlite'] = (string) (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();

        return self::$hash = self::berechneHash($teile);
    }

    /** Reine Funktion (Vertragstest): gleicher Inhalt → gleicher Hash, jede Änderung → neuer Hash. */
    public static function berechneHash(array $teile): string
    {
        ksort($teile);

        return substr(sha1(json_encode($teile, JSON_UNESCAPED_SLASHES)), 0, 20);
    }

    public static function istLeer(PDO $pdo): bool
    {
        return (int) $pdo->query("select count(*) from sqlite_master where type in ('table','view') and name not like 'sqlite_%'")
            ->fetchColumn() === 0;
    }

    /**
     * Kopiert die Vorlagendatei in die über $pdo erreichbare (leere) DB. Öffentlich für den Vertragstest.
     * $jetzt (Format Y-m-d H:i:s): Zielzeit für die Zeitstempel aus dem Bau-Zeitfenster; null = nicht verschieben.
     */
    public static function kopiereIn(PDO $pdo, string $datei, ?string $jetzt = null): void
    {
        $fk = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys = OFF');                 // nur außerhalb einer Transaktion wirksam
        $pdo->exec('ATTACH DATABASE '.self::zitat($datei).' AS vorlage');
        try {
            $pdo->beginTransaction();
            $objekte = $pdo->query("select type, name, sql from vorlage.sqlite_master where sql is not null order by rowid")
                ->fetchAll(PDO::FETCH_ASSOC);
            $tabellen = [];
            foreach ($objekte as $o) {
                if ($o['type'] === 'table' && ! str_starts_with($o['name'], 'sqlite_') && $o['name'] !== self::META) {
                    $pdo->exec($o['sql']);
                    $tabellen[] = $o['name'];
                }
            }
            $fenster = self::fenster($pdo);
            $versatz = ($jetzt !== null && $fenster !== null) ? strtotime($jetzt) - strtotime($fenster[0]) : 0;
            foreach ($tabellen as $t) {
                $spalten = [];
                $zeit = [];
                foreach ($pdo->query('PRAGMA vorlage.table_xinfo('.self::zitat($t).')')->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    if ((int) $s['hidden'] === 0) {                  // generierte Spalten (hidden 2/3) rechnet SQLite selbst
                        $spalten[] = self::name($s['name']);
                        if (preg_match('/time/i', (string) $s['type'])) {   // datetime/timestamp, nicht date
                            $zeit[] = self::name($s['name']);
                        }
                    }
                }
                $liste = implode(', ', $spalten);
                $n = $pdo->exec('INSERT INTO main.'.self::name($t)." ($liste) SELECT $liste FROM vorlage.".self::name($t));
                if ($n > 0 && $versatz !== 0 && $zeit !== []) {
                    $mod = self::zitat(sprintf('%+d seconds', $versatz));
                    foreach ($zeit as $c) {
                        $pdo->exec('UPDATE main.'.self::name($t)." SET $c = strftime('%Y-%m-%d %H:%M:%S', $c, $mod)"
                            ." WHERE length($c) = 19 AND $c BETWEEN ".self::zitat($fenster[0]).' AND '.self::zitat($fenster[1]));
                    }
                }
            }
            if ((int) $pdo->query("select count(*) from vorlage.sqlite_master where name = 'sqlite_sequence'")->fetchColumn() > 0
                && (int) $pdo->query("select count(*) from main.sqlite_master where name = 'sqlite_sequence'")->fetchColumn() > 0) {
                $pdo->exec('DELETE FROM main.sqlite_sequence');
                $pdo->exec('INSERT INTO main.sqlite_sequence (name, seq) SELECT name, seq FROM vorlage.sqlite_sequence');
            }
            foreach (['index', 'trigger', 'view'] as $typ) {
                foreach ($objekte as $o) {
                    if ($o['type'] === $typ) {
                        $pdo->exec($o['sql']);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->exec('DETACH DATABASE vorlage');
            $pdo->exec('PRAGMA foreign_keys = '.($fk ? 'ON' : 'OFF'));
        }
    }

    /** @return array{0: string, 1: string}|null Bau-Zeitfenster der angehängten Vorlage */
    private static function fenster(PDO $pdo): ?array
    {
        if ((int) $pdo->query("select count(*) from vorlage.sqlite_master where name = '".self::META."'")->fetchColumn() === 0) {
            return null;
        }
        $m = $pdo->query('select schluessel, wert from vorlage.'.self::META)->fetchAll(PDO::FETCH_KEY_PAIR);

        return isset($m['start'], $m['ende']) ? [(string) $m['start'], (string) $m['ende']] : null;
    }

    /** @return array{geladen: int, gebaut: int, alt_nicht_leer: int, aus: int} */
    public static function zaehler(): array
    {
        return self::$zaehler;
    }

    /** Alte Vorlagen (anderer Hash, älter als 1 Tag) im eigenen Ordner löschen — nichts außerhalb. */
    private static function raeumeAuf(string $ordner, string $behalten): void
    {
        foreach (glob($ordner.'/*.sqlite') ?: [] as $datei) {
            if (basename($datei) !== $behalten && @filemtime($datei) < time() - 86400) {
                @unlink($datei);
            }
        }
    }

    /**
     * Zähler am Prozessende: als Zeile auf STDERR und als Datei `zaehler-<pid>.log` im Vorlagen-Ordner (die CI sammelt
     * sie ein). Zeigt, wie viele Tests noch den alten Weg nehmen und welche.
     */
    private static function abschlussAnmelden(): void
    {
        if (self::$abschlussAngemeldet) {
            return;
        }
        self::$abschlussAngemeldet = true;
        register_shutdown_function(static function (): void {
            $z = self::$zaehler;
            if (array_sum($z) === 0) {
                return;
            }
            arsort(self::$altWeg);
            $zeile = sprintf('TestDbVorlage pid=%d geladen=%d gebaut=%d alt_nicht_leer=%d aus=%d%s', getmypid(), $z['geladen'],
                $z['gebaut'], $z['alt_nicht_leer'], $z['aus'],
                self::$altWeg === [] ? '' : ' alt_in='.implode(',', array_map(fn ($k, $n) => "$k:$n", array_keys(self::$altWeg), self::$altWeg)));
            $ordner = sys_get_temp_dir().'/'.self::ORDNER;
            if (is_dir($ordner)) {
                @file_put_contents($ordner.'/zaehler-'.getmypid().'.log', $zeile.PHP_EOL, FILE_APPEND);
            }
            if (getenv('FA_TEST_VORLAGE_ZAEHLER') && \defined('STDERR')) {
                fwrite(STDERR, $zeile.PHP_EOL);
            }
        });
    }

    /** @return list<string> */
    private static function dateienIn(string $ordner): array
    {
        $liste = glob(rtrim($ordner, '/').'/*.php') ?: [];
        sort($liste);

        return $liste;
    }

    private static function laravelVersion(): string
    {
        $lock = base_path('composer.lock');
        $daten = is_file($lock) ? json_decode((string) file_get_contents($lock), true) : null;
        foreach ([...($daten['packages'] ?? []), ...($daten['packages-dev'] ?? [])] as $paket) {
            if (($paket['name'] ?? null) === 'laravel/framework') {
                return (string) ($paket['version'] ?? '?').'@'.(string) ($paket['source']['reference'] ?? '');
            }
        }

        return \Illuminate\Foundation\Application::VERSION;
    }

    private static function zitat(string $wert): string
    {
        return "'".str_replace("'", "''", $wert)."'";
    }

    private static function name(string $wert): string
    {
        return '"'.str_replace('"', '""', $wert).'"';
    }
}
