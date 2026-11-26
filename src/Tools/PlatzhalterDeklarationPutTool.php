<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\KeinPlatzhalterException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpStamm\PlatzhalterDeklarationService;

/**
 * Platzhalter-GP: Allergene, Zusatzstoffe und Nährwerte von Hand (Entscheid Dominique 19.11.2026, nur Platzhalter). Gleicher Weg wie die
 * GP-Detailansicht ({@see PlatzhalterDeklarationService}); Lesen über foodalchemist.gps.GET (`platzhalter_deklaration`).
 */
class PlatzhalterDeklarationPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.platzhalter_deklaration.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt bei einem Platzhalter-GP (is_platzhalter, z. B. „Leitungswasser (neutral)“, ohne Lieferantenartikel) Allergene, Zusatzstoffe und '
            . 'Nährwerte von Hand. NUR Platzhalter: jedes andere GP bekommt die Werte aus den Lieferantenartikeln, Aufruf → NOT_PLACEHOLDER. '
            . 'Teil-Update: nur übergebene Felder ändern sich, null/leer leert ein Feld. Sind alle 14 Allergene gesetzt, gilt der Platzhalter als belegt '
            . '(Konfidenz high, Quelle manuell). Danach rechnen die Rezepte mit dem Platzhalter neu. Lesen: foodalchemist.gps.GET → platzhalter_deklaration.';
    }

    public function getSchema(): array
    {
        $allergen = ['type' => 'string', 'description' => implode(' | ', PlatzhalterDeklarationService::ALLERGEN_WERTE) . ' | leer (Feld leeren)'];
        $jaNein = ['type' => 'string', 'description' => 'ja | nein | leer (Feld leeren)'];

        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Platzhalter-GP-Id (eigenes Team).'],
                'alle_allergene' => ['type' => 'string', 'enum' => PlatzhalterDeklarationService::ALLERGEN_WERTE,
                    'description' => 'Ein Wert für alle 14 Allergene (z. B. nicht_enthalten bei Wasser); einzelne `allergene` gehen vor.'],
                'allergene' => ['type' => 'object', 'description' => 'Je Allergen: enthalten | spuren | nicht_enthalten | leer/null (leeren). Schlüssel: '
                    . implode(', ', FoodAlchemistGp::ALLERGEN_FIELDS) . '.',
                    'properties' => array_fill_keys(FoodAlchemistGp::ALLERGEN_FIELDS, $allergen), 'additionalProperties' => false],
                'alle_zusatzstoffe' => ['type' => 'string', 'enum' => ['ja', 'nein'], 'description' => 'Ein Wert für alle 18 Pflicht-Zusatzstoffe (nein = keine).'],
                'zusatzstoffe' => ['type' => 'object', 'description' => 'Je Stoff: ja | nein | leer/null (leeren). Schlüssel: ' . implode(', ', PlatzhalterDeklarationService::stoffe()) . ' (iodized = freiwillige Angabe Jod).',
                    'properties' => array_fill_keys(PlatzhalterDeklarationService::stoffe(), $jaNein), 'additionalProperties' => false],
                'naehrwerte' => ['type' => 'object', 'description' => 'Je 100 g, Zahl ≥ 0 oder null (leeren).',
                    'properties' => array_fill_keys(array_keys(PlatzhalterDeklarationService::NAEHRWERTE), ['type' => 'number']), 'additionalProperties' => false],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ToolResult::error('id ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $daten = app(PlatzhalterDeklarationService::class)->setzen($team, $id,
                array_intersect_key($arguments, array_flip(['alle_allergene', 'allergene', 'alle_zusatzstoffe', 'zusatzstoffe', 'naehrwerte'])));
        } catch (NichtGefundenException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        } catch (KeinPlatzhalterException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_PLACEHOLDER');
        } catch (GeerbtNurLesenException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, ...$daten]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'platzhalter', 'allergene', 'naehrwerte', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gps.GET', 'foodalchemist.platzhalter.POST', 'foodalchemist.platzhalter.PUT'],
            'examples' => ['Leitungswasser (neutral): alle Allergene nicht enthalten, keine Zusatzstoffe, Nährwerte 0.'],
        ];
    }
}
