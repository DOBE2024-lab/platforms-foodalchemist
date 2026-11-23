<?php

namespace Platform\FoodAlchemist\Services\Gericht;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Ausgabe\SpeisekarteService;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;

/**
 * Gericht-Verwaltung (Plan „Verwaltung am Gericht“, Kurator/Dominique 23.11.2026): ein Gericht in allen aktiven
 * Verwendungen durch ein anderes ersetzen — mit Vorschau — und danach weich löschen.
 *
 * Grundsatz „überall ersetzen, danach einwandfrei löschen“: Nach einem vollständigen Tausch (inklusive Haken „auch aktive
 * Foodbooks“) ist das Löschen für das eigene Team frei. Sperren bleiben nur aktive Nutzung anderer Teams, eingefrorene
 * offene Angebote, offene Produktion und laufende Abos — jeweils mit Namen. Offene Angebote mit manuellem Preis werden
 * getauscht, der Preis bleibt (Hinweis „Preis manuell – bitte prüfen“).
 *
 * Geschrieben wird ausschließlich über die vorhandenen Schreibwege (fillSlot/setSlotDarreichung/setSlotWording,
 * RecipeService::ersetzeInVerwendungen, SpeiseplanService::ersetzeEintrag, SpeisekarteService::updatePosition,
 * OfferCompositionService::updateBlock, FoodbookService::updateBlock, ComponentEquivalentService), danach die üblichen
 * Neuberechnungen (Preis-Kaskade, Auto-Preis offener Angebote). Alles oder nichts: eine Transaktion.
 *
 * Darreichung (F2): gleiche Servierform beim neuen Gericht, sonst Standard (null). Eigenes Wording der Position wird
 * geleert (F3/F9) — es gehörte zum alten Gericht.
 */
final class GerichtTauschService
{
    public function __construct(private GerichtVerwendungen $verwendungen) {}

    /**
     * Vorschau — schreibt nichts. Angebots-Preise exakt per Probelauf in einer Transaktion mit Rollback (F4, ohne KI).
     *
     * @return array{von: array{id:int,name:string}, nach: array{id:int,name:string}, bereiche: array<string, array>, haken_noetig: bool}
     */
    public function vorschau(Team $team, int $vonId, int $nachId, bool $auchAktiveFoodbooks = false): array
    {
        [$von, $nach] = $this->paar($team, $vonId, $nachId);
        $alle = $this->verwendungen->alle($team, $vonId);
        $res = app(DarreichungResolver::class);
        $ekAlt = $res->ekProPortion($von);
        $ekNeu = $res->ekProPortion($nach);
        $vkAlt = $res->vkNetto($von);
        $vkNeu = $res->vkNetto($nach);

        $bereiche = [];
        foreach (GerichtVerwendungen::BEREICHE as $b) {
            $zeilen = array_values(array_filter($alle, fn ($v) => $v['bereich'] === $b));
            $getauscht = array_values(array_filter($zeilen, fn ($v) => $this->wirdGetauscht($v, $auchAktiveFoodbooks)));
            $bereiche[$b] = [
                'anzahl' => count($getauscht),
                'eintraege' => array_map(fn ($v) => ['id' => $v['id'], 'ort' => $v['ort'], 'ziel' => $v['ziel'] ?? null, 'hinweis' => $v['hinweis'],
                    'ek_alt' => $ekAlt, 'ek_neu' => $ekNeu, 'vk_alt' => $vkAlt, 'vk_neu' => $vkNeu], $getauscht),
                'bleibt' => array_map(fn ($v) => ['ort' => $v['ort'], 'ziel' => $v['ziel'] ?? null, 'grund' => $v['grund']],
                    array_values(array_filter($zeilen, fn ($v) => ! $this->wirdGetauscht($v, $auchAktiveFoodbooks) && $v['status'] !== 'historisch'))),
                'haken' => array_map(fn ($v) => ['ort' => $v['ort'], 'grund' => $v['grund']],
                    array_values(array_filter($zeilen, fn ($v) => $v['status'] === 'haken'))),
                'historisch' => count(array_filter($zeilen, fn ($v) => $v['status'] === 'historisch')),
            ];
        }
        $bereiche['angebote']['preise'] = $this->angebotsPreise($team, $von, $nach, $alle, $auchAktiveFoodbooks);

        return [
            'von' => ['id' => (int) $von->id, 'name' => (string) $von->name],
            'nach' => ['id' => (int) $nach->id, 'name' => (string) $nach->name],
            'bereiche' => $bereiche,
            'haken_noetig' => collect($alle)->contains(fn ($v) => $v['status'] === 'haken'),
            'ek_delta_je_portion' => $ekAlt !== null && $ekNeu !== null ? round($ekNeu - $ekAlt, 4) : null,
        ];
    }

    /**
     * Ersetzen — alles oder nichts. `$bereiche` = Auswahl aus GerichtVerwendungen::TAUSCH_BEREICHE (leer = alle).
     *
     * @return array{getauscht: array<string,int>, bleibt: list<array{ort:string,grund:?string}>, historisch:int, angebote_neu_berechnet:int}
     */
    public function ersetze(Team $team, int $vonId, int $nachId, array $bereiche = [], bool $auchAktiveFoodbooks = false): array
    {
        [$von, $nach] = $this->paar($team, $vonId, $nachId);
        $bereiche = $bereiche === [] ? GerichtVerwendungen::TAUSCH_BEREICHE : array_values(array_intersect($bereiche, GerichtVerwendungen::TAUSCH_BEREICHE));
        $alle = $this->verwendungen->alle($team, $vonId);

        $ergebnis = DB::transaction(function () use ($team, $von, $nach, $alle, $bereiche, $auchAktiveFoodbooks) {
            $erg = $this->tausche($team, $von, $nach, $alle, $bereiche, $auchAktiveFoodbooks);
            app(PricingCascadeService::class)->recomputeRecipes([(int) $nach->id, (int) $von->id]);   // Konzepte + Angebote am Konzept
            $angebote = app(AngebotService::class);
            foreach (FoodAlchemistAngebot::whereIn('id', $erg['angebote'])->get() as $a) {   // Kapitel-Blöcke hängen an keinem Konzept
                if ($this->verwendungen->istOffen($a)) {   // manueller Preis bleibt: aktualisiereAutoPreis schreibt dann nur die Kalkulation
                    $angebote->aktualisiereAutoPreis($team, $a);
                }
            }

            return $erg;
        });

        return [
            'getauscht' => $ergebnis['zaehler'],
            'bleibt' => array_values(array_map(fn ($v) => ['ort' => $v['ort'], 'grund' => $v['grund']],
                array_filter($alle, fn ($v) => $v['status'] !== 'historisch' && ! ($this->wirdGetauscht($v, $auchAktiveFoodbooks) && in_array($v['bereich'], $bereiche, true))))),
            'historisch' => count(array_filter($alle, fn ($v) => $v['status'] === 'historisch')),
            'angebote_neu_berechnet' => count($ergebnis['angebote']),
        ];
    }

    /** Lösch-Bilanz — siehe GerichtVerwendungen::loeschBilanz (dort, damit deleteDish keinen Dienst-Kreis zieht). */
    public function loeschBilanz(Team $team, int $gerichtId): array
    {
        return $this->verwendungen->loeschBilanz($team, $gerichtId);
    }

    // ── intern ─────────────────────────────────────────────────────────────────────────────────────────

    /** Gericht → Gericht (F1): beide sichtbar, Ziel ein nicht veraltetes Gericht und nicht die Quelle. */
    private function paar(Team $team, int $vonId, int $nachId): array
    {
        $von = FoodAlchemistRecipe::visibleToTeam($team)->find($vonId);
        $nach = FoodAlchemistRecipe::visibleToTeam($team)->find($nachId);
        if ($von === null || $nach === null) {
            throw new \RuntimeException('Quell- oder Ziel-Gericht nicht gefunden.');
        }
        if ((int) $von->id === (int) $nach->id) {
            throw new \RuntimeException('Quelle und Ziel sind identisch.');
        }
        if (! $nach->is_sales_recipe) {
            throw new \RuntimeException('Ersetzt wird nur durch ein Gericht.');
        }
        if ($nach->status === RecipeStatus::Deprecated) {
            throw new \RuntimeException('Ziel-Gericht ist „Veraltet“ — kein gültiges Ersetzungsziel.');
        }

        return [$von, $nach];
    }

    private function wirdGetauscht(array $v, bool $auchAktiveFoodbooks): bool
    {
        return $v['status'] === 'tausch' || ($v['status'] === 'haken' && $auchAktiveFoodbooks);
    }

    /**
     * Die eigentlichen Umhängungen. Gibt Zähler je Bereich und die betroffenen Angebots-IDs zurück.
     *
     * @return array{zaehler: array<string,int>, angebote: list<int>}
     */
    private function tausche(Team $team, FoodAlchemistRecipe $von, FoodAlchemistRecipe $nach, array $alle, array $bereiche, bool $auchAktiveFoodbooks): array
    {
        $zaehler = array_fill_keys(GerichtVerwendungen::TAUSCH_BEREICHE, 0);
        $angebote = [];
        $komponenten = false;
        foreach ($alle as $v) {
            if (! in_array($v['bereich'], $bereiche, true) || ! $this->wirdGetauscht($v, $auchAktiveFoodbooks)) {
                continue;
            }
            $m = $v['meta'];
            $form = $this->gleicheForm($m['presentation_id'] ?? null, (int) $nach->id);
            switch ($v['bereich']) {
                case 'konzepte':
                    $this->slot($team, $v['id'], $nach, $m, $form);
                    break;
                case 'angebote':
                    if (($m['art'] ?? '') === 'slot') {
                        $this->slot($team, $v['id'], $nach, $m, $form);
                    } else {
                        app(OfferCompositionService::class)->updateBlock($team, $v['id'], ['sales_recipe_id' => (int) $nach->id, 'presentation_id' => $form, 'wording' => null]);
                    }
                    $angebote[] = (int) $m['offer_id'];
                    break;
                case 'komponenten':
                    $komponenten = true;   // einmal für alle eigenen Eltern (Zyklus-Prüfung, Neuberechnung)
                    break;
                case 'speiseplan':
                    app(SpeiseplanService::class)->ersetzeEintrag($team, $v['id'], ['sales_recipe_id' => (int) $nach->id, 'presentation_id' => $form]);
                    break;
                case 'speisekarte':
                    app(SpeisekarteService::class)->updatePosition($team, $v['id'], ['sales_recipe_id' => (int) $nach->id, 'presentation_id' => $form, 'wording' => null]);
                    break;
                case 'foodbooks':
                    app(FoodbookService::class)->updateBlock($team, $v['id'], ['sales_recipe_id' => (int) $nach->id, 'wording' => null]);
                    $this->foodbookDarreichung($team, $v['id'], $form);
                    break;
                case 'ersatz':
                    $this->ersatz($team, $v['id'], (int) $von->id, (int) $nach->id);
                    break;
            }
            $zaehler[$v['bereich']]++;
        }
        if ($komponenten) {
            app(RecipeService::class)->ersetzeInVerwendungen($team, (int) $von->id, (int) $nach->id);
        }

        return ['zaehler' => $zaehler, 'angebote' => array_values(array_unique($angebote))];
    }

    /** Konzept-/Paket-Position: Menge und Einheit bleiben, Darreichung nach Form, Wording geleert. */
    private function slot(Team $team, int $slotId, FoodAlchemistRecipe $nach, array $m, ?int $form): void
    {
        $concepts = app(ConceptService::class);
        $concepts->fillSlot($team, $slotId, ['sales_recipe_id' => (int) $nach->id, 'quantity' => $m['quantity'] ?? null, 'unit_vocab_id' => $m['unit_vocab_id'] ?? null]);
        $concepts->setSlotDarreichung($team, $slotId, $form);
        if (($m['wording'] ?? null) !== null) {
            $concepts->setSlotWording($team, $slotId, null);
        }
    }

    /**
     * Foodbook-Block: presentation_id steht bewusst nicht in FoodbookService::BLOCK_FELDER (Formular). Dieselbe Regel wie
     * ConceptService::setSlotDarreichung: eigenes Foodbook, Form gehört zum neuen Gericht.
     */
    private function foodbookDarreichung(Team $team, int $blockId, ?int $form): void
    {
        $block = FoodAlchemistFoodbookBlock::visibleToTeam($team)->findOrFail($blockId);
        if (! $block->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook: Ändern kann es nur das Besitzer-Team.');
        }
        if ($form !== null && ! FoodAlchemistRecipeDarreichung::whereKey($form)->where('recipe_id', $block->sales_recipe_id)->exists()) {
            throw new \RuntimeException('Darreichung gehört nicht zum Gericht dieses Blocks.');
        }
        $block->update(['presentation_id' => $form]);
    }

    /** Ersatz-Verknüpfung: dieselbe Verknüpfung mit dem neuen Gericht neu anlegen, die alte lösen. */
    private function ersatz(Team $team, int $equivId, int $vonId, int $nachId): void
    {
        $e = FoodAlchemistComponentEquivalent::findOrFail($equivId);
        $svc = app(ComponentEquivalentService::class);
        $recipe = FoodAlchemistComponentEquivalent::KIND_RECIPE;
        $quelle = [$e->source_kind, $e->source_kind === $recipe && (int) $e->source_id === $vonId ? $nachId : (int) $e->source_id];
        $alt = [$e->alt_kind, $e->alt_kind === $recipe && (int) $e->alt_id === $vonId ? $nachId : (int) $e->alt_id];
        if ($quelle !== $alt) {   // nach ↔ nach wäre ein eigener Ersatz — dann nur lösen
            $svc->verknuepfe($team, $quelle[0], $quelle[1], $alt[0], $alt[1], (float) $e->umrechnungsfaktor, (string) $e->standard_seite, $e->notes,
                $e->match_confidence !== null ? (float) $e->match_confidence : null);
        }
        $svc->loese($team, (int) $e->id);
    }

    /** F2: gleiche Servierform beim neuen Gericht, sonst null (= Standard). Ohne explizite Form bleibt es beim Standard. */
    private function gleicheForm(?int $altePresentationId, int $nachId): ?int
    {
        if ($altePresentationId === null) {
            return null;
        }
        $form = FoodAlchemistRecipeDarreichung::find($altePresentationId)?->serving_form_id;
        if ($form === null) {
            return null;
        }
        $neu = FoodAlchemistRecipeDarreichung::where('recipe_id', $nachId)->where('serving_form_id', $form)->orderByDesc('is_standard')->value('id');

        return $neu !== null ? (int) $neu : null;
    }

    /**
     * F4: Preis vorher/nachher je offenem Angebot, exakt: Konzepte und Angebote probeweise tauschen, kalkulieren, zurückrollen.
     *
     * @return list<array{id:int, name:string, preis_vorher:?float, preis_nachher:?float, preis_manuell:bool}>
     */
    private function angebotsPreise(Team $team, FoodAlchemistRecipe $von, FoodAlchemistRecipe $nach, array $alle, bool $auchAktiveFoodbooks): array
    {
        $kandidaten = collect($alle)->filter(fn ($v) => in_array($v['bereich'], ['angebote', 'konzepte'], true) && $this->wirdGetauscht($v, $auchAktiveFoodbooks));
        if ($kandidaten->isEmpty()) {
            return [];
        }
        $direkt = $kandidaten->where('bereich', 'angebote')->pluck('meta.offer_id');
        $ueberKonzept = DB::table('foodalchemist_offer_concept')->whereIn('concept_id', $kandidaten->where('bereich', 'konzepte')->pluck('meta.concept_id'))->pluck('offer_id');
        $angebote = FoodAlchemistAngebot::whereIn('id', $direkt->merge($ueberKonzept)->unique())->where('team_id', $team->id)->get()
            ->filter(fn ($a) => $this->verwendungen->istOffen($a))->values();
        if ($angebote->isEmpty()) {
            return [];
        }
        $svc = app(AngebotService::class);
        $vorher = $angebote->mapWithKeys(fn ($a) => [$a->id => $this->autoGesamt($svc, $team, $a)]);

        DB::beginTransaction();
        try {
            $this->tausche($team, $von, $nach, $alle, ['angebote', 'konzepte'], $auchAktiveFoodbooks);
            $nachher = $angebote->mapWithKeys(fn ($a) => [$a->id => $this->autoGesamt($svc, $team, $a->fresh())]);
        } finally {
            DB::rollBack();
        }

        return $angebote->map(fn ($a) => ['id' => (int) $a->id, 'name' => (string) $a->name,
            'preis_vorher' => $vorher[$a->id], 'preis_nachher' => $nachher[$a->id] ?? null,
            'preis_manuell' => ($a->price_mode ?? 'auto') !== 'auto'])->all();   // manueller Preis bleibt — nur Kalkulation zum Prüfen
    }

    private function autoGesamt(AngebotService $svc, Team $team, FoodAlchemistAngebot $a): ?float
    {
        $k = $svc->kalkulation($team, $a);

        return isset($k['auto_gesamt']) ? (float) $k['auto_gesamt'] : null;
    }
}
