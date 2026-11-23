<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Services\Concerns\PruefstOutletZuordnung;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;

/**
 * M11-02 / Doc 15 §9.3 + D-8: Foodbook-Service — Mappe + Kapitel-BAUM + Blöcke.
 *
 * Preis-Modell: jeder Block liefert einen Per-Person-Preis (concept_ref = Concept-
 * €/Person [person-unabhängig], recipe_ref = sales_net × Menge). Ein Kapitel summiert
 * rekursiv über Blöcke + Unterkapitel (`kapitelAggregat`). Der **Gesamtpreis** =
 * Σ Top-Kapitel × **Pax am Foodbook** (F-12, D-CON-5) — erst hier wird die
 * Gästezahl bindend, nicht am Concept.
 *
 * Scope-Härte: visibleToTeam in JEDER Query; Schreiben nur durchs Besitzer-Team.
 */
class FoodbookService implements Foodbook\FoodbookDokumentQuelle, Foodbook\FoodbookAggregatQuelle
{
    use PruefstOutletZuordnung;

    public function __construct(
        private ConceptService $concepts,
        private Foodbook\FoodbookBasis $basis,
        private Foodbook\FoodbookKapitelBild $kapitelBild,
        private Foodbook\FoodbookVokabular $vokabular,
        private Foodbook\FoodbookKundentext $kundentext,
        private Foodbook\FoodbookAggregat $aggregat,
        private Foodbook\FoodbookDokument $dokument,
    ) {}

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        // Große JSON-Spalten NICHT in die sortierte Listen-Query — sonst „Out of sort memory" im filesort (Spec 43-Regression).
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->select($this->browserSpalten('foodalchemist_foodbooks'))
            ->with('crmCompany')
            ->withCount('chapters')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(label) LIKE ?', [$s])
                    ->orWhereHas('crmCompany', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$s]))
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['phase'] ?? '') !== '', fn ($q) => $q->where('phase', $filters['phase'])) // R4.3
            ->orderByDesc('jahr')->orderBy('label')
            ->paginate($perPage);
    }

    /**
     * Spalten für sortierte Listen-Queries OHNE die großen JSON-Blobs (gegen MySQL-„Out of sort memory"). Statisch gecacht je Tabelle.
     *
     * @return list<string>
     */
    private function browserSpalten(string $table): array
    {
        static $cache = [];
        if (! isset($cache[$table])) {
            $exclude = ['presentation_snapshot_json', 'presentation_settings_json', 'preview_snapshot_json'];
            $all = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            $cols = array_values(array_diff($all, $exclude));
            $cache[$table] = $cols !== [] ? array_map(fn ($c) => $table . '.' . $c, $cols) : [$table . '.*'];
        }

        return $cache[$table];
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFoodbook
    {
        return $this->basis->detail($team, $id);   // 98 · 3.3 S4-3: Lesemodell in Foodbook\FoodbookBasis (Kundentext liest es)
    }

    // ── Foodbook ────────────────────────────────────────────────────────────

    private const FELDER = ['code', 'label', 'jahr', 'gueltig_von', 'gueltig_bis', 'outlet_id', 'personen', 'status', 'description', 'note', 'crm_company_id', 'crm_contact_id', 'writing_style_id', 'kundentyp', 'default_niveau', 'default_convenience', 'default_event_type_id', 'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp', 'creative_mode_default'];

    public function create(Team $team, array $in): FoodAlchemistFoodbook
    {
        // Spec 33 P2: fremde Betriebe fallen hier raus — `outlet_id` (Team-Vokabular) erfasst der Datensatz-Guard nicht.
        $in = $this->pruefeOutlet($team, $in);
        \Platform\FoodAlchemist\Support\Verweisziel::pruefeFelder($team, $in);   // CQ 2.3f: Vokabular + CRM

        return FoodAlchemistFoodbook::create([
            'team_id' => $team->id,
            'label' => trim((string) ($in['label'] ?? 'Neues Foodbook')) ?: 'Neues Foodbook',
            // Spec 33 P2: der Betrieb am Foodbook-KOPF (das Kapitel-Outlet bleibt ein Tag).
            'outlet_id' => $in['outlet_id'] ?? null,
            'crm_company_id' => $in['crm_company_id'] ?? null,
            'crm_contact_id' => $in['crm_contact_id'] ?? null,
            'projekt_id' => $in['projekt_id'] ?? null,   // Spec 82 §9: nur über ProjektService (Gate am Modell)
            // Spec 33 P1: Fenster schon beim Anlegen — sonst verliert ein Import/MCP-Aufruf die Angabe stillschweigend.
            'gueltig_von' => ($in['gueltig_von'] ?? '') !== '' ? $in['gueltig_von'] : null,
            'gueltig_bis' => ($in['gueltig_bis'] ?? '') !== '' ? $in['gueltig_bis'] : null,
            'jahr' => $in['jahr'] ?? null,
            'personen' => $in['personen'] ?? null,
            'status' => AusgabeStatus::normalisiere($in['status'] ?? null)->value,
            'description' => $in['description'] ?? null,
            'creative_mode_default' => $in['creative_mode_default'] ?? null,   // Spec 19 E9.1
        ]);
    }

    /**
     * Spec 33: eingehende Werte auf das gültige Format bringen — eine Regel, ein Ort, und
     * damit auch für MCP-Aufrufe gültig, nicht nur für das Formular.
     *
     * - Status durch den Enum (P0): `status` steht in FELDER und war ohne das mit jedem
     *   beliebigen String beschreibbar — so kam ein `final` in den Bestand.
     * - Leere Datumsfelder zu NULL (P1): das Formular liefert `''` für ein nicht gesetztes
     *   Datum, und MySQL nimmt das in einer DATE-Spalte im Strict Mode nicht an.
     */
    private function normalisiereFelder(array $update): array
    {
        if (array_key_exists('status', $update)) {
            $update['status'] = AusgabeStatus::normalisiere((string) $update['status'])->value;
        }

        foreach (['gueltig_von', 'gueltig_bis'] as $datum) {
            if (array_key_exists($datum, $update) && ($update[$datum] === '' || $update[$datum] === false)) {
                $update[$datum] = null;
            }
        }

        return $update;
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        \Platform\FoodAlchemist\Support\Verweisziel::pruefeFelder($team, $in, $fb);
        $fb->update($this->normalisiereFelder(
            $this->pruefeOutlet($team, array_intersect_key($in, array_flip(self::FELDER))),
        ));

        return $fb->refresh();
    }

    /**
     * Tiefe Kopie eines Foodbooks: Kopf (inkl. Branding) → Kapitel (2-Pass parent_id) → Blöcke → Staffeln.
     * Zurückgesetzt: code, Status=Entwurf, alle presentation- und preview- und Kapitel-Snapshot-Felder
     * (Freigabe/Versand sind pro Dokument). Auch geerbte Foodbooks (nur sichtbar): die Kopie gehört ganz dem kopierenden Team.
     */
    public function dupliziere(Team $team, int $id): FoodAlchemistFoodbook
    {
        $quelle = FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['chapters' => fn ($q) => $q->orderBy('position'), 'chapters.blocks' => fn ($q) => $q->orderBy('position'), 'chapters.blocks.staffel'])
            ->findOrFail($id);

        // Kopf: FELDER + Branding; Name = label (NICHT name). Bilder kopiert DuplikatBilderService als eigene Dateien.
        $kopfFelder = array_merge(self::FELDER, ['brand_color', 'band_color', 'footer_text']);

        return DB::transaction(function () use ($quelle, $team, $kopfFelder) {
            $neu = FoodAlchemistFoodbook::create($this->pruefeOutlet($team, array_merge(
                array_intersect_key($quelle->only($kopfFelder), array_flip($kopfFelder)),
                ['team_id' => $team->id, 'label' => $quelle->label . ' (Kopie)', 'status' => AusgabeStatus::Entwurf->value, 'code' => null],
            )));

            // Kapitel flach kopieren (parent_id im 2. Pass), dann Blöcke + Staffeln.
            $map = [];
            foreach ($quelle->chapters as $k) {
                $kopie = FoodAlchemistFoodbookKapitel::create(array_merge(
                    array_intersect_key($k->only(self::KAPITEL_FELDER), array_flip(self::KAPITEL_FELDER)),
                    [
                        'team_id' => $neu->team_id, 'foodbook_id' => $neu->id, 'parent_id' => null,
                        'position' => $k->position, 'status' => 'draft',
                        'format_id' => $k->format_id,   // Format-Bindung mitkopieren (Snapshot/Freigabe bewusst NICHT; Bilder s. u.)
                    ],
                ));
                $map[$k->id] = $kopie->id;
            }
            foreach ($quelle->chapters as $k) {
                if ($k->parent_id !== null && isset($map[$k->parent_id])) {
                    FoodAlchemistFoodbookKapitel::whereKey($map[$k->id])->update(['parent_id' => $map[$k->parent_id]]);
                }
                foreach ($k->blocks as $b) {
                    $blk = FoodAlchemistFoodbookBlock::create(array_merge(
                        array_intersect_key($b->only(self::BLOCK_FELDER), array_flip(self::BLOCK_FELDER)),
                        // presentation_id fehlt bewusst in BLOCK_FELDER → für die Kopie explizit mitnehmen.
                        ['team_id' => $neu->team_id, 'chapter_id' => $map[$k->id], 'position' => $b->position, 'presentation_id' => $b->presentation_id],
                    ));
                    foreach ($b->staffel as $st) {
                        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlockStaffel::create([
                            'team_id' => $neu->team_id, 'block_id' => $blk->id,
                            'position' => $st->position, 'min_persons' => $st->min_persons, 'price' => $st->price,
                        ]);
                    }
                }
            }
            app(DuplikatBilderService::class)->foodbook($quelle, $neu, $map, $team);   // CQ Bild-Löschschutz PR 2: eigene Dateien

            return $neu->refresh();
        });
    }

    // ── Spec 19 E3.3/E3.5 + #369: Zielgruppen-Vokabular, Default-Dimensionen, Leitplanken, CRM-Suche —
    //    98 · 3.3 S4-2: in Foodbook\FoodbookVokabular, hier Fassade ──

    public function toggleEinsatzmoment(Team $team, int $fbId, int $momentId): void
    {
        $this->vokabular->toggleEinsatzmoment($team, $fbId, $momentId);
    }

    public function toggleZielgruppe(Team $team, int $fbId, int $targetGroupId): void
    {
        $this->vokabular->toggleZielgruppe($team, $fbId, $targetGroupId);
    }

    public function setKapitelZielgruppen(Team $team, int $kapitelId, array $ids): void
    {
        $this->vokabular->setKapitelZielgruppen($team, $kapitelId, $ids);
    }

    public function zielgruppenListe(Team $team, bool $inklInaktiv = true): Collection
    {
        return $this->vokabular->zielgruppenListe($team, $inklInaktiv);
    }

    public function zielgruppeAnlegen(Team $team, array $in): FoodAlchemistTargetGroup
    {
        return $this->vokabular->zielgruppeAnlegen($team, $in);
    }

    public function leitplanken(Team $team, FoodAlchemistFoodbook $fb, ?FoodAlchemistConcept $concept = null, ?FoodAlchemistFoodbookKapitel $kapitel = null): array
    {
        return $this->vokabular->leitplanken($team, $fb, $concept, $kapitel);
    }

    public function kreativModus(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        return $this->vokabular->kreativModus($team, $kapitel);
    }

    public function kapitelZiele(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        return $this->vokabular->kapitelZiele($team, $kapitel);
    }

    public function crmVerfuegbar(): bool
    {
        return $this->vokabular->crmVerfuegbar();
    }

    public function sucheFirmen(string $suche, int $limit = 10): Collection
    {
        return $this->vokabular->sucheFirmen($suche, $limit);
    }

    public function sucheKontakte(string $suche, int $limit = 10): Collection
    {
        return $this->vokabular->sucheKontakte($suche, $limit);
    }

    public function verknuepfeKunde(Team $team, int $id, ?int $companyId, ?int $contactId): FoodAlchemistFoodbook
    {
        \Platform\FoodAlchemist\Support\CrmKunden::pruefe($team, $companyId, $contactId);
        return $this->update($team, $id, ['crm_company_id' => $companyId, 'crm_contact_id' => $contactId]);
    }

    public function delete(Team $team, int $id): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        $fb->delete();
    }

    // ── Kapitel-Baum ──────────────────────────────────────────────────────────

    /** @return list<array{id:int, titel:string, parent_id:?int, depth:int}> Pre-Order */
    public function kapitelTree(Team $team, int $foodbookId): array
    {
        $alle = FoodAlchemistFoodbookKapitel::visibleToTeam($team)
            ->where('foodbook_id', $foodbookId)->orderBy('position')->get(['id', 'title', 'parent_id']);
        $byParent = $alle->groupBy(fn ($k) => $k->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $out[] = ['id' => (int) $k->id, 'title' => $k->title, 'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null, 'depth' => $depth];
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function addKapitel(Team $team, int $foodbookId, array $in = [], ?int $parentId = null): FoodAlchemistFoodbookKapitel
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        if ($parentId !== null && ! FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu diesem Foodbook.');
        }

        return FoodAlchemistFoodbookKapitel::create([
            'team_id' => $fb->team_id, 'foodbook_id' => $fb->id, 'parent_id' => $parentId ?: null,
            'title' => trim((string) ($in['title'] ?? 'Neues Kapitel')) ?: 'Neues Kapitel',
            'price_mode' => $in['price_mode'] ?? 'auto',
            'position' => (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    /**
     * Phase 3a: „Struktur anwenden" — die Planungs-Gerüst-Slots als Kapitel des Foodbooks
     * materialisieren (Slot = Kapitel, Dominiques Kopplung). Je Slot ohne (gültige) chapter_id
     * ein Kapitel anlegen (Titel = Slot-Label) + slot.chapter_id setzen. **Idempotent**: bereits
     * verknüpfte Slots werden übersprungen; ein Slot, dessen Kapitel gelöscht wurde, wird neu
     * angelegt. Danach matcht CoverageService robust per chapter_id (nicht mehr Label-fragil).
     *
     * @return array{kein_geruest: bool, angelegt: int, uebersprungen: int, protokoll: list<array{slot:string, status:string, chapter_id:?int, ziele_uebernommen?:list<string>}>}
     */
    public function strukturAusGeruest(Team $team, int $foodbookId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        $frames = app(PlanningFrameService::class);
        $frame = $frames->find('foodbook', $foodbookId);
        if ($frame === null || (int) $frame->slots()->count() === 0) {
            return ['kein_geruest' => true, 'angelegt' => 0, 'uebersprungen' => 0, 'protokoll' => []];
        }
        $vorhandene = array_map('intval', $fb->chapters()->pluck('id')->all());
        $angelegt = 0;
        $uebersprungen = 0;
        $protokoll = [];
        foreach ($frame->slots()->orderBy('position')->get() as $slot) {
            if ($slot->istStruktur()) {
                continue;   // Spec 80 F: Struktur wird kein Kapitel, sondern Block im folgenden Kapitel (Kaskade)
            }
            if ($slot->chapter_id !== null && in_array((int) $slot->chapter_id, $vorhandene, true)) {
                $uebersprungen++;
                $protokoll[] = ['slot' => $slot->label, 'status' => 'vorhanden', 'chapter_id' => (int) $slot->chapter_id];

                continue;
            }
            $kapitel = $this->addKapitel($team, $foodbookId, ['title' => $slot->label]);
            // Spec 19 E4.1: Slot-Ziele einmalig aufs neue Kapitel stempeln (die Ziele
            // wandern vom flachen Slot ans Kapitel). Nur gesetzte Slot-Felder übernehmen.
            $ziele = array_filter([
                'target_count' => $slot->target_count,
                'price_anchor' => $slot->price_anchor,
                'price_min' => $slot->price_min,
                'price_max' => $slot->price_max,
            ], fn ($v) => $v !== null);
            $uebernommen = [];
            if ($ziele !== []) {
                $kapitel->update($ziele);
                $uebernommen = array_keys($ziele);
            }
            $frames->updateSlot($team, $slot->id, ['chapter_id' => $kapitel->id]);
            $vorhandene[] = (int) $kapitel->id;
            $angelegt++;
            $protokoll[] = ['slot' => $slot->label, 'status' => 'angelegt', 'chapter_id' => (int) $kapitel->id, 'ziele_uebernommen' => $uebernommen];
        }

        return ['kein_geruest' => false, 'angelegt' => $angelegt, 'uebersprungen' => $uebersprungen, 'protokoll' => $protokoll];
    }

    /**
     * Spec 19 E4.5: Backfill — stempelt Slot-Ziele auf BESTEHENDE Slot↔Kapitel-Kopplungen,
     * die vor E4.1 entstanden sind (`strukturAusGeruest` stempelt nur bei NEU-Anlage; Slots,
     * die schon vor E4.1 ein Kapitel hatten, tragen ihre Ziele nie ans Kapitel weiter). Für
     * jeden Slot mit gesetztem chapter_id (Kapitel team-eigen) werden die SOLL-Felder
     * target_count/price_anchor/price_min/price_max übernommen — aber NUR die, die am Kapitel
     * noch NULL sind (bereits gesetzte Kapitel-Ziele bleiben unangetastet). Damit **idempotent**:
     * ein zweiter Lauf findet alles gefüllt und schreibt nichts. $apply=false = Dry-Run (nur
     * Protokoll, kein Write). Team-scoped über `visibleToTeam` + `isOwnedBy` (nur eigene Kapitel).
     *
     * @return array{slots_geprueft:int, kapitel_gestempelt:int, felder_gesetzt:int, protokoll: list<array{chapter_id:int, slot:string, felder:list<string>}>}
     */
    public function backfillSlotZiele(Team $team, ?int $foodbookId = null, bool $apply = false): array
    {
        $felder = ['target_count', 'price_anchor', 'price_min', 'price_max'];

        $kapitelQuery = FoodAlchemistFoodbookKapitel::visibleToTeam($team);
        if ($foodbookId !== null) {
            $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
            $this->guard($fb, $team);
            $kapitelQuery->where('foodbook_id', $foodbookId);
        }
        $kapitel = $kapitelQuery->get()
            ->filter(fn (FoodAlchemistFoodbookKapitel $k) => $k->isOwnedBy($team))
            ->keyBy('id');

        $slotsGeprueft = 0;
        $gestempelt = 0;
        $felderGesetzt = 0;
        $protokoll = [];

        if ($kapitel->isEmpty()) {
            return ['slots_geprueft' => 0, 'kapitel_gestempelt' => 0, 'felder_gesetzt' => 0, 'protokoll' => []];
        }

        $slots = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::whereIn('chapter_id', $kapitel->keys()->all())
            ->orderBy('position')->get();

        foreach ($slots as $slot) {
            $k = $kapitel->get((int) $slot->chapter_id);
            if ($k === null) {
                continue;
            }
            $slotsGeprueft++;
            $ziele = [];
            foreach ($felder as $feld) {
                if ($k->{$feld} === null && $slot->{$feld} !== null) {
                    $ziele[$feld] = $slot->{$feld};
                }
            }
            if ($ziele === []) {
                continue;
            }
            if ($apply) {
                $k->update($ziele);
            }
            $gestempelt++;
            $felderGesetzt += count($ziele);
            $protokoll[] = ['chapter_id' => (int) $k->id, 'slot' => (string) $slot->label, 'felder' => array_keys($ziele)];
        }

        return ['slots_geprueft' => $slotsGeprueft, 'kapitel_gestempelt' => $gestempelt, 'felder_gesetzt' => $felderGesetzt, 'protokoll' => $protokoll];
    }

    /**
     * Phase 3 (Weg B): ein vorgeschlagenes Gericht in den Slot ÜBERNEHMEN. Doktrin-treu —
     * das Slot-Kapitel trägt EIN Konzept (concept_ref); übernommene Gerichte werden dessen
     * Konzept-Slots. Erstes Übernehmen legt das Draft-Konzept + den concept_ref-Block an,
     * weitere hängen an. Duplikate werden übersprungen. Setzt „Struktur anwenden" voraus.
     *
     * Spec 19 E7.2: dünner, BIT-IDENTISCHER Wrapper um `uebernehmeGericht` — löst nur
     * Slot→Kapitel auf und delegiert mit $conceptId=null (= heutiges „führendes Kapitel-Konzept"-
     * Verhalten). fb-Guard-Reihenfolge bleibt (fb → guard → slot) für unveränderte Exceptions.
     *
     * @return array{concept_id:int, chapter_id:int, schon_drin:bool}
     */
    public function uebernehmeVorschlag(Team $team, int $foodbookId, int $slotId, int $recipeId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::findOrFail($slotId);
        if ($slot->chapter_id === null || (int) $this->ownedKapitel($team, (int) $slot->chapter_id)->foodbook_id !== $foodbookId) {   // CQ 2.3f
            throw new \RuntimeException('Dieser Platz ist noch kein Kapitel. Zuerst „Struktur anwenden“.');
        }

        return $this->uebernehmeGericht($team, $foodbookId, (int) $slot->chapter_id, $recipeId, $slot->label, 'foodbook_slot');
    }

    /**
     * Spec 19 E7.2 — KERN der Gericht-Übernahme (aus `uebernehmeVorschlag` extrahiert). Nimmt ein
     * VK-Gericht in ein Kapitel-Konzept auf:
     *  - $conceptId = null  → heutiges Verhalten: führendes Kapitel-Konzept (concept_ref) finden ODER
     *    neu anlegen (Draft, Niveau via leitplanken, created_via = $createdVia) + concept_ref-Block.
     *  - $conceptId gesetzt → Gericht gezielt in DIESES Konzept (E7.3 Paket-Weg). Ownership guardet
     *    `ConceptService::addSlot` selbst (visibleToTeam + guardOwner).
     * Dedup ist kapitelweit (Konzept-Slots ∪ recipe_ref-Blöcke) — quer-Kapitel bleibt WEICH (kapitelFreigeben).
     *
     * @return array{concept_id:int, chapter_id:int, schon_drin:bool}
     */
    public function uebernehmeGericht(
        Team $team,
        int $foodbookId,
        int $chapterId,
        int $recipeId,
        ?string $rolle = null,
        string $createdVia = 'foodbook_slot',
        ?int $conceptId = null
    ): array {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        $kapitel = $this->ownedKapitel($team, $chapterId);
        throw_if((int) $kapitel->foodbook_id !== $foodbookId, \RuntimeException::class, 'Kapitel gehört nicht zu diesem Foodbook.');   // CQ 2.3f

        // Spec 19 E1.5: kapitelweite Dedup VOR jeder Anlage. Das Gericht gilt als „schon drin",
        // wenn es Slot IRGENDEINES Konzepts (concept_ref) ODER ein direkter recipe_ref-Block im
        // Kapitel ist (Union beider Wege — der alte Check sah nur EIN Konzept). Treffer ⇒ nichts
        // anlegen (auch kein leeres Konzept); concept_id = führendes Kapitel-Konzept oder 0.
        if ($this->gerichtImKapitel($kapitel, $recipeId)) {
            $vorhanden = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->orderBy('position')->first();

            return ['concept_id' => (int) ($vorhanden->concept_id ?? 0), 'chapter_id' => $chapterId, 'schon_drin' => true];
        }

        if ($conceptId !== null) {
            // Gezieltes Ziel-Konzept (E7.3): kein concept_ref-Block anlegen — den setzt der Aufrufer.
            $zielConceptId = $conceptId;
        } else {
            $block = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->orderBy('position')->first();
            if ($block === null) {
                // Leitstelle: das neue Kapitel-Konzept erbt das Foodbook-Niveau (concept.level, im Concepter-
                // Vokabular). Kapitel kann es dort überschreiben (basic/hochwertig/premium). null = erbt weiter.
                $niveau = \Platform\FoodAlchemist\Services\TeamSettingsService::denormNiveauFuerConcept($this->leitplanken($team, $fb)['niveau']);
                $concept = $this->concepts->create($team, array_filter([
                    'name' => trim((string) ($rolle ?: $kapitel->title)) ?: 'Konzept',
                    'status' => 'draft',
                    'level' => $niveau,
                ], fn ($v) => $v !== null));
                $concept->update(['created_via' => $createdVia]);
                $this->addBlock($team, $chapterId, ['type' => 'concept_ref', 'concept_id' => $concept->id]);
                $zielConceptId = (int) $concept->id;
            } else {
                $zielConceptId = (int) $block->concept_id;
            }
        }

        $cslot = $this->concepts->addSlot($team, $zielConceptId, ['role' => $rolle]);
        $this->concepts->fillSlot($team, $cslot->id, ['sales_recipe_id' => $recipeId]);

        return ['concept_id' => $zielConceptId, 'chapter_id' => $chapterId, 'schon_drin' => false];
    }

    /**
     * Kapitelweite Dedup-Prüfung (Spec 19 E1.5): steckt das VK-Gericht schon im Kapitel —
     * als Slot IRGENDEINES per concept_ref hängenden Konzepts ODER als direkter recipe_ref-Block?
     * Union über beide Anlage-Wege (Paket-Konzept + Einzel-Gericht). Nur Kapitel-lokal; die
     * quer-Kapitel-Meldung ist WEICH und bleibt `uebernehmeGericht` (E7.2) vorbehalten.
     */
    private function gerichtImKapitel(FoodAlchemistFoodbookKapitel $kapitel, int $recipeId): bool
    {
        if ($kapitel->blocks()->where('type', 'recipe_ref')->where('sales_recipe_id', $recipeId)->exists()) {
            return true;
        }
        $conceptIds = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->pluck('concept_id');
        if ($conceptIds->isEmpty()) {
            return false;
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::whereIn('concept_id', $conceptIds)
            ->where('sales_recipe_id', $recipeId)->exists();
    }

    /**
     * Spec 19 E7.4 — Freitext-Queue-Prozessor (L7/L8). Erdet EINE queued Freitext-Skizze
     * in ein echtes VK-Gericht. Der `RecipeGeneratorService` (vkModus) IST die
     * Anpassungs-Schleife: er groundet die freie Idee gegen den verfügbaren Bestand
     * (kandidatenPool/#507) bzw. mintet LA-First eine tentative GP (Spec §„Erst kreativ,
     * dann erden"). Das erzeugte Gericht wird materialisiert — Paket-Mitglied → Konzept-Slot,
     * Einzel → recipe_ref-Block — und die Skizze auf `erstellt`/`freigegeben` gesetzt.
     *
     * **Graceful (DoD):** Ohne LLM-Provider bzw. bei Team-Kill-Switch wirft `propose()`
     * typisiert (KiNichtVerfuegbar/KiDeaktiviert) → die Skizze bleibt `queued`
     * („wartet auf KI", retrybar), der Go scheitert NIE. Jeder andere Fehler →
     * `fehlgeschlagen` (Original bleibt in `dish_ideas` erhalten — kein stiller
     * Kreativitätsverlust). In BEIDEN Fehlerfällen fliegt KEINE Exception hoch,
     * der Queue-Batch läuft weiter.
     *
     * @return array{status:string, idea_id:int, recipe_id?:int, hinweis?:string, fehler?:string}
     */
    public function materialisiereFreitextIdee(Team $team, int $ideaId): array
    {
        $idee = FoodAlchemistDishIdea::visibleToTeam($team)->find($ideaId);
        if ($idee === null || ! $idee->isOwnedBy($team)) {
            throw new \RuntimeException('Skizze nicht gefunden oder aus einem anderen Team geerbt.');
        }
        // Nur echte, noch offene Freitext-Skizzen erden; Bestands-Refs / bereits materialisierte überspringen.
        if ($idee->sales_recipe_id !== null || $idee->generation_status !== 'queued' || $idee->materialized_at !== null) {
            return ['status' => 'uebersprungen', 'idea_id' => (int) $idee->id];
        }
        $kapitelId = (int) $idee->chapter_id;
        if ($kapitelId <= 0) {
            // Konzept-owned Skizze — die Freitext-Queue läuft nur kapitelweit (Kapitel-Go).
            return ['status' => 'uebersprungen', 'idea_id' => (int) $idee->id];
        }
        $kapitel = $this->ownedKapitel($team, $kapitelId);
        $fb = $kapitel->foodbook;
        $fbId = (int) $kapitel->foodbook_id;

        // Erdungs-Kontext (Spec §KI-Führung): kapitel-aufgelöstes Niveau steuert den Generator.
        $ziele = $this->kapitelZiele($team, $kapitel);
        $leit = $this->leitplanken($team, $fb, null, $kapitel);
        $beschreibung = trim(implode(' — ', array_filter([
            (string) $idee->title, (string) $idee->description,
        ]))) ?: (string) $idee->title;
        $parameter = array_filter([
            'niveau' => $ziele['niveau'] ?? $leit['niveau'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $gen = app(RecipeGeneratorService::class)->generiere($team, $beschreibung, $parameter, null, true);
            $recipe = $gen['recipe'] ?? null;
            if ($recipe === null) {
                throw new \RuntimeException('Die KI hat kein Rezept erstellt.');
            }

            $ref = DB::transaction(function () use ($team, $idee, $kapitelId, $fbId, $recipe) {
                // Paket-Mitglied → Slot im Gruppen-Konzept (via uebernehmeGericht, kapitelweite Dedup);
                // sonst Einzel → recipe_ref-Block am Kapitel.
                $gruppe = $idee->group_id !== null
                    ? FoodAlchemistDishIdeaGroup::where('team_id', $team->id)->find($idee->group_id)
                    : null;
                if ($gruppe !== null && $gruppe->materialized_concept_id !== null) {
                    $this->uebernehmeGericht($team, $fbId, $kapitelId, (int) $recipe->id, $idee->title, 'kapitel_freigabe_ki', (int) $gruppe->materialized_concept_id);
                    $cslot = FoodAlchemistConceptSlot::where('concept_id', $gruppe->materialized_concept_id)
                        ->where('sales_recipe_id', $recipe->id)->orderByDesc('id')->first();

                    return ['concept_id' => (int) $gruppe->materialized_concept_id, 'concept_slot_id' => (int) ($cslot->id ?? 0)];
                }
                $block = $this->addBlock($team, $kapitelId, ['type' => 'recipe_ref', 'sales_recipe_id' => (int) $recipe->id]);

                return ['block_id' => (int) $block->id];
            });

            $idee->update([
                'generation_status' => 'erstellt',
                'status' => 'freigegeben',
                'generated_recipe_id' => (int) $recipe->id,
                'materialized_at' => now(),
                'materialized_ref' => $ref,
                'source_meta' => array_merge($idee->source_meta ?? [], [
                    'erdung' => 'ki_generiert',
                    'original_titel' => (string) $idee->title,        // kein stiller Kreativitätsverlust
                    'generated_recipe_id' => (int) $recipe->id,
                ]),
            ]);

            return ['status' => 'erstellt', 'idea_id' => (int) $idee->id, 'recipe_id' => (int) $recipe->id];
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException | \Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            // Graceful: kein Provider / Kill-Switch → bleibt queued (retrybar), Go scheitert nicht.
            $idee->update(['source_meta' => array_merge($idee->source_meta ?? [], [
                'generation_hinweis' => 'wartet auf KI',
            ])]);

            return ['status' => 'wartet_ki', 'idea_id' => (int) $idee->id, 'hinweis' => $e->getMessage()];
        } catch (\Throwable $e) {
            // Jeder andere Fehler (unbrauchbares KI-Rezept, Grounding) → fehlgeschlagen; Original bleibt.
            $idee->update([
                'generation_status' => 'fehlgeschlagen',
                'source_meta' => array_merge($idee->source_meta ?? [], [
                    'generation_fehler' => mb_substr($e->getMessage(), 0, 500),
                ]),
            ]);

            return ['status' => 'fehlgeschlagen', 'idea_id' => (int) $idee->id, 'fehler' => $e->getMessage()];
        }
    }

    private const KAPITEL_FELDER = [
        'title', 'consumer_title', 'claim', 'description', 'price_per_person', 'price_mode',
        // SOLL-Ziele (Spec 19, M3) — release_* NICHT hier (setzt kapitelFreigeben, E7.3)
        'target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
        'service_moment_id', 'serving_form_id', 'pricing_mode', 'target_food_cost_pct',
        'creative_mode',   // Kreativ-Modus-Override (Spec 19, E9.1)
        'writing_style_id',   // #2: Schreibstil-Override pro Kapitel (NULL = Concept-Standard erben)
        'is_struktur',   // Textkapitel/Sektion (kein eigenes Food) — Dominique 2026-08-27
    ];

    public function updateKapitel(Team $team, int $id, array $in): FoodAlchemistFoodbookKapitel
    {
        $k = $this->ownedKapitel($team, $id);
        $felder = array_intersect_key($in, array_flip(self::KAPITEL_FELDER));
        \Platform\FoodAlchemist\Support\Verweisziel::pruefeFelder($team, $felder, $k);   // CQ 2.3f
        if (array_key_exists('writing_style_id', $felder)) {   // #2: '' aus dem Dropdown = kein Override → NULL (FK-safe)
            $felder['writing_style_id'] = $felder['writing_style_id'] ? (int) $felder['writing_style_id'] : null;
        }
        $k->update($felder);

        return $k->refresh();
    }

    // ── Spec 43 (Bild-Epic): Kapitel-Bild — 98 · 3.3 S4-1: in Foodbook\FoodbookKapitelBild, hier Fassade ──

    public function setKapitelImage(Team $team, int $kapitelId, UploadedFile $file): string
    {
        return $this->kapitelBild->setKapitelImage($team, $kapitelId, $file);
    }

    public function clearKapitelImage(Team $team, int $kapitelId): FoodAlchemistFoodbookKapitel
    {
        return $this->kapitelBild->clearKapitelImage($team, $kapitelId);
    }

    /** Weiteres Galeriebild (neben dem Kapitel-Bild) ans Foodbook-Kapitel hängen. */
    public function addKapitelGalleryImage(Team $team, int $kapitelId, UploadedFile $file): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage
    {
        return $this->kapitelBild->addKapitelGalleryImage($team, $kapitelId, $file);
    }

    public function removeKapitelGalleryImage(Team $team, int $imageId): void
    {
        $this->kapitelBild->removeKapitelGalleryImage($team, $imageId);
    }

    /**
     * #2: das WORDING aller concept_ref-Blöcke eines Kapitels im KAPITEL-Schreibstil neu betexten
     * und foodbook-LOKAL als Block-Override (payload_json['wording_overrides']) speichern (Snapshot).
     * Das Concept bleibt unangetastet — nur der Foodbook-Block trägt den Kapitel-Stil-Text.
     *
     * Der Stil = Kapitel-Override (`writing_style_id`); ist keiner gesetzt, gibt es nichts zu
     * überschreiben (dann erbt das Kapitel den Concept-Standard live) → 0 zurück, kein LLM-Call.
     * Nutzt denselben `concept.wording`-Prompt + `sprach_duktus`-Kontext wie der Concepter.
     *
     * @return int Anzahl neu betexteter concept_ref-Blöcke
     */
    public function kapitelWordingRegenerieren(Team $team, int $kapitelId): int
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $k->loadMissing(['writingStyle', 'blocks.concept.slots.dish:id,name,sales_wording_standard',
            // Paket-in-Kapitel-Wording: auch die Gerichte eingebetteter Pakete mit-betexten.
            'blocks.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard']);
        $stil = $k->writingStyle;
        if ($stil === null) {
            return 0; // kein Kapitel-Override → nichts zu snapshotten (Standard erbt live)
        }

        $gateway = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class);
        $n = 0;
        foreach ($k->blocks as $block) {
            if ($block->type !== 'concept_ref' || $block->concept === null) {
                continue;
            }
            // Positionen = direkte Gericht-Slots UND die Gerichte eingebetteter Pakete (deren Slot-IDs) —
            // damit das Kapitel-Wording auch die Paket-Gerichte betextet (Dominique-Fund). Eine Stelle mit dem Konfigurator.
            $positionen = app(WordingResolver::class)->wordingPositionen($block->concept);
            if ($positionen === []) {
                continue;
            }
            $kontext = [
                'concept' => $block->concept->name,
                'occasion' => $block->concept->occasion,
                'class' => $block->concept->class,
                // #2 + Schreibstil-Fix: der KAPITEL-Stil steuert die Tonalität (sprach_duktus, nicht nur Name).
                'schreibstil' => $stil->name,
                'schreibstil_anweisung' => trim((string) $stil->sprach_duktus) ?: null,
                'schreibstil_beispiele' => trim((string) $stil->beispiele_md) ?: null,
                'positionen' => $positionen,
            ];
            try {
                $vorschlag = $gateway->propose('concept.wording', $kontext, [
                    'food_dna_foodbook_id' => $k->foodbook_id,
                    // Spec 82 §7 (Entscheidung 4): die CRM-Firma des Foodbooks gehört in die Kaskade, wie bei den anderen Foodbook-Prompts
                    'food_dna_crm_company_id' => $k->foodbook?->crm_company_id !== null ? (int) $k->foodbook->crm_company_id : null,
                    'food_dna_concept_id' => $block->concept->id,
                    'target_table' => 'foodalchemist_foodbook_blocks', 'target_id' => $block->id,
                ]);
            } catch (\Throwable) {
                continue; // fail-soft: ein Block kippt nicht die ganze Runde
            }
            // Intro → Block-Beschreibung (kundensichtbar, foodbook-lokal).
            $intro = $vorschlag->werte['intro'] ?? null;
            if (is_string($intro) && trim($intro) !== '') {
                $this->updateBlock($team, $block->id, ['customer_text' => trim($intro)]);
            }
            // Gericht-Wordings → foodbook-lokaler Override je Slot (Snapshot, überschreibt Concept-Kette).
            foreach (($vorschlag->werte['slots'] ?? []) as $slotId => $text) {
                if (is_string($text) && trim($text) !== '') {
                    $this->setBlockSlotWording($team, $block->id, (int) $slotId, trim($text));
                }
            }
            $n++;
        }

        return $n;
    }

    /** Verschieben mit Zyklus-Schutz (kein Knoten unter eigenen Nachfahren). */
    public function moveKapitel(Team $team, int $id, ?int $newParentId): void
    {
        $k = $this->ownedKapitel($team, $id);
        if ($newParentId !== null) {
            throw_unless(FoodAlchemistFoodbookKapitel::whereKey($newParentId)->where('foodbook_id', $k->foodbook_id)->exists(), \RuntimeException::class, 'Ziel-Kapitel gehört nicht zu diesem Foodbook.');
            if ($newParentId === $id || in_array($newParentId, $this->descendantKapitelIds($team, $k->foodbook_id, $id), true)) {
                throw new \RuntimeException('Zyklus: Kapitel kann nicht unter einen eigenen Nachfahren.');
            }
        }
        $k->update(['parent_id' => $newParentId ?: null]);
    }

    /** @param list<int> $ids */
    public function reorderKapitel(Team $team, int $foodbookId, ?int $parentId, array $ids): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        DB::transaction(function () use ($foodbookId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistFoodbookKapitel::where('id', (int) $id)->where('foodbook_id', $foodbookId)->update(['position' => $i]);
            }
        });
    }

    /**
     * Board: manuellen Kapitel-Fortschritt setzen (offen|in_arbeit|fertig). Nur das Besitzer-Team (ownedKapitel),
     * Vokabular-Pflicht. Spiegel von {@see OfferCompositionService::kapitelFortschritt} — Spec 84 §5.5: vorher schrieb
     * die Livewire-Komponente direkt (`$k->update`), jetzt ein Schreibweg für UI und künftiges MCP.
     */
    public function kapitelFortschritt(Team $team, int $id, string $wert): FoodAlchemistFoodbookKapitel
    {
        $k = $this->ownedKapitel($team, $id);
        if (! in_array($wert, FoodAlchemistFoodbookKapitel::FORTSCHRITT_STUFEN, true)) {
            throw new \RuntimeException('Unbekannte Fortschritt-Stufe.');
        }
        $k->update(['fortschritt' => $wert]);

        return $k->refresh();
    }

    public function deleteKapitel(Team $team, int $id): void
    {
        $this->ownedKapitel($team, $id)->delete();
    }

    private function descendantKapitelIds(Team $team, int $foodbookId, int $kapitelId): array
    {
        $kinder = [];
        foreach ($this->kapitelTree($team, $foodbookId) as $row) {
            $kinder[$row['parent_id'] ?? 0][] = $row['id'];
        }
        $ids = [];
        $stack = $kinder[$kapitelId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    // ── Blöcke ────────────────────────────────────────────────────────────────

    /**
     * Block-Typen. Ursprüngliche Doktrin (Dominique 2026-06-13): „Foodbook komponiert
     * Concepts, KEINE Einzel-Gerichte" — die Gericht-Ebene war Sache des Concepters
     * (GP→Rezept→Gericht→Concept→Foodbook). **Teilrevidiert Spec 19 (Dominique 2026-07-23,
     * Entscheidung 5):** Ein Kapitel trägt jetzt 0–n Concepts (Paket, €/Gast) UND 0–n
     * direkte Einzel-Gerichte als `recipe_ref`-Block (€/Position). Damit ist „Weg B
     * exklusiv" nicht mehr gültig. `recipe_ref` referenziert per `sales_recipe_id` ein
     * echtes VK-Gericht (`verkauf()`-Scope, KEINE konzept-lokale Slot-Variante) — Validierung
     * siehe `Support\BlockReferenz`. Lesepfade (blockPreis/kapitelAggregat/dokBlockLabel) kannten
     * recipe_ref bereits; hier wird nur der Schreibpfad freigeschaltet. Wahl-Gruppen A|B|C
     * bleiben (zwischen Concepts wie zwischen Gerichten).
     */
        // Spec 50 · A7: `image` war hier deklariert, aber NIRGENDS beschreibbar oder renderbar
    // (`grep "=> 'image'"` über src/ und resources/ = leer; 0 Zeilen im Bestand). Im
    // Angebot stand der Typ zusätzlich im MCP-Enum: ein Agent konnte ihn setzen und bekam
    // einen Block, den keine Ausgabe zeigt. Bilder leben entitätsweit (`*_images`-Tabellen)
    // bzw. als Presentation-Design-Block — beides unberührt.
    public const BLOCK_TYPES = ['concept_ref', 'recipe_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer', 'text'];

    private const BLOCK_FELDER = ['type', 'level', 'visible', 'label', 'wording', 'customer_text', 'interne_bemerkung',
        'variant_group_id', 'concept_id', 'sales_recipe_id', 'quantity', 'unit_vocab_id', 'price_value', 'price_basis', 'height', 'header_source', 'payload_json'];

    public function addBlock(Team $team, int $kapitelId, array $in): FoodAlchemistFoodbookBlock
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        app(FormatKapitelService::class)->pruefeBlockErlaubt($k);   // Format-Kapitel: keine eigenen Blöcke, Pflege im Format
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', self::BLOCK_TYPES, true) ? $in['type'] : 'text';
        \Platform\FoodAlchemist\Support\BlockReferenz::pruefe($team, $daten);   // 1.1j: Gericht/Concept/Einheit sichtbar
        $daten['team_id'] = $k->team_id;
        $daten['position'] = (int) $k->blocks()->max('position') + 1;

        return $k->blocks()->create($daten);
    }

    public function updateBlock(Team $team, int $blockId, array $in): FoodAlchemistFoodbookBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        \Platform\FoodAlchemist\Support\BlockReferenz::pruefe($team, $daten, $block);   // 1.1j: auch concept_ref (vorher ungeprüft)
        $block->update($daten);

        return $block->refresh();
    }

    /**
     * Wording-Kette: Per-Gericht-Override eines concept_ref-Blocks
     * (payload_json['wording_overrides'][slot_id]) setzen/löschen — die oberste
     * Stufe der Kette Foodbook → Konzept → Standard → Name.
     */
    public function setBlockSlotWording(Team $team, int $blockId, int $slotId, ?string $text): FoodAlchemistFoodbookBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $payload = $block->payload_json ?? [];
        $overrides = $payload['wording_overrides'] ?? [];
        $text = trim((string) $text);
        if ($text === '') {
            unset($overrides[(string) $slotId], $overrides[$slotId]);
        } else {
            $overrides[(string) $slotId] = $text;
        }
        $payload['wording_overrides'] = $overrides;
        $block->update(['payload_json' => $payload]);

        return $block->refresh();
    }

    public function deleteBlock(Team $team, int $blockId): void
    {
        $this->ownedBlock($team, $blockId)->delete();
    }

    /** @param list<int> $ids */
    public function reorderBlocks(Team $team, int $kapitelId, array $ids): void
    {
        $this->ownedKapitel($team, $kapitelId);
        DB::transaction(function () use ($kapitelId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistFoodbookBlock::where('id', (int) $id)->where('chapter_id', $kapitelId)->update(['position' => $i]);
            }
        });
    }

    /** Wahl-Gruppe „A|B|C": nächste freie Gruppen-ID im Kapitel. */
    public function nextVariantGroupId(Team $team, int $kapitelId): int
    {
        $this->ownedKapitel($team, $kapitelId);

        return (int) FoodAlchemistFoodbookBlock::where('chapter_id', $kapitelId)->max('variant_group_id') + 1;
    }

    /** @param list<int> $blockIds */
    public function setVariantGroup(Team $team, array $blockIds, ?int $groupId): void
    {
        foreach ($blockIds as $id) {
            $block = $this->ownedBlock($team, (int) $id);
            $block->update(['variant_group_id' => $groupId]);
        }
    }

    /**
     * Staffelpreise eines header_frei_preis-Blocks setzen (Vollersatz).
     *
     * @param  array<int, array{min_personen:int, preis:float}>  $rows
     */
    public function setStaffel(Team $team, int $blockId, array $rows): void
    {
        $block = $this->ownedBlock($team, $blockId);
        DB::transaction(function () use ($block, $rows) {
            $block->staffel()->forceDelete();
            $i = 0;
            foreach ($rows as $row) {
                $block->staffel()->create([
                    'team_id' => $block->team_id,
                    'min_persons' => max(1, (int) ($row['min_persons'] ?? 1)),
                    'price' => (float) ($row['price'] ?? 0),
                    'position' => $i++,
                ]);
            }
        });
    }

    /**
     * Header-Presets für den „+ Inhalt"-Picker (Jarvis-Parität).
     *
     * @return array<string, list<array{slug:string, label:string, type:string, preis_basis?:string, sichtbar?:bool}>>
     */
    public static function headerPresets(): array
    {
        return \Platform\FoodAlchemist\Services\Foodbook\FoodbookHeaderPresets::liste();   // 98 · 3.3 S4-0k: Daten ohne Dienst-Abhängigkeit
    }

    // ── Aggregat / Preis (M11 Cockpit) — 98 · 3.3 S4-4: in Foodbook\FoodbookAggregat, hier Fassade (und FoodbookAggregatQuelle) ──

    public function blockPreis(FoodAlchemistFoodbookBlock $block, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        return $this->aggregat->blockPreis($block, $pax, $outlet);
    }

    public function resolveStaffel(FoodAlchemistFoodbookBlock $block, ?int $pax): float
    {
        return $this->aggregat->resolveStaffel($block, $pax);
    }

    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        return $this->aggregat->kapitelAggregat($team, $kapitel, $pax, $outlet);
    }

    public function wareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        return $this->aggregat->wareneinsatzAmpel($team, $fb, $kapitel, $pax, $outlet);
    }

    public function foodbookWareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        return $this->aggregat->foodbookWareneinsatzAmpel($team, $fb, $outlet);
    }

    public function gesamt(Team $team, FoodAlchemistFoodbook $fb, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        return $this->aggregat->gesamt($team, $fb, $outlet);
    }

    // ── #384/Folge: versendbares Dokument, Concept-Karte, Vorschau-Snapshot — 98 · 3.3 S4-5: in Foodbook\FoodbookDokument, hier Fassade ──

    public function dokumentDaten(Team $team, FoodAlchemistFoodbook $fb, bool $intern = false, array $kapitelFilter = [], bool $mitKaskade = false, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitHandbuchMengen = false, bool $mitTexten = false): array
    {
        return $this->dokument->dokumentDaten($team, $fb, $intern, $kapitelFilter, $mitKaskade, $outlet, $mitHandbuchMengen, $mitTexten);
    }

    public function conceptKarteDaten(Team $team, int $conceptId): array
    {
        return $this->dokument->conceptKarteDaten($team, $conceptId);
    }

    public function vorschauSnapshot(FoodAlchemistFoodbook $fb): ?array
    {
        return $this->dokument->vorschauSnapshot($fb);
    }

    public function vorschauSnapshotAktualisieren(Team $team, int $foodbookId): array
    {
        return $this->dokument->vorschauSnapshotAktualisieren($team, $foodbookId);
    }

    // ── Picker (für den Editor) ─────────────────────────────────────────────

    /**
     * Concepts (echte, keine Vorlagen) für den concept_ref-Picker — optional gefiltert nach
     * Concept-Kategorie (descendant-inklusiv, FB-1/GT-FB-7).
     */
    /**
     * UX 2026-07-25 (Dominique): der Concept-Picker filtert auf die Concepter-DIMENSIONEN
     * (Eventtyp/Servierform/Einsatzmoment/Saison) — nicht mehr auf Kategorie/Klasse (Konzept-Taxonomie
     * ausgemustert). Filter-Logik gespiegelt aus ConceptService::paginateBrowser (4c-Facetten).
     *
     * @param array{eventtyp?:?int, servierform?:?int, einsatzmoment?:?int, season?:?int} $facetten
     */
    public function conceptKandidaten(Team $team, string $suche, int $limit = 20, array $facetten = []): Collection
    {
        // #3: NUR Konzepte (kind=concept) — Pakete haben einen eigenen Picker-Reiter (paketKandidaten).
        return FoodAlchemistConcept::visibleToTeam($team)->echte()->konzepte()
            ->where('status', 'active') // Picker zeigt nur aktive (keine Entwürfe/archivierten; Status berücksichtigt)
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when(! empty($facetten['eventtyp']), fn ($q) => $q->where('event_type_id', (int) $facetten['eventtyp']))
            ->when(! empty($facetten['servierform']), fn ($q) => $q->where('serving_form_id', (int) $facetten['servierform']))
            ->when(! empty($facetten['einsatzmoment']), fn ($q) => $q->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $facetten['einsatzmoment'])))
            ->when(! empty($facetten['season']), fn ($q) => $q->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $facetten['season'])))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache', 'price_incomplete', 'event_type_id', 'serving_form_id']);
    }

    /**
     * #3: Paket-Kandidaten (kind=paket-Concepts) für den Foodbook-Picker — eigener Reiter neben
     * Concept + Format. Dieselbe Filterkette wie {@see conceptKandidaten}; zeigt `consumer_name`
     * (Kundenname) statt des internen Namens („Neues Paket"). Ein Paket wird wie ein Concept als
     * concept_ref-Block gebucht (paket = kind=paket-Concept, concept_id trägt beide Arten).
     */
    public function paketKandidaten(Team $team, string $suche, int $limit = 20, array $facetten = []): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->echte()->pakete()
            ->where('status', 'active')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::likeAny($q, ['name', "COALESCE(consumer_name, '')"], $suche))
            ->when(! empty($facetten['eventtyp']), fn ($q) => $q->where('event_type_id', (int) $facetten['eventtyp']))
            ->when(! empty($facetten['servierform']), fn ($q) => $q->where('serving_form_id', (int) $facetten['servierform']))
            ->when(! empty($facetten['einsatzmoment']), fn ($q) => $q->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $facetten['einsatzmoment'])))
            ->when(! empty($facetten['season']), fn ($q) => $q->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $facetten['season'])))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'consumer_name', 'price_per_person_cache', 'price_incomplete', 'event_type_id', 'serving_form_id']);
    }

    /** Einzelne Gerichte (VK-Rezepte) für den recipe_ref-Picker. */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20, ?int $hauptgruppe = null, ?int $dishClassId = null): Collection
    {
        // Modell A: HG = Kategorie (recipes.dish_main_group_id), Untergruppe = Diät-Klasse
        // (recipes.dish_class_id, z. B. „Vorspeise Vegan"). Beide Achsen filtern den Picker
        // (Browsen, wenn der Name unbekannt ist); dishClassId ist die feinere.
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id') // R4.4: Slot-Varianten sind konzept-lokal, nicht pickbar
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($hauptgruppe !== null, fn ($q) => $q->where('dish_main_group_id', $hauptgruppe))
            ->when($dishClassId !== null, fn ($q) => $q->where('dish_class_id', $dishClassId))
            ->orderBy('name')->limit($limit)
            ->select(['id', 'name'])->mitStandardVk()->get();   // Spec 85 D-1: VK als vk_netto aus der Standard-Darreichung
    }

    // ── Format buchen (F5) — WIE EIN CONCEPT, kein Live-Format-Sonderweg ──────────

    /**
     * Format-Umbau F5: Formate für den „Format einfügen"-Picker (Kunden-IP-gefiltert).
     * Kunden-IP-Guard: ein fremdes Kunden-Format (origin=kunde, anderer Kunde als das
     * Foodbook) wird ausgeblendet, sobald der Foodbook-Kunde bekannt ist.
     */
    public function formatKandidaten(Team $team, FoodAlchemistFoodbook $fb, string $suche, int $limit = 20): Collection
    {
        $fbKunde = mb_strtolower(trim((string) ($fb->customer ?? '')));

        return \Platform\FoodAlchemist\Models\FoodAlchemistFormat::visibleToTeam($team)
            ->where('status', '!=', 'archiviert')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit(50)
            ->get(['id', 'name', 'consumer_name', 'origin', 'customer', 'status'])
            ->reject(fn ($f) => $f->origin === 'kunde' && trim((string) $f->customer) !== ''
                && $fbKunde !== '' && mb_strtolower(trim((string) $f->customer)) !== $fbKunde)
            ->take($limit)->values();
    }

    /**
     * Ein Format ins Foodbook buchen — als LEBENDES Format-Kapitel (Entscheid B, Dominique 2026-08-31;
     * löst die Einmal-Expansion C vom 2026-08-27 ab). Angelegt wird EINE Sektion (is_struktur) mit
     * `format_id` + Kundentitel/Hinführung aus dem Format, ohne Unterkapitel und ohne Blöcke. Die
     * Editionen (format_slots → Concept) und die Struktur-Slots rendern LIVE aus dem Format
     * ({@see dokumentDaten}, Zweig `ist_format`; Editor: {@see FormatKapitelService::ansicht}) — Editionen rein/raus
     * im Format wirken sofort in allen Büchern; eingefroren beim Versand. Kunden-IP- + Status-Guard.
     */
    public function insertFormatAlsKapitel(Team $team, int $foodbookId, int $formatId, ?int $parentId = null): FoodAlchemistFoodbookKapitel
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        // Status ist auf AusgabeStatus gecastet → über statusWert()->value vergleichen ('versendet'
        // normalisiert auf 'aktiv', bleibt der Vollständigkeit halber gelistet).
        if (in_array($fb->statusWert()->value, ['versendet', 'archiviert'], true)) {
            throw new \RuntimeException('Das Foodbook ist ' . $fb->statusWert()->value . '. Neue Kapitel lassen sich nicht mehr einfügen.');
        }
        if ($parentId !== null && ! FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu diesem Foodbook.');
        }
        $format = \Platform\FoodAlchemist\Models\FoodAlchemistFormat::visibleToTeam($team)
            ->with(['slots' => fn ($q) => $q->orderBy('position'), 'slots.concept:id,name,consumer_name'])
            ->findOrFail($formatId);

        // Kunden-IP: ein Kunden-Format nie in ein Buch eines ANDEREN Kunden (CLAUDE.md).
        if ($format->origin === 'kunde' && trim((string) $format->customer) !== '') {
            $fbKunde = trim((string) ($fb->customer ?? ''));
            if ($fbKunde !== '' && mb_strtolower($fbKunde) !== mb_strtolower(trim((string) $format->customer))) {
                throw new \RuntimeException('Kunden-IP: Das Format „' . $format->name . '“ gehört ' . $format->customer
                    . ' und darf nicht in ein Foodbook von ' . $fbKunde . ' eingefügt werden.');
            }
        }

        return DB::transaction(function () use ($team, $fb, $format, $parentId) {
            // B (Dominique 2026-08-31): LEBENDES Format-Kapitel — die Sektion trägt `format_id` und rendert
            // Identität (Marken-Titel/Story) + Editionen + Struktur LIVE aus dem Format ({@see dokumentDaten}).
            // KEINE Einmal-Expansion in Unterkapitel/Blöcke mehr (das war C, 2026-08-27): Editionen rein/raus
            // im Format wirken jetzt sofort in ALLEN eingefügten Foodbooks durch. Der Versand-Snapshot friert
            // das Ergebnis (PresentationService::publish → dokumentDaten) beim Publish ohnehin ein.
            $sektion = $this->addKapitel($team, $fb->id, ['title' => $format->name], $parentId);
            $sektion->update([
                'format_id' => $format->id,                    // Live-Referenz auf das Format
                'consumer_title' => $format->consumer_name,    // Marketing-Titel (PDF); Fallback im Render
                'description' => $format->story,               // Sektions-Hinführung (Story); Fallback im Render
                'is_struktur' => true,                         // Sektion: kein eigenes additives Food
            ]);

            return $sektion->refresh();
        });
    }

    // ── Spec 03 · L2: KI-Kundentext — 98 · 3.3 S4-3: in Foodbook\FoodbookKundentext, hier Fassade ──

    public function kiKundentextVorschlag(Team $team, int $foodbookId): array
    {
        return $this->kundentext->kiKundentextVorschlag($team, $foodbookId);
    }

    public function kiKapitelKundentextVorschlag(Team $team, int $kapitelId): array
    {
        return $this->kundentext->kiKapitelKundentextVorschlag($team, $kapitelId);
    }

    // ── Branding (pro Foodbook) — 98 · 3.3 S4-5: in Foodbook\FoodbookDokument, hier Fassade ──

    public function setBranding(Team $team, int $foodbookId, array $in): FoodAlchemistFoodbook
    {
        return $this->dokument->setBranding($team, $foodbookId, $in);
    }

    public function storeLogo(Team $team, int $foodbookId, UploadedFile $file): string
    {
        return $this->dokument->storeLogo($team, $foodbookId, $file);
    }

    public function storeCover(Team $team, int $foodbookId, UploadedFile $file): string
    {
        return $this->dokument->storeCover($team, $foodbookId, $file);
    }

    public function clearLogo(Team $team, int $foodbookId): FoodAlchemistFoodbook
    {
        return $this->dokument->clearLogo($team, $foodbookId);
    }

    public function clearCover(Team $team, int $foodbookId): FoodAlchemistFoodbook
    {
        return $this->dokument->clearCover($team, $foodbookId);
    }

    // 98 · 3.3 S4-1: Besitzer-Riegel in Foodbook\FoodbookBasis (eine Stelle für alle Teile)
    private function ownedKapitel(Team $team, int $id): FoodAlchemistFoodbookKapitel
    {
        return $this->basis->ownedKapitel($team, $id);
    }

    private function ownedBlock(Team $team, int $id): FoodAlchemistFoodbookBlock
    {
        return $this->basis->ownedBlock($team, $id);
    }

    private function guard(FoodAlchemistFoodbook $fb, Team $team): void
    {
        $this->basis->guard($fb, $team);
    }
}
