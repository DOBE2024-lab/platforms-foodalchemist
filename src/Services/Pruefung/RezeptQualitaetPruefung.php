<?php

namespace Platform\FoodAlchemist\Services\Pruefung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FeedbackQuelle;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFeedback;

/**
 * Register der Rezept-Qualität (Tranche A, 98 · 3.3 aus DataQualityService verschoben): je Check Label,
 * Signal-Deskriptor und Prädikat an EINER Stelle, dazu die Helfer der Prädikate (Arbeitsmengen, Naming-Scan nach
 * Regelwerk_Basisrezepte §1, Dubletten, Verwaiste, Kunden-Exposition). DataQualityService zählt, listet und emittiert
 * daraus; GerichtNamensPruefung und SignalCauseService lesen die Naming-Befunde über die Fassade. Nur lesend.
 */
final class RezeptQualitaetPruefung
{
    /** Zubereitungs-Text unter dieser Länge gilt als nicht vorhanden (Spec 21 §2). */
    private const MIN_ZUBEREITUNG_ZEICHEN = 20;

    /** Kuechen-Feedback: ab wann ist es ein Befund und nicht eine schlechte Schicht. */
    private const FEEDBACK_SCHWELLE = 2.5;

    private const FEEDBACK_MIN_N = 2;

    /** Lob braucht eine hoehere Huerde als Tadel — sonst flutet es die Arbeitsliste. */
    private const FEEDBACK_STARK_SCHWELLE = 4.5;

    private const FEEDBACK_STARK_MIN_N = 3;

    /** So lange unberührt + unreferenziert ⇒ Pflege-Kandidat. */
    private const VERWAIST_TAGE = 180;

    /**
     * „Produktiv" = das Rezept beansprucht, benutzbar zu sein — nur diese Menge wird
     * gegen Rezeptur-Vollständigkeit geprüft. `draft`/`stub`/`deprecated` sind bewusst
     * ausgenommen: ein Auto-Stub (RecipeService setzt `status=stub`) HAT per Definition
     * keine Zubereitung und keine Zutaten — den fängt `rezept_sub_stub_offen`; ein
     * `deprecated`-Rezept ist ausgemustert. Spec 21 §2 schreibt „status != draft", was
     * vor `stub`/`deprecated` formuliert wurde; die engere Menge verhindert Über-Flaggen
     * (Nicht-Ziel: kein Rauschen).
     */
    private const PRODUKTIV_STATUS = ['review', 'approved'];

    /**
     * Alle Flächen, auf denen ein Rezept „benutzt" ist — eine Referenz genügt ⇒ nicht verwaist.
     * Sub-Rezept-Nutzung zählt mit: ein Basisrezept in einem anderen Rezept ist in Gebrauch,
     * auch wenn es in keinem Foodbook steht.
     */
    private const NUTZUNGS_REFERENZEN = [
        ['foodalchemist_recipe_ingredients', 'referenced_recipe_id'],
        ['foodalchemist_concept_slots', 'sales_recipe_id'],
        ['foodalchemist_foodbook_blocks', 'sales_recipe_id'],
        ['foodalchemist_menu_plan_entries', 'sales_recipe_id'],
    ];

    /**
     * Teilmenge von NUTZUNGS_REFERENZEN, die den Gast erreicht — nur hier ist eine
     * belastbare Allergen-Auskunft Pflicht. Sub-Rezept-Nutzung ist bewusst NICHT dabei:
     * sie macht ein Rezept „in Gebrauch", aber nicht kundenexponiert; die Exposition
     * erbt es über sein Eltern-Gericht (s. kundenExponiert()).
     */
    private const KUNDEN_REFERENZEN = [
        ['foodalchemist_concept_slots', 'sales_recipe_id'],
        ['foodalchemist_foodbook_blocks', 'sales_recipe_id'],
        ['foodalchemist_menu_plan_entries', 'sales_recipe_id'],
    ];

    /** Die 14 EU-Allergen-Spalten am Rezept (GL-01) — `unbekannt` ist der Default nach dem Insert. */
    private const ALLERGEN_SPALTEN = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    /*
     * Grammatur im Namen (`(65g)`, `(80 ml)`, `(5x6cm)`) und VK-Marker-Codes (`CC:`, `(BOX)` …) sind seit Spec 81
     * Regeln als Daten: `vk.1.2.grammatur` (Muster) und `vk.1.2.marker` (Wortliste), Einstellungen › Regeln.
     * Die §1.2a-Ausnahme (Grammatur als Diskriminator zweier Gleichnamiger) bleibt Logik hier.
     */
    private function grammaturMuster(): ?string
    {
        $m = \Platform\FoodAlchemist\Services\Regeln\RegelBuch::per('vk.1.2.grammatur')?->params['muster'][0] ?? null;

        return is_string($m) && $m !== '' ? $m : null;
    }

    /** Trennzeichen, die am Namensanfang/-ende immer ein Rest aus Import/Split sind. */
    private const NAME_TRENNER_RAND = '/(^[\s|,;:\-\x{2013}]|[\s|,;:\-\x{2013}]$)/u';

    /**
     * Tranche-A-Register: Label, Signal-Deskriptor und **das Prädikat** je Check —
     * EINMAL definiert. Zähl-Seite (`rezeptqualitaet`), Objekt-Liste (`betroffene`) und
     * Fixer-Lifecycle (`countFor`) ziehen alle aus derselben Closure; ein Regel-Wechsel
     * kann damit nicht auf halber Strecke driften.
     *
     * Reihenfolge = Lese-Reihenfolge im Cockpit: erst was das Rezept unbrauchbar macht
     * (kritisch), dann Struktur-/Pflege-Befunde.
     *
     * @return array<string,array{label:string,typ:SignalTyp,dedup:string,sev:SignalSeverity,desc:string,q:\Closure}>
     */
    public function rezeptQualitaetChecks(): array
    {
        return [
            'rezept_ohne_zubereitung' => [
                'label' => 'Rezepte ohne Zubereitung',
                'typ' => SignalTyp::RezeptOhneZubereitung,
                'dedup' => 'dq-rezept-ohne-zubereitung',
                'sev' => SignalSeverity::Kritisch,
                'desc' => 'Ohne Zubereitungstext kann die Küche das Rezept nicht nachkochen, und die Angaben zum '
                    . 'Regenerieren und zum Ablauf fehlen ebenfalls. Zubereitung im Rezept ergänzen.',
                // LENGTH: SQLite zählt Zeichen, MySQL Bytes — bei Schwelle 20 fachlich belanglos
                // (Umlaute machen MySQL nur minimal nachsichtiger), dafür ohne Dialekt-Fallunterscheidung.
                'q' => fn (Team $t) => $this->produktiveRezepte($t)
                    ->whereRaw("LENGTH(TRIM(COALESCE(preparation, ''))) < " . self::MIN_ZUBEREITUNG_ZEICHEN),
            ],
            'rezept_feedback_kritisch' => [
                'label' => 'Rezepte mit kritischem Küchen-Feedback',
                'typ' => SignalTyp::RezeptFeedbackKritisch,
                'dedup' => 'dq-rezept-feedback-kritisch',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Die Küche hat dieses Rezept mehrfach schlecht bewertet (Durchschnitt höchstens '
                    . str_replace('.', ',', (string) self::FEEDBACK_SCHWELLE) . ' bei mindestens ' . self::FEEDBACK_MIN_N . ' Bewertungen). '
                    . 'Das ist kein Datenfehler, aber oft ein Rezeptfehler. Die Bewertungen stehen im Rezept '
                    . 'unter „Feedback“, von dort führt „Weiterentwickeln“ direkt in eine neue Fassung.',
                // Einzelne Ausreisser erzeugen kein Signal: eine schlechte Schicht ist kein
                // schlechtes Rezept. Erst die WIEDERHOLUNG ist ein Befund.
                'q' => fn (Team $t) => $this->produktiveRezepte($t)->whereIn('id',
                    FoodAlchemistRecipeFeedback::visibleToTeam($t)
                        ->where('quelle', FeedbackQuelle::Kueche->value)
                        ->whereNotNull('score')
                        ->groupBy('recipe_id')
                        // ★ Schwelle als LITERAL, nicht als Binding: Laravel bindet einen Float als
                        // PDO::PARAM_STR, und SQLite sortiert jeden Zahlwert VOR jedem Text — dort
                        // waere `AVG(score) <= '2.5'` fuer JEDEN Durchschnitt wahr (gemessen: Ø 3,5
                        // schlug an). Auf MySQL liefe dieselbe Zeile still richtig. Beide Werte sind
                        // Klassenkonstanten, kein Nutzereingabe-Pfad.
                        ->havingRaw('COUNT(*) >= '.self::FEEDBACK_MIN_N.' AND AVG(score) <= '.self::FEEDBACK_SCHWELLE)
                        ->select('recipe_id')
                ),
            ],
            'rezept_feedback_stark' => [
                'label' => 'Küchen-Favoriten mit wiederholten Bestnoten',
                'typ' => SignalTyp::RezeptFeedbackStark,
                'dedup' => 'dq-rezept-feedback-stark',
                'sev' => SignalSeverity::Info,
                'desc' => 'Die Küche bewertet dieses Rezept wiederholt mit Bestnoten (Durchschnitt mindestens '
                    . str_replace('.', ',', (string) self::FEEDBACK_STARK_SCHWELLE) . ' bei mindestens ' . self::FEEDBACK_STARK_MIN_N . ' Bewertungen). '
                    . 'Kein Mangel, sondern eine Empfehlung: ins Standardrepertoire aufnehmen, auf die nächste Karte '
                    . 'setzen oder als Vorlage für Varianten nutzen.',
                'q' => fn (Team $t) => $this->produktiveRezepte($t)->whereIn('id',
                    FoodAlchemistRecipeFeedback::visibleToTeam($t)
                        ->where('quelle', FeedbackQuelle::Kueche->value)
                        ->whereNotNull('score')
                        ->groupBy('recipe_id')
                        // Schwellen als LITERAL, nicht als Binding — s. rezept_feedback_kritisch:
                        // SQLite sortiert jeden Zahlwert vor jedem Text, ein gebundener Float traf JEDES Rezept.
                        ->havingRaw('COUNT(*) >= '.self::FEEDBACK_STARK_MIN_N.' AND AVG(score) >= '.self::FEEDBACK_STARK_SCHWELLE)
                        ->select('recipe_id')
                ),
            ],
            'rezept_mengen_luecke' => [
                'label' => 'Rezepte mit Zutat ohne Menge',
                'typ' => SignalTyp::RezeptMengenLuecke,
                'dedup' => 'dq-rezept-mengen-luecke',
                'sev' => SignalSeverity::Kritisch,
                'desc' => 'Mindestens eine Zutat steht auf Menge 0. Sie fällt aus Einkaufspreis, Ausbeute und '
                    . 'Nährwerten heraus, der Wareneinsatz ist dadurch unbemerkt zu niedrig. Mengen im Rezept ergänzen.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('foodalchemist_recipe_ingredients as ri')
                    ->whereColumn('ri.recipe_id', 'foodalchemist_recipes.id')
                    ->whereNull('ri.deleted_at')->where('ri.quantity', 0)),
            ],
            'rezept_allergen_unbelastbar' => [
                'label' => 'Rezepte für Gäste mit ungesicherten Allergenen',
                'typ' => SignalTyp::RezeptAllergenUnbelastbar,
                'dedup' => 'dq-rezept-allergen-unbelastbar',
                'sev' => SignalSeverity::Kritisch,
                'desc' => 'Das Rezept geht über Konzept, Foodbook, Speiseplan oder Paket an den Gast, aber seine '
                    . 'Allergenangabe ist nicht gesichert: die Angaben sind ungeprüft oder mindestens ein Allergen '
                    . 'steht auf „unbekannt“. Kritisch, weil daraus eine Auskunft an den Gast wird. Zutaten prüfen '
                    . 'und Allergene vervollständigen. Rezepte, die nicht beim Gast landen, zählen hier nicht mit.',
                'q' => fn (Team $t) => $this->kundenExponiert($this->alleRezepte($t))->where(function ($w) {
                    $w->where('allergens_confidence', 'unknown')->orWhereNull('allergens_confidence');
                    foreach (self::ALLERGEN_SPALTEN as $spalte) {
                        $w->orWhere('allergen_' . $spalte, 'unbekannt');
                    }
                }),
            ],
            'rezept_zutaten_ungemappt' => [
                'label' => 'Rezepte mit Zutaten ohne Grundprodukt',
                'typ' => SignalTyp::RezeptZutatenUngemappt,
                'dedup' => 'dq-rezept-zutaten-ungemappt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Zutaten ohne Zuordnung zu einem Grundprodukt fehlen im Einkaufspreis, bei den Allergenen '
                    . 'und in den Nährwerten. Alle Summen am Rezept sind dadurch unvollständig. Zutaten im Rezept '
                    . 'einem Grundprodukt zuordnen.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->where('n_ingredients_unmapped', '>', 0),
            ],
            'rezept_ein_zutat' => [
                'label' => 'Rezepte mit höchstens einer Zutat',
                'typ' => SignalTyp::RezeptEinZutat,
                'dedup' => 'dq-rezept-ein-zutat',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Ein Rezept mit einer oder keiner Zutat ist meist keins: entweder ein Grundprodukt, das '
                    . 'als Rezept angelegt wurde, oder ein unvollständiger Import, bei dem die Zutatenliste fehlt.',
                'q' => fn (Team $t) => $this->produktiveRezepte($t)->where('n_ingredients_total', '<=', 1),
            ],
            'rezept_kategorie_problem' => [
                'label' => 'Rezepte ohne gültige Kategorie',
                'typ' => SignalTyp::RezeptKategorieProblem,
                'dedup' => 'dq-rezept-kategorie-problem',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Die Kategorie fehlt, oder das Gericht hängt an einer Hauptgruppe, die nicht mehr verwendet '
                    . 'wird. Ohne gültige Kategorie findet man das Rezept weder in der Rezeptliste noch bei der '
                    . 'Gerichtauswahl. Kategorie im Rezept setzen.',
                // Zwei getrennte Taxonomien, deshalb zwei Zweige (verifiziert an den Migrationen):
                //  · VK-Gericht  → `dish_main_group_id` direkt am Rezept (Modell A), Ziel `dish_main_groups`
                //    — DIESE Tabelle trägt `is_inactive` (269er-Neutralisierung).
                //  · Basisrezept → `category_id` → `recipe_categories.main_group_id` → `recipe_main_groups`
                //    — Produktions-Taxonomie, hat KEIN Stillgelegt-Flag; hier bleibt nur „Kategorie fehlt".
                'q' => fn (Team $t) => $this->alleRezepte($t)->where(fn ($w) => $w
                    ->where(fn ($vk) => $vk->where('is_sales_recipe', true)
                        ->where(fn ($x) => $x->whereNull('dish_main_group_id')
                            ->orWhereExists($this->inaktiveSpeisenHauptgruppe())))
                    ->orWhere(fn ($br) => $br->where('is_sales_recipe', false)->whereNull('category_id'))),
            ],
            'rezept_yield_implausibel' => [
                'label' => 'Rezepte mit fehlender oder unmöglicher Ausbeute',
                'typ' => SignalTyp::RezeptYieldImplausibel,
                'dedup' => 'dq-rezept-yield-implausibel',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Entweder fehlt die Ausbeute, obwohl Zutaten da sind (dann rechnet nichts pro kg oder '
                    . 'Portion), oder sie ist höher als die eingesetzte Rohware. Das ist unmöglich, weil Putz- und '
                    . 'Garverlust die Menge nur senken. Meist wurde die Ausbeute von Hand überschrieben oder stammt '
                    . 'aus einer älteren Zutatenliste. Rezept neu berechnen lassen.',
                // Bewusst NICHT gebaut: der Spec-Zweig „yield < 0,3 × Einsatzmasse = >70 % Verlust".
                // Er ist deterministisch nicht bewertbar, weil genau dieser Wert das REGULÄRE Ergebnis
                // des Recomputes ist, sobald Putz-/Garverlust deklariert sind (Reduktionen, Fonds,
                // Jus erreichen 70–90 %) — und die Verlust-Kaskade zieht Defaults von GP und Team-WG,
                // steht also nicht an der Zutat. Der Check würde jede Reduktion flaggen. Der belastbare
                // Zwilling wäre „Ausbeute weicht von der Neuberechnung ab" (Recompute-Drift) — anderer
                // Mechanismus, eigenes Signal (Backlog).
                'q' => fn (Team $t) => $this->produktiveRezepte($t)->where(fn ($w) => $w
                    ->where(fn ($fehlt) => $fehlt->whereNull('yield_kg')->whereNull('yield_kg_manual')
                        ->where('n_ingredients_total', '>', 0))
                    ->orWhere(fn ($zuHoch) => $zuHoch
                        ->where(fn ($da) => $da->whereNotNull('yield_kg')->orWhereNotNull('yield_kg_manual'))
                        // Ratio nur bewerten, wenn JEDE beitragende Zutat eine Massen-Einheit trägt —
                        // sonst zählt eine Stück-/Bund-Zutat mit 0 g in die Summe und die Ausbeute wirkt
                        // fälschlich zu hoch (die Falle dieses Checks).
                        ->whereExists($this->beitragendeZutat(false))
                        ->whereNotExists($this->beitragendeZutat(true))
                        ->whereRaw('COALESCE(foodalchemist_recipes.yield_kg_manual, foodalchemist_recipes.yield_kg) * 1000 > ('
                            . $this->einsatzmasseGrammSql() . ')'))),
            ],
            'rezept_naming_regelwerk' => [
                'label' => 'Rezepte mit Verstoß gegen die Benennungsregeln',
                'typ' => SignalTyp::RezeptNamingRegelwerk,
                'dedup' => 'dq-rezept-naming-regelwerk',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Der Name verstößt gegen die Benennungsregeln: doppelte Leerzeichen, Trennzeichen am Anfang '
                    . 'oder Ende, eine Grammatur ohne Grund. Bei Gerichten zusätzlich: das Kürzel der Hauptgruppe am '
                    . 'Anfang fehlt, oder Katalog-Kennzeichen wie „CC:“, „STF:“ oder „(BOX)“ stehen im Namen. Falsch '
                    . 'benannte Rezepte findet man schlecht und legt sie doppelt an.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn('id', $this->namingVerstossIds($t)),
            ],
            'rezept_dublette' => [
                'label' => 'Rezepte mit gleichem Namen',
                'typ' => SignalTyp::RezeptDublette,
                'dedup' => 'dq-rezept-dublette',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Zwei oder mehr Rezepte derselben Art und Kategorie heißen gleich (Groß- und '
                    . 'Kleinschreibung, Satzzeichen und Leerzeichen nicht mitgezählt). Alle werden gelistet. '
                    . 'Zusammenführen oder durch einen Zusatz im Namen unterscheiden. Gleiche Namen in '
                    . 'unterschiedlichen Kategorien sind erlaubt und zählen nicht.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn('id', $this->dubletteIds($t)),
            ],
            'rezept_sub_stub_offen' => [
                'label' => 'Leere Unterrezepte, die schon verwendet werden',
                'typ' => SignalTyp::RezeptSubStubOffen,
                'dedup' => 'dq-rezept-sub-stub-offen',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Ein anderes Rezept verwendet dieses als Unterrezept, aber es ist noch ein leerer Platzhalter. '
                    . 'Solange es leer ist, fehlen dem übergeordneten Rezept Menge, Einkaufspreis und Allergene. '
                    . 'Unterrezept ausarbeiten.',
                // Spec 21 §2 schreibt `status=draft`; `RecipeService::createSubRecipeStub()` setzt real
                // `status=stub` (ein per Hand angelegter Sub-Verweis kann als draft entstehen) → beide
                // Status zählen, sonst geht der Regelfall durch. 0 Zutaten ist die eigentliche Aussage.
                'q' => fn (Team $t) => $this->alleRezepte($t)
                    ->whereIn('status', ['stub', 'draft'])
                    ->where('n_ingredients_total', 0)
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('foodalchemist_recipe_ingredients as ri')
                        ->whereColumn('ri.referenced_recipe_id', 'foodalchemist_recipes.id')
                        ->whereNull('ri.deleted_at')),
            ],
            'rezept_verwaist' => [
                'label' => 'Rezepte, die lange nicht genutzt wurden',
                'typ' => SignalTyp::RezeptVerwaist,
                'dedup' => 'dq-rezept-verwaist',
                'sev' => SignalSeverity::Info,
                'desc' => 'Seit über ' . self::VERWAIST_TAGE . ' Tagen nicht bearbeitet und in keinem Gericht, Konzept, '
                    . 'Foodbook, Speiseplan oder Paket verwendet. Prüfen: aktualisieren, ausmustern oder bewusst '
                    . 'behalten. Nur ein Hinweis, Bestand ist kein Fehler.',
                'q' => fn (Team $t) => $this->unreferenziert(
                    $this->produktiveRezepte($t)->where('updated_at', '<', now()->subDays(self::VERWAIST_TAGE))
                ),
            ],
            // Tranche B (S5b) — die zwei Zeilen dieses Registers mit KI-Urteil im Rücken.
            // Sie prüfen trotzdem nichts selbst: der Egress lag im Batch
            // (`foodalchemist:recipe-findings`), hier wird nur der abgelegte Bestand
            // gelesen. Die Auswahl-Regel kommt ungeteilt aus `offeneUeberSchwelle()` —
            // eine eigene Schwelle hier hieße, dass ein Befund in der Rezept-Ansicht
            // erledigt aussieht und im Cockpit noch zählt.
            'rezept_plausi_ki' => [
                'label' => 'Rezepte mit offenem KI-Hinweis',
                'typ' => SignalTyp::RezeptPlausiKi,
                'dedup' => 'dq-rezept-plausi-ki',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Die KI-Prüfung hat am Rezept mindestens einen offenen Hinweis hinterlassen, bei dem sie '
                    . 'sich sicher ist (falsche Menge, unpassende Zutat, fehlende Kernzutat). Typischer Fall: '
                    . 'Pfefferkörner wurden als Pfefferrahmsauce erkannt. Im Rezept je Hinweis übernehmen oder '
                    . 'verwerfen. Ein verworfener Hinweis kommt nicht wieder.',
                // `whereIn` mit Sub-Builder statt eigenem EXISTS: die Befund-Zeilen sind
                // bewusst NICHT team-hierarchisch (Messreihen-Ausnahme aus S5a), ihr Scope
                // steckt im Service. Nachgebaut wäre er hier eine zweite Wahrheit.
                // Die Arten-Grenze ist ab S5b-2 Pflicht: sonst zählte diese Zeile auch die
                // Bauart-Befunde mit und dasselbe Rezept stünde in zwei Signalen für einen
                // Sachverhalt, den nur eines von beiden beschreibt.
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn(
                    'foodalchemist_recipes.id',
                    app(RecipeFindingService::class)
                        ->offeneUeberSchwelle($t, null, \Platform\FoodAlchemist\Services\Rezept\RecipeReviewService::ARTEN_COPILOT)->select('recipe_id')
                ),
            ],
            'rezept_gericht_vs_komponente' => [
                'label' => 'Rezepte, bei denen Gericht oder Komponente unklar ist',
                'typ' => SignalTyp::RezeptGerichtVsKomponente,
                'dedup' => 'dq-rezept-gericht-vs-komponente',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Die KI-Prüfung widerspricht der Einordnung: was hier als Gericht geführt wird, ist nach '
                    . 'seinem Aufbau eine Komponente, oder umgekehrt. Maßstab ist, wie es gebaut ist, nicht wo es '
                    . 'eingesetzt wird. Eine falsche Einordnung wirkt still, aber breit: eine als Gericht geführte '
                    . 'Sauce erscheint in der Gerichtauswahl, ein als Komponente geführtes Gericht bekommt weder '
                    . 'Verkaufsangaben noch Servierformen. Im Rezept selbst umstellen.',
                'q' => fn (Team $t) => $this->alleRezepte($t)->whereIn(
                    'foodalchemist_recipes.id',
                    app(RecipeFindingService::class)
                        ->offeneUeberSchwelle($t, null, \Platform\FoodAlchemist\Services\Rezept\RecipeReviewService::ARTEN_STRUKTUR)->select('recipe_id')
                ),
            ],
        ];
    }

    /** Basis-Query über BEIDE Rezept-Arten — Tranche-A-Regeln gelten für Basisrezept wie VK-Gericht. */
    private function alleRezepte(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team);
    }

    /** Rezepte, die benutzbar sein wollen (s. PRODUKTIV_STATUS) — Stubs/Entwürfe/Ausgemusterte raus. */
    private function produktiveRezepte(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return $this->alleRezepte($team)->whereIn('status', self::PRODUKTIV_STATUS);
    }

    /** EXISTS: die Speisen-Hauptgruppe des VK-Gerichts ist stillgelegt (269er-Neutralisierung). */
    private function inaktiveSpeisenHauptgruppe(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_dish_main_groups as hg')
            ->whereColumn('hg.id', 'foodalchemist_recipes.dish_main_group_id')
            ->where('hg.is_inactive', true)->whereNull('hg.deleted_at');
    }

    /**
     * EXISTS-Closure auf die *beitragenden* Zutaten eines Rezepts — dieselbe Auswahl, die
     * RecipeRecomputeService::yieldUndZaehler in den Yield rechnet (optional und Einheit „qs"
     * tragen dort 0 bei, also auch hier nicht).
     *
     * @param bool $ohneMasse true ⇒ nur die beitragenden Zutaten, die NICHT in Gramm
     *                        umrechenbar sind (Zähl-/Volumen-Einheit ohne Gramm-Default).
     */
    private function beitragendeZutat(bool $ohneMasse): \Closure
    {
        return function ($q) use ($ohneMasse) {
            $q->select(DB::raw(1))
                ->from('foodalchemist_recipe_ingredients as ri')
                ->join('foodalchemist_vocab_units as u', 'u.id', '=', 'ri.unit_vocab_id')
                ->whereColumn('ri.recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('ri.deleted_at')
                ->where('ri.is_optional', false)
                ->where('u.slug', '!=', 'qs');

            if ($ohneMasse) {
                $q->where(fn ($w) => $w->where('u.dimension', '!=', 'mass')
                    ->orWhereNull('u.dimension')
                    ->orWhereNull('u.default_in_g')
                    ->orWhere('u.default_in_g', '<=', 0));
            }

            return $q;
        };
    }

    /**
     * Korrelierte Summe der Roh-Einsatzmasse in Gramm (vor Verlust) — spiegelt
     * `mengeAvg × default_in_g` aus RecipeRecomputeService (Mengen-Bereich = Mittelwert,
     * §6.4). Nur aufgerufen, wenn alle beitragenden Zutaten Massen-Einheiten tragen.
     */
    private function einsatzmasseGrammSql(): string
    {
        return 'SELECT SUM(((ri.quantity + COALESCE(ri.quantity_max, ri.quantity)) / 2) * u.default_in_g)'
            . ' FROM foodalchemist_recipe_ingredients ri'
            . ' JOIN foodalchemist_vocab_units u ON u.id = ri.unit_vocab_id'
            . ' WHERE ri.recipe_id = foodalchemist_recipes.id AND ri.deleted_at IS NULL'
            . " AND ri.is_optional = 0 AND u.slug != 'qs'";
    }

    /**
     * Schränkt auf Rezepte ein, die den Gast erreichen: entweder direkt auf einer
     * Kunden-Fläche (Konzept/Foodbook/Speiseplan/Paket) oder als Sub-Rezept eines Rezepts,
     * das dort hängt. Bewusst EINE Ebene tief — tiefer wäre rekursiv (max. 3 Ebenen laut
     * Regelwerk §4) und für die Trefferliste unverhältnismäßig; die Eltern-Ebene ist der Fall,
     * der zählt (Sauce im verkauften Gericht).
     */
    private function kundenExponiert(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where(function ($w) {
            foreach (self::KUNDEN_REFERENZEN as [$tabelle, $spalte]) {
                $w->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from($tabelle)
                    ->whereColumn($tabelle . '.' . $spalte, 'foodalchemist_recipes.id')
                    ->whereNull($tabelle . '.deleted_at'));
            }
            $w->orWhereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('foodalchemist_recipe_ingredients as ri')
                ->whereColumn('ri.referenced_recipe_id', 'foodalchemist_recipes.id')
                ->whereNull('ri.deleted_at')
                ->where(function ($p) {
                    foreach (self::KUNDEN_REFERENZEN as [$tabelle, $spalte]) {
                        $p->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from($tabelle)
                            ->whereColumn($tabelle . '.' . $spalte, 'ri.recipe_id')
                            ->whereNull($tabelle . '.deleted_at'));
                    }
                }));
        });
    }

    /** @return list<array{id:int,name:string,vk:bool,achse:string}> */
    private function nameScan(Team $team): array
    {
        return $this->alleRezepte($team)
            ->get(['id', 'name', 'is_sales_recipe', 'category_id', 'dish_main_group_id'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'vk' => (bool) $r->is_sales_recipe,
                // Gruppen-Achse = Rezept-Art + ihre jeweilige Taxonomie. Getrennte Bäume:
                // VK-Gericht hängt an der Speisen-Hauptgruppe, Basisrezept an der Produktions-
                // Kategorie. Gleicher Name in anderer Kategorie ist ein zulässiger Diskriminator.
                'achse' => $r->is_sales_recipe
                    ? 'vk:' . ($r->dish_main_group_id ?? '-')
                    : 'br:' . ($r->category_id ?? '-'),
            ])->all();
    }

    /** Vergleichsform eines Namens: klein, ohne Satzzeichen/Trenner, Leerraum normalisiert. */
    private function normalisierterName(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/[.,;:|\/\-\x{2013}()\[\]"\x{201C}\x{201E}\']+/u', ' ', $n) ?? $n;

        return trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
    }

    /**
     * Rezepte mit deterministisch belegbarem Naming-Verstoß.
     *
     * Grammatur im Namen ist NICHT pauschal ein Verstoß: Regelwerk_Verkaufsgerichte §1.2a
     * (und Basisrezepte §1.8) erlauben sie ausdrücklich, wenn sie zwei sonst gleichnamige
     * Gerichte unterscheidet („(17g)" vs. „(65g)"). Deshalb zählt sie nur, wenn es diesen
     * Zwilling gar nicht gibt — dann ist sie reine Steckbrief-Angabe und gehört ins Datenfeld.
     *
     * @return list<int>
     */
    private function namingVerstossIds(Team $team): array
    {
        $rows = $this->nameScan($team);

        $basisZaehler = [];
        foreach ($rows as $r) {
            $basis = $this->grammaturBasis($r);
            $basisZaehler[$basis] = ($basisZaehler[$basis] ?? 0) + 1;
        }

        $ids = [];
        foreach ($rows as $r) {
            if ($this->namingBefunde($r, $basisZaehler) !== []) {
                $ids[] = $r['id'];
            }
        }

        return $ids;
    }

    /**
     * Spec 21 · S3b-3 (Ursachen-Kette): WELCHE Regel ein einzelnes Rezept verletzt.
     *
     * Der Check selbst (`namingVerstossIds`) beantwortet nur „ja/nein" — für den
     * Deep-Link auf das verletzte § braucht das Panel den konkreten Fall. Die Regel-
     * Prüfung bleibt hier (eine Stelle, dasselbe `nameScan`); die Zuordnung
     * Fall → §/Dokument liegt bewusst NICHT hier, sondern im `SignalCauseService`:
     * dieser Service misst, er formuliert nicht.
     *
     * Der Grammatur-Fall braucht den Zwillings-Zähler über den ganzen Bestand
     * (§1.2a — sie ist nur ohne Diskriminator-Funktion ein Verstoß), darum wird der
     * volle Scan gefahren und danach auf das eine Rezept gefiltert.
     *
     * @return list<string> Fall-Schlüssel aus {@see self::NAMING_FAELLE}, leer = regelkonform
     */
    public function namingBefundeFuer(Team $team, int $recipeId): array
    {
        $rows = $this->nameScan($team);

        $basisZaehler = [];
        foreach ($rows as $r) {
            $basis = $this->grammaturBasis($r);
            $basisZaehler[$basis] = ($basisZaehler[$basis] ?? 0) + 1;
        }

        foreach ($rows as $r) {
            if ($r['id'] === $recipeId) {
                return $this->namingBefunde($r, $basisZaehler);
            }
        }

        return [];
    }

    /** Alle Fall-Schlüssel, die {@see namingBefundeFuer} liefern kann (Vertrag für den Aufrufer). */
    public const NAMING_FAELLE = ['leerraum', 'trenner_rand', 'grammatur', 'vk_marker', 'vk_praefix'];

    /** @param array{id:int,name:string,vk:bool,achse:string} $r */
    private function grammaturBasis(array $r): string
    {
        return $r['achse'] . '|' . $this->normalisierterName(
            ($m = $this->grammaturMuster()) !== null ? (preg_replace($m, ' ', $r['name']) ?? $r['name']) : $r['name']
        );
    }

    /**
     * Alle verletzten Regel-Fälle eines Namens. Bewusst eine LISTE statt eines bool:
     * der Zähl-Pfad braucht nur „nicht leer", die Ursachen-Kette (S3b-3) den Fall.
     * Es wird nicht beim ersten Treffer abgebrochen — ein Name kann mehrere Regeln
     * reißen, und wer ihn korrigiert, soll alle auf einmal sehen.
     *
     * @param array{id:int,name:string,vk:bool,achse:string} $r
     * @param array<string,int> $basisZaehler
     * @return list<string>
     */
    private function namingBefunde(array $r, array $basisZaehler): array
    {
        $name = $r['name'];
        $faelle = [];

        if (preg_match('/\s{2,}/u', $name) === 1) {
            $faelle[] = 'leerraum';
        }
        if (preg_match(self::NAME_TRENNER_RAND, $name) === 1) {
            $faelle[] = 'trenner_rand';
        }
        if (($gm = $this->grammaturMuster()) !== null && preg_match($gm, $name) === 1
            && ($basisZaehler[$this->grammaturBasis($r)] ?? 0) < 2) {
            $faelle[] = 'grammatur';
        }

        if (! $r['vk']) {
            return $faelle;                                 // §1-Präfix/Marker sind VK-Regeln
        }

        foreach ((array) (\Platform\FoodAlchemist\Services\Regeln\RegelBuch::per('vk.1.2.marker')?->params['tokens'] ?? []) as $marker) {
            if (mb_stripos($name, (string) $marker) !== false) {
                $faelle[] = 'vk_marker';
                break;
            }
        }

        // Pipe-Skelett: führendes Hauptgruppen-Kürzel in eckigen Klammern (§1.1).
        if (preg_match('/^\[[A-Z]{2,5}\]\s/u', $name) !== 1) {
            $faelle[] = 'vk_praefix';
        }

        return $faelle;
    }

    /**
     * Rezepte, deren normalisierter Name innerhalb derselben Art+Kategorie mehrfach vorkommt.
     * Es werden ALLE Mitglieder der Gruppe geflaggt — man muss beide Seiten ansehen, um zu
     * entscheiden, welche bleibt.
     *
     * @return list<int>
     */
    private function dubletteIds(Team $team): array
    {
        $gruppen = [];
        foreach ($this->nameScan($team) as $r) {
            $gruppen[$r['achse'] . '|' . $this->normalisierterName($r['name'])][] = $r['id'];
        }

        $ids = [];
        foreach ($gruppen as $gruppe) {
            if (count($gruppe) > 1) {
                array_push($ids, ...$gruppe);
            }
        }

        return $ids;
    }

    /** Schränkt auf Rezepte ein, die auf KEINER Nutzungs-Fläche referenziert werden. */
    private function unreferenziert(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        foreach (self::NUTZUNGS_REFERENZEN as [$tabelle, $spalte]) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from($tabelle)
                ->whereColumn($tabelle . '.' . $spalte, 'foodalchemist_recipes.id')
                ->whereNull($tabelle . '.deleted_at'));
        }

        return $q;
    }
}
