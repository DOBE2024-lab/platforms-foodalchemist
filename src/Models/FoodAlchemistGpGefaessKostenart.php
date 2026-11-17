<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Art und Preis eines Gefäß-GP je Team (Entscheid 17.11.2026): einweg | mehrweg | extern plus Preis je Stück.
 * Gilt für alle Darreichungen des Teams mit diesem Gefäß; die Darreichung kann die Art übersteuern (`vessel_cost_type`).
 * Eine Zeile je Team und GP (Unique auch über gelöschte Zeilen: Entfernen und neu Setzen stellt die Zeile wieder her).
 */
class FoodAlchemistGpGefaessKostenart extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_gp_gefaess_kostenarten';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'preis_stk' => 'decimal:4'];
}
