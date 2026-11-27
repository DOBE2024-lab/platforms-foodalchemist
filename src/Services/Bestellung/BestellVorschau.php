<?php

namespace Platform\FoodAlchemist\Services\Bestellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Einkaufsvorschau aus Cockpit-Quellen (98 · 3.3, aus OrderService verschoben): Quellen auflösen (Artikel, GP, Rezept, Konzept,
 * Nachfüllen, Produktion), je Lieferant und Liefertag bündeln, Overrides (Lead-Wechsel, Lager-Abgleich, Auslassen, Menge von
 * Hand) anwenden. Schreibt nichts. Die Bausteine liegen in BestellBedarf. Produktions-Schlüssel und Ziel-Hash kommen aus
 * BestellHerkunft statt aus ProductionOrderService (der liegt mit der Fassade in einem Kreis). Nutzt BestellBedarf, nicht die Fassade.
 */
final class BestellVorschau
{
    public function __construct(
        private \Platform\FoodAlchemist\Services\Planung\PlanungsblattService $planung,
        private \Platform\FoodAlchemist\Services\Preis\PriceService $preise,
        private \Platform\FoodAlchemist\Services\Einkauf\GebindeRechner $gebinde,
        private \Platform\FoodAlchemist\Services\Einkauf\LeadLaService $leadLa,
        private BestellBedarf $bedarf,
    ) {}

    /**
     * Reine Einkaufsvorschau aus Cockpit-Quellen. Schreibt keine Drafts, bündelt aber schon
     * exakt wie die echte Bestellung: je Lieferant + Liefertag.
     *
     * @param  list<array{type:string,id:int|string,qty?:int|float|string,unit?:string,delivery_date?:?string,reference?:?string}>  $sources
     * @param  array<string,int>  $overrides  override_key => lead_la_id
     * @return array{orders_preview:list<array>, unresolved:list<array>, warnings:list<string>, totals:array}
     */
    public function previewFromSources(Team $team, array $sources, LeadLaStrategie|string|null $strategy = null, array $overrides = []): array
    {
        $strategie = $this->bedarf->strategieAusWert($strategy);
        $gruppen = [];
        $unresolved = [];
        $warnings = [];
        $sourceCount = 0;
        $rezeptLager = $this->bedarf->rezeptLagerTopf($team, $overrides);

        foreach (array_values($sources) as $idx => $source) {
            $sourceCount++;
            $type = (string) ($source['type'] ?? '');
            $date = ($source['delivery_date'] ?? null) ?: null;
            $reference = trim((string) ($source['reference'] ?? ''));
            $label = $this->bedarf->sourceLabel($team, $source);
            $sourceRef = $this->bedarf->sourceRef($source, $idx);

            try {
                if ($type === 'supplier_item') {
                    $this->previewSupplierItem($team, $gruppen, $unresolved, $source, $date, $reference, $label, $sourceRef);
                } elseif ($type === 'gp') {
                    $this->previewGp($team, $gruppen, $unresolved, $source, $date, $reference, $label, $sourceRef, $strategie, $overrides);
                } elseif ($type === 'recipe') {
                    $this->previewZiel($team, $gruppen, $unresolved, $warnings, $this->bedarf->zielAusRecipeSource($source), $date, $reference, $label, $sourceRef, $strategie, $overrides, $rezeptLager);
                } elseif ($type === 'concept') {
                    // Spec 73: Konzept (auch Paket = kind=paket) × Personen — Auflösung wie in der Produktion; Typ paket (Alt-Paket) entfällt mit 98 · 2.6c
                    $this->previewZiel($team, $gruppen, $unresolved, $warnings, $this->bedarf->zielAusMengenQuelle($source), $date, $reference, $label, $sourceRef, $strategie, $overrides, $rezeptLager);
                } elseif ($type === 'nachfuellen') {
                    // Spec 74: Lagerartikel unter Mindestbestand → auf Sollbestand auffüllen
                    foreach (app(\Platform\FoodAlchemist\Services\Einkauf\LagerartikelService::class)->unterMindest($team) as $la) {
                        $qty = in_array($la['base'], ['g', 'ml'], true) ? $la['nachfuellen_basis'] / 1000 : $la['nachfuellen_basis'];
                        if ($qty <= 0) {
                            continue;
                        }
                        $this->previewGp($team, $gruppen, $unresolved, ['type' => 'gp', 'id' => $la['gp_id'], 'qty' => round($qty, 3), 'unit' => $la['base'] === 'Stk' ? 'stk' : 'kg'],
                            $date, $reference !== '' ? $reference : 'Lagerartikel nachfüllen', 'Nachfüllen: ' . $la['name'], 'nachfuellen:gp:' . $la['gp_id'], $strategie, $overrides);
                    }
                } elseif ($type === 'production') {
                    $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                    if ($production === null) {
                        $unresolved[] = $this->bedarf->unresolved($source, $label, 'produktion_fehlt', 'Produktion nicht sichtbar oder nicht vorhanden.');

                        continue;
                    }
                    $targets = $this->releasedProductionTargets($production);
                    $prodDate = $date ?: $production->production_date?->toDateString();
                    foreach ($targets as $targetIdx => $target) {
                        $targetRef = BestellHerkunft::produktionsRef((int) $production->id, (string) ($target['source_ref'] ?? ('target-'.$targetIdx)));
                        $this->previewZiel(
                            $team,
                            $gruppen,
                            $unresolved,
                            $warnings,
                            $this->bedarf->zielOhneMeta($target),
                            $prodDate,
                            $reference !== '' ? $reference : (string) ($target['label'] ?? ''),
                            $label,
                            $targetRef,
                            $strategie,
                            $overrides,
                            $rezeptLager
                        );
                    }
                } else {
                    $unresolved[] = $this->bedarf->unresolved($source, $label, 'quelle_unbekannt', 'Quelltyp wird noch nicht unterstützt.');
                }
            } catch (\Throwable $e) {
                $unresolved[] = $this->bedarf->unresolved($source, $label, 'quelle_fehlerhaft', $e->getMessage());
            }
        }

        [$gruppen, $ausLager, $ausgelassen, $vorrat] = $this->nachbearbeitePreview($team, $gruppen, $overrides);
        $ordersPreview = $this->bedarf->finalisierePreviewGruppen($gruppen);
        $totalNet = round(array_sum(array_map(fn ($g) => (float) ($g['total_net'] ?? 0), $ordersPreview)), 2);

        return [
            'orders_preview' => $ordersPreview,
            // Spec 71: aus dem Lager gedeckt bzw. von Hand ausgelassen (zum Wiederherstellen)
            'aus_lager' => $ausLager,
            'ausgelassen' => $ausgelassen,
            'lager_abgleich' => ! empty($overrides['lager_abgleich']),
            'rezept_lager' => $this->bedarf->rezeptLagerAnzeige($rezeptLager),
            'vorrat' => $vorrat,                                                        // Spec 74: Lagerartikel aus dem Rezeptbedarf
            'nachfuellen_moeglich' => count(app(\Platform\FoodAlchemist\Services\Einkauf\LagerartikelService::class)->unterMindest($team)),
            'nachfuellen_aktiv' => collect($sources)->contains(fn ($q) => ($q['type'] ?? '') === 'nachfuellen'),
            'unresolved' => array_values($unresolved),
            'warnings' => array_values(array_unique($warnings)),
            'totals' => [
                'sources' => $sourceCount,
                'groups' => count($ordersPreview),
                'positions' => array_sum(array_map(fn ($g) => count($g['positionen'] ?? []), $ordersPreview)),
                'unresolved' => count($unresolved),
                'total_net' => $totalNet,
            ],
        ];
    }

    /** @return list<array> */
    public function releasedProductionTargets(FoodAlchemistProductionOrder $production): array
    {
        if ($production->procurement_released_at === null || empty($production->procurement_targets_snapshot)) {
            throw new \RuntimeException('Materialbedarf dieser Produktion ist noch nicht freigegeben.');
        }
        if ($production->procurement_targets_hash !== BestellHerkunft::zielHash($production->targets)) {
            throw new \RuntimeException('Materialbedarf wurde nach der Freigabe geändert. Bitte in Produktion erneut freigeben.');
        }

        return array_values($production->procurement_targets_snapshot);
    }

    private function previewSupplierItem(Team $team, array &$gruppen, array &$unresolved, array $source, ?string $date, string $reference, string $label, string $sourceRef): void
    {
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure.gp:id,name,piece_default_g'])
            ->find((int) ($source['id'] ?? 0));

        if ($la === null || $la->supplier_id === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'artikel_fehlt', 'Lieferantenartikel fehlt oder hat keinen Lieferanten.');

            return;
        }

        $qty = max(0.0, $this->bedarf->sourceQty($source));
        if ($qty <= 0) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'menge_fehlt', 'Menge muss größer 0 sein.');

            return;
        }

        $ctx = (object) [
            'qty' => FoodAlchemistSupplierItem::mengeVon($la),   // korrigierte Menge vor Necta-qty
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id, $team)?->price,
        ];
        $geb = $this->gebinde->berechne($ctx, 0.0, $la->structure?->gp?->piece_default_g !== null ? (float) $la->structure->gp->piece_default_g : null);
        $packPrice = $geb['pack_price'] ?? null;
        $lineTotal = $packPrice !== null ? round($qty * (float) $packPrice, 2) : 0.0;
        $this->bedarf->previewAddPosition($gruppen, (int) $la->supplier_id, $la->supplier?->name ?? '—', $date, [
            'source_ref' => $sourceRef,
            'source_label' => $label,
            'reference' => $reference,
            'type' => 'supplier_item',
            'gp_id' => $la->structure?->gp?->id !== null ? (int) $la->structure->gp->id : null,
            'gp' => $la->structure?->gp?->name,
            'lead_la_id' => (int) $la->id,
            'override_key' => $this->bedarf->overrideKey($sourceRef, $la->structure?->gp?->id !== null ? (int) $la->structure->gp->id : null),
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'qty_packs' => $qty,
            'packaging_unit' => $la->packaging_unit,
            'pack_price' => $packPrice !== null ? (float) $packPrice : null,
            'line_total' => $lineTotal,
            'needed_base_g' => 0.0,
            'needed_display' => null,
            'needed_unit' => null,
            'bestellbar' => $packPrice !== null && $qty > 0,
        ]);

        if ($packPrice === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'preis_fehlt', 'Preis am Lieferantenartikel fehlt; Position wird als 0-Euro-Klärfall angezeigt.');
        }
    }

    private function previewGp(Team $team, array &$gruppen, array &$unresolved, array $source, ?string $date, string $reference, string $label, string $sourceRef, ?LeadLaStrategie $strategie, array $overrides = []): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
        if ($gp === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'gp_fehlt', 'Grundprodukt nicht sichtbar oder nicht vorhanden.');

            return;
        }

        $grams = $this->bedarf->sourceGramsForGp($gp, $source);
        if ($grams <= 0) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'menge_fehlt', 'Menge muss größer 0 sein.');

            return;
        }
        if (($bz = \Platform\FoodAlchemist\Support\Derivat\DerivatBedarf::bestellziel($gp, $grams)) === null) { $unresolved[] = $this->bedarf->unresolved($source, $label, 'sammelbestand', 'Nebenprodukt aus dem Sammelbestand, wird nicht bestellt.'); return; } [$gp, $grams] = $bz;   // Spec 96: Zweck-Derivat → Mutter
        $overrideKey = $this->bedarf->overrideKey($sourceRef, (int) $gp->id);
        $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
        $lead = $this->overrideLead($team, $gp, $overrides[$overrideKey] ?? null) ?? $lead;
        if ($lead?->supplier_id === null || $lead?->id === null) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'lead_la_fehlt', 'Kein bestellbarer Hauptartikel für dieses Grundprodukt. Lieferantenartikel zuordnen.');

            return;
        }

        $pieceG = $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null;
        $geb = $this->gebinde->berechne($lead, $grams, $pieceG);
        $packPrice = $geb['pack_price'] ?? null;
        $this->bedarf->previewAddPosition($gruppen, (int) $lead->supplier_id, $lead->supplier_name ?? '—', $date, [
            'source_ref' => $sourceRef,
            'source_label' => $label,
            'reference' => $reference,
            'type' => 'gp',
            'gp_id' => (int) $gp->id,
            'gp' => $gp->name,
            'lead_la_id' => (int) $lead->id,
            'override_key' => $overrideKey,
            'article_number' => $lead->article_number,
            'designation' => $lead->designation,
            'qty_packs' => (float) ($geb['qty_packs'] ?? 0),
            'packaging_unit' => $geb['packaging_unit'] ?? $lead->packaging_unit,
            'pack_price' => $packPrice !== null ? (float) $packPrice : null,
            'line_total' => ($geb['line_total'] ?? null) !== null ? (float) $geb['line_total'] : 0.0,
            'needed_base_g' => $grams,
            'needed_display' => $this->bedarf->displayMenge($grams, (string) ($source['unit'] ?? 'kg'), $pieceG),
            'needed_unit' => (string) ($source['unit'] ?? 'kg'),
            'bestellbar' => $packPrice !== null && (float) ($geb['qty_packs'] ?? 0) > 0,
        ]);

        if ($packPrice === null || ! ($geb['berechenbar'] ?? false)) {
            $unresolved[] = $this->bedarf->unresolved($source, $label, 'gebinde_preis_fehlt', 'Gebinde oder Preis ist nicht vollständig berechenbar.');
        }
    }

    private function previewZiel(Team $team, array &$gruppen, array &$unresolved, array &$warnings, array $ziel, ?string $date, string $reference, string $label, string $sourceRef, ?LeadLaStrategie $strategie, array $overrides = [], ?array &$rezeptLager = null): void
    {
        $vorschlag = $this->planung->bestellvorschlag($team, $ziel, $strategie, $rezeptLager);
        $warnings = array_merge($warnings, $vorschlag['warnungen'] ?? []);

        foreach (\Platform\FoodAlchemist\Support\Derivat\DerivatBedarf::ohneSammelbestand($vorschlag['lieferanten'] ?? []) as $grp) {   // Spec 96: Sammelbestand nie bestellen
            $supplierId = $grp['supplier_id'] ?? null;
            if ($supplierId === null) {
                foreach (($grp['positionen'] ?? []) as $pos) {
                    $unresolved[] = $this->bedarf->unresolved(
                        ['type' => 'recipe', 'id' => $ziel['recipe_id'] ?? null],
                        $label.($pos['gp'] ? ' · '.$pos['gp'] : ''),
                        'lead_la_fehlt',
                        'Kein bestellbarer Hauptartikel für diese Bedarfsposition. Lieferantenartikel zuordnen.'
                    );
                }

                continue;
            }

            foreach (($grp['positionen'] ?? []) as $pos) {
                $geb = $pos['gebinde'] ?? [];
                $gpId = $pos['gp_id'] !== null ? (int) $pos['gp_id'] : null;
                $neededG = (float) ($pos['menge_kg'] ?? 0) * 1000.0;
                $overrideKey = $this->bedarf->overrideKey($sourceRef, $gpId);
                $leadLaId = $pos['lead_la_id'] !== null ? (int) $pos['lead_la_id'] : null;
                $supplierForGroup = (int) $supplierId;
                $supplierName = (string) ($grp['lieferant'] ?? '—');
                $articleNumber = $pos['lead_artikel_nr'] ?? ($geb['article_number'] ?? null);
                $designation = $pos['lead_artikel'] ?? null;
                $packagingUnit = $geb['packaging_unit'] ?? null;
                $usedOverride = false;
                if ($gpId !== null && $overrideKey !== null && array_key_exists($overrideKey, $overrides)) {
                    $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
                    $overrideLead = $gp !== null ? $this->overrideLead($team, $gp, $overrides[$overrideKey] ?? null) : null;
                    if ($overrideLead !== null && $overrideLead->supplier_id !== null) {
                        $usedOverride = true;
                        $supplierForGroup = (int) $overrideLead->supplier_id;
                        $supplierName = (string) ($overrideLead->supplier_name ?? '—');
                        $leadLaId = (int) $overrideLead->id;
                        $pieceG = $gp?->piece_default_g !== null ? (float) $gp->piece_default_g : null;
                        $geb = $this->gebinde->berechne($overrideLead, $neededG, $pieceG);
                        $articleNumber = $overrideLead->article_number;
                        $designation = $overrideLead->designation;
                        $packagingUnit = $geb['packaging_unit'] ?? $overrideLead->packaging_unit;
                    }
                }
                $packPrice = $geb['pack_price'] ?? null;
                $this->bedarf->previewAddPosition($gruppen, $supplierForGroup, $supplierName, $date, [
                    'source_ref' => $sourceRef,
                    'source_label' => $label,
                    'reference' => $reference,
                    'type' => 'recipe',
                    'gp_id' => $gpId,
                    'gp' => $pos['gp'] ?? null,
                    'lead_la_id' => $leadLaId,
                    'override_key' => $overrideKey,
                    'article_number' => $articleNumber,
                    'designation' => $designation,
                    'qty_packs' => (float) ($geb['qty_packs'] ?? 0),
                    'packaging_unit' => $packagingUnit,
                    'pack_price' => $packPrice !== null ? (float) $packPrice : null,
                    'line_total' => ($geb['line_total'] ?? null) !== null ? (float) $geb['line_total'] : ($usedOverride ? 0.0 : ($pos['bestell_ek_eur'] !== null ? (float) $pos['bestell_ek_eur'] : 0.0)),
                    'needed_base_g' => $neededG,
                    'needed_display' => (float) ($pos['menge_kg'] ?? 0),
                    'needed_unit' => 'kg',
                    'bestellbar' => $leadLaId !== null && $packPrice !== null && (float) ($geb['qty_packs'] ?? 0) > 0,
                ]);

                if ($leadLaId === null || $packPrice === null || ! ($geb['berechenbar'] ?? false)) {
                    $unresolved[] = $this->bedarf->unresolved(
                        ['type' => 'recipe', 'id' => $ziel['recipe_id'] ?? null],
                        $label.($pos['gp'] ? ' · '.$pos['gp'] : ''),
                        $leadLaId === null ? 'lead_la_fehlt' : 'gebinde_preis_fehlt',
                        $leadLaId === null ? 'Kein Hauptartikel gefunden. Lieferantenartikel zuordnen.' : 'Gebinde oder Preis ist nicht vollständig berechenbar.'
                    );
                }
            }
        }
    }

    /**
     * Spec 71 · Vorschau nachbearbeiten (vor dem Gruppen-Abschluss, gilt für Vorschau UND Speichern):
     *  1. Lager-Abgleich (`overrides.lager_abgleich`): Bedarf aus Rezepten/Grundprodukten minus Lagerbestand
     *     des Grundprodukts (alle aktiven Lagerorte, je GP nur einmal verteilt); voll gedeckt → „aus Lager".
     *  2. Auslassen (`overrides.skip[schluessel]`) → Liste „ausgelassen", wiederherstellbar.
     *  3. Menge von Hand (`overrides.menge[schluessel]` = Gebinde) überschreibt die gerechneten Gebinde.
     *
     * @return array{0: array, 1: list<array>, 2: list<array>}
     */
    private function nachbearbeitePreview(Team $team, array $gruppen, array $overrides): array
    {
        $skip = (array) ($overrides['skip'] ?? []);
        $mengen = (array) ($overrides['menge'] ?? []);
        $global = ! empty($overrides['lager_abgleich']);
        $jePos = (array) ($overrides['lager_pos'] ?? []);
        // Bestand immer lesen: jede Position zeigt, was vom GP im Lager liegt — abgezogen wird nur auf Knopfdruck.
        // Spec 73: abzüglich dessen, was andere gespeicherte Runden schon angerechnet haben.
        $reserviert = $this->bedarf->lagerReserviert($team, isset($overrides['round_id']) ? (int) $overrides['round_id'] : null);
        $lager = $this->bedarf->lagerJeGp($team, $reserviert['gp']);
        $ausLager = [];
        $ausgelassen = [];
        $vorrat = [];
        $lagerartikel = app(\Platform\FoodAlchemist\Services\Einkauf\LagerartikelService::class)->ids($team);
        $vorratBestellen = (array) ($overrides['vorrat_bestellen'] ?? []);
        foreach ($gruppen as $gk => $g) {
            $neu = [];
            foreach ($g['positionen'] as $pos) {
                $pk = $this->bedarf->positionsSchluessel($pos);
                $pos['position_key'] = $pk;
                $gpId = $pos['gp_id'] ?? null;
                $nachfuellen = str_starts_with((string) ($pos['source_ref'] ?? ''), 'nachfuellen:');
                $pos['nachfuellen'] = $nachfuellen;
                // 0. Spec 74: Lagerartikel (Gewürze, Öle …) kommen aus dem Vorrat, nicht aus dem Rezeptbedarf
                if (! $nachfuellen && $gpId !== null && isset($lagerartikel[(int) $gpId]) && ($pos['type'] ?? '') !== 'supplier_item' && empty($vorratBestellen[$pk])) {
                    $vorrat[] = $pos + ['supplier' => $g['supplier']];

                    continue;
                }
                // 1. Lager — nur gerechneter Bedarf (Rezept/GP), nicht der feste Artikel in Gebinden
                $lagerBar = ! $nachfuellen && ($pos['type'] ?? '') !== 'supplier_item' && $gpId !== null && (float) ($pos['needed_base_g'] ?? 0) > 0 && ($lager[$gpId] ?? 0) > 0;
                if ($lagerBar) {
                    $pos['lager_verfuegbar_g'] = round((float) $lager[$gpId], 2);
                }
                if ($gpId !== null && ($reserviert['gp'][$gpId] ?? 0) > 0) {
                    $pos['lager_reserviert_g'] = round((float) $reserviert['gp'][$gpId], 2);
                    $pos['lager_reserviert_fuer'] = array_values(array_unique($reserviert['runden']['gp:' . $gpId] ?? []));
                }
                $abziehen = array_key_exists($pk, $jePos) ? (bool) $jePos[$pk] : $global;
                if ($lagerBar && $abziehen) {
                    $bedarf = (float) $pos['needed_base_g'];
                    $nimm = min($bedarf, (float) $lager[$gpId]);
                    $lager[$gpId] -= $nimm;
                    $pos['bedarf_g'] = round($bedarf, 2);
                    $pos['lager_g'] = round($nimm, 2);
                    $pos['needed_base_g'] = round($bedarf - $nimm, 2);
                    if ($pos['needed_base_g'] <= 0.5) {
                        $ausLager[] = $pos + ['supplier' => $g['supplier']];

                        continue;
                    }
                    $lead = $this->leadObjekt($team, (int) ($pos['lead_la_id'] ?? 0));
                    if ($lead !== null) {
                        $gpModel = FoodAlchemistGp::find($gpId, ['id', 'piece_default_g']);
                        $geb = $this->gebinde->berechne($lead, (float) $pos['needed_base_g'], $gpModel?->piece_default_g !== null ? (float) $gpModel->piece_default_g : null);
                        $pos['qty_packs'] = (float) ($geb['qty_packs'] ?? $pos['qty_packs']);
                        $pos['line_total'] = (float) ($geb['line_total'] ?? $pos['line_total']);
                    }
                }
                // 2. Auslassen
                if (! empty($skip[$pk])) {
                    $ausgelassen[] = $pos + ['supplier' => $g['supplier']];

                    continue;
                }
                // 3. Menge von Hand (Gebinde)
                if (isset($mengen[$pk]) && is_numeric($mengen[$pk]) && (float) $mengen[$pk] >= 0) {
                    $pos['qty_packs_berechnet'] = (float) ($pos['qty_packs'] ?? 0);
                    $pos['qty_packs'] = round((float) $mengen[$pk], 3);
                    $pos['line_total'] = ($pos['pack_price'] ?? null) !== null ? round($pos['qty_packs'] * (float) $pos['pack_price'], 2) : (float) ($pos['line_total'] ?? 0);
                    $pos['menge_von_hand'] = true;
                }
                $neu[] = $pos;
            }
            if ($neu === []) {
                unset($gruppen[$gk]);

                continue;
            }
            $gruppen[$gk]['positionen'] = $neu;
            $gruppen[$gk]['total_net'] = round(array_sum(array_map(fn ($p) => (float) ($p['line_total'] ?? 0), $neu)), 2);
        }

        return [$gruppen, $ausLager, $ausgelassen, $vorrat];
    }

    /** Lieferantenartikel als Gebinde-Kontext (wie overrideLead, ohne GP-Prüfung). */
    private function leadObjekt(Team $team, int $laId): ?object
    {
        $la = $laId > 0 ? FoodAlchemistSupplierItem::visibleToTeam($team)->find($laId) : null;
        if ($la === null) {
            return null;
        }

        return (object) ['id' => (int) $la->id, 'qty' => FoodAlchemistSupplierItem::mengeVon($la), 'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit, 'article_number' => $la->article_number, 'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id, $team)?->price];
    }

    private function overrideLead(Team $team, FoodAlchemistGp $gp, int|string|null $leadLaId): ?object
    {
        if ($leadLaId === null || (int) $leadLaId <= 0) {
            return null;
        }

        $la = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure.gp:id,name'])
            ->find((int) $leadLaId);
        if ($la === null || $la->supplier_id === null || (int) ($la->structure?->gp?->id ?? 0) !== (int) $gp->id) {
            return null;
        }

        return (object) [
            'id' => (int) $la->id,
            'supplier_id' => (int) $la->supplier_id,
            'supplier_name' => $la->supplier?->name,
            'qty' => FoodAlchemistSupplierItem::mengeVon($la),   // korrigierte Menge vor Necta-qty
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id, $team)?->price,
        ];
    }
}
