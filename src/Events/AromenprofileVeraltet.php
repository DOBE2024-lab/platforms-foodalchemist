<?php

namespace Platform\FoodAlchemist\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 98 · 3.3 S7 P2 · Nach einem Recompute sind die Aromenprofile (Spec 60) der betroffenen Rezepte neu zu ziehen. Listener: RezeptProfil::fuer
 * je Rezept, in der Reihenfolge der Ids. Der Absender (RecipeRecomputeService) gibt sie topologisch sortiert mit, Kinder vor Eltern,
 * weil der Eltern-Hash die Kinder enthält.
 * Synchron und sofort (kein Queue, kein After-Commit) wie in CQ 3.4: Der Listener läuft im selben Aufruf und an derselben Stelle wie vorher
 * der Direktaufruf; Exceptions erreichen den Aufrufer, der sie dort wie bisher abfängt (best effort, I8). Damit ruft die Rezept-Neuberechnung
 * die Pairing-Schicht nicht mehr direkt. Registriert im FoodAlchemistServiceProvider (Test: Spec98AbhaengigkeitsRichtungTest).
 */
final class AromenprofileVeraltet
{
    use Dispatchable;

    /** @param  list<int>  $recipeIds  topologisch sortiert (Kinder vor Eltern) */
    public function __construct(public readonly array $recipeIds) {}
}
