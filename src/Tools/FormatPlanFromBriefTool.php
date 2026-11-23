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
 * MCP-Einstieg „Format aus Brief" — ein gebrandetes FOODKONZEPT (Chefs Corner / Taste & Fly / Lunchbuffet /
 * Dinner) in der Leitstelle planen. Legt ein Format an (oder lädt via format_id), baut aus dem Brief die
 * Marken-Identität (consumer_name/claim/story) + die eigenständigen Concepte/Veranstaltungen, die die Marke bündelt (Gerüst,
 * owner=format) und startet die Voll-Kaskade (eager, wie Angebot): je Slot ein Concept, das als Aufbau-Slot
 * (type=concept) ins Format referenziert wird ({@see FormatService::slotConceptEinfuegen}). Tenancy: Writes isOwnedBy.
 */
class FormatPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant ein gebrandetes Foodkonzept (Format: z. B. Streetfood-Konzept, Lunchbuffet, Flying-Dinner) '
            . 'aus einem Brief in der Leitstelle: legt ein Format an (oder lädt via format_id ein bestehendes, '
            . 'team-eigenes), leitet Marken-Identität (consumer_name/claim/story) + aufeinander abgestimmte '
            . 'eigenständige Concepte/Veranstaltungen (ein Tag / Event / eine Menü-Variante) aus dem Brief ab und startet '
            . 'die Voll-Kaskade (owner_type=format, eager): je Slot ein ganzes Concept, das ins Format referenziert wird '
            . '(die Stationen/Gänge baut der Conceptor im Concept). Branding wird nur bei NEU angelegten '
            . 'Formaten geschrieben (bestehende Identität bleibt unangetastet). Bildwelt bleibt manuell '
            . '(format_images.*). Liefert format_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing des Foodkonzepts (Idee/Marke, Anlass, Service-Format, Niveau, Zielgruppe, Preis …).'],
                'format_id' => ['type' => 'integer', 'description' => 'Optional: ein bestehendes, team-eigenes Format bebriefen (sonst neu anlegen). Bei bestehenden bleibt die Marken-Identität unangetastet.'],
                'label' => ['type' => 'string', 'description' => 'Optionaler interner Name für ein neues Format (Default „Format aus Brief"; wird vom Gerüst-Namen überschrieben, falls die KI einen liefert). Ignoriert bei format_id.'],
                'origin' => ['type' => 'string', 'enum' => ['eigen', 'gruppe', 'kunde'], 'description' => 'Herkunft/IP eines NEUEN Formats (Default eigen). Kunden-IP wird nie fremd wiederverwendet.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank/hybrid = Bestands-Concepts reusen wo sie passen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken (whitelist-gefiltert), z. B. level, sektor, diaet_hart, '
                        . 'frische_erlaubt[], bio_pref, aroma_kueche, menue_quote_*, ki_bilder, complete_coverage. Erben in die Concept-/Gericht-/Basisrezept-Ebene.',
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
            $r = app(PlanungsEinstiegService::class)->formatAusBrief($team, (string) ($arguments['brief'] ?? ''), [
                'owner_id' => $arguments['format_id'] ?? null,
                'titel' => $arguments['label'] ?? null,
                'creative_mode' => $arguments['creative_mode'] ?? null,
                'leitplanken' => $arguments['leitplanken'] ?? null,
                'origin' => $arguments['origin'] ?? null,
                'created_via' => 'mcp_format_brief',
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
            'format_id' => (int) $r['owner']->id,
            'neu_angelegt' => $r['neu'],
            'branding_gesetzt' => $r['branding_gesetzt'],
            'session_id' => (int) $r['session']->id,
            'run' => $status ?? ['run_id' => (int) $r['run']->id, 'status' => (string) $r['run']->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'branding'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.formats.GET', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Plane ein Format aus diesem Brief: „Taste & Fly" — ein Flying-Fingerfood-Konzept für Empfänge, verspielt-modern, 3 abgestimmte Stationen.'],
        ];
    }
}
