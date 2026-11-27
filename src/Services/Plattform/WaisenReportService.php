<?php

namespace Platform\FoodAlchemist\Services\Plattform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 98 · 3.5 Schritt 2 · Waisen-Report der Kernkette, NUR LESEND.
 *
 * Je Verweis-Spalte (GP → Rezept/Zutat → Darreichung → Concept/Format → Ausgabe, dazu Fassung/Kopie-Selbstbezug):
 * wie viele Zeilen sie setzen, wie viele davon ins Leere zeigen (Elternzeile fehlt) und wie viele auf eine soft-gelöschte
 * Elternzeile (das sieht kein Fremdschlüssel). Eigene soft-gelöschte Zeilen zählen nicht mit.
 *
 * Die Spalten werden zur Laufzeit aus dem Schema abgeleitet (Spaltenname → Elterntabelle, {@see ZIEL}), damit der Bericht auf
 * io (MySQL) und im Test-Schema (SQLite) dasselbe prüft und neue Tabellen von selbst mitkommen. Bewusst nicht dabei:
 * polymorphe Verweise (`ref_id`, `objekt_id` …), modulfremde Spalten (`crm_*`, `*context_file_id`) und die Alt-Paket-Tabellen
 * (eigener Abbau, 3.5 Schritt 7). Grundlage für die Fremdschlüssel in Schritt 5: dort gilt „ins_leere = 0“ als Vorbedingung.
 */
final class WaisenReportService
{
    /** Spaltenname → Elterntabelle (ohne Präfix `foodalchemist_`). */
    public const ZIEL = [
        'gp_id' => 'gps', 'vessel_gp_id' => 'gps', 'derivat_von_gp_id' => 'gps',
        'recipe_id' => 'recipes', 'recipe_id' => 'recipes', 'referenced_recipe_id' => 'recipes', 'variant_source_recipe_id' => 'recipes',
        'recipe_ingredient_id' => 'recipe_ingredients', 'presentation_id' => 'recipe_presentations',
        'concept_id' => 'concepts', 'embedded_concept_id' => 'concepts', 'format_id' => 'formats', 'offer_id' => 'offers',
        'serving_form_id' => 'serving_forms', 'markup_class_id' => 'markup_classes', 'dish_main_group_id' => 'dish_main_groups',
    ];

    /** Selbstbezug: zeigt auf dieselbe Tabelle. */
    public const SELBST = ['fassung_von_id', 'kopie_von_id'];

    /** Tabellen-Präfixe, die der Bericht auslässt (Alt-Pakete: eigener Abbau). */
    public const OHNE_TABELLEN = ['foodalchemist_package'];

    /**
     * @return list<array{tabelle: string, spalte: string, ziel: string}>
     */
    public function spalten(): array
    {
        $tabellen = collect(Schema::getTables())->pluck('name')
            ->filter(fn ($t) => str_starts_with($t, 'foodalchemist_'))
            ->reject(fn ($t) => collect(self::OHNE_TABELLEN)->contains(fn ($p) => str_starts_with($t, $p)))
            ->sort()->values();
        $vorhanden = $tabellen->flip();
        $out = [];
        foreach ($tabellen as $t) {
            foreach (Schema::getColumnListing($t) as $c) {
                $ziel = in_array($c, self::SELBST, true) ? $t : (isset(self::ZIEL[$c]) ? 'foodalchemist_'.self::ZIEL[$c] : null);
                if ($ziel !== null && isset($vorhanden[$ziel])) {
                    $out[] = ['tabelle' => $t, 'spalte' => $c, 'ziel' => $ziel];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{tabelle: string, spalte: string, ziel: string, gesetzt: int, ins_leere: int, auf_geloescht: int}>
     */
    public function bericht(): array
    {
        $softSpalte = [];
        $soft = function (string $t) use (&$softSpalte): bool {
            return $softSpalte[$t] ??= Schema::hasColumn($t, 'deleted_at');
        };
        $zeilen = [];
        foreach ($this->spalten() as ['tabelle' => $t, 'spalte' => $c, 'ziel' => $p]) {
            $geloescht = $soft($p) ? 'SUM(CASE WHEN p.id IS NOT NULL AND p.deleted_at IS NOT NULL THEN 1 ELSE 0 END)' : '0';
            $r = DB::table($t.' as c')->leftJoin($p.' as p', 'p.id', '=', 'c.'.$c)
                ->whereNotNull('c.'.$c)
                ->when($soft($t), fn ($q) => $q->whereNull('c.deleted_at'))
                ->selectRaw('COUNT(*) AS gesetzt, SUM(CASE WHEN p.id IS NULL THEN 1 ELSE 0 END) AS ins_leere, '.$geloescht.' AS auf_geloescht')
                ->first();
            $zeilen[] = ['tabelle' => $t, 'spalte' => $c, 'ziel' => $p, 'gesetzt' => (int) $r->gesetzt,
                'ins_leere' => (int) $r->ins_leere, 'auf_geloescht' => (int) $r->auf_geloescht];
        }
        usort($zeilen, fn ($a, $b) => [$b['ins_leere'], $b['auf_geloescht'], $a['tabelle'], $a['spalte']]
            <=> [$a['ins_leere'], $a['auf_geloescht'], $b['tabelle'], $b['spalte']]);

        return $zeilen;
    }
}
