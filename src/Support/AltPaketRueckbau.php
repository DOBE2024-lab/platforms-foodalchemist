<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 98 · 3.1: Riegel vor dem endgültigen Drop der Alt-Pakete (`foodalchemist_packages`, `foodalchemist_package_dishes`,
 * Spalten `package_id` an Konzept-Slots und Speiseplan-Einträgen). Seit 98 · 2.6c ist ein Paket ein Konzept der Art Paket
 * (`kind=paket`, am Slot `embedded_concept_id`); der Code liest die Alt-Spalten nicht mehr.
 *
 * Der Drop bricht ab, solange
 *  - ein NICHT gelöschter Slot bzw. Speiseplan-Eintrag noch eine `package_id` trägt, oder
 *  - ein nicht gelöschtes Alt-Paket kein Gegenstück hat (Konzept mit gleichem Namen, `kind=paket`, gleichem Team, nicht gelöscht).
 * Gelöschte Zeilen sind gewollt weg. Den Rückweg der Daten deckt der Dump vor dem Lauf ab.
 */
final class AltPaketRueckbau
{
    /** Tabellen der Alt-Pakete (Reihenfolge = Drop-Reihenfolge: Posten vor Paketen). */
    public const TABELLEN = ['foodalchemist_package_dishes', 'foodalchemist_packages'];

    /** Tabellen mit der Alt-Spalte `package_id` → Beschreibung für die Meldung. */
    public const SPALTEN = [
        'foodalchemist_concept_slots' => 'Konzept-Slots',
        'foodalchemist_menu_plan_entries' => 'Speiseplan-Einträge',
    ];

    /** @return list<string> Befunde, die den Drop verhindern (leer = frei) */
    public static function befunde(): array
    {
        $befunde = [];
        foreach (self::SPALTEN as $tabelle => $label) {
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'package_id')) {
                continue;
            }
            $ids = DB::table($tabelle)->whereNotNull('package_id')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
            if ($ids !== []) {
                $befunde[] = count($ids) . " {$label} mit package_id (nicht gelöscht): #" . implode(', #', array_slice($ids, 0, 20));
            }
        }
        if (Schema::hasTable('foodalchemist_packages')) {
            $ohne = DB::table('foodalchemist_packages as p')->whereNull('p.deleted_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('foodalchemist_concepts as c')
                    ->whereColumn('c.name', 'p.name')->whereColumn('c.team_id', 'p.team_id')
                    ->where('c.kind', 'paket')->whereNull('c.deleted_at'))
                ->orderBy('p.id')->get(['p.id', 'p.team_id', 'p.name']);
            foreach ($ohne as $p) {
                $befunde[] = "Alt-Paket #{$p->id} „{$p->name}“ (Team {$p->team_id}) hat kein Gegenstück als Konzept der Art Paket";
            }
        }

        return $befunde;
    }

    public static function pruefeOderAbbrechen(): void
    {
        $befunde = self::befunde();
        if ($befunde !== []) {
            throw new \RuntimeException("Alt-Pakete können nicht gelöscht werden (Plan 98 · 3.1):\n- " . implode("\n- ", $befunde)
                . "\nErst umhängen (Slot/Eintrag auf das Konzept der Art Paket) bzw. das Gegenstück anlegen, dann erneut migrieren.");
        }
    }
}
