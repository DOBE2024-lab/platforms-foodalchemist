<?php

namespace Platform\FoodAlchemist\Services\Concepter;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Position\PositionGericht;
use Platform\FoodAlchemist\Services\Rezept\DarreichungResolver;
use Platform\FoodAlchemist\Support\AngebotFestpreise;

/**
 * Preis-Zeilen des Concept-Cockpits (Arbeitszeilen-Pfad; Fassungen rechnet ConceptPreisService) — aus
 * ConceptService::preisCockpit ausgelagert (CQ 2.6, Größen-Ratsche).
 *
 * VK-Lücke (CQ 2.6a/2.6b): Eine Position ohne berechenbaren VK (Gericht ohne VK oder ohne Portionsgewicht, Paket ohne
 * Preis, eingefrorener Festpreis ohne VK) bekommt `price` = null und `vk_fehlt` = true, das Cockpit `hat_vk_luecke`.
 * Die Summe bleibt die Summe der BEKANNTEN Preise — keine stille Änderung der Rechnung, aber auch keine 0 €, die wie
 * ein Preis aussieht. Der FA schätzt nie (vgl. x-fa::money: null = „Preis fehlt").
 * Ein eingebettetes Paket mit `price_incomplete` behält seinen (Teil-)Preis, die Zeile bekommt `vk_unvollstaendig` und das
 * Cockpit `hat_vk_luecke` (CQ price_incomplete).
 */
final class CockpitZeilen
{
    /**
     * @return array{zeilen: list<array<string, mixed>>, vk: float, ek: float, hat_stale: bool, hat_leer: bool, hat_ek_luecke: bool, hat_vk_luecke: bool}
     */
    public function rechne(FoodAlchemistConcept $concept, ?FoodAlchemistOutlet $outlet): array
    {
        $concept->loadMissing(['slots' => fn ($q) => $q->orderBy('position'),
            'slots.unit:id,slug,dimension,default_in_g',
            'slots.embeddedConcept:id,name,price_per_person_cache,ek_per_person_cache,price_incomplete',   // Kaskade: eingebettetes Paket
            'slots.dish:id,name,ek_total_eur,sales_unit_count,sales_quantity_per_unit_g,yield_kg,yield_pieces', 'slots.dish.standardPresentation']);

        $r = ['zeilen' => [], 'vk' => 0.0, 'ek' => 0.0, 'hat_stale' => false, 'hat_leer' => false, 'hat_ek_luecke' => false, 'hat_vk_luecke' => false];
        foreach ($concept->slots as $slot) {
            if (in_array($slot->type, ConceptService::STRUKTUR_TYPEN, true)) {
                continue; // Struktur-Blöcke (Text/Leerzeile/Header) sind keine Preis-Positionen
            }
            $basis = ['slot_id' => $slot->id, 'role' => $slot->role];
            if ($slot->embedded_concept_id !== null && $slot->embeddedConcept) {
                // Kaskade: eingebettetes Paket = kind=paket-Concept → lebender Preis/EK (spiegelt Edits).
                $ep = $slot->embeddedConcept;
                $zeile = $basis + ['type' => 'paket', 'wording' => $slot->wording, 'label' => $ep->name, 'stale' => false]
                    + $this->werte($ep->price_per_person_cache, $ep->ek_per_person_cache ?? 0, false);
                if ($ep->price_incomplete === true) {
                    $zeile['vk_unvollstaendig'] = true;   // CQ price_incomplete: Paket-Preis = Summe seiner bekannten Positionen, der Wert bleibt sichtbar
                }
                // Unbekanntes nie als 0 (B2), auch durch Pakete: fehlt einem Gericht des Pakets (rekursiv) der EK, ist der Paket-EK eine Teilsumme
                if (! app(ConcepterAggregateService::class)->conceptAggregat($ep)['ek_vollstaendig']) {
                    $zeile['ek_unvollstaendig'] = $r['hat_ek_luecke'] = true;
                }
            } elseif ($slot->sales_recipe_id !== null && $slot->dish) {
                $zeile = $basis + ['type' => 'gericht', 'wording' => $slot->wording, 'label' => $slot->dish->name, 'stale' => false]
                    + $this->gericht($concept, $slot, $outlet);
                $r['hat_ek_luecke'] = $r['hat_ek_luecke'] || $zeile['ek_fehlt'] || $zeile['ek_unbekannt'];
            } else {
                $r['hat_leer'] = true;
                $zeile = $basis + ['type' => 'leer', 'label' => $slot->title ?? '(leer)', 'price' => null, 'ek' => null, 'ek_fehlt' => false, 'vk_fehlt' => false, 'stale' => false];
            }
            $r['hat_vk_luecke'] = $r['hat_vk_luecke'] || $zeile['vk_fehlt'] || ($zeile['vk_unvollstaendig'] ?? false);
            $r['vk'] += (float) ($zeile['_vk_roh'] ?? $zeile['price'] ?? 0);   // ungerundet summieren wie bisher
            $r['ek'] += (float) ($zeile['_ek_roh'] ?? $zeile['ek'] ?? 0);
            unset($zeile['_vk_roh'], $zeile['_ek_roh']);
            $r['zeilen'][] = $zeile;
        }

        return $r;
    }

    /** Gericht-Slot: Darreichung auflösen, Menge umrechnen, VK/EK je Person. */
    private function gericht(FoodAlchemistConcept $concept, $slot, ?FoodAlchemistOutlet $outlet): array
    {
        // Umbau-Spec Phase 5: geltende Darreichung auflösen (explizit → Konzept-Form → Standard)
        $slot->setRelation('concept', $concept);
        $dar = app(DarreichungResolver::class)->fuerSlot($slot);
        $darPortionG = $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null;
        // Einheit-abhängige Mengen-Umrechnung — EINE Stelle (Konsistenz zu ConcepterAggregate/Paket).
        $pae = ConcepterAggregateService::portionsAequivalent($slot->quantity !== null ? (float) $slot->quantity : null, $slot->unit, $slot->dish, $darPortionG);
        $ekFehlt = $pae === null;       // Gramm-Position ohne Portionsgewicht → ehrlich „unbekannt"
        // 98 · 3.6 PR4: VK/EK je Portion über den Positions-Kern (wie Foodbook/Angebot); ohne Kosten-Team = Master-EK (B4, vorerst so).
        // Stück-Modus (kg↔Stück): EK je Stück aus yield_pieces. Paket-/Embedded-Caches bleiben Team-Baseline (kein Fan-out).
        $stueck = ConcepterAggregateService::stueckModus($slot->unit, $slot->dish);
        $p = app(PositionGericht::class)->jePortion($slot->dish, $dar, null, $outlet, $stueck);
        $vkStd = $p['vk'];   // null = kein VK (Lücke, nicht 0 €)
        if (($fp = app(AngebotFestpreise::class)->gericht((int) $slot->dish->id, $dar?->id)) !== null) {
            $vkStd = $fp['vk'];   // eingefrorenes Angebot: fester VK je Form, ohne VK = Lücke
            $ekFehlt = $ekFehlt || $fp['vk'] === null;
        }
        $ekUnbekannt = ! $ekFehlt && $p['ek'] === null;   // B2: Gericht ohne EK → Lücke markieren, nie still 0
        $ek = $ekFehlt ? 0.0 : (float) ($p['ek'] ?? 0) * $pae;
        $vkFehlt = $ekFehlt || $vkStd === null;

        $vk = $vkFehlt ? 0.0 : (float) $vkStd * $pae;

        return ['price' => $vkFehlt ? null : round($vk, 2), '_vk_roh' => $vk, 'vk_fehlt' => $vkFehlt,
            'ek' => ($ekFehlt || $ekUnbekannt) ? null : round($ek, 2), '_ek_roh' => $ek, 'ek_fehlt' => $ekFehlt, 'ek_unbekannt' => $ekUnbekannt];
    }

    /** Paket-Zeile: Preis null = Lücke; EK wie bisher (0, wenn unbekannt). */
    private function werte(mixed $vk, mixed $ek, bool $ekFehlt): array
    {
        return ['price' => $vk !== null ? (float) $vk : null, 'vk_fehlt' => $vk === null, 'ek' => round((float) $ek, 2), '_ek_roh' => (float) $ek, 'ek_fehlt' => $ekFehlt];
    }
}
