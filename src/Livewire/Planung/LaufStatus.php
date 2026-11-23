<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Plattform\WorkerHealthService;

/**
 * Code-Qualität 2.1d · schlanker Poll der Planung. Vorher pollte die ganze `Planung/Index` (3,2k Zeilen) alle 1,5 s
 * bzw. 3 s und baute dabei Board, Lauf, Cockpit, Composer, Trends und Auswahllisten neu (Bericht 97: 60–80 Abfragen
 * je Poll). Jetzt pollt nur diese Komponente: Sie liest einen **Stand** der beobachteten Läufe (Status, letzte
 * Änderung an Lauf und Schritten, Phasen, Watchdog-Schwelle, offene externe KI-Aufträge, Worker-Ampel) und meldet `planung.lauf-geaendert` nur, wenn er sich
 * ändert. Erst dann rendert die Planung neu ({@see Index::laufGeaendert}).
 *
 * `$laufIds` = die Läufe, die gerade etwas melden (Cockpit: der offene Lauf; Board: alle laufenden).
 * `$neueBeobachten` (Board): ein neu gestarteter Lauf des Teams zählt auch als Änderung.
 */
class LaufStatus extends Component
{
    /** @var list<int> */
    #[Locked]
    public array $laufIds = [];

    #[Locked]
    public bool $neueBeobachten = false;

    /** Poll-Takt als Livewire-Modifier (Cockpit 1500ms, Board 3s). */
    #[Locked]
    public string $takt = '1500ms';

    /** Ab so vielen Sekunden ohne Fortschritt schlägt der Watchdog der Planung an ({@see Index::WATCHDOG_SEKUNDEN}). */
    #[Locked]
    public int $watchdogSekunden = 90;

    public string $stand = '';

    public function mount(): void
    {
        $this->laufIds = array_values(array_unique(array_map('intval', $this->laufIds)));
        $this->stand = $this->stand();
    }

    /** Poll-Ziel: nur bei geändertem Stand die Planung neu zeichnen lassen. */
    public function pruefen(): void
    {
        if ($this->laufIds === [] && ! $this->neueBeobachten) {
            $this->dispatch('planung.lauf-geaendert');   // nichts zu beobachten (Lauf weg): die Planung räumt ihren Lauf-Zustand auf

            return;
        }
        $neu = $this->stand();
        if ($neu !== $this->stand) {
            $this->stand = $neu;
            $this->dispatch('planung.lauf-geaendert');
        }
    }

    /**
     * Fingerabdruck der beobachteten Läufe: Läufe, Schritte, offene externe KI-Aufträge (zwei Abfragen) und der Ampel-Zustand des
     * Workers (Cache) — mit `$neueBeobachten` eine Abfrage mehr. Ampel und KI-Warten springen damit beim nächsten Poll um, nicht erst
     * bei der nächsten Schritt-Änderung (Kurator 22.11.).
     */
    private function stand(): string
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return '';
        }
        $teile = ['worker:' . (app(WorkerHealthService::class)->status()['state'] ?? 'unbekannt')];
        if ($this->laufIds !== []) {
            $runs = FoodAlchemistCascadeRun::visibleToTeam($team)->whereIn('id', $this->laufIds)->orderBy('id')
                ->get(['id', 'status', 'updated_at', 'created_at']);
            $steps = FoodAlchemistCascadeRunStep::whereIn('cascade_run_id', $runs->pluck('id')->all())
                ->get(['id', 'team_id', 'cascade_run_id', 'status', 'phase', 'deferred', 'updated_at', 'context_snapshot', 'generator_run_id', 'ref_type', 'ref_id']);
            $kiOffen = collect(app(PlanningCascadeService::class)->kiWartend($steps))->sum('anzahl');
            foreach ($runs as $r) {
                $eigene = $steps->where('cascade_run_id', $r->id);
                // Der Watchdog hängt an der Zeit, nicht an einer Änderung: das Überschreiten der Schwelle zählt als Änderung.
                $alt = $r->status === 'running' && $r->created_at !== null && $r->created_at->diffInSeconds(now()) > $this->watchdogSekunden;
                // updated_at hat Sekunden-Auflösung: Status, Phase und `deferred` je Schritt fangen zwei Änderungen in derselben Sekunde.
                $schritte = $eigene->sortBy('id')->map(fn ($st) => $st->id . ':' . $st->status . ':' . $st->phase . ':' . md5((string) json_encode($st->deferred)))->implode(',');
                $teile[] = implode('|', [$r->id, $r->status, (string) $r->updated_at, (string) $eigene->max('updated_at'), md5($schritte), $alt ? 'alt' : '']);
            }
            $teile[] = 'ki:' . $kiOffen;
        }
        if ($this->neueBeobachten) {
            $teile[] = 'max:' . (int) FoodAlchemistCascadeRun::visibleToTeam($team)->max('id');
        }

        return implode(';', $teile);
    }

    public function render()
    {
        return view('foodalchemist::livewire.planung.lauf-status');
    }
}
