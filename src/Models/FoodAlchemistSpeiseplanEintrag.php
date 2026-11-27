<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speiseplan-Eintrag (M14) — belegt (woche × wochentag × mahlzeit)
 * mit GENAU EINEM: Concept, Paket oder Gericht (D-PLAN-1). Position-sortiert je Zelle.
 */
class FoodAlchemistSpeiseplanEintrag extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_plan_entries';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'week' => 'integer',
        'weekday' => 'integer',
        'position' => 'integer',
        'entry_date' => 'date',
        'pax' => 'integer',
    ];

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeiseplan::class, 'menu_plan_id');
    }

    /** @deprecated #486 deutscher Alias → mealPlan() */
    public function speiseplan(): BelongsTo
    {
        \Platform\FoodAlchemist\Support\VeralteterAlias::melde($this, __FUNCTION__, 'mealPlan');
        return $this->mealPlan();
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeiseplanLinie::class, 'line_id');
    }

    /** @deprecated #486 deutscher Alias → line() */
    public function linie(): BelongsTo
    {
        \Platform\FoodAlchemist\Support\VeralteterAlias::melde($this, __FUNCTION__, 'line');
        return $this->line();
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis')->withTrashed();   // Spec 82: Verweis löst auch Fassungen auf · Gericht-Verwaltung: weich gelöschte Gerichte bleiben als Verweis lesbar (historische Ansichten)
    }

    /** @deprecated #486 deutscher Alias → dish() */
    public function gericht(): BelongsTo
    {
        \Platform\FoodAlchemist\Support\VeralteterAlias::melde($this, __FUNCTION__, 'dish');
        return $this->dish();
    }

    /** Optionale Darreichung des Gerichts (null = Standard-Darreichung, s. DarreichungResolver::fuerSpeiseplanEintrag). */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    /** Identitäts-Schlüssel des Inhalts (für Wiederholungs-Check). */
    public function inhaltKey(): ?string
    {
        return match (true) {
            $this->concept_id !== null => 'c' . $this->concept_id,
            $this->recipe_id !== null => 'g' . $this->recipe_id,
            default => null,
        };
    }

    public function inhaltName(): string
    {
        // Spec 57 · 0.9: über die Haupt-Relationen, damit das Eager-Loading aus
        // SpeiseplanService::detail() greift (die deutschen Aliase luden je Eintrag nach).
        return $this->concept?->name ?? $this->dish?->name ?? '—';
    }
}
