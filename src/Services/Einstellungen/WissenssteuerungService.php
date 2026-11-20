<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Services\Knowledge\WissensSichtbarkeit;

/**
 * 98 · 2.3a — Wissens-Routings je Kategorie (Einstellungen › Wissenssteuerung, Spec 52 · Grundsatz E) headless
 * speichern und entfernen. `knowledge_routings` ist globaler Master (keine team_id) — eine Fehlkonfiguration legt die
 * KI-Versorgung ALLER Mandanten still, darum schreibt nur das Master-Team ({@see WissensSichtbarkeit::darfGlobalSchreiben()}).
 * Routings je Wissensart pflegt der KnowledgeRoutingService (setArt) — hier werden sie nicht überschrieben.
 *
 * Fehler: GeerbtNurLesenException (RuntimeException) und \InvalidArgumentException — die Meldungen sind für Menschen
 * geschrieben und werden so angezeigt.
 */
class WissenssteuerungService
{
    private const TABELLE = 'foodalchemist_knowledge_routings';

    public const MODI = ['always', 'discovery', 'grounding', 'none'];

    /**
     * Routing ändern ($id gesetzt) oder für (feature, category) anlegen/überschreiben.
     *
     * @param  array{feature?: string, category?: string, mode?: string, max_docs?: string, max_chars_per_doc?: string}  $eingabe
     */
    public function speichern(Team $team, ?int $id, array $eingabe): void
    {
        $this->nurMaster($team);
        $mode = (string) ($eingabe['mode'] ?? '');
        if (! in_array($mode, self::MODI, true)) {
            throw new \InvalidArgumentException('Bitte eine Verwendung wählen: „Immer vollständig", „Passendes suchen", „Je Hauptzutat" oder „Bewusst nicht".');
        }
        $maxDocs = $eingabe['max_docs'] ?? '';
        $maxChars = $eingabe['max_chars_per_doc'] ?? '';
        $daten = [
            'mode' => $mode,
            'max_docs' => $maxDocs === '' ? null : max(0, (int) $maxDocs),
            'max_chars_per_doc' => $maxChars === '' ? null : max(0, (int) $maxChars),
            'updated_at' => now(),
        ];

        if ($id !== null) {
            if (DB::table(self::TABELLE)->where('id', $id)->whereNotNull('art')->exists()) {
                throw new \InvalidArgumentException('Diese Einstellung gehört zu einer Wissensart. Bitte unter „Wissen nach Art" bearbeiten.');
            }
            DB::table(self::TABELLE)->where('id', $id)->update($daten);

            return;
        }

        $feature = trim((string) ($eingabe['feature'] ?? ''));
        $category = trim((string) ($eingabe['category'] ?? ''));
        if ($feature === '' || $category === '') {
            throw new \InvalidArgumentException('Bitte Arbeitsschritt und Kategorie angeben.');
        }
        // (feature, category) ist unique — updateOrInsert, sonst stirbt das Anlegen am Bestand.
        DB::table(self::TABELLE)->updateOrInsert(
            ['feature' => $feature, 'category' => $category],
            $daten + ['created_at' => now()],
        );
    }

    /** Ohne Zeile ist die Kategorie search-only — das ist etwas ANDERES als `none` (bewusst leer). */
    public function entfernen(Team $team, int $id): void
    {
        $this->nurMaster($team);
        DB::table(self::TABELLE)->where('id', $id)->delete();
    }

    private function nurMaster(Team $team): void
    {
        if (! WissensSichtbarkeit::darfGlobalSchreiben($team)) {
            throw new GeerbtNurLesenException('Wissens-Routing', 'Diese Einstellung gilt für alle Teams. Ändern darf sie nur das Master-Team.');
        }
    }
}
