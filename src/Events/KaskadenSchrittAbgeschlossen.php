<?php

namespace Platform\FoodAlchemist\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;

/**
 * CQ 3.4 · Ein Kaskaden-Step ist fertig oder fehlgeschlagen ({@see \Platform\FoodAlchemist\Services\Kaskade\KaskadenSchritt}).
 * Synchron, sofort, kein Queue: Der Listener (PlanningCascadeService::nachSchrittAbschluss) läuft im selben Aufruf wie vorher
 * der Direktaufruf. Registriert im FoodAlchemistServiceProvider.
 */
final class KaskadenSchrittAbgeschlossen
{
    use Dispatchable;

    public function __construct(public readonly FoodAlchemistCascadeRunStep $step) {}
}
