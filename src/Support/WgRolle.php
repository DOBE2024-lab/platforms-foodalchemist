<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Rollen der Warengruppen (Code-Qualität 3.7, Taxonomie Teil 2): Getränk, Non-Food, kühl, fertig. Vorher standen die
 * Codes als Literale im Code ('15', '16', Listen für kühl/fertig). Jetzt tragen die Warengruppen Flags
 * (`is_getraenk`, `is_non_food`, `is_kuehl`, `is_fertig` an `foodalchemist_lookup_commodity_groups`).
 *
 * Regeln (Entscheid Kurator/Dominique 2026-11-22):
 *  · Rollen gelten global. Maßgeblich sind die Zeilen des Basis-Katalogs (team_id NULL bzw. Master-Team); Team-Kopien
 *    erben über den Code, ein Team kann eine Rolle nicht überschreiben (Steuer-, Getränke-, Non-Food-Logik).
 *  · 13 (Convenience) gilt als fertig.
 *  · Unterkategorien (09.6 Knabbereien, 01.8) bekommen kein Flag, sie bleiben benannte Fachregel an ihrer Stelle.
 *  · {@see self::STANDARD} ist die Anfangsbelegung (Backfill der Migration, Vorbelegung neuer Zeilen) und der Rückfall
 *    für Codes ohne Katalog-Zeile (leere Test-DB, Daten vor dem Import). Liegt eine Katalog-Zeile vor, gilt ihr Flag.
 */
final class WgRolle
{
    public const GETRAENK = 'getraenk';

    public const NON_FOOD = 'non_food';

    public const KUEHL = 'kuehl';

    public const FERTIG = 'fertig';

    /** Rolle => Spalte. */
    public const SPALTEN = [
        self::GETRAENK => 'is_getraenk',
        self::NON_FOOD => 'is_non_food',
        self::KUEHL => 'is_kuehl',
        self::FERTIG => 'is_fertig',
    ];

    /** Anfangsbelegung je Rolle (Codes nach §3 GP-Regelwerk). */
    public const STANDARD = [
        self::GETRAENK => ['15'],
        self::NON_FOOD => ['16'],
        self::KUEHL => ['01', '02', '03', '04', '05', '06', '13', '14'],
        self::FERTIG => ['10', '11', '13'],
    ];

    private const CACHE = 'foodalchemist.wg-rollen';

    public static function hat(mixed $code, string $rolle): bool
    {
        if (! isset(self::SPALTEN[$rolle])) {
            throw new \InvalidArgumentException("WgRolle: unbekannte Rolle „{$rolle}“");
        }
        $code = trim((string) $code);
        if ($code === '') {
            return false;
        }
        $belegung = self::belegung();

        return isset($belegung[$code]) ? $belegung[$code][$rolle] : in_array($code, self::STANDARD[$rolle], true);
    }

    public static function istGetraenk(mixed $code): bool
    {
        return self::hat($code, self::GETRAENK);
    }

    public static function istNonFood(mixed $code): bool
    {
        return self::hat($code, self::NON_FOOD);
    }

    public static function istKuehl(mixed $code): bool
    {
        return self::hat($code, self::KUEHL);
    }

    public static function istFertig(mixed $code): bool
    {
        return self::hat($code, self::FERTIG);
    }

    /**
     * Alle Codes mit dieser Rolle — für Abfragen (`whereIn`/`whereNotIn`). Katalog-Zeilen mit Flag plus Standard-Codes
     * ohne Katalog-Zeile.
     *
     * @return list<string>
     */
    public static function codes(string $rolle): array
    {
        $belegung = self::belegung();
        $codes = array_keys(array_filter($belegung, fn (array $r) => $r[$rolle] ?? false));
        foreach (self::STANDARD[$rolle] ?? [] as $code) {
            if (! isset($belegung[$code])) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    /** Der Code, unter dem eine Rolle neu angelegt wird (z. B. die Non-Food-Warengruppe). */
    public static function standardCode(string $rolle): string
    {
        return self::STANDARD[$rolle][0];
    }

    /** @return array<string, bool> Spalte => Standard-Flag für einen Code (Vorbelegung neuer Zeilen). */
    public static function standardFlags(mixed $code): array
    {
        $code = trim((string) $code);
        $out = [];
        foreach (self::SPALTEN as $rolle => $spalte) {
            $out[$spalte] = in_array($code, self::STANDARD[$rolle], true);
        }

        return $out;
    }

    /** Cache verwerfen (nach Änderung an einer Warengruppe). */
    public static function vergessen(): void
    {
        if (app()->bound(self::CACHE)) {
            app()->forgetInstance(self::CACHE);
        }
    }

    /** @return array<string, array<string, bool>> Code => Rolle => Flag, aus dem Basis-Katalog; je Request gecacht. */
    private static function belegung(): array
    {
        if (app()->bound(self::CACHE)) {
            return app(self::CACHE);
        }
        $belegung = [];
        try {
            $master = MasterTeam::id();
            $zeilen = DB::table('foodalchemist_lookup_commodity_groups')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('team_id')->when($master !== null, fn ($q) => $q->orWhere('team_id', $master)))
                ->get(['code', ...array_values(self::SPALTEN)]);
            foreach ($zeilen as $z) {
                $code = trim((string) $z->code);
                foreach (self::SPALTEN as $rolle => $spalte) {
                    // Basis-Zeilen gleichen Codes (global + Master): eine gesetzte Rolle reicht.
                    $belegung[$code][$rolle] = ($belegung[$code][$rolle] ?? false) || (bool) $z->{$spalte};
                }
            }
        } catch (\Illuminate\Database\QueryException) {
            $belegung = [];   // Spalten noch nicht migriert → Standard
        }
        app()->instance(self::CACHE, $belegung);

        return $belegung;
    }
}
