<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * 98 · 3.3 S4-0k (Muster K3 aus 98 · 3.4, wie SpeiseplanDokumentQuelle): Lese-Rückweg der Präsentation zum Foodbook-Dokument
 * über eine Schnittstelle. PresentationService fragt diese Quelle statt FoodbookService direkt; gebunden im
 * FoodAlchemistServiceProvider an FoodbookService. Kein Verhaltenswechsel: dieselbe Methode, derselbe Dienst.
 */
interface FoodbookDokumentQuelle
{
    /** Dokument-Daten des Foodbooks, siehe {@see \Platform\FoodAlchemist\Services\FoodbookService::dokumentDaten}. */
    public function dokumentDaten(Team $team, FoodAlchemistFoodbook $fb, bool $intern = false, array $kapitelFilter = [], bool $mitKaskade = false, ?FoodAlchemistOutlet $outlet = null, bool $mitHandbuchMengen = false, bool $mitTexten = false): array;
}
