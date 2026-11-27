<?php

namespace Platform\FoodAlchemist\Services\Concepter;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;

/**
 * Welche Einheit an einer Gericht-/Basisrezept-Position (Konzept- und Paket-Slot) rechnet wirklich?
 *
 * Entscheid Dominique 17.11.: die Auswahl zeigt nur Einheiten, die tatsächlich rechnen; eine andere Einheit wird beim
 * Speichern abgelehnt statt still als Portion gerechnet. Die Regel spiegelt die Rechnung, sie ändert sie nicht:
 * {@see ConcepterAggregateService::portionsAequivalent()} und {@see ConcepterAggregateService::stueckModus()}.
 *
 * Gericht (VK):
 *  - leer = 1 Einheit der Darreichung (Standard), `portion` und die Einheit der Darreichung selbst (keine Masse/kein Volumen)
 *    → Menge = Portionen
 *  - `stk` nur mit Stück-Ertrag (yield_pieces) → Stück-Modus
 *  - `g`, `kg` nur mit Grammatur (Darreichung, sonst Rezept-Altspalte) → Menge × g ÷ Grammatur
 *  - alles andere (ml, l, Karton, Prise, stk ohne Stück-Ertrag …) ging still als Portion ein → nicht wirksam.
 *    Volumen hat im Code keine Umrechnung.
 * Basisrezept: Menge = Gramm je Person, die Einheit wird ignoriert — wirksam sind leer und `g`; `stk` nur mit Stück-Ertrag.
 */
class SlotEinheitService
{
    /** Zähl-Einheiten, die im Stück-Modus als Stück rechnen dürfen (Karton/Beutel o. ä. würden still zu Stück). */
    private const STUECK = ['stk'];

    private const MASSE = ['g', 'kg'];

    public function __construct(private \Platform\FoodAlchemist\Services\Rezept\DarreichungResolver $darreichungen) {}

    /** Wirkt die Einheit an dieser Position? `null` (leer) wirkt immer. */
    public static function wirkt(?object $unit, ?object $dish, ?object $dar): bool
    {
        if ($unit === null) {
            return true;
        }
        if ($dish === null) {
            return false;
        }
        $slug = (string) ($unit->slug ?? '');
        $stueck = in_array($slug, self::STUECK, true) && ConcepterAggregateService::stueckModus($unit, $dish);

        if (! (bool) ($dish->is_sales_recipe ?? true)) {
            return $stueck || ($slug === 'g' && ($unit->dimension ?? null) === 'mass');
        }
        if ($stueck) {
            return true;
        }
        if (in_array($slug, self::MASSE, true)) {
            $portionG = app(\Platform\FoodAlchemist\Services\Rezept\DarreichungResolver::class)->portionGramm($dish, $dar);   // 98 · 3.5 6a

            return ($unit->dimension ?? null) === 'mass' && (float) ($unit->default_in_g ?? 0) > 0
                && $portionG !== null && (float) $portionG > 0;
        }
        if ($slug === 'portion') {
            // Portion ist die Darreichungs-Grundeinheit; mit Stück-Ertrag rechnet sie (Zähl-Einheit) im Stück-Modus — beides wirkt.
            return true;
        }
        // Die Einheit der Darreichung selbst (Glas, Schale …): rechnet als 1 Einheit = 1 Portion, solange sie keine Masse/kein Volumen ist.
        return $dar !== null && $dar->unit_vocab_id !== null && (int) $dar->unit_vocab_id === (int) ($unit->id ?? 0)
            && ! in_array($unit->dimension ?? null, ['mass', 'volume'], true)
            && ! in_array($slug, self::STUECK, true);
    }

    /**
     * Die Auswahl einer Position: wirksame Einheiten des Teams (Vokabular-Reihenfolge).
     *
     * @return Collection<int, FoodAlchemistVocabEinheit>
     */
    public function wirksame(Team $team, FoodAlchemistRecipe $dish, ?FoodAlchemistRecipeDarreichung $dar, ?Collection $einheiten = null): Collection
    {
        return ($einheiten ?? app(\Platform\FoodAlchemist\Services\GpStamm\VocabularyService::class)->listEinheiten($team))
            ->filter(fn ($e) => self::wirkt($e, $dish, $dar))->values();
    }

    /** Beschriftung der leeren Auswahl: was „Standard" an dieser Position heißt. */
    public static function standardLabel(?object $dish, ?object $dar): string
    {
        if ($dish !== null && ! (bool) ($dish->is_sales_recipe ?? true)) {
            return 'g (Standard)';
        }
        $e = $dar?->unit?->display_de ?? null;

        return ($e !== null && $e !== '' ? $e : 'Portion') . ' (Standard)';
    }

    /** Darreichung einer Konzept-/Paket-Position (gleiche Quelle wie die Kostenrechnung). */
    public function darreichungFuerSlot(FoodAlchemistConceptSlot $slot): ?FoodAlchemistRecipeDarreichung
    {
        return $this->darreichungen->fuerSlot($slot);
    }

    /**
     * Speicher-Riegel: wirft mit verständlicher Meldung, wenn die Einheit an dieser Position nicht rechnet.
     *
     * @throws \RuntimeException
     */
    public function pruefe(Team $team, FoodAlchemistConceptSlot $slot, ?int $einheitId): void
    {
        if ($einheitId === null || $slot->dish === null) {
            return;
        }
        $unit = FoodAlchemistVocabEinheit::visibleToTeam($team)->find($einheitId);
        if ($unit === null) {
            throw new \RuntimeException("Einheit {$einheitId} ist unbekannt oder nicht sichtbar.");
        }
        $dar = $this->darreichungFuerSlot($slot);
        if (self::wirkt($unit, $slot->dish, $dar)) {
            return;
        }
        $erlaubt = $this->wirksame($team, $slot->dish, $dar)->map(fn ($e) => $e->slug)->implode(', ');

        throw new \RuntimeException(sprintf(
            'Einheit „%s“ rechnet bei „%s“ nicht und würde still als Portion gezählt. %s Wirksam: leer = %s%s.',
            $unit->display_de ?? $unit->slug,
            $slot->dish->name,
            $this->grund($unit, $slot->dish, $dar),
            self::standardLabel($slot->dish, $dar),
            $erlaubt !== '' ? ', ' . $erlaubt : '',
        ));
    }

    /** Riegel beim Befüllen: Einheit gegen das NEUE Gericht der Position prüfen (fillSlot), ohne die Position zu ändern. */
    public function pruefeBelegung(Team $team, FoodAlchemistConceptSlot $slot, int $recipeId, ?int $einheitId): void
    {
        if ($einheitId === null) {
            return;
        }
        $probe = (clone $slot)->forceFill(['recipe_id' => $recipeId])->setRelation('concept', $slot->concept);
        $probe->unsetRelation('dish');
        $this->pruefe($team, $probe, $einheitId);
    }

    /**
     * Auswahl je Gericht-/Basisrezept-Position eines Konzepts/Pakets (Concepter-Editor): Standard-Beschriftung, wirksame
     * Einheiten und — rechnet die gespeicherte Einheit nicht (Altbestand) — diese als gesperrte Option, damit das Feld
     * nicht still etwas anderes zeigt.
     *
     * @return array<int, array{standard: string, optionen: list<array{id: int, label: string}>, unwirksam: ?array{id: int, label: string}}>
     */
    public function auswahl(Team $team, object $concept): array
    {
        $aus = [];
        $einheiten = app(\Platform\FoodAlchemist\Services\GpStamm\VocabularyService::class)->listEinheiten($team);   // 2.1e: einmal je Konzept statt je Position
        foreach ($concept->slots as $slot) {
            if ($slot->recipe_id === null || $slot->dish === null) {
                continue;
            }
            $slot->setRelation('concept', $concept);
            $dar = $this->darreichungFuerSlot($slot);
            $optionen = $this->wirksame($team, $slot->dish, $dar, $einheiten)
                ->map(fn ($e) => ['id' => (int) $e->id, 'label' => (string) $e->slug])->values()->all();
            $unwirksam = $slot->unit_vocab_id !== null && ! in_array((int) $slot->unit_vocab_id, array_column($optionen, 'id'), true)
                ? ['id' => (int) $slot->unit_vocab_id, 'label' => ($slot->unit?->slug ?? '?') . ' (rechnet nicht)'] : null;
            $aus[(int) $slot->id] = ['standard' => self::standardLabel($slot->dish, $dar), 'optionen' => $optionen, 'unwirksam' => $unwirksam];
        }

        return $aus;
    }

    /**
     * Altbestand: Positionen eines Konzepts/Pakets mit nicht wirksamer Einheit (für den Reife-Befund; ändert nichts).
     *
     * @param  iterable<FoodAlchemistConceptSlot>  $slots
     * @return list<int> slot_ids
     */
    public function unwirksameSlots(iterable $slots): array
    {
        $ids = [];
        foreach ($slots as $slot) {
            if ($slot->unit_vocab_id === null || $slot->recipe_id === null || $slot->dish === null) {
                continue;
            }
            if (! self::wirkt($slot->unit, $slot->dish, $this->darreichungFuerSlot($slot))) {
                $ids[] = (int) $slot->id;
            }
        }

        return $ids;
    }

    private function grund(object $unit, object $dish, ?object $dar): string
    {
        $slug = (string) $unit->slug;
        if (! (bool) ($dish->is_sales_recipe ?? true)) {
            return 'Beim Basisrezept zählt die Menge als Gramm je Person.';
        }
        if (in_array($slug, self::STUECK, true)) {
            return 'Das Gericht hat keinen Stück-Ertrag (yield_pieces).';
        }
        if (in_array($slug, self::MASSE, true)) {
            return 'Die Darreichung hat keine Grammatur.';
        }
        if (($unit->dimension ?? null) === 'volume') {
            return 'Für Volumen gibt es keine Umrechnung.';
        }

        return 'Für diese Einheit gibt es keine Umrechnung.';
    }
}
