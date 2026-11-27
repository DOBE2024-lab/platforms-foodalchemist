<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;

/**
 * 98 · 3.3 (KI-Strang a): Routing-Auflösung des Wissenskontexts — welcher Routing-Schlüssel zu einem Prompt-Key gehört
 * (Alias mit Rückfall je Kategorie), welche Cross-Cutting-Dossiers ein Feature zieht, das Zeichenbudget je Feature, die
 * propose()-Optionen und die Regelwerk-Slug-Karte. Aus {@see KnowledgeContextService} geschnitten; die Fassade führt die
 * öffentlichen Konstanten als Alias und delegiert die Methoden unverändert. Zustandslos, ruft die Fassade nie.
 */
final class KnowledgeRouting
{
    /**
     * Invariante 1: diese 7 gehen bei `cross_cutting:always`-Features IMMER mit (Reihenfolge = Ist).
     *
     * ⚠ LEGACY-DEFAULT seit Spec 50 Welle 2 (2026-09-06): alle 7 Originale sind auf demo
     * DEAKTIVIERT (in Ein-Thema-Splits zerlegt), und kein live geroutetes Feature nutzt den
     * Default mehr — `concept.wording` + `foodbook.kundentext` überschreiben ihn per config
     * `ai.cross_cutting_slugs` mit Split-Slugs; die Generatoren laden cross_cutting per
     * discovery. Die Konstante bleibt, weil Tests + Wissens-Browser daran hängen. Ein Feature,
     * das künftig ohne Override auf `always` geroutet wird, bekäme hier still NICHTS —
     * `wissen-steuerdaten-w0 --verify` (Cross-Cutting-Wächter) macht das sichtbar.
     */
    public const ALWAYS_LOAD_CROSS_CUTTING = [
        'substitutionen', 'saisonkalender', 'synonyme', 'sauce_mutterstrukturen',
        'mengen_defaults', 'techniken', 'bruehen_fonds',
    ];

    /** W0-5: das aufgelöste Zeichenbudget eines Features (für Prüf-Werkzeuge). */
    public function budgetFuer(string $feature): int
    {
        return $this->knowledgeBudget($feature, in_array($feature, self::REZEPT_BUDGET_KEYS, true));
    }

    /**
     * Spec 52/A2+A4 — welcher ROUTING-Schlüssel gehört zu einem Prompt-Key?
     *
     * Heute sind das zwei Schlüsselräume im selben Aufruf: der Gateway löst den Kanon über den
     * Prompt-Key auf (`recipe.generator`), das Routing wird aber mit einem hartkodierten
     * Alt-Feature gerufen. Wer das nicht weiss, hält die Generatoren für ungesteuert — und ein
     * `knowledge_routings.PUT` auf `vk.generator` schreibt stumm ins Leere.
     *
     * Die Tabelle ist AUS DEN AUFRUFSTELLEN abgelesen, nicht geraten; jede Zeile nennt ihre.
     * Sie ist bewusst hier zentral, damit Bericht (`wissen-versorgung`) und Auskunft
     * (`regelwerk.GET`) dieselbe Antwort geben. **Mit Spec 52/D1 verschwindet sie** — dann ist
     * der Schlüsselraum einer und diese Methode gibt den Prompt-Key unverändert zurück.
     *
     * @var array<string, string>
     */
    public const ROUTING_ALIAS = [
        // RecipeGenerationContextService:88 — EIN Pfad; `$vkModus` entscheidet nur den Kanon-Key,
        // nicht den Routing-Key. Basisrezept und Gericht teilen sich damit eine Routing-Politik.
        'recipe.generator' => 'ai_generate_recipe',
        'vk.generator' => 'ai_generate_recipe',
        // RecipeModal:961 / BulkEnrichService::proposeDichteklasse — contextFor() läuft unter
        // 'recipe.eigenschaften', propose() unter 'recipe.dichteklasse'.
        'recipe.dichteklasse' => 'recipe.eigenschaften',
    ];

    /** Der Routing-Schlüssel, unter dem dieser Prompt-Key sein Wissen zieht. */
    public static function routingFeatureFuer(string $promptKey): string
    {
        return self::ROUTING_ALIAS[$promptKey] ?? $promptKey;
    }

    /**
     * Spec 52/Paket 2 — Aufrufe, die die REZEPT-Deckel benutzen.
     *
     * ★ Vorher stand hier ein String-Vergleich: `$feature === 'ai_generate_recipe'`. Der
     * gatete **acht** Verhaltensweisen, darunter (bis Spec 53) jeden Pro-Dossier-Deckel
     * (`RECIPE_MAX_CHARS_PER_DOC`, seither entfernt — er war nie verdrahtet, s. Docblock am
     * `routingZeilen()`-Aufruf in `contextFor()`). Den Alt-Namen einfach durch den Prompt-Key zu
     * ersetzen hätte deshalb JEDEN Rezept-Prompt anders gekappt — still, ohne dass ein Test rot
     * wird. Genau die Fehlerklasse, die diese Spec abbaut. Heute bestimmt `REZEPT_BUDGET_KEYS` nur
     * noch die Discovery-Prioritätsreihenfolge (unten) und das Gesamt-Zeichenbudget.
     *
     * Als Satz geschrieben, ist die Umstellung verhaltensneutral: `recipe.generator` und
     * `vk.generator` bekommen dieselben Deckel wie der Alt-Name, und der Alt-Name funktioniert
     * weiter, solange Aufrufer oder Steuerdaten ihn noch tragen.
     *
     * @var list<string>
     */
    public const REZEPT_BUDGET_KEYS = ['ai_generate_recipe', 'recipe.generator', 'vk.generator'];

    /**
     * Routing-Zeilen eines Features — mit Rückfall auf den Alt-Namen.
     *
     * Damit wird `knowledge_routings.PUT` auf `vk.generator` erstmals wirksam (vorher schrieb
     * es stumm ins Leere, weil der Generator unter `ai_generate_recipe` nachsah), ohne dass die
     * bestehenden Zeilen migriert sein müssen: eigene Zeilen gewinnen, sonst gilt der Alt-Name.
     * Dasselbe „Neues gewinnt, Altes bleibt Fallback" wie bei Kanon ⇄ Bindung und Achse ⇄ Config.
     */
    /**
     * Die WIRKSAMEN Routing-Zeilen eines Features — für Berichte und Wächter.
     *
     * ★ Warum das öffentlich ist: `WissensProfilService` und `WissensVersorgungService` haben
     * die Zeilen zuerst selbst abgefragt (`where('feature', $routingKey)`) und damit die
     * ALIAS-Zeilen gezeigt, während der Prompt-Bau bereits die zusammengeführten benutzte.
     * Gefunden beim Verifizieren auf demo: eine gesetzte VK-Zeile wirkte, der Bericht zeigte
     * weiter den geerbten Wert. Ein Diagnose-Werkzeug, das über die Laufzeit lügt, ist
     * schlimmer als keines — und es ist genau die Fehlerklasse, die diese Spec abbaut.
     *
     * Deshalb: eine Formel, drei Abnehmer (`docs/ARCHITEKTUR.md` — „Eine Formel pro fachlicher
     * Wahrheit").
     */
    public function wirksameRoutings(string $feature): \Illuminate\Support\Collection
    {
        return $this->routingZeilen($feature);
    }

    private function routingZeilen(string $feature): \Illuminate\Support\Collection
    {
        $eigene = DB::table('foodalchemist_knowledge_routings')->where('feature', $feature)->get();
        $alt = self::ROUTING_ALIAS[$feature] ?? null;
        if ($alt === null) {
            return $eigene;
        }

        // ★ Der Rückfall wirkt PRO KATEGORIE, nicht pro Feature.
        //
        // Meine erste Fassung gab die eigenen Zeilen zurück, sobald es welche gab — also
        // alles-oder-nichts. Wer EINE VK-Zeile setzt, hätte damit still die anderen elf
        // verloren: `vk.generator` hätte plötzlich nur noch `weltkueche` gehabt, ohne dass
        // irgendwo etwas anzeigt, dass `domain`, `kueche` und `cross_cutting` weg sind.
        //
        // Das bricht auch das Muster, das überall sonst gilt: der Kanon überschreibt die
        // Bindungen **pro Prompt-Key**, eine Achsen-Zeile die Config **pro Achsenwert** — immer
        // pro Element, nie pro Gruppe. „Bewusst leer" wird ausdrücklich mit `mode = none`
        // gesagt, nicht durch das Fehlen einer Zeile.
        $selector = static fn ($r) => ! empty($r->art) ? 'art:'.$r->art : 'category:'.$r->category;
        $eigeneKategorien = $eigene->map($selector)->all();

        return DB::table('foodalchemist_knowledge_routings')->where('feature', $alt)->get()
            ->reject(fn ($r) => in_array($selector($r), $eigeneKategorien, true))
            ->concat($eigene)
            ->values();
    }

    /**
     * Spec 52/A2 — Routing-Namen, die ein Aufrufer selbst führt, OHNE dass es dazu eine
     * Prompt-Registry-Zeile gibt. Dritte Variante des Schlüssel-Bruchs, nach dem Alias
     * (`recipe.generator` → `ai_generate_recipe`) und dem Feld-Mismatch (`recipe.dichteklasse`).
     *
     * Ohne diese Liste hielte der Versorgungs-Bericht solche Namen für toten Ballast — sie sind
     * aber live. Umgekehrt gilt: **welche Routing-Features WIRKLICH keinen Aufrufer haben, lässt
     * sich statisch nicht abschliessend feststellen**, weil mehrere Stellen mit einer Variablen
     * rufen (`contextFor($team, $promptKey, …)` in `ConceptGeneratorService`,
     * `contextFor($this->team(), $prompt, …)` in `StepEditor`). Der Bericht sagt deshalb
     * „ohne Prompt-Key", nicht „ohne Aufrufer" — und nennt die vier, für die ein `grep` über
     * `src/` am 2026-09-07 wirklich leer war.
     *
     * @var array<string, string>  Feature → Fundstelle
     */
    public const CONTEXT_ONLY_FEATURES = [
        'foodbook.plan' => 'IdeenService — contextFor(…, \'foodbook.plan\'), kein Registry-Key',
    ];

    /**
     * Routing-Features, für die ein `grep` über `src/` am 2026-09-07 KEINEN `contextFor()`-Aufrufer
     * fand — also konfigurierte Politik, die nichts steuert. 11 der 73 Zeilen.
     *
     * Besonders bitter bei `ai_extract_recipe × cross_cutting = none`: das ist eine BEWUSSTE
     * Entscheidung („dieser Schritt braucht kein Wissen"), hinterlegt unter einem Namen, den
     * niemand ruft. Der eigentliche Key heisst `recipe.extract` und hat keine Zeile.
     *
     * Als Datum geführt, nicht als Wahrheit: fügt jemand einen Aufrufer hinzu, gehört der Name
     * hier heraus.
     *
     * @var list<string>
     */
    public const OHNE_AUFRUFER_GEMESSEN = [
        'ai_extract_recipe', 'ai_infer_ankers', 'ai_plan_dishes', 'ai_suggest_pairings',
    ];

    /**
     * Spec 52/B3 — die Optionen für `propose()` aus einem `contextFor()`-Ergebnis bauen.
     *
     * Warum ein Helfer und nicht 18 Aufrufstellen von Hand: `knowledge_dropped_chars` gaben
     * genau ZWEI von achtzehn Aufrufern weiter, und darum stand in sechzehn Features
     * `prompt_parts.dropped = 0`, obwohl Zeichen fehlten (Befund I3). Das ist keine
     * Nachlässigkeit einzelner Stellen, sondern eine Konvention ohne Durchsetzung — dieselbe
     * Klasse wie `_kanon_prompt_key` (2 von 14). Ein Helfer, der ALLE Messfelder mitnimmt,
     * macht die nächste neue Aufrufstelle automatisch richtig; ein Wächter-Test hält es.
     *
     * Extras (`target_table`, `tier`, …) hängt der Aufrufer per `+` an.
     *
     * @param  array<string, mixed>  $wissen  Ergebnis von {@see contextFor()}
     * @return array<string, mixed>
     */
    public static function proposeOptionen(array $wissen): array
    {
        $block = (string) ($wissen['block'] ?? '');

        // BEWUSST ohne `knowledge_channels`: dieses Feld ist der Dedup-/Anzeige-Eingang, an dem
        // schon einmal der Bound-Kanal gestorben ist (W0-3b — ein Anzeige-Spiegel schrieb auf
        // das Feld, das die Auswahl-Logik liest). Wer Kanäle liefert, liefert sie weiterhin
        // selbst und bewusst. Dieser Helfer schliesst nur die Messlücke.
        return [
            'knowledge' => $block !== '' ? $block : null,
            'knowledge_used' => $wissen['files_used'] ?? [],
            // Ohne diese Zeile ist die Kappung im Call-Log unsichtbar.
            'knowledge_dropped_chars' => (int) ($wissen['dropped_chars'] ?? 0),
        ];
    }

    /**
     * W0-5: Featureweites Zeichenbudget. Reihenfolge: Feature-Override aus der Config >
     * Rezept-Sonderdeckel (historisch, bleibt der strengste) > Default.
     *
     * Bewusst auf `$feature` gekeyt, nicht auf den Prompt-Key: `vk.generator` ist KEIN
     * Routing-Feature — Gerichte laufen über contextFor('ai_generate_recipe'). Ein
     * Prompt-Key-Scope braucht erst den `_prompt_key`-Durchstich (Welle 1).
     */
    private function knowledgeBudget(string $feature, bool $recipeBudget): int
    {
        return KnowledgeBudget::forKey($feature);
    }

    /**
     * Feature/Prompt-Key → Slug-Muster des zugehörigen Regelwerks. `format.grundgeruest` ist
     * bewusst eigen eingetragen, damit es NICHT auf den Basisrezept-Fallback rutscht — es gibt
     * (noch) kein Format-Regelwerk, und ein falsches wäre schlimmer als keines.
     *
     * ★ **Spec 52/Paket 2 — genau dieses Prinzip war für Gerichte verletzt.** `vk.generator`
     * hatte keinen Eintrag, und der Blind-Default (`?? ['ai_generate_recipe']`) liefert
     * `%basisrezept%`. Ohne Kanon bekam der Gericht-Pfad damit das **Basisrezepte**-Regelwerk,
     * obwohl `regelwerk.regelwerk_verkaufsgerichte--*` existiert — dieselbe Fehlerklasse wie
     * der schon dokumentierte Fall „vk bekam Basisrezepte statt Verkaufsgerichte", nur an
     * anderer Stelle. Auf demo fiel es nicht auf, weil der Kanon greift; auf einer frischen DB
     * (Migrationsstand: `regelwerk always`) hätte es zugeschlagen.
     *
     * ★ **Diese Karte trug zwei Dinge unter einem Namen — eines ist mit Spec 52 · F4 weg.**
     *
     *  1. *Injektion*: welches Dossier der Generator per `regelwerk:always` in den Prompt
     *     hebt. **Gelöscht.** Verbindliches Wissen kommt aus dem Kanon.
     *  2. *Nachschlagen*: welcher Regelwerks-BEREICH fachlich zu einem Feature gehört —
     *     für `regelwerk.GET` und das Vorgangs-Register. **Bleibt**, und das ist legitim:
     *     einem Agenten zu sagen „für Gerichte gilt das VK-Regelwerk" ist eine Auskunft,
     *     kein Prompt-Bau.
     *
     * Der Plan wollte die Karte ganz löschen. Das hielt der Messung nicht stand — sie hat
     * einen zweiten, gesunden Abnehmer. Weg ist stattdessen das `->first()`
     * ({@see regelwerkDossiersFuer()}): ein Muster wie `%basisrezept%` trifft rund zwanzig
     * §-Dossiers, und eines davon alphabetisch zu greifen war der eigentliche Defekt.
     */
    public const REGELWERK_SLUG_LIKE = [
        'concept.brief_geruest' => '%concept%',
        'foodbook.grundgeruest' => '%foodbook%',
        'format.grundgeruest' => '%format%',
        'ai_generate_recipe' => '%basisrezept%',
        // Paket 2: der eine Schlüsselraum — Prompt-Keys explizit, Basis und VK getrennt.
        'recipe.generator' => '%basisrezept%',
        'recipe.ueberarbeiten' => '%basisrezept%',
        'recipe.review' => '%basisrezept%',
        'recipe.eigenschaften' => '%basisrezept%',
        'vk.generator' => '%verkaufsgerichte%',
        'vk.ueberarbeiten' => '%verkaufsgerichte%',
        'vk.review' => '%verkaufsgerichte%',
        // Der GP-Vorgang fiel auf `%basisrezept%` zurueck, obwohl `regelwerk-gp-*` existiert.
        'gp.suggest' => '%regelwerk-gp%',
        'gp.conformance_revise' => '%regelwerk-gp%',
    ];

    /**
     * B3 — welche Cross-Cutting-Dossiers ein Feature WIRKLICH braucht.
     *
     * `ALWAYS_LOAD_CROSS_CUTTING` sind sieben PRODUKTIONS-Dossiers (Substitutionen,
     * Saisonkalender, Synonyme, Saucen-Mutterstrukturen, Mengen-Defaults, Techniken,
     * Brühen/Fonds) = 7 × 1.800 = 12.600 Zeichen. Für einen Generator ist das richtig.
     *
     * Für einen KUNDENTEXT ist es das nicht: `foodbook.kundentext` schreibt 2–4 Sätze
     * Fließtext (max_tokens 1.500) und bekam dafür Mengen-Defaults und Brühen-Rezepturen
     * mitgeliefert. Nützlich sind dort höchstens `saisonkalender` (Saison-Aussagen belegen)
     * und `synonyme` (Dinge richtig benennen) — der Rest ist Ballast, den das Modell
     * mitbezahlt und der die Aufmerksamkeit vom Auftrag wegzieht.
     *
     * Default bleibt die Konstante (Tests + Wissens-Browser hängen daran); Überschreibung
     * je Feature über config('foodalchemist.ai.cross_cutting_slugs') — seit Welle 2 mit
     * Split-Slugs (`saisonkalender--hauptsaison-nach-monaten-de`,
     * `synonyme--cross-sprachliche-synonyme-gleicher-lebensmittel`), die Originale sind inaktiv.
     * Public, weil der W0-Wächter genau diese Auflösung gegen den Korpus prüft.
     *
     * @return list<string>
     */
    public function crossCuttingSlugs(string $feature): array
    {
        $map = config('foodalchemist.ai.cross_cutting_slugs', []);
        if (is_array($map) && isset($map[$feature]) && is_array($map[$feature]) && $map[$feature] !== []) {
            return array_values(array_map('strval', $map[$feature]));
        }

        return self::ALWAYS_LOAD_CROSS_CUTTING;
    }
}
