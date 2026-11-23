<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\Verweisziel;

/**
 * Code-Qualität 1.1c (K3): Ziele eines Concept-Slots — eingebettetes Paket (kind=paket-Concept),
 * oder Rezept — beim SCHREIBEN prüfen (ein Alt-Paket per package_id wird seit 98 · 2.6c abgelehnt). {@see ConceptService::fillSlot} nimmt nur Ziele an,
 * die das Team sehen darf (bestehende Sicht-Regel inkl. Abo-Fassungen, {@see Verweisziel}), ein Paket
 * nicht in sich selbst (auch nicht über Umwege) und einen zum Rezept passenden Typ.
 *
 * Lesen bleibt unverändert: die Slot-Beziehungen lösen bewusst ohne Teamsicht auf (Spec 82 „verweis“,
 * auch Fassungen). Ein fremdes Ziel kommt nur über ungeprüftes Schreiben hinein — das schließt diese Prüfung.
 */
class ConceptSlotZielService
{
    /**
     * Prüft das Ziel, das fillSlot setzen will (genau eines: embedded_concept_id, package_id,
     * sales_recipe_id). Liefert für ein Rezept den Slot-Typ, sonst null. Wirft bei nicht sichtbarem
     * Ziel, Kreis oder widersprechendem Typ.
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
        if (! Verweisziel::sichtbar(FoodAlchemistConcept::class, $team)->pakete()->whereKey($paketId)->exists()) {
            throw new ModelNotFoundException("Paket #{$paketId} nicht sichtbar oder kein Paket.");
        }
        if ($this->enthaelt($paketId, (int) $slot->concept_id)) {
            throw new \RuntimeException("Paket Nr. {$paketId} enthält dieses Konzept bereits — das wäre ein Kreis.");
        }
    }

    /** 98 · 2.6c: alte Pakete gibt es nicht mehr — neue package_id-Verweise werden abgelehnt. */
    public function pruefeAltPaket(Team $team, int $paketId): void
    {
        throw new \RuntimeException("Paket #{$paketId}: alte Pakete gibt es nicht mehr. Ein Paket wird über embedded_concept_id (Konzept der Art Paket) eingehängt.");
    }

    /**
     * Prüft das Rezept und liefert den Slot-Typ. Ohne Typ: aus dem Rezept abgeleitet
     * (VK-Gericht → gericht, sonst basisrezept). Ein widersprechender Typ wird abgelehnt.
     */
    public function rezeptTyp(Team $team, int $recipeId, ?string $typ): string
    {
        $rezept = Verweisziel::sichtbar(FoodAlchemistRecipe::class, $team)->whereKey($recipeId)->first(['foodalchemist_recipes.id', 'foodalchemist_recipes.is_sales_recipe'])
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
}
