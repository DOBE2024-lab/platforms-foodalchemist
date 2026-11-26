<?php

namespace Platform\FoodAlchemist\Services\Position;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Concepter\ConceptPreisService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Rezept\KostenCacheService;
use Platform\FoodAlchemist\Services\Rezept\DarreichungResolver;
use Platform\FoodAlchemist\Services\Rezept\TeamVkService;
use Platform\FoodAlchemist\Support\AngebotFestpreise;

/**
 * 98 · 3.6 PR2 Positions-Kern: EIN Preis je Positionszeile für Foodbook-Block und Angebots-Block (Entscheid E4: Tabellen
 * getrennt, Code gemeinsam). Ersetzt die zwei Kopien in FoodbookAggregat::blockPreis und OfferCompositionService::blockPreis.
 *
 * Regeln (Kurator/Dominique):
 *  - P1 Preise aus dem Team: Gericht-VK über TeamVkService, Gericht-EK über KostenCacheService, Konzept über
 *    preisCockpit mit Team — im Foodbook wie im Angebot. Festpreise (eingefrorenes Angebot) nur im Angebot.
 *  - P2 Fehlt ein VK, ist die Zeile „vorläufig“ (vk_fehlt) statt still 0.
 *  - P3 Darreichung wirkt hier NICHT auf den Preis (Standard-Darreichung bzw. Preisleiter); nur das Konzept rechnet sie.
 *  - P7 Rundung je Zeile auf 2 Stellen.
 *  - P8 Gericht pauschal führt seinen EK mit (ek_pauschal) statt ihn fallen zu lassen.
 *
 * Ergebnis je Zeile: vk_pp, ek_pp (je Person) · pauschal, ek_pauschal (flach) · aufwand, ek_aufwand (zzgl., nicht in der
 * Summe) · vk_fehlt, ek_vollstaendig · optional eingefroren.
 */
final class PositionPreisService
{
    /** Konzept-Spalten, die preisCockpit braucht (Fassungspfad, Team, Konzept-Servierform, manueller Preis) — für Eager-Loads mit Spaltenauswahl. */
    public const KONZEPT_SPALTEN = 'id,name,price_per_person_cache,price_incomplete,team_id,ist_fassung,serving_form_id,price_mode,price_per_person_manual,price_override_expires_at';

    public function __construct(
        private ConceptService $concepts,
        private AngebotFestpreise $festpreise,
    ) {}

    /**
     * @param  ?array  $festpreise  nur Angebot: Festpreise der Einfrierung des Kapitels ({@see AngebotFestpreise::fuerKapitel})
     * @param  bool  $mitFestpreisen  nur Angebot: Festpreise gelten (ohne eigene Liste die gerade aktive, {@see AngebotFestpreise::mit})
     * @return array{vk_pp: float, ek_pp: float, pauschal: float, ek_pauschal: float, aufwand: float, ek_aufwand: float, vk_fehlt: bool, ek_vollstaendig: bool}
     */
    public function zeile(Model $zeile, ?Team $team, ?FoodAlchemistOutlet $outlet = null, ?int $pax = null, ?array $festpreise = null, bool $mitFestpreisen = false): array
    {
        $typ = (string) $zeile->type;
        if ($typ === 'concept_ref' && $zeile->concept) {
            $cockpit = $this->festpreise->mit($festpreise, fn () => $this->concepts->preisCockpit($zeile->concept, $outlet, $team));

            return $this->ergebnis(vk: (float) $cockpit['price_per_person'], ek: (float) $cockpit['ek_per_person'],
                vkFehlt: ConceptPreisService::unvollstaendig($cockpit));
        }
        if ($typ === 'recipe_ref' && $zeile->dish) {
            return $this->gericht($zeile, $zeile->dish, $team, $outlet, $festpreise, $mitFestpreisen);
        }
        if ($typ === 'position' && $zeile instanceof FoodAlchemistOfferBlock) {   // Spec 94b: Menge × Satz, EK = Menge × EK je Einheit
            $menge = (float) ($zeile->quantity ?? 0);
            $vk = $zeile->positionBetrag();   // Spec 94c 8c: Verbrauch mit Deckel höchstens der Deckel
            $voll = round($menge * (float) ($zeile->price_value ?? 0), 2);
            $ek = round($menge * (float) ($zeile->ek_value ?? 0) * ($voll > 0 ? $vk / $voll : 1.0), 2);
            $aufwand = $zeile->istAufwand();

            return $this->ergebnis(pauschal: $aufwand ? 0.0 : $vk, ekPauschal: $aufwand ? 0.0 : $ek, aufwand: $aufwand ? $vk : 0.0,
                ekAufwand: $aufwand ? $ek : 0.0, ekVoll: $zeile->ek_value !== null);
        }
        if (in_array($typ, ['header_preis', 'header_frei_preis'], true)) {   // Preis-Kopf: person · pauschal · staffel (nur Foodbook)
            $wert = (float) ($zeile->price_value ?? 0);

            return match ($zeile->price_basis) {
                'pauschal' => $this->ergebnis(pauschal: $wert),
                'staffel' => $this->ergebnis(vk: $zeile instanceof FoodAlchemistFoodbookBlock ? $this->staffel($zeile, $pax) : 0.0),
                default => $this->ergebnis(vk: $wert),
            };
        }

        return $this->ergebnis();
    }

    /**
     * 98 · 3.6 PR3 — gemeinsame Kapitel-Summe der Blöcke (Foodbook wie Angebot). Wahlgruppen (variant_group_id, P4): je
     * Gruppe zählt die TEUERSTE Alternative (nach VK je Person, dann pauschal); dazu die Spanne von (günstigste je Gruppe)
     * bis (teuerste je Gruppe) für den VK je Person. Eine Gruppe mit vorläufigem Mitglied macht die Summe vorläufig.
     *
     * @param  iterable<array{gruppe: ?int, preis: array}>  $positionen
     * @return array{vk: float, ek: float, pauschal: float, ek_pauschal: float, aufwand: float, ek_aufwand: float, vk_fehlt: bool, ek_vollstaendig: bool, wahl_spanne: ?array{von: float, bis: float}, wahl_gruppen: array<int, array{von: float, bis: float}>}
     */
    public function summe(iterable $positionen): array
    {
        $einzeln = [];
        $gruppen = [];
        foreach ($positionen as $p) {
            $p['gruppe'] !== null ? ($gruppen[(int) $p['gruppe']][] = $p['preis']) : ($einzeln[] = $p['preis']);
        }
        $s = ['vk' => 0.0, 'ek' => 0.0, 'pauschal' => 0.0, 'ek_pauschal' => 0.0, 'aufwand' => 0.0, 'ek_aufwand' => 0.0, 'vk_fehlt' => false, 'ek_vollstaendig' => true];
        $plus = function (array $pr) use (&$s): void {
            $s['vk'] += $pr['vk_pp'];
            $s['ek'] += $pr['ek_pp'];
            $s['pauschal'] += $pr['pauschal'];
            $s['ek_pauschal'] += $pr['ek_pauschal'] ?? 0.0;
            $s['aufwand'] += $pr['aufwand'] ?? 0.0;
            $s['ek_aufwand'] += $pr['ek_aufwand'] ?? 0.0;
            $s['vk_fehlt'] = $s['vk_fehlt'] || ($pr['vk_fehlt'] ?? false);
            $s['ek_vollstaendig'] = $s['ek_vollstaendig'] && ($pr['ek_vollstaendig'] ?? true);
        };
        foreach ($einzeln as $pr) {
            $plus($pr);
        }
        $basis = $s['vk'];
        [$von, $bis] = [0.0, 0.0];
        $jeGruppe = [];
        foreach ($gruppen as $gid => $alternativen) {
            usort($alternativen, fn ($a, $b) => [$b['vk_pp'], $b['pauschal']] <=> [$a['vk_pp'], $a['pauschal']]);
            $plus($alternativen[0]);   // teuerste zählt
            $s['vk_fehlt'] = $s['vk_fehlt'] || collect($alternativen)->contains(fn ($a) => $a['vk_fehlt'] ?? false);
            $von += end($alternativen)['vk_pp'];
            $bis += $alternativen[0]['vk_pp'];
            $jeGruppe[$gid] = ['von' => round(end($alternativen)['vk_pp'], 2), 'bis' => round($alternativen[0]['vk_pp'], 2)];
        }
        $s['wahl_spanne'] = $gruppen === [] ? null : ['von' => round($basis + $von, 2), 'bis' => round($basis + $bis, 2)];
        $s['wahl_gruppen'] = $jeGruppe;   // je Gruppe von–bis (Anzeige an der Wahlgruppe im Editor)

        return $s;
    }

    /** Staffel-Auflösung: höchste Stufe mit min_persons ≤ Pax (ohne Pax die niedrigste). */
    public function staffel(FoodAlchemistFoodbookBlock $block, ?int $pax): float
    {
        $stufen = $block->relationLoaded('staffel') ? $block->staffel : $block->staffel()->get();
        if ($stufen->isEmpty()) {
            return 0.0;
        }
        if ($pax === null) {
            return (float) $stufen->sortBy('min_persons')->first()->price;
        }
        $treffer = $stufen->where('min_persons', '<=', $pax)->sortByDesc('min_persons')->first();

        return (float) ($treffer?->price ?? $stufen->sortBy('min_persons')->first()->price);
    }

    /** Gericht-Zeile: Festpreis (nur Angebot) → Team-VK/-EK → Preisleiter ohne Team. */
    private function gericht(Model $zeile, FoodAlchemistRecipe $dish, ?Team $team, ?FoodAlchemistOutlet $outlet, ?array $festpreise, bool $mitFestpreisen): array
    {
        $faktor = $zeile->quantity !== null ? (float) $zeile->quantity : 1.0;
        $pauschal = $zeile->price_basis === 'pauschal';
        $portionen = max(1, (int) ($dish->sales_unit_count ?? 1));

        if ($mitFestpreisen && ($fp = $this->festpreise->gericht((int) $dish->id, null, $festpreise)) !== null) {
            // eingefroren: fester VK (fehlt er, weil der EK beim Einfrieren unvollständig war, bleibt er eine sichtbare Lücke)
            $vk = round((float) ($fp['vk'] ?? 0) * $faktor, 2);
            $ek = round((float) ($fp['ek_summe'] ?? 0) / $portionen * $faktor, 2);

            return $this->ergebnis(vk: $pauschal ? 0.0 : $vk, ek: $pauschal ? 0.0 : $ek, pauschal: $pauschal ? $vk : 0.0, ekPauschal: $pauschal ? $ek : 0.0,
                vkFehlt: $fp['vk'] === null, ekVoll: (bool) $fp['vollstaendig']) + ['eingefroren' => true];
        }
        if ($team !== null) {
            // Spec 82 §12 C: VK aus Sicht des Teams (Abo-Fassung: Team-EK × eigener Kalkulationsfaktor; sonst Preis-Leiter)
            $vkRoh = app(TeamVkService::class)->fuer($dish, $team, $outlet)['vk'];
            $k = app(KostenCacheService::class)->fuer($dish, $team);   // Spec 85: Team-EK des Ansatzes ÷ Portionen = EK je Portion
            $ekRoh = $k['ek_summe'] !== null ? (float) $k['ek_summe'] / $portionen : null;
            $ekVoll = $k['vollstaendig'] && $k['ek_summe'] !== null;
        } else {
            $resolver = app(DarreichungResolver::class);   // Spec 85 D-1: Preisleiter (Standard-Darreichung); im Betrieb dessen VK
            $vkRoh = ($outlet !== null ? $resolver->vkNetto($dish, $outlet) : null) ?? $resolver->vkNetto($dish);
            $ekRoh = $resolver->ekProPortion($dish);
            $ekVoll = $dish->ek_total_eur !== null;
        }
        $vk = round((float) ($vkRoh ?? 0) * $faktor, 2);
        $ek = round((float) ($ekRoh ?? 0) * $faktor, 2);

        return $this->ergebnis(vk: $pauschal ? 0.0 : $vk, ek: $pauschal ? 0.0 : $ek, pauschal: $pauschal ? $vk : 0.0, ekPauschal: $pauschal ? $ek : 0.0,
            vkFehlt: $vkRoh === null, ekVoll: $ekVoll);
    }

    private function ergebnis(float $vk = 0.0, float $ek = 0.0, float $pauschal = 0.0, float $ekPauschal = 0.0, float $aufwand = 0.0,
        float $ekAufwand = 0.0, bool $vkFehlt = false, bool $ekVoll = true): array
    {
        return ['vk_pp' => round($vk, 2), 'ek_pp' => round($ek, 2), 'pauschal' => round($pauschal, 2), 'ek_pauschal' => round($ekPauschal, 2),
            'aufwand' => round($aufwand, 2), 'ek_aufwand' => round($ekAufwand, 2), 'vk_fehlt' => $vkFehlt, 'ek_vollstaendig' => $ekVoll];
    }
}
