<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 19 E3.3/E3.5 + #369 · Vokabular des Foodbooks (98 · 3.3 S4-2, aus FoodbookService verschoben): Default-Dimensionen
 * (Einsatzmoment, Zielgruppe), Zielgruppen-Vokabular und -Anlage, Leitplanken und Kreativ-Modus je Kapitel, Kapitel-Ziele
 * sowie die CRM-Suche (Firmen/Kontakte). Das Verlinken selbst (verknuepfeKunde) bleibt im Kern, weil es über update schreibt.
 * Nutzt die Basis, nie die Fassade.
 */
final class FoodbookVokabular
{
    public function __construct(private FoodbookBasis $basis) {}

    // ── Spec 19 E3.3: Bedarf — Foodbook-Default-Dimensionen (kaskadieren als Boden) ──

    /** Default-Einsatzmoment (Tagesablauf) an/abwählen — 1–n-Pivot foodbook_service_moments. */
    public function toggleEinsatzmoment(Team $team, int $fbId, int $momentId): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($fbId);
        $this->basis->guard($fb, $team);
        $fb->serviceMoments()->allRelatedIds()->contains($momentId) || \Platform\FoodAlchemist\Support\Verweisziel::pruefe(\Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::class, $team, $momentId, 'Einsatzmoment');
        $fb->serviceMoments()->toggle([$momentId]);
    }

    /** Default-Zielgruppe an/abwählen — 1–n-Pivot foodbook_target_groups (Entscheidung 4). */
    public function toggleZielgruppe(Team $team, int $fbId, int $targetGroupId): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($fbId);
        $this->basis->guard($fb, $team);
        $fb->targetGroups()->allRelatedIds()->contains($targetGroupId) || \Platform\FoodAlchemist\Support\Verweisziel::pruefe(FoodAlchemistTargetGroup::class, $team, $targetGroupId, 'Zielgruppe');
        $fb->targetGroups()->toggle([$targetGroupId]);
    }

    /**
     * Spec 19 E4.6: Zielgruppen eines Kapitels setzen (PUT-Semantik — `sync` auf die genaue Liste; leeres Array = alle entfernen).
     * Kapitel-Zielgruppen überschreiben den Foodbook-Default in der Kaskade (leitplanken()/zielgruppenKaskade). Neue IDs
     * müssen team-sichtbares Vokabular sein (Entscheidung 6; CQ 2.3f: hier geprüft, nicht nur im MCP-Guard).
     *
     * @param  list<int>  $ids
     */
    public function setKapitelZielgruppen(Team $team, int $kapitelId, array $ids): void
    {
        $k = $this->basis->ownedKapitel($team, $kapitelId);
        \Platform\FoodAlchemist\Support\Verweisziel::pruefeIds(FoodAlchemistTargetGroup::class, $team, array_diff($ids, $k->targetGroups()->allRelatedIds()->all()), 'Zielgruppe');
        $k->targetGroups()->sync(array_values(array_unique(array_map('intval', $ids))));
    }

    // ── Spec 19 E3.5: Zielgruppen-Vokabular (MCP-Lesefläche + Anlage) ──

    /**
     * Team-sichtbares Zielgruppen-Vokabular (eigenes Team + Master-Kette), sortiert.
     * Für `zielgruppen.GET` und die Bedarf-Sektion. Read-only.
     */
    public function zielgruppenListe(Team $team, bool $inklInaktiv = true): Collection
    {
        return FoodAlchemistTargetGroup::visibleToTeam($team)
            ->when(! $inklInaktiv, fn ($q) => $q->where('is_inactive', false))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Neue Zielgruppe anlegen (immer team-eigen). Dedup gegen das eigene Team
     * (unique(team_id,name) — ein Kind-Team darf einen Master-Namen bewusst
     * überschreiben). Wirft RuntimeException bei leerem/doppeltem Namen.
     */
    public function zielgruppeAnlegen(Team $team, array $in): FoodAlchemistTargetGroup
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name der Zielgruppe ist Pflicht.');
        }
        $doppelt = FoodAlchemistTargetGroup::where('team_id', $team->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($doppelt) {
            throw new \RuntimeException("Zielgruppe «{$name}» existiert bereits.");
        }

        return FoodAlchemistTargetGroup::create([
            'team_id' => $team->id,
            'name' => $name,
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'sort_order' => (int) ($in['sort_order'] ?? 100),
            'is_inactive' => false,
        ]);
    }

    /**
     * Kreative Leitplanken auflösen: die effektive Guideline für Generierung + Vorschläge.
     * Kaskade (spezifisch gewinnt): Kapitel/Konzept-Niveau (concept.level) → Foodbook-Default
     * → Segment (aus Küchen-Typ). Niveau kanonisiert (haute → haute_cuisine). Convenience:
     * Foodbook-Default → Segment. Kundentyp = Foodbook-Feld (kein Fallback). So kann ein
     * Foodbook basic/hochwertig/premium tragen (Niveau je Kapitel), mit Foodbook-Default als Boden.
     *
     * Spec 19 E3.4: DER Auflösungs-Punkt für Vorschläge, Kickoff, Canvas, Anlage-Stempel. Wird
     * ein Kapitel übergeben, kaskadieren die Dimensions-Keys Kapitel(+Eltern) → Foodbook → Segment
     * (Segment-Boden nur niveau/convenience). `zielgruppen` kaskadiert über die M1-Pivots
     * (Kapitel-Stempel schlägt Foodbook-Default). Eventtyp/Servierform/Einsatzmomente lösen
     * vorerst nur auf Foodbook-Ebene auf — die Kapitel-Overrides sind M3-Spalten (E4.1) und werden
     * dort in dieser Kaskade ergänzt. `quellen` protokolliert je Dimension die gewinnende Ebene.
     *
     * @return array{kundentyp: ?string, niveau: ?string, convenience: ?string, niveau_quelle: ?string,
     *     zielgruppen: list<array{id:int, name:string}>, event_type_id: ?int, serving_form_id: ?int,
     *     service_moment_ids: list<int>, quellen: array<string, ?string>}
     */
    public function leitplanken(Team $team, FoodAlchemistFoodbook $fb, ?FoodAlchemistConcept $concept = null, ?FoodAlchemistFoodbookKapitel $kapitel = null): array
    {
        $segment = app(TeamSettingsService::class)->segment($team);
        $kapitelNiveau = TeamSettingsService::normNiveau($concept?->level);

        $niveau = $kapitelNiveau ?? $fb->default_niveau ?? ($segment['niveau'] ?? null);
        $niveauQuelle = $kapitelNiveau !== null ? 'kapitel'
            : ($fb->default_niveau !== null ? 'foodbook'
            : (($segment['niveau'] ?? null) !== null ? 'segment' : null));

        // Zielgruppen: erstes Kapitel im Pfad (Kapitel → Eltern → …) mit eigener Stempelung
        // gewinnt, sonst Foodbook-Default.
        [$zielgruppen, $zgQuelle] = $this->zielgruppenKaskade($fb, $kapitel);

        // Eventtyp/Servierform/Einsatzmomente: vorerst Foodbook-Default (Kapitel-Override = M3/E4.1).
        $eventTypeId = $fb->default_event_type_id !== null ? (int) $fb->default_event_type_id : null;
        $servingFormId = $fb->default_serving_form_id !== null ? (int) $fb->default_serving_form_id : null;
        $serviceMomentIds = $fb->serviceMoments->map(fn ($m) => (int) $m->id)->values()->all();

        // Kreativ-Modus (E9.1): Kaskade Kapitel → Foodbook → Code-Default 'hybrid'.
        [$kreativModus, $kmQuelle] = $this->kreativModusRoh($fb, $kapitel);

        return [
            'kundentyp' => $fb->kundentyp,
            'niveau' => $niveau,
            'convenience' => $fb->default_convenience ?? ($segment['convenience'] ?? null),
            'niveau_quelle' => $niveauQuelle,
            'zielgruppen' => $zielgruppen,
            'event_type_id' => $eventTypeId,
            'serving_form_id' => $servingFormId,
            'service_moment_ids' => $serviceMomentIds,
            'creative_mode' => $kreativModus,
            'quellen' => [
                'niveau' => $niveauQuelle,
                'zielgruppen' => $zgQuelle,
                'event_type_id' => $eventTypeId !== null ? 'foodbook' : null,
                'serving_form_id' => $servingFormId !== null ? 'foodbook' : null,
                'service_moment_ids' => $serviceMomentIds !== [] ? 'foodbook' : null,
                'creative_mode' => $kmQuelle,
            ],
        ];
    }

    /**
     * Spec 19 E9.1: Kreativ-Modus-Kaskade (roh, ohne Team-Guard — für leitplanken()).
     * Kapitel.creative_mode → Foodbook.creative_mode_default → CREATIVE_MODE_DEFAULT ('hybrid').
     * Unbekannte/ungültige Werte fallen auf den Default zurück (weiche Prüfung, Vokabular-Pflicht).
     *
     * @return array{0: string, 1: string} [modus, quelle('kapitel'|'foodbook'|'default')]
     */
    private function kreativModusRoh(FoodAlchemistFoodbook $fb, ?FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $gueltig = FoodAlchemistFoodbookKapitel::CREATIVE_MODES;
        $kapModus = $kapitel?->creative_mode;
        if ($kapModus !== null && in_array($kapModus, $gueltig, true)) {
            return [$kapModus, 'kapitel'];
        }
        $fbModus = $fb->creative_mode_default;
        if ($fbModus !== null && in_array($fbModus, $gueltig, true)) {
            return [$fbModus, 'foodbook'];
        }

        return [FoodAlchemistFoodbookKapitel::CREATIVE_MODE_DEFAULT, 'default'];
    }

    /**
     * Spec 19 E9.1: aufgelöster Kreativ-Modus eines Kapitels (team-geguarded, öffentlich).
     * Der Auflösungs-Punkt für den Kreativ-Tab (E9.4), die Pairing-Inspiration (E9.2) und MCP.
     *
     * @return array{modus: string, quelle: string, optionen: list<string>}
     */
    public function kreativModus(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $kapitel = $this->basis->ownedKapitel($team, (int) $kapitel->id);
        [$modus, $quelle] = $this->kreativModusRoh($kapitel->foodbook, $kapitel);

        return ['modus' => $modus, 'quelle' => $quelle, 'optionen' => FoodAlchemistFoodbookKapitel::CREATIVE_MODES];
    }

    /**
     * Spec 19 E3.4: Zielgruppen-Kaskade. Läuft das Kapitel und seine Eltern hoch; das erste mit
     * eigener Stempelung gewinnt (Quelle 'kapitel'). Findet sich keine, greift der Foodbook-Default
     * ('foodbook'). Nirgends gesetzt ⇒ leer + null. Zyklus-Schutz via `$besucht` (Baum ist über
     * `moveKapitel` acyclisch, Guard aus Vorsicht).
     *
     * @return array{0: list<array{id:int, name:string}>, 1: ?string} [zielgruppen, quelle]
     */
    private function zielgruppenKaskade(FoodAlchemistFoodbook $fb, ?FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $node = $kapitel;
        $besucht = [];
        while ($node !== null && ! isset($besucht[(int) $node->id])) {
            $besucht[(int) $node->id] = true;
            $zg = $node->targetGroups->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])->values()->all();
            if ($zg !== []) {
                return [$zg, 'kapitel'];
            }
            $node = $node->parent;
        }
        $fbZg = $fb->targetGroups->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])->values()->all();

        return $fbZg !== [] ? [$fbZg, 'foodbook'] : [[], null];
    }

    /**
     * Spec 19 E4.2: aufgelöste SOLL-Sicht eines Kapitels — die Mengen-/Preis-/WE-Ziele mit
     * Vererbung **Kapitel → Eltern → Slot → Foodbook**. Pro Feld gewinnt der erste nicht-leere
     * Wert entlang der Kette; `quellen[<feld>]` nennt die Herkunfts-Ebene
     * ('kapitel'|'eltern'|'slot'|'foodbook'|null). Ergänzt `leitplanken()` (das die
     * Zielgruppen/Dimensionen liefert) um die kapitel-scoped SOLL-Ziele. DER SOLL-Punkt für
     * `pruefeKapitel` (E4.3), `wareneinsatzAmpel` (E4.4) und die Kapitel-Planung-Rail (E5.3).
     *
     * Feld-Ebenen: target_count/price_anchor/price_min/price_max kennen Slot-Fallback (der flache
     * Slot trug die Ziele vor der E4.1-Stempelung); niveau/serving_form_id/target_food_cost_pct
     * kennen Foodbook-Default; service_moment_id/pricing_mode nur die Kapitel-Kette (Foodbook führt
     * Einsatzmomente als 1–n-Pivot, kein Einzel-Default).
     *
     * @return array{
     *     target_count: ?int, price_anchor: ?float, price_min: ?float, price_max: ?float,
     *     niveau: ?string, serving_form_id: ?int, service_moment_id: ?int, pricing_mode: ?string,
     *     target_food_cost_pct: ?float, quellen: array<string, ?string>
     * }
     */
    public function kapitelZiele(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $kapitel = $this->basis->ownedKapitel($team, (int) $kapitel->id);
        $fb = $kapitel->foodbook;

        // Kapitel-Kette: self + Eltern hoch (Zyklus-Guard aus Vorsicht — moveKapitel hält den Baum acyclisch).
        $kette = [];
        $node = $kapitel;
        $besucht = [];
        while ($node !== null && ! isset($besucht[(int) $node->id])) {
            $besucht[(int) $node->id] = true;
            $kette[] = $node;
            $node = $node->parent;
        }

        // Ist-Bezug: der flache Planungs-Slot dieses Kapitels (Slot-Ziele als Fallback, falls das
        // Kapitel-Feld leer ist — z.B. nach manuellem Reset oder bei Kapiteln ohne Stempelung).
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::where('chapter_id', $kapitel->id)->first();

        $quellen = [];
        $gesetzt = static fn ($w): bool => $w !== null && $w !== '';
        $resolve = function (string $feld, $slotWert, $fbWert) use ($kette, $slot, $gesetzt, &$quellen) {
            foreach ($kette as $i => $k) {
                if ($gesetzt($k->{$feld})) {
                    $quellen[$feld] = $i === 0 ? 'kapitel' : 'eltern';

                    return $k->{$feld};
                }
            }
            if ($slot !== null && $gesetzt($slotWert)) {
                $quellen[$feld] = 'slot';

                return $slotWert;
            }
            if ($gesetzt($fbWert)) {
                $quellen[$feld] = 'foodbook';

                return $fbWert;
            }
            $quellen[$feld] = null;

            return null;
        };

        $targetCount = $resolve('target_count', $slot?->target_count, null);
        $priceAnchor = $resolve('price_anchor', $slot?->price_anchor, null);
        $priceMin = $resolve('price_min', $slot?->price_min, null);
        $priceMax = $resolve('price_max', $slot?->price_max, null);
        $niveau = $resolve('niveau', null, $fb?->default_niveau);
        $servingFormId = $resolve('serving_form_id', null, $fb?->default_serving_form_id);
        $serviceMomentId = $resolve('service_moment_id', null, null);
        $pricingMode = $resolve('pricing_mode', null, null);
        $targetFoodCostPct = $resolve('target_food_cost_pct', null, $fb?->target_food_cost_pct);

        return [
            'target_count' => $targetCount !== null ? (int) $targetCount : null,
            'price_anchor' => $priceAnchor !== null ? (float) $priceAnchor : null,
            'price_min' => $priceMin !== null ? (float) $priceMin : null,
            'price_max' => $priceMax !== null ? (float) $priceMax : null,
            'niveau' => $niveau !== null ? (string) $niveau : null,
            'serving_form_id' => $servingFormId !== null ? (int) $servingFormId : null,
            'service_moment_id' => $serviceMomentId !== null ? (int) $serviceMomentId : null,
            'pricing_mode' => $pricingMode !== null ? (string) $pricingMode : null,
            'target_food_cost_pct' => $targetFoodCostPct !== null ? (float) $targetFoodCostPct : null,
            'quellen' => $quellen,
        ];
    }

    // ── #369: CRM-Kunde-Link (MVP, nur verlinken) — class_exists-geschützt (Modul läuft ohne crm) ──


    public function crmVerfuegbar(): bool
    {
        return class_exists(\Platform\Crm\Services\CompanyLinkService::class);
    }

    public function sucheFirmen(string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || ! $this->crmVerfuegbar()) {
            return collect();
        }

        return \Platform\FoodAlchemist\Support\CrmKunden::firmen(\Platform\FoodAlchemist\Support\CrmKunden::aktuellesTeam(), $suche, $limit);
    }

    public function sucheKontakte(string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || ! class_exists(\Platform\Crm\Services\ContactLinkService::class)) {
            return collect();
        }

        return \Platform\FoodAlchemist\Support\CrmKunden::kontakte(\Platform\FoodAlchemist\Support\CrmKunden::aktuellesTeam(), $suche, $limit);
    }
}
