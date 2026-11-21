<?php

namespace Platform\FoodAlchemist\Tools\Concerns;

use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;

/**
 * 98 · 2.3d (Parität Rezept): die Fachfelder, die das Rezept-Modal setzt und die recipes.POST/PUT bisher nicht
 * kannten — Temperatur, Funktion, Notiz, Ertrag in Stück und Geräte. Gleiche Regeln wie im Modal: Temperatur und
 * Funktion sind Freitext (RecipeService kürzt auf 255 Zeichen), Stück ist eine Zahl ab 0, Geräte nur aus dem für
 * das Team sichtbaren Vokabular. Unbekannte Geräte-IDs lehnt das Tool ab, statt sie still zu verwerfen.
 */
trait RezeptFachfelder
{
    /** @return array<string, array<string, mixed>> Schema-Eigenschaften der Fachfelder */
    protected function fachfelderSchema(): array
    {
        return [
            'temperature' => ['type' => 'string', 'description' => 'Serviertemperatur als Freitext, z. B. warm, kalt, raumtemperatur'],
            'function' => ['type' => 'string', 'description' => 'Funktion im Gericht als Freitext, z. B. Komponente, Sauce, Bindung'],
            'notes_manual' => ['type' => 'string', 'description' => 'Notiz zum Rezept'],
            'yield_pieces' => ['type' => 'number', 'description' => 'Ertrag in Stück (ab 0)'],
            'equipment_ids' => ['type' => 'array', 'items' => ['type' => 'integer'],
                'description' => 'Koch-Geräte (IDs aus dem Geräte-Vokabular). Ersetzt die Liste; [] leert sie.'],
        ];
    }

    /**
     * Die übergebenen Fachfelder, geprüft, als Eingabe für RecipeService::create/update — oder ein VALIDATION_ERROR.
     *
     * @return array<string, mixed>|ToolResult
     */
    protected function fachfelder(array $arguments, Team $team): array|ToolResult
    {
        $in = array_intersect_key($arguments, $this->fachfelderSchema());
        if (array_key_exists('yield_pieces', $in) && $in['yield_pieces'] !== null && (! is_numeric($in['yield_pieces']) || (float) $in['yield_pieces'] < 0)) {
            return ToolResult::error('yield_pieces: Zahl ab 0.', 'VALIDATION_ERROR');
        }
        if (array_key_exists('equipment_ids', $in)) {
            $ids = $in['equipment_ids'];
            if (! is_array($ids) || array_filter($ids, fn ($id) => ! is_numeric($id) || (int) $id != $id) !== []) {
                return ToolResult::error('equipment_ids: Liste von Geräte-IDs.', 'VALIDATION_ERROR');
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $sichtbar = FoodAlchemistVocabKochequipment::visibleToTeam($team)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (($fremd = array_diff($ids, $sichtbar)) !== []) {
                return ToolResult::error('Unbekannte Geräte-IDs: ' . implode(', ', $fremd) . '.', 'VALIDATION_ERROR');
            }
            $in['equipment_ids'] = $ids;
        }

        return $in;
    }
}
