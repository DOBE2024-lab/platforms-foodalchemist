<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Plan 98 · 2.6c: Rest des alten Pakets (Tabelle foodalchemist_packages), nur noch lesend.
 * Bleibt, bis die Bestellungs-Stellen in OrderService (Quellen-Typ paket) nach dem Umbau
 * der Bestellung nachgezogen sind; danach entfällt das Model. Schreiben ist gesperrt.
 */
class FoodAlchemistPaket extends Model
{
    use HasUuidV7, BelongsToTeamHierarchy, SoftDeletes, \Platform\FoodAlchemist\Models\Concerns\HatFassungen;

    protected $table = 'foodalchemist_packages';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $gesperrt = fn () => throw new \RuntimeException('Alte Pakete gibt es nicht mehr (98 · 2.6c) — nur noch lesend.');
        static::saving($gesperrt);
        static::deleting($gesperrt);
        static::restoring($gesperrt);
    }
}
