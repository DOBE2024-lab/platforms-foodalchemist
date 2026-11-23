<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Livewire\Concepter\Browser as ConcepterBrowser;
use Platform\FoodAlchemist\Livewire\Planung\Brief;
use Platform\FoodAlchemist\Services\PlanningSessionService;

/**
 * 98 · 2.6c · „Konzept aus Brief“ aus dem Konzepter: Der Konzepter legt den Brief in die Session und leitet auf
 * `planung?tab=concept`. Der Trait sitzt auf beiden Teilen der Planung (Livewire ruft mountPlanKonzeptAusBrief nach
 * dem jeweiligen mount()):
 *  - Planung\Index öffnet eine freie Konzept-Planung (wie „schnellErstellen“) und lässt den Brief liegen.
 *  - Planung\Brief (Kind, mountet beim Rendern danach) holt ihn einmalig ab (lesen und löschen) und belegt das
 *    Briefing des Reiters Konzept vor.
 * Plan und Go laufen danach wie bisher (planAusBrief → GenerateConceptJob); hier entsteht kein KI-Aufruf.
 */
trait PlanKonzeptAusBrief
{
    public function mountPlanKonzeptAusBrief(): void
    {
        if (request('tab') !== 'concept') {
            return;
        }
        $brief = session(ConcepterBrowser::BRIEF_SESSION);
        if (! is_string($brief) || trim($brief) === '') {
            return;
        }
        if ($this instanceof Brief) {
            session()->forget(ConcepterBrowser::BRIEF_SESSION);
            $this->eingabe['concept']['brief'] = trim($brief);

            return;
        }
        $this->schnellErstellen('concept', app(PlanningSessionService::class));
    }
}
