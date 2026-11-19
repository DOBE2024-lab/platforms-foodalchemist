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
 * MCP-Einstieg „Angebot aus Brief" — spiegelt {@see \Platform\FoodAlchemist\Livewire\Planung\Index::angebotAusBrief}.
 * Legt ein Angebot an (oder lädt via angebot_id), baut das Gerüst aus dem Brief (owner-neutral) und startet die
 * Voll-Kaskade (owner_type=offer, eager): je Slot ein Concept, das via GenerateConceptJob direkt ans Angebot
 * referenziert wird (Pivot foodalchemist_offer_concept, KEIN Zwischen-Container). Tenancy: Writes isOwnedBy.
 */
class AngebotPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant ein Angebot aus einem Brief in der Leitstelle: legt ein Angebot an (oder lädt via '
            . 'angebot_id ein bestehendes, team-eigenes), baut das Gerüst aus dem Brief und startet die '
            . 'Voll-Kaskade (owner_type=offer, eager): je Slot ein Concept, das ans Angebot referenziert wird. '
            . 'Liefert angebot_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Gäste, Saison, Niveau, Budget …).'],
                'angebot_id' => ['type' => 'integer', 'description' => 'Optional: ein bestehendes, team-eigenes Angebot bebriefen (sonst neu anlegen).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für ein neues Angebot (Default „Angebot aus Brief"). Ignoriert bei angebot_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken (whitelist-gefiltert). menue_*-Achsen (menue_gaenge, '
                        . 'menue_preis_*_pp, menue_quote_*, menue_balance) steuern die Menü-Zusammenstellung; die übrigen (level, '
                        . 'sektor, diaet_hart, frische_erlaubt[], bio_pref, aroma_kueche, ki_bilder, complete_coverage) erben in Gerichte/Basisrezepte.',
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
            $r = app(PlanungsEinstiegService::class)->angebotAusBrief($team, (string) ($arguments['brief'] ?? ''), [
                'owner_id' => $arguments['angebot_id'] ?? null,
                'titel' => $arguments['label'] ?? null,
                'creative_mode' => $arguments['creative_mode'] ?? null,
                'leitplanken' => $arguments['leitplanken'] ?? null,
                'created_via' => 'mcp_offer_brief',
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
            'angebot_id' => (int) $r['owner']->id,
            'session_id' => (int) $r['session']->id,
            'run' => $status ?? ['run_id' => (int) $r['run']->id, 'status' => (string) $r['run']->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'angebot', 'offer', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.speisekarte.PLAN_FROM_BRIEF', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Plane ein Angebot aus diesem Brief: Sommer-Gartenfest für 80 Gäste, 3-Gänge-Menü, gehoben.'],
        ];
    }
}
