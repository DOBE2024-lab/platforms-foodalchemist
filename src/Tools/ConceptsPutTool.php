<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Konzept-Stammdaten bearbeiten (team-eigen; Service-Whitelist). */
class ConceptsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet ein team-eigenes Gerichte-Konzept. felder: name, occasion, level, class, description, '
            . 'target_price_per_person, price_mode, price_display, kundentyp, default_niveau, default_convenience, '
            . 'writing_style_id … (nur Service-Whitelist wirkt). Status → concepts.STATUS. '
            . 'Optional service_moments / seasons (Namen wie bei concepts.POST): ersetzen die Liste, [] leert sie; '
            . 'weggelassen bleibt der Bestand unberührt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'felder' => ['type' => 'object', 'description' => 'Zu schreibende Konzept-Felder.'],
                'service_moments' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Einsatzmomente (Namen) — ersetzt die Liste; [] leert sie'],
                'seasons' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Saison-Facetten (Namen) — ersetzt die Liste; [] leert sie'],
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
        $felder = $arguments['felder'] ?? [];
        // 98 · 2.3d (Parität Concepter-Editor): Facetten nur, wenn übergeben — anders als der Editor, der fehlende als leer liest.
        $facetten = array_intersect_key($arguments, ['service_moments' => 1, 'seasons' => 1]);
        if (! is_array($felder) || ($felder === [] && $facetten === [])) {
            return ToolResult::error('felder (nicht-leeres Objekt) oder service_moments/seasons angeben.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;
        }
        $ids = [];
        foreach (['service_moments' => 'foodalchemist_service_moments', 'seasons' => 'foodalchemist_seasons'] as $arg => $tabelle) {
            if (! array_key_exists($arg, $facetten)) {
                continue;
            }
            if (! is_array($facetten[$arg])) {
                return ToolResult::error("{$arg}: Liste von Namen.", 'VALIDATION_ERROR');
            }
            try {   // unbekannter Name → Fehler statt still verworfen: die Liste wird ersetzt, ein fehlender Name löschte einen Bestandswert
                $ids[$arg] = array_values(array_unique(array_filter(array_map(
                    fn ($name) => $this->resolveFacetId($team, $tabelle, (string) $name), $facetten[$arg]))));
            } catch (\RuntimeException $e) {
                return ToolResult::error("{$arg}: " . $e->getMessage(), 'VALIDATION_ERROR');
            }
        }

        try {
            $c = \Illuminate\Support\Facades\DB::transaction(function () use ($team, $id, $felder, $ids) {
                $svc = app(ConceptService::class);
                $c = $felder !== [] ? $svc->update($team, $id, $felder) : FoodAlchemistConcept::where('team_id', $team->id)->findOrFail($id);
                if (array_key_exists('service_moments', $ids)) {
                    $svc->syncEinsatzmomente($team, $id, $ids['service_moments']);
                }
                if (array_key_exists('seasons', $ids)) {
                    $svc->syncSaisons($team, $id, $ids['seasons']);
                }

                return $c;
            });
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $c->id, 'name' => $c->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concepts.STATUS', 'foodalchemist.concepts.GET'],
            'examples' => ['Setze bei Konzept 7 den Zielpreis/Preis-Modus.'],
        ];
    }
}
