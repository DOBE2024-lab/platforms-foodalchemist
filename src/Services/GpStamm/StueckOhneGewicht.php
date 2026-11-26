<?php

namespace Platform\FoodAlchemist\Services\GpStamm;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * Zutaten in Stück- bzw. Zähl-Einheiten ohne Gewicht (Entscheid Dominique 23.11.: warnen + Pflegeliste, Speichern bleibt möglich).
 *
 * Eine Zeile wie „1 Stück Ei“ ohne Stückgewicht wiegt im Komponentenmodell 0 g (RecipeRecomputeService::grammFaktor, T1 Zeile 5).
 * Seit dem Kurator-Entscheid (c) läuft sie über ihre Kosten bzw. ihren Rezept-Anteil mit; Grammatur und Produktionsmenge bleiben
 * aber vorläufig, bis das Gewicht gepflegt ist. Diese Klasse ist die eine Quelle für:
 *  - die Pflegeliste der Datenqualität ({@see checks}, Kennzahl `gp_stueck_ohne_gewicht`, registriert in DataQualityService),
 *  - die Zeilen eines Rezepts ({@see zeilen}) für den Hinweis im Zutaten-Editor und für MCP,
 *  - den Hinweis an einer Darreichung ({@see darreichung}).
 *
 * Die Abfrage der Pflegeliste spiegelt grammFaktor in SQL: Gewicht hat eine Zeile über die GP-Form (gp_forms), den Zähl-Default
 * des GP (gp_count_unit_defaults), das Gewicht der Einheit (default_in_g/ml) oder — nur bei „stk“ — das Stückgewicht des GP.
 * Gepflegt wird das Stückgewicht im GP-Modal unter Formen (Formen-Editor oder KI-Schätzung).
 */
final class StueckOhneGewicht
{
    public const KEY = 'gp_stueck_ohne_gewicht';

    public const LABEL = 'Grundprodukte in Rezepten mit Stück- oder Zähl-Einheit ohne Gewicht';

    /** Für DataQualityService::METRIK_LABELS (gleiche Bauart wie Einkauf\LaBeschaffungsLagen). */
    public const LABELS = [self::KEY => self::LABEL];

    /**
     * Register-Eintrag für DataQualityService::queryFor (kind gp): Label und Prädikat, wie Einkauf\LaBeschaffungsLagen::checks.
     *
     * @return array<string, array{label: string, q: \Closure}>
     */
    public function checks(): array
    {
        return [self::KEY => ['label' => self::LABEL, 'q' => fn (Team $t): Builder => $this->abfrage($t)]];
    }

    /** Die Abfrage der Pflegeliste — Zählung (DataQualityService::gp) und Liste (betroffene) nutzen dieselbe. */
    public function abfrage(Team $team): Builder
    {
        return self::gpOhneGewicht($team);
    }

    /** GP (team-sichtbar), die in mindestens einer sichtbaren Rezeptzeile mit Zähl-Einheit ohne Gewichtsquelle stecken. */
    public static function gpOhneGewicht(Team $team): Builder
    {
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->select('id');

        return FoodAlchemistGp::visibleToTeam($team)->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
            ->from('foodalchemist_recipe_ingredients as i')
            ->join('foodalchemist_vocab_units as v', 'v.id', '=', 'i.unit_vocab_id')
            ->whereColumn('i.gp_id', 'foodalchemist_gps.id')
            ->whereNull('i.deleted_at')
            ->whereIn('i.recipe_id', $rezepte)
            ->where('i.quantity', '>', 0)
            ->where(fn ($w) => $w->whereNull('i.is_optional')->orWhere('i.is_optional', false))
            ->where(fn ($w) => $w->whereNull('i.match_method')->orWhere('i.match_method', '!=', MatchMethod::Ignored->value))
            ->where('v.slug', '!=', 'qs')
            ->whereNull('v.default_in_g')
            ->whereNull('v.default_in_ml')
            ->whereNotExists(fn ($f) => $f->selectRaw('1')->from('foodalchemist_gp_forms as f')
                ->whereColumn('f.gp_id', 'i.gp_id')->whereColumn('f.form_slug', 'v.slug')->whereNull('f.deleted_at'))
            ->whereNotExists(fn ($c) => $c->selectRaw('1')->from('foodalchemist_gp_count_unit_defaults as c')
                ->whereColumn('c.gp_id', 'i.gp_id')->whereColumn('c.unit_vocab_id', 'i.unit_vocab_id')->whereNull('c.deleted_at'))
            ->where(fn ($w) => $w->where('v.slug', '!=', 'stk')->orWhereNull('foodalchemist_gps.piece_default_g')));
    }

    /**
     * Zeilen eines Rezepts ohne Gewicht — aus derselben Rechnung wie EK und Produktion (zeilenKostenUndMassen.ohne_gewicht).
     *
     * @return list<array{ingredient_id: int, name: string, menge: float, einheit: ?string, gp_id: ?int}>
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
                'menge' => (float) $z->quantity, 'einheit' => $z->unit?->display_de ?? $z->unit?->slug, 'gp_id' => $z->gp_id !== null ? (int) $z->gp_id : null];
        }

        return $out;
    }

    /** „Ei (1 Stück), Lorbeer (2 Blatt)“ für Hinweise. @param list<array{name: string, menge: float, einheit: ?string}> $zeilen */
    public static function text(array $zeilen): string
    {
        return implode(', ', array_map(fn ($z) => $z['name'] . ' (' . rtrim(rtrim(number_format($z['menge'], 3, ',', ''), '0'), ',')
            . ($z['einheit'] !== null ? ' ' . $z['einheit'] : '') . ')', $zeilen));
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
        $namen = self::text(array_values(array_filter(self::zeilen($recipe, $team), fn ($z) => in_array($z['ingredient_id'], $k['ohne_gewicht'], true))));
        $text = ($k['vorlaeufig'] ? 'EK vorläufig: ' : 'Ohne Gewicht: ') . $namen . '. '
            . ($k['vorlaeufig']
                ? 'Läuft im Verhältnis dieser Darreichung mit. Stückgewicht im Grundprodukt pflegen.'
                : 'Kosten über den Stückpreis, die Grammatur zählt sie nicht. Stückgewicht im Grundprodukt pflegen.');

        return ['ohne_gewicht' => $k['ohne_gewicht'], 'vorlaeufig' => (bool) $k['vorlaeufig'], 'text' => $text];
    }
}
