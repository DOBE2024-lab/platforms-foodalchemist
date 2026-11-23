<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * 98 · 3.3 S3-0 (Muster K3 aus 98 · 3.4): Lese-Rückweg der Präsentation zum Speiseplan-Aushang über eine Schnittstelle.
 * PresentationService fragt diese Quelle statt SpeiseplanService direkt; gebunden im FoodAlchemistServiceProvider an
 * SpeiseplanService. Damit liegt SpeiseplanService in keinem Kreis mehr, und die Teile unter Services/Speiseplan erben
 * keine Kreis-Kanten. Kein Verhaltenswechsel: dieselbe Methode, derselbe Dienst.
 */
interface SpeiseplanDokumentQuelle
{
    /** Aushang-Daten der Woche, siehe {@see \Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService::dokumentDaten}. */
    public function dokumentDaten(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit = 'mittag', ?string $montag = null, bool $intern = false, bool $mitKaskade = false, ?FoodAlchemistOutlet $outlet = null, bool $mitPreis = false): array;
}
