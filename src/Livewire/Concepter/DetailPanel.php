<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\Concepter\ConcepterBewertungService;

/**
 * M10R-2/3 / Doc 15 §10.4: kontext-adaptives Detail-Panel im VK-Stil. Zeigt für
 * Concept ODER Paket Stammdaten · Live-Preis/Kosten-Cockpit · Voll-Aggregat
 * (Nährwerte/Allergene/Diät/Arbeitszeit, ConcepterAggregateService) · Aktionen.
 * In M10R-3 kommen das Voll-Editor-Modal + die deterministische Bewertung dazu.
 */
class DetailPanel extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;        // #1003: am Handy nur lesen

    /** #1003: am Handy laufen Auswahl und Aktualisieren; Vorlage, Duplizieren und Löschen bleiben am Computer. */
    public function handyLeseMethoden(): array
    {
        return ['zeige', 'aktualisiere'];
    }

    /** Spec 65: gleiche Sperre wie der Concepter-Editor — beide Reiter sind Concepts (Kaskade) → Typ concept. */
    protected function sperrZiel(): ?array
    {
        return $this->selectedId !== null ? ['concept', $this->selectedId] : null;
    }

    /**
     * Ohne Sperre: Anzeige/Auswahl, Neu-Rendern; Kopien (Als Vorlage speichern, Aus Vorlage anlegen, Duplizieren)
     * ändern das gewählte Concept nicht. Löschen holt sich die Sperre selbst (kurzsperrMethoden).
     */
    protected function sperrFreiExtra(): array
    {
        return ['zeige', 'aktualisiere', 'alsVorlage', 'ausVorlage', 'dupliziere'];
    }

    public ?int $selectedId = null;

    public string $type = 'concepts';   // concepts | pakete

    #[On('concepter-selected')]
    public function zeige(string $type, ?int $id): void
    {
        // Spec 65: anderes Concept gewählt → eigene Sperre am bisherigen freigeben.
        if ($this->selectedId !== null && $this->selectedId !== $id) {
            $this->sperreBeiWechselFreigeben();
        }
        $this->type = in_array($type, ['concepts', 'pakete'], true) ? $type : 'concepts';
        $this->selectedId = $id;
    }

    #[On('concepter-gespeichert')]
    public function aktualisiere(): void
    {
        // Re-render mit frischen Daten (Kontext bleibt).
    }

    public function alsVorlage(ConceptService $concepts): void
    {
        if ($this->type === 'concepts' && $this->selectedId !== null) {
            $concepts->alsVorlageSpeichern($this->team(), $this->selectedId);
            $this->dispatch('concepter-gespeichert');
        }
    }

    /** M10R-4 (D-CON-7): „Aus Vorlage starten" — forkt das Slot-Gerüst und öffnet den Fork. */
    public function ausVorlage(ConceptService $concepts): void
    {
        if ($this->type !== 'concepts' || $this->selectedId === null) {
            return;
        }
        $vorlage = $concepts->detail($this->team(), $this->selectedId);
        if ($vorlage === null || ! $vorlage->is_template) {
            return;
        }
        $fork = $concepts->forkVonVorlage($this->team(), $this->selectedId, $vorlage->name . ' – Kopie');
        $this->dispatch('concepter-gespeichert');
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $fork->id);
    }

    /** C-13/B-10: Duplizieren + Kopie auswählen. Kaskade: Paket = kind=paket-Concept → ConceptService::duplicate erhält kind. */
    public function dupliziere(ConceptService $concepts): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $neu = $concepts->duplicate($this->team(), $this->selectedId);
        $this->bearbeitenBeenden();   // Spec 65: Auswahl springt auf die Kopie → eigene Sperre am Original frei
        $this->selectedId = $neu->id;
        $this->dispatch('concepter-gespeichert');
        $this->dispatch('concepter-selected', type: $this->type, id: $neu->id);
    }

    /** Löschen — Kaskade: beide Reiter sind Concepts (kind concept|paket), also immer ConceptService. */
    /** 98 · 2.4d: auch im Ansichtsmodus — holt die Sperre für die Dauer der Aktion (mitKurzsperre). */
    public function loeschen(ConceptService $concepts): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->mitKurzsperre(function () use ($concepts) {
            $id = $this->selectedId;
            try {
                $concepts->delete($this->team(), $id);
            } catch (\RuntimeException $e) {   // z. B. noch in Foodbooks verwendet: Meldung statt Fehlerseite
                $this->dispatch('fa-saved', message: $e->getMessage(), type: 'error');

                return;
            }
            $this->bearbeitenBeenden();   // Spec 65: gelöscht → Sperre frei
            $this->selectedId = null;
            $this->dispatch('concepter-geloescht', id: $id);
        });
    }

    protected function kurzsperrMethoden(): array
    {
        return ['loeschen'];
    }

    public function render(ConceptService $concepts, ConcepterAggregateService $agg, ConcepterBewertungService $bewertung)
    {
        $team = $this->team();
        $concept = null;
        $cockpit = null;
        $aggregat = null;
        $bewertet = null;
        $istPaket = false;

        $verwendung = collect();

        // Kaskade (2026-08-24): beide Reiter sind Concepts (kind concept|paket) → ein Ladepfad.
        // Das Paket ist ein kind=paket-Concept mit eigenem Preis; nur die Anzeige (VK/Bewertung)
        // unterscheidet sich (→ $istPaket).
        if ($this->selectedId !== null) {
            $concept = $concepts->detail($team, $this->selectedId);
            if ($concept !== null) {
                $istPaket = $concept->kind === 'paket';
                $cockpit = $concepts->preisCockpit($concept, $team ? app(\Platform\FoodAlchemist\Services\Plattform\ActiveOutletContext::class)->current($team) : null, $team);
                $aggregat = $agg->conceptAggregat($concept);
                // Menü-Bewertung (Gang-Dramaturgie etc.) nur für echte Concepts, nicht fürs Bündel.
                $bewertet = $istPaket ? null : $bewertung->bewerten($concept, $cockpit, $aggregat);
                $verwendung = $istPaket
                    ? $concepts->eingebettetInConcepts($team, $concept->id)
                    : $concepts->verwendetInFoodbooks($team, $concept->id);
            } else {
                $this->selectedId = null;
            }
        }

        return view('foodalchemist::livewire.concepter.detail-panel', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'concept' => $concept,
            'istPaket' => $istPaket,
            'cockpit' => $cockpit,
            'aggregat' => $aggregat,
            'bewertung' => $bewertet,
            'verwendung' => $verwendung,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
