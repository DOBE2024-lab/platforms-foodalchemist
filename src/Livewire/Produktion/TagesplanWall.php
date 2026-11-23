<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Concerns\HaktZeileAb;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\Produktion\ProductionCapacityService;
use Platform\FoodAlchemist\Services\Produktion\ProductionOrderService;
use Platform\FoodAlchemist\Services\Produktion\ProductionReadinessService;
use Platform\FoodAlchemist\Support\Produktion\TagesplanAbfrage;

/**
 * Küchenmonitor des Tagesplans (Spec 35, Wandmodus): Posten-Lanes, Mise en Place, Pass, Gericht-Overlay und die
 * Anleitung als Kind (98 · 3.3 S6, aus Produktion\Tagesplan herausgelöst). Zeitraum und Posten-Filter bleiben
 * URL-Zustand des Tagesplans; das Kind bekommt sie als #[Reactive]-Props, Tag- und Posten-Knöpfe rufen `$parent`.
 *
 * Pollt alle 30 s wie vorher die Wand im Tagesplan. Hat die Anleitung eine Zeile gestartet oder abgehakt, meldet sie
 * `tagesplan-zeile-geaendert` und die Wand zeichnet neu.
 */
class TagesplanWall extends Component
{
    use HaktZeileAb;

    #[Reactive]
    public string $von = '';

    #[Reactive]
    public string $bis = '';

    #[Reactive]
    public int $tage = 14;

    #[Reactive]
    public ?int $postenFilter = null;

    public ?string $fehler = null;

    /** Wandmonitor (Spec 35): Ansicht der Wand — 'lanes' (nach Posten) | 'mise' (zusammengefasst). */
    public string $wallAnsicht = 'lanes';

    /** Wandmonitor: Filter innerhalb der Posten-Lanes — alle | gerichte | basis. */
    public string $wallGruppenFilter = 'alle';

    /** Wandmonitor: Gericht-/Arbeitsblock, dessen enthaltene Rezepte gerade offen sind. */
    public ?string $wallGerichtKey = null;

    /** Wandmonitor: eine zusammengefasste Mise-en-Place-Komponente gesammelt abhaken. */
    public function abhakenMise(int $lineId, ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $zeilen = $kap->tagesplanZeilen($team, $this->von, $this->bis, true);
            $referenz = $zeilen->first(fn ($z) => (int) $z->id === $lineId);
            if ($referenz === null) {
                throw new \RuntimeException('Mise-en-Place-Zeile nicht im aktuellen Tagesfenster.');
            }

            $gruppe = $zeilen->filter(fn ($z) => $referenz->recipe_id !== null
                ? (int) $z->recipe_id === (int) $referenz->recipe_id
                : (string) $z->name === (string) $referenz->name);
            $ziel = $gruppe->every(fn ($z) => $z->line_status === ProductionLineStatus::Done->value)
                ? ProductionLineStatus::Open
                : ProductionLineStatus::Done;

            foreach ($gruppe as $z) {
                if ($z->line_status === $ziel->value) {
                    continue;
                }
                $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($z->id), $ziel, $svc, true);
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Wandmonitor (Spec 35): zwischen Posten-Lanes und zusammengefasster Mise-en-Place umschalten. */
    public function wallAnsichtSetzen(string $a): void
    {
        $this->wallAnsicht = in_array($a, ['lanes', 'mise'], true) ? $a : 'lanes';
    }

    public function wallGruppenFilterSetzen(string $filter): void
    {
        $this->wallGruppenFilter = in_array($filter, ['alle', 'gerichte', 'basis'], true) ? $filter : 'alle';
    }

    /**
     * Wandmonitor: Anleitung (Schritte + Zutaten) einer Zeile im Overlay öffnen. Die Anleitung ist ein Kind
     * (Produktion\TagesplanAnleitung, 98 · 3.3 S6) und hält Zeile und Schritt-Haken selbst.
     */
    public function oeffneAnleitung(int $lineId): void
    {
        $this->dispatch('tagesplan-anleitung-oeffnen', lineId: $lineId)->to(TagesplanAnleitung::class);
        $this->dispatch('modal.open', name: 'wall-anleitung');
    }

    /** Die Anleitung hat eine Zeile gestartet oder abgehakt: Lanes und Pass neu zeichnen. */
    #[On('tagesplan-zeile-geaendert')]
    public function zeileGeaendert(): void {}

    public function oeffneGericht(string $key): void
    {
        $this->wallGerichtKey = $key;
        $this->dispatch('modal.open', name: 'wall-gericht');
    }

    public function gerichtSchliessen(): void
    {
        $this->wallGerichtKey = null;
    }

    protected function imWandmodus(): bool
    {
        return true;
    }

    public function render(ProductionCapacityService $kap)
    {
        $team = Auth::user()?->currentTeamRelation;
        $von = $this->von;
        $bis = $this->bis;

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis, true) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        // Readiness zieht eine zweite, teurere Abfrage — nur hier im Wandmodus.
        $readiness = $team !== null ? app(ProductionReadinessService::class)->findings($team, $von, $bis) : [];
        $abfrage = new TagesplanAbfrage;
        $miseEnPlace = $abfrage->miseEnPlace($zeilen);
        // Spec 83 M2: Verknüpfung Ober-/Unterrezept aus den Auftragsdaten (vor den Gruppen: die Sortierung nutzt sie)
        $abfrage->wallVerknuepfung = $abfrage->wallVerknuepfungen($zeilen);
        $abfrage->wallSammelposten = $team !== null
            ? FoodAlchemistProductionStation::visibleToTeam($team)->where('ist_sammelposten', true)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $wallPostenGruppen = $abfrage->wallPostenGruppen($zeilen);

        return view('foodalchemist::livewire.produktion.tagesplan-wall', [
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString()),
            'readiness' => $readiness,
            'miseEnPlace' => $miseEnPlace,
            'wallPostenGruppen' => $wallPostenGruppen,
            'wallVerknuepfung' => $abfrage->wallVerknuepfung,
            'wallIstPass' => fn ($schluessel, ?string $name) => $abfrage->wallIstPassLane($schluessel, $name),
            'wallPassAuftraege' => fn ($laneGruppen) => $abfrage->wallPassAuftraege($laneGruppen),
            'wallGericht' => $abfrage->wallGerichtAufloesen($wallPostenGruppen, $this->wallGerichtKey),
        ]);
    }
}
