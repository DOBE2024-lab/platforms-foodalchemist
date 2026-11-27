<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Concept-Slot (M10-01) — eine Rolle im Concept, gefüllt mit GENAU
 * EINEM: `embedded_concept_id` (eingebettetes Paket, kind=paket) ODER `recipe_id` (fest gesetztes
 * Gericht). Der Service erzwingt „genau eines". Position-sortiert.
 */
class FoodAlchemistConceptSlot extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_concept_slots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'level' => 'integer',
        'is_pflicht' => 'boolean',
        'quantity' => 'decimal:3',
        'price_value' => 'decimal:2',
    ];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** Kaskade (2026-08-24): eingebettetes Paket als kind=paket-Concept (die Alt-Spalte package_id fiel mit 98 · 3.1). */
    public function embeddedConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'embedded_concept_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** Befüllung B: fest gesetztes Gericht (VK-Rezept). */
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id')->ohneTeamSicht('verweis');
    }

    /** Optional: explizit gewählte Darreichung des Gerichts (Umbau-Spec Phase 3). */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    /** @deprecated #486 deutscher Alias → presentation() */
    public function darreichung(): BelongsTo
    {
        \Platform\FoodAlchemist\Support\VeralteterAlias::melde($this, __FUNCTION__, 'presentation');
        return $this->presentation();
    }

    /** Staffelpreise (nur bei type=header_preis + preis_basis='staffel'). */
    public function staffel(): HasMany
    {
        return $this->hasMany(FoodAlchemistConceptSlotStaffel::class, 'slot_id')->orderBy('min_persons');
    }
}
