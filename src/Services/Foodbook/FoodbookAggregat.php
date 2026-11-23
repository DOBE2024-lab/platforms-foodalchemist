<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\ConceptPreisService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\FormatKapitelService;
use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * M11 Cockpit · Aggregat und Preis des Foodbooks (98 · 3.3 S4-4, aus FoodbookService verschoben): Preis-Beitrag je Block
 * inklusive Staffel, rekursives Kapitel-Aggregat, Wareneinsatz-Ampel je Kapitel und Foodbook, Gesamtpreis (Σ Top-Kapitel × Pax).
 * Nutzt ConceptService (Preis-Cockpit eines Concepts) und das Vokabular (Kapitel-Ziele), nie die Fassade.
 */
final class FoodbookAggregat
{
    public function __construct(
        private ConceptService $concepts,
        private FoodbookVokabular $vokabular,
    ) {}

    // ── Aggregat / Preis (M11 Cockpit) ──────────────────────────────────────────

    /**
     * Preis-Beitrag eines Blocks (Jarvis-Parität): liefert Per-Person-Anteil (vk/ek)
     * UND einen Pauschal-Anteil (flach, nicht ×Pax).
     *  - recipe_ref  → sales_net/ek_total × Menge; `price_basis` steuert die Achse:
     *                  person (Default) = Per-Person · pauschal = flacher Anteil (€/Position,
     *                  kein ×Pax; EK bleibt hier ungezählt, WE-Ampel meldet „partiell", E4.4)
     *  - concept_ref → Concept-€/Person (person-unabhängig)
     *  - header_frei_preis: person→Per-Person · staffel→Per-Person (nach Pax aufgelöst) · pauschal→flach
     *
     * @return array{vk_pp: float, ek_pp: float, pauschal: float}
     */
    public function blockPreis(FoodAlchemistFoodbookBlock $block, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        if ($block->type === 'concept_ref' && $block->concept) {
            $cockpit = $this->concepts->preisCockpit($block->concept, $outlet);

            return ['vk_pp' => (float) $cockpit['price_per_person'], 'ek_pp' => (float) $cockpit['ek_per_person'], 'pauschal' => 0.0, 'vk_fehlt' => ConceptPreisService::unvollstaendig($cockpit)];   // CQ price_incomplete
        }
        if ($block->type === 'recipe_ref' && $block->dish) {
            $faktor = $block->quantity !== null ? (float) $block->quantity : 1.0;
            $resolver = app(DarreichungResolver::class);   // Spec 85 D-1: Preisleiter; im Betrieb dessen VK, sonst die Baseline
            $baseVk = (float) (($outlet !== null ? $resolver->vkNetto($block->dish, $outlet) : null) ?? $resolver->vkNetto($block->dish) ?? 0);
            $vk = round($baseVk * $faktor, 2);
            $ek = round((float) ($resolver->ekProPortion($block->dish) ?? 0) * $faktor, 2);   // Spec 85: EK je Portion, wie der VK

            // Spec 19 E1.2: Einzel-Gericht pauschal (€/Position, flach) vs. per-Person (€/Gast).
            // Pauschal → VK in den flachen Anteil (kein ×Pax); EK bleibt ungezählt (WE-Ampel
            // meldet „partiell", E4.4), konsistent zu header_frei_preis/pauschal.
            if ($block->price_basis === 'pauschal') {
                return ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => $vk];
            }

            return ['vk_pp' => $vk, 'ek_pp' => $ek, 'pauschal' => 0.0];
        }
        if ($block->type === 'header_frei_preis') {
            return match ($block->price_basis) {
                'pauschal' => ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => (float) ($block->price_value ?? 0)],
                'staffel' => ['vk_pp' => $this->resolveStaffel($block, $pax), 'ek_pp' => 0.0, 'pauschal' => 0.0],
                default => ['vk_pp' => (float) ($block->price_value ?? 0), 'ek_pp' => 0.0, 'pauschal' => 0.0], // person
            };
        }

        return ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => 0.0];
    }

    /** Staffel-Auflösung: höchste Stufe mit min_personen ≤ Pax (ohne Pax die niedrigste). */
    public function resolveStaffel(FoodAlchemistFoodbookBlock $block, ?int $pax): float
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

    /**
     * Rekursives Kapitel-Aggregat: sichtbare Blöcke + Unterkapitel. Per-Person (vk/ek)
     * getrennt vom Pauschal-Anteil. Manuell gesetzter `preis_pro_person` übersteuert
     * die Per-Person-VK-Summe (EK + Pauschal bleiben gerechnet).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, pauschal: float, food_cost_percent: ?float}
     */
    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $kapitel->loadMissing(['blocks' => fn ($q) => $q->where('visible', true),
            'blocks.concept:id,name,price_per_person_cache,price_incomplete,team_id,ist_fassung,price_mode,price_per_person_manual,price_override_expires_at', 'blocks.dish:id,ek_total_eur,sales_unit_count', 'blocks.dish.standardPresentation',
            'blocks.staffel', 'children']);

        $vk = $ek = $pauschal = 0.0;
        $vkFehlt = false;   // CQ price_incomplete: mind. ein Konzept-Preis ist nur die Summe der bekannten Positionen
        foreach ($kapitel->blocks as $block) {
            $p = $this->blockPreis($block, $pax, $outlet);
            $vkFehlt = $vkFehlt || ($p['vk_fehlt'] ?? false);
            $vk += $p['vk_pp'];
            $ek += $p['ek_pp'];
            $pauschal += $p['pauschal'];
        }
        foreach ($kapitel->children as $kind) {
            $kindAgg = $this->kapitelAggregat($team, $kind, $pax, $outlet);
            $vk += $kindAgg['vk_pro_person'];
            $ek += $kindAgg['ek_per_person'];
            $pauschal += $kindAgg['pauschal'];
            $vkFehlt = $vkFehlt || $kindAgg['vk_fehlt'];
        }

        if ($kapitel->price_mode === 'manuell' && $kapitel->price_per_person !== null) {
            [$vk, $vkFehlt] = [(float) $kapitel->price_per_person, false];   // gesetzter Kapitelpreis ist nicht vorläufig
        }

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_per_person' => round($ek, 2),
            'pauschal' => round($pauschal, 2),
            'food_cost_percent' => $vkFehlt ? null : MargeService::wareneinsatzPct($ek, $vk), 'vk_fehlt' => $vkFehlt,   // Food-Cost aus einer VK-Teilsumme wäre geschönt
        ];
    }

    /**
     * Spec 19 E4.4: Wareneinsatz-Ampel eines Kapitels. **IST** = tatsächliche Food-Cost-%
     * aus `kapitelAggregat()` (EK ÷ VK der Per-Person-Anteile, rekursiv über Nachfahren).
     * **SOLL** = Ziel-Wareneinsatz mit Kaskade Kapitel → Eltern → Foodbook (via `kapitelZiele`)
     * → Team-Setting (`zielWareneinsatzPct`, 30 %-Default). Toleranz = `food_cost_tolerance_pp`
     * des Foodbooks (Code-Default 5,0 pp).
     *
     * Ampel: `gruen` IST ≤ Ziel · `gelb` IST ≤ Ziel+Toleranz · `rot` darüber · `unbekannt`
     * ohne IST (kein Per-Person-VK). **Partiell-Hinweis:** Pauschal-Anteile (header_frei_preis/
     * pauschal, recipe_ref/pauschal) tragen VK, aber ihr EK bleibt ungezählt (`blockPreis`, E1.2)
     * → die IST-Quote unterschätzt den echten Food-Cost. `partiell=true` markiert das, damit die
     * Kalkulations-Rail (E5.3) und `coverage.GET` (E4.6) den Vorbehalt sichtbar machen.
     *
     * @return array{status: string, ist_pct: ?float, ziel_pct: float, toleranz_pp: float,
     *               quelle: string, partiell: bool}
     */
    public function wareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        // kapitelZiele zuerst — erzwingt Team-Scope + Ownership (ownedKapitel) vor jeder Rechnung.
        $ziele = $this->vokabular->kapitelZiele($team, $kapitel);
        $ziel = $ziele['target_food_cost_pct'];
        $quelle = $ziele['quellen']['target_food_cost_pct'] ?? null;
        if ($ziel === null) {
            // Ebene 2: ohne Kapitel-Ziel folgt die Soll-Quote der Betriebsbrille (Betriebs-Ziel-WE).
            $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($team, $outlet);
            $quelle = 'settings';
        }
        $ziel = (float) $ziel;

        $agg = $this->kapitelAggregat($team, $kapitel, $pax, $outlet);
        $ist = $agg['food_cost_percent']; // ?float

        $tol = $fb->food_cost_tolerance_pp !== null ? (float) $fb->food_cost_tolerance_pp : 5.0;

        return [
            'status' => $this->weAmpelStatus($ist, $ziel, $tol),
            'ist_pct' => $ist,
            'ziel_pct' => round($ziel, 2),
            'toleranz_pp' => round($tol, 2),
            'quelle' => $quelle ?? 'settings',
            'partiell' => $agg['pauschal'] > 0,
        ];
    }

    /**
     * Spec 19 E8.2 — Portfolio-Wareneinsatz-Ampel des GANZEN Foodbooks (Rail-Kalkulation-
     * Panel, Kopf-Modus). Gleiche Ampel-Logik wie {@see wareneinsatzAmpel}, aber IST aus dem
     * Foodbook-Gesamt ({@see gesamt}: Σ Top-Kapitel Per-Person, EK ÷ VK) statt je Kapitel.
     * **SOLL** = Foodbook-Ziel (`target_food_cost_pct`) → Team-Setting-Default (30 %);
     * Toleranz = `food_cost_tolerance_pp` (Code-Default 5,0 pp). **Partiell** = Pauschal-Anteil
     * im Gesamt (EK ungezählt → IST unterschätzt), analog zur Kapitel-Ampel.
     *
     * @return array{status: string, ist_pct: ?float, ziel_pct: float, toleranz_pp: float, quelle: string, partiell: bool}
     */
    public function foodbookWareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $gesamt = $this->gesamt($team, $fb, $outlet);
        $vk = $gesamt['vk_pro_person'];
        $ek = $gesamt['ek_per_person'];
        $ist = $gesamt['vk_fehlt'] ? null : MargeService::wareneinsatzPct($ek, $vk);   // CQ price_incomplete: VK-Teilsumme → keine Quote

        if ($fb->target_food_cost_pct !== null) {
            $ziel = (float) $fb->target_food_cost_pct;
            $quelle = 'foodbook';
        } else {
            // Ebene 2: Soll-Quote folgt der Betriebsbrille.
            $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($team, $outlet);
            $quelle = 'settings';
        }
        $tol = $fb->food_cost_tolerance_pp !== null ? (float) $fb->food_cost_tolerance_pp : 5.0;

        return [
            'status' => $this->weAmpelStatus($ist, $ziel, $tol),
            'ist_pct' => $ist,
            'ziel_pct' => round($ziel, 2),
            'toleranz_pp' => round($tol, 2),
            'quelle' => $quelle,
            'partiell' => $gesamt['pauschal'] > 0,
        ];
    }

    /**
     * Gemeinsame Ampel-Klassifikation (E4.4/E8.2): `gruen` IST ≤ Ziel · `gelb` IST ≤ Ziel+Toleranz
     * · `rot` darüber · `unbekannt` ohne IST. Wird von Kapitel- ({@see wareneinsatzAmpel}) und
     * Portfolio-Ampel ({@see foodbookWareneinsatzAmpel}) geteilt (identische Grenzen).
     */
    private function weAmpelStatus(?float $ist, float $ziel, float $tol): string
    {
        if ($ist === null) {
            return 'unbekannt';
        }
        if ($ist <= $ziel) {
            return 'gruen';
        }

        return $ist <= $ziel + $tol ? 'gelb' : 'rot';
    }

    /**
     * Foodbook-Gesamt: (Σ Top-Kapitel Per-Person × Pax) + Pauschal-Anteile. Erst HIER wird die Gästezahl bindend (F-12, D-CON-5).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, pauschal: float, personen: ?int, gesamt_vk: ?float, gesamt_ek: ?float, food_cost_percent: ?float, edition_ab: ?float}
     */
    public function gesamt(Team $team, FoodAlchemistFoodbook $fb, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $pax = $fb->personen;
        $vk = $ek = $pauschal = 0.0;
        $vkFehlt = false;
        foreach ($fb->chapters()->whereNull('parent_id')->get() as $top) {
            $agg = $this->kapitelAggregat($team, $top, $pax, $outlet);
            $vkFehlt = $vkFehlt || $agg['vk_fehlt'];
            $vk += $agg['vk_pro_person'];
            $ek += $agg['ek_per_person'];
            $pauschal += $agg['pauschal'];
        }

        [$vkPp, $ekPp] = [round($vk, 2), round($ek, 2)];

        return [
            'vk_pro_person' => $vkPp,
            'ek_per_person' => $ekPp,
            'pauschal' => round($pauschal, 2),
            'personen' => $pax,
            'gesamt_vk' => $pax !== null ? round($vk * $pax + $pauschal, 2) : null,
            'gesamt_ek' => $pax !== null ? round($ek * $pax, 2) : null,
            // Wareneinsatz % auf denselben gerundeten Per-Person-Werten wie die Anzeige (F-12),
            // damit die Zahl exakt der bisherigen Blade-Rechnung ek_pp / vk_pp * 100 entspricht.
            'food_cost_percent' => $vkFehlt ? null : MargeService::wareneinsatzPct($ekPp, $vkPp), 'vk_fehlt' => $vkFehlt,   // CQ price_incomplete
            'edition_ab' => app(FormatKapitelService::class)->editionAb($fb),   // 98 · 2.4e: Format-Editionen (Alternativen, nicht summiert) als „ab …“
        ];
    }
}
