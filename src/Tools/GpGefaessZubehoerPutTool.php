<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService;

/** Zubehör am Gefäß-GP für das eigene Team pflegen (20.11.2026) — z. B. 1 × Deckel am Schraubglas; jede Darreichung mit dem Gefäß rechnet es mit. */
class GpGefaessZubehoerPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_gefaess_zubehoer.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt das Zubehör eines Gefäß-Grundprodukts (Non-Food, Warengruppe 16) für das eigene Team: „gehört dazu: menge × <Non-Food-GP>“, '
            . 'z. B. 1 × Deckel am Schraubglas, Etikett, Löffel. Jede Darreichung mit diesem Gefäß rechnet das Zubehör automatisch mit, jedes Teil mit '
            . 'seinem eigenen Preis und seiner eigenen Kostenart (gp_gefaess_kostenart.PUT am Zubehör-GP; einweg/extern ohne Preis = Artikelpreis je Stück, '
            . 'mehrweg nur mit Preis am GP; fehlt ein Preis, gilt die Darreichung als unvollständig). Eine Ebene: Zubehör hat kein eigenes Zubehör. '
            . 'setzen = [{gp_id, menge}] (Stück je Gefäß, > 0; setzt oder ändert), entfernen = [gp_id], zuruecksetzen = true verwirft die eigene Liste '
            . '(dann gilt die des Oberteams). Ohne Änderung liefert das Tool nur die wirksame Liste mit Preisen. Unter-Teams erben die Liste des Oberteams; '
            . 'die erste Änderung übernimmt sie ins eigene Team. Rechnet die Darreichungen mit diesem Gefäß neu.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'gefaess_gp_id' => ['type' => 'integer', 'description' => 'Das Gefäß (Non-Food-GP).'],
            'setzen' => ['type' => 'array', 'description' => 'Zubehör setzen/ändern.', 'items' => ['type' => 'object', 'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'Zubehör (Non-Food-GP).'],
                'menge' => ['description' => 'Stück je Gefäß (> 0, Komma erlaubt), Standard 1.'],
            ], 'required' => ['gp_id']]],
            'entfernen' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Zubehör-GPs, die nicht mehr dazugehören.'],
            'zuruecksetzen' => ['type' => 'boolean', 'description' => 'Eigene Liste verwerfen, danach gilt die des Oberteams.'],
        ], 'required' => ['gefaess_gp_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gefaessId = (int) ($arguments['gefaess_gp_id'] ?? 0);
        if ($gefaessId <= 0) {
            return ToolResult::error('gefaess_gp_id fehlt.', 'VALIDATION_ERROR');
        }
        $svc = app(GefaessZubehoerService::class);
        $neu = 0;
        try {
            if (! empty($arguments['zuruecksetzen'])) {
                $neu += $svc->zuruecksetzen($team, $gefaessId);
            }
            foreach ((array) ($arguments['entfernen'] ?? []) as $gpId) {
                $neu += $svc->entfernen($team, $gefaessId, (int) $gpId);
            }
            foreach ((array) ($arguments['setzen'] ?? []) as $z) {
                if (! is_array($z) || (int) ($z['gp_id'] ?? 0) <= 0) {
                    return ToolResult::error('setzen: je Eintrag gp_id (und optional menge) angeben.', 'VALIDATION_ERROR');
                }
                $neu += $svc->setzen($team, $gefaessId, (int) $z['gp_id'], $z['menge'] ?? 1);
            }
            $gefaess = FoodAlchemistGp::visibleToTeam($team)->find($gefaessId);
            if ($gefaess === null || ! $gefaess->istNonFood()) {
                return ToolResult::error('Gefäß (Non-Food-Grundprodukt) nicht sichtbar oder nicht vorhanden.', 'VALIDATION_ERROR');
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $liste = $svc->liste($gefaessId, $team);
        $kosten = app(DarreichungService::class)->gefaessZubehoerKosten($gefaess, $team);

        return ToolResult::success([
            'gefaess_gp_id' => $gefaessId, 'gefaess' => $gefaess->name,
            'liste_von_team_id' => $liste['team_id'], 'geerbt' => $liste['team_id'] !== null && $liste['team_id'] !== (int) $team->id,
            'zubehoer' => array_map(fn ($z) => ['gp_id' => $z['gp_id'], 'name' => $z['name'], 'menge' => $z['menge'], 'kostenart' => $z['kostenart'],
                'preis_stk' => $z['preis_stk'], 'kosten_je_gefaess' => $z['kosten'], 'preis_fehlt' => $z['kosten'] === null], $kosten['zeilen']),
            'zubehoer_summe_je_gefaess' => $kosten['summe'], 'preis_fehlt' => $kosten['preis_fehlt'],
            'darreichungen_neu_gerechnet' => $neu,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'gefaess', 'zubehoer', 'darreichung', 'kosten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates', 'deletes'],
            'related_tools' => ['foodalchemist.gp_gefaess_kostenart.PUT', 'foodalchemist.recipe_darreichung.PUT'],
            'examples' => ['Zum Schraubglas 212 ml gehört immer 1 Deckel 82 mm.', 'Welches Zubehör hängt am Achenbachglas und was kostet es?'],
        ];
    }
}
