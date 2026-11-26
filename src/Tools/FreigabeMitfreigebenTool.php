<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FreigabeGesperrtException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Services\Rezept\FreigabeSperre;

/**
 * Code-Qualität 1.1f · „Alles mitfreigeben“ per MCP (Headless-Regel: Service + Tool + UI). Ohne `ausfuehren` nur die Vorschau (welche
 * Komponenten mitfreigegeben würden, welche weiter blockieren); mit `ausfuehren=true` Freigabe der Komponenten und danach des Ziels über den
 * normalen Status-Weg. Rolle Kuratieren prüft der Service.
 */
class FreigabeMitfreigebenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.freigabe.MITFREIGEBEN';
    }

    public function getDescription(): string
    {
        return 'Freigabe-Sperre: ein Rezept/Gericht/Concept wird nur freigegeben, wenn alle Komponenten (rekursiv) freigegeben sind. '
            . 'Ohne ausfuehren: Vorschau (freigebbar = eigene Entwürfe/Prüfen, blockiert = Platzhalter/Veraltet/geerbt). '
            . 'Mit ausfuehren=true: Komponenten mitfreigeben und das Ziel freigeben (Rolle Kuratieren).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'art' => ['type' => 'string', 'enum' => ['rezept', 'concept'], 'description' => 'rezept (Basisrezept oder Gericht) oder concept.'],
                'id' => ['type' => 'integer', 'description' => 'Id des Ziels (team-eigen).'],
                'ausfuehren' => ['type' => 'boolean', 'description' => 'true = freigeben; sonst nur Vorschau.'],
            ],
            'required' => ['art', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $art = (string) ($arguments['art'] ?? '');
        $id = (int) ($arguments['id'] ?? 0);
        if (! in_array($art, ['rezept', 'concept'], true)) {
            return ToolResult::error('art muss rezept oder concept sein.', 'VALIDATION_ERROR');
        }
        $modell = $art === 'concept' ? FoodAlchemistConcept::class : FoodAlchemistRecipe::class;
        if (($guard = $this->guardOwned($team, $modell, $id, $art === 'concept' ? 'Konzept' : 'Rezept')) !== null) {
            return $guard;
        }
        $sperre = app(FreigabeSperre::class);
        if (empty($arguments['ausfuehren'])) {
            return ToolResult::success(['art' => $art, 'id' => $id] + $sperre->vorschau($team, $art, $id));
        }
        try {
            $ergebnis = $sperre->mitFreigeben($team, $art, $id);
            $art === 'concept'
                ? app(ConceptService::class)->setStatus($team, $id, 'active')
                : app(RecipeService::class)->setStatus($team, $id, 'approved');
        } catch (FreigabeGesperrtException $e) {
            return ToolResult::error($e->getMessage(), 'FREIGABE_GESPERRT', ['offen' => $e->offen]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        }

        return ToolResult::success(['art' => $art, 'id' => $id, 'freigegeben' => true, 'mitfreigegeben' => $ergebnis['freigegeben']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'concept', 'status', 'freigabe', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.STATUS', 'foodalchemist.verkaufsrezepte.STATUS', 'foodalchemist.concepts.STATUS'],
            'examples' => ['Welche Komponenten von Gericht 501 sind noch nicht freigegeben?', 'Gib Gericht 501 mit allen Komponenten frei.'],
        ];
    }
}
