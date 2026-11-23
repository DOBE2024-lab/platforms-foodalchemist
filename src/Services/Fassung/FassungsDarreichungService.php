<?php

namespace Platform\FoodAlchemist\Services\Fassung;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/**
 * Spec 82 D9: Darreichung einer Rezept-Fassung zur Quell-Darreichung des Arbeitsstands finden oder anhängen.
 * Lag bis Plan 98 · 2.6c im alten PaketFassungService; unverändert hierher verschoben (genutzt von ConceptFassungService).
 */
class FassungsDarreichungService
{
    /** Eingaben einer Darreichung, die ihren Inhalt bestimmen (wie FassungService). */
    private const DARREICHUNG_FELDER = ['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count', 'container_warm_vocab_id',
        'container_cold_vocab_id', 'serving_vehicle_vocab_id', 'vessel_gp_id'];

    private const DARREICHUNG_NIE = ['uuid', 'legacy_id', 'recipe_id', 'ek_portion', 'markup_class_id', 'sales_net', 'calculated_sales_net',
        'sales_gross', 'price_calculation_source', 'price_calculation_version', 'price_calculated_at', 'price_override_reason',
        'price_override_user_id', 'price_override_at', 'price_override_expires_at', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * D9: Die Darreichung der Rezept-Fassung, die der Quell-Darreichung des Arbeitsstands entspricht. Standard → die Standard-
     * Darreichung der Fassung; sonst eine bereits angehängte gleiche Kopie, sonst wird sie angehängt (Deltas über Zutaten-Position).
     */
    public function fassungsDarreichung(FoodAlchemistRecipe $fassung, FoodAlchemistRecipeDarreichung $quelle): ?FoodAlchemistRecipeDarreichung
    {
        $vorhandene = FoodAlchemistRecipeDarreichung::where('recipe_id', $fassung->id)->orderByDesc('is_standard')->orderBy('id')->get();
        if ($quelle->is_standard) {
            return $vorhandene->first(fn ($d) => (bool) $d->is_standard) ?? $vorhandene->first();
        }
        $gesucht = $this->darreichungsInhalt($quelle);
        foreach ($vorhandene as $d) {
            if (! $d->is_standard && $this->gleich($this->darreichungsInhalt($d), $gesucht)) {
                return $d;
            }
        }
        $kopie = $quelle->replicate(self::DARREICHUNG_NIE);
        $kopie->forceFill(['recipe_id' => $fassung->id, 'is_standard' => false, 'price_mode' => 'auto'])->save();

        // Deltas: Zutat der Arbeitszeile → Zutat der Fassung über (Position, gp_id, Unter-Rezept) — passt nichts, entfällt das Delta
        $arbeit = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $quelle->recipe_id)->whereNull('deleted_at')->get(['id', 'position', 'gp_id'])->keyBy('id');
        $ziel = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $fassung->id)->whereNull('deleted_at')->get(['id', 'position', 'gp_id']);
        foreach ($quelle->deltas()->get() as $d) {
            $z = $arbeit[(int) $d->recipe_ingredient_id] ?? null;
            $treffer = $z === null ? null : $ziel->first(fn ($t) => (int) $t->position === (int) $z->position && (int) $t->gp_id === (int) $z->gp_id);
            if ($treffer === null) {
                continue;
            }
            $d->replicate(['uuid', 'legacy_id'])->forceFill(['presentation_id' => $kopie->id, 'recipe_ingredient_id' => $treffer->id])->save();
        }
        foreach ($quelle->zubehoer()->get() as $z) {
            $z->replicate(['uuid'])->forceFill(['presentation_id' => $kopie->id])->save();   // Zubehör & Verpackung geht mit (EK der Darreichung)
        }

        return $kopie;
    }

    /** Inhalt einer Darreichung (Form + Deltas über Zutaten-Position) für Hash und Wiederfinden. */
    private function darreichungsInhalt(FoodAlchemistRecipeDarreichung $d): array
    {
        $positionen = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $d->recipe_id)->pluck('position', 'id');
        $deltas = $d->deltas()->get(['recipe_ingredient_id', 'quantity_override_g', 'omitted'])
            ->map(fn ($x) => [$positionen[(int) $x->recipe_ingredient_id] ?? null, $x->quantity_override_g, (bool) $x->omitted])
            ->sortBy(fn ($x) => (string) $x[0])->values()->all();

        $zubehoer = $d->zubehoer()->reorder()->orderBy('gp_id')->get(['gp_id', 'quantity'])->map(fn ($z) => [(int) $z->gp_id, (float) $z->quantity])->all();

        // Kostenart des Gefäßes (17.11.2026) zählt nur, wenn gesetzt — sonst bliebe kein bisheriger Inhalts-Hash gleich
        $felder = $d->only(self::DARREICHUNG_FELDER) + ($d->vessel_cost_type !== null ? ['vessel_cost_type' => $d->vessel_cost_type] : []) + $d->inhaltErgaenzung();

        return [$felder, $deltas, (bool) $d->is_standard, $zubehoer];
    }

    private function gleich(array $a, array $b): bool
    {
        return json_encode($a) === json_encode($b);
    }
}
