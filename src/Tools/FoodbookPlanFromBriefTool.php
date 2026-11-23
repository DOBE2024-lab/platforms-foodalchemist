<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Planung\PlanungsEinstiegService;

/**
 * Spec 42 (F5) — MCP-Einstieg „Foodbook aus Brief". Plant ein ganzes Foodbook aus einem Brief in der
 * Leitstelle: der Rahmen (Gerüst/Struktur) entsteht in der Leitstelle, das Foodbook ist reine Ausgabe.
 * Spiegelt die Livewire-Aktion {@see \Platform\FoodAlchemist\Livewire\Planung\Index::foodbookAusBrief}:
 * Foodbook anlegen (oder via foodbook_id laden) → Gerüst aus Brief → Struktur anwenden → Voll-Kaskade
 * (owner_type=foodbook, gestufte Freigabe). Das Ergebnis dockt automatisch als Kapitel/Blöcke ins
 * Foodbook (attachToOutput). Dies ist der legitime vollkaskade-MCP-Weg — er liefert den Ausgabe-Owner
 * (foodbook), den {@see PlanungKaskadeStartPostTool} bewusst nicht kennt. Tenancy: Writes isOwnedBy.
 */
class FoodbookPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant ein ganzes Foodbook aus einem Brief in der Leitstelle: legt ein Foodbook an (oder '
            . 'lädt via foodbook_id ein bestehendes, team-eigenes), baut das Gerüst aus dem Brief, wendet '
            . 'die Struktur an und startet die Voll-Kaskade (owner_type=foodbook, gestufte Freigabe je '
            . 'Ebene). Das Ergebnis dockt automatisch als Kapitel/Blöcke ins Foodbook. Liefert '
            . 'foodbook_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Gäste, Saison, Niveau, Budget …).'],
                'foodbook_id' => ['type' => 'integer', 'description' => 'Optional: ein bestehendes, team-eigenes Foodbook bebriefen (sonst wird ein neues angelegt).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für ein neues Foodbook (Default „Foodbook aus Brief"). Ignoriert bei foodbook_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken für die ganze Kaskade (whitelist-gefiltert). Die menue_*-'
                        . 'Achsen (menue_gaenge, menue_preis_min_pp/ziel_pp/max_pp, menue_quote_vegan_pct, menue_quote_vegetarisch_pct, '
                        . 'menue_balance) steuern die Menü-/Kapitel-Komposition; die übrigen (level, sektor, diaet_hart, allergen_nogo[], '
                        . 'frische_erlaubt[], bio_pref, aroma_kueche, saison, ki_bilder, complete_coverage, …) erben in die Gerichte/'
                        . 'Basisrezepte. Per planung_kaskade.GET als „leitplanken" prüfbar.',
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
            $r = app(PlanungsEinstiegService::class)->foodbookAusBrief($team, (string) ($arguments['brief'] ?? ''), [
                'owner_id' => $arguments['foodbook_id'] ?? null,
                'titel' => $arguments['label'] ?? null,
                'creative_mode' => $arguments['creative_mode'] ?? null,
                'leitplanken' => $arguments['leitplanken'] ?? null,
                'created_via' => 'mcp_foodbook_brief',
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
            'foodbook_id' => (int) $r['owner']->id,
            'foodbook_label' => (string) $r['owner']->label,
            'session_id' => (int) $r['session']->id,
            'run' => $status ?? ['run_id' => (int) $r['run']->id, 'status' => (string) $r['run']->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'foodbook', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.planung_kaskade.START', 'foodalchemist.planung_kaskade.GET', 'foodalchemist.foodbooks.POST'],
            'examples' => ['Plane ein Foodbook aus diesem Brief: Sommer-Galadinner für 80 Gäste, gehoben.', 'Foodbook 42 aus Brief bebriefen'],
        ];
    }
}
