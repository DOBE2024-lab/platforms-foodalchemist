<?php

namespace Platform\FoodAlchemist\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * CQ 3.4 · Rezept-Kosten (EK, Darreichungspreise) einzelner Rezepte sind neu berechnet. Listener: Preisweitergabe an Pakete, Concepts und offene Auto-Angebote (PricingCascadeService::recomputeRecipes).
 * Synchron und sofort (kein Queue, kein After-Commit): Der Listener läuft im selben Aufruf und an derselben Stelle wie vorher der
 * Direktaufruf; Exceptions erreichen den Aufrufer wie bisher. Damit ruft die Rezept-Schicht nicht mehr nach oben.
 * Registriert im FoodAlchemistServiceProvider (Test: Spec98AbhaengigkeitsRichtungTest).
 */
final class RezeptKostenGeaendert
{
    use Dispatchable;

    public function __construct(public readonly array $recipeIds) {}
}
