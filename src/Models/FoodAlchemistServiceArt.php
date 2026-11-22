<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Service-Art der Planung (CQ 2.5b): Tellerservice, Buffet, Flying Service, Stehempfang, Boxed — wie serviert wird.
 * Getrennt von den Darreichungs-Servierformen ({@see FoodAlchemistServierform}). Grundbestand global, gepflegt in den Einstellungen.
 */
class FoodAlchemistServiceArt extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_service_arten';

    protected $guarded = ['id'];

    protected $casts = ['is_inactive' => 'bool'];
}
