<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Code-Qualität 2.5b · stabile Codes für die Konzepter-Vokabulare, die die Planung liest (Saison, Einsatzmoment = „Anlass“,
 * Eventtyp, Service-Art). Die Zeilen liegen je Team als Kopie vor, IDs taugen deshalb nicht als gespeicherter Wert — gespeichert
 * wird der Code. Für die Namen aus dem Grundbestand gilt der bisherige Planungs-Slug (`empfang`, `late_night`, `konferenz` …),
 * damit gespeicherte Planungen, die Wissens-Achsen (`ai.knowledge_axis_map`) und die Wissens-Dokumente gültig bleiben;
 * alles andere bekommt `Str::slug(name, '_', 'de')`.
 *
 * Nur ergänzend: Zeilen mit Code bleiben unangetastet. `bericht()` ist der Trockenlauf (Befehl `foodalchemist:vokabular-codes`),
 * `fuellen()` schreibt (Migration und Befehl mit `--apply`).
 */
final class VokabularCodes
{
    /** Tabellen mit Code-Spalte (Feld der Planung => Tabelle). */
    public const TABELLEN = [
        'saison' => 'foodalchemist_seasons',
        'occasion' => 'foodalchemist_service_moments',
        'eventtyp' => 'foodalchemist_event_types',
        'serviceform' => 'foodalchemist_service_arten',
    ];

    /** Grundbestand-Name => bisheriger Planungs-Slug (Tabelle => [Name => Code]). */
    public const BESTANDS_CODES = [
        'foodalchemist_service_moments' => ['Apéro/Empfang' => 'empfang', 'Late-Night/Mitternachtssnack' => 'late_night'],
        'foodalchemist_event_types' => ['Konferenz/Tagung' => 'konferenz'],
        'foodalchemist_seasons' => ['ganzjährig' => 'ganzjaehrig'],
    ];

    /** Grundbestand der Service-Art (global, `team_id` NULL), getrennt von den Darreichungs-Servierformen (Dominique 22.11.2026). */
    public const SERVICE_ARTEN = [
        'tellerservice' => 'Tellerservice', 'buffet' => 'Buffet', 'flying' => 'Flying Service', 'stehempfang' => 'Stehempfang', 'boxed' => 'Boxed',
    ];

    /** Grundbestand je Team (wie Migration 2026_07_03_000006) — für neue Teams und Test-Fixtures ({@see grundbestandAnlegen}). */
    public const GRUNDBESTAND = [
        'foodalchemist_service_moments' => ['Frühstück', 'Brunch', 'Vormittagspause', 'Lunch', 'Nachmittagspause', 'Apéro/Empfang', 'Dinner',
            'Late-Night/Mitternachtssnack', 'Ganztags/Tagungspauschale'],
        'foodalchemist_event_types' => ['Konferenz/Tagung', 'Seminar/Workshop', 'Gala/Bankett', 'Firmenfeier', 'Sommerfest', 'Weihnachtsfeier',
            'Messe/Ausstellung', 'Produktpräsentation/Launch', 'Empfang/Vernissage', 'Hochzeit', 'Privatfeier/Jubiläum', 'Sportevent', 'Betriebsverpflegung'],
        'foodalchemist_seasons' => ['Frühling', 'Sommer', 'Herbst', 'Winter', 'ganzjährig'],
    ];

    /**
     * Grundbestand mit Codes für ein Team anlegen — je Vokabular nur, wenn das Team in seiner Kette noch nichts sieht (Unterteams erben,
     * eigene Kopien würden doppelt erscheinen). Idempotent. @return int angelegte Zeilen
     */
    public static function grundbestandAnlegen(\Platform\Core\Models\Team $team): int
    {
        $n = 0;
        foreach (self::GRUNDBESTAND as $tabelle => $namen) {
            if (\Platform\FoodAlchemist\Support\TeamScope::applyVisible(DB::table($tabelle)->whereNull('deleted_at'), 'team_id', $team)->exists()) {
                continue;
            }
            foreach ($namen as $i => $name) {
                DB::table($tabelle)->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $team->id, 'name' => $name, 'code' => self::codeFuer($tabelle, $name),
                    'sort_order' => ($i + 1) * 10, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    public static function codeFuer(string $tabelle, string $name): string
    {
        return self::BESTANDS_CODES[$tabelle][$name] ?? Str::slug($name, '_', 'de');
    }

    /**
     * Trockenlauf: was `fuellen()` setzen würde (je Zeile Name → Code), Kollisionen (zwei Zeilen eines Teams mit demselben Code)
     * und Fehler (Namen, aus denen kein Code entsteht).
     *
     * @return array<string, array{setzen: list<array{id: int, team_id: ?int, name: string, code: string}>, kollisionen: list<string>, fehler: list<string>}>
     */
    public static function bericht(): array
    {
        $out = [];
        foreach (self::TABELLEN as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $hatCode = Schema::hasColumn($tabelle, 'code');
            $zeilen = DB::table($tabelle)->whereNull('deleted_at')->orderBy('id')
                ->get($hatCode ? ['id', 'team_id', 'name', 'code'] : ['id', 'team_id', 'name']);
            $setzen = [];
            $belegt = [];
            $fehler = [];
            foreach ($zeilen as $z) {
                $code = ($hatCode && $z->code !== null) ? (string) $z->code : self::codeFuer($tabelle, (string) $z->name);
                if ($code === '') {
                    $fehler[] = '#' . $z->id . ' Team ' . ($z->team_id ?? 'global') . ' «' . $z->name . '» ergibt keinen Code';

                    continue;
                }
                if (! $hatCode || $z->code === null) {
                    $setzen[] = ['id' => (int) $z->id, 'team_id' => $z->team_id !== null ? (int) $z->team_id : null, 'name' => (string) $z->name, 'code' => $code];
                }
                $belegt[($z->team_id ?? 'global') . ':' . $code][] = (string) $z->name;
            }
            $kollisionen = [];
            foreach ($belegt as $schluessel => $namen) {
                if (count($namen) > 1) {
                    $kollisionen[] = $schluessel . ' ← ' . implode(' | ', $namen);
                }
            }
            $out[$tabelle] = ['setzen' => $setzen, 'kollisionen' => $kollisionen, 'fehler' => $fehler];
        }

        return $out;
    }

    /** Fehlende Codes setzen (idempotent). @return int gesetzte Zeilen */
    public static function fuellen(): int
    {
        $n = 0;
        foreach (self::bericht() as $tabelle => $teil) {
            if (! Schema::hasColumn($tabelle, 'code')) {
                continue;
            }
            foreach ($teil['setzen'] as $z) {
                $n += DB::table($tabelle)->where('id', $z['id'])->whereNull('code')->update(['code' => $z['code']]);
            }
        }

        return $n;
    }
}
