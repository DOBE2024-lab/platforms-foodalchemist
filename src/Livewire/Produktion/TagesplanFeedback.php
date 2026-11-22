<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Enums\FeedbackQuelle;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Services\FeedbackService;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;

/**
 * Wandmonitor: Mini-Feedbackbogen zum Rezept der offenen Anleitung (98 · 3.3 S6, aus Produktion\Tagesplan
 * herausgelöst). Landet über `FeedbackService` in DERSELBEN Ablage wie der Feedback-Tab des Rezepts (R2.6) —
 * kein zweiter Speicherort, ein Ø-Score.
 *
 * Bewusst kleiner als das Formular im Editor: „Gäste-Reaktion" fehlt, weil die Person am
 * Posten die Gäste nicht sieht. Ein Feld, das nur geraten beantwortet werden kann,
 * verdirbt das Aggregat, für das die Küche die ehrlichste Quelle ist.
 *
 * Die Zeile kommt reaktiv von der Anleitung; der Schlüssel bleibt gleich, damit ein halb ausgefüllter Bogen
 * beim Wechsel zu einem Unterrezept stehen bleibt (wie vorher im Tagesplan).
 */
class TagesplanFeedback extends Component
{
    use WithFileUploads;

    #[Reactive]
    public ?int $lineId = null;

    public array $feedbackForm = ['score' => null, 'machbarkeit' => null, 'aufwand' => null,
        'geschmack' => null, 'comment' => '', 'gruende' => []];

    public bool $feedbackGespeichert = false;

    /** Diktat fuer den Feedback-Kommentar (WithFileUploads) — Muster wie Planungsstelle/StepEditor. */
    public $feedbackAudio = null;

    public ?string $fehler = null;

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
            if ($this->lineId === null) {
                return;
            }
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $line = FoodAlchemistProductionOrderLine::findOrFail($this->lineId);
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

    public function render()
    {
        return view('foodalchemist::livewire.produktion.tagesplan-feedback');
    }
}
