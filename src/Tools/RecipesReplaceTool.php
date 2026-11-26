<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Gericht\GerichtTauschService;
use Platform\FoodAlchemist\Services\Gericht\GerichtVerwendungen;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;

/**
 * MCP-Steuerbarkeit · D2a: Rezept in ALLEN eigenen Verwendungen durch ein anderes ersetzen —
 * das Pendant zu `gps.REPLACE` (Web: Verwaltungs-Block in Rezept-Panel + Rezept-Editor).
 *
 * Geschrieben werden ausschließlich Zutat-Zeilen in Rezepten des eigenen Teams; geerbte
 * Master-/Seed-Eltern bleiben unberührt und werden gezählt gemeldet. `from` darf deshalb
 * bewusst auch ein geerbtes Rezept sein („die geerbte Komponente in MEINEN Gerichten
 * ersetzen") — das from-Rezept selbst wird dabei nie verändert.
 *
 * Hohe Reichweite (Referenzen umhängen + Neuberechnung je betroffenem Eltern-Rezept
 * inkl. Propagation) → confirm=true Pflicht.
 */
class RecipesReplaceTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.REPLACE';
    }

    public function getDescription(): string
    {
        return 'Ersetzt ein Rezept in allen eigenen Verwendungen als Komponente (from → to) und rechnet die '
            . 'betroffenen Eltern-Rezepte neu. Menge/Einheit der Zeilen bleiben. Geerbte Eltern bleiben '
            . 'unberührt; Zyklen werden abgewiesen. Reichweitenstark → confirm=true Pflicht. '
            . 'Für GERICHTE zusätzlich: bereiche (konzepte, komponenten, speiseplan, speisekarte, angebote, foodbooks, ersatz) '
            . 'ersetzt in allen aktiven Verwendungen (Speiseplan ab heute, gültige Karten, offene auto-Angebote, Foodbook-Entwürfe; '
            . 'aktive Foodbooks nur mit auch_aktive_foodbooks=true); preview=true liefert die Vorschau ohne zu schreiben (ohne confirm).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from_id' => ['type' => 'integer', 'description' => 'Zu ersetzendes Rezept (sichtbar; darf geerbt sein).'],
                'to_id' => ['type' => 'integer', 'description' => 'Ziel-Rezept (sichtbar, nicht „deprecated").'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (reichweitenstarke Aktion; nicht nötig bei preview=true).'],
                'bereiche' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => GerichtVerwendungen::TAUSCH_BEREICHE],
                    'description' => 'Nur für Gerichte: Bereiche des Gericht-Tauschs (Gericht-Verwaltung). Ohne Angabe: wie bisher nur Komponenten-Zeilen.'],
                'preview' => ['type' => 'boolean', 'description' => 'Nur für Gerichte: Vorschau je Bereich (Anzahl, Orte, bleibt-Gründe, Angebotspreis vorher/nachher). Schreibt nichts.'],
                'auch_aktive_foodbooks' => ['type' => 'boolean', 'description' => 'Nur für Gerichte: auch aktive/live Foodbooks tauschen (Live-Inhalt beim Kunden ändert sich). Standard false.'],
            ],
            'required' => ['from_id', 'to_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $von = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['from_id'] ?? 0))->first();
        if ($von === null) {
            return ToolResult::error('from-Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $nach = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['to_id'] ?? 0))->first();
        if ($nach === null) {
            return ToolResult::error('to-Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        // Gericht-Verwaltung (23.11.2026): Vorschau bzw. Tausch über alle Ausgaben — derselbe Dienst wie das Web-Panel.
        $gerichtWeg = ($arguments['preview'] ?? false) === true || array_key_exists('bereiche', $arguments);
        if ($gerichtWeg) {
            if (! $von->is_sales_recipe) {
                return ToolResult::error('bereiche/preview gelten nur für Gerichte (from ist ein Basisrezept).', 'VALIDATION_ERROR');
            }
            $tausch = app(GerichtTauschService::class);
            $aktiveFb = ($arguments['auch_aktive_foodbooks'] ?? false) === true;
            try {
                if (($arguments['preview'] ?? false) === true) {
                    return ToolResult::success($tausch->vorschau($team, (int) $von->id, (int) $nach->id, $aktiveFb));
                }
                if (($arguments['confirm'] ?? false) !== true) {
                    return ToolResult::error('Ersetzen erfordert confirm=true (reichweitenstarke Aktion).', 'CONFIRM_REQUIRED');
                }
                $bereiche = array_values(array_map('strval', (array) ($arguments['bereiche'] ?? [])));

                return ToolResult::success(['from_id' => (int) $von->id, 'to_id' => (int) $nach->id]
                    + $tausch->ersetze($team, (int) $von->id, (int) $nach->id, $bereiche, $aktiveFb)
                    + ['loeschen' => app(GerichtVerwendungen::class)->loeschBilanz($team, (int) $von->id)]);
            } catch (\RuntimeException $e) {
                return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
            }
        }

        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Ersetzen erfordert confirm=true (reichweitenstarke Aktion).', 'CONFIRM_REQUIRED');
        }

        try {
            $res = app(RecipeService::class)->ersetzeInVerwendungen($team, (int) $von->id, (int) $nach->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'from_id' => (int) $von->id,
            'to_id' => (int) $nach->id,
            'zeilen' => (int) $res['zeilen'],
            'rezepte' => (int) $res['rezepte'],
            'geerbt_unberuehrt' => (int) $res['fremd_rezepte'],
            'uebersprungen_zyklus' => $res['zyklus'],
            'ziel_war_schon_drin' => $res['doppelt'],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'ersetzen', 'destructive', 'recompute', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.DELETE', 'foodalchemist.gps.REPLACE'],
            'examples' => ['Ersetze Rezept 12 durch Rezept 34 in allen eigenen Gerichten (confirm=true).',
                'Gericht 7 durch Gericht 9 ersetzen: erst preview=true, dann bereiche=[konzepte, speiseplan, angebote] mit confirm=true.'],
        ];
    }
}
