<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * CQ 3.4 · Abhängigkeitsgraph der Services (src/Services) und Schichtregel Rezept → Concepter → Foodbook.
 *
 * Kante A → B, wenn A die Service-Klasse B nutzt: `B::class`, `B::methode(`, Typ-Hinweis `B $x`, `new B`. Kommentare zählen nicht.
 * Schichten (unten → oben); erlaubt sind Kanten nach unten und innerhalb einer Schicht:
 *   0 Basis        Kaskaden-Status (KaskadenSchritt); seit 3.3 S7 P6 (K2) auch GP-Stamm, Lieferanten-Artikel (LA), Leitartikel,
 *                  Lieferanten, Preise, Lager/Inventur, Wissen/KI, Vokabular, Matching (IngredientMatch, Match*) und die
 *                  Schnittstelle NeuberechnungRezepte. Pairing/Kombinationslogik bleibt neutral (Rest-Kreis Wissen/Pairing).
 *   1 Rezept       Rezept, Darreichung, Recompute, Kosten, Nährwert/Allergen, Produktionszeit
 *   2 Concepter    Concept, Paket, Format
 *   3 Ausgabe      Foodbook, Angebot/Offer, Speisekarte, Speiseplan, Präsentation, Report, Planungsblatt, Bestellung
 *                  (dazu Wareneingang und Lieferanten-Rechnung: Einkauf an der Bestellung)
 *   4 Orchestrierung  Planung (Kaskade), PricingCascadeService — darf in alle Schichten nach unten
 * Nicht zugeordnete Services bleiben neutral. 3.3 S7: Rückkanten 2 → 0 (P5); mit K2 (P6, Kurator 23.11. Variante B) kommen 6
 * Rückkanten als Bestand der neuen Regel in die Baseline. Abbau-Folgepunkte je Kante:
 *   - GpAggregateService → NaehrwertAggregatService       Nährwert-Aggregat des GP; Lesemodell nach Basis oder Event
 *   - GpMasseService → DarreichungService                  Masse-Umrechnung liest die Darreichung; Lese-Schnittstelle (K3)
 *   - GpPaketImportService → KostenCacheService            Kosten-Cache „veraltet“ nach Import; Event wie TeamKostenGeaendert
 *   - LeadLaService → KostenCacheService                   dto. nach Leitartikel-Wechsel
 *   - PriceService → KostenCacheService                    dto. nach Preisänderung
 *   - InventurService → RecipeRecomputeService             Lesewerte preisProGramm/Stück, letzterPreisGenutzt; Lese-Schnittstelle
 * Dazu die neutralen Lese-Wege aus P4 (SignalDetektor, BehaelterBedarf, ProduktionsVerbrauch, ZutatenZielSuche, Sensorik,
 * HardstopResolve → RecipeRecomputeService), die erst mit ihrer Zuordnung zählen.
 */
final class AbhaengigkeitsGraph
{
    public const SCHICHTEN = [0 => 'Basis', 1 => 'Rezept', 2 => 'Concepter', 3 => 'Ausgabe', 4 => 'Orchestrierung'];

    /** Benannte Zuordnungen vor den Präfix-Regeln (Name trügt sonst). */
    private const EXPLIZIT = [
        'KaskadenSchritt' => 0,
        'PricingCascadeService' => 4,
        'PresentationService' => 3,          // Dokument-Präsentation von Angebot/Foodbook, nicht die Darreichung
        'PlanungsblattService' => 3,
        'ConceptPreisService' => 2,
        'PlanningFrameService' => 2,         // Planungsrahmen (Slots wie Concept/Format), keine Kaskaden-Orchestrierung
        // 3.3 S7 P6 (K2, Variante B)
        'WareneingangService' => 3,          // Einkauf an der Bestellung (OrderService), kein Stamm
        'LieferantenRechnungService' => 3,   // dto.
        'RecipeKnowledgeRun' => 0,           // Wert-Objekt in Services/Knowledge (Kanonstand eines Laufs), Name trügt
        'IngredientMatchService' => 0,       // Zutat-String → GP/Sub-Rezept; ruft keine Rezept-Dienste, wird von ihnen gerufen
        'GerichtVerwendungen' => 3,          // Gericht-Verwaltung: liest Verwendungen in Konzept, Plan, Karte, Angebot, Foodbook (Präfix „Gericht“ wäre Rezept)
        'GerichtTauschService' => 4,         // Gericht-Verwaltung: orchestriert den Tausch über alle Ausgaben + Preis-Kaskade
        'PositionProfil' => 2,               // 98 · 3.6 Positions-Kern: auch der Concepter (2) nutzt ihn, Foodbook/Angebot (3) darüber
        'PositionValidierung' => 2,
        'PositionFehler' => 2,
        'PositionGericht' => 1,              // 98 · 3.6 PR4: Gericht-Zeile je Portion, nur Rezept-/Preis-Dienste; Slot (2) und Blöcke (3) darüber
    ];

    /** Präfix → Schicht, erste passende Regel gewinnt. */
    private const PRAEFIXE = [
        '/^(Planning|Planung|Kaskade)/' => 4,   // 3.3 S1: Teile der Kaskaden-Fassade (Services/Kaskade); KaskadenSchritt steht explizit auf 0
        '/^(Foodbook|Offer|Angebot|Speisekarte|Speiseplan|Report|Order|Bestell)/' => 3,
        '/^(Concept|Paket|Format)/' => 2,
        '/^(Recipe|Rezept|Darreichung|Ingredient|Naehrwert|Allergen|Kosten|ProductionTime|Gericht|SubRecipe)/' => 1,
        // 3.3 S7 P6 (K2): Basis — GP-Stamm, LA, Leitartikel, Lieferanten, Preise, Lager/Inventur, Wissen/KI, Vokabular, Matching
        '/^(Gp|La[A-Z]|LeadLa|Supplier|Lieferant|Price|LetzterPreis|Aktionspreis|Lager|Inventur|Inventory|Wareneingang|Knowledge|AiGateway|KiAuftrag|Vocabulary|Match|NeuberechnungRezepte)/' => 0,
    ];

    /** @return array<string, string> Klasse => Pfad relativ zu src (nur Services) */
    public static function klassen(string $src): array
    {
        $klassen = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src . '/Services', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $datei) {
            if ($datei->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', (string) file_get_contents($datei->getPathname()), $m)) {
                $klassen[$m[1]] = substr($datei->getPathname(), strlen($src) + 1);
            }
        }
        ksort($klassen);

        return $klassen;
    }

    /** Ohne Kommentare (Block, Zeile, Zeilenende), Strings bleiben. */
    public static function ohneKommentare(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $bekannt  Klassennamen, die als Ziel zählen
     * @return list<string> genutzte Klassen (ohne die eigene)
     */
    public static function nutzt(string $code, array $bekannt, ?string $selbst = null): array
    {
        preg_match_all('/(?<![\w$])(\w+)(?=::class\b|::\w+\s*\(|\s+\$\w+|\s*\.\.\.\$)|\bnew\s+\\\\?(?:\w+\\\\)*(\w+)/', self::ohneKommentare($code), $m, PREG_SET_ORDER);
        $ziele = [];
        foreach ($m as $treffer) {
            $name = ($treffer[2] ?? '') !== '' ? $treffer[2] : $treffer[1];
            if (isset($bekannt[$name]) && $name !== $selbst) {
                $ziele[$name] = true;
            }
        }
        $ziele = array_keys($ziele);
        sort($ziele);

        return $ziele;
    }

    /** @return array<string, list<string>> Klasse => genutzte Klassen */
    public static function kanten(string $src): array
    {
        $klassen = self::klassen($src);
        $bekannt = array_fill_keys(array_keys($klassen), true);
        $kanten = [];
        foreach ($klassen as $klasse => $pfad) {
            $kanten[$klasse] = self::nutzt((string) file_get_contents($src . '/' . $pfad), $bekannt, $klasse);
        }

        return $kanten;
    }

    public static function schicht(string $klasse): ?int
    {
        if (array_key_exists($klasse, self::EXPLIZIT)) {
            return self::EXPLIZIT[$klasse];
        }
        foreach (self::PRAEFIXE as $muster => $schicht) {
            if (preg_match($muster, $klasse)) {
                return $schicht;
            }
        }

        return null;
    }

    /**
     * Kanten gegen die Richtung: untere zugeordnete Schicht nutzt obere.
     *
     * @return array<string, list<string>> Klasse => obere Klassen
     */
    public static function rueckkanten(array $kanten): array
    {
        $rueck = [];
        foreach ($kanten as $a => $ziele) {
            $sa = self::schicht($a);
            foreach ($ziele as $b) {
                $sb = self::schicht($b);
                if ($sa !== null && $sb !== null && $sb > $sa) {
                    $rueck[$a][] = $b;
                }
            }
        }
        ksort($rueck);

        return $rueck;
    }

    /**
     * Stark zusammenhängende Komponenten mit mehr als einer Klasse (Tarjan, iterativ).
     *
     * @return list<list<string>>
     */
    public static function kreise(array $kanten): array
    {
        $index = [];
        $low = [];
        $aufStapel = [];
        $stapel = [];
        $kreise = [];
        $n = 0;
        foreach (array_keys($kanten) as $start) {
            if (isset($index[$start])) {
                continue;
            }
            $arbeit = [[$start, 0]];
            while ($arbeit !== []) {
                [$v, $i] = array_pop($arbeit);
                if ($i === 0) {
                    $index[$v] = $low[$v] = $n++;
                    $stapel[] = $v;
                    $aufStapel[$v] = true;
                }
                $ziele = $kanten[$v] ?? [];
                $weiter = false;
                for (; $i < count($ziele); $i++) {
                    $w = $ziele[$i];
                    if (! isset($kanten[$w])) {
                        continue;
                    }
                    if (! isset($index[$w])) {
                        $arbeit[] = [$v, $i + 1];
                        $arbeit[] = [$w, 0];
                        $weiter = true;
                        break;
                    }
                    if (isset($aufStapel[$w])) {
                        $low[$v] = min($low[$v], $index[$w]);
                    }
                }
                if ($weiter) {
                    continue;
                }
                if ($low[$v] === $index[$v]) {
                    $komponente = [];
                    do {
                        $w = array_pop($stapel);
                        unset($aufStapel[$w]);
                        $komponente[] = $w;
                    } while ($w !== $v);
                    if (count($komponente) > 1) {
                        sort($komponente);
                        $kreise[] = $komponente;
                    }
                }
                if ($arbeit !== []) {
                    $eltern = $arbeit[count($arbeit) - 1][0];
                    $low[$eltern] = min($low[$eltern], $low[$v]);
                }
            }
        }
        usort($kreise, fn ($a, $b) => count($b) <=> count($a));

        return $kreise;
    }

    /** Baseline-Form für {@see BaselineKlinke}: pfad => [regel => anzahl]. */
    public static function zaehlen(string $src): array
    {
        $kanten = self::kanten($src);
        $klassen = self::klassen($src);
        $ist = [];
        foreach (self::rueckkanten($kanten) as $a => $ziele) {
            foreach ($ziele as $b) {
                $ist[$klassen[$a]]['rueckkante ' . $b] = 1;
            }
        }
        $kreise = self::kreise($kanten);
        $ist['_graph']['groesste_kreis_komponente'] = $kreise === [] ? 0 : count($kreise[0]);
        $ist['_graph']['klassen_in_kreisen'] = array_sum(array_map('count', $kreise));
        ksort($ist);

        return $ist;
    }

    public static function baselinePfad(): string
    {
        return __DIR__ . '/../Fixtures/abhaengigkeit_richtung_baseline.json';
    }
}
