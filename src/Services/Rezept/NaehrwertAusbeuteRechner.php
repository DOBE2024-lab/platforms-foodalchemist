<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\GpAggregateService;

/**
 * Rezept-Nährwerte je 100 g AUSBEUTE (GL-08 + Entscheid Dominique 17.11.2026, Regelwerk Basisrezepte §6a/§18.7).
 * Ausgelagert aus {@see \Platform\FoodAlchemist\Services\RecipeRecomputeService} (Spec 85 §9); die Verlust-Kaskade liefert der Recompute.
 *
 * Je Zeile, relativ zur Rohmenge (GL-08 §4.2: NUR g/ml-Faktor, kein stk-Fallback):
 *   masse     = (1 − Putz) × (1 − Gar)                    → Nenner (Bezugsmasse, gleiche Kaskade wie yield_kg)
 *   nährstoff = (1 − Putz) × (1 − Gar, NUR Kochmedium)    → Zähler
 * Garverlust heißt zweierlei: bei Lebensmitteln Verdampfen/Schrumpfen (Nährstoffe bleiben, Dichte steigt), bei der Zeilenart
 * „Medium“ (Kochwasser samt Salz, Frittieröl, Lake, weggegossener Fond) Verwerfen (Nährstoffe gehen mit). Putzverlust wird immer
 * verworfen. Nenner = die relevanten (gemappten) Zeilen, auch ohne Nährwerte („verdünnen“, Invariante 4); ungemappte bleiben
 * draußen (Invariante 2). Sub-Rezepte liefern Werte je 100 g IHRER Ausbeute, die Elternzeile misst die Menge des fertigen
 * Unterrezepts — dieselbe Masse.
 */
class NaehrwertAusbeuteRechner
{
    public function __construct(private GpAggregateService $gpAggregate) {}

    /** @return array{masse: float, naehrstoff: float} */
    public static function faktoren(float $putzPct, float $garPct, bool $kochmedium): array
    {
        $putz = 1 - $putzPct / 100;
        $gar = 1 - $garPct / 100;

        return ['masse' => $putz * $gar, 'naehrstoff' => $putz * ($kochmedium ? $gar : 1.0)];
    }

    /**
     * Schreibt nutri_* (je 100 g Ausbeute), Zähler und Konfidenz auf das Rezept.
     *
     * @param  Collection<int, FoodAlchemistRecipeIngredient>  $relevant  nicht optional, nicht qs, gemappt
     * @param  callable(FoodAlchemistRecipeIngredient): array{0: float, 1: float}  $verluste  [Putz %, Gar %] aus der Kaskade
     */
    public function aggregiere(FoodAlchemistRecipe $recipe, Collection $relevant, callable $verluste, ?Team $sicht): void
    {
        $totalG = 0.0;
        $summen = ['kcal' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0, 'salt' => 0.0, 'sugar' => 0.0, 'satfat' => 0.0];
        $nMapped = 0;

        foreach ($relevant as $z) {
            $menge = $z->quantity_max !== null ? ((float) $z->quantity + (float) $z->quantity_max) / 2 : (float) $z->quantity;
            $mengeG = $menge * (float) ($z->unit?->default_in_g ?? $z->unit?->default_in_ml ?? 0);
            $f = self::faktoren(...[...$verluste($z), $z->istKochmedium()]);

            $werte = null;
            if ($z->referencedRecipe !== null) {                   // Sub-Pfad gewinnt (GL-08 4.1)
                $sub = $z->referencedRecipe;
                if ($sub->nutri_kcal_per_100g !== null) {
                    $werte = [
                        'kcal' => (float) $sub->nutri_kcal_per_100g,
                        'protein' => (float) ($sub->nutri_protein_g_per_100g ?? 0),
                        'fat' => (float) ($sub->nutri_fat_g_per_100g ?? 0),
                        'carbs' => (float) ($sub->nutri_carbs_g_per_100g ?? 0),
                        'salt' => (float) ($sub->nutri_salt_g_per_100g ?? 0),
                        'sugar' => (float) ($sub->nutri_sugar_g_per_100g ?? 0),
                        'satfat' => (float) ($sub->nutri_saturated_fat_g_per_100g ?? 0),
                    ];
                }
            } elseif ($z->gp !== null) {
                // GL-08-Verfeinerung (Salz-Fall): KURATIERTE GP-Werte (nutri_source='manual')
                // dürfen LA-Lücken füllen — z.B. Speisesalz-LAs mit sodium, aber ohne kcal
                // (Leit-Indikator schlug fehl → Salz trug 0 bei). KI-Schätzungen bleiben
                // weiterhin Panel-only und verfälschen keine Rezept-Nährwerte.
                $n = $this->gpAggregate->naehrwerte($z->gp, mitKiFallback: $z->gp->nutri_source === 'manual', sicht: $sicht);
                if ($n['energy_kcal']['avg'] !== null) {           // kcal = Leit-Indikator
                    $werte = [
                        'kcal' => $n['energy_kcal']['avg'],
                        'protein' => $n['protein']['avg'] ?? 0.0,
                        'fat' => $n['fat']['avg'] ?? 0.0,
                        'carbs' => $n['carbs_absorbable']['avg'] ?? 0.0,
                        'salt' => $n['salt_g']['avg'] ?? 0.0,      // sodium×0.0025 (GL-08 §4.2)
                        'sugar' => $n['sugar']['avg'] ?? 0.0,
                        'satfat' => $n['saturated_fat']['avg'] ?? 0.0,
                    ];
                }
            }

            $totalG += $mengeG * $f['masse'];                      // Ausbeute; auch ohne Werte „verdünnen" (Invariante 4)
            if ($mengeG > 0 && $werte !== null) {
                $nMapped++;
                foreach ($summen as $k => $v) {
                    $summen[$k] += ($werte[$k] ?? 0.0) * $mengeG * $f['naehrstoff'] / 100;  // fehlender Wert ⇒ 0-Anteil (GT-02)
                }
            }
        }

        $nTotal = $relevant->count();
        if ($nMapped > 0 && $totalG > 0) {
            $recipe->nutri_kcal_per_100g = round($summen['kcal'] * 100 / $totalG, 1);
            $recipe->nutri_protein_g_per_100g = round($summen['protein'] * 100 / $totalG, 2);
            $recipe->nutri_fat_g_per_100g = round($summen['fat'] * 100 / $totalG, 2);
            $recipe->nutri_carbs_g_per_100g = round($summen['carbs'] * 100 / $totalG, 2);
            $recipe->nutri_salt_g_per_100g = round($summen['salt'] * 100 / $totalG, 3);
            $recipe->nutri_sugar_g_per_100g = round($summen['sugar'] * 100 / $totalG, 2);
            $recipe->nutri_saturated_fat_g_per_100g = round($summen['satfat'] * 100 / $totalG, 2);
        } else {
            $recipe->nutri_kcal_per_100g = null;
            $recipe->nutri_protein_g_per_100g = null;
            $recipe->nutri_fat_g_per_100g = null;
            $recipe->nutri_carbs_g_per_100g = null;
            $recipe->nutri_salt_g_per_100g = null;
            $recipe->nutri_sugar_g_per_100g = null;
            $recipe->nutri_saturated_fat_g_per_100g = null;
        }
        $recipe->nutri_n_ingredients_total = $nTotal;
        $recipe->nutri_n_ingredients_mapped = $nMapped;
        $recipe->nutri_confidence = match (true) {                  // GL-08 §4.3 (erste zutreffende)
            $nTotal === 0, $nMapped === 0 => 'unknown',
            $nMapped === $nTotal => 'high',
            $nMapped / $nTotal >= 0.8 => 'medium',
            default => 'low',
        };
        $recipe->nutri_aggregated_at = now();
    }
}
