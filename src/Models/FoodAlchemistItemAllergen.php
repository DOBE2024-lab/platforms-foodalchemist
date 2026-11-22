<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Support\Allergene;

/**
 * @ai.description LA-Allergene (M2-09, GL-01-Quelle): 14 EU-Allergene im 4-Wert-Modell
 * je Lieferantenartikel; NULL = unbekannt. details = Getreide-/Nuss-Unterarten.
 */
class FoodAlchemistItemAllergen extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Labels der 14 EU-Allergene (LMIV-Langform) — Quelle ist {@see Allergene::LANG}. */
    public const ALLERGENE = Allergene::LANG;

    protected $table = 'foodalchemist_item_allergens';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id')->ohneTeamSicht('verweis');
    }
}
