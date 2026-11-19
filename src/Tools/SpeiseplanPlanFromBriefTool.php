<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanungsEinstiegService;

/**
 * MCP-Einstieg „Speiseplan aus Brief" — spiegelt {@see \Platform\FoodAlchemist\Livewire\Planung\Index::speiseplanAusBrief}.
 * Legt einen GV-Speiseplan an (oder lädt via speiseplan_id) — `create()` legt GV-Standard-Linien + Zyklus +
 * Start-Montag an (kein Gänge-Gerüst) — und startet die Voll-Kaskade (owner_type=speiseplan, eager): je leerer
 * Zyklus-Zelle ein VK-Gericht (gedeckelt). Der Session-Brief fließt als Kontext in jede Zell-Generierung.
 * Tenancy: Writes isOwnedBy.
 */
class SpeiseplanPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant einen GV-Speiseplan aus einem Brief in der Leitstelle: legt einen Speiseplan an (oder '
            . 'lädt via speiseplan_id ein bestehendes, team-eigenes; GV-Standard-Linien + Zyklus werden angelegt, '
            . 'Zyklus-Wochen ggf. aus dem Brief „N Wochen") und startet die Voll-Kaskade (owner_type=speiseplan, '
            . 'eager): je Zyklus-Zelle ein VK-Gericht. Liefert speiseplan_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Saison, Linien, Zyklus z.B. „4 Wochen", Pax/DGE …).'],
                'speiseplan_id' => ['type' => 'integer', 'description' => 'Optional: einen bestehenden, team-eigenen Speiseplan bebriefen (sonst neu anlegen).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für einen neuen Speiseplan (Default „Speiseplan aus Brief"). Ignoriert bei speiseplan_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken (whitelist-gefiltert), z.B. level, sektor, diaet_hart, allergen_nogo[], '
                        . 'frische_erlaubt[], bio_pref, aroma_kueche, saison, ziel_we_pct, menue_quote_vegetarisch_pct, ki_bilder, complete_coverage.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['brief'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        // 98 · 1.3d: derselbe Use-Case wie die Leitstelle (PlanungsEinstiegService) — Besitz, Gerüst, Session, Kaskade.
        try {
            $r = app(PlanungsEinstiegService::class)->speiseplanAusBrief($team, (string) ($arguments['brief'] ?? ''), [
                'owner_id' => $arguments['speiseplan_id'] ?? null,
                'titel' => $arguments['label'] ?? null,
                'creative_mode' => $arguments['creative_mode'] ?? null,
                'leitplanken' => $arguments['leitplanken'] ?? null,
                'created_via' => 'mcp_speiseplan_brief',
            ]);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (NichtGefundenException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        } catch (GeerbtNurLesenException $e) {
            return ToolResult::error($e->getMessage(), 'INHERITED');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PLAN_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $r['run']->id);

        return ToolResult::success([
            'speiseplan_id' => (int) $r['owner']->id,
            'session_id' => (int) $r['session']->id,
            'run' => $status ?? ['run_id' => (int) $r['run']->id, 'status' => (string) $r['run']->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'speiseplan', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe', 'gv'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.speisekarte.PLAN_FROM_BRIEF', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Plane einen 4-Wochen-GV-Speiseplan aus diesem Brief: Betriebsrestaurant, 3 Linien, ausgewogen.'],
        ];
    }
}
