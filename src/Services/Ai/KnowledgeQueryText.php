<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * 98 · 3.3 (KI-Strang a): Query- und Text-Helfer des Wissenskontexts — Discovery-Query (Brief + Hauptzutaten, Spec 53
 * Query-Hygiene), Scope-Slugs, Tokenizer/Jaccard, semantischer Anker-Recall, Kürzen für Einzelabrufe, Pairing-Namen aus
 * der »## Pairings«-Region, Zutat-Aspekt je Prompt-Key. Aus {@see KnowledgeContextService} geschnitten; Rümpfe unverändert,
 * die vorher privaten Helfer sind hier öffentlich, weil die Fassade und KnowledgePairingBlock sie rufen.
 * Zustandslos, ruft die Fassade nie.
 */
final class KnowledgeQueryText
{
    /**
     * Spec 53 Aufgabe 2 (Query-Hygiene) — die Retrieval-Query ist Brief + Hauptzutat-Slugs, SONST
     * NICHTS. Bis 2026-09-17 hängte diese Methode die WERTE von 18 Leitplanken (niveau, sektor,
     * occasion, saison, …) roh an den Brief. Gemessen per `knowledge.PREVIEW` am Tomatensuppen-Brief:
     * mit Leitplanken gewannen `niveau.*`/`event_playbook_business_lunch` die Discovery per
     * Ein-Token-Treffer (Jaccard) komplett — die Zutaten-/Technik-Domäne (Fonds, Wurzelgemüse) fiel
     * ganz raus. Leitplanken sind kein Rausch-Text für die Ähnlichkeitssuche; sie wirken über ihre
     * EIGENEN, deterministischen Selektoren (`niveauBlock()`, `achsenBlock()`), nicht hier.
     *
     * ★ Ausnahme, mit Beleg (`QueryHygieneKuecheDiaetTest`): `aroma_kueche` (z. B. "thai") und
     * `diaet_hart` (z. B. "vegan") tragen Fachinformation, die oft NICHT im Brief-Text steht, und
     * haben — anders als niveau/saison/occasion/… — KEINEN eigenen deterministischen Selektor, der
     * ihre Kategorie (weltkueche/ernaehrung) sonst erden würde. Ohne sie verschwindet das passende
     * Dossier komplett aus der Discovery, gemessen an Fixture-Dossiers. Diese zwei bleiben deshalb
     * in der Query — als einzige Ausnahme, nicht als Rückfall auf die alte Liste.
     */
    public function discoveryQuery(string $description, array $hauptzutatSlugs = [], array $params = []): string
    {
        $zutaten = array_values(array_filter(array_map(
            static fn ($slug) => is_scalar($slug) ? str_replace(['_', '-'], ' ', trim((string) $slug)) : '',
            $hauptzutatSlugs
        )));
        foreach (['aroma_kueche', 'diaet_hart'] as $key) {
            $value = $params[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $zutaten[] = str_replace(['_', '-'], ' ', (string) $value);
            }
        }

        return trim($description . ' ' . implode(' ', $zutaten));
    }

    /** @return list<string> */
    public function knowledgeScopeSlugs(mixed $scope): array
    {
        if (! is_array($scope)) {
            return [];
        }
        $slugs = [];
        foreach ($scope as $item) {
            $slug = preg_replace('/@v\d+$/', '', trim((string) $item));
            if ($slug !== '' && ! str_starts_with($slug, 'graph:')) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Tokenisiert für Alias- und Slug-Matching (vault_context.rs:343-362):
     * lowercase, Umlaut-Expansion (ä→ae ö→oe ü→ue ß→ss), nur Alphanumerik,
     * Token ≥3 Zeichen.
     *
     * @return list<string> dedupliziert
     */
    public function tokenize(string $s): array
    {
        return app(KnowledgeTokenizer::class)->tokenize($s);
    }

    /** @param list<string> $a @param list<string> $b */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersect = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : $intersect / $union;
    }

    /**
     * ★ Spec 53 (2026-09-17): NICHT mehr Teil der automatischen Kontext-Pipeline (kein
     * Block-Builder ruft das noch auf — die Ganzdokument-Budgetierung ersetzt den toten
     * Pro-Doc-Deckel, s. Docblock bei `routingZeilen()` in `contextFor()`). Bleibt als expliziter,
     * angeforderter Deckel für EINEN einzelnen Dokument-Abruf (`foodalchemist.knowledge.GET`,
     * `max_chars`-Parameter) — ein Mensch/Agent, der bewusst nur einen Ausschnitt sehen will,
     * ist ein anderer Fall als eine Pipeline, die Pflichtwissen unbemerkt kappt.
     */
    public function truncate(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . "\n\n[…gekürzt für KI-Kontext…]";
    }

    /**
     * Invariante 4: extrahiert KOMPAKT die verifizierten Partner-NAMEN aus der
     * »## Pairings«-Region (Wikilink-Displays + **bold**, ≤40 Zeichen, dedupe),
     * NICHT die molekulare Prosa. Region endet bei »## Notizen«/»## Eigene«.
     * $sections = Stil-Filter (Tabelle 4.2): null = ganze Region inkl.
     * Verbund/Trinitas; sonst nur ###-Untersektionen, deren Header mit einem
     * Key beginnt — neue ##-Sektion schaltet den Filter wieder aus.
     *
     * @param  list<string>|null  $sections
     * @return list<string>
     */
    public function extractPairingNames(string $content, ?array $sections = null): array
    {
        $start = mb_strpos($content, '## Pairings');
        if ($start === false) {
            return [];
        }
        $rest = mb_substr($content, $start);
        $end = mb_strpos($rest, '## Notizen');
        if ($end === false) {
            $end = mb_strpos($rest, '## Eigene');
        }
        $region = $end === false ? $rest : mb_substr($rest, 0, $end);

        $scan = $region;
        if ($sections !== null) {
            $kept = '';
            $keep = false;
            foreach (explode("\n", $region) as $line) {
                if (str_starts_with($line, '### ')) {
                    $h = substr($line, 4);
                    $keep = count(array_filter($sections, fn ($k) => str_starts_with($h, $k))) > 0;
                } elseif (str_starts_with($line, '## ')) {
                    $keep = false;                                  // neue ##-Sektion (z.B. Verbund) → aus
                }
                if ($keep) {
                    $kept .= $line . "\n";
                }
            }
            $scan = $kept;
        }

        $names = [];
        $seen = [];
        $push = function (string $raw) use (&$names, &$seen): void {
            $name = trim($raw);
            $key = mb_strtolower($name);
            if ($name !== '' && strlen($name) <= 40 && ! isset($seen[$key])) {
                $seen[$key] = true;
                $names[] = $name;
            }
        };
        // Wikilinks [[slug|Display]] / [[Display]] → Display
        foreach (array_slice(explode('[[', $scan), 1) as $part) {
            $close = strpos($part, ']]');
            if ($close !== false) {
                $inner = substr($part, 0, $close);
                $segments = explode('|', $inner);
                $push(end($segments));
            }
        }
        // **Bold**-Pairings (Einträge ohne eigene Datei)
        $boldParts = explode('**', $scan);
        for ($i = 1; $i < count($boldParts); $i += 2) {
            if (strlen($boldParts[$i]) <= 40 && preg_match('/[\x00-\x1f]/', $boldParts[$i]) === 0) {
                $push($boldParts[$i]);
            }
        }

        return $names;
    }

    /**
     * Spec 53/H Aufgabe B: welcher Aspekt (`zutat.<anker>--<aspekt>`) trägt die Antwort auf die
     * Frage, die DIESER Prompt-Key stellt — Naming/Rezeptur fragt "wie wird sie verwendet",
     * Produktionsschritte fragen "wie verhält sie sich unter Hitze/Säure/Zeit", Review/Konformität
     * fragt "was ist zu beachten" (Cave), GP-Anlage fragt "was ist sie" (Steckbrief). Eine Zutat
     * hat immer nur EINEN Platz im Grounding-Block (B2) — anders als bei der Aspekt-Präferenz in
     * `KnowledgeSearchService::search()` (Aufgabe A) muss hier nicht zwischen mehreren Kandidaten
     * gewählt werden, das Aspekt-Dossier wird direkt adressiert.
     */
    public function zutatAspektFuer(string $feature): string
    {
        return match (true) {
            in_array($feature, ['recipe.generator', 'vk.generator'], true) => 'verwendung',
            in_array($feature, ['recipe.steps', 'vk.plating'], true) => 'verhalten',
            in_array($feature, ['recipe.review', 'vk.review'], true) => 'cave',
            str_starts_with($feature, 'conformance.') => 'cave',
            str_starts_with($feature, 'gp.') => 'steckbrief',
            default => 'verwendung',
        };
    }
}
