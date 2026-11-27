<?php

namespace Platform\FoodAlchemist\Services\Ausgabe;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;

/**
 * Board-Daten des Angebots-Editors (98 · 3.3, aus Angebote\Editor::kapitelBoardDaten). Foodbook nutzt
 * LeitstelleService::kapitelBoard (foodbook-typisiert); fürs Angebot entsteht das Board offer-nativ aus der
 * bereits berechneten Komposition.
 */
class AngebotKapitelBoard
{
    /**
     * Board-Zeilen je Kapitel in der Foodbook-Board-Shape (die das geforkte Blade erwartet):
     * kapitel_id/titel/is_struktur/pricing_mode/hat_ziele/positionen_count/bepreist/hat_inhalt/
     * positionen[]/aggregat{vk_pro_person,ek_per_person,pauschal}/wareneinsatz{status,ist_pct,ziel_pct}.
     * Quelle = die bereits berechnete Komposition (intern) + die Kapitel-Modelle (fortschritt/is_struktur/Ziele).
     */
    public function daten(Team $team, FoodAlchemistAngebot $angebot, ?array $komposition, float $zielWePct): array
    {
        $modelle = FoodAlchemistOfferChapter::visibleToTeam($team)
            ->where('offer_id', $angebot->id)->get()->keyBy('id');
        $ampel = app(\Platform\FoodAlchemist\Services\Preis\MargeService::class);
        $board = [];
        foreach (($komposition['kapitel'] ?? []) as $kap) {
            $k = $modelle->get($kap['id']);
            $positionen = [];
            if ($kap['ist_format']) {
                foreach ($kap['editionen'] as $ed) {
                    if (($ed['typ'] ?? 'concept') !== 'concept') {
                        continue;
                    }
                    $vk = (float) ($ed['preis_pp'] ?? 0);
                    $ek = (float) ($ed['ek_pp'] ?? 0);
                    $positionen[] = ['art' => 'paket', 'label' => (string) ($ed['name'] ?? ''), 'ek' => $ek, 'vk' => $vk,
                        'preis_einheit' => 'gast', 'we_pct' => $ek > 0 ? \Platform\FoodAlchemist\Services\Preis\MargeService::wareneinsatzPct($ek, $vk) : null];
                }
            } else {
                foreach ($kap['bloecke'] as $b) {
                    if (($b['ist_header'] ?? false) || in_array($b['type'] ?? '', ['header', 'header_preis', 'text', 'spacer'], true)) {
                        continue;
                    }
                    $vk = (float) ($b['preis_pp'] ?? 0);
                    $ek = (float) ($b['ek_pp'] ?? 0);
                    $positionen[] = ['art' => ($b['type'] ?? '') === 'recipe_ref' ? 'einzel' : 'paket',
                        'label' => (string) ($b['label'] ?? ''), 'ek' => $ek, 'vk' => $vk,
                        'preis_einheit' => $b['preis_einheit'] ?? 'gast', 'we_pct' => $ek > 0 ? \Platform\FoodAlchemist\Services\Preis\MargeService::wareneinsatzPct($ek, $vk) : null];
                }
            }
            $vkPP = $kap['vk_pro_person'];
            $ekPP = (float) ($kap['ek_pro_person'] ?? 0);
            $fcPct = $kap['food_cost_percent'] ?? ($ekPP > 0 ? \Platform\FoodAlchemist\Services\Preis\MargeService::wareneinsatzPct($ekPP, $vkPP) : null);
            $board[] = [
                'kapitel_id' => (int) $kap['id'],
                'titel' => (string) ($kap['title_intern'] ?? $kap['title'] ?? ''),
                'parent_id' => $kap['parent_id'],
                'depth' => (int) ($kap['depth'] ?? 0),
                'fortschritt' => $k?->fortschritt ?? 'offen',
                'is_struktur' => (bool) ($k?->is_struktur ?? false),
                'pricing_mode' => $kap['price_mode'] ?? null,
                'hat_ziele' => $k !== null && ($k->target_count !== null || $k->price_anchor !== null),
                'positionen' => $positionen,
                'positionen_count' => count($positionen),
                'hat_inhalt' => count($positionen) > 0,
                'bepreist' => ($vkPP ?? 0) > 0,
                'aggregat' => ['vk_pro_person' => $vkPP, 'ek_per_person' => $ekPP, 'pauschal' => (float) ($kap['pauschal'] ?? 0), 'wahl_spanne' => $kap['wahl_spanne'] ?? null, 'wahl_gruppen' => $kap['wahl_gruppen'] ?? []],   // 98 · 3.6 PR3
                'wareneinsatz' => ['status' => $ampel->weAmpel($fcPct, $zielWePct), 'ist_pct' => $fcPct, 'ziel_pct' => $zielWePct],
            ];
        }

        return $board;
    }
}
