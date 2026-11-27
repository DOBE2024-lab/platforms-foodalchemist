<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Enums\AussageTyp;
use Platform\FoodAlchemist\Enums\Kantenart;
use Platform\FoodAlchemist\Services\Pairing\AnkerGraph;
use Platform\FoodAlchemist\Support\AnkerQuelle;
use Platform\FoodAlchemist\Support\Text;

/**
 * M5-04/05: GL-10 — Pairing-Kohäsion & Anker-Graph (deterministisch, ohne KI).
 * Queries read-only (Inv. 7); Schreibpfade nur set/remove (Caps Inv. 1,
 * manual gewinnt Inv. 3). Kanten sind seit dem V-23-Backfill symmetrisch (Inv. 4).
 * Fehlende Kante = unbekannt, nie Clash (Inv. 5); Scores = runde Ganzzahlen (Inv. 8).
 */
class PairingService
{
    private ?AnkerAufloesung $ankerTeil = null;

    private ?GpAnkerPflege $gpAnkerTeil = null;

    private function gpAnker(): GpAnkerPflege
    {
        return $this->gpAnkerTeil ??= app(GpAnkerPflege::class);
    }

    private function anker(): AnkerAufloesung
    {
        return $this->ankerTeil ??= app(AnkerAufloesung::class);
    }

    /** Spec 60 · P2: einzige Lesestelle für die Harmonie zwischen Ankern. */
    private function graph(): AnkerGraph
    {
        return app(AnkerGraph::class);
    }

    // ── Slug-Matching (Tabelle 2/3) ──────────────────────────────────────

    /** {@see AnkerAufloesung::normalizeAnkerSlug()} */
    public function normalizeAnkerSlug(string $s): string
    {
        return $this->anker()->normalizeAnkerSlug($s);
    }

    /** {@see AnkerAufloesung::ankerSlugMatches()} */
    public function ankerSlugMatches(string $a, string $b): bool
    {
        return $this->anker()->ankerSlugMatches($a, $b);
    }

    // ── Komponenten-Auflösung (3.1) ──────────────────────────────────────

    /** {@see AnkerAufloesung::fold()} */
    public function fold(string $s): string
    {
        return $this->anker()->fold($s);
    }

    /** {@see AnkerAufloesung::resolveByName()} */
    public function resolveByName(string $name): ?int
    {
        return $this->anker()->resolveByName($name);
    }

    /** {@see AnkerAufloesung::lexicalAnkerKandidaten()} */
    public function lexicalAnkerKandidaten(string $name, int $limit = 8): array
    {
        return $this->anker()->lexicalAnkerKandidaten($name, $limit);
    }

    /** {@see AnkerAufloesung::resolveAnkerSemantically()} */
    private function resolveAnkerSemantically(string $name): ?int
    {
        return $this->anker()->resolveAnkerSemantically($name);
    }

    /** {@see AnkerAufloesung::neutralAnkerId()} */
    private function neutralAnkerId(): mixed
    {
        return $this->anker()->neutralAnkerId();
    }

    /**
     * V-045: bereits eager geladene Zutaten wiederverwenden statt neu zu fragen.
     *
     * Der Kandidaten-Pool lädt `ingredients` (+ `gp`/`referencedRecipe`) im Begriffe-/
     * Convenience-Modus schon eager — die Requery hier war eine Query je Gericht auf
     * Daten, die der Aufrufer in der Hand hält. Wiederverwendet wird **nur**, wenn auch
     * die beiden Label-Relationen geladen sind: sonst löste der Zugriff je Zeile ein
     * Lazy-Load aus und wäre teurer als die eine Sammel-Query.
     *
     * Zwei Feinheiten, damit das Ergebnis identisch bleibt: die Relation ist wegen
     * `SoftDeletes` am Ingredient-Model schon ohne getrashte Zeilen (das `whereNull`
     * unten ist dort redundant), und `position` wird in PHP sortiert, weil eine geladene
     * Relation keine `orderBy`-Garantie mitbringt.
     *
     * @return iterable<int, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient>
     */
    private function ankerZutaten(FoodAlchemistRecipe $recipe): iterable
    {
        if ($recipe->relationLoaded('ingredients')) {
            $zutaten = $recipe->ingredients;
            $labelRelationenDa = $zutaten->every(
                fn ($z) => $z->relationLoaded('gp') && $z->relationLoaded('referencedRecipe')
            );
            if ($labelRelationenDa) {
                return $zutaten->sortBy('position')->values();
            }
        }

        return $recipe->ingredients()
            ->with(['gp:id,name', 'referencedRecipe:id,name'])
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();
    }

    /**
     * V-045 (zweiter Halbschritt): die Mapping-Lookups für MEHRERE Rezepte in einem Zug.
     *
     * Der Einzel-Fall {@see resolveRecipeAnchors} delegiert hierher — es gibt genau **eine**
     * Auflösungs-Wahrheit, damit Pool und Solver nicht auf einer zweiten Auswahl-Logik
     * rechnen als die Detail-Anzeige. Dominante Ebene sind die Mapping-Lookups **je Zutat**
     * (bei 1.000 Gerichten × ~12 Zutaten rund 12.000 Einzel-Queries); der erste Halbschritt
     * hat nur die Per-Gericht-Ebene erwischt.
     *
     * **Die Auswahl bleibt in SQL.** Jede der drei Mapping-Tabellen wird mit demselben
     * `ORDER BY COALESCE(ai_confidence, 1.0) DESC, id` gelesen wie vorher, nur mit `whereIn`
     * über alle Schlüssel; „gewonnen" hat die **erste** Zeile je Schlüssel. Die Auswahl in PHP
     * nachzubauen wäre die eigentliche Gefahr dieses Umbaus (NULL sortiert anders, Gleichstand
     * über Einfüge- statt `id`-Reihenfolge) — sie ist deshalb bewusst nicht nachgebaut. Der
     * Golden-Test `AnkerAufloesungGoldenTest` friert genau das ein.
     *
     * @param  iterable<int, FoodAlchemistRecipe>  $recipes
     * @return array<int, array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>> keyBy recipe id
     */
    public function resolveRecipeAnchorsMany(iterable $recipes): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, FoodAlchemistRecipe> $rezepte */
        $rezepte = (new \Illuminate\Database\Eloquent\Collection(
            $recipes instanceof \Traversable ? iterator_to_array($recipes) : (array) $recipes
        ))->filter(fn ($r) => $r instanceof FoodAlchemistRecipe && $r->id !== null)->keyBy('id');

        if ($rezepte->isEmpty()) {
            return [];
        }

        // Zutaten + Label-Relationen für den ganzen Satz — `loadMissing`, weil ein Aufrufer
        // sie mit einem breiteren Select geladen haben kann (Pool: `gp:id,name,tag_is_convenience`).
        // Ein blindes `load` würde diese Spalten still wegnehmen und den Convenience-Anteil kippen.
        $rezepte->loadMissing(['ingredients', 'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name']);

        $zutatenJeRezept = [];
        $subRezeptIds = [];
        $gpIds = [];
        foreach ($rezepte as $rid => $recipe) {
            $zutaten = $this->ankerZutaten($recipe);
            $zutatenJeRezept[$rid] = $zutaten;
            foreach ($zutaten as $z) {
                if ($z->referenced_recipe_id !== null) {
                    $subRezeptIds[$z->referenced_recipe_id] = true;
                } elseif ($z->gp_id !== null) {
                    $gpIds[$z->gp_id] = true;
                }
            }
        }

        // Spec 60 · P7c: Basisrezept-Kern = stärkster Anker seines Aromenprofils (statt KI-Beutel am Rezept).
        $rezeptKerne = $this->profilKerne(array_keys($subRezeptIds));
        $gpKerne = $this->kernMappingsBatch('foodalchemist_gp_anchor_mappings', 'gp_id', array_keys($gpIds));

        $out = [];
        foreach ($zutatenJeRezept as $rid => $zutaten) {
            $out[$rid] = $this->ankerZeilen($zutaten, $rezeptKerne, $gpKerne);
        }

        return $out;
    }

    /**
     * Spec 60 · P7c: Kern-Anker je Basisrezept = stärkster Anker seines Aromenprofils
     * ({@see RezeptProfil}). Rezepte ohne Profil fehlen im Ergebnis.
     *
     * @param  array<int, int|string>  $recipeIds
     * @return array<int, int> recipe_id → anchor_id
     */
    private function profilKerne(array $recipeIds): array
    {
        $profil = app(\Platform\FoodAlchemist\Services\Pairing\RezeptProfil::class);
        $out = [];
        foreach (array_unique(array_map('intval', $recipeIds)) as $id) {
            $kern = $profil->fuer($id)['anker'][0]['anchor_id'] ?? null;
            if ($kern !== null) {
                $out[$id] = (int) $kern;
            }
        }

        return $out;
    }

    /**
     * Kern-Mapping je Schlüssel: dieselbe Sortierung wie der frühere Einzel-`value()`-Aufruf,
     * nur mit `whereIn`. Die erste Zeile je Schlüssel gewinnt (`isset`-Riegel) — die DB
     * entscheidet also weiterhin, nicht PHP.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, mixed> Schlüssel → anchor_id (untypisiert wie zuvor)
     */
    private function kernMappingsBatch(string $tabelle, string $spalte, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $treffer = [];
        foreach (DB::table($tabelle)->whereIn($spalte, $ids)->where('role', 'kern')->whereNull('deleted_at')
            ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')
            ->get([$spalte, 'anchor_id']) as $zeile) {
            if (! isset($treffer[$zeile->{$spalte}])) {
                $treffer[$zeile->{$spalte}] = $zeile->anchor_id;
            }
        }

        return $treffer;
    }

    /**
     * resolve_recipe_anchors (Tabelle 4): pro Zutaten-Zeile GENAU EIN Kern
     * (+ Prozess-Anker nur bei Sub-Rezepten).
     *
     * @return array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>
     */
    public function resolveRecipeAnchors(FoodAlchemistRecipe $recipe): array
    {
        return $this->resolveRecipeAnchorsMany([$recipe])[$recipe->id] ?? [];
    }

    /**
     * Die Zeilen-Schleife selbst — unverändert in der Logik, sie liest die Mappings jetzt
     * aus den Batch-Karten statt sie je Zutat zu erfragen.
     *
     * @param  iterable<int, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient>  $zutaten
     * @param  array<int|string, mixed>  $rezeptKerne
     * @param  array<int|string, mixed>  $gpKerne
     * @return array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>
     */
    private function ankerZeilen(
        iterable $zutaten,
        array $rezeptKerne,
        array $gpKerne,
    ): array {
        $neutralId = $this->neutralAnkerId();
        $out = [];
        foreach ($zutaten as $z) {
            $label = $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->raw_text;
            $kern = null;
            $via = 'unresolved';
            // Spec 60 · P3: Prozess-Anker gibt es nicht mehr (Zubereitung = Verfahren, P4). Der
            // Schlüssel bleibt bis zur Umstellung der Leser (P6) als leere Liste stehen.
            $prozess = [];

            if ($z->referenced_recipe_id !== null) {
                $kern = $rezeptKerne[$z->referenced_recipe_id] ?? null;
                $via = $kern !== null ? 'rezept_profil' : 'unresolved';
            } elseif ($z->gp_id !== null) {
                $mapping = $gpKerne[$z->gp_id] ?? null;
                if ($mapping !== null) {
                    [$kern, $via] = $mapping === $neutralId ? [null, 'neutral'] : [$mapping, 'gp_anker'];
                } else {
                    // Spec 58 · Paket 1: nur exakter Grundname, kein Wortteil-Treffer.
                    $kern = $this->ankerIdExakt($z->gp->name);
                    $via = $kern !== null ? 'exakt_name' : 'unresolved';
                }
            } else {
                $kern = $this->ankerIdExakt($z->raw_text);
                $via = $kern !== null ? 'exakt_name' : 'unresolved';
            }

            // B: semantischer Fallback NUR für sonst unauflösbare Zeilen (opt-in,
            // hinter foodalchemist.semantic_search.enabled). Überschreibt NIE
            // explizite gp/recipe-Mappings; markiert via='embedding' für Provenienz.
            if ($kern === null && $via === 'unresolved') {
                $semId = $this->resolveAnkerSemantically($label);
                if ($semId !== null) {
                    $kern = $semId;
                    $via = 'embedding';
                }
            }

            $out[] = ['label' => $label, 'kern' => $kern, 'prozess' => $prozess, 'via' => $via];
        }

        return $out;
    }

    /**
     * Spec 60 · P5: Kern-Anker je Grundprodukt (beste Zuordnung, wie die Anker-Auflösung).
     *
     * @param  array<int, int|string>  $gpIds
     * @return array<int|string, mixed> gp_id → anchor_id
     */
    public function gpKernAnker(array $gpIds): array
    {
        return $this->kernMappingsBatch('foodalchemist_gp_anchor_mappings', 'gp_id', array_values(array_unique($gpIds)));
    }

    /** {@see AnkerAufloesung::gpAnkerZuordnungen()} */
    public function gpAnkerZuordnungen(array $gpIds): Collection
    {
        return $this->anker()->gpAnkerZuordnungen($gpIds);
    }

    /** {@see AnkerAufloesung::neutralAnker()} */
    public function neutralAnker(): ?int
    {
        return $this->anker()->neutralAnker();
    }

    /** {@see AnkerAufloesung::ankerSlugVonId()} */
    private function ankerSlugVonId(int $id): ?string
    {
        return $this->anker()->ankerSlugVonId($id);
    }

    // ── Kohäsion (3.2 — T4/T5/T6/T9) ─────────────────────────────────────

    /**
     * @param array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}> $komponenten
     */
    public function cohesionFor(array $komponenten): array
    {
        $aufgeloest = array_values(array_filter($komponenten, fn ($k) => $k['kern'] !== null || $k['prozess'] !== []));
        $n = count($aufgeloest);
        $totalPairs = intdiv($n * ($n - 1), 2);

        $alleAnker = [];
        foreach ($aufgeloest as $k) {
            foreach (array_merge($k['kern'] !== null ? [$k['kern']] : [], $k['prozess']) as $a) {
                $alleAnker[$a] = true;
            }
        }
        $kanten = $this->edgeBest(array_keys($alleAnker));
        // Spec 60 · P7c: Inspire hat JEDES Paar seiner Anker gemessen. Ein Paar zweier Inspire-Anker
        // ohne 3★ ist darum bewertet (neutral, 0) — „unbewertet" bleibt nur, wer keinen Inspire-Anker hat.
        $gemessen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_keys($alleAnker))
            ->whereNotNull('inspire_id')->pluck('id')->map(fn ($i) => (int) $i)->flip()->all();

        $staerken = [];
        $unrated = [];
        $fit = array_fill(0, $n, ['sum' => 0.0, 'cnt' => 0]);
        $schwaechstes = null;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $w = null;
                $typ = null;
                foreach (array_merge($aufgeloest[$i]['kern'] !== null ? [$aufgeloest[$i]['kern']] : [], $aufgeloest[$i]['prozess']) as $ka) {
                    foreach (array_merge($aufgeloest[$j]['kern'] !== null ? [$aufgeloest[$j]['kern']] : [], $aufgeloest[$j]['prozess']) as $kb) {
                        if ((int) $ka === (int) $kb) {
                            [$w, $typ] = [1.0, 'gleich'];
                        } elseif (isset($kanten[$ka][$kb]) && ($w === null || $kanten[$ka][$kb][0] > $w)) {
                            [$w, $typ] = $kanten[$ka][$kb];
                        }
                    }
                }
                if ($w === null && $aufgeloest[$i]['kern'] !== null && $aufgeloest[$j]['kern'] !== null
                    && isset($gemessen[(int) $aufgeloest[$i]['kern']], $gemessen[(int) $aufgeloest[$j]['kern']])) {
                    [$w, $typ] = [0.0, 'neutral'];
                }
                if ($w !== null) {
                    $staerken[] = $w;
                    $fit[$i]['sum'] += $w;
                    $fit[$i]['cnt']++;
                    $fit[$j]['sum'] += $w;
                    $fit[$j]['cnt']++;
                    if ($schwaechstes === null || $w < $schwaechstes['w']) {
                        $schwaechstes = ['w' => $w, 'a' => $aufgeloest[$i]['label'], 'b' => $aufgeloest[$j]['label'], 'type' => $typ];
                    }
                } else {
                    $unrated[] = [$aufgeloest[$i]['label'], $aufgeloest[$j]['label']];
                }
            }
        }

        $anyRated = $staerken !== [];
        $komponentenOut = [];
        foreach ($aufgeloest as $i => $k) {
            $komponentenOut[] = [
                'label' => $k['label'], 'via' => $k['via'],
                'fit' => $fit[$i]['cnt'] > 0 ? (int) round(100 * $fit[$i]['sum'] / $fit[$i]['cnt']) : null,
                'rated_links' => $fit[$i]['cnt'],
                'is_orphan' => $k['kern'] !== null && $fit[$i]['cnt'] === 0 && $anyRated,  // T9: keine Daten ≠ kein Fit
            ];
        }

        return [
            'score' => $anyRated ? (int) round(100 * array_sum($staerken) / count($staerken)) : 0,
            'min_score' => $anyRated ? (int) round(100 * min($staerken)) : 0,
            'rated_pairs' => count($staerken),
            'total_pairs' => $totalPairs,
            'coverage_pct' => $totalPairs > 0 ? (int) round(100 * count($staerken) / $totalPairs) : 0,
            'weakest_pair' => $schwaechstes !== null
                ? ['a' => $schwaechstes['a'], 'b' => $schwaechstes['b'], 'score' => (int) round(100 * $schwaechstes['w']), 'type' => $schwaechstes['type']]
                : null,
            'unrated_pairs' => $unrated,
            'komponenten' => $komponentenOut,
        ];
    }

    public function recipeCohesion(FoodAlchemistRecipe $recipe): array
    {
        return $this->cohesionFor($this->resolveRecipeAnchors($recipe));
    }

    /** R6.1: flache, eindeutige Anker-IDs eines Rezepts (kern + prozess über alle Zutaten). */
    public function anchorsForRecipe(FoodAlchemistRecipe $recipe): array
    {
        return $this->flacheAnker($this->resolveRecipeAnchors($recipe));
    }

    /**
     * Dieselbe Abflachung wie {@see anchorsForRecipe}, aber auf schon aufgelösten Komponenten —
     * damit ein Batch-Aufrufer (Pool/Solver) nicht seine eigene Variante schreibt.
     *
     * @param  array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>  $komponenten
     * @return array<int, int|string>
     */
    public function flacheAnker(array $komponenten): array
    {
        $ids = [];
        foreach ($komponenten as $k) {
            foreach (array_merge($k['kern'] !== null ? [$k['kern']] : [], $k['prozess']) as $a) {
                $ids[$a] = true;
            }
        }

        return array_keys($ids);
    }

    /** R6.1: beste Kanten zwischen Anker-IDs (öffentlicher Zugriff auf edgeBest — Menü-Ranking im Generator). */
    public function edgesFor(array $ankerIds): array
    {
        return $this->edgeBest($ankerIds);
    }

    /**
     * Spec 21 · S4b-2 — WIEDERHOLUNG über eine Menüfolge: welche Gerichte tragen
     * dieselbe Hauptzutat? Gegenstück zu {@see menuCohesion}, bewusst als eigener
     * Weg und nicht als weiterer Rückgabe-Schlüssel dort:
     *
     *  · `cohesionFor` bewertet einen geteilten Anker als **1,0 — das Maximum**
     *    (Paar-Typ `gleich`). Für den Teller ist das richtig (die Komponenten
     *    gehören zusammen), für die Menüfolge ist es die Diagnose auf dem Kopf:
     *    zweimal Lachs hebt dort den Score, obwohl es genau der Mangel ist.
     *    Denselben Wert einmal als Stärke und einmal als Befund zu lesen, wären
     *    zwei Wahrheiten in einer Zahl.
     *  · `menuCohesion` legt je Gericht die **Union aller Zutaten-Anker** an. Ein
     *    geteilter Anker heißt dort auch „beide enthalten Butter" — für die
     *    Dramaturgie-Frage zählt nur, worum es in dem Gang geht.
     *
     * Die Hauptzutat ist deshalb die **mengenmäßig dominierende Zutat** des Gerichts
     * (größte Roh-Einsatzmasse, gleiche Formel wie `RecipeRecomputeService`: Mittelwert
     * eines Mengen-Bereichs × `default_in_g`), und ihr Identitäts-Anker ist der `kern`
     * ihres GP bzw. — bei einer Sub-Rezept-Zeile — der des Sub-Rezepts; dieselbe Wahl,
     * die {@see resolveRecipeAnchors} je Zutaten-Zeile trifft (höchste `ai_confidence`,
     * dann `id`). Bewusst NICHT genommen: die früheren KI-Anker am Gericht (bis Spec 60)
     * selbst — das liest sich wie ein Identitäts-Feld, ist im Bestand aber ein *Beutel*
     * aller KI-erkannten Zutaten-Anker (bis zu 24 je Rezept, durchweg ohne Konfidenz).
     * Daraus einen zu wählen hieße raten, und „beide fangen mit Butter an" wäre dasselbe
     * Signal wie „beide sind Lachs".
     *
     * Zwei weitere Auslegungen:
     *  · Gruppiert wird über {@see ankerSlugMatches}, nicht über die Anker-ID:
     *    `lachs` und `lachs_wild` sind für den Gast dieselbe Hauptzutat. Der Match
     *    ist prefix-basiert und damit nicht transitiv — Repräsentant einer Gruppe
     *    ist deshalb das zuerst gesehene Gericht (deterministisch über `recipe_id`).
     *  · Was sich nicht in Masse vergleichen lässt (Stück-/Volumen-Einheit, `qs`,
     *    optionale Zeile) oder keinen Anker trägt, bleibt **unbewertet** — kein Befund.
     *    Fehlende Erdung ist keine Aussage über das Menü (T9). `neutral` ist kein
     *    Identitäts-Anker, so behandelt es auch `resolveRecipeAnchors`.
     *
     * @param  list<int>  $dishIds
     * @return list<array{slug: string, recipe_ids: list<int>}>
     */
    public function menuRepetitions(array $dishIds): array
    {
        $dishIds = array_values(array_unique(array_map('intval', $dishIds)));
        if (count($dishIds) < 2) {
            return [];
        }

        // Einheiten-Gate wie beim Ausbeute-Check (Spec 21 · S1b): ein Gericht wird nur
        // bewertet, wenn ALLE beitragenden Zeilen massen-vergleichbar sind. Sonst gewönne
        // die schwerste *messbare* Zeile — in einer Mayonnaise mit 0,25 l Sojamilch und
        // 0,5 l Öl wäre das das Salz, und der Befund behauptete eine Hauptzutat, die
        // keine ist. Lieber unbewertet als falsch.
        $unvergleichbar = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_vocab_units AS u', 'u.id', '=', 'ri.unit_vocab_id')
            ->whereIn('ri.recipe_id', $dishIds)
            ->whereNull('ri.deleted_at')
            ->where('ri.is_optional', false)
            ->where(fn ($w) => $w->whereNull('u.slug')->orWhere('u.slug', '!=', 'qs'))
            ->where(fn ($w) => $w->whereNull('u.dimension')->orWhere('u.dimension', '!=', 'mass')
                ->orWhereNull('u.default_in_g')->orWhere('u.default_in_g', '<=', 0))
            ->distinct()->pluck('ri.recipe_id')->map(fn ($i) => (int) $i)->all();
        $dishIds = array_values(array_diff($dishIds, $unvergleichbar));
        if (count($dishIds) < 2) {
            return [];
        }

        // Schwerste Zeile je Gericht (Bereich = Mittelwert, §6.4).
        $top = [];
        $rows = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->join('foodalchemist_vocab_units AS u', 'u.id', '=', 'ri.unit_vocab_id')
            ->whereIn('ri.recipe_id', $dishIds)
            ->whereNull('ri.deleted_at')
            ->where('ri.is_optional', false)
            ->where('u.slug', '!=', 'qs')
            ->where('u.dimension', 'mass')
            ->where('u.default_in_g', '>', 0)
            ->where('ri.quantity', '>', 0)
            ->selectRaw('ri.recipe_id, ri.gp_id, ri.referenced_recipe_id, ri.position,'
                . ' ((ri.quantity + COALESCE(ri.quantity_max, ri.quantity)) / 2) * u.default_in_g AS masse_g')
            ->orderBy('ri.recipe_id')
            ->orderByDesc('masse_g')
            ->orderBy('ri.position')
            ->get();
        foreach ($rows as $r) {
            $top[(int) $r->recipe_id] ??= $r;
        }

        $kernJeGericht = [];
        $gpKerne = $this->kernSlugs('foodalchemist_gp_anchor_mappings', 'gp_id',
            array_map(fn ($z) => (int) $z->gp_id, array_filter($top, fn ($z) => $z->referenced_recipe_id === null && $z->gp_id !== null)));
        $subKerne = array_map(fn ($id) => $this->ankerSlugVonId((int) $id),
            $this->profilKerne(array_map(fn ($z) => (int) $z->referenced_recipe_id, array_filter($top, fn ($z) => $z->referenced_recipe_id !== null))));
        foreach ($top as $recipeId => $z) {
            $slug = $z->referenced_recipe_id !== null
                ? ($subKerne[(int) $z->referenced_recipe_id] ?? null)
                : ($z->gp_id !== null ? ($gpKerne[(int) $z->gp_id] ?? null) : null);
            if ($slug !== null) {
                $kernJeGericht[$recipeId] = $slug;
            }
        }
        ksort($kernJeGericht);

        $gruppen = [];
        foreach ($kernJeGericht as $recipeId => $slug) {
            foreach ($gruppen as &$g) {
                if ($this->ankerSlugMatches($g['slug'], $slug)) {
                    $g['recipe_ids'][] = $recipeId;

                    continue 2;
                }
            }
            unset($g);
            $gruppen[] = ['slug' => $slug, 'recipe_ids' => [$recipeId]];
        }

        return array_values(array_filter($gruppen, fn ($g) => count($g['recipe_ids']) > 1));
    }

    /**
     * Kern-Anker-Slug je Besitzer (GP oder Sub-Rezept) — dieselbe Wahl wie in
     * {@see resolveRecipeAnchors}: höchste `ai_confidence`, dann `id`. `neutral`
     * gilt dort wie hier als „kein Kern".
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function kernSlugs(string $tabelle, string $spalte, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = DB::table($tabelle . ' AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->whereIn('m.' . $spalte, $ids)
            ->where('m.role', 'kern')
            ->where('a.slug', '!=', 'neutral')
            ->whereNull('m.deleted_at')
            ->whereNull('a.deleted_at')
            ->orderBy('m.' . $spalte)
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')
            ->orderBy('m.id')
            ->get(['m.' . $spalte . ' AS owner_id', 'a.slug']);
        foreach ($rows as $r) {
            $out[(int) $r->owner_id] ??= (string) $r->slug;
        }

        return $out;
    }

    /**
     * R6.1 Kohäsions-Beweis über eine MENÜFOLGE: jedes Gericht ist EINE Komponente
     * (Anker = Union seiner Zutaten-Anker), Score/Coverage/schwächstes Paar über die
     * Gericht-Paare. Gleiche Mechanik wie der Teller-Score (cohesionFor), eine Ebene höher.
     *
     * @param  list<FoodAlchemistRecipe>  $dishes
     */
    public function menuCohesion(array $dishes): array
    {
        $komponenten = [];
        foreach ($dishes as $dish) {
            $komponenten[] = [
                'label' => $dish->name,
                'via' => 'menu',
                'kern' => null,
                'prozess' => $this->anchorsForRecipe($dish),
            ];
        }

        return $this->cohesionFor($komponenten);
    }

    /**
     * Roadmap Etappe 1 »LLM-Foodpairing als Assist«: das Aroma-Profil einer bereits
     * (teil)belegten Menüfolge — die Anker der schon gesetzten Gerichte plus die
     * harmonischen Partner-Anker aus dem Graphen (Expansion AUSSERHALB des getragenen
     * Sets). Speist die KI-Ideen-Erfindung ({@see IdeenService::kiDivergenzConcept}),
     * damit neue Gerichte auf der Aroma-Achse zur Folge PASSEN statt frei geraten zu
     * werden. Liefert `null`, wenn die Folge keine auflösbaren Anker trägt (leeres oder
     * ungemapptes Menü) — dann bleibt der Prompt byte-identisch, ohne Erdungs-Block.
     *
     * @param  iterable<FoodAlchemistRecipe>  $recipes  die bereits gesetzten Gerichte der Folge
     * @return array{anker: list<string>, partner: list<string>}|null
     */
    public function menueAromaProfil(iterable $recipes, int $partnerLimit = 12): ?array
    {
        $ankerIds = [];
        foreach ($this->resolveRecipeAnchorsMany($recipes) as $komponenten) {
            foreach ($this->flacheAnker($komponenten) as $id) {
                $ankerIds[(int) $id] = true;
            }
        }
        $ankerIds = array_keys($ankerIds);
        if ($ankerIds === []) {
            return null;
        }

        // Anker-Namen der Folge (display_de, sonst slug) — stabil alphabetisch.
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ankerIds)->whereNull('deleted_at')
            ->orderBy('display_de')->get(['slug', 'display_de'])
            ->map(fn ($a) => (string) ($a->display_de ?: $a->slug))
            ->filter()->unique()->values()->all();
        if ($anker === []) {
            return null;
        }

        // Harmonische Partner: gewichtsstärkste Expansion außerhalb der schon getragenen
        // Anker (Stern-Stufe → Gewicht → Abdeckung). Dubletten-frei, gekappt.
        [$kandidaten] = app(NetzKandidaten::class)->fuerAnker($ankerIds);
        usort($kandidaten, fn ($a, $b) => [$b['level'], $b['weight'], $b['cover']] <=> [$a['level'], $a['weight'], $a['cover']]);
        $partner = [];
        foreach (array_slice($kandidaten, 0, max(0, $partnerLimit)) as $k) {
            $lbl = (string) ($k['display_de'] ?: $k['slug']);
            if ($lbl !== '') {
                $partner[$lbl] = true;
            }
        }

        return ['anker' => $anker, 'partner' => array_keys($partner)];
    }

    /**
     * Roadmap Etappe 1 »Menü-Folge-Kohärenz-Gate«: macht aus einem rohen
     * {@see menuCohesion}-Ergebnis eine abgestufte, sichtbare Rückkopplung/Warnung.
     * Ergänzt die Erdung ({@see menueAromaProfil}) um die Gegenprobe: nachdem die
     * Erfindung am Graphen geerdet wurde, wird die entstandene Folge auch GEGEN die
     * Folge-Kohäsion geprüft, nicht nur blind erzeugt.
     *
     * Die Schwellen sind byte-genau aus dem Anzeige-Panel gespiegelt
     * (`kohaesion-panel.blade.php`: ≥60 emerald, ≥35 amber, sonst rose) — Gate und
     * Farb-Band sagen damit dasselbe, keine zweite Wahrheit. Liefert `null`, wenn
     * nichts zu beurteilen ist: zu wenige Gerichte ODER kein einziges bewertetes
     * Gericht-Paar. Fehlende Graph-Daten sind KEIN schlechtes Menü (T9) — nur keine
     * Aussage, und ein Nichts wird nicht als Warnung verkauft.
     *
     * @param  array  $kohaesion  Ergebnis aus {@see menuCohesion} / {@see cohesionFor}
     * @return array{stufe: 'gut'|'schwach'|'kritisch', score: int, text: string}|null
     */
    public function menuKohaesionWarnung(array $kohaesion): ?array
    {
        if (($kohaesion['zu_wenig'] ?? false) === true) {
            return null;
        }
        if ((int) ($kohaesion['rated_pairs'] ?? 0) < 1) {
            return null;   // kein bewertetes Paar → keine Aussage (T9), nicht „schlecht"
        }

        $score = (int) ($kohaesion['score'] ?? 0);
        $stufe = $score >= 60 ? 'gut' : ($score >= 35 ? 'schwach' : 'kritisch');

        $weakest = $kohaesion['weakest_pair'] ?? null;
        $paarHinweis = is_array($weakest)
            ? sprintf(' Schwächste Brücke: %s ↔ %s (%d).',
                (string) ($weakest['a'] ?? '?'), (string) ($weakest['b'] ?? '?'), (int) ($weakest['score'] ?? 0))
            : '';

        $text = match ($stufe) {
            'gut' => sprintf('Die Menüfolge trägt auf der Aroma-Achse (Score %d).', $score),
            'schwach' => sprintf('Die Menüfolge hängt nur lose zusammen (Score %d) — einzelne Gänge harmonieren schwach.%s', $score, $paarHinweis),
            'kritisch' => sprintf('Die Menüfolge wirkt aroma-fremd (Score %d) — die Gänge stehen kaum in Verbindung.%s', $score, $paarHinweis),
        };

        return ['stufe' => $stufe, 'score' => $score, 'text' => $text];
    }

    // ── Suggest (3.3 — T8) ───────────────────────────────────────────────

    /** @return array{klassiker: array, signature: array} */
    public function componentSuggestions(FoodAlchemistRecipe $recipe, int $top = 8): array
    {
        $dish = [];
        foreach ($this->resolveRecipeAnchors($recipe) as $k) {
            foreach (array_merge($k['kern'] !== null ? [$k['kern']] : [], $k['prozess']) as $a) {
                $dish[$a] = true;
            }
        }
        if (count($dish) < 2) {
            return ['klassiker' => [], 'signature' => []];
        }
        $dishIds = array_keys($dish);

        $kandidaten = [];
        // Spec 58 · Paket 4: nur 3★ (echtes Food Pairing) trägt einen Vorschlag — 2★ ist Rauschen.
        $diaet = $this->diaetFilter($recipe);
        foreach ($this->graph()->kanten($dishIds, null, AnkerGraph::HARMONIERT, $dishIds) as $kante) {
            $k = &$kandidaten[$kante->zu];
            $k['best'][$kante->von] = AnkerGraph::GEWICHT[AnkerGraph::HARMONIERT];
        }
        unset($k);

        $grade = $this->graph()->grad(array_keys($kandidaten));
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', array_merge(array_keys($kandidaten), $dishIds))   // + dish für »verbindet n/m: …«
            ->pluck('slug', 'id');

        $meta = $diaet !== null
            ? DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_keys($kandidaten))
                ->get(['id', 'slug', 'category', 'subcategory'])->keyBy('id')
            : collect();

        $liste = [];
        foreach ($kandidaten as $id => $daten) {
            $cover = count($daten['best']);
            if ($cover < 2) {
                continue;                                           // Filter cover ≥ 2
            }
            if ($diaet !== null && ($m = $meta->get($id)) !== null && ! $this->passtZurDiaet($m, $diaet)) {
                continue;                                           // kein Hühnerfond fürs vegane Gericht
            }
            $meanW = (int) round(100 * array_sum($daten['best']) / $cover);
            $degree = (int) ($grade[$id] ?? 0);
            $liste[] = [
                'anchor_id' => $id, 'slug' => (string) $namen[$id], 'cover' => $cover,
                'mean_w' => $meanW, 'degree' => $degree,
                'spec' => ($cover * $meanW / 100) / sqrt(max($degree, 1)),
                // Anzeige-Zusatz (Ist-App »Aroma-Nachbarn«): welche Teller-Anker er trifft,
                // |dish| als Nenner, Allrounder = promiskuitiver Kandidat (hoher Grad)
                'trifft' => collect(array_keys($daten['best']))->map(fn ($d) => (string) ($namen[$d] ?? $d))->sort()->values()->all(),
                'dish_n' => count($dishIds),
                'allrounder' => $degree >= 50,
            ];
        }

        $klassiker = $liste;
        usort($klassiker, fn ($a, $b) => [$b['cover'], $b['mean_w'], $a['degree'], $a['slug']] <=> [$a['cover'], $a['mean_w'], $b['degree'], $b['slug']]);
        $signature = $liste;
        usort($signature, fn ($a, $b) => [$b['spec'], $b['mean_w'], $a['slug']] <=> [$a['spec'], $a['mean_w'], $b['slug']]);

        return ['klassiker' => array_slice($klassiker, 0, $top), 'signature' => array_slice($signature, 0, $top)];
    }

    /**
     * Spec 58 · Paket 4: Ernährungsform des Gerichts → 'vegan' | 'vegetarisch' | null (keine Einschränkung).
     * Nur ein ausdrücklich gesetztes true schränkt ein — unbekannt ist keine Aussage.
     */
    private function diaetFilter(FoodAlchemistRecipe $recipe): ?string
    {
        if ($recipe->spec_is_vegan === true) {
            return 'vegan';
        }

        return $recipe->spec_is_vegetarian === true ? 'vegetarisch' : null;
    }

    /** {@see AnkerAufloesung::passtZurDiaet()} */
    public function passtZurDiaet(object $anker, string $diaet): bool
    {
        return $this->anker()->passtZurDiaet($anker, $diaet);
    }

    // ── Bridge / verwandte Rezepte / Nachbarn (3.4 — T7) ────────────────

    /**
     * Rezepte mit gemeinsamen Kern-Ankern im Aromenprofil (Spec 60 · P7c; vorher über die
     * Pairing-Chips). Kern = Anteil ≥ 10 %.
     */
    public function recipesSharingPairings(Team $team, int $recipeId, int $minShared = 2, int $limit = 10): Collection
    {
        $minShared = max(1, $minShared);
        $limit = max(1, min(50, $limit));
        $kern = \Platform\FoodAlchemist\Services\Pairing\RezeptGraph::KERN;
        $eigene = DB::table('foodalchemist_recipe_profile_anker')->where('recipe_id', $recipeId)
            ->where('anteil', '>=', $kern)->pluck('anchor_id');
        if ($eigene->isEmpty()) {
            return collect();
        }

        $treffer = DB::table('foodalchemist_recipe_profile_anker')
            ->whereIn('anchor_id', $eigene)->where('recipe_id', '!=', $recipeId)->where('anteil', '>=', $kern)
            ->selectRaw('recipe_id, COUNT(DISTINCT anchor_id) AS shared')
            ->groupBy('recipe_id')->havingRaw('COUNT(DISTINCT anchor_id) >= ?', [$minShared])
            ->get();
        $gesamt = DB::table('foodalchemist_recipe_profile_anker')->whereIn('recipe_id', $treffer->pluck('recipe_id'))
            ->where('anteil', '>=', $kern)->selectRaw('recipe_id, COUNT(*) AS n')->groupBy('recipe_id')->pluck('n', 'recipe_id');
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $treffer->pluck('recipe_id'))->pluck('name', 'id');

        return $treffer->filter(fn ($t) => $rezepte->has($t->recipe_id))
            ->map(fn ($t) => [
                'recipe_id' => (int) $t->recipe_id,
                'name' => $rezepte[$t->recipe_id],
                'shared' => (int) $t->shared,
                'eigene_gesamt' => (int) ($gesamt[$t->recipe_id] ?? 0),
            ])
            ->sortBy([fn ($a, $b) => [$b['shared'], $a['eigene_gesamt'], $a['recipe_id']] <=> [$a['shared'], $b['eigene_gesamt'], $b['recipe_id']]])
            ->take($limit)
            ->map(function (array $row) use ($eigene, $kern) {
                $row['shared_slugs'] = DB::table('foodalchemist_recipe_profile_anker AS m')
                    ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
                    ->where('m.recipe_id', $row['recipe_id'])->whereIn('m.anchor_id', $eigene)->where('m.anteil', '>=', $kern)
                    ->orderByDesc('m.anteil')->limit(5)->pluck('a.slug')->all();

                return $row;
            })
            ->values();
    }

    /** {@see AnkerAufloesung::ankerNeighbors()} */
    public function ankerNeighbors(string $slug, ?string $typ = null, int $limit = 30): Collection
    {
        return $this->anker()->ankerNeighbors($slug, $typ, $limit);
    }

    // ── Schreibpfade (Inv. 1/3) — 98 · 3.3: in GpAnkerPflege, hier Fassade (setGpAnkerStaerke bleibt) ──

    /** Spaltenbreite gp_anchor_mappings.source — {@see GpAnkerPflege::GP_ANKER_SOURCE_MAX}. */
    public const GP_ANKER_SOURCE_MAX = GpAnkerPflege::GP_ANKER_SOURCE_MAX;

    /** {@see GpAnkerPflege::darfAnkerPflegen()} */
    public function darfAnkerPflegen(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, Team $team): bool
    {
        return $this->gpAnker()->darfAnkerPflegen($gp, $team);
    }

    /** {@see GpAnkerPflege::pruefeAnkerPflege()} */
    private function pruefeAnkerPflege(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, Team $team): void
    {
        $this->gpAnker()->pruefeAnkerPflege($gp, $team);
    }

    /** {@see GpAnkerPflege::setGpAnker()} */
    public function setGpAnker(
        Team $team, int $gpId, int $ankerId, string $role = 'kern', string $source = 'manual',
        ?float $aiConfidence = null, ?string $aiReasoning = null,
    ): void {
        $this->gpAnker()->setGpAnker($team, $gpId, $ankerId, $role, $source, $aiConfidence, $aiReasoning);
    }

    public const GP_ANKER_STAERKEN = ['schwach', 'mittel', 'stark'];

    public function setGpAnkerStaerke(Team $team, int $gpId, int $ankerId, ?string $staerke): void
    {
        if ($staerke !== null && ! in_array($staerke, self::GP_ANKER_STAERKEN, true)) {
            throw new \InvalidArgumentException('Unbekannte Aromastärke.');
        }

        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        $this->pruefeAnkerPflege($gp, $team);
        DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->where('anchor_id', $ankerId)
            ->whereNull('deleted_at')->update(['staerke' => $staerke, 'updated_at' => now()]);
        app(RezeptProfil::class)->vergiss();
    }

    /** {@see GpAnkerPflege::removeGpAnker()} */
    public function removeGpAnker(Team $team, int $gpId, int $ankerId): void
    {
        $this->gpAnker()->removeGpAnker($team, $gpId, $ankerId);
    }

    /** {@see GpAnkerPflege::clearGpAnker()} */
    public function clearGpAnker(Team $team, int $gpId): void
    {
        $this->gpAnker()->clearGpAnker($team, $gpId);
    }

    /** {@see GpAnkerPflege::setGpAnkerInference()} */
    public function setGpAnkerInference(Team $team, int $gpId, int $ankerId, float $confidence, string $reasoning = 'Bulk-Anreicherung', string $quelle = AnkerQuelle::KI, string $role = 'kern'): bool
    {
        return $this->gpAnker()->setGpAnkerInference($team, $gpId, $ankerId, $confidence, $reasoning, $quelle, $role);
    }

    /** {@see GpAnkerPflege::gpAnkers()} */
    public function gpAnkers(int $gpId): Collection
    {
        return $this->gpAnker()->gpAnkers($gpId);
    }

    /** {@see GpAnkerPflege::gpAnkerAlle()} */
    public function gpAnkerAlle(int $gpId): Collection
    {
        return $this->gpAnker()->gpAnkerAlle($gpId);
    }

    /** {@see GpAnkerPflege::gpsForAnkerIds()} */
    public function gpsForAnkerIds(Team $team, array $ankerIds): Collection
    {
        return $this->gpAnker()->gpsForAnkerIds($team, $ankerIds);
    }

    // ── Kompakt-Panels fürs »Sensorik & Pairing«-Tab (read-only) ─────────

    /** Prozess-/neutrale Anker sind keine Zutat-Vorschläge (»Fermentiert« kauft man nicht). */
    private const NICHT_ZUTAT_ANKER = ['neutral', 'roestaromen', 'ferment', 'karamell', 'rauch'];

    /**
     * Pairing-Panel der Editoren (Gericht, Basisrezept) — Spec 60 · P10: dieselbe Aussage wie die
     * Detail-Spalte (Kombinationslogik + Netz), nicht mehr die alte Kohäsion über Anker-Paare.
     *
     *   kombination   „Passt das zusammen?" ({@see Kombinationslogik::daten})
     *   netz          das Netz (Gericht: Basisrezepte; Basisrezept: Kern-Anker mit Anteil)
     *   profil        Kern-Anker des Aromenprofils mit Anteil
     *   Gericht       passt_dazu / deckt_bedarf — die Basisrezepte aus dem Netz (eine Rechnung)
     *   Basisrezept   partner (★★★ der Kern-Anker), kontrast (Lieferanten für offene Bedarfe),
     *                 verwandte (Basisrezepte mit denselben Kern-Ankern)
     */
    public function panelRecipe(FoodAlchemistRecipe $recipe): array
    {
        $team = Team::find((int) $recipe->team_id);
        $istGericht = (bool) $recipe->is_sales_recipe;
        $netz = $team !== null ? $this->pairingNetz($team, (int) $recipe->id) : ['nodes' => [], 'edges' => [], 'meta' => []];
        $profil = app(\Platform\FoodAlchemist\Services\Pairing\RezeptProfil::class)->fuer((int) $recipe->id);
        $anteil = collect($profil['anker'])->sortByDesc('anteil')->take(8);
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $anteil->pluck('anchor_id')->all())->pluck('display_de', 'id');

        $out = [
            'type' => 'recipe',
            'ist_gericht' => $istGericht,
            'kombination' => app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class)->daten($recipe),
            'netz' => $netz,
            'profil' => $anteil->map(fn ($a) => ['name' => (string) ($namen[$a['anchor_id']] ?? '#'.$a['anchor_id']), 'anteil' => round((float) $a['anteil'])])->values()->all(),
            'passt_dazu' => [], 'deckt_bedarf' => [], 'partner' => [], 'kontrast' => [], 'verwandte' => [],
        ];
        if ($istGericht) {
            $basis = collect($netz['nodes'])->where('kind', 'basisrezept');
            $out['passt_dazu'] = $basis->where('typ', 'stern3')->map(fn ($n) => ['recipe_id' => $n['recipe_id'], 'name' => $n['label'], 'mit' => $n['via']])->values()->all();
            $out['deckt_bedarf'] = $basis->where('typ', 'kontrast')->map(fn ($n) => ['recipe_id' => $n['recipe_id'], 'name' => $n['label'], 'achse' => $n['achse'] ?? $n['via']])->values()->all();

            return $out;
        }
        $kern = $anteil->filter(fn ($a) => $a['anteil'] >= RezeptGraph::KERN)->pluck('anchor_id')->map(fn ($i) => (int) $i)->all();
        $out['partner'] = $this->harmoniePartnerNamen($kern, quellen: $profil['anker_quellen']);
        $out['kontrast'] = array_map(fn ($c) => ['name' => (string) ($c['display_de'] ?: $c['slug']), 'achse' => $c['achse_label']],
            $this->kontrastUndKonflikt($kern)[0]);
        $out['verwandte'] = $team !== null ? $this->recipesSharingPairings($team, (int) $recipe->id)->all() : [];

        return $out;
    }

    /**
     * Pairing-Panel im GP-Editor — Spec 60 · P10: Anker des GP, echtes Food Pairing (★★★), was der
     * Anker braucht (Anker-Wissen), wer das liefert (Kontrast) und womit er sich stört.
     */
    public function panelGp(int $gpId): array
    {
        $anker = $this->gpAnkers($gpId);
        $ids = $anker->pluck('id')->map(fn ($i) => (int) $i)->all();
        $bedarfe = $ids === [] ? collect() : DB::table('foodalchemist_anchor_bedarfe')->whereIn('anchor_id', $ids)
            ->where('status', '!=', \Platform\FoodAlchemist\Enums\WissensStatus::Verworfen->value)
            ->orderByRaw("CASE staerke WHEN 'muss' THEN 0 ELSE 1 END")->get(['achse', 'staerke', 'status', 'deckung']);
        $konflikt = $ids === [] ? [] : DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $this->graph()->beziehungen($ids, Kantenart::Konflikt)->pluck('zu')->unique()->all())
            ->orderBy('display_de')->pluck('display_de')->all();

        return [
            'type' => 'gp',
            'anker' => $anker->map(fn ($a) => ['slug' => $a->slug, 'display_de' => $a->display_de, 'source' => $a->source])->all(),
            'aroma' => $this->harmoniePartnerNamen($ids),
            'braucht' => $bedarfe->map(fn ($b) => [
                'achse' => \Platform\FoodAlchemist\Enums\Achse::tryFrom((string) $b->achse)?->label() ?? (string) $b->achse,
                'staerke' => $b->staerke,
                'deckung' => \Platform\FoodAlchemist\Enums\BedarfDeckung::tryFrom((string) $b->deckung)?->label() ?? 'Partner',
                'grundlage' => \Platform\FoodAlchemist\Enums\Grundlage::aus('dossier', (string) $b->status)->label(),
            ])->unique('achse')->values()->all(),
            'kontrast' => array_map(fn ($c) => ['name' => (string) ($c['display_de'] ?: $c['slug']), 'achse' => $c['achse_label']],
                $this->kontrastUndKonflikt($ids)[0]),
            'konflikt' => array_values(array_map('strval', $konflikt)),
        ];
    }

    /**
     * ★★★-Partner einer Anker-Menge (echtes Food Pairing), nach Zahl der bedienten Anker, dann Name.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function harmoniePartnerNamen(array $ids, int $max = 14, array $quellen = []): array
    {
        if ($ids === []) {
            return [];
        }
        $cover = $this->graph()->kanten($ids, null, AnkerGraph::HARMONIERT, $ids)->groupBy('zu')
            ->map(fn ($kanten) => app(NetzKandidaten::class)->zahlAromaQuellen($kanten->pluck('von')->all(), $quellen));
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $cover->keys()->all())
            ->whereNotIn('slug', self::NICHT_ZUTAT_ANKER)->pluck('display_de', 'id');

        $liste = [];
        foreach ($cover as $id => $n) {
            if (isset($namen[$id])) {
                $liste[] = [(int) $n, (string) $namen[$id]];
            }
        }
        usort($liste, fn ($x, $y) => [$y[0], $x[1]] <=> [$x[0], $y[1]]);

        return array_slice(array_column($liste, 1), 0, $max);
    }

    // ── M5-07: Pairing-Netz-Graph (D-7) ───────────────────────────────────
    // 2026-07-22 Empfehler-Redesign: statt »Anker-Netzwerk des Rezepts« zeigt das
    // Netz jetzt »was passt ZUM Gericht« — Kandidaten nach Typ (erprobt/aroma/
    // kontrast) in Sektoren + komplementäre Basisrezepte. Dish-zentrisch,
    // clientseitig nach Typ filterbar. Positionen serverseitig fix (keine Simulation).

    private const CANVAS_W = 1200.0;

    private const CANVAS_H = 980.0;

    // Dish-zentrisches Empfehler-Layout (2026-07-22 Redesign): Gericht in der
    // Mitte, eigene Kern-Anker auf einem kleinen Innenring, Pairing-Kandidaten
    // in TYP-SEKTOREN aussen (erprobt oben, aroma oben-rechts, kontrast oben-links),
    // komplementäre Basisrezepte unten. Beantwortet »was passt DAZU, nach Typ«.
    // Konzentrische Kreise (Foodpairing-Look): Gericht in der Mitte, Kern-Anker
    // auf einem kleinen Innenring, die ERPROBTEN Kandidaten als voller Kreis
    // darum (wie in der Vorschau), und aroma + kontrast + Basisrezepte gemeinsam
    // als äusserer Kreis drumherum (nach Typ in zusammenhängende Bögen sortiert).
    private const R_ANKER = 150.0;      // Innenring Kern-Anker

    private const R_BEST = 320.0;

    /** Gericht-Netz: Bestandteile tragen lange Rezeptnamen — weiter innen als die Anker (150) überlappten sie. */
    private const R_BESTANDTEIL = 215.0;       // mittlerer Vollkreis: best-Kandidaten (Inspire L3, ★★★)

    private const R_OUTER = 470.0;      // Aussenkreis: harmonie (★★/★) + kontrast (⇄) + Basisrezepte

    private const KANDIDATEN_PRO_TYP = 14;   // Cap je Typ

    private const BASIS_MAX = 10;

    /** Spec 60: Kontrast-Lieferanten je offenem Bedarf im Netz. */
    private const KONTRAST_JE_BEDARF = 4;

    private const INNER_ANKER_MAX = 12;

    /**
     * Pairing-Empfehler fürs Netz (2026-07-22 Redesign): beantwortet »was passt
     * zum Gericht, getrennt nach erprobt / aroma / kontrast«.
     *
     * Zentrum = Gericht, Innenring = eigene Kern-Anker (Geschmacks-Identität),
     * aussen die PAIRING-KANDIDATEN in Typ-Sektoren (erprobt oben, aroma oben-
     * rechts, kontrast oben-links) — jeder Kandidat ist ein Aroma-Partner der
     * Kern-Anker, gerankt nach der Zahl passender Zutaten im Profil +
     * Gewicht. Unten die KOMPLEMENTÄREN Basisrezepte (Rezepte, die auf einem
     * passenden Partner aufbauen). Typ-Filterung passiert clientseitig (Chips) —
     * jeder Kandidat/jede Kante trägt ihren `typ`. Positionen serverseitig fix
     * (reine Formel, keine Simulation).
     *
     * @param  int  $vorschlaegeProAnker  Legacy-Parameter, ignoriert (Kandidaten
     *                                     sind jetzt immer Teil des Netzes).
     * @return array{nodes: list<array>, edges: list<array>, meta: array}
     */
    public function pairingNetz(Team $team, int $recipeId, int $vorschlaegeProAnker = 0): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['recipe_id' => $recipeId]];
        }

        // Spec 60 · P7: im Gericht stehen Basisrezepte im Vordergrund, die Anker im Hintergrund.
        if ((bool) $recipe->is_sales_recipe) {
            return $this->gerichtNetz($team, $recipe);
        }
        // Basisrezept: Innenring = Kern-Anker seines Aromenprofils, mit Anteil (Hintergrund sichtbar).
        $profile = app(RezeptProfil::class);
        $aroma = $profile->fuer((int) $recipe->id);
        $anzeige = $profile->ankerFuerAnzeige($aroma);
        $vokabular = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', array_column($anzeige, 'anchor_id'))->whereNull('deleted_at')
            ->get(['id', 'slug', 'display_de'])->keyBy('id');
        $inner = collect($anzeige)->filter(fn ($a) => $vokabular->has($a['anchor_id']))
            ->map(fn ($a) => $a + (array) $vokabular[$a['anchor_id']]);

        return $this->baueNetz($team, $inner, (string) $recipe->name, $recipeId, quellen: $aroma['anker_quellen']);
    }

    /**
     * Spec 60 · P7: Netz eines Gerichts — Basisrezepte statt Anker (Dominique 06.10.: „im Gericht-Netz
     * keine Anker, sondern andere Basisrezepte, die dazu passen").
     *
     *   Innenring   die Bestandteile des Gerichts (Basisrezepte, einzeln eingesetzte GPs)
     *   Linien      zwischen Bestandteilen: harmoniert / Klassiker (gold), Spannung (türkis), Konflikt (rot)
     *   Mittelring  Basisrezepte aus dem Bestand, die mit einem Bestandteil harmonieren
     *   Außenring   Basisrezepte, die einen offenen Bedarf decken (Kontrast)
     * Dieselbe Kombinationslogik wie „Passt das zusammen?" im Panel.
     */
    private function gerichtNetz(Team $team, FoodAlchemistRecipe $gericht): array
    {
        $cx = self::CANVAS_W / 2;
        $cy = self::CANVAS_H / 2;
        $logik = app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class);
        $analyse = $logik->analysiere($gericht);
        $diaet = $gericht->spec_is_vegan === true ? 'vegan' : ($gericht->spec_is_vegetarian === true ? 'vegetarisch' : null);
        $teamId = $gericht->team_id !== null ? (int) $gericht->team_id : (int) $team->id;

        $nodes = [['id' => 'z', 'kind' => 'zentrum', 'label' => (string) $gericht->name, 'x' => $cx, 'y' => $cy]];
        $edges = [];
        $teile = array_values($analyse['bestandteile']);
        $anteile = $logik->portionsanteile($teile);
        foreach ($teile as $i => $t) {
            $anteil = isset($anteile[$t['schluessel']]) ? round(100 * $anteile[$t['schluessel']], 1) : null;
            [$x, $y] = $this->positionAufKreis($i, max(1, count($teile)), self::R_BESTANDTEIL, $cx, $cy);
            $nodes[] = ['id' => 't:'.$t['schluessel'], 'kind' => 'bestandteil', 'label' => $t['label'],
                'recipe_id' => $t['recipe_id'], 'ohne_profil' => $t['profil'] === null,
                'anteil' => $anteil, 'gramm' => $t['gramm'] !== null ? round($t['gramm'], 1) : null,
                'kern' => $t['profil'] !== null ? array_slice(array_map(fn ($a) => (int) $a['anchor_id'], $t['profil']['anker']), 0, 3) : [],
                'x' => $x, 'y' => $y];
            $edges[] = ['source' => 'z', 'target' => 't:'.$t['schluessel'], 'kind' => 'zentrum_anker', 'anteil' => $anteil, 'visible' => true];
        }
        $typ = [AussageTyp::Harmoniert->value => 'stern3', AussageTyp::Kombination->value => 'stern3', AussageTyp::Spannung->value => 'kontrast'];
        $gesehen = [];
        foreach ($analyse['aussagen'] as $a) {
            if (count($a->bestandteile) !== 2) {
                continue;
            }
            [$p, $q] = $a->bestandteile;
            if ($a->typ === AussageTyp::Konflikt) {
                $edges[] = ['source' => 't:'.$p, 'target' => 't:'.$q, 'kind' => 'konflikt', 'text' => $a->text, 'visible' => true];
            } elseif ($a->typ === AussageTyp::Hinweis && str_starts_with($a->text, Kombinationslogik::HINWEIS_BEGLEITAROMA)) {
                $edges[] = ['source' => 't:'.$p, 'target' => 't:'.$q, 'kind' => 'hinweis', 'text' => $a->text, 'visible' => true];
            } elseif (isset($typ[$a->typ->value]) && ! isset($gesehen[$a->typ->value.$p.$q])) {
                $gesehen[$a->typ->value.$p.$q] = true;
                $edges[] = ['source' => 't:'.$p, 'target' => 't:'.$q, 'kind' => 'teil_teil', 'typ' => $typ[$a->typ->value],
                    'text' => $a->text, 'visible' => true];
            }
        }

        $passend = $logik->passendeBasisrezepte($analyse, $diaet, $gericht->taste_direction, $teamId, self::KANDIDATEN_PRO_TYP);
        foreach ($passend as $i => $b) {
            [$x, $y] = $this->positionAufKreis($i, max(1, count($passend)), self::R_BEST, $cx, $cy);
            $nodes[] = ['id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => 'stern3', 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['mit'],
                'mit_anteil' => isset($anteile[$b['mit_schluessel']]) ? round(100 * $anteile[$b['mit_schluessel']], 1) : null,
                'x' => $x, 'y' => $y];
            $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 't:'.$b['mit_schluessel'], 'kind' => 'basis', 'typ' => 'stern3',
                'text' => $b['name'].' harmoniert mit '.$b['mit'], 'visible' => true];
        }
        $schon = array_flip(array_column($passend, 'recipe_id'));
        $kontrast = [];
        foreach ($logik->vorschlaegeFuer($analyse, $diaet, $gericht->taste_direction, $teamId, 3) as $v) {
            $achse = \Platform\FoodAlchemist\Enums\Achse::from($v['achse'])->label();
            foreach ($v['basisrezepte'] as $b) {
                if (! isset($schon[$b['recipe_id']]) && ! isset($kontrast[$b['recipe_id']])) {
                    $kontrast[$b['recipe_id']] = $b + ['achse' => $achse];
                }
            }
        }
        $kontrast = array_values($kontrast);
        foreach ($kontrast as $i => $b) {
            [$x, $y] = $this->positionAufKreis($i, max(1, count($kontrast)), self::R_OUTER, $cx, $cy);
            $nodes[] = ['id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => 'kontrast', 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['achse'], 'achse' => $b['achse'], 'x' => $x, 'y' => $y];
            if ($b['mit_schluessel'] !== null) {
                $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 't:'.$b['mit_schluessel'], 'kind' => 'basis', 'typ' => 'kontrast',
                    'text' => $b['name'].' deckt '.$b['achse'], 'visible' => true];
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'recipe_id' => (int) $gericht->id,
                'art' => 'gericht',
                'canvas_w' => self::CANVAS_W,
                'canvas_h' => self::CANVAS_H,
                'sig' => substr(md5(json_encode([$nodes, $edges])), 0, 10),
                'typ_default' => ['stern3' => true, 'kontrast' => true],
                'counts' => [
                    'bestandteile' => count($teile),
                    'stern3' => count($passend),
                    'kontrast' => count($kontrast),
                    'basis' => count($passend) + count($kontrast),
                    'konflikt' => count(array_filter($edges, fn ($e) => $e['kind'] === 'konflikt')),
                ],
            ],
        ];
    }

    /**
     * Ad-hoc-Netz aus einer freien Anker-Menge (Planungs-Composer): kein Rezept,
     * Zentrum = frei benennbare „Komposition". Gleiche Downstream-Logik wie
     * {@see pairingNetz} — nur der Innenring kommt direkt als Anker-ID-Liste
     * (Auswahl-Reihenfolge bleibt erhalten).
     *
     * @param  array<int>  $ankerIds
     */
    public function pairingNetzForAnkers(Team $team, array $ankerIds, string $centerLabel = 'Komposition'): array
    {
        $ids = array_values(array_unique(array_map('intval', $ankerIds)));
        if ($ids === []) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['recipe_id' => 0]];
        }
        $inner = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)
            ->get(['id', 'slug', 'display_de'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de])
            ->sortBy(fn ($a) => array_search($a['id'], $ids, true))->values();

        return $this->baueNetz($team, $inner, $centerLabel, 0, true);
    }

    /**
     * Gemeinsamer Netz-Aufbau (rezept- ODER anker-getrieben). $inner = roher Innenring
     * (wird auf INNER_ANKER_MAX gekappt). $recipeId nur für meta + Selbst-Ausschluss der
     * komplementären Basisrezepte (0 = keiner). $centerLabel = Label des Zentrum-Knotens.
     *
     * @param  \Illuminate\Support\Collection<int,array{id:int,slug:string,display_de:?string}>  $inner
     */
    private function baueNetz(Team $team, \Illuminate\Support\Collection $inner, string $centerLabel, int $recipeId, bool $composer = false, array $quellen = []): array
    {
        $cx = self::CANVAS_W / 2;
        $cy = self::CANVAS_H / 2;

        $inner = $inner->unique('id')->take(self::INNER_ANKER_MAX)->values();
        $innerIds = $inner->filter(fn ($a) => $a['kern'] ?? true)->pluck('id')->all();

        $edges = [];
        $ankerNodes = [];
        $nInner = max(1, $inner->count());
        foreach ($inner as $i => $a) {
            $w = 2 * M_PI * $i / $nInner - M_PI / 2;
            $x = round($cx + self::R_ANKER * cos($w), 1);
            $y = round($cy + self::R_ANKER * sin($w), 1);
            $ankerNodes[] = [
                'id' => 'a:'.$a['id'], 'kind' => 'anker', 'label' => $a['display_de'], 'slug' => $a['slug'],
                'kern' => $a['kern'] ?? true, 'anteil' => $a['anteil'] ?? null, 'staerke' => $a['staerke'] ?? null,
                'x' => $x, 'y' => $y,
            ];
            $edges[] = ['source' => 'z', 'target' => 'a:'.$a['id'], 'kind' => 'zentrum_anker', 'visible' => true];
        }

        // ── Innere Ebene: Kanten ZWISCHEN den Kern-Ankern ───────────────────
        // Zeigt, wie die ausgewählten Anker laut Foodpairing-Matrix zusammenhängen
        // (die eigentliche Beweisführung): Best-/Good-Match zwischen den violetten
        // Ankern selbst, nicht nur Anker→Zutat. Kein Match → keine Linie.
        $ankerKanten = $this->innerAnkerKanten($innerIds);
        $edges = array_merge($edges, $ankerKanten);

        // ── Kandidaten: Aroma-Partner der Kern-Anker (ausserhalb), typisiert ──
        [$kandidaten, $candMeta] = app(NetzKandidaten::class)->fuerAnker($innerIds, $quellen);
        $nebenIds = $inner->filter(fn ($a) => isset($a['kern']) && ! $a['kern'])->pluck('id')->all();
        $schwacheKonflikte = app(RezeptGraph::class)->schwacheKonflikte(
            ['nebenanker' => array_fill_keys($nebenIds, true)],
            ['anker' => array_map(fn ($id) => ['anchor_id' => $id, 'anteil' => 0.0], $innerIds)],
        );
        $ankerNamen = $inner->pluck('display_de', 'id');
        foreach ($schwacheKonflikte as $k) {
            $edges[] = ['source' => 'a:'.$k->von, 'target' => 'a:'.$k->zu, 'kind' => 'hinweis',
                'text' => "{$ankerNamen[$k->von]} und {$ankerNamen[$k->zu]} können sich stören (Begleitaroma, Dossier geprüft)",
                'visible' => true];
        }

        // ── Spec 60: Kombinationslogik über die Anker-Auswahl — dieselbe Aussage wie im Panel.
        // Daraus: Kontrast-Ring (Lieferanten für offene Bedarfe), Konflikt-Linien und — nur im
        // Composer — der Hinweis „kein gemessener Bezug" an Ankern ohne jede Verbindung (neutral).
        [$kontrast, $konfliktKanten, $verbunden] = $this->kontrastUndKonflikt($innerIds, ohneSalz: $composer);
        // Kein Partner mit dem Grundnamen einer gewählten Zutat („Augenbohne" neben „Augenbohne, gekocht"):
        // Vergleich über die Spalte grundname (AnkerVarianten), nie über Teilstrings (Bohne ≠ Bohnenkraut).
        $innerGrund = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $innerIds)->get(['grundname', 'slug'])
            ->map(fn ($r) => mb_strtolower(trim((string) ($r->grundname ?: $r->slug))))->flip()->all();
        $kandidaten = array_values(array_filter($kandidaten, fn ($c) => ! isset($innerGrund[$c['grundname']])));
        $kontrastGrund = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_column($kontrast, 'id'))->get(['id', 'grundname', 'slug'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => mb_strtolower(trim((string) ($r->grundname ?: $r->slug)))])->all();
        $kontrast = array_values(array_filter($kontrast, fn ($c) => ! isset($innerGrund[$kontrastGrund[(int) $c['id']] ?? ''])));
        // Ein ★★★-Partner, der zugleich einen offenen Bedarf deckt, bleibt im ★★★-Ring und trägt die
        // Kontrast-Achse als Markierung (Dominique 15.10.: vorher wanderten alle 6 ★★★-Partner des
        // Kürbis in den Kontrast-Ring, der ★★★-Ring war leer). Der Kontrast-Ring zeigt den Rest.
        $kontrastVon = [];
        foreach ($kontrast as $c) {
            $kontrastVon[(int) $c['id']] = $c;
        }

        $edges = array_merge($edges, $konfliktKanten);
        if ($composer && count($innerIds) >= 2) {
            foreach ($ankerNodes as &$an) {
                $an['orphan'] = ! isset($verbunden[(int) substr($an['id'], 2)]);
            }
            unset($an);
        }

        // Rang (vorher bei Gleichstand alphabetisch: „Afrikanische Tagetes, Alpenpfeffer …" vorne):
        // verbindet mehr gewählte Anker · deckt einen offenen Bedarf · Klassiker aus dem Dossier · Gruppen-Klassiker ·
        // Grundform vor Variante · Verbreitung im Katalog · Name. Vielfalt: je Grundname einer.
        $jeTyp = fn ($typ) => array_slice(
            (function () use ($kandidaten, $typ, $kontrastVon) {
                $l = array_values(array_filter($kandidaten, fn ($c) => $c['typ'] === $typ));
                $schluessel = fn ($c) => [$c['cover'], isset($kontrastVon[(int) $c['id']]) ? 1 : 0,
                    $c['klassiker'] ? 2 : ($c['gruppen_klassiker'] ? 1 : 0), $c['grundform'] ? 1 : 0, $c['verbreitung'], $c['weight']];
                usort($l, fn ($x, $y) => [...$schluessel($y), $x['slug']] <=> [...$schluessel($x), $y['slug']]);
                $gesehen = [];

                return array_values(array_filter($l, function ($c) use (&$gesehen) {
                    if (isset($gesehen[$c['grundname']])) {
                        return false;
                    }

                    return $gesehen[$c['grundname']] = true;
                }));
            })(),
            0, self::KANDIDATEN_PRO_TYP
        );
        // Spec 60: Harmonie-Kandidaten nur ★★★; der äußere Ring trägt den Kontrast (offene Bedarfe).
        $stern3 = $jeTyp('stern3');
        // Wer im ★★★-Ring steht, erscheint nicht ein zweites Mal im Kontrast-Ring.
        $stern3Ids = array_flip(array_map(fn ($c) => (int) $c['id'], $stern3));
        $kontrast = array_values(array_filter($kontrast, fn ($c) => ! isset($stern3Ids[(int) $c['id']])));

        $basis = $this->komplementaerBasisrezepte($team, $recipeId, $candMeta);

        $kandidatNodes = [];
        $basisNodes = [];

        // Mittlerer Vollkreis: ★★★-Kandidaten (Inspire L3, Best-Match) gleichmässig rundum.
        $mB = max(1, count($stern3));
        foreach ($stern3 as $idx => $c) {
            [$x, $y] = $this->positionAufKreis($idx, $mB, self::R_BEST, $cx, $cy);
            $k = $kontrastVon[(int) $c['id']] ?? null;
            $kandidatNodes[] = [
                'id' => 'k:'.$c['id'], 'kind' => 'kandidat', 'typ' => 'stern3', 'level' => 3,
                'label' => $c['display_de'], 'slug' => $c['slug'], 'cover' => $c['cover'],
                'cover_einheit' => $composer ? 'Ankern' : 'Zutaten', 'anker_treffer' => count($c['partner']), 'x' => $x, 'y' => $y,
                'auch_kontrast' => $k['achse_label'] ?? null,
            ];
            foreach ($c['partner'] as $p) {
                $edges[] = ['source' => 'k:'.$c['id'], 'target' => 'a:'.$p['anker_id'], 'kind' => 'kandidat',
                    'typ' => $p['typ'], 'level' => $p['level'] ?? 1, 'weight' => $p['weight'],
                    'visible' => true];
            }
        }

        // Äusserer Vollkreis: Kontrast-Lieferanten (decken einen offenen Bedarf) + Basisrezepte.
        $outerTotal = max(1, count($kontrast) + count($basis));
        $oi = 0;
        foreach ($kontrast as $c) {
            [$x, $y] = $this->positionAufKreis($oi++, $outerTotal, self::R_OUTER, $cx, $cy);
            $kandidatNodes[] = [
                'id' => 'k:'.$c['id'], 'kind' => 'kandidat', 'typ' => 'kontrast', 'level' => 0,
                'label' => $c['display_de'], 'slug' => $c['slug'], 'cover' => count($c['fuer']),
                'achse' => $c['achse_label'], 'x' => $x, 'y' => $y,
            ];
            foreach ($c['fuer'] as $aid) {
                $edges[] = ['source' => 'k:'.$c['id'], 'target' => 'a:'.$aid, 'kind' => 'kandidat',
                    'typ' => 'kontrast', 'level' => 0, 'weight' => 1.0, 'achse' => $c['achse_label'], 'visible' => true];
            }
        }
        foreach ($basis as $b) {
            [$x, $y] = $this->positionAufKreis($oi++, $outerTotal, self::R_OUTER, $cx, $cy);
            $basisNodes[] = [
                'id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => $b['typ'], 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['via_slug'], 'x' => $x, 'y' => $y,
            ];
            $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 'a:'.$b['anker_id'], 'kind' => 'basis',
                'typ' => $b['typ'], 'visible' => true];
        }

        $nodes = array_merge(
            [['id' => 'z', 'kind' => 'zentrum', 'label' => $centerLabel, 'x' => $cx, 'y' => $cy]],
            $ankerNodes, $kandidatNodes, $basisNodes
        );

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'recipe_id' => $recipeId,
                'canvas_w' => self::CANVAS_W,
                'canvas_h' => self::CANVAS_H,
                // Inhalts-Signatur (Knoten-IDs in Reihenfolge): keyt die wire:ignore-D3-Insel
                // in Vorschau + Modal. Ändert sich der Ankersatz (o. die Kandidaten), ändert
                // sich sig → wire:key wechselt → Livewire ersetzt die Insel → D3 re-initialisiert
                // mit frischen Daten. Ohne das friert das Modal auf dem Erst-Öffnungsstand ein.
                'sig' => substr(md5(json_encode([$nodes, $edges])), 0, 10),
                // Filter-Defaults: beide Stern-Stufen an (zweistufige Inspire-Harmonie).
                'typ_default' => ['stern3' => true, 'kontrast' => true],
                'counts' => [
                    'stern3' => count(array_filter($kandidatNodes, fn ($n) => $n['typ'] === 'stern3')),
                    'kontrast' => count(array_filter($kandidatNodes, fn ($n) => $n['typ'] === 'kontrast')),
                    'basis' => count($basisNodes),
                    // Kanten zwischen den Kern-Ankern (innere Ebene).
                    'anker_anker' => count($ankerKanten),
                    'konflikt' => count($konfliktKanten),
                ],
            ],
        ];
    }

    /**
     * Composer: Kohäsion einer freien Anker-Menge — „passen die gewählten Anker zusammen?".
     * Baut die komponenten-Struktur aus den Ankern und delegiert an {@see cohesionFor}
     * (liefert score/min_score/weakest_pair + je Anker fit/is_orphan = der „passt-nicht"-Flag).
     *
     * @param  array<int>  $ankerIds
     */
    public function composerCohesion(array $ankerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $ankerIds)));
        $leer = ['score' => 0, 'min_score' => 0, 'rated_pairs' => 0, 'total_pairs' => 0,
            'coverage_pct' => 0, 'weakest_pair' => null, 'unrated_pairs' => [], 'komponenten' => []];
        if (count($ids) < 2) {
            return $leer;
        }
        $rows = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)->get(['id', 'slug', 'display_de'])->keyBy('id');

        $komponenten = [];
        foreach ($ids as $id) {
            $a = $rows->get($id);
            if ($a === null) {
                continue;
            }
            $komponenten[] = [
                'kern' => (int) $a->id,
                'prozess' => [],
                'label' => $a->display_de ?: $a->slug,
                'via' => $a->slug,
            ];
        }

        return $this->cohesionFor($komponenten);
    }

    /**
     * Composer-Picker: browsebare Anker-Liste (wie der GP-Picker `IngredientEditor::browseKatalog`).
     * Gefiltert nach Kategorie + Suche, aktuelle Auswahl ausgeschlossen; je Anker ein Best/Good-Badge
     * relativ zur Auswahl (aus {@see NetzKandidaten::fuerAnker}) + das Kategorie-Vokabular für den Dropdown.
     *
     * @param  array<int>  $selectedIds
     * @return array{items: list<array>, total: int, kategorien: list<string>}
     */
    public function composerAnkerBrowse(Team $team, string $q, ?string $category, array $selectedIds, int $limit = 200, ?int $focusId = null): array
    {
        $selected = array_values(array_unique(array_map('intval', $selectedIds)));
        $q = trim($q);

        $query = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.$q.'%';
                $w->where(fn ($x) => $x->where('slug', 'like', $like)->orWhere('display_de', 'like', $like));
            })
            ->when($category !== null && $category !== '', fn ($w) => $w->where('category', $category))
            ->when($selected !== [], fn ($w) => $w->whereNotIn('id', $selected));

        $total = (clone $query)->count();
        $rows = $query->orderBy('display_de')->limit($limit)->get(['id', 'slug', 'display_de', 'category']);

        // Best/Good-Badge: bei Fokus relativ zum EINEN fokussierten Anker („was passt zu X"),
        // sonst relativ zur ganzen Auswahl.
        $badgeBasis = $focusId !== null ? [$focusId] : $selected;
        $badge = [];
        $auchKontrast = [];
        if ($badgeBasis !== []) {
            // Spec 60: ★★★ = harmoniert · kontrast = deckt einen offenen Bedarf · konflikt = stört sich.
            // Wie im Netz (#393): ★★★, der zugleich einen Bedarf deckt, bleibt ★★★ und trägt die Achse
            // (auch_kontrast); „kontrast" nur für Lieferanten ohne ★★★ (Klassiker). Konflikt vor allem.
            [$kand] = app(NetzKandidaten::class)->fuerAnker($badgeBasis);
            foreach ($kand as $c) {
                $badge[(int) $c['id']] = $c['typ'];
            }
            foreach ($this->kontrastUndKonflikt($badgeBasis, ohneSalz: true)[0] as $c) {
                if (($badge[(int) $c['id']] ?? null) === 'stern3') {
                    $auchKontrast[(int) $c['id']] = $c['achse_label'];
                } else {
                    $badge[(int) $c['id']] = 'kontrast';
                }
            }
            foreach ($this->graph()->beziehungen($badgeBasis, Kantenart::Konflikt) as $k) {
                $badge[$k->zu] = 'konflikt';
            }
        }

        $kategorien = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category')->all();

        return [
            'items' => $rows->map(fn ($a) => [
                'id' => (int) $a->id,
                'slug' => $a->slug,
                'label' => $a->display_de ?: $a->slug,
                'category' => $a->category,
                'typ' => $badge[(int) $a->id] ?? null,
                'auch_kontrast' => ($badge[(int) $a->id] ?? null) === 'stern3' ? ($auchKontrast[(int) $a->id] ?? null) : null,
            ])->all(),
            'total' => $total,
            'kategorien' => $kategorien,
        ];
    }

    /**
     * Kanten ZWISCHEN den Kern-Ankern (innere Ebene des Netzes). Beantwortet
     * „wie hängen die ausgewählten Anker untereinander zusammen" — die eigentliche
     * Beweisführung des Foodpairing-Modells, die im Zutat→Anker-Netz fehlt.
     *
     * Signal = die gemessene Foodpairing-Harmonie ({@see AnkerGraph}): je ungeordnetem Paar
     * mit Stufe ★★★ eine Linie. Darunter (2★ oder keine Kante) entsteht keine Linie.
     *
     * @param  array<int>  $innerIds
     * @return list<array{source:string,target:string,kind:string,typ:string,level:int,weight:float,visible:bool}>
     */
    private function innerAnkerKanten(array $innerIds): array
    {
        if (count($innerIds) < 2) {
            return [];
        }

        $out = [];
        foreach ($this->graph()->kanten($innerIds, $innerIds, AnkerGraph::HARMONIERT) as $k) {   // Spec 60: nur ★★★
            if ($k->von > $k->zu) {
                continue;                                   // jedes Paar steht in beiden Richtungen — einmal zeigen
            }
            $out[] = [
                'source' => 'a:'.$k->von,
                'target' => 'a:'.$k->zu,
                'kind' => 'anker_anker',
                'typ' => 'stern'.$k->stufe,
                'level' => $k->stufe,
                'weight' => AnkerGraph::GEWICHT[$k->stufe],
                'visible' => true,
            ];
        }

        return $out;
    }

    /**
     * Spec 60: Kontrast-Lieferanten und Konflikte einer Anker-Auswahl aus der Kombinationslogik
     * (Anker als Einzel-Bestandteile — dieselbe Analyse wie „Passt das zusammen?").
     *
     *   kontrast   je offenem Bedarf die stärksten Lieferanten (abgeleitete Kontrast-Kante)
     *   konflikt   Linie zwischen zwei gewählten Ankern, die sich laut Anker-Wissen stören
     *   verbunden  Anker, die mit einem anderen harmonieren, Spannung bilden oder ein Klassiker sind
     *
     * @param  array<int>  $innerIds
     * @return array{0: list<array{id: int, slug: string, display_de: ?string, achse_label: string, fuer: list<int>}>, 1: list<array>, 2: array<int, true>}
     */
    private function kontrastUndKonflikt(array $innerIds, bool $ohneSalz = false): array
    {
        if ($innerIds === []) {
            return [[], [], []];
        }
        $logik = app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class);
        $analyse = $logik->analysiereBestandteile($logik->bestandteileAusAnkern($innerIds));
        if ($ohneSalz) {
            // Composer (Dominique 16.10.): Salz ist keine Pairing-Frage → kein Salz-Lieferant im Ring
            $analyse['offene_bedarfe'] = array_values(array_filter($analyse['offene_bedarfe'],
                fn ($b) => $b['achse'] !== \Platform\FoodAlchemist\Enums\Achse::Salz->value));
        }
        $id = fn (string $schluessel) => (int) substr($schluessel, 1);           // 'a123' → 123

        // Ein Lieferant muss zur Auswahl passen: ★★★ zu mindestens einem gewählten Anker ODER Klassiker
        // aus dem Dossier („Verträgt"), auch als Gruppe („verträgt Fisch" + Lieferant ist Fisch, schwächer). Das gilt auch für reine Träger (Stufe 3: Öl, Meersalz, Zucker).
        // ★★ zählt nicht (Dominique 15.10.: „2 Sterne ist die Grund-Aroma-Passform", Rauschen).
        // Vorher durfte ein Träger ohne jede Harmonie stehen → Wildfond für Erdbeere + Basilikum,
        // Tikka Masala für Kürbis. (2026-10-06: Olivenöl für Tomate fehlte mit reinem ★★★-Filter —
        // heute trägt das der Klassiker aus dem Dossier.)
        // Sortiert: Harmonien mit der Auswahl, Klassiker, belegte Träger (Nährwert gemessen / Dossier
        // geprüft) vor ungeprüften, dann Rang der Kontrast-Kante.
        $jeBedarf = [];
        foreach ($analyse['offene_bedarfe'] as $b) {
            $fuer = $id((string) $b['bestandteil']);
            $jeBedarf[] = [$fuer, (string) $b['achse'], $this->graph()->beziehungen([$fuer], Kantenart::Kontrast)
                ->where('achse', (string) $b['achse'])->reject(fn ($k) => in_array($k->zu, $innerIds, true))->values()];
        }
        $alle = collect($jeBedarf)->flatMap(fn ($x) => $x[2]->pluck('zu'))->unique()->values()->all();
        $harmonien = [];
        foreach ($alle === [] ? [] : $this->graph()->kanten($alle, $innerIds, AnkerGraph::HARMONIERT) as $k) {
            $harmonien[$k->von] = ($harmonien[$k->von] ?? 0) + 1;
        }
        // Klassiker aus dem Dossier („Verträgt") lässt einen Lieferanten auch ohne ★★★ zu
        $klassiker = $alle === [] ? [] : $this->graph()->beziehungen($alle, Kantenart::Kombination, $innerIds)
            ->merge($this->graph()->beziehungen($innerIds, Kantenart::Kombination, $alle))
            ->flatMap(fn ($k) => [$k->von, $k->zu])->flip()->all();
        $gruppenKlassiker = $this->graph()->gruppenKlassikerZwischen($alle, $innerIds);   // „verträgt Fisch", schwächer
        $stoert = $alle === [] ? [] : $this->graph()->beziehungen($alle, Kantenart::Konflikt, $innerIds)
            ->merge($this->graph()->beziehungen($innerIds, Kantenart::Konflikt, $alle))
            ->flatMap(fn ($k) => [$k->von, $k->zu])->flip()->all();
        $traeger = [];
        foreach ($alle === [] ? [] : DB::table('foodalchemist_anchor_eigenschaften')->whereIn('anchor_id', $alle)
            ->where('stufe', '>=', 3)->where('status', '!=', \Platform\FoodAlchemist\Enums\WissensStatus::Verworfen->value)
            ->get(['anchor_id', 'achse', 'quelle', 'status']) as $e) {
            $belegt = $e->quelle === 'naehrwert' || $e->status === \Platform\FoodAlchemist\Enums\WissensStatus::Geprueft->value;
            $schluessel = (int) $e->anchor_id.'|'.$e->achse;
            $traeger[$schluessel] = ($traeger[$schluessel] ?? 0) === 2 || $belegt ? 2 : 1;   // 2 = belegt, 1 = ungeprüft
        }
        $kontrast = [];
        foreach ($jeBedarf as [$fuer, $achse, $lieferanten]) {
            $passend = $lieferanten
                ->filter(fn ($k) => ! isset($stoert[$k->zu])
                    && (isset($harmonien[$k->zu]) || isset($klassiker[$k->zu]) || isset($gruppenKlassiker[$k->zu])))
                ->sortBy([
                    fn ($x, $y) => ($harmonien[$y->zu] ?? 0) <=> ($harmonien[$x->zu] ?? 0),
                    // Klassiker-Stufe: Einzel 2, nur Gruppe 1 (eine Gruppe hebt nie über einen Einzel-Klassiker)
                    fn ($x, $y) => (isset($klassiker[$y->zu]) ? 2 : (isset($gruppenKlassiker[$y->zu]) ? 1 : 0))
                        <=> (isset($klassiker[$x->zu]) ? 2 : (isset($gruppenKlassiker[$x->zu]) ? 1 : 0)),
                    fn ($x, $y) => ($traeger[$y->zu.'|'.$achse] ?? 0) <=> ($traeger[$x->zu.'|'.$achse] ?? 0),
                    fn ($x, $y) => $y->rang <=> $x->rang,
                ])
                ->take(self::KONTRAST_JE_BEDARF);
            foreach ($passend as $k) {
                $kontrast[$k->zu] ??= ['id' => $k->zu, 'achse_label' => \Platform\FoodAlchemist\Enums\Achse::from($achse)->label(), 'fuer' => []];
                $kontrast[$k->zu]['fuer'][] = $fuer;
            }
        }
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_keys($kontrast))
            ->get(['id', 'slug', 'display_de'])->keyBy('id');
        $kontrast = array_values(array_filter(array_map(fn ($c) => isset($namen[$c['id']])
            ? $c + ['slug' => $namen[$c['id']]->slug, 'display_de' => $namen[$c['id']]->display_de] : null, $kontrast)));

        $konflikt = [];
        $verbunden = [];
        foreach ($analyse['aussagen'] as $a) {
            if (count($a->bestandteile) !== 2) {
                continue;
            }
            [$x, $y] = array_map(fn ($k) => $id((string) $k), $a->bestandteile);
            if ($a->typ === AussageTyp::Konflikt) {
                $konflikt[min($x, $y).':'.max($x, $y)] = ['source' => 'a:'.min($x, $y), 'target' => 'a:'.max($x, $y),
                    'kind' => 'konflikt', 'text' => $a->text, 'visible' => true];
            } elseif (in_array($a->typ, [AussageTyp::Harmoniert, AussageTyp::Spannung, AussageTyp::Kombination], true)) {
                $verbunden[$x] = true;
                $verbunden[$y] = true;
            }
        }

        return [$kontrast, array_values($konflikt), $verbunden];
    }

    /**
     * Komplementäre Basisrezepte: Basisrezepte (is_sales_recipe=0), deren Kern-
     * Anker ein Pairing-Partner der Gericht-Anker ist — also Rezepte, die auf
     * einer passenden Zutat AUFBAUEN (ergänzend, nicht bloss »ähnlich«). Typ +
     * Andock-Anker aus der stärksten Partner-Beziehung.
     *
     * @param  array<int,array>  $candMeta  candId → [typ, anker_id, slug]
     * @return list<array>
     */
    private function komplementaerBasisrezepte(Team $team, int $recipeId, array $candMeta): array
    {
        if ($candMeta === []) {
            return [];
        }
        $candIds = array_keys($candMeta);

        // Spec 60 · P7c: Basisrezepte, deren Aromenprofil einen Kandidaten als Kern trägt (≥ 10 %).
        $rows = DB::table('foodalchemist_recipe_profile_anker AS m')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'm.recipe_id')
            ->whereIn('m.anchor_id', $candIds)
            ->where('m.recipe_id', '!=', $recipeId)
            ->where('m.anteil', '>=', \Platform\FoodAlchemist\Services\Pairing\RezeptGraph::KERN)
            ->where('r.is_sales_recipe', 0)
            ->whereNull('r.deleted_at')
            ->get(['m.recipe_id', 'm.anchor_id', 'r.name']);

        // Nur team-sichtbare Rezepte (Tenancy).
        $sichtbar = FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', $rows->pluck('recipe_id')->unique()->all())->pluck('id')->flip();

        $perRecipe = [];
        foreach ($rows as $r) {
            $rid = (int) $r->recipe_id;
            if (! $sichtbar->has($rid)) {
                continue;
            }
            $meta = $candMeta[(int) $r->anchor_id] ?? null;
            if ($meta === null) {
                continue;
            }
            if (! isset($perRecipe[$rid])) {
                $perRecipe[$rid] = ['recipe_id' => $rid, 'name' => $r->name, 'treffer' => 0,
                    'typ' => $meta['typ'], 'anker_id' => $meta['anker_id'], 'via_slug' => $meta['slug']];
            }
            $perRecipe[$rid]['treffer']++;
        }

        $out = array_values($perRecipe);
        usort($out, fn ($x, $y) => [$y['treffer'], $x['name']] <=> [$x['treffer'], $y['name']]);

        return array_slice($out, 0, self::BASIS_MAX);
    }

    /** Gleichmässige Position auf einem Vollkreis: Slot idx von n, Start oben (270°/-90°). */
    private function positionAufKreis(int $idx, int $n, float $radius, float $cx, float $cy): array
    {
        $rad = 2 * M_PI * $idx / max(1, $n) - M_PI / 2;

        return [round($cx + $radius * cos($rad), 1), round($cy + $radius * sin($rad), 1)];
    }

    // ── R6.8: Aroma-treue Substitution (read-only, 2026-07-19) ───────────
    // Ersatz, der den GESCHMACK erhält — nicht nur den Preis senkt. Zwei vorhandene
    // Basen kombiniert (kein Neubau der Mathematik): (1) Anker-Kanten-Überlappung —
    // welche der Aroma-Brücken des Quell-GP trägt/erreicht der Kandidat (edgeBest über
    // die gpAnkers beider Seiten). Der frühere Aroma-Vektor-Cosinus (Moleküle) ist mit
    // Spec 60 · P3 entfallen. Manuell kuratierte Äquivalente (ComponentEquivalentService) werden geboostet (Inv. 3: manual
    // gewinnt). Der eigentliche Tausch bleibt tauscheZutat (Allergen-/swap_locked-Guards dort).

    /**
     * EK (indikativ) der Lead-LA in der Preis-Sicht des anfragenden Teams (PriceService::activeFor), null wenn keiner; ohne Team sähe ein Mandant seit dem Master-Listenpreis (27.10.) BHG-Konditionen.
     */
    private function gpLeadListenEk(?int $leadLaId, Team $team): ?float
    {
        if ($leadLaId === null) {
            return null;
        }
        $p = app(\Platform\FoodAlchemist\Services\Preis\PriceService::class)->activeFor($leadLaId, $team)?->price;

        return $p !== null ? (float) $p : null;
    }

    /**
     * R6.8 — Aroma-treue Ersatz-GPs für einen Quell-GP, gerankt nach erhaltenem Geschmack.
     * Optionaler Rezept-Kontext (recipe_ingredient_id) liefert zusätzlich das Kohäsions-Delta
     * fürs Gesamtgericht + swap_locked-Status. Read-only.
     *
     * @return array{source: ?array, context: array, candidates: list<array>}
     */
    public function aromaTrueSubstitutes(Team $team, int $sourceGpId, int $limit = 8, ?int $recipeIngredientId = null): array
    {
        $context = ['recipe_ingredient_id' => null, 'recipe_id' => null, 'swap_locked' => false, 'base_cohesion' => null];
        $recipe = null;

        // Rezept-Kontext: Zutat auf Sichtbarkeit prüfen, gp_id daraus ableiten (überschreibt Param).
        if ($recipeIngredientId !== null) {
            $zutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::find($recipeIngredientId);
            if ($zutat !== null
                && FoodAlchemistRecipe::visibleToTeam($team)->whereKey($zutat->recipe_id)->exists()) {
                if ($zutat->gp_id !== null) {
                    $sourceGpId = (int) $zutat->gp_id;
                }
                $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($zutat->recipe_id);
                $context['recipe_ingredient_id'] = (int) $zutat->id;
                $context['recipe_id'] = (int) $zutat->recipe_id;
                $context['swap_locked'] = (bool) $zutat->swap_locked;
            }
        }

        $sourceGp = FoodAlchemistGp::visibleToTeam($team)->find($sourceGpId);
        if ($sourceGp === null) {
            return ['source' => null, 'context' => $context, 'candidates' => []];
        }

        $sourceAnker = $this->gpAnkers($sourceGpId);
        $sourceAnkerIds = $sourceAnker->pluck('id')->map(fn ($i) => (int) $i)->all();
        $sourceSlugs = [];
        foreach ($sourceAnker as $a) {
            $sourceSlugs[(int) $a->id] = $a->display_de ?: $a->slug;
        }

        // ── Kandidaten-Pool: Aroma-Geschwister (teilen ≥1 Quell-Anker) ∪ gleiche Warengruppe
        //    ∪ manuelle Äquivalente. Konservativ begrenzt; Rohware/Derivat/Platzhalter raus.
        $poolIds = [];
        if ($sourceAnkerIds !== []) {
            foreach (DB::table('foodalchemist_gp_anchor_mappings')
                ->whereIn('anchor_id', $sourceAnkerIds)->where('role', 'kern')->whereNull('deleted_at')
                ->where('gp_id', '!=', $sourceGpId)->distinct()->pluck('gp_id') as $gid) {
                $poolIds[(int) $gid] = true;
            }
        }
        if ($sourceGp->commodity_group_code !== null) {
            foreach (FoodAlchemistGp::visibleToTeam($team)
                ->where('commodity_group_code', $sourceGp->commodity_group_code)
                ->where('id', '!=', $sourceGpId)->limit(300)->pluck('id') as $gid) {
                $poolIds[(int) $gid] = true;
            }
        }
        $manuelleIds = [];
        foreach (app(\Platform\FoodAlchemist\Services\GpStamm\ComponentEquivalentService::class)->fuer($team, 'gp', $sourceGpId) as $eq) {
            if ($eq->gegen_kind === 'gp' && $eq->gegen_id !== null) {
                $poolIds[(int) $eq->gegen_id] = true;
                $manuelleIds[(int) $eq->gegen_id] = true;
            }
        }
        unset($poolIds[$sourceGpId]);

        // Sichtbar + keine Derivate/Platzhalter; manuelle Äquivalente bleiben immer drin (kuratiert).
        $poolModels = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', array_keys($poolIds))
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('is_derivat', false)->where('is_platzhalter', false))
                ->orWhereIn('id', array_keys($manuelleIds)))
            ->get(['id', 'name', 'lead_la_supplier_item_id']);
        if ($poolModels->isEmpty()) {
            return ['source' => $this->substSourceOut($sourceGp, $sourceSlugs), 'context' => $context, 'candidates' => []];
        }
        $poolIdList = $poolModels->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Kandidaten-Anker in EINER Query gruppieren.
        $candAnkerByGp = [];
        foreach (DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->whereIn('m.gp_id', $poolIdList)->where('m.role', 'kern')->whereNull('m.deleted_at')
            ->get(['m.gp_id', 'a.id', 'a.slug', 'a.display_de']) as $row) {
            $candAnkerByGp[(int) $row->gp_id][] = ['id' => (int) $row->id, 'label' => $row->display_de ?: $row->slug];
        }

        // Kanten über die Anker-Union (ein Query).
        $unionAnker = $sourceAnkerIds;
        foreach ($candAnkerByGp as $rows) {
            foreach ($rows as $r) {
                $unionAnker[] = $r['id'];
            }
        }
        $kanten = $this->edgeBest(array_values(array_unique($unionAnker)));

        // ── Scoring je Kandidat ──────────────────────────────────────────
        $scored = [];
        $nSource = max(1, count($sourceAnkerIds));
        foreach ($poolModels as $cand) {
            $cid = (int) $cand->id;
            $candAnker = $candAnkerByGp[$cid] ?? [];
            $candIds = array_map(fn ($r) => $r['id'], $candAnker);

            $erhalten = [];
            $verloren = [];
            foreach ($sourceAnkerIds as $sa) {
                $keep = in_array($sa, $candIds, true);
                if (! $keep) {
                    foreach ($candIds as $ca) {
                        if (isset($kanten[$sa][$ca]) && $kanten[$sa][$ca][0] > 0) {
                            $keep = true;
                            break;
                        }
                    }
                }
                $label = $sourceSlugs[$sa] ?? (string) $sa;
                if ($keep) {
                    $erhalten[] = $label;
                } else {
                    $verloren[] = $label;
                }
            }
            $edgeOverlap = round(count($erhalten) / $nSource, 4);

            // Spec 60 · P3: Aroma-Vektoren (Moleküle) sind raus — der Geschmack wird allein über
            // die gemessenen Anker-Kanten erhalten.
            $flavorScore = $edgeOverlap;

            $isManual = isset($manuelleIds[$cid]);
            // Rein-lexikalische Warengruppen-Nachbarn ohne jede Aroma-Beziehung fliegen raus
            // (kein Ersatz-Vorschlag »ins Blaue«) — manuelle Äquivalente bleiben immer.
            if (! $isManual && $flavorScore <= 0.0) {
                continue;
            }

            $scored[] = [
                'gp_id' => $cid,
                'name' => $cand->name,
                'lead_la_supplier_item_id' => $cand->lead_la_supplier_item_id !== null ? (int) $cand->lead_la_supplier_item_id : null,
                'flavor_score' => $flavorScore,
                'edge_overlap' => $edgeOverlap,
                'erhaltene_bruecken' => $erhalten,
                'verlorene_bruecken' => $verloren,
                'is_manual_equiv' => $isManual,
                'candidate_anchor_ids' => $candIds,
            ];
        }

        // Sortierung: kuratiert zuerst, dann Aroma-Treue, dann meiste erhaltene Brücken, dann Name.
        usort($scored, fn ($a, $b) => [
            $b['is_manual_equiv'], $b['flavor_score'], count($b['erhaltene_bruecken']), $a['name'],
        ] <=> [
            $a['is_manual_equiv'], $a['flavor_score'], count($a['erhaltene_bruecken']), $b['name'],
        ]);
        $scored = array_slice($scored, 0, max(1, $limit));

        // ── Anreicherung nur der Top-N (teure Schritte: Allergene, Preis, Kohäsion) ──
        $agg = app(\Platform\FoodAlchemist\Services\GpStamm\GpAggregateService::class);
        $sourceAllergene = $agg->allergene($sourceGp);
        $sourceEk = $this->gpLeadListenEk($sourceGp->lead_la_supplier_item_id !== null ? (int) $sourceGp->lead_la_supplier_item_id : null, $team);

        if ($recipe !== null) {
            $context['base_cohesion'] = $this->recipeCohesion($recipe)['score'];
        }

        $candidates = [];
        foreach ($scored as $c) {
            // Voll-Model (poolModels trägt nur id/name/lead — Allergen-Spalten fehlen dort).
            $candGp = FoodAlchemistGp::find($c['gp_id']);
            if ($candGp === null) {
                continue;
            }

            // Allergen-Neuberechnung VOR Tausch: was der Kandidat NEU/STÄRKER einbringt.
            $candAllergene = $agg->allergene($candGp);
            $allergenWarn = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $cv = $candAllergene[$feld]['value'] ?? null;
                $sv = $sourceAllergene[$feld]['value'] ?? null;
                if ($cv instanceof \Platform\FoodAlchemist\Enums\AllergenValue
                    && in_array($cv, [\Platform\FoodAlchemist\Enums\AllergenValue::Enthalten, \Platform\FoodAlchemist\Enums\AllergenValue::Spuren], true)
                    && ($sv === null || $cv->rank() > $sv->rank())) {
                    $allergenWarn[$feld] = $cv->value;
                }
            }

            // Cost-Achse (R6.3): indikativer Listen-EK der jeweiligen Lead-LA (NICHT mengennormalisiert).
            $candEk = $this->gpLeadListenEk($c['lead_la_supplier_item_id'], $team);
            $cost = [
                'source_listen_ek' => $sourceEk,
                'candidate_listen_ek' => $candEk,
                'guenstiger' => ($sourceEk !== null && $candEk !== null) ? ($candEk < $sourceEk) : null,
                'hinweis' => 'indikativ: Listen-EK der Lead-LA, nicht mengennormalisiert',
            ];

            // Kohäsions-Delta fürs Gesamtgericht (nur mit Rezept-Kontext).
            $kohaesionsDelta = null;
            if ($recipe !== null && $context['base_cohesion'] !== null) {
                $kohaesionsDelta = $this->substCohesionDelta(
                    $recipe, $sourceGp->name, $sourceAnkerIds, $c['candidate_anchor_ids'], $context['base_cohesion']
                );
            }

            $candidates[] = [
                'gp_id' => $c['gp_id'],
                'name' => $c['name'],
                'flavor_score' => $c['flavor_score'],
                'edge_overlap' => $c['edge_overlap'],
                'erhaltene_bruecken' => $c['erhaltene_bruecken'],
                'verlorene_bruecken' => $c['verlorene_bruecken'],
                'kohaesions_delta' => $kohaesionsDelta,
                'is_manual_equiv' => $c['is_manual_equiv'],
                'allergen_warnungen' => $allergenWarn,
                'cost' => $cost,
                'evidenz' => [
                    'tier' => $c['is_manual_equiv'] ? 'kuratiert' : 'abgeleitet',
                    'basis' => $c['is_manual_equiv'] ? 'manuelles Äquivalent' : 'Anker-Kanten',
                ],
            ];
        }

        return [
            'source' => $this->substSourceOut($sourceGp, $sourceSlugs),
            'context' => $context,
            'candidates' => $candidates,
        ];
    }

    /** @param array<int, string> $sourceSlugs */
    private function substSourceOut(FoodAlchemistGp $gp, array $sourceSlugs): array
    {
        return ['gp_id' => (int) $gp->id, 'name' => $gp->name, 'anker' => array_values($sourceSlugs)];
    }

    /**
     * Kohäsions-Delta: Teller-Score MIT Kandidat statt Quell-Komponente minus Basis-Score.
     * Findet die zu ersetzende Komponente über den GP-Namen (Fallback: geteilter kern-Anker),
     * tauscht deren Anker gegen die des Kandidaten, rechnet cohesionFor neu. Null wenn nicht gefunden.
     *
     * @param  list<int>  $sourceAnkerIds
     * @param  list<int>  $candAnkerIds
     */
    private function substCohesionDelta(FoodAlchemistRecipe $recipe, string $sourceName, array $sourceAnkerIds, array $candAnkerIds, int $baseScore): ?int
    {
        $komponenten = $this->resolveRecipeAnchors($recipe);
        $trefferIdx = null;
        foreach ($komponenten as $i => $k) {
            if ($k['label'] === $sourceName) {
                $trefferIdx = $i;
                break;
            }
        }
        if ($trefferIdx === null) {
            foreach ($komponenten as $i => $k) {
                if ($k['kern'] !== null && in_array($k['kern'], $sourceAnkerIds, true)) {
                    $trefferIdx = $i;
                    break;
                }
            }
        }
        if ($trefferIdx === null || $candAnkerIds === []) {
            return null;
        }
        $komponenten[$trefferIdx]['kern'] = $candAnkerIds[0];
        $komponenten[$trefferIdx]['prozess'] = array_slice($candAnkerIds, 1);

        return $this->cohesionFor($komponenten)['score'] - $baseScore;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * Kante je Anker-Paar: [a][b] => [1.0, 'aroma'] — nur Stufe 3 (Spec 60 · P7c).
     */
    private function edgeBest(array $ankerIds): array
    {
        if ($ankerIds === []) {
            return [];
        }
        // Spec 60 · P7c: nur echtes Food Pairing (3★) zählt — eine Regel für Kohäsion, Ranking, Ersatz.
        $out = [];
        foreach ($this->graph()->kanten($ankerIds, $ankerIds, AnkerGraph::HARMONIERT) as $k) {
            $out[$k->von][$k->zu] = [1.0, 'aroma'];
        }

        return $out;
    }

    /** {@see AnkerAufloesung::ankerSlugExakt()} */
    public function ankerSlugExakt(string $token): ?array
    {
        return $this->anker()->ankerSlugExakt($token);
    }

    /** {@see AnkerAufloesung::ankerIdExakt()} */
    public function ankerIdExakt(?string $name): ?int
    {
        return $this->anker()->ankerIdExakt($name);
    }

    /** {@see AnkerAufloesung::ankerAufgeloest()} */
    public function ankerAufgeloest(?string $begriff): ?array
    {
        return $this->anker()->ankerAufgeloest($begriff);
    }

    /** {@see AnkerAufloesung::neighborsForName()} */
    public function neighborsForName(string $name, ?string $typ = null, int $limit = 30): array
    {
        return $this->anker()->neighborsForName($name, $typ, $limit);
    }
}
