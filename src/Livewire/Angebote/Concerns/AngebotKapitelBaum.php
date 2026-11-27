<?php

namespace Platform\FoodAlchemist\Livewire\Angebote\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;

/**
 * Angebots-Editor · Kapitel-Baum ordnen: hoch/runter, ein-/ausrücken, Drag & Drop (98 · 3.3, aus Angebote\Editor).
 * Braucht von der Komponente `$selectedId`, `team()` und `nachKomposition()`.
 */
trait AngebotKapitelBaum
{
    public function kapitelHoch(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeKapitel($id, -1, $comp);
    }

    public function kapitelRunter(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeKapitel($id, 1, $comp);
    }

    private function verschiebeKapitel(int $id, int $richtung, OfferCompositionService $comp): void
    {
        $k = FoodAlchemistOfferChapter::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = $this->geschwisterIds($k->parent_id !== null ? (int) $k->parent_id : null);
        $pos = array_search($id, $geschwister, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($geschwister)) {
            return;
        }
        [$geschwister[$pos], $geschwister[$ziel]] = [$geschwister[$ziel], $geschwister[$pos]];
        $comp->reorderKapitel($this->team(), $this->selectedId, $geschwister);
        $this->nachKomposition();
    }

    /**
     * Kapitel eine Ebene TIEFER — neuer Parent = unmittelbar vorheriges Geschwister
     * (Outline-Editor-Doktrin). Ohne vorheriges Geschwister nicht einrückbar.
     */
    public function kapitelEinruecken(int $id, OfferCompositionService $comp): void
    {
        $k = FoodAlchemistOfferChapter::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = $this->geschwisterIds($k->parent_id !== null ? (int) $k->parent_id : null);
        $pos = array_search($id, $geschwister, true);
        if ($pos === false || $pos === 0) {
            return; // erstes Geschwister hat keinen Vorgänger zum Einrücken
        }
        $this->kapitelUnter($id, (int) $geschwister[$pos - 1], $comp);
    }

    /**
     * Kapitel eine Ebene HÖHER — neuer Parent = Großelternteil (oder Top-Ebene).
     * Top-Kapitel sind nicht weiter ausrückbar.
     */
    public function kapitelAusruecken(int $id, OfferCompositionService $comp): void
    {
        $k = FoodAlchemistOfferChapter::find($id);
        if ($k === null || $k->parent_id === null || $this->selectedId === null) {
            return;
        }
        $parent = FoodAlchemistOfferChapter::find($k->parent_id);
        $grossParent = $parent?->parent_id !== null ? (int) $parent->parent_id : null;
        $this->kapitelUnter($id, $grossParent, $comp);
    }

    /**
     * Gemeinsamer Move: Parent wechseln (Service trägt den Zyklus-Schutz) und das Kapitel
     * ans ENDE der neuen Geschwister-Ordnung setzen, damit die Positionen konsistent bleiben.
     */
    private function kapitelUnter(int $id, ?int $neuerParent, OfferCompositionService $comp): void
    {
        try {
            $comp->moveKapitel($this->team(), $id, $neuerParent);
        } catch (\RuntimeException) {
            return; // Zyklus o. Ä. — UI bietet solche Moves ohnehin nicht an
        }
        $geschwister = $this->geschwisterIds($neuerParent, $id);
        $geschwister[] = $id;
        $comp->reorderKapitel($this->team(), $this->selectedId, $geschwister);
        $this->nachKomposition();
    }

    /**
     * Drag & Drop: das gezogene Kapitel landet unmittelbar VOR dem Ziel-Kapitel und
     * übernimmt dessen Parent-Ebene (moveKapitel trägt den Zyklus-Schutz). Spiegelt die
     * Block-Drop-Semantik.
     */
    public function kapitelVerschiebenAuf(int $dragId, int $zielId, OfferCompositionService $comp): void
    {
        if ($dragId === $zielId || $this->selectedId === null) {
            return;
        }
        $ziel = FoodAlchemistOfferChapter::where('offer_id', $this->selectedId)->find($zielId);
        if ($ziel === null) {
            return;
        }
        $neuerParent = $ziel->parent_id !== null ? (int) $ziel->parent_id : null;
        try {
            $comp->moveKapitel($this->team(), $dragId, $neuerParent);   // auf die Ziel-Ebene (Zyklus-Schutz im Service)
        } catch (\RuntimeException) {
            return;
        }
        $geschwister = $this->geschwisterIds($neuerParent, $dragId);
        $zielPos = array_search($zielId, $geschwister, true);
        if ($zielPos === false) {
            $geschwister[] = $dragId;
        } else {
            array_splice($geschwister, $zielPos, 0, [$dragId]);   // direkt VOR das Ziel
        }
        $comp->reorderKapitel($this->team(), $this->selectedId, $geschwister);
        $this->nachKomposition();
    }

    /**
     * Geschwister-IDs (gleiche Parent-Ebene) des aktuellen Angebots, nach Position geordnet.
     * `$ausser` schließt eine ID aus (für Move-Neuordnung).
     *
     * @return list<int>
     */
    private function geschwisterIds(?int $parentId, ?int $ausser = null): array
    {
        return FoodAlchemistOfferChapter::where('offer_id', $this->selectedId)
            ->where('parent_id', $parentId)
            ->when($ausser !== null, fn ($q) => $q->where('id', '!=', $ausser))
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
    }
}
