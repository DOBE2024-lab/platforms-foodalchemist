<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Concerns\SetztZeileMitWallStart;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Services\Produktion\ProductionCapacityService;
use Platform\FoodAlchemist\Services\Produktion\ProductionOrderService;
use Platform\FoodAlchemist\Support\Produktion\TagesplanAbfrage;

/**
 * Wandmonitor: Anleitung einer Zeile im Vollbild-Overlay — Schritte, Zutaten, Medien, Behälter, Etikett, Start und
 * Schritt-Haken (98 · 3.3 S6, aus Produktion\Tagesplan herausgelöst). Die Wand (TagesplanWall) öffnet per Event
 * `tagesplan-anleitung-oeffnen`; nach Start/Abhaken meldet das Kind `tagesplan-zeile-geaendert`, damit Lanes und Pass
 * neu zeichnen.
 *
 * Bleibt im Wandmodus gemountet (Zeile null = Overlay zu): Schritt-Haken und der Feedback-Bogen darin überleben das
 * Schließen wie vorher im Tagesplan. Solange eine Anleitung offen ist, pollt das Kind selbst alle 30 s — vorher hat der
 * Poll der Wand die offene Anleitung mitgezeichnet (Zeilenstatus von anderen Geräten).
 */
class TagesplanAnleitung extends Component
{
    use SetztZeileMitWallStart;

    #[Reactive]
    public string $von = '';

    #[Reactive]
    public string $bis = '';

    #[Reactive]
    public ?int $postenFilter = null;

    /** Zeile, deren Anleitung offen ist (null = zu). */
    public ?int $lineId = null;

    /** Flüchtige Step-Checks je geöffneter Anleitungszeile. Dauerhaft ist die Zeile selbst. */
    public array $anleitungStepStatus = [];

    public ?string $fehler = null;

    #[On('tagesplan-anleitung-oeffnen')]
    public function oeffneAnleitung(int $lineId): void
    {
        $this->lineId = $lineId;
    }

    public function anleitungSchliessen(): void
    {
        $this->lineId = null;
    }

    public function anleitungStarten(ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            if ($this->lineId === null) {
                return;
            }

            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->lineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($this->lineId), ProductionLineStatus::InProgress, $svc, true);
            $this->zeileGeaendert();
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anleitungStepUmschalten(int $index, ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $anleitung = $this->aktuelleAnleitung($kap);
            if ($anleitung === null || ! array_key_exists($index, $anleitung['arbeitsschritte'])) {
                return;
            }

            $lineId = (int) $anleitung['line_id'];
            $schritte = collect($this->anleitungStepStatus[$lineId] ?? [])->map(fn ($i) => (int) $i);
            $schritte = $schritte->contains($index)
                ? $schritte->reject(fn ($i) => $i === $index)
                : $schritte->push($index);

            $this->anleitungStepStatus[$lineId] = $schritte->unique()->sort()->values()->all();
            $this->anleitungAlsErledigtWennVollstaendig($anleitung, $svc);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anleitungAlleStepsUmschalten(ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $anleitung = $this->aktuelleAnleitung($kap);
            if ($anleitung === null) {
                return;
            }

            $lineId = (int) $anleitung['line_id'];
            $alle = array_keys($anleitung['arbeitsschritte']);
            $erledigt = collect($this->anleitungStepStatus[$lineId] ?? [])->intersect($alle)->count();
            if ($alle !== [] && $erledigt === count($alle)) {
                $this->anleitungStepStatus[$lineId] = [];
                if (($anleitung['line_status'] ?? null) === ProductionLineStatus::Done->value) {
                    $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
                    $this->lineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($lineId), ProductionLineStatus::Open, $svc, true);
                    $this->zeileGeaendert();
                }

                return;
            }

            $this->anleitungStepStatus[$lineId] = $alle;
            $this->anleitungAlsErledigtWennVollstaendig($anleitung, $svc);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function anleitungAlsErledigtWennVollstaendig(array $anleitung, ProductionOrderService $svc): void
    {
        $lineId = (int) $anleitung['line_id'];
        $alle = array_keys($anleitung['arbeitsschritte']);
        if ($alle === []) {
            return;
        }

        $erledigt = collect($this->anleitungStepStatus[$lineId] ?? [])->intersect($alle)->count();
        if ($erledigt !== count($alle) || ($anleitung['line_status'] ?? null) === ProductionLineStatus::Done->value) {
            return;
        }

        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $this->lineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($lineId), ProductionLineStatus::Done, $svc, true);
        $this->zeileGeaendert();
    }

    private function aktuelleAnleitung(ProductionCapacityService $kap): ?array
    {
        if ($this->lineId === null) {
            return null;
        }

        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $zeilen = $kap->tagesplanZeilen($team, $this->von, $this->bis, true);

        return (new TagesplanAbfrage)->anleitungAufloesen($zeilen, $this->lineId, $this->anleitungStepStatus);
    }

    private function zeileGeaendert(): void
    {
        $this->dispatch('tagesplan-zeile-geaendert')->to(TagesplanWall::class);
    }

    public function render(ProductionCapacityService $kap)
    {
        $anleitung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($this->lineId !== null && $team !== null) {
            $zeilen = $kap->tagesplanZeilen($team, $this->von, $this->bis, true);
            if ($this->postenFilter !== null) {
                $zeilen = $zeilen->where('station_id', $this->postenFilter);
            }
            $anleitung = (new TagesplanAbfrage)->anleitungAufloesen($zeilen, $this->lineId, $this->anleitungStepStatus);
        }

        return view('foodalchemist::livewire.produktion.tagesplan-anleitung', ['anleitung' => $anleitung]);
    }
}
