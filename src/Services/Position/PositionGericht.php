<?php

namespace Platform\FoodAlchemist\Services\Position;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Preis\CatalogPricingService;
use Platform\FoodAlchemist\Services\Rezept\DarreichungResolver;
use Platform\FoodAlchemist\Services\Rezept\KostenCacheService;
use Platform\FoodAlchemist\Services\Rezept\TeamVkService;

/**
 * 98 · 3.6 PR4: die Gericht-Zeile je Portion für alle drei Zeilenarten — Konzept-Slot ({@see \Platform\FoodAlchemist\Services\Concepter\CockpitZeilen}),
 * Foodbook- und Angebots-Block ({@see PositionPreisService}). VK und EK der geltenden Darreichung: im Slot die aufgelöste
 * (explizit → Konzept-Form → Standard), im Block die Standard-Darreichung (P3: Darreichung preiswirksam nur im Konzept).
 *
 * B1 (Dominique 26.11.): ein eigenes Gericht bringt den EK je Portion seiner Darreichung mit (`ek_portion`, inkl. Beigaben),
 * sonst Ansatz ÷ Portionen. Mit Kosten-Team und fremdem Gericht gelten die Team-Kosten (P1). Der Slot übergibt kein
 * Kosten-Team und bleibt damit beim Master-EK (B4, vorerst so). Menge, Preisbasis und Festpreise bleiben beim Aufrufer.
 * Ohne Concepter-Abhängigkeit, damit der Slot ihn rufen kann, ohne einen Kreis über ConceptService zu schließen.
 */
final class PositionGericht
{
    /**
     * @param  bool  $stueck  Stück-Modus des Slots (kg↔Stück): EK je Stück aus dem Ansatz ÷ yield_pieces, nie ek_portion
     * @return array{vk: ?float, ek: ?float, ek_vollstaendig: bool} je Portion (Stück-Modus: je Stück); null = unbekannt, nie 0
     */
    public function jePortion(FoodAlchemistRecipe $dish, ?FoodAlchemistRecipeDarreichung $dar, ?Team $kostenTeam, ?FoodAlchemistOutlet $outlet, bool $stueck = false): array
    {
        if ($kostenTeam !== null) {
            // Spec 82 §12 C: VK aus Sicht des Teams (Abo-Fassung: Team-EK × eigener Kalkulationsfaktor; sonst Preis-Leiter)
            $vk = app(TeamVkService::class)->fuer($dish, $kostenTeam, $outlet, $dar)['vk'];
            $k = app(KostenCacheService::class)->fuer($dish, $kostenTeam);   // Spec 85: Team-EK des Ansatzes ÷ Portionen
            $ek = $k['quelle'] === 'rezept' ? app(DarreichungResolver::class)->ekProPortion($dish, $dar)   // eigenes Gericht: B1
                : ($k['ek_summe'] !== null ? (float) $k['ek_summe'] / app(DarreichungResolver::class)->portionenJeAnsatz($dish) : null);

            return ['vk' => $vk, 'ek' => $ek, 'ek_vollstaendig' => $k['vollstaendig'] && $k['ek_summe'] !== null];
        }

        // Ebene 2: im Betrieb der VK der Darreichung gegen dessen Kostenstruktur, sonst ihr Baseline-VK; danach die Preis-Leiter (Spec 85 D-1)
        $vk = $dar === null ? null
            : ($outlet !== null ? app(CatalogPricingService::class)->salesNetFor(Team::find($outlet->team_id), $dar, $outlet)
                : ($dar->sales_net !== null ? (float) $dar->sales_net : null));
        $vk ??= app(DarreichungResolver::class)->vkNetto($dish, $outlet);
        $ek = $stueck
            ? ($dish->ek_total_eur !== null ? (float) $dish->ek_total_eur / (float) $dish->yield_pieces : null)
            : app(DarreichungResolver::class)->ekProPortion($dish, $dar);

        return ['vk' => $vk, 'ek' => $ek, 'ek_vollstaendig' => $ek !== null];
    }
}
