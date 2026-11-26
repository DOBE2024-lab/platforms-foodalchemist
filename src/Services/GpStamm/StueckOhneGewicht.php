<?php

namespace Platform\FoodAlchemist\Services\GpStamm;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;

/**
 * Zutaten in Stück- bzw. Zähl-Einheiten ohne Gewicht (Entscheid Dominique 23.11.: warnen + Pflegeliste, Speichern bleibt möglich).
 *
 * Eine Zeile wie „1 Stück Ei“ ohne Stückgewicht wiegt im Komponentenmodell 0 g (RecipeRecomputeService::grammFaktor, T1 Zeile 5).
 * Seit dem Kurator-Entscheid (c) läuft sie über ihre Kosten bzw. ihren Rezept-Anteil mit; Grammatur und Produktionsmenge bleiben
 * aber vorläufig, bis das Gewicht gepflegt ist. Diese Klasse ist die eine Quelle für:
 *  - die Pflegelisten der Datenqualität (registriert in DataQualityService, beide Info, kein Signal):
 *    `gp_stueck_ohne_gewicht` ({@see checks}) für Grundprodukte und `br_stueck_ohne_ertrag` ({@see checksRezept}) für Basisrezepte,
 *  - die Zeilen eines Rezepts ({@see zeilen}) für den Hinweis im Zutaten-Editor und für MCP,
 *  - den Hinweis an einer Darreichung ({@see darreichung}).
 *
 * Die Abfragen spiegeln grammFaktor in SQL:
 *  - GP-Zeile: Gewicht über die GP-Form (gp_forms), den Zähl-Default des GP (gp_count_unit_defaults), das Gewicht der Einheit
 *    (default_in_g/ml) oder — nur bei „stk“ — das Stückgewicht des GP. Gepflegt im GP-Modal, Reiter Kalkulation
 *    („Verluste und Stückgewicht“, auch per KI-Schätzung) bzw. „Gewicht je Form“.
 *  - Unterrezept-Zeile in Zähl-Einheit: Gewicht je Stück = Ertrag (yield_kg) ÷ Stückzahl (yield_pieces) des Basisrezepts, sonst das
 *    Gewicht der Einheit. Gepflegt am Basisrezept (Kurator 26.11.: „Ertrag bzw. Stückzahl im Basisrezept pflegen“).
 */
final class StueckOhneGewicht
{
    public const KEY = 'gp_stueck_ohne_gewicht';

    public const LABEL = 'Grundprodukte in Rezepten mit Stück- oder Zähl-Einheit ohne Gewicht';

    public const KEY_REZEPT = 'br_stueck_ohne_ertrag';

    public const LABEL_REZEPT = 'Basisrezepte, in Stück verwendet, ohne Ertrag bzw. Stückzahl';

    /** Für DataQualityService::METRIK_LABELS (gleiche Bauart wie Einkauf\LaBeschaffungsLagen). */
    public const LABELS = [self::KEY => self::LABEL, self::KEY_REZEPT => self::LABEL_REZEPT];

    /** Pflege-Hinweise je Zeilenart — Editor-Badge, Darreichung und Pflegeliste sagen dasselbe. */
    public const PFLEGE_GP = 'Stückgewicht im Grundprodukt pflegen (Kalkulation)';

    public const PFLEGE_REZEPT = 'Ertrag bzw. Stückzahl im Basisrezept pflegen';

    /**
     * Register-Eintrag für DataQualityService::queryFor (kind gp): Label und Prädikat, wie Einkauf\LaBeschaffungsLagen::checks.
     *
     * @return array<string, array{label: string, q: \Closure}>
     */
    public function checks(): array
    {
        return [self::KEY => ['label' => self::LABEL, 'q' => fn (Team $t): Builder => $this->abfrage($t)]];
    }

    /**
     * Register-Eintrag für DataQualityService::queryFor (kind recipe): Basisrezepte ohne Stück-Gewicht.
     *
     * @return array<string, array{label: string, q: \Closure}>
     */
    public function checksRezept(): array
    {
        return [self::KEY_REZEPT => ['label' => self::LABEL_REZEPT, 'q' => fn (Team $t): Builder => self::rezepteOhneErtrag($t)]];
    }

    /** Die Abfrage der Pflegeliste — Zählung (DataQualityService::gp) und Liste (betroffene) nutzen dieselbe. */
    public function abfrage(Team $team): Builder
    {
        return self::gpOhneGewicht($team);
    }

    /** GP (team-sichtbar), die in mindestens einer sichtbaren Rezeptzeile mit Zähl-Einheit ohne Gewichtsquelle stecken. */
    public static function gpOhneGewicht(Team $team): Builder
    {
        return FoodAlchemistGp::visibleToTeam($team)->whereExists(fn (QueryBuilder $q) => self::zeilenOhneEinheitsgewicht($q, $team)
            ->whereColumn('i.gp_id', 'foodalchemist_gps.id')
            ->whereNotExists(fn ($f) => $f->selectRaw('1')->from('foodalchemist_gp_forms as f')
                ->whereColumn('f.gp_id', 'i.gp_id')->whereColumn('f.form_slug', 'v.slug')->whereNull('f.deleted_at'))
            ->whereNotExists(fn ($c) => $c->selectRaw('1')->from('foodalchemist_gp_count_unit_defaults as c')
                ->whereColumn('c.gp_id', 'i.gp_id')->whereColumn('c.unit_vocab_id', 'i.unit_vocab_id')->whereNull('c.deleted_at'))
            ->where(fn ($w) => $w->where('v.slug', '!=', 'stk')->orWhereNull('foodalchemist_gps.piece_default_g')));
    }

    /**
     * Basisrezepte (team-sichtbar), die in mindestens einer sichtbaren Rezeptzeile in Zähl-Einheit stecken, aber kein Gewicht je Stück
     * hergeben: Stückzahl fehlt bzw. ist 0 oder der Ertrag fehlt (grammFaktor, Sub-Rezept per Stück).
     */
    public static function rezepteOhneErtrag(Team $team): Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)
            ->where(fn ($w) => $w->whereNull('foodalchemist_recipes.yield_pieces')->orWhere('foodalchemist_recipes.yield_pieces', '<=', 0)
                ->orWhereNull('foodalchemist_recipes.yield_kg'))
            ->whereExists(fn (QueryBuilder $q) => self::zeilenOhneEinheitsgewicht($q, $team)
                ->whereColumn('i.referenced_recipe_id', 'foodalchemist_recipes.id')
                ->where('v.dimension', 'count'));
    }

    /** Gemeinsamer Kern: zählende Zeilen sichtbarer Rezepte, deren Einheit selbst kein Gewicht trägt. */
    private static function zeilenOhneEinheitsgewicht(QueryBuilder $q, Team $team): QueryBuilder
    {
        return $q->selectRaw('1')
            ->from('foodalchemist_recipe_ingredients as i')
            ->join('foodalchemist_vocab_units as v', 'v.id', '=', 'i.unit_vocab_id')
            ->whereNull('i.deleted_at')
            ->whereIn('i.recipe_id', FoodAlchemistRecipe::visibleToTeam($team)->select('id'))
            ->where('i.quantity', '>', 0)
            ->where(fn ($w) => $w->whereNull('i.is_optional')->orWhere('i.is_optional', false))
            ->where(fn ($w) => $w->whereNull('i.match_method')->orWhere('i.match_method', '!=', MatchMethod::Ignored->value))
            ->where('v.slug', '!=', 'qs')
            ->whereNull('v.default_in_g')
            ->whereNull('v.default_in_ml');
    }

    /**
     * Zeilen eines Rezepts ohne Gewicht — aus derselben Rechnung wie EK und Produktion (zeilenKostenUndMassen.ohne_gewicht).
     * `recipe_id` ist das verwendete Basisrezept (Unterrezept-Zeile), sonst null.
     *
     * @return list<array{ingredient_id: int, name: string, menge: float, einheit: ?string, gp_id: ?int, recipe_id: ?int}>
     */
    public static function zeilen(FoodAlchemistRecipe $recipe, Team $team): array
    {
        $flags = app(RecipeRecomputeService::class)->zeilenKostenUndMassen($recipe, $team);
        $out = [];
        foreach ($recipe->ingredients as $z) {
            if (! ($flags[$z->id]['ohne_gewicht'] ?? false)) {
                continue;
            }
            $out[] = ['ingredient_id' => (int) $z->id, 'name' => trim((string) ($z->display_name ?: $z->raw_text ?: $z->gp?->name)),
                'menge' => (float) $z->quantity, 'einheit' => $z->unit?->display_de ?? $z->unit?->slug, 'gp_id' => $z->gp_id !== null ? (int) $z->gp_id : null,
                'recipe_id' => $z->referenced_recipe_id !== null ? (int) $z->referenced_recipe_id : null];
        }

        return $out;
    }

    /** „Ei (1 Stück), Lorbeer (2 Blatt)“ für Hinweise. @param list<array{name: string, menge: float, einheit: ?string}> $zeilen */
    public static function text(array $zeilen): string
    {
        return implode(', ', array_map(fn ($z) => $z['name'] . ' (' . rtrim(rtrim(number_format($z['menge'], 3, ',', ''), '0'), ',')
            . ($z['einheit'] !== null ? ' ' . $z['einheit'] : '') . ')', $zeilen));
    }

    /** Wo das Gewicht gepflegt wird — je nach Zeilenart GP, Basisrezept oder beides. @param list<array{recipe_id?: ?int}> $zeilen */
    public static function pflege(array $zeilen): string
    {
        $rezept = array_filter($zeilen, fn ($z) => ($z['recipe_id'] ?? null) !== null);

        return implode('; ', array_filter([count($rezept) < count($zeilen) ? self::PFLEGE_GP : null, $rezept !== [] ? self::PFLEGE_REZEPT : null]));
    }

    /**
     * Hinweis an einer Darreichung, die über ihre Bestandteile rechnet (Standard „Summe“ oder eigene Mengen): welche Zeilen ohne Gewicht
     * mitlaufen und ob der EK dadurch vorläufig ist (Variante mit eigenen Mengen: Verhältnis geschätzt). Freie Grammatur → nichts.
     *
     * @return array{ohne_gewicht: list<int>, vorlaeufig: bool, text: ?string}
     */
    public static function darreichung(FoodAlchemistRecipe $recipe, FoodAlchemistRecipeDarreichung $d, Team $team): array
    {
        $leer = ['ohne_gewicht' => [], 'vorlaeufig' => false, 'text' => null];
        if (! $d->menge_vom_standard && ! $d->deltas()->exists()) {
            return $leer;
        }
        $k = app(DarreichungService::class)->komponentenSumme($d, $recipe, $team);
        if (($k['ohne_gewicht'] ?? []) === []) {
            return $leer;
        }
        $zeilen = array_values(array_filter(self::zeilen($recipe, $team), fn ($z) => in_array($z['ingredient_id'], $k['ohne_gewicht'], true)));
        $text = ($k['vorlaeufig'] ? 'EK vorläufig: ' : 'Ohne Gewicht: ') . self::text($zeilen) . '. '
            . ($k['vorlaeufig'] ? 'Läuft im Verhältnis dieser Darreichung mit. ' : 'Kosten über den Stückpreis, die Grammatur zählt sie nicht. ')
            . self::pflege($zeilen) . '.';

        return ['ohne_gewicht' => $k['ohne_gewicht'], 'vorlaeufig' => (bool) $k['vorlaeufig'], 'text' => $text];
    }
}
