<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\Text;

/**
 * 98 · 3.3 (KI-Strang c): Anker-Auflösung — Slug-Normalisierung und -Abgleich, fold(), exakte Anker-Treffer (inkl. eindeutigem
 * Singular, Synonym, Kompositum), der `neutral`-Anker, die aktiven Aroma-Zuordnungen je GP und der Diät-Filter für Anker.
 * Aus {@see PairingService} geschnitten; Rümpfe und die Zwischenspeicher je Instanz unverändert. Blatt: kennt weder
 * PairingService noch Kombinationslogik oder RezeptProfil — beide lesen hier statt an der Fassade, damit der Kreis
 * Pairing ↔ Kombinationslogik ↔ RezeptProfil aufgeht.
 */
class AnkerAufloesung
{
    /**
     * V-045: der `neutral`-Anker wird einmal je Service-Instanz aufgelöst, nicht je
     * Rezept. `resolveRecipeAnchors` läuft im Kandidaten-Pool je Gericht — der Lookup
     * war damit eine Query pro Gericht für einen Wert, der sich nie ändert.
     * **Bewusst untypisiert** und ohne Cast: der Wert wird per `===` gegen
     * `->value('anchor_id')` verglichen, und beide kommen aus derselben untypisierten
     * Query-Builder-Quelle. Ein `(int)`-Cast hier würde die Identitäts-Vergleiche
     * unten je nach Treiber kippen.
     */
    private mixed $neutralAnkerId = null;

    private bool $neutralAnkerAufgeloest = false;

    /** Pairing-Slug-Normalisierung: ä→a … PLUS Digraphen ae→a/oe→o/ue→u (Tabelle 2). */
    public function normalizeAnkerSlug(string $s): string
    {
        return strtr(Text::grundlaut(mb_strtolower($s)), ['ae' => 'a', 'oe' => 'o', 'ue' => 'u']);
    }

    /** T1/T2: tolerant + ungerichtet — roh/normalisiert exakt oder _-Präfix; nie Geschwister-Sorten. */
    public function ankerSlugMatches(string $a, string $b): bool
    {
        $praefix = fn (string $x, string $y) => str_starts_with($y, $x . '_') || str_starts_with($x, $y . '_');
        if ($a === $b || $praefix($a, $b)) {
            return true;
        }
        $an = $this->normalizeAnkerSlug($a);
        $bn = $this->normalizeAnkerSlug($b);

        return $an === $bn || $praefix($an, $bn);
    }

    /** Cache für {@see ankerSlugExakt()} — ein Grounding-Lauf prüft mehrere Tokens (bis zu 8 Zutaten
     * plus Singular-Kandidaten je Token), ohne Cache je Prüfung ein voller Tabellen-Scan (Orchestrierung
     * 2026-09-18: "sobald der Dossier-Import die Anker-Zahl hebt, lohnt ein Cache je Request"). */
    private ?\Illuminate\Support\Collection $ankerExaktListe = null;

    /** fold(): lowercase, Umlaut→Digraph, nicht-alnum→Space, kollabiert, umrandet. */
    public function fold(string $s): string
    {
        $s = Text::umschrift(mb_strtolower($s));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return ' ' . trim(preg_replace('/\s+/', ' ', $s)) . ' ';
    }

    /** V-045: `neutral` einmal je Instanz auflösen. Eigenes Flag, damit ein NULL-Ergebnis nicht jedes Mal neu gefragt wird. */
    public function neutralAnkerId(): mixed
    {
        if (! $this->neutralAnkerAufgeloest) {
            $this->neutralAnkerId = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('slug', 'neutral')->value('id');
            $this->neutralAnkerAufgeloest = true;
        }

        return $this->neutralAnkerId;
    }

    /** Aktive Aroma-Zuordnungen je Grundprodukt für die Rezeptprofile. */
    public function gpAnkerZuordnungen(array $gpIds): Collection
    {
        return DB::table('foodalchemist_gp_anchor_mappings as m')
            ->join('foodalchemist_vocab_pairing_anchors as a', 'a.id', '=', 'm.anchor_id')
            ->whereIn('m.gp_id', $gpIds)->whereNull('m.deleted_at')->whereNull('a.deleted_at')
            ->orderBy('m.id')->get(['m.gp_id', 'm.anchor_id', 'm.role', 'm.staerke'])
            ->each(function ($anker): void {
                $anker->role = match ($anker->staerke) {
                    'schwach' => 'neben',
                    'mittel', 'stark' => 'kern',
                    default => $anker->role,
                };
            })->groupBy('gp_id');
    }

    /** Spec 60 · P5: ID des Ankers „neutral" (kein Aroma), sonst null. */
    public function neutralAnker(): ?int
    {
        $id = $this->neutralAnkerId();

        return $id !== null ? (int) $id : null;
    }

    public function ankerSlugVonId(int $id): ?string
    {
        return $this->ankerExaktListe()->first(fn ($a) => (int) $a->id === $id)?->slug;
    }

    /** Tierische Fonds/Brühen-Ausnahmen: diese Einträge in „Brühen und Fonds" sind pflanzlich. */
    private const PFLANZLICHE_FONDS = ['vegetable_bouillon', 'kombu_dashi', 'truffle_juice'];

    /** Passt ein Anker (Kategorie/Unterkategorie aus dem Inspire-Vokabular) zur Ernährungsform? */
    public function passtZurDiaet(object $anker, string $diaet): bool
    {
        $kat = (string) ($anker->category ?? '');
        $sub = (string) ($anker->subcategory ?? '');
        if ($kat === 'Protein' && $sub !== 'Protein/Pflanzliche Proteine') {
            return false;                                           // Fleisch, Fisch, Meeresfrüchte, Charcuterie
        }
        if ($kat === 'Brühen und Fonds' && ! in_array($anker->slug, self::PFLANZLICHE_FONDS, true)) {
            return false;
        }
        if ($diaet === 'vegan') {
            if ($kat === 'Milchprodukte' && $sub !== 'Milchprodukte/Pflanzliche Milchprodukte') {
                return false;                                       // Milch, Käse, Butter, Sahne, Ei
            }
            if (str_contains((string) $anker->slug, 'honey')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Spec 53/H Aufgabe B1 → verschärft (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 17):
     * für das Zutaten-Grounding EXAKTE Gleichheit von `display_de` oder `slug` (beide normalisiert),
     * NIE Nearest-Neighbor. `neighborsForName()`/`resolveByName()` sind für die INTERAKTIVE
     * Pairing-Suche gebaut (Composer, Seed-Anker) und akzeptieren bewusst Wort-Fragmente ≥4 Zeichen
     * aus `display_de` als Treffer (`anchorIndex()` prio 2) plus einen semantischen Fallback — genau
     * das ist hier die Gefahr: `leitTokens()` liefert aus "Passionsfrucht-Gelee" auch das Token
     * "gelee", und `resolveByName('gelee')` traf einen Anker, dessen `display_de` NUR zufällig das
     * Wort "Gelee" enthält (z. B. "Apfel Gelee") — nicht die gemeinte Zutat. Grounding lädt
     * deterministisch GENAU EIN Dossier und darf sich diese Verwechslung nicht erlauben: lieber
     * `ohne_anker` (ehrlich sichtbar in der Herkunft) als ein falsches Dossier.
     *
     * ★ Konservativer Singular-Fallback (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 21):
     * `leitTokens()` liefert aus einem Brief oft den Plural ("aprikosen", "tomaten", "kartoffeln"),
     * das Anker-Label steht im Singular ("Aprikose", "Tomate", "Kartoffel") — exakte Gleichheit trifft
     * dann nie. Deutsche Pluralbildung ist nicht eindeutig rückführbar (Aprikose→Aprikosen braucht
     * `-n` weg, nicht `-en`) — deshalb EINE Stufe abschneiden, aber ALLE plausiblen Endungen
     * gleichzeitig prüfen (`-n`, `-en`, `-e`, `-s`, `-er`) und nur akzeptieren, wenn GENAU EIN Anker
     * über den gesamten Versuch matcht. Mehrdeutigkeit (zwei Endungen treffen zwei verschiedene
     * Anker) bleibt bewusst `ohne_anker` statt zu raten — dieselbe Regel wie beim exakten Treffer.
     *
     * @return ?array{slug: string, via: 'exakt'|'singular'}
     */
    public function ankerSlugExakt(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (($anker = $this->ankerExaktGleich($token)) !== null) {
            return ['slug' => $anker->slug, 'via' => 'exakt'];
        }

        $treffer = [];
        foreach (['n', 'en', 'e', 's', 'er'] as $endung) {
            if (! str_ends_with($token, $endung)) {
                continue;
            }
            $kandidat = mb_substr($token, 0, mb_strlen($token) - mb_strlen($endung));
            if (mb_strlen($kandidat) < 3) {
                continue;   // zu kurz, um noch verlässlich zu sein
            }
            if (($singularAnker = $this->ankerExaktGleich($kandidat)) !== null) {
                $treffer[$singularAnker->slug] = $singularAnker;
            }
        }
        if (count($treffer) === 1) {
            return ['slug' => reset($treffer)->slug, 'via' => 'singular'];
        }

        return null;
    }

    /** Exakte Gleichheit von `slug` (normalisiert) ODER `display_de` (gefaltet) — Kern von {@see ankerSlugExakt()}. */
    private function ankerExaktGleich(string $token): ?object
    {
        $slugNorm = $this->normalizeAnkerSlug($token);
        $displayNorm = trim($this->fold($token));

        return $this->ankerExaktListe()->first(fn ($a) => $this->normalizeAnkerSlug($a->slug) === $slugNorm
            || trim($this->fold($a->display_de)) === $displayNorm);
    }

    /** @return \Illuminate\Support\Collection<int, object{slug: string, display_de: string}> */
    private function ankerExaktListe(): \Illuminate\Support\Collection
    {
        return $this->ankerExaktListe ??= DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where('slug', '!=', 'neutral')->get(['id', 'slug', 'display_de']);
    }

    /**
     * Spec 58 · Paket 1: Anker-ID nur bei EXAKTER Gleichheit (inkl. eindeutigem Singular) — für
     * Zutaten ohne Mapping. Grundprodukt-Namen tragen den Zustand nach dem Doppelpunkt
     * („Möhre: frisch, Stifte") → nur der Grundname davor zählt. Kein Wortteil-Treffer: lieber
     * eine sichtbare Lücke als „Sauce: Chimichurri" → A1-Sauce.
     */
    public function ankerIdExakt(?string $name): ?int
    {
        $basis = trim((string) strtok((string) $name, ':'));
        if ($basis === '') {
            return null;
        }
        $treffer = $this->ankerSlugExakt($basis);
        // „Petersilie, glatt" → „Petersilie": Teil vor dem Komma, ebenfalls nur exakt.
        if ($treffer === null && str_contains($basis, ',')) {
            $treffer = $this->ankerSlugExakt(trim((string) strtok($basis, ',')));
        }
        if ($treffer === null) {
            return null;
        }
        $anker = $this->ankerExaktListe()->first(fn ($a) => $a->slug === $treffer['slug']);

        return $anker !== null ? (int) $anker->id : null;
    }

    /**
     * Spec 80 A5: Anker für einen Suchbegriff — exakt, sonst über kuratierte Synonyme, sonst über ein
     * zerlegtes Kompositum. Jeder Schritt endet in {@see ankerIdExakt}; es gibt KEINEN Teilwort-Treffer
     * (Spec 60). Die Synonyme pflegt der Mensch (`terminology_aliases`, MCP `terminology.POST`), z. B.
     * „petersilie ⇄ glatte petersilie" — der Code erfindet keine Zuordnung. Die Zerlegung trennt nur einen
     * bekannten Kopf ab („Petersilienpüree" → „petersilie" + „püree", siehe
     * {@see TerminologyService::decompoundPhrasesFor}); der Rest muss für sich exakt ein Anker sein.
     *
     * @return array{id: int, via: string}|null  via: exakt | synonym | zerlegt
     */
    public function ankerAufgeloest(?string $begriff): ?array
    {
        $begriff = trim((string) $begriff);
        if ($begriff === '') {
            return null;
        }
        if (($id = $this->ankerIdExakt($begriff)) !== null) {
            return ['id' => $id, 'via' => 'exakt'];
        }
        $term = app(\Platform\FoodAlchemist\Services\GpStamm\TerminologyService::class);
        foreach ($term->aliasPhrasesFor($begriff) as $phrase) {
            if (($id = $this->ankerIdExakt($phrase)) !== null) {
                return ['id' => $id, 'via' => 'synonym'];
            }
        }
        foreach ($term->decompoundPhrasesFor($begriff) as $phrase) {
            $modifier = trim((string) preg_replace('/\s+\S+$/u', '', $phrase));
            if ($modifier === '' || $modifier === $phrase) {
                continue;
            }
            if (($id = $this->ankerIdExakt($modifier)) !== null) {
                return ['id' => $id, 'via' => 'zerlegt'];
            }
            foreach ($term->aliasPhrasesFor($modifier) as $alias) {
                if (($id = $this->ankerIdExakt($alias)) !== null) {
                    return ['id' => $id, 'via' => 'zerlegt'];
                }
            }
        }

        return null;
    }

    // ── Anker-Suche (Composer, MCP, Wissen): lexikalischer Index, semantischer Rückfall, Nachbarn — 98 · 3.3 KI-c aus PairingService ──

    /** Spec 60 · P2: einzige Lesestelle für die Harmonie zwischen Ankern. */
    private function graph(): AnkerGraph
    {
        return app(AnkerGraph::class);
    }

    private ?array $anchorIndex = null;

    /** Term-Index über alle Anker (außer neutral): slug prio 0, display 1, Einzelwörter ≥ 4 prio 2. */
    private function anchorIndex(): array
    {
        if ($this->anchorIndex !== null) {
            return $this->anchorIndex;
        }
        $index = [];
        foreach (DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where('slug', '!=', 'neutral')->get(['id', 'slug', 'display_de']) as $anker) {
            $terme = [[trim($this->fold($anker->slug)), 0]];
            $display = trim($this->fold($anker->display_de));
            if ($display !== $terme[0][0]) {
                $terme[] = [$display, 1];
            }
            foreach (array_unique(array_merge(explode(' ', $terme[0][0]), explode(' ', $display))) as $wort) {
                if (mb_strlen($wort) >= 4) {
                    $terme[] = [$wort, 2];
                }
            }
            foreach ($terme as [$term, $prio]) {
                if ($term === '') {
                    continue;
                }
                if (! isset($index[$term]) || $index[$term][1] > $prio) {
                    $index[$term] = [$anker->id, $prio];            // bestehender mit ≤ prio bleibt
                }
            }
        }

        return $this->anchorIndex = $index;
    }

    /** resolve_by_name: längster Substring-Term gewinnt, Gleichstand → niedrigere prio. */
    public function resolveByName(string $name): ?int
    {
        $folded = $this->fold($name);
        $gewinner = null;
        foreach ($this->anchorIndex() as $term => [$ankerId, $prio]) {
            if (str_contains($folded, ' ' . $term . ' ') || str_contains($folded, $term)) {
                if ($gewinner === null
                    || mb_strlen($term) > $gewinner[0]
                    || (mb_strlen($term) === $gewinner[0] && $prio < $gewinner[2])) {
                    $gewinner = [mb_strlen($term), $ankerId, $prio];
                }
            }
        }

        return $gewinner[1] ?? null;
    }

    /**
     * Spec 50 · B-7: lexikalische Anker-KANDIDATEN für einen Namen — alle Anker, deren Term
     * als ganzes Wort im Namen steht (Wortgrenze wie lexicalAnkerIsSolid, KEINE Substrings:
     * „auberGINe" liefert hier kein Gin). Reihenfolge: längster Term zuerst, Gleichstand →
     * niedrigere prio. Das ist die billige Vorauswahl, aus der die KI den Kern-Anker wählt;
     * resolveByName (EIN Gewinner, auch Substring) bleibt für den Signal-Fixer.
     *
     * @return list<int> Anker-Ids
     */
    public function lexicalAnkerKandidaten(string $name, int $limit = 8): array
    {
        $folded = $this->fold($name);
        $treffer = [];                                                // id => [len, prio]
        foreach ($this->anchorIndex() as $term => [$ankerId, $prio]) {
            if (! str_contains($folded, ' ' . $term . ' ')) {
                continue;
            }
            $len = mb_strlen((string) $term);
            if (! isset($treffer[$ankerId]) || $treffer[$ankerId][0] < $len
                || ($treffer[$ankerId][0] === $len && $treffer[$ankerId][1] > $prio)) {
                $treffer[$ankerId] = [$len, $prio];
            }
        }
        uasort($treffer, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_slice(array_map('intval', array_keys($treffer)), 0, $limit);
    }

    /**
     * Quality-Gate für einen lexikalischen Anker-Treffer: NUR akzeptieren, wenn
     * ein Anker-Term als ganzes Wort in der Anfrage steht. resolveByName matcht
     * auch beliebige Substrings — „auberGINe" trifft so den „Gin"-Anker. Ein
     * solcher Substring-Zufall ist ein stiller Falsch-Treffer, der den
     * semantischen Fallback fälschlich verhindern würde → hier verworfen.
     * fold() umschließt mit Spaces ⇒ Wortgrenze = space-delimitiertes Vorkommen
     * (konsistent zu anchorIndex/resolveByName; nur strenger — keine Substrings).
     */
    private function lexicalAnkerIsSolid(string $name, object $anker): bool
    {
        $q = $this->fold($name);
        foreach ([(string) $anker->slug, (string) $anker->display_de] as $raw) {
            $folded = trim($this->fold($raw));
            if ($folded === '') {
                continue;
            }
            if (str_contains($q, ' ' . $folded . ' ')) {          // ganzer Term als Wortfolge
                return true;
            }
            foreach (explode(' ', $folded) as $wort) {            // Einzelwörter ≥4 (wie anchorIndex prio 2)
                if (mb_strlen($wort) >= 4 && str_contains($q, ' ' . $wort . ' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * B: semantische Anker-Auflösung als Fallback (opt-in). Gibt die Anker-ID
     * des besten Embedding-Treffers über der Schwelle zurück, sonst null.
     * Deaktiviert (Default) / kein Provider / Fehler ⇒ null (kein Verhalten).
     */
    public function resolveAnkerSemantically(string $name): ?int
    {
        if (trim($name) === '' || ! config('foodalchemist.semantic_search.enabled', false)) {
            return null;
        }
        try {
            return app(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class)->resolveAnkerId($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Wie resolveAnkerSemantically, aber mit Score — für die transparente
     * Match-Weg-Kennzeichnung in neighborsForName (via=semantic + score).
     * Gleiche Gates: aus / kein Provider / Fehler ⇒ null.
     *
     * @return array{id: int, score: float}|null
     */
    private function resolveAnkerSemanticallyScored(string $name): ?array
    {
        if (trim($name) === '' || ! config('foodalchemist.semantic_search.enabled', false)) {
            return null;
        }
        try {
            return app(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class)
                ->resolveAnkerWithScore($name);
        } catch (\Throwable) {
            return null;
        }
    }

    public function ankerNeighbors(string $slug, ?string $typ = null, int $limit = 30): Collection
    {
        $limit = max(1, min(200, $limit));
        $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');
        if ($ankerId === null) {
            return collect();
        }

        // Spec 60 · P2: Partner aus der Harmonie ({@see AnkerGraph}), stärkste Stufe zuerst. Es gibt
        // nur noch einen Kantentyp (`aroma`, Inspire); ein anderer `$typ` hat keine Partner. Die
        // Felder type/evidence/axis/level/weight bleiben für die Konsumenten (pairingBlock,
        // forGeneration, neighborsForName, pairings.GET) in derselben Form erhalten.
        if ($typ !== null && $typ !== 'aroma') {
            return collect();
        }

        // Nur ★★★ ist Empfehlung (Dominique 15.10.): ★★ = Grund-Aroma-Passform, im Schnitt 94 ★★- gegen
        // 12 ★★★-Partner je Zutat, viel „technisch ja, kulinarisch nein" (Zitrone + Tagliatelle).
        return $this->graph()->partner((int) $ankerId, AnkerGraph::HARMONIERT, $limit)
            ->map(fn ($p) => (object) [
                'id' => $p->id, 'slug' => $p->slug, 'display_de' => $p->display_de,
                'type' => 'aroma',
                'evidence' => 'Foodpairing Inspire (best match)',
                'axis' => 'harmony',
                'level' => $p->stufe,
                'weight' => AnkerGraph::GEWICHT[$p->stufe],
            ]);
    }

    /**
     * MCP-Discovery (Phase K): Pairing-Partner für einen Zutat-NAMEN oder
     * Anker-Slug. Auflösung ist HYBRID (analog gps.SEARCH): exakter/
     * normalisierter Slug → lexikalischer Anker-Index (resolveByName) →
     * semantischer Fallback (opt-in, Konfidenz-Floor), der deutsche Begriffe
     * gegen den englischen Inspire-Graph auflöst. Der Match-Weg steht in
     * anker.resolution.via (slug|lexical|semantic; semantic zusätzlich mit
     * score), damit der Client sieht, worauf UND WIE gematcht wurde.
     *
     * @return array{anker: ?array{id: int, slug: string, display_de: ?string, resolution: array{via: string, score?: float}}, partner: list<object>}
     */
    public function neighborsForName(string $name, ?string $typ = null, int $limit = 30): array
    {
        $via = 'slug';
        $score = null;

        $anker = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('slug', array_unique([trim($name), $this->normalizeAnkerSlug($name)]))
            ->first(['id', 'slug', 'display_de']);

        // Lexikalischer Anker-Index — aber nur akzeptieren, wenn der Treffer ein
        // Wortgrenzen-Match ist (lexicalAnkerIsSolid). Ein Substring-Zufall
        // („auberGINe" → Gin) wird verworfen, damit der semantische Fallback
        // greifen kann statt still den falschen Anker zurückzugeben.
        if ($anker === null && ($ankerId = $this->resolveByName($name)) !== null) {
            $kandidat = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('id', $ankerId)->first(['id', 'slug', 'display_de']);
            if ($kandidat !== null && $this->lexicalAnkerIsSolid($name, $kandidat)) {
                $via = 'lexical';
                $anker = $kandidat;
            }
        }

        // B (Hybrid): semantischer Fallback, wenn die Lexik nichts findet. Fängt
        // deutsche Gastro-Begriffe gegen den englischen Inspire-Graph ab
        // („Aubergine" → „eggplant"). Opt-in (semantic_search.enabled) + Konfidenz-
        // Floor (anker_min_score); ohne Provider / aus ⇒ reine Lexik wie zuvor.
        if ($anker === null && ($hit = $this->resolveAnkerSemanticallyScored($name)) !== null) {
            $treffer = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('id', $hit['id'])->first(['id', 'slug', 'display_de']);
            if ($treffer !== null) {
                $anker = $treffer;
                $via = 'semantic';
                $score = round($hit['score'], 3);
            }
        }

        if ($anker === null) {
            return ['anker' => null, 'partner' => []];
        }

        $resolution = ['via' => $via];
        if ($score !== null) {
            $resolution['score'] = $score;
        }

        return [
            'anker' => [
                'id' => (int) $anker->id,
                'slug' => $anker->slug,
                'display_de' => $anker->display_de,
                'resolution' => $resolution,
            ],
            'partner' => $this->ankerNeighbors($anker->slug, $typ, $limit)->all(),
        ];
    }
}
