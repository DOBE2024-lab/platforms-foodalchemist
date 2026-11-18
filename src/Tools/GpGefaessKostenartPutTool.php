<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GefaessKostenartService;

/** Art + Preis eines Gefäß-GP für das eigene Team setzen (17.11.2026) — gilt für alle Darreichungen des Teams mit diesem Gefäß. */
class GpGefaessKostenartPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_gefaess_kostenart.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt Art und Preis von Gefäß-Grundprodukten (Non-Food, Warengruppe 16) für das eigene Team: '
            . 'kostenart einweg (Kosten = preis_stk, ohne Preis der Lead-Artikelpreis je Stück) | mehrweg (Eigenbestand, Kosten = preis_stk, '
            . 'z. B. Spülkosten) | extern (Miete/Fremdartikel, Kosten = preis_stk, ohne Preis der Lead-Artikelpreis je Stück). Fehlt der Preis, meldet die Darreichung '
            . '„Gefäß-Preis fehlt“ und gilt als unvollständig. preis_stk in € netto je Stück (leer = kein Preis). kostenart null/leer entfernt den '
            . 'Eintrag des Teams (dann gilt das Oberteam bzw. die Automatik: Verleih-Lieferant → extern, sonst einweg). Die Kosten gehen je Stück '
            . 'in den Wareneinsatz der Darreichung; recipe_darreichung.PUT attrs.vessel_cost_type kann die Art je Darreichung übersteuern. '
            . 'Rechnet die Darreichungen mit diesem Gefäß neu.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Gefäß-GPs (Non-Food).'],
            'kostenart' => ['description' => 'einweg | mehrweg | extern, oder null/leer zum Entfernen.'],
            'preis_stk' => ['description' => 'Preis € netto je Stück (Zahl oder Text mit Komma), null/leer = kein Preis.'],
        ], 'required' => ['gp_ids']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['gp_ids'] ?? [])))));
        if ($ids === []) {
            return ToolResult::error('gp_ids fehlt.', 'VALIDATION_ERROR');
        }
        $svc = app(GefaessKostenartService::class);
        $neu = 0;
        try {
            $art = GefaessKostenartService::pruefe($arguments['kostenart'] ?? null);
            $preis = GefaessKostenartService::pruefePreis($arguments['preis_stk'] ?? null);
            foreach ($ids as $id) {
                $neu += $svc->setzen($team, $id, $art, $preis);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['gesetzt' => count($ids), 'kostenart' => $art, 'preis_stk' => $art !== null ? $preis : null, 'darreichungen_neu_gerechnet' => $neu]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'gefaess', 'darreichung', 'kosten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates'],
            'related_tools' => ['foodalchemist.recipe_darreichung.PUT'],
            'examples' => ['Das Schraubglas 212 ml ist bei uns Mehrweg, 0,08 € je Stück fürs Spülen.', 'Speiseteller vom Verleiher: extern, 0,55 € je Stück.'],
        ];
    }
}
