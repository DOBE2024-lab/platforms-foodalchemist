<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Knowledge\WissensSichtbarkeit;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 3.3 (KI-Strang a): Lesewege auf den Wissens-Bestand außerhalb des Prompt-Baus — Sichtbarkeit (§13 H: Lesen ≠ Prompt),
 * MCP-Suche und -Liste, Einzel-Dokument, Frontmatter-Parser. Aus {@see KnowledgeContextService} geschnitten, die Fassade
 * delegiert unverändert; `nurSichtbar()` ist der eine Team-Filter, den auch der Prompt-Pfad der Fassade benutzt.
 * Zustandslos, ruft die Fassade nie.
 */
final class KnowledgeDokumentLeser
{
    /** Sichtbare Dossiers als Abfrage-Basis (§13 H) — EINE Stelle für „was darf dieses Team sehen", statt sie abzuschreiben (Spec 52). */
    public function sichtbareDokumente(?Team $team): \Illuminate\Database\Query\Builder
    {
        return DB::table('foodalchemist_knowledge_documents')->tap($this->nurLesbar($team));
    }

    /** MCP-Leseweg (Entscheid 13.11.2026): wie oben plus Plattform-Regelwerke/-Abläufe für jedes Team ({@see WissensSichtbarkeit::mcpLesbar()}). Nie für die Oberfläche. */
    public function mcpLesbareDokumente(?Team $team): \Illuminate\Database\Query\Builder
    {
        return DB::table('foodalchemist_knowledge_documents')->tap(WissensSichtbarkeit::aktiv() ? static fn ($q) => WissensSichtbarkeit::mcpLesbar($q, 'team_id', $team) : self::nurSichtbar($team));
    }

    /**
     * Spec 82 §13 H — Lese-Sichtbarkeit (MCP/Browser/Vorgangs-Anleitung), getrennt vom PROMPT-Pfad `nurSichtbar()`.
     * Eigene Kette + globales Wissen nur für Master-Team und dessen Unter-Teams, siehe {@see WissensSichtbarkeit}.
     * Notbremse `foodalchemist.knowledge_master_regel` = false: alte Regel (`nurSichtbar`).
     */
    private function nurLesbar(?Team $team, string $spalte = 'team_id'): \Closure
    {
        if (! WissensSichtbarkeit::aktiv()) {
            return self::nurSichtbar($team, $spalte);
        }

        return static fn ($q) => WissensSichtbarkeit::lesbar($q, $spalte, $team);
    }

    /**
     * Sichtbarkeits-Filter für die ROHEN Doc-Queries — HINTER EINEM SCHALTER.
     *
     * Dominiques Modell (2026-09-03): „das Wissen ist global, damit die Generatoren laufen;
     * ein neuer Nutzer bekommt es leer und kann für sich Wissen hinterlegen, das nur für
     * sein Team und Kinder gilt." Das ist wörtlich `TeamScope::applyVisible` — NULL ODER
     * eigene Ancestry.
     *
     * WARUM DER SCHALTER: der Filter ist erst richtig, wenn die DATEN es sind. Auf demo
     * liegen 818 kuratierte Dossiers unter `team_id = 6` statt NULL — gemessen am
     * 2026-09-03 wären mit Filter für Team 6 alles unverändert (598 → 598), für JEDES
     * andere Team und für jeden Console-Lauf ohne Team aber nur 6 von 598 übrig. Der Korpus
     * fiele auf 1 %, ohne dass ein Test rot wird (die Suite seedet mit team_id NULL).
     * Genau dieser Weg wurde in der Nacht gebaut, gemessen und wieder verworfen.
     *
     * Schalter AUS (Default) = byte-identisch zu heute: der Closure ist die Identität.
     * Schalter AN = Dominiques Modell. Der Flip ist eine ENV-Zeile, der Rollback dieselbe
     * Zeile zurück — kein Deploy. Reihenfolge: erst Daten (Bestand auf NULL heben ODER
     * `teams.parent_team_id` setzen), dann Flip.
     *
     * Bei $team === null bleibt genau der globale Seed übrig — nicht alles, nicht nichts.
     */
    public static function nurSichtbar(?Team $team, string $spalte = 'team_id'): \Closure
    {
        if (! (bool) config('foodalchemist.knowledge_team_scope', false)) {
            return static fn ($q) => $q;                         // Schalter aus: keine Änderung
        }

        return static fn ($q) => TeamScope::applyVisible($q, $spalte, $team);
    }

    // ── MCP-Discovery (Phase K): Wissens-Suche für externe LLM-Clients ──────

    /**
     * Volltext-leichte Suche über den Wissens-Bestand: Token-Treffer in
     * slug/titel + Alias-Treffer (gewichtet).
     *
     * Team-Sicht: §13 H (eigene Kette, global nur Master-Familie) + seit 13.11.2026 Plattform-Regelwerke/-Abläufe ({@see mcpLesbareDokumente()}).
     * (Früher: kein Team-Filter, MANDANTEN-INVARIANTE.)
     * Präzisierung gegenüber der früheren Fassung („der Bestand ist global"): die
     * Zeilen sind NICHT `team_id NULL`, sondern tragen zu 99 % den Kurator (Team 6).
     * Global ist der Bestand durch PRODUKTENTSCHEID, nicht durch die Spalte — wer
     * das verwechselt, baut einen Filter ein und kappt 99 % des Korpus.
     *
     * @return list<array{slug: string, titel: string, kategorie: string, version: int, char_count: int, score: float}>
     */
    public function searchDocuments(?Team $team, string $q, ?string $kategorie = null, int $limit = 10, bool $includeInactive = false): array
    {
        $base = $this->mcpLesbareDokumente($team)
            ->whereNull('deleted_at')
            ->when(! $includeInactive, fn ($query) => $query->where('active', 1))
            ->when($kategorie !== null, fn ($query) => $query->where('category', $kategorie));
        $hits = app(KnowledgeSearchService::class)->search($base, $q, max(1, min(50, $limit)),
            (bool) config('foodalchemist.semantic_search.enabled', false), $team);

        return array_map(static fn ($hit) => array_replace($hit, [
            'active' => (bool) $hit['active'], 'version' => (int) $hit['version'], 'char_count' => (int) $hit['char_count'],
        ]), $hits);
    }

    /**
     * #496: Vollständige, seiten-basierte Enumeration des Wissens-Bestands
     * (ohne Suchbegriff, ohne 50er-Cap) — für MCP-Clients, die den ganzen
     * Katalog abrufen wollen. Optional pro Kategorie gefiltert; Frontmatter
     * (thema/sub_thema/relevanz/recherche_datum/tags) wird bei Bedarf aus dem
     * content_md geparst.
     *
     * @return array{total: int, offset: int, limit: int, next_offset: ?int, categories: array<string,int>, documents: list<array>}
     */
    public function listDocuments(?Team $team, ?string $kategorie, int $offset, int $limit, bool $mitFrontmatter = true, bool $includeInactive = false): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $base = $this->mcpLesbareDokumente($team)
            ->whereNull('deleted_at')
            ->when(! $includeInactive, fn ($q) => $q->where('active', 1))
            ->when($kategorie !== null, fn ($q) => $q->where('category', $kategorie));

        $total = (clone $base)->count();
        $categories = $this->mcpLesbareDokumente($team)
            ->whereNull('deleted_at')
            ->when(! $includeInactive, fn ($q) => $q->where('active', 1))
            ->select('category', DB::raw('COUNT(*) AS c'))->groupBy('category')
            ->pluck('c', 'category')->map(fn ($c) => (int) $c)->all();

        $spalten = ['slug', 'title', 'category', 'active', 'version', 'char_count', 'updated_at', 'geltung'];
        // Spec 52/H1: die Art gehoert in die Inventar-Sicht — beim Korpus-Umbau ist „welche
        // Dossiers sind noch nicht eingeordnet" genau die Frage, die man an LIST stellt.
        // Schema-Wache, damit die Liste vor der Migration nicht stirbt.
        if (Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            $spalten[] = 'art';
        }
        if ($mitFrontmatter) {
            $spalten[] = 'content_md';
        }
        $docs = (clone $base)->orderBy('category')->orderBy('slug')
            ->offset($offset)->limit($limit)->get($spalten);

        $documents = $docs->map(function ($doc) use ($mitFrontmatter) {
            $row = [
                'slug' => $doc->slug,
                'title' => $doc->title,
                'category' => $doc->category,
                'art' => $doc->art ?? null,
                'geltung' => json_decode($doc->geltung ?? '[]', true),
                'active' => (bool) $doc->active,
                'version' => (int) $doc->version,
                'char_count' => (int) $doc->char_count,
                'updated_at' => $doc->updated_at,
            ];
            if ($mitFrontmatter) {
                $fm = $this->parseFrontmatter((string) $doc->content_md);
                $row['frontmatter'] = [
                    'thema' => $fm['thema'] ?? null,
                    'sub_thema' => $fm['sub_thema'] ?? null,
                    'relevanz' => $fm['relevanz'] ?? null,
                    'recherche_datum' => $fm['recherche_datum'] ?? null,
                    'tags' => $this->normalizeTags($fm['tags'] ?? []),
                ];
            }

            return $row;
        })->all();

        $next = ($offset + count($documents) < $total) ? $offset + count($documents) : null;

        return [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'next_offset' => $next,
            'categories' => $categories,
            'documents' => $documents,
        ];
    }

    /**
     * Öffentliche Naht für den Trendradar (Clustering + UI): derselbe Parser
     * wie intern, aber mit auf Listen normalisierten `tags`/`quellen`. So
     * bleibt EINE Parser-Wahrheit — der Trendradar kopiert keine eigene
     * (schwächere) Frontmatter-Logik.
     *
     * @return array<string, string|list<string>>
     */
    public function frontmatterOf(string $md): array
    {
        $fm = $this->parseFrontmatter($md);
        foreach (['tags', 'quellen'] as $listenfeld) {
            if (array_key_exists($listenfeld, $fm)) {
                $fm[$listenfeld] = $this->normalizeTags($fm[$listenfeld]);
            }
        }

        return $fm;
    }

    /**
     * Minimaler, dependency-freier YAML-Frontmatter-Parser (Skalar-Keys +
     * Block-Sequenzen `- item`). Reicht für die geSyncten Research-/Domain-
     * Header; kein voller YAML-Support (bewusst schlank, keine Symfony-Yaml-
     * Abhängigkeit).
     *
     * @return array<string, string|list<string>>
     */
    private function parseFrontmatter(string $md): array
    {
        if (! preg_match('/\A\x{FEFF}?\s*---\R(.*?)\R---\s*(\R|$)/su', $md, $m)) {
            return [];
        }
        $fm = [];
        $listKey = null;
        foreach (preg_split('/\R/', $m[1]) as $line) {
            if ($listKey !== null && preg_match('/^\s*-\s+(.*\S)\s*$/', $line, $lm)) {
                $fm[$listKey][] = $this->frontmatterScalar($lm[1]);

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $km)) {
                $wert = trim($km[2]);
                if ($wert === '') {
                    $listKey = $km[1];
                    $fm[$listKey] = [];
                } else {
                    $fm[$km[1]] = $this->frontmatterScalar($wert);
                    $listKey = null;
                }
            }
        }

        return $fm;
    }

    /**
     * tags-Frontmatter auf eine Liste bringen — deckt Block-Sequenz (schon
     * Array), Flow-Sequenz `[a, b, c]` und Einzel-Skalar ab.
     *
     * @param  string|list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags($tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map(fn ($t) => $this->frontmatterScalar((string) $t), $tags), fn ($t) => $t !== ''));
        }
        $s = trim((string) $tags);
        if ($s === '') {
            return [];
        }
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
            $s = substr($s, 1, -1);
        }

        return array_values(array_filter(array_map(fn ($t) => $this->frontmatterScalar($t), explode(',', $s)), fn ($t) => $t !== ''));
    }

    private function frontmatterScalar(string $s): string
    {
        $s = trim($s);
        if (strlen($s) >= 2 && ($s[0] === '"' || $s[0] === "'") && $s[-1] === $s[0]) {
            $s = substr($s, 1, -1);
        }

        return trim($s);
    }

    /** Einzelnes Wissens-Dokument per Slug (aktiv, nicht gelöscht) — MCP-Leseweg ({@see mcpLesbareDokumente()}). */
    public function getDocument(?Team $team, string $slug): ?object
    {
        return $this->mcpLesbareDokumente($team)
            ->where('slug', $slug)->where('active', 1)->whereNull('deleted_at')
            ->first(['id', 'slug', 'title', 'category', 'anchor_id', 'art', 'geltung', 'datenwerte', 'version', 'char_count', 'content_md']);
    }
}
