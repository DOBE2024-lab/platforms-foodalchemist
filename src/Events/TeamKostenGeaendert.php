<?php

namespace Platform\FoodAlchemist\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * CQ 3.4 · Alle Rezepte eines Teams sind neu berechnet. Listener: PricingCascadeService::recomputeTeam (läuft in der Sicht, in der das Event ausgelöst wird).
 * Synchron und sofort (kein Queue, kein After-Commit): Der Listener läuft im selben Aufruf und an derselben Stelle wie vorher der
 * Direktaufruf; Exceptions erreichen den Aufrufer wie bisher. Damit ruft die Rezept-Schicht nicht mehr nach oben.
 * Registriert im FoodAlchemistServiceProvider (Test: Spec98AbhaengigkeitsRichtungTest).
 */
final class TeamKostenGeaendert
{
    use Dispatchable;

    public function __construct(public readonly \Platform\Core\Models\Team $team) {}
}
