<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Position\PositionFehler;

/**
 * Code-Qualität 1.1j: EINE Schreibpfad-Prüfung für Inhalts-Blöcke in Foodbook und Angebot (98 · 3.6: Teil von
 * Position\PositionValidierung; Fehler als PositionFehler mit MCP-Code, Referenz nicht sichtbar = NOT_FOUND).
 * Was ein Block referenziert, muss das schreibende Team sehen dürfen (Sicht-Regel inkl. Abo-Fassungen, {@see Verweisziel}):
 * - recipe_ref: sichtbares VK-Gericht, keine konzept-lokale Slot-Variante (Spec 19 E1.1)
 * - concept_ref: sichtbares Concept oder Paket (kind=paket) — concept_id trägt beide
 * - presentation_id: Darreichung gehört zum Gericht des Blocks
 * - unit_vocab_id: sichtbare Einheit
 *
 * Neu anlegen ($block null): Referenz des Typs ist Pflicht. Ändern: geprüft wird, was gesetzt
 * wird, und die wirksame Referenz, wenn der Typ wechselt.
 */
final class BlockReferenz
{
    public static function pruefe(Team $team, array $daten, ?Model $block = null): void
    {
        $typ = array_key_exists('type', $daten) ? $daten['type'] : $block?->type;
        $typWechsel = $block !== null && array_key_exists('type', $daten) && $daten['type'] !== $block->type;
        $wert = fn (string $f) => array_key_exists($f, $daten) ? $daten[$f] : $block?->{$f};
        $gesetzt = fn (string $f) => $block === null || $typWechsel || array_key_exists($f, $daten);

        if ($typ === 'recipe_ref' && $gesetzt('recipe_id')) {
            self::gericht($team, $wert('recipe_id'));
        }
        if ($typ === 'concept_ref' && $gesetzt('concept_id')) {
            self::concept($team, $wert('concept_id'));
        }
        if ($typ !== 'recipe_ref' && ($daten['recipe_id'] ?? null) !== null && $daten['recipe_id'] !== '') {
            self::gericht($team, $daten['recipe_id']);   // 98 · 3.6 PR5: wie zuvor die Vorab-Prüfung im MCP-Tool — ein gesetztes Gericht ist immer sichtbar
        }
        \Platform\FoodAlchemist\Services\Position\PositionValidierung::darreichung($daten['presentation_id'] ?? null, $wert('recipe_id'));
        if (($daten['unit_vocab_id'] ?? null) !== null && $daten['unit_vocab_id'] !== ''
            && ! Verweisziel::sichtbar(FoodAlchemistVocabEinheit::class, $team)->whereKey((int) $daten['unit_vocab_id'])->exists()) {
            throw new PositionFehler("Einheit {$daten['unit_vocab_id']} ist nicht sichtbar/vorhanden.", 'NOT_FOUND');
        }
    }

    private static function gericht(Team $team, mixed $id): void
    {
        if ($id === null || $id === '') {
            throw new PositionFehler('recipe_ref-Block braucht ein recipe_id (VK-Gericht).');
        }
        $ok = Verweisziel::sichtbar(FoodAlchemistRecipe::class, $team)->verkauf()
            ->whereNull('variant_source_recipe_id')->whereKey((int) $id)->exists();
        if (! $ok) {
            throw new PositionFehler("recipe_id {$id} ist kein gültiges, sichtbares VK-Gericht (keine Slot-Variante) — via foodalchemist.verkaufsrezepte.SEARCH ermitteln.", 'NOT_FOUND');
        }
    }

    private static function concept(Team $team, mixed $id): void
    {
        if ($id === null || $id === '' || ! Verweisziel::sichtbar(FoodAlchemistConcept::class, $team)->whereKey((int) $id)->exists()) {
            throw new PositionFehler('concept_ref-Block braucht ein sichtbares Konzept oder Paket — via foodalchemist.concepts.SEARCH ermitteln.', 'NOT_FOUND');
        }
    }
}
