<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Closure;
use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec-Flags (Diät/Herkunft, dynamisch über alle Ebenen). Ausgelagert aus
 * {@see \Platform\FoodAlchemist\Services\RecipeRecomputeService} (Diät-Fix 98, Größen-Wächter); Konfidenz und Speichern
 * bleiben dort.
 *
 * Tags sind DYNAMISCH aus der Rezeptur (User-Prinzip 2026-07-06): kommt ein nicht-veganer GP in ein Gericht, ist es nicht
 * mehr vegan. Quelle = GP-Tags (FoodAlchemistGp.tag_is_*); Sub-Rezepte liefern ihre bereits berechneten spec_is_*-Werte
 * (topologische Ordnung garantiert Kinder-vor-Eltern).
 *
 * Zwei Logiken:
 *  - ZUSICHERUNG (vegan/vegetarisch/halal/laktosefrei): AND mit NULL-Propagation — 1 nur wenn ALLE bekannten Zutaten
 *    zusichern und NICHTS unbekannt ist; 0 sobald eine verletzt; sonst NULL (unbekannt).
 *  - WARNUNG (enthält Schwein/Rind/Alkohol): OR — 1 sobald eine Zutat es trägt; 0 nur wenn alles bekannt und nichts
 *    trägt; sonst NULL.
 * spec_is_gluten_free bleibt normativ aus der Allergen-Kette (RecipeRecomputeService::allergene()).
 */
class DiaetEignung
{
    public const ASSURE = ['is_vegan', 'is_vegetarian', 'is_halal', 'is_lactose_free'];

    public const WARN = ['contains_pork', 'contains_beef', 'contains_alcohol'];   // Spec 94c: Alkohol aus den Artikeln des GP

    /** Die spec_*-Spaltennamen (Zusicherung + Warnung). @return list<string> */
    public static function felder(): array
    {
        return array_map(fn ($f) => "spec_{$f}", [...self::ASSURE, ...self::WARN]);
    }

    /**
     * Setzt spec_is_* / spec_contains_* am Rezept (ohne Speichern) und gibt die Zähler zurück.
     *
     * Keine eigene Dienst-Abhängigkeit (Spec 98 Klinke: sonst läge die Klasse im großen Kreis über GpAggregateService):
     * „gemappt?“ und „Alkohol im GP?“ reicht der RecipeRecomputeService als Closures herein.
     *
     * @param  Closure(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient): bool  $istGemappt
     * @param  Closure(\Platform\FoodAlchemist\Models\FoodAlchemistGp): (bool|null)  $enthaeltAlkohol
     * @return array{n_total: int, n_mapped: int}
     */
    public function setze(FoodAlchemistRecipe $recipe, Collection $zutaten, Closure $istGemappt, Closure $enthaeltAlkohol): array
    {
        $felder = [...self::ASSURE, ...self::WARN];
        /** @var array<string, list<bool|null>> $werte */
        $werte = array_fill_keys($felder, []);
        $nTotal = 0;
        $nMapped = 0;

        foreach ($zutaten as $z) {
            if ($z->is_optional) {
                continue;
            }
            $nTotal++;
            // Diät-Fix 98: „gemappt“ wie bei den Allergenen (istGemappt) — ein KI-Vorschlag unter 0.85 sichert nichts zu.
            if ($z->gp_id !== null && $z->gp !== null && $istGemappt($z)) {
                $nMapped++;
                foreach ($felder as $f) {
                    $werte[$f][] = $this->boolOrNull($f === 'contains_alcohol' ? $enthaeltAlkohol($z->gp) : $z->gp->{"tag_{$f}"});
                }
            } elseif ($z->referencedRecipe !== null) {
                $nMapped++;
                foreach ($felder as $f) {
                    $werte[$f][] = $this->boolOrNull($z->referencedRecipe->{"spec_{$f}"});
                }
            } else {                                               // ungemappt ⇒ NULL-Beitrag
                foreach ($felder as $f) {
                    $werte[$f][] = null;
                }
            }
        }

        foreach (self::ASSURE as $f) {
            $recipe->{"spec_{$f}"} = $this->mergeAssure($werte[$f]);
        }
        foreach (self::WARN as $f) {
            $recipe->{"spec_{$f}"} = $this->mergeWarn($werte[$f]);
        }
        $this->gegenDeklaration($recipe);

        return ['n_total' => $nTotal, 'n_mapped' => $nMapped];
    }

    /**
     * Diät-Fix 98 (c): Zusicherungen gegen die Deklaration desselben Rezepts prüfen. Läuft nach allergene() und
     * yieldUndZaehler(), beide Werte stehen also schon.
     *  - laktosefrei normativ wie glutenfrei: Milch enthalten/Spuren ⇒ false.
     *  - unbewertete Deklaration (allergens_confidence nicht low/medium/high) ⇒ keine Zusicherung: true wird NULL. Ein
     *    false bleibt — eine belegte Verletzung (Fisch ist nicht vegan) gilt auch ohne Deklaration.
     */
    private function gegenDeklaration(FoodAlchemistRecipe $recipe): void
    {
        if (in_array($recipe->allergen_milk, ['enthalten', 'spuren'], true)) {
            $recipe->spec_is_lactose_free = false;
        }
        if (! in_array($recipe->allergens_confidence, ['low', 'medium', 'high'], true)) {
            foreach (self::ASSURE as $f) {
                if ($recipe->{"spec_{$f}"} === true) {
                    $recipe->{"spec_{$f}"} = null;
                }
            }
        }
    }

    /** Zusicherung: 0 wenn eine verletzt, NULL wenn eine unbekannt, sonst 1. Leer ⇒ NULL (Diät-Fix 98: nichts sichert zu). */
    private function mergeAssure(array $werte): ?bool
    {
        if ($werte === [] || (! in_array(false, $werte, true) && in_array(null, $werte, true))) {
            return null;
        }

        return ! in_array(false, $werte, true);
    }

    /** Warnung: 1 wenn eine trägt, NULL wenn eine unbekannt, sonst 0. Leer ⇒ NULL (ohne Zutaten ist nichts belegt). */
    private function mergeWarn(array $werte): ?bool
    {
        if ($werte === [] || (! in_array(true, $werte, true) && in_array(null, $werte, true))) {
            return null;
        }

        return in_array(true, $werte, true);
    }

    private function boolOrNull(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }
}
