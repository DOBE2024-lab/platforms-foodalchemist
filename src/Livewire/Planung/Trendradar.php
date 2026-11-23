<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Support\Planung\PlanungReiterDaten;

/**
 * Planungs-Leitstelle · Reiter „Trendradar“ (Spec 79; Spec 85 C1 PR-2, Verschiebung aus {@see Index}).
 *
 * Trends, Hypes und Fundstücke kombinieren → Briefing-Vorschau → Übernehmen schreibt Brief, Analyse und Herkunft in die Session.
 * Die Tab-Briefings (`eingabe`) und den Editor-Reiter führt weiter der Parent: das Kind meldet `planung.trend-uebernommen(scope)`,
 * der Parent liest die Session und füllt das Briefing. Am Handy bleibt der Kombinierer gesperrt (HandyLesebereich, keine Lese-Methoden).
 */
class Trendradar extends Component
{
    use HandyLesebereich;

    /** Aktive Planungs-Session des Parents; das Kind ist je Session gekeyt und liest die Auswahl beim Mount aus der Herkunft. */
    #[Reactive]
    public ?int $sessionId = null;

    /** 2.1d: Kandidaten nur laden, wenn der Reiter aktiv ist (Parent reicht `editorTab === 'trendradar'` herein). */
    #[Reactive]
    public bool $aktiv = false;

    /** Gewählte Impulse (Trends/Hypes und Inspirationen), kombiniert zu einem Briefing. */
    public array $trendWahl = ['trends' => [], 'inspirationen' => []];

    public string $trendSuche = '';

    public ?string $trendMeldung = null;

    /** Auswahl aus der Herkunft der Session wiederherstellen (früher in Index::ladeForm). */
    public function mount(): void
    {
        $team = $this->team();
        $session = ($team !== null && $this->sessionId !== null) ? app(PlanningSessionService::class)->get($team, $this->sessionId) : null;
        $refs = is_array($session?->source_trend_refs) ? $session->source_trend_refs : [];
        $this->trendWahl = [
            'trends' => array_map('strval', $refs['trend_ids'] ?? []),
            // Inspirationen; ältere Planungen mit einzelnen Fundstücken nehmen deren Inspiration
            'inspirationen' => $team !== null ? array_map('strval', app(PlanningSessionService::class)->refsInspirationen($team, $refs)) : [],
        ];
    }

    /**
     * Gewählte Impulse als Briefing übernehmen: Session-Brief/Analyse/Herkunft setzen, dann dem Parent melden. Der Parent
     * ÜBERSCHREIBT das Briefing der Ziel-Ebene (ausdrücklicher Klick), füllt die anderen nur, wenn leer, und wechselt den Reiter.
     */
    public function trendradarUebernehmen(string $scope, PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null || $this->sessionId === null || ! in_array($scope, Index::SCOPES, true)) {
            return;
        }
        $this->trendMeldung = null;
        try {
            $svc->trendradarUebernehmen($team, $this->sessionId,
                array_map('intval', $this->trendWahl['trends'] ?? []), array_map('intval', $this->trendWahl['inspirationen'] ?? []));
        } catch (\RuntimeException $e) {
            $this->trendMeldung = $e->getMessage();

            return;
        }
        $this->dispatch('planung.trend-uebernommen', scope: $scope);
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function render()
    {
        $daten = app(PlanungReiterDaten::class)->trend($this->team(), $this->sessionId !== null && $this->aktiv, $this->trendSuche, $this->trendWahl);
        $this->trendMeldung = $daten['trendMeldung'] ?? $this->trendMeldung;
        unset($daten['trendMeldung']);

        return view('foodalchemist::livewire.planung.trendradar', $daten);
    }
}
