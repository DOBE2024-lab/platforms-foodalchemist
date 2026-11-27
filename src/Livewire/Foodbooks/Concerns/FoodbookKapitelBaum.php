<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;

/**
 * Foodbook-Editor · Kapitel-Baum ordnen: hoch/runter, ein-/ausrücken, Drag & Drop (98 · 3.3, aus Foodbooks\Index).
 * Braucht von der Komponente `$selectedId` und `team()`.
 */
trait FoodbookKapitelBaum
{
    public function kapitelHoch(int $id, FoodbookService $svc): void
    {
        $this->verschiebeKapitel($id, -1, $svc);
    }

    public function kapitelRunter(int $id, FoodbookService $svc): void
    {
        $this->verschiebeKapitel($id, 1, $svc);
    }

    private function verschiebeKapitel(int $id, int $richtung, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $k->parent_id)->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $geschwister, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($geschwister)) {
            return;
        }
        [$geschwister[$pos], $geschwister[$ziel]] = [$geschwister[$ziel], $geschwister[$pos]];
        $svc->reorderKapitel($this->team(), $this->selectedId, $k->parent_id, $geschwister);
    }

    /**
     * E2.1: Kapitel eine Ebene TIEFER — neuer Parent = unmittelbar vorheriges Geschwister
     * (Outline-Editor-Doktrin). Ohne vorheriges Geschwister nicht einrückbar.
     */
    public function kapitelEinruecken(int $id, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $k->parent_id)->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $geschwister, true);
        if ($pos === false || $pos === 0) {
            return; // erstes Geschwister hat keinen Vorgänger zum Einrücken
        }
        $this->kapitelUnter($id, (int) $geschwister[$pos - 1], $svc);
    }

    /**
     * E2.1: Kapitel eine Ebene HÖHER — neuer Parent = Großelternteil (oder Top-Ebene).
     * Top-Kapitel sind nicht weiter ausrückbar.
     */
    public function kapitelAusruecken(int $id, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $k->parent_id === null || $this->selectedId === null) {
            return;
        }
        $parent = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($k->parent_id);
        $grossParent = $parent?->parent_id !== null ? (int) $parent->parent_id : null;
        $this->kapitelUnter($id, $grossParent, $svc);
    }

    /**
     * Gemeinsamer Move: Parent wechseln (Service trägt den Zyklus-Schutz) und das Kapitel
     * ans ENDE der neuen Geschwister-Ordnung setzen, damit `position` konsistent bleibt
     * (moveKapitel selbst rührt die Position nicht an).
     */
    private function kapitelUnter(int $id, ?int $neuerParent, FoodbookService $svc): void
    {
        try {
            $svc->moveKapitel($this->team(), $id, $neuerParent);
        } catch (\RuntimeException) {
            return; // Zyklus o. Ä. — UI bietet solche Moves ohnehin nicht an
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $neuerParent)->where('id', '!=', $id)->orderBy('position')->pluck('id')->all();
        $geschwister[] = $id;
        $svc->reorderKapitel($this->team(), $this->selectedId, $neuerParent, $geschwister);
    }

    /**
     * #4: Kapitel per Drag&Drop umsortieren — das gezogene Kapitel landet unmittelbar VOR dem
     * Ziel-Kapitel und übernimmt dessen Parent-Ebene (moveKapitel trägt den Zyklus-Schutz:
     * ein Kapitel auf einen eigenen Nachfahren gezogen wird abgewiesen). Spiegelt die
     * Block-Drop-Semantik (blockVerschiebenAuf).
     */
    public function kapitelVerschiebenAuf(int $dragId, int $zielId, FoodbookService $svc): void
    {
        if ($dragId === $zielId || $this->selectedId === null) {
            return;
        }
        $ziel = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)->find($zielId);
        if ($ziel === null) {
            return;
        }
        try {
            $svc->moveKapitel($this->team(), $dragId, $ziel->parent_id);   // auf die Ziel-Ebene (Zyklus-Schutz im Service)
        } catch (\RuntimeException) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $ziel->parent_id)->where('id', '!=', $dragId)->orderBy('position')->pluck('id')->all();
        $zielPos = array_search($zielId, $geschwister, true);
        if ($zielPos === false) {
            $geschwister[] = $dragId;
        } else {
            array_splice($geschwister, $zielPos, 0, [$dragId]);   // direkt VOR das Ziel
        }
        $svc->reorderKapitel($this->team(), $this->selectedId, $ziel->parent_id, $geschwister);
    }
}
