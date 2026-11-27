<?php

namespace Platform\FoodAlchemist\Livewire\Angebote\Concerns;

use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;

/**
 * Angebots-Editor · KI-Kundentext (Einleitung + Kapitel-Hinführung) mit Vorschau-Zustand (98 · 3.3, aus Angebote\Editor).
 * Braucht von der Komponente `$selectedId`, `$selectedKapitelId`, `$form`, `$kapitelForm` und `team()`.
 */
trait AngebotKiText
{
    // ── KI-Kundentext (Foodbook §L2): Vorschau-Zustand (Buch- + Kapitel-Ebene) ──
    public ?string $kiTextVorschau = null;

    public ?float $kiTextConfidence = null;

    public ?string $kiTextHinweis = null;

    /** Welches Feld der Vorschlag füllt: `angebot` (Einleitung) oder `kapitel` (Hinführung). */
    public string $kiTextZiel = 'angebot';

    /** Kundentext-Vorschlag für die Angebots-Einleitung holen (schreibt NUR in die Vorschau). */
    public function kiEinleitung(AngebotService $svc): void
    {
        $this->kiTextZuruecksetzen('angebot');
        if ($this->selectedId === null) {
            return;
        }
        $this->kiTextHolen(fn () => $svc->kiKundentextVorschlag($this->team(), $this->selectedId));
    }

    /** Hinführung fürs gewählte Kapitel holen. Gleiche zwei Stufen wie auf der Buch-Ebene. */
    public function kiKapitelText(AngebotService $svc): void
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
     * Gemeinsamer Call-Rahmen beider Ebenen: die typisierten KI-Ausfälle werden zu genau
     * einer Hinweis-Zeile.
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

    /** Vorschlag ins Formular übernehmen — bewusst OHNE zu speichern. Ziel entscheidet kiTextZiel. */
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
            $this->form['brief'] = $this->kiTextVorschau;
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
