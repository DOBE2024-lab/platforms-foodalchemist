<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Produktion\Concerns\HaktZeileAb;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\Produktion\ProductionCapacityService;
use Platform\FoodAlchemist\Services\Produktion\ProductionOrderService;
use Platform\FoodAlchemist\Services\Produktion\ProductionPlanService;
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
    use HaktZeileAb;

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

    public function render(ProductionCapacityService $kap)
    {
        $team = Auth::user()?->currentTeamRelation;
        [$von, $bis] = $this->zeitraum();

        // Wandmodus: der Küchenmonitor ist das Kind TagesplanWall (98 · 3.3 S6) und rechnet seine Daten selbst.
        if ($this->display === 'wall') {
            return view('foodalchemist::livewire.produktion.tagesplan', ['modus' => $this->modus, 'istWall' => true, 'von' => $von, 'bis' => $bis])
                ->layout('foodalchemist::layouts.kiosk');
        }

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis, false) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        $abfrage = new TagesplanAbfrage;

        $zeilenNachTag = $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString());
        $tagDetail = $abfrage->tagDetail($zeilenNachTag, $auslastung, $von, $bis, $this->selectedDay);

        return view('foodalchemist::livewire.produktion.tagesplan', [
            'modus' => $this->modus,
            'istWall' => false,
            'von' => $von,
            'bis' => $bis,
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilenNachTag,
            'dashboard' => $abfrage->dashboard($zeilen, $auslastung, $von, $bis),
            'tagDetail' => $tagDetail,
            'postenListe' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }


    /** @return array{0:string,1:string} */
    /** Abhaken im Editor; der Wandmodus ist seit 98 · 3.3 S6 das Kind TagesplanWall. */
    protected function imWandmodus(): bool
    {
        return $this->display === 'wall';
    }

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
