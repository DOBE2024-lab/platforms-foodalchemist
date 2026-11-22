<?php

namespace Platform\FoodAlchemist\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * CQ 3.4 · Gerichte haben neue Kosten → alte Auto-Pakete mit diesen Gerichten sind preis_stale. Listener: PaketService::markStaleForRecipe. Fällt in 2.6c mit PaketService samt Auslösung weg.
 * Synchron und sofort (kein Queue, kein After-Commit): Der Listener läuft im selben Aufruf und an derselben Stelle wie vorher der
 * Direktaufruf; Exceptions erreichen den Aufrufer wie bisher. Damit ruft die Rezept-Schicht nicht mehr nach oben.
 * Registriert im FoodAlchemistServiceProvider (Test: Spec98AbhaengigkeitsRichtungTest).
 */
final class GerichtePreisVeraltet
{
    use Dispatchable;

    public function __construct(public readonly array $recipeIds) {}
}
