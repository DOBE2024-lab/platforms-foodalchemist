<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Produktion\Concerns\SetztZeileMitWallStart;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProductionPlanService;
use Platform\FoodAlchemist\Services\ProductionReadinessService;
use Platform\FoodAlchemist\Support\Produktion\TagesplanAbfrage;

/**
 * Spec 30 E3 — Tagesplan: was steht an welchem Tag an welchem Posten an, über ALLE Aufträge.
 *
 * Der Auftrag bleibt ein Punkt (ein Liefertag), aber seine Zeilen dürfen davor liegen
 * (`vorlauf_tage`). Diese Sicht ist deshalb eine Abfrage über Zeilen, keine neue
 * Auftrags-Struktur — Spec-18-Nicht-Ziel „Mehrtages-Produktionszeiträume" bleibt gewahrt.
 *
 * Jede Zeile trägt ihren Auftrag und den Liefertag als Kontext mit: am Posten weiß sonst
 * niemand, wofür der Fond eigentlich ist.
 */
class Tagesplan extends Component
{
    use SetztZeileMitWallStart;

    #[Url(as: 'von')]
    public string $von = '';

    #[Url(as: 'bis')]
    public ?string $bis = null;

    #[Url(as: 'tage')]
    public int $tage = 14;

    #[Url(as: 'posten')]
    public ?int $postenFilter = null;

    /** Gewählter Auftrag fürs Detail-Panel (3-Panel-Layout wie im Produktions-Browser). */
    #[Url(as: 'auftrag')]
    public ?int $orderId = null;

    /** Gewählter Tag fürs rechte Tagesdetail-Panel. */
    #[Url(as: 'tag')]
    public ?string $selectedDay = null;

    /** Ausgabe-Modus: '' = normal (3 Panels), 'wall' = Küchen-Wandmodus (chrome-arm, groß). */
    #[Url(as: 'display')]
    public string $display = '';

    #[Url(as: 'ansicht')]
    public string $ansicht = 'posten';

    /** Dashboard = Leitstand, Editor = Koch-/Planungsarbeitsplatz. Wird über die Route gesetzt. */
    public string $modus = 'dashboard';

    public ?string $fehler = null;

    /** Stufe 3 P3.4 — der zuletzt gerechnete Planungs-Vorschlag (Review vor Übernahme), oder null. */
    public ?array $vorschlag = null;

    /** Wandmonitor (Spec 35): Ansicht der Wand — 'lanes' (nach Posten) | 'mise' (zusammengefasst). */
    public string $wallAnsicht = 'lanes';

    /** Wandmonitor: Filter innerhalb der Posten-Lanes — alle | gerichte | basis. */
    public string $wallGruppenFilter = 'alle';


    /** Wandmonitor: Gericht-/Arbeitsblock, dessen enthaltene Rezepte gerade offen sind. */
    public ?string $wallGerichtKey = null;


    private const DASHBOARD_FENSTER = [3, 7, 14, 30];

    public function mount(): void
    {
        $this->modus = request()->routeIs('foodalchemist.produktion.tagesplan.editor')
            || request()->routeIs('foodalchemist.produktion.wandmonitor')
                ? 'editor'
                : 'dashboard';

        if (request()->routeIs('foodalchemist.produktion.wandmonitor')) {
            $this->display = 'wall';
            if (! request()->has('tage')) {
                $this->tage = 1;
            }
        }

        if ($this->von === '') {
            $this->von = now()->toDateString();
        }
        [$this->von, $this->bis] = $this->zeitraum();

        if ($this->modus === 'editor' && $this->display !== 'wall') {
            $this->dispatch('modal.open', name: 'tagesplan-editor');
        }
    }

    public function verschiebe(int $tage): void
    {
        [$von, $bis] = $this->zeitraum();
        $this->von = Carbon::parse($von)->addDays($tage)->toDateString();
        $this->bis = Carbon::parse($bis)->addDays($tage)->toDateString();
    }

    public function heute(): void
    {
        $tage = max(1, min(60, $this->tage));
        $this->von = now()->toDateString();
        $this->bis = now()->addDays($tage - 1)->toDateString();
    }

    public function updatedTage(): void
    {
        $this->tage = max(1, min(60, (int) $this->tage));
        $this->bis = Carbon::parse($this->von ?: now()->toDateString())->addDays($this->tage - 1)->toDateString();
    }

    public function updatedVon(): void
    {
        [$this->von, $this->bis] = $this->zeitraum();
    }

    public function updatedBis(): void
    {
        [$this->von, $this->bis] = $this->zeitraum();
    }

    public function waehleDashboardFenster(int $tage): void
    {
        if (in_array($tage, self::DASHBOARD_FENSTER, true)) {
            $this->tage = $tage;
            $this->bis = Carbon::parse($this->von ?: now()->toDateString())->addDays($tage - 1)->toDateString();
        }
    }

    public function dashboardTagVerschieben(int $tage): void
    {
        $delta = max(-14, min(14, $tage));
        $this->verschiebe($delta);
    }

    public function dashboardHeute(): void
    {
        $this->heute();
    }

    public function postenWaehlen(?int $id): void
    {
        $this->postenFilter = $this->postenFilter === $id ? null : $id;
    }

    /** Tag ins Detail-Panel wählen — zweiter Klick auf denselben Tag schließt es. */
    public function waehleTag(?string $tag): void
    {
        $this->selectedDay = $this->selectedDay === $tag ? null : $tag;
    }

    /** Schließt den Fullscreen-Editor und räumt den Deep-Link zurück aufs Dashboard. */
    public function editorSchliessen()
    {
        return $this->redirect(route('foodalchemist.produktion.tagesplan', array_filter([
            'von' => $this->von,
            'bis' => $this->bis,
            'tage' => $this->tage,
            'posten' => $this->postenFilter,
            'ansicht' => $this->ansicht,
            'tag' => $this->selectedDay,
        ])), navigate: true);
    }

    /** Stufe 3 P3.4 — Planungs-Vorschlag über das aktuelle Fenster rechnen (schreibt nichts). */
    public function vorschlagen(ProductionPlanService $planer): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet.';

            return;
        }
        [$von, $bis] = $this->zeitraum();
        $this->vorschlag = $planer->schlage($team, $von, $bis);
    }

    public function vorschlagUebernehmen(ProductionPlanService $planer): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->vorschlag === null) {
            return;
        }
        $planer->uebernehmen($team, $this->vorschlag['vorschlag']);
        $this->vorschlag = null;
    }

    public function vorschlagVerwerfen(): void
    {
        $this->vorschlag = null;
    }

    /** Zeile auf einen anderen Tag ziehen — der Vorlauf folgt dem Liefertag, nicht umgekehrt. */
    public function vorlaufSetzen(int $lineId, string $wert, ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $svc->assignLine($team, $lineId, ['vorlauf_tage' => (int) $wert]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Spec 30 E6 — Zeile abhaken. Der Tagesplan IST die Küchen-Sicht: nach Posten gefiltert
     * arbeitet man hier die Positionen des Tages ab. Ein zweiter Klick nimmt den Haken zurück
     * (Checkliste, kein Beleg-Lebenszyklus).
     */
    public function abhaken(int $lineId, ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $zeile = FoodAlchemistProductionOrderLine::findOrFail($lineId);
            $ziel = $zeile->line_status === ProductionLineStatus::Done
                ? ProductionLineStatus::Open
                : ProductionLineStatus::Done;

            $this->zeileSetzenMitWallStart($team, $zeile, $ziel, $svc, $this->display === 'wall');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Wandmonitor: eine zusammengefasste Mise-en-Place-Komponente gesammelt abhaken. */
    public function abhakenMise(int $lineId, ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            [$von, $bis] = $this->zeitraum();
            $zeilen = $kap->tagesplanZeilen($team, $von, $bis, true);
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
                $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($z->id), $ziel, $svc, $this->display === 'wall');
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

    public function render(ProductionCapacityService $kap)
    {
        $team = Auth::user()?->currentTeamRelation;
        [$von, $bis] = $this->zeitraum();

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $istWall = $this->display === 'wall';
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis, $istWall) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        // Wandmonitor-Extras nur im Wall-Modus rechnen (Readiness zieht eine zweite, teurere Abfrage).
        $readiness = ($istWall && $team !== null) ? app(ProductionReadinessService::class)->findings($team, $von, $bis) : [];
        $abfrage = new TagesplanAbfrage;
        $miseEnPlace = $istWall ? $abfrage->miseEnPlace($zeilen) : collect();
        // Spec 83 M2: Verknüpfung Ober-/Unterrezept aus den Auftragsdaten (vor den Gruppen: die Sortierung nutzt sie)
        $abfrage->wallVerknuepfung = $istWall ? $abfrage->wallVerknuepfungen($zeilen) : [];
        $abfrage->wallSammelposten = ($istWall && $team !== null)
            ? FoodAlchemistProductionStation::visibleToTeam($team)->where('ist_sammelposten', true)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $wallPostenGruppen = $istWall ? $abfrage->wallPostenGruppen($zeilen) : collect();
        $wallGericht = $istWall ? $abfrage->wallGerichtAufloesen($wallPostenGruppen, $this->wallGerichtKey) : null;

        $zeilenNachTag = $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString());
        $tagDetail = $abfrage->tagDetail($zeilenNachTag, $auslastung, $von, $bis, $this->selectedDay);

        return view('foodalchemist::livewire.produktion.tagesplan', [
            'modus' => $this->modus,
            'istWall' => $istWall,
            'von' => $von,
            'bis' => $bis,
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilenNachTag,
            'dashboard' => $abfrage->dashboard($zeilen, $auslastung, $von, $bis),
            'tagDetail' => $tagDetail,
            'readiness' => $readiness,
            'miseEnPlace' => $miseEnPlace,
            'wallPostenGruppen' => $wallPostenGruppen,
            'wallVerknuepfung' => $abfrage->wallVerknuepfung,
            'wallIstPass' => fn ($schluessel, ?string $name) => $abfrage->wallIstPassLane($schluessel, $name),
            'wallPassAuftraege' => fn ($laneGruppen) => $abfrage->wallPassAuftraege($laneGruppen),
            'wallGericht' => $wallGericht,
            'postenListe' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout($istWall ? 'foodalchemist::layouts.kiosk' : \Platform\FoodAlchemist\Support\FaShell::layout());
    }


    /** @return array{0:string,1:string} */
    private function zeitraum(): array
    {
        try {
            $von = Carbon::parse($this->von ?: now()->toDateString())->startOfDay();
        } catch (\Throwable) {
            $von = now()->startOfDay();
        }

        try {
            $bis = $this->bis ? Carbon::parse($this->bis)->startOfDay() : $von->copy()->addDays(max(1, min(60, $this->tage)) - 1);
        } catch (\Throwable) {
            $bis = $von->copy()->addDays(max(1, min(60, $this->tage)) - 1);
        }

        if ($bis->lt($von)) {
            $bis = $von->copy();
        }
        if ((int) $von->diffInDays($bis) >= 60) {
            $bis = $von->copy()->addDays(59);
        }

        $this->von = $von->toDateString();
        $this->bis = $bis->toDateString();
        $this->tage = (int) $von->diffInDays($bis) + 1;

        return [$this->von, $this->bis];
    }
}
