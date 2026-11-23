<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * 98 · 3.3 S4-0k (Muster K3): Lese-Rückweg des Soll-Ist-Abgleichs (CoverageService) zu Kapitel-Aggregat, Wareneinsatz-Ampel
 * und Gesamtpreis des Foodbooks über eine Schnittstelle statt über FoodbookService. Gebunden im FoodAlchemistServiceProvider
 * an FoodbookService. Kein Verhaltenswechsel: dieselben Methoden, derselbe Dienst.
 */
interface FoodbookAggregatQuelle
{
    /** Siehe {@see \Platform\FoodAlchemist\Services\FoodbookService::kapitelAggregat}. */
    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?FoodAlchemistOutlet $outlet = null): array;

    /** Siehe {@see \Platform\FoodAlchemist\Services\FoodbookService::wareneinsatzAmpel}. */
    public function wareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?FoodAlchemistOutlet $outlet = null): array;

    /** Siehe {@see \Platform\FoodAlchemist\Services\FoodbookService::gesamt}. */
    public function gesamt(Team $team, FoodAlchemistFoodbook $fb, ?FoodAlchemistOutlet $outlet = null): array;
}
