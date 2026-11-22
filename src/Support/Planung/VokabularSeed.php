<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;

/**
 * CQ (Folge aus 2.5b) · Onboarding-Seed: Ein neues Wurzel-Team bekommt Saison, Einsatzmoment und Eventtyp aus dem Grundbestand,
 * mit Codes ({@see VokabularCodes::grundbestandAnlegen}). Unterteams erben die Kette, persönliche Teams bleiben leer, die Service-Art
 * liegt global. Ausgelöst über `Team::created` (FoodAlchemistServiceProvider), in Core ändert sich nichts.
 *
 * Leitplanken (Kurator 22.11.2026): Das Anlegen eines Teams scheitert nie am Seed (try/catch + Log::warning); ohne FA-Tabellen (Host ohne
 * FA-Migrationen) passiert nichts, einmal je Prozess geprüft; läuft das Anlegen in einer Transaktion, erst nach dem Commit
 * (`DB::afterCommit`), damit ein Rollback keine Waisen-Vokabulare hinterlässt.
 */
final class VokabularSeed
{
    private static ?bool $tabellenDa = null;

    public static function fuerNeuesTeam(Team $team): void
    {
        if (! config('foodalchemist.vokabular_seed_bei_team_anlage', true) || $team->personal_team || $team->parent_team_id !== null) {
            return;
        }
        DB::afterCommit(function () use ($team) {
            try {
                if (self::tabellenDa()) {
                    DB::transaction(fn () => VokabularCodes::grundbestandAnlegen($team));   // ganz oder gar nicht
                }
            } catch (\Throwable $e) {
                Log::warning('[FoodAlchemist] Vokabular-Seed für neues Team übersprungen', ['team_id' => $team->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /** Wurzel-Teams ohne sichtbare Einträge in mindestens einem Vokabular (Nachziehen für den Bestand). @return list<Team> */
    public static function offeneTeams(): array
    {
        return Team::query()->whereNull('parent_team_id')->where('personal_team', false)->orderBy('id')->get()
            ->filter(fn (Team $t) => collect(array_keys(VokabularCodes::GRUNDBESTAND))->contains(
                fn ($tabelle) => ! \Platform\FoodAlchemist\Support\TeamScope::applyVisible(DB::table($tabelle)->whereNull('deleted_at'), 'team_id', $t)->exists()))
            ->values()->all();
    }

    private static function tabellenDa(): bool
    {
        // Nur „vorhanden" wird gecacht: ein Team, das während der Migrationen entsteht, soll den Seed nicht für den ganzen Prozess abschalten.
        if (self::$tabellenDa !== true) {
            self::$tabellenDa = collect(array_keys(VokabularCodes::GRUNDBESTAND))->every(fn ($t) => Schema::hasTable($t) && Schema::hasColumn($t, 'code')) ?: null;
        }

        return self::$tabellenDa === true;
    }

    /** Nur für Tests: Prozess-Cache der Tabellenprüfung leeren. */
    public static function vergessen(): void
    {
        self::$tabellenDa = null;
    }
}
