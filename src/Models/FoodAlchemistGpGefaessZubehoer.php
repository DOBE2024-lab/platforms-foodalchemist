<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Zubehör am Gefäß-GP je Team (Entscheid 20.11.2026): „gehört dazu: menge × zubehoer_gp_id“ (z. B. 1 × Deckel am Schraubglas).
 * Jede Darreichung mit diesem Gefäß rechnet das Zubehör mit, jedes Teil zu seinem Preis und seiner Kostenart. Eine Ebene, nie rekursiv.
 * Es gilt die Liste des eigenen Teams, sonst die des nächsten Oberteams; gelöschte Zeilen markieren eine eigene (leere) Liste.
 */
class FoodAlchemistGpGefaessZubehoer extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_gp_gefaess_zubehoer';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'menge' => 'decimal:3', 'position' => 'integer'];
}
