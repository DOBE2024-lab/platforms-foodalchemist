<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\KalkulationService;

/**
 * Phase C: Kalkulations-Werkstatt (read-only) — HK1/HK2-Zerlegung für
 * Rezept, Konzept oder Paket nach den Team-Kalkulationsparametern.
 * package_id = Concepter-Paket (kind=paket-Concept); abo_paket_id = Alt-/Abo-Paket bis Paket 2.6.
 */
class KalkulationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kalkulation.GET';
    }

    public function getDescription(): string
    {
        return 'Rechnet die Kalkulation (HK1/HK2, Wareneinsatz, Arbeitszeit, Nebenkosten) nach den '
            . 'Team-Parametern — GENAU EINES angeben: recipe_id, concept_id, package_id oder abo_paket_id. '
            . 'package_id = Paket wie bei foodalchemist.pakete.GET (Concepter-Paket, concept mit kind=paket). '
            . 'abo_paket_id = altes Abo-Paket aus foodalchemist.abo_pakete.LIST (Übergang, entfällt mit der Paket-Umstellung). '
            . 'Optional outlet_id: rechnet gegen die Kostenstruktur EINES Kalkulationsprofils (Ebene 2). Read-only.'
            . ' Ablauf und geltende Regelwerke vorher: foodalchemist.ablauf.GET(vorgang="preis_margen_monitoring").';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'concept_id' => ['type' => 'integer'],
                'package_id' => ['type' => 'integer', 'description' => 'Paket wie bei pakete.GET (Concepter-Paket = concept mit kind=paket). IDs via pakete.LIST.'],
                'abo_paket_id' => ['type' => 'integer', 'description' => 'Übergang: altes Abo-Paket (abo_pakete.LIST/GET). Entfällt mit der Paket-Umstellung.'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional: Kalkulationsprofil/Standort — HK/VK gegen dessen Kostenstruktur (Marge/Ziel-WE/Stundensatz/Fixkosten). Fehlt = Team-Baseline. IDs via outlets.GET.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $keys = array_values(array_intersect(['recipe_id', 'concept_id', 'package_id', 'abo_paket_id'], array_keys(array_filter($arguments))));
        if (count($keys) !== 1) {
            return ToolResult::error('Genau EINES von recipe_id, concept_id, package_id, abo_paket_id angeben.', 'VALIDATION_ERROR');
        }
        $svc = app(KalkulationService::class);

        $outlet = null;
        if (! empty($arguments['outlet_id'])) {
            $outlet = FoodAlchemistOutlet::where('team_id', $team->id)->find((int) $arguments['outlet_id']);
            if ($outlet === null) {
                return ToolResult::error('Kalkulationsprofil nicht gefunden im Team.', 'NOT_FOUND');
            }
        } else {
            // Ebene 2: kein explizites outlet_id → aktive Betriebs-Brille (outlets.SET_ACTIVE), sonst Team-Baseline.
            $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)
                ->current($team, $context->user?->id !== null ? (int) $context->user->id : null);
        }

        try {
            $ergebnis = match ($keys[0]) {
                'recipe_id' => ($m = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $arguments['recipe_id']))
                    ? ['ziel' => ['type' => 'recipe', 'id' => $m->id, 'name' => $m->name], 'outlet_id' => $outlet?->id, 'kalkulation' => $svc->recipeHk($team, $m, $outlet),
                        // Weg-B (2026-10-12): FEK = 0 hier ist kein Konfigurationsfehler — die Katalogsicht
                        // rechnet bewusst ohne Arbeitszeit (Commit af2c8783); Lohn gehört in den Produktionslauf.
                        'hinweis' => 'Katalogsicht: Lohnkosten (FEK) bewusst 0 — Arbeitszeit wird erst im Produktionslauf bewertet (produktion.*), nicht aus work_time_min je Rezept.'] : null,
                'concept_id' => ($m = FoodAlchemistConcept::visibleToTeam($team)->find((int) $arguments['concept_id']))
                    ? ['ziel' => ['type' => 'concept', 'id' => $m->id, 'name' => $m->name], 'outlet_id' => $outlet?->id, 'kalkulation' => $svc->conceptHk($team, $m, $outlet)] : null,
                // 1.1a: package_id = Concepter-Paket (kind=paket-Concept) wie pakete.GET — bei ID-Gleichheit mit der Alt-Tabelle gilt immer das Concept
                'package_id' => ($m = app(ConceptPaketService::class)->sichtbar($team, (int) $arguments['package_id']))
                    ? ['ziel' => ['type' => 'paket', 'id' => $m->id, 'name' => $m->name], 'outlet_id' => $outlet?->id, 'kalkulation' => $svc->conceptHk($team, $m, $outlet)] : null,
                // Übergang bis Paket 2.6: altes Abo-Paket (foodalchemist_packages)
                'abo_paket_id' => ($m = FoodAlchemistPaket::visibleToTeam($team)->find((int) $arguments['abo_paket_id']))
                    ? ['ziel' => ['type' => 'abo_paket', 'id' => $m->id, 'name' => $m->name], 'outlet_id' => $outlet?->id, 'kalkulation' => $svc->paketHk($team, $m, $outlet)] : null,
            };
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }
        if ($ergebnis === null) {
            if ($keys[0] === 'package_id' && FoodAlchemistPaket::visibleToTeam($team)->whereKey((int) $arguments['package_id'])->exists()) {
                return ToolResult::error('Paket nicht gefunden. package_id meint Concepter-Pakete (pakete.LIST); für ein altes Abo-Paket abo_paket_id angeben.', 'NOT_FOUND');
            }

            return ToolResult::error('Ziel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success($ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'kalkulation', 'hk', 'wareneinsatz', 'marge', 'price'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipes.GET', 'foodalchemist.concepts.GET', 'foodalchemist.pakete.GET', 'foodalchemist.abo_pakete.GET', 'foodalchemist.angebote.GET'],
            'examples' => ['Was kostet Rezept 456 in der Herstellung (HK1/HK2)?', 'Kalkuliere Konzept 42', 'Kalkuliere Paket 17 (package_id)', 'Kalkuliere Abo-Paket 5 (abo_paket_id)'],
        ];
    }
}
