<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Illuminate\Http\UploadedFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\Concepter\FormatKapitelService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\Concepter\WordingResolver;

/**
 * #384/Folge · versendbares Foodbook-/Portfolio-Dokument mit Branding (98 · 3.3 S4-5, aus FoodbookService verschoben):
 * dokumentDaten (Kunden- und interne Sicht), Concept-Karte, Vorschau-Snapshot sowie Farb-/Text-Marke, Logo und Cover.
 * Nutzt Basis (Riegel, Block-Label) und Aggregat (Block-, Kapitel- und Gesamtpreis), nie die Fassade.
 */
final class FoodbookDokument
{
    public function __construct(
        private FoodbookBasis $basis,
        private FoodbookAggregat $aggregat,
    ) {}

    // ── #384/Folge: versendbares Foodbook/Portfolio-Dokument ───────────────────

    /**
     * Daten fürs versendbare Foodbook-Dokument (Druck/PDF): Kapitel-Baum (Pre-Order,
     * Tiefe) mit NUR sichtbaren Blöcken (Export-Filter `sichtbar`) + Kunden-Labels
     * (konsumententitel/kundentext), pro Kapitel der Per-Person-Preis, + Gesamt.
     * interne_bemerkung wird NIE ausgegeben (Kundensicht).
     *
     * @return array{fb:FoodAlchemistFoodbook, kapitel:list<array>, gesamt:array, kunde:?string}
     */
    /**
     * @param  list<int>  $kapitelFilter  #3: leer = alle Kapitel; sonst nur diese Kapitel-IDs. Ein
     *                                     selektiertes Kapitel, dessen Eltern rausgefiltert wurde,
     *                                     wird auf die oberste Ebene gehoben (sonst nie erreicht).
     * @param  bool  $mitKaskade  #3: zusätzlich den Produktions-Baum je Gericht anhängen (EK nur intern).
     */
    public function dokumentDaten(Team $team, FoodAlchemistFoodbook $fb, bool $intern = false, array $kapitelFilter = [], bool $mitKaskade = false, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitHandbuchMengen = false, bool $mitTexten = false): array
    {
        $fb->loadMissing([
            'chapters' => fn ($q) => $q->orderBy('position'),
            'chapters.blocks' => fn ($q) => $q->where('visible', true)->orderBy('position'),
            // Wording-Kette: Slots (inkl. Paket-Gerichte) fürs Auflösen der Gericht-Zeilen
            'chapters.blocks.concept.slots.dish:id,name,sales_wording_standard',
            // eingebettetes Paket (kind=paket-Concept) + dessen Gerichte für die Menü-Auflösung
            'chapters.blocks.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache,price_incomplete,ek_per_person_cache',
            'chapters.blocks.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
            // E8.3: recipe_ref braucht sales_net/ek_total_eur für die €/Position-Preisspalte (blockPreis) — sonst rendert der Preis leer.
            'chapters.blocks.dish:id,name,sales_wording_standard,ek_total_eur,sales_unit_count', 'chapters.blocks.dish.standardPresentation',
            // B (2026-08-31): LEBENDES Format-Kapitel — Identität + Editionen live aus dem Format. Editionen =
            // format_slots (type=concept) → Concept; die Wording-Kette der Editions-Concepte wie bei chapters.blocks.
            'chapters.format', 'chapters.format.images',
            'chapters.format.slots' => fn ($q) => $q->orderBy('position'),
            'chapters.format.slots.concept.slots.dish:id,name,sales_wording_standard',
            'chapters.format.slots.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache,price_incomplete,ek_per_person_cache',
            'chapters.format.slots.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
            'crmCompany', 'crmContact',
        ]);
        $pax = $fb->personen;

        // #3: Kapitel-Filter — nur ausgewählte Kapitel rendern; rausgefilterte Eltern hochziehen.
        $chapters = $fb->chapters;
        if ($kapitelFilter !== []) {
            $erlaubt = array_flip(array_map('intval', $kapitelFilter));
            $chapters = $chapters->filter(fn ($k) => isset($erlaubt[(int) $k->id]))->values();
            $vorhanden = array_flip($chapters->pluck('id')->map(fn ($v) => (int) $v)->all());
            $byParent = $chapters->groupBy(fn ($k) => ($k->parent_id !== null && isset($vorhanden[(int) $k->parent_id])) ? (int) $k->parent_id : 0);
        } else {
            $byParent = $chapters->groupBy(fn ($k) => $k->parent_id ?? 0);
        }
        $wording = app(WordingResolver::class);

        $rows = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$rows, $team, $pax, $wording, $intern, $outlet, $mitHandbuchMengen, $mitTexten) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                // B (2026-08-31): LEBENDES Format-Kapitel — Identität + Editionen LIVE aus dem Format
                // rendern (statt der Kapitel-Blöcke). Editionen = format_slots (type=concept) → Concept
                // (Wording-Kette wie sonst); header/text/spacer = Struktur-Editionen. Showcase:
                // vk_pro_person=null (Editionen sind Alternativen → Preis-Range, KEIN additiver Summand).
                if ($k->format_id !== null) {
                    $format = $k->format;
                    if ($format === null) {
                        // Reconciliation: Format weg (soft-deleted) → leerer Platzhalter, kein Fehler.
                        $rows[] = ['title' => $k->consumer_title ?: $k->title, 'title_intern' => $k->title,
                            'text' => trim((string) $k->description) ?: null, 'anker' => 'k' . $k->id, 'depth' => $depth,
                            'bloecke' => [], 'ist_format' => true, 'editionen' => [], 'vk_pro_person' => null];
                        $walk((int) $k->id, $depth + 1);

                        continue;
                    }
                    $editionen = app(FormatKapitelService::class)->editionen($format, $mitHandbuchMengen, $mitTexten, $intern);
                    $hero = $format->images->firstWhere('is_hero', true);
                    $rows[] = [
                        'title' => $k->consumer_title ?: ($format->consumer_name ?: $format->name),
                        'title_intern' => $k->title ?: $format->name,
                        'text' => trim((string) ($k->description ?: $format->story)) ?: null,
                        'anker' => 'k' . $k->id,
                        'depth' => $depth,
                        'bloecke' => [],
                        'ist_format' => true,
                        'claim' => $format->claim,
                        'hero' => $hero?->dataUri(),
                        'preis_range' => $format->priceRange(),
                        'editionen' => $editionen,
                        'vk_pro_person' => null,   // Showcase — Editionen sind Alternativen, nicht additiv
                    ];
                    $walk((int) $k->id, $depth + 1);

                    continue;
                }
                $bloecke = [];
                foreach ($k->blocks as $b) {
                    $label = $this->basis->dokBlockLabel($b);
                    if ($label === null || $label === '') {
                        continue; // spacer/image/leerer Header
                    }
                    // Untertitel: kundentext zusätzlich, wenn er nicht schon das Label ist (Legacy-Doppelrolle)
                    $untertitel = trim((string) $b->customer_text);
                    $untertitel = ($untertitel !== '' && $untertitel !== $label) ? $untertitel : null;
                    // concept_ref: Gerichte des Concepts mit aufgelöster Wording-Kette als Kundenzeilen
                    $gerichte = ($b->type === 'concept_ref' && $b->concept !== null)
                        ? $wording->gerichtZeilen($b->concept, $b, $mitTexten, $mitHandbuchMengen)   // $mitTexten: Präsentation zeigt Text-Bausteine des Konzepts (wie das Angebot)
                        : [];
                    // Block-Preis für die Preis-links-Spalte (Referenz-Layout „x € pro Person").
                    $bp = $this->aggregat->blockPreis($b, $pax, $outlet);
                    // E8.3: €/Gast vs. €/Position — Preis-Einheit typ-getrieben (spiegelt LeitstelleService::preiseBaum):
                    // concept_ref = Paket → pro Gast · recipe_ref = Einzelgericht → pro Position · sonst null (Header/Text).
                    $preisEinheit = match ($b->type) {
                        'concept_ref' => 'gast',
                        'recipe_ref' => 'position',
                        default => null,
                    };
                    $bloecke[] = ['type' => $b->type, 'label' => $label, 'untertitel' => $untertitel,
                        'gerichte' => $gerichte, 'ist_header' => str_starts_with((string) $b->type, 'header'),
                        'preis_pp' => (float) $bp['vk_pp'], 'pauschal' => (float) $bp['pauschal'], 'preis_vorlaeufig' => (bool) ($bp['vk_fehlt'] ?? false),
                        'preis_einheit' => $preisEinheit,
                        // Preisdarstellung (2026-08-25): einzel-Concept → Concept-Summenpreis ausblenden,
                        // stattdessen zeigt jede Gericht-Zeile ihren eigenen Preis (aus gerichtZeilen).
                        'einzelpreise' => $b->type === 'concept_ref' && $b->concept !== null && $b->concept->istEinzelpreis(),
                        // #5b: Einzelgericht-Block trägt seine Rezept-ID → per-Gericht-Codes am Block-Label.
                        'recipe_id' => $b->type === 'recipe_ref' ? ($b->sales_recipe_id !== null ? (int) $b->sales_recipe_id : null) : null,
                        'codes' => []] + ($intern ? ['ek_pp' => (float) $bp['ek_pp']] : []);   // EK je Baustein NUR intern (EK-Schalter, Kalkulations-Print)
                }
                $agg = $this->aggregat->kapitelAggregat($team, $k, $pax, $outlet);
                $row = [
                    'title' => $k->consumer_title ?: $k->title,
                    'title_intern' => $k->title,           // interner Titel für die Projektleitung-Sicht
                    // Spec 03 · L2b: die Kapitel-Hinführung. Das Feld existierte im Schema und war
                    // über MCP schreibbar, wurde aber von keiner Projektion gelesen — ein Kundentext,
                    // der nie beim Kunden ankam. Gleiche Rolle wie `fb->description` auf Buch-Ebene.
                    'text' => trim((string) $k->description) ?: null,
                    'anker' => 'k' . $k->id,               // Navleiste-Sprungziel (klickbar in HTML + PDF)
                    'depth' => $depth,
                    'bloecke' => $bloecke,
                    'vk_pro_person' => $agg['vk_pro_person'], 'vk_vorlaeufig' => $agg['vk_fehlt'],
                ];
                if ($intern) {
                    // Marge nur in der internen Projektion (Projektleitung/Vertrieb) — NIE im Kundendokument.
                    $row['ek_pro_person'] = $agg['ek_per_person'];
                    $row['food_cost_percent'] = $agg['food_cost_percent'];
                }
                $rows[] = $row;
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        // #5b: §-Kennzeichnung PRO GERICHT (Dominique 2026-08-25: nicht pro Konzept) — jede
        // Gericht-Zeile trägt ihre eigenen Allergen-/Zusatzstoff-Codes aus der Gericht-Deklaration;
        // die Legende sammelt nur, was auf den Gerichten tatsächlich vorkommt (LMIV/ZZulV).
        $kzAgg = app(\Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService::class);
        $katalog = $kzAgg->kennzeichnungKatalog();
        // Alle Gericht-IDs: aus den concept_ref-Gericht-Zeilen UND den recipe_ref-Block-Labels.
        $recipeIds = collect($rows)->flatMap(function ($r) {
            $ausBloecke = collect($r['bloecke'])->flatMap(fn ($b) => collect($b['gerichte'])->pluck('recipe_id')->push($b['recipe_id'] ?? null));
            // B: Format-Editionen tragen ihre Gericht-Zeilen (LMIV-Codes je Gericht) ausserhalb der bloecke.
            $ausEditionen = collect($r['editionen'] ?? [])->flatMap(fn ($e) => collect($e['gerichte'])->pluck('recipe_id'));

            return $ausBloecke->concat($ausEditionen);
        })->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $usedAlg = [];
        $usedZus = [];
        if ($recipeIds !== []) {
            $dishes = FoodAlchemistRecipe::whereIn('id', $recipeIds)->get()->keyBy('id');
            // Normale Closure (kein arrow fn) — $usedAlg/$usedZus MÜSSEN by-ref laufen, damit die
            // Legende die real vorkommenden Codes sammelt (arrow fn würde sie by value kopieren).
            $codesFuer = function (?int $rid) use ($dishes, $kzAgg, $katalog, &$usedAlg, &$usedZus): array {
                return ($rid !== null && $dishes->get($rid) !== null)
                    ? $kzAgg->gerichtCodes($dishes->get($rid), $usedAlg, $usedZus, $katalog) : [];
            };
            foreach ($rows as $ri => $row) {
                foreach ($row['bloecke'] as $bi => $blk) {
                    // recipe_ref: Codes am Block-Label (Einzelgericht ist selbst ein Gericht).
                    $rows[$ri]['bloecke'][$bi]['codes'] = $codesFuer($blk['recipe_id'] ?? null);
                    // concept_ref: Codes je Gericht-Zeile.
                    foreach ($blk['gerichte'] as $gi => $g) {
                        $rows[$ri]['bloecke'][$bi]['gerichte'][$gi]['codes'] = $codesFuer(isset($g['recipe_id']) ? (int) $g['recipe_id'] : null);
                    }
                }
                // B: LEBENDES Format-Kapitel — Codes je Editions-Gericht (LMIV, pro Gericht).
                foreach ($row['editionen'] ?? [] as $ei => $ed) {
                    foreach ($ed['gerichte'] as $gi => $g) {
                        $rows[$ri]['editionen'][$ei]['gerichte'][$gi]['codes'] = $codesFuer(isset($g['recipe_id']) ? (int) $g['recipe_id'] : null);
                    }
                }
            }
        }
        $legende = $kzAgg->kennzeichnungLegende($usedAlg, $usedZus, $katalog);

        // #3: optionaler Produktions-Kaskaden-Anhang. Gericht-IDs aus den (gefilterten) Kapitel-
        // Blöcken: recipe_ref = direktes Gericht, concept_ref = Slot-/Paket-Gerichte. Je Gericht der
        // rekursive Baum aus ReportExportService (wiederverwendet report-recipe-node). EK nur intern
        // (ek/preise/lieferanten an $intern gebunden) → Kundensicht zeigt Struktur+Mengen ohne Kosten.
        $kaskaden = [];
        if ($mitKaskade) {
            $gerichtIds = [];
            foreach ($chapters as $k) {
                foreach ($k->blocks as $b) {
                    if ($b->type === 'recipe_ref' && $b->dish !== null) {
                        $gerichtIds[] = (int) $b->dish->id;
                    } elseif ($b->type === 'concept_ref' && $b->concept !== null) {
                        foreach ($b->concept->slots as $slot) {
                            if ($slot->dish !== null) {
                                $gerichtIds[] = (int) $slot->dish->id;
                            }
                        }
                    }
                }
            }
            $kOpt = [
                'stammdaten' => true, 'zutaten' => true, 'kaskade' => true,
                'steps' => false, 'sensorik' => false, 'produktion' => false, 'bilder' => false,
                'deklaration' => false, 'naehrwerte' => false, 'notizen' => false,
                'preise' => $intern, 'lieferanten' => $intern, 'ek' => $intern, 'intern' => $intern,
            ];
            $report = app(\Platform\FoodAlchemist\Services\Ausgabe\ReportExportService::class);
            foreach (array_values(array_unique($gerichtIds)) as $gid) {
                try {
                    $d = $report->rezeptDaten($team, $gid, $kOpt);
                    $kaskaden[] = ['name' => $d['name'], 'recipe' => $d['recipe'], 'optionen' => $kOpt];
                } catch (\Throwable) {
                    // fail-soft: ein nicht ladbares Gericht kippt den Druck nicht
                }
            }
        }

        return [
            'fb' => $fb,
            'intern' => $intern,
            'kapitel' => $rows,
            'gesamt' => $this->aggregat->gesamt($team, $fb, $outlet),
            // #5b: §-Kennzeichnungs-Legende (nur real vorkommende Allergene/Zusatzstoffe) — ganz unten im Dokument.
            'legende' => $legende,
            // CRM-only: Kontaktperson separat.
            'customer' => $fb->crmCompany?->display_name,
            'kontakt' => $fb->crmContact?->display_name,
            // Kundendokument-Vollständigkeit: gesetzlicher MwSt-Satz + Stand-Datum.
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $fb->updated_at,
            // PDF-Redesign: pro-Foodbook-Marke (Farbe/Band/Logo/Cover/Footer), DomPDF-taugliche base64-Bilder.
            'branding' => $this->brandingDaten($fb),
            // #3: Produktions-Kaskaden-Anhang (leer, wenn nicht angefordert).
            'kaskaden' => $kaskaden,
            // #3: volle Kapitel-Liste (ungefiltert) + aktiver Filter — für den Kapitel-Picker im Dokument.
            'alle_kapitel' => $fb->chapters->map(fn ($k) => ['id' => (int) $k->id, 'title' => $k->consumer_title ?: $k->title])->values()->all(),
            'aktive_kapitel' => array_map('intval', $kapitelFilter),
        ];
    }

    /**
     * F3: schöne Einzel-Concept-„Karte" (Druck/PDF) — die fehlende hübsche Einzel-Ausgabe
     * des Concepters (bisher gab es dort nur den technischen Report). Foodbook-styled:
     * Konsumenten-Titel + Claim + Hinführung + die aufgelösten Menü-Zeilen (gleiche
     * Wording-Kette wie Foodbook/Format) + €/Gast. Klein gehalten: reine Menü-Sicht
     * (kein EK/Marge — das ist die Kunden-Ausgabe). Team-scoped über visibleToTeam.
     *
     * @return array{
     *     concept: FoodAlchemistConcept, titel: string, claim: ?string, text: ?string,
     *     preis_pp: ?float, gerichte: list<array<string, mixed>>, mwst: ?array, stand: mixed
     * }
     */
    public function conceptKarteDaten(Team $team, int $conceptId): array
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.dish:id,name,sales_wording_standard',
                'slots.embeddedConcept:id,name,consumer_name,price_per_person_cache,price_incomplete,ek_per_person_cache',
                'slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
            ])
            ->findOrFail($conceptId);

        $wording = app(WordingResolver::class);

        // §-Kennzeichnung je Gericht + Legende (2026-09-04): die Karte geht an den Kunden,
        // trug aber bis hierher KEINE Deklaration — anders als Foodbook, Speisekarte und
        // Speiseplan, die dieselbe Code-Quelle längst nutzen (Regelwerk Concept §7.1).
        $kz = app(\Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService::class)
            ->codesFuerZeilen($wording->gerichtZeilen($concept));

        return [
            'concept' => $concept,
            'titel' => $concept->consumer_name ?: $concept->name,
            'claim' => $concept->claim,
            'text' => trim((string) $concept->description) ?: null,
            'preis_pp' => $concept->price_per_person_cache !== null ? (float) $concept->price_per_person_cache : null,
            // Preisdarstellung (2026-08-25): einzel → Concept-Summenpreis ausblenden, Preise je Gericht-Zeile.
            'einzelpreise' => $concept->istEinzelpreis(),
            'gerichte' => $kz['zeilen'],
            'legende' => $kz['legende'],
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $concept->updated_at,
        ];
    }

    public function vorschauSnapshot(FoodAlchemistFoodbook $fb): ?array
    {
        $snapshot = $fb->preview_snapshot_json;
        if (! is_array($snapshot)) {
            return null;
        }

        $snapshot['snapshot_at'] = $fb->preview_snapshot_at;

        return $snapshot;
    }

    public function vorschauSnapshotAktualisieren(Team $team, int $foodbookId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->basis->guard($fb, $team);

        $snapshot = $this->dokumentDaten($team, $fb);
        unset($snapshot['fb']);
        $snapshot['snapshot_version'] = 1;

        $fb->forceFill([
            'preview_snapshot_json' => $snapshot,
            'preview_snapshot_at' => now(),
        ])->save();

        return $snapshot;
    }

    // ── Branding (pro Foodbook) ─────────────────────────────────────────────────
    //
    // UI-agnostische API: der Branding/CI-Tab im Cockpit (separate Session) UND MCP/Console
    // rufen dieselben Methoden. Owner-Guard wie überall (D1). Bilder laufen fuer neue Uploads
    // ueber Core ContextFiles (zentraler Disk/S3), alte public-Pfade bleiben lesbar.

    /** Setzt Farb-/Text-Marke. $in: brand_color, band_color, footer_text (jeweils optional). */
    public function setBranding(Team $team, int $foodbookId, array $in): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->basis->guard($fb, $team);

        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $this->normHexOderThrow($in['brand_color'], 'Markenfarbe') ?? '#6d28d9';
        }
        if (array_key_exists('band_color', $in)) {
            // Leer → null (Blade leitet dann aus brand_color ab).
            $daten['band_color'] = $this->normHexOderThrow($in['band_color'], 'Bandfarbe', erlaubeLeer: true);
        }
        if (array_key_exists('footer_text', $in)) {
            $t = trim((string) $in['footer_text']);
            $daten['footer_text'] = $t !== '' ? $t : null;
        }
        if ($daten !== []) {
            $fb->update($daten);
        }

        return $fb->refresh();
    }

    public function storeLogo(Team $team, int $foodbookId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $foodbookId, $file, 'logo_path');
    }

    public function storeCover(Team $team, int $foodbookId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $foodbookId, $file, 'cover_image_path');
    }

    public function clearLogo(Team $team, int $foodbookId): FoodAlchemistFoodbook
    {
        return $this->loescheBrandingBild($team, $foodbookId, 'logo_path');
    }

    public function clearCover(Team $team, int $foodbookId): FoodAlchemistFoodbook
    {
        return $this->loescheBrandingBild($team, $foodbookId, 'cover_image_path');
    }

    private function speichereBrandingBild(Team $team, int $foodbookId, UploadedFile $file, string $spalte): string
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->basis->guard($fb, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $fb->{$spalte};
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($fb->{$contextSpalte}, $alt, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($fb, $spalte)]);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file,
            $team,
            'foodalchemist.foodbook',
            $foodbookId,
            "foodalchemist/branding/{$foodbookId}",
        );
        $pfad = $media['path'];
        $fb->update([
            $spalte => $pfad,
            $contextSpalte => $media['context_file_id'],
        ]);

        return $pfad;
    }

    private function loescheBrandingBild(Team $team, int $foodbookId, string $spalte): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->basis->guard($fb, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $fb->{$spalte};
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($fb->{$contextSpalte}, $alt, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($fb, $spalte)]);
        $fb->update([
            $spalte => null,
            $contextSpalte => null,
        ]);

        return $fb->refresh();
    }

    /**
     * Marken-Tokens fürs Dokument-Blade. Logo/Cover als base64-Data-URI (DomPDF lädt keine
     * http-URLs, enable_remote ist aus) — funktioniert im HTML- wie im PDF-Pfad. band leer →
     * aus brand_color, footer null → Blade nutzt Default-Zeile.
     *
     * @return array{color:string, band:string, logo:?string, cover:?string, footer:?string}
     */
    private function brandingDaten(FoodAlchemistFoodbook $fb): array
    {
        $color = ($fb->brand_color ?? '') !== '' ? $fb->brand_color : '#6d28d9';

        return [
            'color' => $color,
            'band' => ($fb->band_color ?? '') !== '' ? $fb->band_color : $color,
            'logo' => app(FoodAlchemistMediaService::class)->dataUri($fb->logo_context_file_id, $fb->logo_path),
            'cover' => app(FoodAlchemistMediaService::class)->dataUri($fb->cover_context_file_id, $fb->cover_image_path),
            'footer' => ($fb->footer_text ?? '') !== '' ? $fb->footer_text : null,
        ];
    }

    private function brandingContextSpalte(string $spalte): string
    {
        return match ($spalte) {
            'logo_path' => 'logo_context_file_id',
            'cover_image_path' => 'cover_context_file_id',
            default => throw new \InvalidArgumentException("Unbekannte Branding-Bildspalte: {$spalte}"),
        };
    }

    /** Hex-Validierung wie Settings\Kueche::sanitizeFarben. erlaubeLeer=true → '' ⇒ null. */
    private function normHexOderThrow($wert, string $feld, bool $erlaubeLeer = false): ?string
    {
        $v = trim((string) $wert);
        if ($v === '') {
            if ($erlaubeLeer) {
                return null;
            }
            throw new \RuntimeException("{$feld} darf nicht leer sein.");
        }
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            throw new \RuntimeException("{$feld}: ungültiger Farbwert „{$v}“ (erwartet #RRGGBB).");
        }

        return strtolower($v);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
}
