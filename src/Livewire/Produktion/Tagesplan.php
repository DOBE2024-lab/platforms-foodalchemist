<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Enums\FeedbackQuelle;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\FeedbackService;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProductionPlanService;
use Platform\FoodAlchemist\Services\ProductionReadinessService;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
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
    use WithFileUploads;

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

    /** Wandmonitor: Zeile, deren Anleitung gerade im Overlay offen ist (null = zu). */
    public ?int $anleitungLineId = null;

    /** Wandmonitor: Gericht-/Arbeitsblock, dessen enthaltene Rezepte gerade offen sind. */
    public ?string $wallGerichtKey = null;

    /** Wandmonitor: flüchtige Step-Checks je geöffneter Anleitungszeile. Dauerhaft ist die Zeile selbst. */
    public array $anleitungStepStatus = [];

    /**
     * Wandmonitor: Mini-Feedbackbogen zum Rezept. Landet über `FeedbackService` in DERSELBEN
     * Ablage wie der Feedback-Tab des Rezepts (R2.6) — kein zweiter Speicherort, ein Ø-Score.
     *
     * Bewusst kleiner als das Formular im Editor: „Gäste-Reaktion" fehlt, weil die Person am
     * Posten die Gäste nicht sieht. Ein Feld, das nur geraten beantwortet werden kann,
     * verdirbt das Aggregat, für das die Küche die ehrlichste Quelle ist.
     */
    public array $feedbackForm = ['score' => null, 'machbarkeit' => null, 'aufwand' => null,
        'geschmack' => null, 'comment' => '', 'gruende' => []];

    public bool $feedbackGespeichert = false;

    /** Diktat fuer den Feedback-Kommentar (WithFileUploads) — Muster wie Planungsstelle/StepEditor. */
    public $feedbackAudio = null;

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

            $this->zeileSetzenMitWallStart($team, $zeile, $ziel, $svc);
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
                $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($z->id), $ziel, $svc);
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine $zeile, ProductionLineStatus $ziel, ProductionOrderService $svc): int
    {
        $lineId = (int) $zeile->id;
        if ($this->display === 'wall' && in_array($ziel, [ProductionLineStatus::InProgress, ProductionLineStatus::Done], true)) {
            $order = $zeile->productionOrder;
            $status = $order?->status instanceof ProductionOrderStatus
                ? $order->status
                : ($order?->status !== null ? ProductionOrderStatus::from((string) $order->status) : null);

            if ($order !== null && $status === ProductionOrderStatus::Planned) {
                $svc->setStatus($team, (int) $order->id, ProductionOrderStatus::InProgress);

                $neu = FoodAlchemistProductionOrderLine::query()
                    ->where('production_order_id', $order->id)
                    ->when($zeile->recipe_id !== null, fn ($q) => $q->where('recipe_id', $zeile->recipe_id))
                    ->when($zeile->recipe_id === null, fn ($q) => $q->whereKey($lineId))
                    ->orderBy('position')
                    ->first();

                $lineId = (int) ($neu?->id ?? $lineId);
            }
        }

        $svc->setLineStatus($team, $lineId, $ziel);

        return $lineId;
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

    /** Wandmonitor: Anleitung (Schritte + Zutaten) einer Zeile im Overlay öffnen. */
    public function oeffneAnleitung(int $lineId): void
    {
        $this->anleitungLineId = $lineId;
        $this->dispatch('modal.open', name: 'wall-anleitung');
    }

    public function anleitungSchliessen(): void
    {
        $this->anleitungLineId = null;
    }

    public function oeffneGericht(string $key): void
    {
        $this->wallGerichtKey = $key;
        $this->dispatch('modal.open', name: 'wall-gericht');
    }

    public function gerichtSchliessen(): void
    {
        $this->wallGerichtKey = null;
    }

    /**
     * Diktat statt Tastatur — am Wandmonitor der eigentliche Gewinn: nasse oder behandschuhte
     * Haende bedienen keine Bildschirmtastatur, und die verdeckt am Pass den halben Schirm.
     *
     * Gesprochenes wird ANGEHAENGT, nie ersetzt (Muster StepEditor/Planungsstelle): ein Diktat
     * ist ein Nachtrag zu dem, was schon dasteht. Reines STT ueber `SttServiceContract`, kein
     * Tool-Loop — im Feld soll stehen, was gesagt wurde, nicht was eine KI daraus macht.
     *
     * Die zaehlbare Haelfte bleiben die Tipp-Gruende; das Diktat traegt den Einzelfall nach.
     */
    public function updatedFeedbackAudio(): void
    {
        if ($this->feedbackAudio === null) {
            return;
        }
        try {
            $text = trim(app(SttServiceContract::class)->transcribe(
                (string) file_get_contents($this->feedbackAudio->getRealPath()),
                $this->feedbackAudio->getMimeType() ?: 'audio/webm',
            ));
        } catch (\Throwable $e) {
            $this->fehler = 'Diktat fehlgeschlagen: '.$e->getMessage();
            $this->feedbackAudio = null;

            return;
        }

        $this->feedbackAudio = null;
        if ($text === '') {
            // Kann auch der Prompt-Echo-Riegel im STT sein (Aufnahme ohne Sprache).
            $this->fehler = 'Diktat war leer — nichts übernommen.';

            return;
        }

        $bisher = trim((string) ($this->feedbackForm['comment'] ?? ''));
        $this->feedbackForm['comment'] = $bisher === '' ? $text : $bisher.' '.$text;
        $this->feedbackGespeichert = false;
        $this->fehler = null;
    }

    /**
     * Antippbare Gruende statt Tippen.
     *
     * Am Wandmonitor ist die Tastatur nicht das Problem — freier Text ist es: zwoelf Leute
     * formulieren „Menge stimmt nicht" auf zwoelf Arten, und keine Auswertung sieht das. Die
     * Kacheln machen aus dem haeufigen Fall eine Haeufigkeit; der Kommentar bleibt fuer den Rest.
     */
    public function feedbackGrundUmschalten(string $slug): void
    {
        if (! array_key_exists($slug, (array) config('foodalchemist.feedback_gruende', []))) {
            return;
        }
        $gruende = collect($this->feedbackForm['gruende'] ?? [])->map(fn ($g) => (string) $g);
        $this->feedbackForm['gruende'] = $gruende->contains($slug)
            ? $gruende->reject(fn ($g) => $g === $slug)->values()->all()
            : $gruende->push($slug)->unique()->values()->all();
        $this->feedbackGespeichert = false;
    }

    /** Touch statt Tastatur: ein Tipp setzt (oder loescht) den Wert. */
    public function feedbackSetzen(string $feld, int $wert): void
    {
        if (! array_key_exists($feld, $this->feedbackForm) || in_array($feld, ['comment', 'gruende'], true)) {
            return;
        }
        $this->feedbackForm[$feld] = ((int) ($this->feedbackForm[$feld] ?? 0)) === $wert ? null : $wert;
        $this->feedbackGespeichert = false;
    }

    /**
     * Feedback der Kueche zum Rezept DIESER Zeile.
     *
     * Der Kontext kommt aus dem Auftrag und wird nicht getippt: Name und Produktionstag stehen
     * hier ohnehin fest — im Editor muesste sie jemand aus dem Gedaechtnis nachtragen.
     */
    public function feedbackSpeichern(FeedbackService $svc): void
    {
        $this->fehler = null;
        $this->feedbackGespeichert = false;
        try {
            if ($this->anleitungLineId === null) {
                return;
            }
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $line = FoodAlchemistProductionOrderLine::findOrFail($this->anleitungLineId);
            if ($line->recipe_id === null) {
                throw new \RuntimeException('Diese Zeile hängt an keinem Rezept — Feedback braucht ein Rezept.');
            }

            $svc->erstelle($team, (int) $line->recipe_id, [
                'quelle' => FeedbackQuelle::Kueche->value,
                'score' => $this->feedbackForm['score'] ?? null,
                'machbarkeit' => $this->feedbackForm['machbarkeit'] ?? null,
                'aufwand' => $this->feedbackForm['aufwand'] ?? null,
                'geschmack' => $this->feedbackForm['geschmack'] ?? null,
                'comment' => $this->feedbackForm['comment'] ?? null,
                'gruende' => $this->feedbackForm['gruende'] ?? [],
                'kontext_label' => $line->productionOrder?->name,
                'kontext_datum' => $line->productionOrder?->production_date?->toDateString(),
                'author_user_id' => Auth::id(),
                'created_via' => 'fa_wall',
            ]);

            $this->feedbackForm = ['score' => null, 'machbarkeit' => null, 'aufwand' => null,
                'geschmack' => null, 'comment' => '', 'gruende' => []];
            $this->feedbackGespeichert = true;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anleitungStarten(ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            if ($this->anleitungLineId === null) {
                return;
            }

            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->anleitungLineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($this->anleitungLineId), ProductionLineStatus::InProgress, $svc);
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
                    $this->anleitungLineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($lineId), ProductionLineStatus::Open, $svc);
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
        $this->anleitungLineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($lineId), ProductionLineStatus::Done, $svc);
    }

    private function aktuelleAnleitung(ProductionCapacityService $kap): ?array
    {
        if ($this->anleitungLineId === null) {
            return null;
        }

        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        [$von, $bis] = $this->zeitraum();
        $zeilen = $kap->tagesplanZeilen($team, $von, $bis, true);

        return (new TagesplanAbfrage)->anleitungAufloesen($zeilen, $this->anleitungLineId, $this->anleitungStepStatus);
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
        $anleitung = $istWall ? $abfrage->anleitungAufloesen($zeilen, $this->anleitungLineId, $this->anleitungStepStatus) : null;
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
            'anleitung' => $anleitung,
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
