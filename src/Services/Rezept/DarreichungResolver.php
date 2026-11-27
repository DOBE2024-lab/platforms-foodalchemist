<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;

/**
 * Löst auf, WELCHE Darreichung eines Gerichts in einem Kontext gilt
 * (Umbau-Spec Darreichungen §2). Auflösungsreihenfolge:
 *
 *   1. explizit am Slot/Paket-Gericht gesetzte Darreichung
 *   2. (Phase 4, vorbereitet) Darreichung passend zur Servierform des Konzepts
 *   3. Standard-Darreichung des Gerichts
 *
 * Stateless. Preis-Wahrheit liegt an der Darreichung; die Rezept-Spalten sales_net/markup_class_id gibt es
 * seit Spec 85 D-1 PR-C nicht mehr.
 */
class DarreichungResolver
{
    public function fuerSlot(FoodAlchemistConceptSlot $slot): ?FoodAlchemistRecipeDarreichung
    {
        // B7 (98 · 3.6 PR6): nur eine Darreichung DIESES Gerichts — eine fremde (Altbestand nach Gerichtswechsel) zählt nicht
        if ($slot->presentation_id !== null && $slot->presentation !== null && (int) $slot->presentation->recipe_id === (int) $slot->recipe_id) {
            return $slot->presentation;
        }

        if ($slot->dish === null) {
            return null;
        }

        // Phase 4: Servierform des Konzepts → passende Darreichung des Gerichts
        $konzeptServierformId = $slot->concept?->serving_form_id;
        if ($konzeptServierformId !== null) {
            // Vorgeladene Formen (Concepter-Aufbau, 2.1e) ohne Abfrage — gleiche Reihenfolge: Standard zuerst, dann id
            $passend = $slot->dish->relationLoaded('presentations')
                ? $slot->dish->presentations->where('serving_form_id', $konzeptServierformId)->sortBy([['is_standard', 'desc'], ['id', 'asc']])->first()
                : $slot->dish->presentations()->where('serving_form_id', $konzeptServierformId)->orderByDesc('is_standard')->orderBy('id')->first();
            if ($passend !== null) {
                return $passend;
            }
        }

        return $this->standardFuer($slot->dish);
    }

    /**
     * Darreichung eines `recipe_ref`-Foodbook-Blocks (Spec 19, M5/E7.1 — Einzel-Gericht-Pfad).
     * Auflösung analog {@see fuerSlot()}:
     *   1. block.presentation_id (expliziter Override) →
     *   2. Gericht-Darreichung zur übergebenen Servierform (Kapitel-/Foodbook-Servierform,
     *      vom Aufrufer aus der Dimensions-Kaskade gereicht) →
     *   3. standardFuer().
     *
     * `$servingFormId === null` UND keine block-Darreichung ⇒ standardFuer() (der VK des Gerichts).
     * dish === null ⇒ null.
     */
    public function fuerBlock(FoodAlchemistFoodbookBlock $block, ?int $servingFormId = null): ?FoodAlchemistRecipeDarreichung
    {
        if ($block->presentation_id !== null && $block->presentation !== null) {
            return $block->presentation;
        }

        if ($block->dish === null) {
            return null;
        }

        if ($servingFormId !== null) {
            $passend = $block->dish->presentations()
                ->where('serving_form_id', $servingFormId)
                ->orderByDesc('is_standard')->orderBy('id')->first();
            if ($passend !== null) {
                return $passend;
            }
        }

        return $this->standardFuer($block->dish);
    }

    /**
     * Darreichung eines Speiseplan-Eintrags: explizit gesetzte Form (nur wenn sie noch zum
     * Gericht des Eintrags gehört) → Standard-Darreichung. Concept-/Paket-Einträge ⇒ null.
     */
    public function fuerSpeiseplanEintrag(FoodAlchemistSpeiseplanEintrag $e): ?FoodAlchemistRecipeDarreichung
    {
        if ($e->recipe_id === null || $e->dish === null) {
            return null;
        }
        if ($e->presentation_id !== null && $e->presentation !== null
            && (int) $e->presentation->recipe_id === (int) $e->recipe_id) {
            return $e->presentation;
        }

        return $this->standardFuer($e->dish);
    }

    public function standardFuer(FoodAlchemistRecipe $recipe): ?FoodAlchemistRecipeDarreichung
    {
        return $recipe->standardPresentation ?? $recipe->presentations()->orderBy('id')->first();
    }

    /**
     * VK netto EINES GERICHTS ohne Kontext — die Preis-Leiter selbst: Standard-Darreichung
     * (M2-Wahrheit) → keine Zahl (die Rezept-Altspalte gibt es seit Spec 85 D-1 PR-C nicht mehr). Gibt die Quelle mit
     * aus, damit ein Aufrufer sie benennen kann statt sie zu erraten.
     *
     * **Warum hier und nicht beim Aufrufer (V-046):** dieselbe Leiter lag verstreut in
     * `KalkulationService::recipeHk`, im Wirtschafts-Block des Kandidaten-Pools und (in halber
     * Form) direkt als Rezept-Spalte im Pool-Filter. Solange der harte Preisfilter die eine
     * Zahl liest und die Zielfunktion die andere, ist ein Optimum nicht erklärbar.
     *
     * **Batch-fähig ohne zweiten Weg:** ist `standardPresentation` bereits eager geladen, wird
     * die geladene Relation genommen (keine Query je Gericht); sonst läuft die volle Leiter über
     * {@see standardFuer()} inklusive dessen Fallback „erste Darreichung nach id".
     *
     * ⚠️ **Bekannte Rest-Divergenz (V-059, NICHT in diesem Zug behoben):** genau dieser Fallback
     * unterscheidet die beiden Wege. Hat ein Gericht Darreichungen, aber keine mit `is_standard`,
     * liefert der geladene Weg `null` (⇒ keine Zahl), der ungeladene die erste Darreichung.
     * Im Bestand ist der Fall leer (0 von 31 Gerichten mit Darreichung, Dev-MySQL 2026-07-27);
     * ihn aufzulösen heißt, entweder alle Darreichungen eager zu laden oder den Fallback
     * fallenzulassen — eine Auswahl-Entscheidung, die Dominique gehört.
     *
     * @return array{vk: ?float, quelle: 'darreichung'|'keine'}
     */
    public function vkNettoMitQuelle(FoodAlchemistRecipe $recipe, ?FoodAlchemistOutlet $outlet = null): array
    {
        $standard = $recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe);

        if ($standard !== null) {
            // Ebene 2: im Betriebs-Kontext den VK on-the-fly gegen dessen Kostenstruktur;
            // ohne Betrieb der gespeicherte Baseline-VK der Standard-Darreichung (= heute).
            $vk = $outlet !== null
                ? app(\Platform\FoodAlchemist\Services\Preis\CatalogPricingService::class)->salesNetFor(Team::find($outlet->team_id), $standard, $outlet)
                : ($standard->sales_net !== null ? (float) $standard->sales_net : null);
            if ($vk !== null) {
                return ['vk' => $vk, 'quelle' => 'darreichung'];
            }
        }

        return ['vk' => null, 'quelle' => 'keine'];
    }

    /**
     * VK netto eines Gerichts als Zahl — {@see vkNettoMitQuelle()} ohne die Quelle. Spec 85 D-1 PR-A: die
     * Leser lesen den VK nur noch hier (bzw. per SQL an der Standard-Darreichung). Für Listen vorher
     * `standardPresentation` eager laden (sonst eine Query je Gericht).
     */
    public function vkNetto(FoodAlchemistRecipe $recipe, ?FoodAlchemistOutlet $outlet = null): ?float
    {
        return $this->vkNettoMitQuelle($recipe, $outlet)['vk'];
    }

    /**
     * EK EINER Portion — Gegenstück zu {@see vkNetto()}, damit Wareneinsatz % = EK/Portion ÷ VK/Portion auf
     * derselben Menge rechnet (Spec 85, Wareneinsatz-Maßstab): `ek_portion` der Darreichung (inkl. Beigaben), sonst
     * `ek_total_eur`. 98 · 3.5 6a (Dominique: „Ja, fest 1“): Darreichung = Portion, ein Gericht ist EINE Portion —
     * kein Teiler mehr, und damit braucht die Methode `sales_unit_count` nicht mehr (kein Nachladen, keine Warnung).
     */
    public function ekProPortion(FoodAlchemistRecipe $recipe, ?FoodAlchemistRecipeDarreichung $darreichung = null): ?float
    {
        // 98 · 3.6 PR4: mit Darreichung (z. B. die eines Konzept-Slots) deren EK je Portion inkl. Beigaben, sonst die Standard-Darreichung
        $standard = $darreichung ?? ($recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe));
        if ($standard?->ek_portion !== null) {
            return (float) $standard->ek_portion;
        }

        return $recipe->ek_total_eur !== null ? (float) $recipe->ek_total_eur / $this->portionenJeAnsatz($recipe) : null;
    }

    /**
     * 98 · 3.5 Schritt 6a: Portionsgewicht in Gramm — `quantity_per_unit_g` der übergebenen Darreichung (z. B. die eines
     * Slots), sonst der Standard-Darreichung. Ersetzt den Rezept-Spiegel `sales_quantity_per_unit_g`. Für Listen vorher
     * `standardPresentation` eager laden (sonst eine Abfrage je Gericht). Kein Rezept-Modell (z. B. stdClass) ⇒ null.
     */
    public function portionGramm(?object $recipe, ?object $darreichung = null): ?float
    {
        if ($darreichung?->quantity_per_unit_g !== null) {
            return (float) $darreichung->quantity_per_unit_g;
        }
        if (! $recipe instanceof FoodAlchemistRecipe) {
            return null;
        }
        $standard = $recipe->relationLoaded('standardPresentation') ? $recipe->standardPresentation : $this->standardFuer($recipe);

        return $standard?->quantity_per_unit_g !== null ? (float) $standard->quantity_per_unit_g : null;
    }

    /**
     * 98 · 3.5 Schritt 6a: Portionen je Ansatz — der Teiler „Ansatz-Summe ÷ Portionen“ (EK-Summe, Team-EK, eingefrorener EK).
     * Dominique 27.11.: „Ja, fest 1“ — Darreichung = Portion, das Gericht ist EINE Portion. Der Rezept-Spiegel
     * `sales_unit_count` wird nicht mehr gelesen (io: 0 Gerichte und 0 Basisrezepte mit Wert > 1). Die Methode bleibt als
     * benannte Stelle, damit die Leser sagen, WAS sie teilen; das Rezept-Argument ist bewusst ungenutzt.
     */
    public function portionenJeAnsatz(object $recipe): int
    {
        return 1;
    }

    /**
     * Spec 85 D-1 PR-B: Aufschlagsklasse eines Gerichts = Klasse seiner Standard-Darreichung. Für Listen
     * `standardPresentation.markupClass` eager laden.
     */
    public function klasseFuer(FoodAlchemistRecipe $recipe): ?\Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass
    {
        $standard = $recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe);
        if ($standard?->markup_class_id !== null) {
            return $standard->markupClass;
        }

        return null;
    }

    /** VK netto im Kontext (Darreichung des Slots, sonst die Standard-Darreichung des Gerichts). */
    public function vkNettoFuerSlot(FoodAlchemistConceptSlot $slot, ?FoodAlchemistOutlet $outlet = null): ?float
    {
        $darreichung = $this->fuerSlot($slot);

        if ($darreichung !== null) {
            $vk = $outlet !== null
                ? app(\Platform\FoodAlchemist\Services\Preis\CatalogPricingService::class)->salesNetFor(Team::find($outlet->team_id), $darreichung, $outlet)
                : ($darreichung->sales_net !== null ? (float) $darreichung->sales_net : null);
            if ($vk !== null) {
                return $vk;
            }
        }

        return $slot->dish !== null ? $this->vkNetto($slot->dish, $outlet) : null;   // Spec 85 D-1: Standard-Darreichung des Gerichts
    }
}
