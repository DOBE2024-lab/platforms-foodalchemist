<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;

/**
 * 98 · 3.3 (KI-Strang a): der kompakte FLAVOR-PAIRING-Block (Graph-first, nur Stufe 3) und das Anker-Vokabular, aus dem
 * Pairing-Discovery und Pairing-Grounding ihre Stems ziehen. Aus {@see KnowledgeContextService} geschnitten; Rümpfe
 * unverändert. Zustandslos (Audit `$filesUsed` per Referenz wie vorher), ruft die Fassade nie.
 */
final class KnowledgePairingBlock
{
    public const PAIRING_TOP_K = 3;

    public const MAX_PARTNERS = 28;

    public function __construct(private KnowledgeQueryText $text = new KnowledgeQueryText()) {}

    /**
     * Kompakter FLAVOR-PAIRING-Block (vault_context.rs:464-539): Beschreibung
     * gegen Pairing-Doc-Stems matchen (max 3, sortiert), je Anker eine Zeile
     * »- <stem>: A · B · C« (max 28 Partner). Invariante 5: auch »gewagt«
     * zieht NUR belegte Paarungen — der Header sagt das der KI explizit.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    public function pairingBlock(string $description, ?string $stil, array &$filesUsed, int $maxAnchors = self::PAIRING_TOP_K): ?KnowledgeContextBlock
    {
        // Graph-first (2026-07-13): Partner kommen aus dem Anker-Graphen (PairingService),
        // NICHT mehr aus dem Markdown-Volltext. Der Graph ist das Gehirn (kuratiert + Buch +
        // computed, ~179k Kanten); die md-Prosa liefert nur noch Grounding (groundingBlock).
        // Spec 60: nur Stufe 3 (Inspire best match = echtes Food Pairing) geht in den Prompt —
        // unabhängig vom Stil. Die früheren Stil→Typ-Listen zeigten auf `erprobt`, das seit dem
        // Inspire-Umbau nicht mehr existiert (Stil „klassisch" lieferte einen leeren Block).
        $stilHint = match ($stil) {
            'klassisch' => ' (Stil KLASSISCH — bevorzuge etablierte Kombinationen aus dieser Liste)',
            'kreativ' => ' (Stil KREATIV — belegte Basis, Twists nur mit Partnern aus dieser Liste)',
            'gewagt' => ' (Stil GEWAGT — bewusst mutig, aber NUR belegte Partner aus dieser Liste)',
            default => '',
        };

        $tokens = $this->text->tokenize($description);
        if ($tokens === []) {
            return null;
        }

        $matched = [];
        foreach ($this->pairingStems() as $stem) {
            $stemNorm = str_replace(['-', '_'], '', $stem);          // mehrteilige Slugs auch ohne Trenner matchen
            foreach ($tokens as $t) {
                if ($t === $stem || $t === $stemNorm
                    || (mb_strlen($t) >= 4 && (str_contains($stem, $t) || str_contains($stemNorm, $t)))
                    || (mb_strlen($stem) >= 4 && str_contains($t, $stem))) {
                    $matched[] = $stem;
                    break;
                }
            }
        }

        // Semantischer Recall (Hybrid, opt-in): B2 — MERGEN statt nur auffüllen. Semantisch
        // passende Anker-Stems (über die Anker-Embeddings, NICHT Pairing-Docs) treten IMMER bei
        // und können via Top-K lexikalische Stems verdrängen. Deaktiviert (Default) = no-op.
        foreach ($this->semanticAnkerStems($description, $maxAnchors) as $stem) {
            if (! in_array($stem, $matched, true)) {
                $matched[] = $stem;
            }
        }

        if ($matched === []) {
            return null;
        }
        sort($matched);

        $svc = app(\Platform\FoodAlchemist\Services\Pairing\PairingService::class);
        $zeilen = [];
        foreach (array_slice($matched, 0, $maxAnchors) as $stem) {
            // Stem (Doc-Slug, evtl. mit »-«) → Anker-Slug (»_«) für die Graph-Auflösung.
            $res = $svc->neighborsForName(str_replace('-', '_', $stem), null, self::MAX_PARTNERS * 4);
            if ($res['anker'] === null) {
                continue;
            }
            $namen = [];                                             // display_de → Stärke-Symbol (Typ-Prio- + Level-sortiert)
            foreach ($res['partner'] as $p) {
                if ((int) ($p->level ?? 0) < 3) {
                    continue;
                }
                // C-b (2026-08-22): Harmonie-Stärke aus axis/level rahmen — ●●● = beste (L3),
                // ●● = gute (L2), ● = schwächer/ohne Level. Kontrast liegt live NICHT im Graph
                // (Inspire = reine Harmonie-Matrix) → keine ⚡-Annotation; Kontrast kommt aus dem
                // Pairing-Prinzip + Kochwissen (siehe Header + Regelwerk/Wissens-Doc).
                $namen[$p->display_de ?: $p->slug] = $this->harmonieStaerke($p);
                if (count($namen) >= self::MAX_PARTNERS) {
                    break;
                }
            }
            if ($namen !== []) {
                $partnerText = implode(' · ', array_map(
                    fn ($name, $sym) => $name . $sym,
                    array_keys($namen), array_values($namen),
                ));
                $zeilen[] = ['file' => "graph:{$res['anker']['slug']}", 'text' => "- {$stem}: {$partnerText}", 'score' => KnowledgeContextBlock::DETERMINISTISCHER_SCORE];
                $filesUsed[] = "graph:{$res['anker']['slug']}";
            }
        }
        if ($zeilen === []) {
            return null;
        }

        return new KnowledgeContextBlock("# FLAVOR-PAIRING (gemessene Harmonie aus dem Anker-Graphen{$stilHint}"
            . " — ●●● = echtes Food Pairing (Foodpairing best match, geteilte Aromastoffe); bevorzuge diese fuer"
            . " Komponenten + Garnitur, erfinde KEINE unbelegten Paarungen. Kontrast (bewusstes"
            . " Gegeneinander von Saeure/Fett/Textur) leite aus dem Pairing-Prinzip + Kochwissen"
            . " ab, NICHT aus dieser Harmonie-Liste):\n",
            $zeilen, "\n");
    }

    /**
     * C-b: Harmonie-Stärke eines Graph-Partners als Symbol. Inspire kennt nur die Achse
     * `harmony` mit Level 3 (best, ●●●) / 2 (good, ●●). Level 1 oder fehlend → ● (schwach).
     * Nicht-Harmonie-Achsen bekommen kein Symbol (Kontrast liegt live nicht im Graph).
     */
    private function harmonieStaerke(object $p): string
    {
        if (($p->axis ?? null) !== 'harmony') {
            return '';
        }
        $level = $p->level ?? null;

        return match (true) {
            $level >= 3 => ' ●●●',
            $level >= 2 => ' ●●',
            default => ' ●',
        };
    }

    /**
     * @return list<string> Anker-Slugs aus dem Pairing-Graphen (Inspire-Anker-Vokabular), sortiert.
     *
     * Quelle ist seit 2026-08-07 das Anker-VOKABULAR (foodalchemist_vocab_pairing_anchors),
     * nicht mehr die Pairing-Docs (category='pairing'). Die Token→Anker-Brücke lebt damit am
     * Graphen selbst — die Pairing-Docs sind für die KI-Rezept-Erdung nicht mehr nötig und
     * dürfen aufgeräumt werden (die Partner kommen ohnehin aus PairingService, nicht aus den
     * Docs). 'neutral' bleibt außen vor (kein Aroma), konsistent mit embedAnkers().
     */
    public function pairingStems(): array
    {
        static $stems = null;
        if ($stems === null || app()->runningUnitTests()) {
            $stems = DB::table('foodalchemist_vocab_pairing_anchors')
                ->whereNull('deleted_at')->where('slug', '!=', 'neutral')
                ->orderBy('slug')->pluck('slug')
                ->all();
        }

        return $stems;
    }

    /**
     * Semantischer Recall für Pairing über die ANKER-Embeddings (embedAnkers), nicht die
     * Pairing-Docs. Gates: Config + Provider, damit deaktivierte
     * Semantik/kein Provider sauber zu no-op werden.
     *
     * @return list<string> Anker-Slugs, bestes zuerst
     */
    private function semanticAnkerStems(string $description, int $limit): array
    {
        if ($limit <= 0 || ! config('foodalchemist.semantic_search.enabled', false)) {
            return [];
        }
        try {
            $svc = app(KnowledgeEmbeddingService::class);
            if (! $svc->searchEnabled()) {
                return [];
            }

            return $svc->searchAnkerSlugs($description, $limit);
        } catch (\Throwable) {
            return [];
        }
    }
}
