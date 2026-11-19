<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Code-Qualität 1.1c (K3): Ziele eines Concept-Slots — eingebettetes Paket (kind=paket-Concept),
 * Alt-Paket oder Rezept — gegen die Teamsicht prüfen.
 *
 * Schreiben: {@see ConceptService::fillSlot} nimmt nur sichtbare Ziele an, ein Paket nicht in
 * sich selbst (auch nicht über Umwege) und einen zum Rezept passenden Typ.
 *
 * Lesen: Die Slot-Beziehungen lösen bewusst ohne Teamsicht auf (Spec 82 „verweis“). Damit ein
 * fremdes Ziel, das an der Prüfung vorbei in der DB steht, nicht in Detail, Kalkulation oder Export
 * erscheint, blendet {@see fremdeAusblenden} es an den Lese-Einstiegen aus. Maßstab ist das
 * Besitzer-Team des Concepts (eigene Kette oder sichtbar), nicht der Betrachter.
 */
class ConceptSlotZielService
{
    /** @var array<string, bool> */
    private array $sichtCache = [];

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Prüft das Ziel, das fillSlot setzen will (genau eines: embedded_concept_id, package_id,
     * sales_recipe_id). Liefert für ein Rezept den Slot-Typ, sonst null. Wirft bei fremdem Ziel,
     * Kreis oder widersprechendem Typ.
     */
    public function pruefe(Team $team, FoodAlchemistConceptSlot $slot, array $in): ?string
    {
        if (! empty($in['embedded_concept_id'])) {
            $this->pruefeEinbettung($team, $slot, (int) $in['embedded_concept_id']);
        } elseif (! empty($in['package_id'])) {
            $this->pruefeAltPaket($team, (int) $in['package_id']);
        } elseif (! empty($in['sales_recipe_id'])) {
            return $this->rezeptTyp($team, (int) $in['sales_recipe_id'], $in['type'] ?? null);
        }

        return null;
    }

    public function pruefeEinbettung(Team $team, FoodAlchemistConceptSlot $slot, int $paketId): void
    {
        if ($paketId === (int) $slot->concept_id) {
            throw new \RuntimeException('Ein Paket kann sich nicht selbst einbetten.');
        }
        if (! FoodAlchemistConcept::visibleToTeam($team)->pakete()->whereKey($paketId)->exists()) {
            throw new ModelNotFoundException("Paket #{$paketId} nicht sichtbar oder kein Paket.");
        }
        if ($this->enthaelt($paketId, (int) $slot->concept_id)) {
            throw new \RuntimeException("Paket #{$paketId} enthält dieses Concept bereits — das wäre ein Kreis.");
        }
    }

    public function pruefeAltPaket(Team $team, int $paketId): void
    {
        if (! FoodAlchemistPaket::visibleToTeam($team)->whereKey($paketId)->exists()) {
            throw new ModelNotFoundException("Paket #{$paketId} nicht sichtbar/vorhanden.");
        }
    }

    /**
     * Prüft das Rezept und liefert den Slot-Typ. Ohne Typ: aus dem Rezept abgeleitet
     * (VK-Gericht → gericht, sonst basisrezept). Ein widersprechender Typ wird abgelehnt.
     */
    public function rezeptTyp(Team $team, int $recipeId, ?string $typ): string
    {
        $rezept = FoodAlchemistRecipe::visibleToTeam($team)->whereKey($recipeId)->first(['id', 'is_sales_recipe'])
            ?? throw new ModelNotFoundException("Rezept #{$recipeId} nicht sichtbar/vorhanden.");
        $soll = (bool) $rezept->is_sales_recipe ? 'gericht' : 'basisrezept';
        if ($typ !== null && $typ !== '' && $typ !== $soll) {
            throw new \RuntimeException($soll === 'basisrezept'
                ? "Rezept #{$recipeId} ist ein Basisrezept — Typ 'basisrezept' verwenden."
                : "Rezept #{$recipeId} ist ein Gericht (VK) — Typ 'gericht' verwenden.");
        }

        return $soll;
    }

    /** Bettet $vonId (direkt oder tiefer) $zielId ein? Breitensuche über embedded_concept_id. */
    private function enthaelt(int $vonId, int $zielId): bool
    {
        $offen = [$vonId];
        $gesehen = [];
        while ($offen !== []) {
            $ids = array_values(array_diff($offen, array_keys($gesehen)));
            if ($ids === []) {
                return false;
            }
            foreach ($ids as $id) {
                $gesehen[$id] = true;
            }
            $offen = DB::table('foodalchemist_concept_slots')->whereIn('concept_id', $ids)->whereNull('deleted_at')
                ->whereNotNull('embedded_concept_id')->pluck('embedded_concept_id')->map(fn ($v) => (int) $v)->all();
            if (in_array($zielId, $offen, true)) {
                return true;
            }
        }

        return false;
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /**
     * Setzt geladene Slot-Ziele, die das Besitzer-Team des Concepts nicht sehen darf, auf null —
     * rekursiv für geladene eingebettete Pakete. Fassungen (Spec 82) sind Systemkopien und bleiben.
     *
     * @param  array<int, true>  $pfad  intern: Zyklus-Sperre
     */
    public function fremdeAusblenden(FoodAlchemistConcept $concept, array $pfad = []): FoodAlchemistConcept
    {
        if ((bool) ($concept->ist_fassung ?? false) || ! $concept->relationLoaded('slots') || isset($pfad[(int) $concept->id])) {
            return $concept;
        }
        $pfad[(int) $concept->id] = true;
        $team = $concept->team_id !== null ? Team::find($concept->team_id) : null;
        if ($team === null) {
            return $concept;
        }
        $kette = FoodAlchemistConcept::teamAncestryIds($team);
        foreach ($concept->slots as $slot) {
            foreach (['package' => FoodAlchemistPaket::class, 'embeddedConcept' => FoodAlchemistConcept::class, 'dish' => FoodAlchemistRecipe::class] as $rel => $klasse) {
                $ziel = $slot->relationLoaded($rel) ? $slot->getRelation($rel) : null;
                if ($ziel !== null && ! $this->erlaubt($team, $kette, $klasse, $ziel)) {
                    $slot->setRelation($rel, null);
                }
            }
            if ($slot->relationLoaded('embeddedConcept') && $slot->embeddedConcept !== null) {
                $this->fremdeAusblenden($slot->embeddedConcept, $pfad);
            }
        }

        return $concept;
    }

    /** @param  list<int>  $kette  Team-Kette des Besitzers */
    private function erlaubt(Team $team, array $kette, string $klasse, Model $ziel): bool
    {
        $teamId = $ziel->getAttribute('team_id');
        if ($teamId === null && ! array_key_exists('team_id', $ziel->getAttributes())) {
            // Spalte nicht mitgeladen (reduziertes Select) → einmal nachsehen statt blind durchlassen
            $teamId = DB::table($ziel->getTable())->where('id', $ziel->getKey())->value('team_id');
        }
        if ($teamId === null || in_array((int) $teamId, $kette, true)) {
            return true;
        }
        $key = $klasse . ':' . $team->id . ':' . $ziel->getKey();

        return $this->sichtCache[$key] ??= $klasse::visibleToTeam($team)->whereKey($ziel->getKey())->exists();
    }
}
