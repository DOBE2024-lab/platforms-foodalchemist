<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DarreichungService;

/**
 * MCP-Steuerbarkeit · D3: Darreichungsform bearbeiten (Menge/Einheit/Preisklasse/Preis-Modus/VK/Notiz).
 * Der Service filtert auf die Darreichungs-Whitelist. Nur team-eigene Gerichte.
 */
class RecipeDarreichungPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet eine Darreichungsform. attrs: serving_form_id (wechselt die Servierform — '
            . 'der Weg aus dem Review-Zustand "unbestimmt"), menge_vom_standard (bool; true übernimmt Mengen des aktuellen Standards, beim Standard aus der Rezeptur; false hält die aktuelle Komposition als Mengen je Komponente fest), quantity_per_unit_g ist nicht setzbar (VALIDATION_ERROR — die Grammatur ergibt sich aus den Komponenten; Varianten über anteil_prozent oder recipe_darreichung_delta.PUT), unit_count (nur Bestandskompatibilität; neue Gerichte haben eine Portion), markup_class_id, '
            . 'name (Bezeichnung der Variante), ausgabe_station_id (sichtbarer Ausgabe-Posten, leer = bisherige Verteilung), arbeitszeiten (Liste mit use_case=anrichten_teller|abfuellen|portionieren_platte|stueck|pauschale, basis=portion|einheit|stueck|behaelter|kg|auftragszeile, ort=kueche|vor_ort, wert>=0; portion/einheit/stueck in Sekunden, sonst Minuten; eine gepflegte Liste ersetzt die Altzeiten vollständig, [] bedeutet bewusst keine aktive Arbeit), '
            . 'price_mode (auto|manuell), sales_net, vat_profile_key, vessel_gp_id (Gefäß: Non-Food-GP der Warengruppe 16, leer löst; bestimmt den Servier-Typ; 1 Stück je Einheit, Kosten nach Art und Preis), vessel_cost_type (Art des Gefäßes, übersteuert den Team-Eintrag am GP: einweg = Preis am GP, sonst Artikelpreis je Stück | mehrweg = Eigenbestand, Preis am GP | extern = Miete/Fremdartikel, Preis am GP, sonst Artikelpreis je Stück; fehlt der Preis: Hinweis, Darreichung unvollständig; leer = Eintrag des Teams aus gp_gefaess_kostenart.PUT, sonst Automatik Verleih→extern, sonst einweg; Zubehör am Gefäß wie Deckel/Etikett kommt aus gp_gefaess_zubehoer.PUT und rechnet automatisch mit), serving_vehicle_vocab_id (Geschirr-Empfehlung = Servier-Typ, kostet nichts, unabhängig vom Gefäß wählbar; neutral: ohne Gefäß der Typ selbst, mit Gefäß eine ausdrückliche Übersteuerung; an der Standard-Darreichung wird der wirksame Typ ins Gericht gespiegelt; leer = Typ des Gefäßes, ein Wert gleich dem Typ des Gefäßes wird geleert), note, anrichten_wert (Spec 83: Einheit aus der Servierform — Teller/Box/Theke/Foodtruck s je Portion, Flying/Fingerfood s je Stück, Buffet/Pause/Sharing min je Platte; leer = Startwert), anrichten_pauschal_min (Minuten je Auftragszeile) (nur Whitelist wirkt). '
            . 'anteil_prozent (eigener Parameter neben attrs, nur an Varianten): setzt alle Komponenten-Mengen auf diesen Anteil der Standard-Darreichung (z. B. 50 = halbe Portion); ersetzt die bisherigen Komponenten-Mengen der Variante, weggelassene Komponenten der Variante bleiben weggelassen; nicht kombinierbar mit quantity_per_unit_g oder menge_vom_standard.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'presentation_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id.'],
                'attrs' => ['type' => 'object', 'description' => 'Zu schreibende Darreichungs-Felder.'],
                'anteil_prozent' => ['type' => 'number', 'description' => 'Nur Varianten: alle Komponenten-Mengen auf diesen Anteil (Prozent, > 0 bis 1000) der Standard-Darreichung.'],
            ],
            'required' => ['presentation_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        // Plan 98 B, Schritt 5: „alle Mengen auf … %“ — allein oder zusammen mit attrs, wird zuletzt angewandt.
        $anteil = $arguments['anteil_prozent'] ?? null;
        if ($anteil !== null && ! is_numeric($anteil)) {
            return ToolResult::error('anteil_prozent muss eine Zahl sein (Prozent der Standard-Darreichung).', 'VALIDATION_ERROR');
        }
        $attrs = $arguments['attrs'] ?? ($anteil !== null ? [] : null);
        if (! is_array($attrs) || ($attrs === [] && $anteil === null)) {
            return ToolResult::error('attrs muss ein nicht-leeres Objekt sein (oder anteil_prozent angeben).', 'VALIDATION_ERROR');
        }
        if ($anteil !== null && (array_key_exists('quantity_per_unit_g', $attrs) || array_key_exists('menge_vom_standard', $attrs))) {
            return ToolResult::error('anteil_prozent setzt die Mengen selbst — nicht zusammen mit quantity_per_unit_g oder menge_vom_standard.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['presentation_id'] ?? 0);
        if (($guard = $this->guardDarreichungOwned($team, $id)) !== null) {
            return $guard;
        }

        // 2026-09-04: der Formwechsel ist ein eigener Übergang (Kollisionsprüfung gegen die
        // schon vergebenen Formen), kein Attribut-Update — deshalb aus `attrs` herausgezogen.
        // Ohne diesen Weg könnte die UI mehr als das Tool.
        $formId = $attrs['serving_form_id'] ?? null;
        unset($attrs['serving_form_id']);

        // 98 · 2.3d → Plan 98 B: die Grammatur ist an allen Darreichungen gesperrt; der Service wirft (GRAMMATUR_GESPERRT),
        // der catch unten macht daraus VALIDATION_ERROR — für Oberfläche und Tool dieselbe Regel.

        $svc = app(DarreichungService::class);
        try {
            $dar = ($formId !== null && $formId !== '')
                ? $svc->setzeServierform($team, $id, (int) $formId)
                : null;
            if ($attrs !== []) {
                $dar = $svc->aktualisieren($team, $id, $attrs);
            }
            if ($anteil !== null) {
                $dar = $svc->setzeAnteil($team, $id, (float) $anteil);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        if ($dar === null) {
            return ToolResult::error('attrs enthält kein schreibbares Feld.', 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'presentation_id' => (int) $dar->id,
            'updated' => true,
            'serving_form_id' => (int) $dar->serving_form_id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'darreichung', 'preis', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_darreichung.POST', 'foodalchemist.recipe_darreichung_delta.PUT'],
            'examples' => [
                'Setze bei Darreichung 88 den Preis-Modus auf manuell und sales_net 14.50.',
                'Stelle Darreichung 88 von "unbestimmt" auf die Servierform Teller (serving_form_id).',
                'Setze die Kinder-Variante 91 auf 50 % der Standard-Mengen (anteil_prozent).',
            ],
        ];
    }
}
