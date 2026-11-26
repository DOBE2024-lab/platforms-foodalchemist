<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Kaskade\KaskadenReaperSweep;
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
 *
 * Hängende Läufe (io 23.11.: Board pollte tagelang alle 3 s auf einem toten Lauf):
 * - Takt nach Stillstand ({@see taktFuer}): bis 2 min der Grundtakt, danach 15 s, ab 30 min 60 s.
 * - Selbstheilung ({@see heilen}): Ab 30 min Stillstand ruft der Poll den Reaper ({@see PlanningCascadeService::reapeVerwaisteSteps}),
 *   höchstens alle 5 min je Lauf (Cache-Sperre), nur für das Besitzer-Team; ein Fehler im Reaper bricht den Poll nie.
 * - Board: kurzer Hinweis „hängt seit … — Fortsetzen“ mit Link in den Lauf.
 * - Team-Sweep ({@see KaskadenReaperSweep}): auch Läufe, die das Board nicht beobachtet (ältere Läufe einer Session, Läufe ohne Session).
 *   Unabhängig vom Board heilt der Zeitplan `foodalchemist:kaskade-reaper` alle Teams.
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

    /** Wirksamer Poll-Takt nach Stillstand (Grundtakt `$takt`, 15 s, 60 s). */
    public string $aktuellerTakt = '';

    /** @var list<array{run_id: int, session_id: ?int, titel: string, minuten: int}> Board: Läufe ohne Fortschritt seit mindestens 30 min */
    public array $haengend = [];

    /** Sekunden seit dem letzten Fortschritt (jüngster beobachteter Lauf), null = nichts läuft */
    private ?int $stillSekunden = null;

    /** @var array<int, int> Lauf-Id → Sekunden ohne Fortschritt */
    private array $stillJeLauf = [];

    public const STILL_15S = 120;

    public const STILL_60S = 1800;

    public const HEILEN_SPERRE_SEK = 300;

    public function mount(): void
    {
        $this->laufIds = array_values(array_unique(array_map('intval', $this->laufIds)));
        $this->stand = $this->stand();
        $this->takten();
    }

    /** Takt-Stufe nach Stillstand. Öffentlich und statisch für den Test der Stufenwahl. */
    public static function taktFuer(string $grundtakt, ?int $stillSekunden): string
    {
        return match (true) {
            $stillSekunden === null => $grundtakt,
            $stillSekunden >= self::STILL_60S => '60s',
            $stillSekunden >= self::STILL_15S => '15s',
            default => $grundtakt,
        };
    }

    /** Poll-Ziel: nur bei geändertem Stand die Planung neu zeichnen lassen. */
    public function pruefen(): void
    {
        if ($this->laufIds === [] && ! $this->neueBeobachten) {
            $this->dispatch('planung.lauf-geaendert');   // nichts zu beobachten (Lauf weg): die Planung räumt ihren Lauf-Zustand auf

            return;
        }
        $neu = $this->stand();
        if ($this->heilen() > 0) {
            $neu = $this->stand();
        }
        $this->takten();
        if ($neu !== $this->stand) {
            $this->stand = $neu;
            $this->dispatch('planung.lauf-geaendert');
        }
    }

    private function takten(): void
    {
        $this->aktuellerTakt = self::taktFuer($this->takt, $this->stillSekunden);
        if (! $this->neueBeobachten) {
            $this->haengend = [];

            return;
        }
        $lange = array_filter($this->stillJeLauf, fn ($s) => $s >= self::STILL_60S);
        if ($lange === []) {
            $this->haengend = [];

            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->haengend = [];

            return;
        }
        $runs = FoodAlchemistCascadeRun::visibleToTeam($team)->whereIn('id', array_keys($lange))->get(['id', 'planning_session_id']);
        $titel = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession::visibleToTeam($team)
            ->whereIn('id', $runs->pluck('planning_session_id')->filter()->all())->pluck('title', 'id');
        $this->haengend = $runs->map(fn ($r) => ['run_id' => (int) $r->id, 'session_id' => $r->planning_session_id !== null ? (int) $r->planning_session_id : null,
            'titel' => (string) ($titel[(int) $r->planning_session_id] ?? 'Lauf ' . $r->id), 'minuten' => intdiv($lange[(int) $r->id], 60)])->values()->all();
    }

    /**
     * Selbstheilung hängender Läufe: Reaper für jeden beobachteten Lauf, der seit {@see STILL_60S} keinen Fortschritt hat — höchstens
     * alle {@see HEILEN_SPERRE_SEK} je Lauf (Cache-Sperre), nur für das Besitzer-Team (der Reaper prüft es selbst noch einmal).
     * Fehler werden geloggt und nie an den Poll weitergereicht.
     *
     * @return int geheilte Schritte bzw. Läufe
     */
    private function heilen(): int
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return 0;
        }
        $n = 0;
        $selbst = [];
        foreach ($this->stillJeLauf as $runId => $sek) {
            if ($sek < self::STILL_60S || ! Cache::add('fa:kaskade-reaper:' . $runId, 1, self::HEILEN_SPERRE_SEK)) {
                continue;
            }
            $selbst[] = (int) $runId;
            try {
                $n += app(PlanningCascadeService::class)->reapeVerwaisteSteps($team, (int) $runId);
            } catch (\Throwable $e) {
                Log::warning("Kaskade-Reaper aus dem Poll fehlgeschlagen (Lauf {$runId}): {$e->getMessage()}");
            }
        }
        // io 26.11.: Das Board beobachtet nur den jüngsten Lauf je Session. Ältere Läufe, Läufe ohne Session und Läufe, die gar nicht
        // auf dem Board stehen, heilt der Team-Sweep — nur wenn der Poll selbst schon Stillstand sieht (60-s-Stufe, sonst bleibt der Poll
        // schlank), höchstens alle HEILEN_SPERRE_SEK je Team, nur eigene Läufe des Teams und ohne die eben schon geprüften.
        if (max($this->stillJeLauf ?: [0]) >= self::STILL_60S && Cache::add('fa:kaskade-reaper-team:' . $team->id, 1, self::HEILEN_SPERRE_SEK)) {
            try {
                $n += app(KaskadenReaperSweep::class)->heile($team, 'Board-Poll', $selbst)['geheilt'];
            } catch (\Throwable $e) {
                Log::warning("Kaskade-Reaper (Board-Poll, Team {$team->id}) fehlgeschlagen: {$e->getMessage()}");
            }
        }

        return $n;
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
        $this->stillJeLauf = [];
        $this->stillSekunden = null;
        if ($this->laufIds !== []) {
            $runs = FoodAlchemistCascadeRun::visibleToTeam($team)->whereIn('id', $this->laufIds)->orderBy('id')
                ->get(['id', 'team_id', 'status', 'updated_at', 'created_at']);
            $steps = FoodAlchemistCascadeRunStep::whereIn('cascade_run_id', $runs->pluck('id')->all())
                ->get(['id', 'team_id', 'cascade_run_id', 'status', 'phase', 'phase_at', 'deferred', 'updated_at', 'context_snapshot', 'generator_run_id', 'ref_type', 'ref_id']);
            $kiOffen = collect(app(PlanningCascadeService::class)->kiWartend($steps))->sum('anzahl');
            foreach ($runs as $r) {
                $eigene = $steps->where('cascade_run_id', $r->id);
                // Der Watchdog hängt an der Zeit, nicht an einer Änderung: das Überschreiten der Schwelle zählt als Änderung.
                $alt = $r->status === 'running' && $r->created_at !== null && $r->created_at->diffInSeconds(now()) > $this->watchdogSekunden;
                // updated_at hat Sekunden-Auflösung: Status, Phase und `deferred` je Schritt fangen zwei Änderungen in derselben Sekunde.
                $schritte = $eigene->sortBy('id')->map(fn ($st) => $st->id . ':' . $st->status . ':' . $st->phase . ':' . md5((string) json_encode($st->deferred)))->implode(',');
                $teile[] = implode('|', [$r->id, $r->status, (string) $r->updated_at, (string) $eigene->max('updated_at'), md5($schritte), $alt ? 'alt' : '']);
                // Stillstand: letzter Fortschritt an Lauf, Schritten oder Phasen. Nur für eigene, noch laufende Läufe (das Board beobachtet
                // auch Läufe mit liegengebliebener Phase). Aus den schon geladenen Zeilen — keine zusätzliche Abfrage.
                if ((int) $r->team_id === (int) $team->id && ($r->status === 'running' || $eigene->whereNotNull('phase')->isNotEmpty())) {
                    $zuletzt = collect([$r->updated_at, $eigene->max('updated_at'), $eigene->max('phase_at')])->filter()->map(fn ($t) => \Illuminate\Support\Carbon::make($t))->max();
                    $sek = $zuletzt !== null ? (int) $zuletzt->diffInSeconds(now()) : 0;
                    $this->stillJeLauf[(int) $r->id] = $sek;
                    $this->stillSekunden = $this->stillSekunden === null ? $sek : min($this->stillSekunden, $sek);
                }
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
