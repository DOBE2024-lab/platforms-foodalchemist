<?php

namespace Platform\FoodAlchemist\Services\GrammaturUmstellung;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Services\Rezept\RezeptGraphService;

/**
 * Plan 98 B · Obergrenze der Neuberechnung (Kurator 23.11.2026): Der Normalweg darf nur Zeilen der betroffenen Rezepte und ihrer
 * Verwender schreiben — Eltern-Rezepte, Konzepte (samt einbettender Konzepte und Pakete der Art Paket), Angebote; Caches (Team-Kosten, Aromenprofil)
 * auch für Unter-Rezepte. Grenzen werden VOR der Neuberechnung bestimmt; danach wird jede seit dem Phasenstart geschriebene Zeile
 * dagegen geprüft und je Tabelle und team_id gezählt.
 */
final class NeuberechnungGrenzen
{
    /** @var array<string, array<int, true>> tabelle → erlaubte Ids */
    private array $ids = [];

    /** @param  list<int>  $rezeptIds  umgestellte Rezepte */
    public function __construct(array $rezeptIds)
    {
        $graph = app(RezeptGraphService::class);
        $rezepte = array_map('intval', $graph->vorfahren($rezeptIds));
        $caches = array_values(array_unique([...$rezepte, ...array_map('intval', $graph->nachfahren($rezeptIds))]));
        $konzepte = $this->konzepte($rezepte);
        $angebote = DB::table('foodalchemist_concepts')->whereIn('id', $konzepte)->whereNotNull('offer_id')->pluck('offer_id')
            ->merge(DB::table('foodalchemist_offer_concept')->whereIn('concept_id', $konzepte)->pluck('offer_id'))
            ->map(fn ($i) => (int) $i)->unique()->values()->all();

        $this->ids = [
            'foodalchemist_recipes' => $rezepte,
            'foodalchemist_recipe_presentations' => DB::table('foodalchemist_recipe_presentations')->whereIn('recipe_id', $rezepte)
                ->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'foodalchemist_concepts' => $konzepte,
            'foodalchemist_offers' => $angebote,
            'cache_rezepte' => $caches,
        ];
        $this->ids = array_map(fn ($l) => array_fill_keys($l, true), $this->ids);
    }

    /**
     * Prüft alle seit `$seit` geschriebenen Zeilen gegen die Grenzen und zählt sie je Tabelle und team_id.
     *
     * @return array{verstoesse: list<string>, zaehlung: array<string, array<string, int>>}
     */
    public function pruefe(Carbon $seit): array
    {
        $verstoesse = [];
        $zaehlung = [];
        $zaehle = function (string $tabelle, $zeilen, string $idSpalte, string $grenze) use (&$verstoesse, &$zaehlung) {
            foreach ($zeilen as $z) {
                $zaehlung[$tabelle][(string) ($z->team_id ?? '–')] = ($zaehlung[$tabelle][(string) ($z->team_id ?? '–')] ?? 0) + 1;
                if (! isset($this->ids[$grenze][(int) $z->{$idSpalte}])) {
                    $verstoesse[] = "Neuberechnung schrieb {$tabelle} #{$z->{$idSpalte}} außerhalb der betroffenen Rezepte/Verwender";
                }
            }
        };

        foreach (['foodalchemist_recipes', 'foodalchemist_recipe_presentations', 'foodalchemist_concepts', 'foodalchemist_offers'] as $t) {
            $zaehle($t, DB::table($t)->where('updated_at', '>=', $seit)->get(['id', 'team_id']), 'id', $t);
        }
        $zaehle('foodalchemist_recipe_team_kosten', DB::table('foodalchemist_recipe_team_kosten')->where('updated_at', '>=', $seit)
            ->get(['recipe_id', 'team_id']), 'recipe_id', 'cache_rezepte');
        $zaehle('foodalchemist_recipe_profile', DB::table('foodalchemist_recipe_profile as p')->join('foodalchemist_recipes as r', 'r.id', '=', 'p.recipe_id')
            ->where('p.updated_at', '>=', $seit)->get(['p.recipe_id', 'r.team_id']), 'recipe_id', 'cache_rezepte');

        $grenzeAudit = ['presentation' => 'foodalchemist_recipe_presentations', 'concept' => 'foodalchemist_concepts', 'offer' => 'foodalchemist_offers'];
        foreach (DB::table('foodalchemist_price_change_audits')->where('created_at', '>=', $seit)->get(['entity_type', 'entity_id', 'team_id']) as $a) {
            $zaehlung['foodalchemist_price_change_audits'][(string) $a->team_id] = ($zaehlung['foodalchemist_price_change_audits'][(string) $a->team_id] ?? 0) + 1;
            $grenze = $grenzeAudit[$a->entity_type] ?? null;
            if ($grenze === null || ! isset($this->ids[$grenze][(int) $a->entity_id])) {
                $verstoesse[] = "Preis-Audit für {$a->entity_type} #{$a->entity_id} außerhalb der betroffenen Rezepte/Verwender";
            }
        }
        if (Schema::hasTable('activity_log_activities') && Schema::hasColumn('activity_log_activities', 'created_at')) {
            $zaehlung['activity_log_activities']['–'] = DB::table('activity_log_activities')->where('created_at', '>=', $seit)->count();
        }

        return ['verstoesse' => $verstoesse, 'zaehlung' => $zaehlung];
    }

    /** @return list<int> Konzepte mit Slots auf die Rezepte, dazu alle einbettenden Konzepte (wie PricingCascadeService) */
    private function konzepte(array $rezepte): array
    {
        $ids = DB::table('foodalchemist_concept_slots')->whereNull('deleted_at')
            ->whereIn('sales_recipe_id', $rezepte)
            ->distinct()->pluck('concept_id')->map(fn ($i) => (int) $i)->all();
        $gesehen = array_fill_keys($ids, true);
        for ($rand = $ids; $rand !== [];) {
            $rand = DB::table('foodalchemist_concept_slots')->whereNull('deleted_at')->whereIn('embedded_concept_id', $rand)
                ->distinct()->pluck('concept_id')->map(fn ($i) => (int) $i)->reject(fn ($i) => isset($gesehen[$i]))->values()->all();
            foreach ($rand as $i) {
                $gesehen[$i] = true;
            }
        }

        return array_keys($gesehen);
    }
}
