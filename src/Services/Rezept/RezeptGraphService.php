<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\RezeptZyklusException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;

/**
 * Sub-Rezept-Graph (§3.5): Verknüpfungs-Guards parent→sub und Tiefen. Aus RecipeRecomputeService ausgelagert (27.10.2026, Dateigrößen-Sperrklinke),
 * Verhalten unverändert; RecipeRecomputeService::pruefeVerknuepfung delegiert hierher.
 *
 * Code-Qualität 1.1e (19.11.2026) · EIN Schreibweg für Kanten Zutat → Unterrezept: Wer eine Zeile NEU auf ein Unterrezept zeigen lässt
 * (Eloquent oder Query-Builder), tut das über {@see schreibeKanten()} / {@see haengeUm()} bzw. — innerhalb einer laufenden Transaktion —
 * über {@see sichereKante()}. Prüfen und Schreiben laufen unter der Graph-Sperre in EINER Transaktion; die Sperre ist eine Zeilensperre
 * auf `foodalchemist_graph_sperren` (endet mit Commit/Rollback der äußersten Transaktion). Bewusst global statt „beide Rezepte in fester
 * Reihenfolge“: zwei Kanten A→B und C→D mit bestehendem B→C und D→A sperren disjunkte Paare und schlössen gemeinsam einen Zyklus.
 * Kanten-Schreiber sind selten (Speichern mit NEUEM Unterrezept, Tausch, Umhängen), die Sperre hält nur bis zum Commit.
 * Kopien in FRISCHE Rezepte (Duplizieren, Fassung, Vorlage, Abo-Kopie) brauchen den Weg nicht: ein neuer Knoten ohne Eltern schließt keinen Zyklus.
 */
class RezeptGraphService
{
    public const SPERRE = 'rezeptgraph';

    /**
     * Kanten prüfen und schreiben, atomar: Transaktion → Graph-Sperre → je Kante {@see pruefeVerknuepfung()} → `$schreib()`.
     *
     * @template T
     * @param  list<array{0:int,1:int}>  $kanten  [parentId, subId]
     * @param  \Closure(): T  $schreib
     * @return T
     *
     * @throws RezeptZyklusException
     */
    public function schreibeKanten(array $kanten, \Closure $schreib): mixed
    {
        return DB::transaction(function () use ($kanten, $schreib) {
            $this->sperre();                                       // auch ohne Kanten: Aufrufer, die selbst unter der Sperre prüfen (ersetzeInVerwendungen)
            foreach ($kanten as [$parentId, $subId]) {
                $this->sichereKante((int) $parentId, (int) $subId);
            }

            return $schreib();
        });
    }

    /**
     * Innerhalb einer laufenden Transaktion: Graph-Sperre holen (einmal je Transaktion genügt, doppelt schadet nicht) und die Kante prüfen.
     * `$sperren = false` nur für eine Kante, die es schon gibt: sie schließt keinen neuen Zyklus. `syncIngredients` prüft bestehende Kanten seit
     * 2.1c gar nicht mehr (nur geänderte Zeilen); ein per DB eingeschleuster Altbestands-Zyklus fällt in `topoOrder`/`explodiere` auf (1.1e).
     *
     * @throws RezeptZyklusException
     */
    public function sichereKante(int $parentId, int $subId, bool $sperren = true): void
    {
        if ($sperren) {
            $this->sperre();
        }
        $pruefung = $this->pruefeVerknuepfung($parentId, $subId);
        if (! $pruefung['erlaubt']) {
            throw new RezeptZyklusException([$parentId, $subId], "Sub-Rezept-Verknüpfung abgelehnt: {$pruefung['grund']}.", (string) $pruefung['grund']);
        }
    }

    /**
     * Zeilen (Query) von `$alt` auf `$neu` umhängen — der Query-Builder-Weg (Fassungs-Umstellung, Abo-Kündigung). Jedes betroffene
     * Eltern-Rezept wird gegen `$neu` geprüft; ein Zyklus lehnt das ganze Umhängen ab (nichts geschrieben).
     *
     * @param  Builder|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $zeilen  Zutatenzeilen, die heute auf `$alt` zeigen
     * @param  string  $rezeptSpalte  Spalte mit der Eltern-Rezept-ID (bei Alias-Joins `z.recipe_id`)
     * @return int Anzahl umgehängter Zeilen
     *
     * @throws RezeptZyklusException
     */
    public function haengeUm($zeilen, int $neu, array $weitere = [], string $rezeptSpalte = 'recipe_id'): int
    {
        return DB::transaction(function () use ($zeilen, $neu, $weitere, $rezeptSpalte) {
            $this->sperre();
            foreach ((clone $zeilen)->distinct()->pluck($rezeptSpalte) as $parentId) {
                $this->sichereKante((int) $parentId, $neu);
            }

            return $zeilen->update(['referenced_recipe_id' => $neu] + $weitere);
        });
    }

    /** Graph-Sperre bis zum Ende der äußersten Transaktion. Ohne Transaktion ist sie wirkungslos — darum nur über die Methoden oben. */
    private function sperre(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Rezeptgraph: Kanten nur innerhalb einer Transaktion schreiben (schreibeKanten/haengeUm).');
        }
        $q = fn () => DB::table('foodalchemist_graph_sperren')->where('schluessel', self::SPERRE)->lockForUpdate()->value('schluessel');
        if ($q() === null) {                                       // Sperr-Zeile fehlt (z. B. geleerte Tabelle): anlegen, dann sperren
            DB::table('foodalchemist_graph_sperren')->insertOrIgnore(['schluessel' => self::SPERRE]);
            $q();
        }
    }

    /**
     * Einen Zyklus in einer Adjazenz finden (iterative Tiefensuche, Farben weiß/grau/schwarz). Für die rechnenden Dienste
     * (`topoOrder`, `explodiere`), die eine eingeschleuste Kreis-Kante melden statt still weiterzurechnen.
     *
     * @param  array<int, list<int>>  $kinderVon  recipe_id => Unterrezept-IDs
     * @return ?list<int> die Rezepte eines Zyklus in Pfad-Reihenfolge, null = zyklenfrei
     */
    public static function findeZyklus(array $kinderVon): ?array
    {
        $farbe = [];
        foreach (array_keys($kinderVon) as $start) {
            if (isset($farbe[$start])) {
                continue;
            }
            $pfad = [$start];
            $iter = [$start => 0];
            $farbe[$start] = 1;
            while ($pfad !== []) {
                $knoten = $pfad[count($pfad) - 1];
                $kinder = $kinderVon[$knoten] ?? [];
                if ($iter[$knoten] < count($kinder)) {
                    $kind = (int) $kinder[$iter[$knoten]++];
                    if (($farbe[$kind] ?? 0) === 1) {
                        return array_slice($pfad, (int) array_search($kind, $pfad, true));
                    }
                    if (! isset($farbe[$kind])) {
                        $farbe[$kind] = 1;
                        $iter[$kind] = 0;
                        $pfad[] = $kind;
                    }
                } else {
                    $farbe[$knoten] = 2;
                    array_pop($pfad);
                }
            }
        }

        return null;
    }

    /**
     * §3.5: Verknüpfungs-Guards parent→sub — Selbstreferenz und Zyklus blocken. Seit A-5 (03.08.) gibt es kein Tiefenlimit; Code-Qualität 2.1c:
     * keine (nie gelesene) Tiefen-Rechnung mehr, die Suche endet beim ersten Treffer.
     *
     * @return array{erlaubt: bool, grund: ?string}
     */
    public function pruefeVerknuepfung(int $parentId, int $subId): array
    {
        if ($parentId === $subId) {
            return ['erlaubt' => false, 'grund' => 'Selbstreferenz'];
        }
        // von sub abwärts: wird parent erreicht ⇒ Zyklus
        $zyklus = false;
        $this->ebenen([$subId], 'recipe_id', 'referenced_recipe_id', function (array $kinder) use ($parentId, &$zyklus) {
            return ! ($zyklus = in_array($parentId, $kinder, true));
        });

        return $zyklus ? ['erlaubt' => false, 'grund' => 'Zyklus'] : ['erlaubt' => true, 'grund' => null];
    }

    /**
     * Code-Qualität 2.1c · alle Rezepte, die die Starts (transitiv) als Unterrezept verwenden — Starts zuerst (Eingabe-Reihenfolge, ohne Dubletten),
     * dann Ebene für Ebene. Kein Tiefen-Deckel (A-5); die Besucht-Liste trägt die Terminierung, auch bei eingeschleusten Zyklen.
     *
     * @param  array<int|string>  $start
     * @return list<int>
     */
    public function vorfahren(array $start): array
    {
        return $this->ebenen($start, 'referenced_recipe_id', 'recipe_id');
    }

    /**
     * Code-Qualität 2.1c · alle Unterrezepte der Starts (transitiv), ohne die Starts selbst. Kein Tiefen-Deckel (A-5), Besucht-Liste.
     *
     * @param  array<int|string>  $start
     * @return list<int>
     */
    public function nachfahren(array $start): array
    {
        $start = array_values(array_unique(array_map('intval', $start)));

        return array_values(array_diff($this->ebenen($start, 'recipe_id', 'referenced_recipe_id'), $start));
    }

    /**
     * Breitensuche über die Zutat→Unterrezept-Kanten, eine Abfrage je Ebene. `$weiter` (optional) sieht die neuen Knoten jeder Ebene und bricht
     * mit `false` ab.
     *
     * @param  array<int|string>  $start
     * @return list<int> Starts + alle erreichten Knoten
     */
    private function ebenen(array $start, string $von, string $nach, ?\Closure $weiter = null): array
    {
        $besucht = [];
        foreach ($start as $id) {
            $besucht[(int) $id] = true;
        }
        $ebene = array_keys($besucht);
        while ($ebene !== []) {
            $neu = FoodAlchemistRecipeIngredient::whereIn($von, $ebene)->whereNotNull($nach)->whereNull('deleted_at')
                ->distinct()->pluck($nach)->map(fn ($id) => (int) $id)->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if ($weiter !== null && ! $weiter($neu)) {
                break;
            }
            foreach ($neu as $id) {
                $besucht[$id] = true;
            }
            $ebene = $neu;
        }

        return array_keys($besucht);
    }
}
