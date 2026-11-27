<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks\Concerns;

use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;

/**
 * Foodbook-Editor · KI-Kundentext (Einleitung + Kapitel-Hinführung) mit Vorschau-Zustand (98 · 3.3, aus Foodbooks\Index).
 * Braucht von der Komponente `$selectedId`, `$selectedKapitelId`, `$form`, `$kapitelForm` und `team()`.
 */
trait FoodbookKiText
{
    /**
     * Spec 03 · L2: KI-Kundentext — VORSCHAU-Zustand. Der Vorschlag landet hier und
     * nirgends sonst; erst `kiTextUebernehmen()` schreibt ihn ins Formular, erst
     * „Speichern" in die DB. Zwei Stufen mit Absicht: das Briefing-Feld ist oft
     * handgeschrieben, und ein still ersetzter Kundentext wäre unwiederbringlich.
     */
    public ?string $kiTextVorschau = null;

    public ?float $kiTextConfidence = null;

    /** Fehler ODER Erfolgs-Hinweis der KI-Text-Fläche (eine Zeile, ein Zustand). */
    public ?string $kiTextHinweis = null;

    /**
     * Spec 03 · L2b: WELCHES Feld der Vorschlag füllen soll — `foodbook` (Einleitung) oder
     * `kapitel` (Hinführung). EIN Vorschau-Zustand für beide Ebenen, weil beide Flächen
     * nie gleichzeitig sichtbar sind (Master-Detail: Buch-Kopf ODER Kapitel). Zwei
     * Zustands-Sätze wären zwei Wahrheiten über denselben Ablauf.
     */
    public string $kiTextZiel = 'foodbook';

    /**
     * Spec 03 · L2: Kundentext-Vorschlag für die Foodbook-Einleitung holen.
     * Schreibt NICHT ins Formular — nur in die Vorschau (`kiTextVorschau`).
     */
    public function kiEinleitung(FoodbookService $svc): void
    {
        $this->kiTextZuruecksetzen('foodbook');
        if ($this->selectedId === null) {
            return;
        }
        $this->kiTextHolen(fn () => $svc->kiKundentextVorschlag($this->team(), $this->selectedId));
    }

    /**
     * Spec 03 · L2b: Hinführung fürs gewählte Kapitel holen. Gleiche zwei Stufen wie auf
     * der Buch-Ebene — der Vorschlag berührt `kapitelForm.description` nie.
     */
    public function kiKapitelText(FoodbookService $svc): void
    {
        $this->kiTextZuruecksetzen('kapitel');
        if ($this->selectedKapitelId === null) {
            return;
        }
        $this->kiTextHolen(fn () => $svc->kiKapitelKundentextVorschlag($this->team(), $this->selectedKapitelId));
    }

    private function kiTextZuruecksetzen(string $ziel): void
    {
        $this->kiTextZiel = $ziel;
        $this->kiTextVorschau = null;
        $this->kiTextConfidence = null;
        $this->kiTextHinweis = null;
    }

    /**
     * Der gemeinsame Call-Rahmen beider Ebenen: die typisierten KI-Ausfälle werden zu
     * genau einer Hinweis-Zeile. Bewusst EINE Stelle — sonst driften die Meldungen
     * zwischen Buch- und Kapitel-Knopf auseinander.
     *
     * @param  \Closure():array{text:string,confidence:?float,call_log_id:?int}  $call
     */
    private function kiTextHolen(\Closure $call): void
    {
        try {
            $r = $call();
            $this->kiTextVorschau = $r['text'];
            $this->kiTextConfidence = $r['confidence'];
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            $this->kiTextHinweis = 'KI ist für dieses Team deaktiviert (Einstellungen → Food DNA / KI).';
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException $e) {
            $this->kiTextHinweis = 'Kein KI-Provider gebunden — der Kundentext braucht ein aktives Modell (demo).';
        } catch (\RuntimeException $e) {
            $this->kiTextHinweis = $e->getMessage();
        }
    }

    /**
     * Vorschlag ins Formular übernehmen — bewusst OHNE zu speichern: der Text steht
     * danach sichtbar im Feld und geht denselben Weg wie jede Handeingabe („Speichern").
     * Das Ziel entscheidet `kiTextZiel`, nicht der Aufrufer: der Knopf sitzt in beiden
     * Ebenen an derselben Vorschau-Fläche.
     */
    public function kiTextUebernehmen(): void
    {
        if ($this->kiTextVorschau === null) {
            return;
        }
        if ($this->kiTextZiel === 'kapitel') {
            if ($this->selectedKapitelId === null) {
                return;                                              // Kapitel gewechselt, während die KI lief
            }
            $this->kapitelForm['description'] = $this->kiTextVorschau;
            $hinweis = 'Text steht im Kapitel-Feld — noch nicht gespeichert (Feld verlassen speichert).';
        } else {
            $this->form['description'] = $this->kiTextVorschau;
            $hinweis = 'Text steht im Feld — noch nicht gespeichert („Speichern" oben).';
        }
        $this->kiTextVorschau = null;
        $this->kiTextConfidence = null;
        $this->kiTextHinweis = $hinweis;
    }

    public function kiTextVerwerfen(): void
    {
        $this->kiTextVorschau = null;
        $this->kiTextConfidence = null;
        $this->kiTextHinweis = null;
    }
}
