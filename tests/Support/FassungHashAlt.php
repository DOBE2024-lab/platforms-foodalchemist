<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;

/**
 * 98 · 3.2 · EINGEFRORENE Kopie der Inhalts-Hashes von FassungService, ConceptFassungService und FormatFassungService,
 * Stand main 3ba1a3510, vor der Fassungs-Basisklasse. NICHT anpassen: Der Zwillingstest (FassungHashZwillingTest) prüft,
 * dass die umgebauten Dienste bit-gleich hashen. Ein anderer Hash hieße: jede bestehende Fassung „weicht ab“ und das
 * nächste Veröffentlichen erzeugt für alle Abonnenten eine neue Fassung ohne inhaltliche Änderung.
 * Ändert sich der Hash später ABSICHTLICH (neues Feld, das nur gesetzt zählt), gehört die Änderung hierher mit Begründung.
 */
final class FassungHashAlt
{
    private const REZEPT_NIE = [
        'uuid', 'legacy_id', 'recipe_key', 'kopie_von_id', 'kopie_stand_at', 'created_at', 'updated_at', 'deleted_at',
        'ek_total_eur', 'ek_per_kg_eur', 'ek_price_basis', 'ek_n_ingredients_priced', 'ek_n_ingredients_total',
        'additional_costs_eur',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    private const ABGELEITET_PRAEFIXE = ['allergen', 'additive', 'nutri_', 'spec_', 'ek_', 'yield_kg'];

    private const ZUTAT_FELDER = ['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
        'quantity', 'quantity_max', 'unit_vocab_id', 'trimming_loss_pct', 'cooking_loss_pct', 'zeilenart',
        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
        'role', 'role_source', 'is_value_relevant', 'calc_mode'];

    private const KONZEPT_NIE = [
        'uuid', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'offer_id', 'kopie_von_id', 'kopie_stand_at', 'template_source_id',
        'price_per_person_cache', 'calculated_price_per_person', 'price_per_person_manual', 'ek_per_person_cache', 'price_incomplete', 'price_calculated_at',
        'price_calculation_source', 'price_calculation_version', 'price_override_reason', 'price_override_user_id',
        'price_override_at', 'price_override_expires_at', 'nutrition_cache', 'work_time_min_cache',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    private const KONZEPT_SLOT_NIE = ['id', 'uuid', 'team_id', 'concept_id', 'package_id', 'embedded_concept_id', 'sales_recipe_id', 'presentation_id',
        'price_value', 'price_basis', 'created_at', 'updated_at', 'deleted_at'];

    private const FORMAT_NIE = [
        'uuid', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'kopie_von_id', 'kopie_stand_at',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    private const FORMAT_SLOT_NIE = ['id', 'uuid', 'team_id', 'format_id', 'concept_id', 'created_at', 'updated_at', 'deleted_at'];

    /** @param array<int,int> $map Arbeitszeile → Fassung (Unter-Rezepte) */
    public static function rezept(FoodAlchemistRecipe $recipe, array $map): string
    {
        $kopf = collect($recipe->getAttributes())
            ->except(array_merge(['id', 'team_id', 'status', 'version'], self::REZEPT_NIE))
            ->reject(fn ($v, $k) => collect(self::ABGELEITET_PRAEFIXE)->contains(fn ($p) => str_starts_with($k, $p)) && $k !== 'yield_kg_manual')
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))
            ->sortKeys()->all();
        $zutaten = $recipe->ingredients()->get()->map(function ($z) use ($map) {
            $felder = $z->only(self::ZUTAT_FELDER);
            if ($felder['referenced_recipe_id'] !== null) {
                $felder['referenced_recipe_id'] = $map[(int) $felder['referenced_recipe_id']] ?? (int) $felder['referenced_recipe_id'];
            }

            return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $felder);
        })->all();
        $schritte = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->orderBy('ebene')->orderBy('position')
            ->get(['ebene', 'position', 'phase', 'text'])->toArray();

        $standard = FoodAlchemistRecipeDarreichung::where('recipe_id', $recipe->id)
            ->orderByDesc('is_standard')->orderBy('id')->first();
        $darreichung = $standard === null ? null : [
            $standard->only(['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count', 'container_warm_vocab_id',
                'container_cold_vocab_id', 'serving_vehicle_vocab_id', 'vessel_gp_id'])
                + ($standard->vessel_cost_type !== null ? ['vessel_cost_type' => $standard->vessel_cost_type] : []) + $standard->inhaltErgaenzung()
                + (($zubGef = self::gefaessZubehoer($standard)) !== [] ? ['gefaess_zubehoer' => $zubGef] : []),
            $standard->deltas()->orderBy('recipe_ingredient_id')->get(['recipe_ingredient_id', 'quantity_override_g', 'omitted'])->toArray(),
            $standard->zubehoer()->reorder()->orderBy('gp_id')->get(['gp_id', 'quantity'])->toArray(),
        ];

        return hash('sha256', json_encode([$kopf, $zutaten, $schritte, $darreichung], JSON_UNESCAPED_UNICODE));
    }

    private static function gefaessZubehoer(FoodAlchemistRecipeDarreichung $d): array
    {
        if ($d->vessel_gp_id === null) {
            return [];
        }
        $zeilen = array_map(fn ($z) => [$z['gp_id'], $z['menge']],
            app(\Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService::class)->liste((int) $d->vessel_gp_id, \Platform\Core\Models\Team::find($d->team_id))['zeilen']);
        sort($zeilen);

        return $zeilen;
    }

    /** @param array{recipe: array<int, FoodAlchemistRecipe>, concept: array<int, FoodAlchemistConcept>} $ziele */
    public static function konzept(FoodAlchemistConcept $concept, $slots, array $ziele): string
    {
        $kopf = collect($concept->getAttributes())
            ->except(array_merge(['id', 'team_id', 'price_mode', 'is_template'], self::KONZEPT_NIE))
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))
            ->sortKeys()->all();
        $zeilen = $slots->filter(fn ($s) => self::konzeptHatZiel($s, $ziele))->map(function (FoodAlchemistConceptSlot $s) use ($ziele) {
            $felder = collect($s->getAttributes())->except(self::KONZEPT_SLOT_NIE)->sortKeys()->all();
            $quelle = $s->presentation_id ? FoodAlchemistRecipeDarreichung::find($s->presentation_id) : null;

            return [$felder,
                null,
                $s->embedded_concept_id !== null ? (int) ($ziele['concept'][(int) $s->embedded_concept_id]->id ?? 0) : null,
                $s->sales_recipe_id !== null ? (int) ($ziele['recipe'][(int) $s->sales_recipe_id]->id ?? 0) : null,
                $quelle === null ? null : [$quelle->only(['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count']) + $quelle->inhaltErgaenzung(), (bool) $quelle->is_standard],
            ];
        })->values()->all();
        $pivots = [
            $concept->serviceMoments()->allRelatedIds()->sort()->values()->all(),
            $concept->seasons()->allRelatedIds()->sort()->values()->all(),
            $concept->targetGroups()->allRelatedIds()->sort()->values()->all(),
        ];

        return hash('sha256', json_encode([$kopf, $zeilen, $pivots], JSON_UNESCAPED_UNICODE));
    }

    private static function konzeptHatZiel(FoodAlchemistConceptSlot $s, array $ziele): bool
    {
        if ($s->package_id !== null) {
            return false;
        }
        if ($s->embedded_concept_id !== null) {
            return isset($ziele['concept'][(int) $s->embedded_concept_id]);
        }
        if ($s->sales_recipe_id !== null) {
            return isset($ziele['recipe'][(int) $s->sales_recipe_id]);
        }

        return true;
    }

    /** @param array<int, FoodAlchemistConcept> $ziele */
    public static function format(FoodAlchemistFormat $format, $slots, array $ziele): string
    {
        $kopf = collect($format->getAttributes())->except(array_merge(['id', 'team_id', 'status'], self::FORMAT_NIE))
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))->sortKeys()->all();
        $zeilen = $slots->filter(fn ($s) => $s->concept_id === null || isset($ziele[(int) $s->concept_id]))->map(function (FoodAlchemistFormatSlot $s) use ($ziele) {
            $felder = collect($s->getAttributes())->except(array_merge(self::FORMAT_SLOT_NIE, ['payload_json']))->sortKeys()->all();

            return [$felder, $s->concept_id !== null ? (int) $ziele[(int) $s->concept_id]->id : null, self::payloadUmgeschluesselt($s, $ziele)];
        })->values()->all();
        $pivots = [
            $format->serviceMoments()->allRelatedIds()->sort()->values()->all(),
            $format->seasons()->allRelatedIds()->sort()->values()->all(),
            $format->targetGroups()->allRelatedIds()->sort()->values()->all(),
        ];

        return hash('sha256', json_encode([$kopf, $zeilen, $pivots], JSON_UNESCAPED_UNICODE));
    }

    private static function payloadUmgeschluesselt(FoodAlchemistFormatSlot $s, array $ziele): array
    {
        $payload = (array) ($s->payload_json ?? []);
        if ($s->concept_id === null || ! isset($ziele[(int) $s->concept_id]) || empty($payload['wording_overrides'])) {
            return $payload;
        }
        $alt = DB::table('foodalchemist_concept_slots')->where('concept_id', $s->concept_id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->pluck('id')->values()->all();
        $neu = DB::table('foodalchemist_concept_slots')->where('concept_id', $ziele[(int) $s->concept_id]->id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->pluck('id')->values()->all();
        $umgeschluesselt = [];
        foreach ((array) $payload['wording_overrides'] as $slotId => $text) {
            $i = array_search((int) $slotId, array_map('intval', $alt), true);
            if ($i !== false && isset($neu[$i])) {
                $umgeschluesselt[(string) $neu[$i]] = $text;
            }
        }
        $payload['wording_overrides'] = $umgeschluesselt;

        return $payload;
    }
}
