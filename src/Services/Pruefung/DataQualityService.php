<?php

namespace Platform\FoodAlchemist\Services\Pruefung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Datenqualitäts-Ampel für die Kaskade LA → GP → Basisrezept → VK-Gericht.
 *
 * Rein MESSEND (read-only, keine Daten-Mutation) + optionale Signal-Emission über
 * den SignalService (Dedup, kein Dauerfeuer) → die Befunde landen damit in der
 * bestehenden „Signale"-Inbox (ReviewQueue) und sind über MCP `signale.SEARCH`
 * sichtbar, statt in einer Wegwerf-Report-Datei. Idempotent + team-gescoped
 * (visibleToTeam) + schedulebar (foodalchemist:data-quality --signals).
 *
 * Ergänzt SignalDetektorService::datenqualitaetGpLa (GP ohne Lead) um die restlichen
 * Kaskaden-Dimensionen: Preis-Auflösung, Allergen-Metadaten, Anker-Erdung,
 * unbestimmte Servierform, unvollständige EK-Ketten.
 */
class DataQualityService
{
    /**
     * Die Register liegen seit 98 · 3.3 in eigenen Klassen: RezeptQualitaetPruefung (Tranche A), KonzeptPruefung
     * (Tranche C, braucht CoverageService und PairingService) und FoodbookPruefung (Tranche D). Diese Fassade zählt,
     * listet und emittiert daraus.
     *
     * `PriceService` kommt ab Spec 22 · H2b dazu (V-053: die Preis-Aktiv-Bedingung wird
     * geholt, nicht ein zweites Mal geschrieben). Ebenfalls zyklenfrei — `PriceService`
     * hat keine Konstruktor-Abhängigkeiten.
     */
    public function __construct(
        private SignalService $signals,
        private \Platform\FoodAlchemist\Services\Preis\PriceService $preise,
        private RezeptQualitaetPruefung $rezeptQualitaet,
        private KonzeptPruefung $konzepte,
        private FoodbookPruefung $foodbooks,
    ) {
    }

    /** 98 · 3.3: Register liegt in RezeptQualitaetPruefung. */
    public const NAMING_FAELLE = RezeptQualitaetPruefung::NAMING_FAELLE;

    /** 98 · 3.3 DataQuality-Schnitt: RezeptQualitaetPruefung::namingBefundeFuer() — Naming-Befunde eines Rezepts. */
    public function namingBefundeFuer(Team $team, int $recipeId): array
    {
        return $this->rezeptQualitaet->namingBefundeFuer($team, $recipeId);
    }

    /** Ab dieser Anzahl gilt eine Lücke als kritisch (rot) statt Warnung (gelb). */
    private const ROT_SCHWELLE = 100;

    /**
     * Metrik-Art. Nötig, weil `severity='info'` beide Arten treffen kann (ein als
     * Info deklarierter Lücken-Check wird nie rot) — die Zeitreihe (Spec 21 · E1)
     * braucht aber genau die Lücken und nicht die Bestands-Totale („GPs approved").
     */
    public const KIND_GAP = 'gap';

    public const KIND_INFO = 'info';

    /**
     * Anzeige-Namen der Kaskaden-Metriken, die nicht aus einem Register (Tranche A/C/D) kommen.
     * Eine Stelle für Messung und Anzeige: die Ebenen-Messung liest sie, und
     * {@see metrikLabel} gibt sie an Verlauf und Drift-Signal weiter, damit dort kein
     * roher Metrik-Schlüssel auf dem Bildschirm landet.
     */
    private const METRIK_LABELS = [
        'la_strukturiert' => 'Erfasste Lieferantenartikel',
        'la_gemappt' => 'davon einem Grundprodukt zugeordnet',
        'la_needs_review' => 'Lieferantenartikel zur Prüfung',
        'la_konformitaet' => 'Lieferantenartikel mit Verstoß gegen die Anlageregeln',
        'gp_approved' => 'Freigegebene Grundprodukte',
        'gp_tentative' => 'Grundprodukte im Entwurf (zur Prüfung)',
        'gp_kein_la' => 'Grundprodukte ohne Lieferantenartikel',
        ...\Platform\FoodAlchemist\Services\Einkauf\LaBeschaffungsLagen::LABELS, ...\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::LABELS,
        'gp_kein_preis' => 'Grundprodukte mit Artikeln, aber ohne Preis',
        'gp_kein_lead' => 'Grundprodukte ohne gewählten Hauptartikel',
        'gp_lead_ohne_preis' => 'Grundprodukte, deren Hauptartikel keinen gültigen Preis hat',
        'gp_allergen_konfidenz' => 'Grundprodukte ohne ausgewertete Allergene',
        'gp_anker_fehlt' => 'Genutzte Grundprodukte ohne Aromaprofil',
        'gp_tentative_genutzt' => 'Grundprodukte im Entwurf, die schon in Rezepten stecken',
        'gp_konformitaet' => 'Grundprodukte mit Verstoß gegen die Anlageregeln',
        'br_ek_null' => 'Basisrezepte ohne Einkaufspreis',
        'br_ek_teil' => 'Basisrezepte mit Zutaten ohne Preis',
        'br_anker_fehlt' => 'Basisrezepte ohne Aromaprofil',
        'vk_ek_null' => 'Gerichte ohne Einkaufspreis',
        'vk_ek_teil' => 'Gerichte mit Zutaten ohne Preis',
        'vk_anker_fehlt' => 'Gerichte ohne Aromaprofil',
        'vk_servierform_unbestimmt' => 'Gerichte ohne festgelegte Servierform',
        'ri_gemini_unverifiziert' => 'Zutatenzuordnungen der KI, noch nicht geprüft',
    ];

    /**
     * Anzeige-Name einer Metrik (Kaskade oder Register) oder null, wenn der Schlüssel
     * keiner Metrik dieses Service gehört. Liest nur die Definitionen, misst nichts.
     */
    public function metrikLabel(string $key): ?string
    {
        if (isset(self::METRIK_LABELS[$key])) {
            return self::METRIK_LABELS[$key];
        }
        foreach ([$this->rezeptQualitaet->rezeptQualitaetChecks(), $this->konzepte->konzeptChecks(), $this->foodbooks->foodbookChecks()] as $checks) {
            if (isset($checks[$key])) {
                return $checks[$key]['label'];
            }
        }

        return null;
    }

    /**
     * Voll-Messung aller Kaskaden-Ebenen.
     *
     * @return array<string,array{label:string,metriken:list<array<string,mixed>>}>
     */
    public function messeAlleEbenen(Team $team): array
    {
        return [
            'la' => ['label' => 'Lieferantenartikel', 'metriken' => $this->la($team)],
            'gp' => ['label' => 'Grundprodukte', 'metriken' => $this->gp($team)],
            'basisrezept' => ['label' => 'Basisrezepte', 'metriken' => $this->basisrezepte($team)],
            'gericht' => ['label' => 'VK-Gerichte', 'metriken' => $this->gerichte($team)],
            'rezeptqualitaet' => ['label' => 'Rezept-Qualität', 'metriken' => $this->rezeptqualitaet($team)],
            'konzept' => ['label' => 'Konzepte', 'metriken' => $this->konzept($team)],
            'foodbook' => ['label' => 'Foodbooks', 'metriken' => $this->foodbook($team)],
            'quer' => ['label' => 'Querschnitt', 'metriken' => $this->quer($team)],
        ];
    }

    /**
     * Emittiert für jede Lücken-Metrik (wert > 0) mit Signal-Deskriptor ein Signal.
     * Idempotent über dedup_key (SignalService dedupt offene Signale je Team+Typ+Key).
     *
     * Seit 22·H4a schließt derselbe Durchlauf auch die auf 0 gemessenen Lücken (V-011).
     * Die **Rückgabe bleibt bewusst die Emissions-Zahl** — an ihr hängen die Ausgabe von
     * `foodalchemist:data-quality` und die Summe in `SignalDetektorService::laufen()`, und
     * „5 Signale" darf dort nicht plötzlich „5 emittiert + 3 geschlossen" heißen. Wer beide
     * Zahlen braucht, ruft `emittiereUndSchliesse()`.
     *
     * @return int Anzahl erzeugter/aktualisierter Signale
     */
    public function emittiereSignale(Team $team): int
    {
        return $this->emittiereUndSchliesse($team)['emittiert'];
    }

    /**
     * Dieselbe Voll-Messung, aber mit beiden Richtungen des Lifecycles (Spec 22 · H4a).
     *
     * `wert > 0` emittiert wie bisher; **`wert === 0` schließt** ein offenes Signal desselben
     * Typs+`dedup_key` aus derselben Quelle (V-011). Beide Zweige teilen sich denselben
     * Durchlauf, weil `messeAlleEbenen()` die teure Operation ist — ein zweiter Pass nur zum
     * Schließen hätte die Messkosten verdoppelt und, schlimmer, zwei Messungen gegen einen
     * womöglich zwischenzeitlich geänderten Bestand gestellt.
     *
     * Geschlossen wird ausdrücklich **nur, was diese Ampel selbst emittiert hat**
     * (`source = 'data-quality'`, s. `SignalService::schliesseGemessen()`).
     *
     * @return array{emittiert:int,geschlossen:int}
     */
    public function emittiereUndSchliesse(Team $team): array
    {
        $n = 0;
        $geschlossen = 0;
        foreach ($this->messeAlleEbenen($team) as $ebene) {
            foreach ($ebene['metriken'] as $m) {
                if (($m['signal'] ?? null) === null) {
                    continue;
                }
                if ((int) $m['wert'] === 0) {
                    $geschlossen += $this->signals->schliesseGemessen(
                        $team,
                        $m['signal']['typ'],
                        $m['signal']['dedup'],
                        'data-quality',
                        'Lücke gemessen 0 — automatisch geschlossen',
                    );

                    continue;
                }
                $wert = (int) $m['wert'];
                $this->signals->erzeuge(
                    $team,
                    $m['signal']['typ'],
                    // Fester Schweregrad je Check schlägt die reine Mengen-Heuristik: „ohne Zubereitung"
                    // ist auch bei 3 Treffern kritisch, „verwaist" auch bei 300 nur Info (Spec 21 §2).
                    $m['signal']['sev'] ?? ($wert > self::ROT_SCHWELLE ? SignalSeverity::Kritisch : SignalSeverity::Warnung),
                    $m['label'] . ': ' . $wert,
                    [
                        'dedup_key' => $m['signal']['dedup'],
                        'description' => $m['signal']['desc'] ?? $m['label'],
                        'payload' => ['anzahl' => $wert, 'metrik' => $m['key'], 'ebene' => $ebene['label']],
                        'source' => 'data-quality',
                    ]
                );
                $n++;
            }
        }

        return ['emittiert' => $n, 'geschlossen' => $geschlossen];
    }

    // ---- Ebenen-Messungen -------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function la(Team $team): array
    {
        // Arbeitsmenge = strukturierte LAs (die 264k Roh-Katalog sind nicht das Ziel).
        $strukturiert = DB::table('foodalchemist_supplier_item_structures')->count();
        $gemappt = DB::table('foodalchemist_supplier_item_structures')->whereNotNull('gp_id')->count();
        $needsReview = DB::table('foodalchemist_supplier_item_structures')->where('needs_review', true)->count();

        // Schicht 3 · Slice 4c-2: LAs mit offenem Konformitäts-Hinweis (Critic gegen das LA-Regelwerk).
        $konfLa = DB::table('foodalchemist_conformance_findings')
            ->where('team_id', $team->id)->where('artifact_type', 'la')
            ->where('status', 'offen')->whereNull('deleted_at')
            ->distinct()->count('artifact_id');

        return [
            $this->info('la_strukturiert', self::METRIK_LABELS['la_strukturiert'], $strukturiert),
            $this->info('la_gemappt', self::METRIK_LABELS['la_gemappt'], $gemappt),
            $this->gap('la_needs_review', self::METRIK_LABELS['la_needs_review'], $needsReview),
            ...array_map(fn ($m, $sig) => $this->gap($m, self::METRIK_LABELS[$m], app(\Platform\FoodAlchemist\Services\Einkauf\LaBeschaffungsLagen::class)->abfrage($team, $m)->count(), ...$sig),
                array_keys(\Platform\FoodAlchemist\Services\Einkauf\LaBeschaffungsLagen::SIGNALE), \Platform\FoodAlchemist\Services\Einkauf\LaBeschaffungsLagen::SIGNALE),
            $this->gap('la_konformitaet', self::METRIK_LABELS['la_konformitaet'], $konfLa, SignalTyp::KonformitaetLa, 'dq-la-konformitaet',
                'Bei diesen Lieferantenartikeln verstoßen Angaben aus dem Lieferantenkatalog gegen die Anlageregeln. Artikel öffnen und die markierten Felder prüfen.'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function gp(Team $team): array
    {
        $approved = FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->count();
        $tentative = FoodAlchemistGp::visibleToTeam($team)->where('status', 'tentative')->count();

        // approved, requires_la — die drei Beschaffungs-Lagen einzeln (H2c/V-014)
        $keinLa = $this->gpLage($team, 'kein_la')->count();
        $keinPreis = $this->gpLage($team, 'kein_preis')->count();
        $keinLead = $this->gpLage($team, 'kein_lead')->count();

        // approved, requires_la, Lead gesetzt, aber Lead-LA hat keinen gültigen Preis
        $leadOhnePreis = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'approved')->where('requires_la', true)
            ->whereNotNull('lead_la_supplier_item_id')
            ->whereNotExists($this->aktivPreisFuerLead())
            ->count();

        // Allergen-Metadaten nie aggregiert (allergens_confidence NULL)
        $allergenKonfidenzFehlt = FoodAlchemistGp::visibleToTeam($team)->lebensmittel()   // Non-Food hat keine Allergene (Regelwerk §8.18)
            ->where('status', 'approved')->whereNull('allergens_confidence')->count();

        // genutzte approved-GPs ohne Anker (Flavor-Graph-Erdung)
        $ankerFehlt = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'approved')
            ->whereExists($this->gpGenutzt())
            ->whereNotExists($this->gpHatAnker())
            ->count();

        // tentative GPs, die (regelwidrig) schon in Rezepten hängen
        $tentativeGenutzt = FoodAlchemistGp::visibleToTeam($team)
            ->where('status', 'tentative')->whereExists($this->gpGenutzt())->count();

        // Schicht 3 · Slice 4c-2: GPs mit offenem Konformitäts-Hinweis (Critic gegen das GP-Regelwerk).
        $konfGp = DB::table('foodalchemist_conformance_findings')
            ->where('team_id', $team->id)->where('artifact_type', 'gp')
            ->where('status', 'offen')->whereNull('deleted_at')
            ->distinct()->count('artifact_id');

        return [
            $this->info('gp_approved', self::METRIK_LABELS['gp_approved'], $approved),
            $this->info('gp_tentative', self::METRIK_LABELS['gp_tentative'], $tentative),
            // H2c/V-014: aus `gp_ohne_lead` sind drei Lagen geworden, weil sie drei
            // verschiedene Arbeiten sind — Einkauf (kein LA), Preispflege (kein Preis),
            // eine Auswahl-Entscheidung (kein Lead). Nur die letzte trägt den Auto-Fix-Knopf
            // (SignalCockpit::DETERMINISTIC); vorher versprach er eine Reparatur über alle
            // drei und bewegte in der Mehrzahl der Fälle keine Zahl.
            $this->gap('gp_kein_la', self::METRIK_LABELS['gp_kein_la'], $keinLa,
                SignalTyp::DatenqualitaetGpLa, 'dq-gp-kein-la', 'Diese Grundprodukte brauchen einen Lieferantenartikel. Einen für das Team sichtbaren Artikel verknüpfen.'),
            $this->gap('gp_kein_preis', self::METRIK_LABELS['gp_kein_preis'], $keinPreis),
            $this->gap('gp_kein_lead', self::METRIK_LABELS['gp_kein_lead'], $keinLead),
            $this->gap('gp_lead_ohne_preis', self::METRIK_LABELS['gp_lead_ohne_preis'], $leadOhnePreis, SignalTyp::DatenqualitaetGpLa, 'dq-gp-lead-ohne-preis',
                'Der gewählte Hauptartikel hat keinen gültigen Preis (gesperrt, ohne Preis oder Betrag 0). Das Grundprodukt bekommt dadurch keinen Einkaufspreis. Einen Artikel mit Preis als Hauptartikel wählen oder den Preis pflegen.'),
            $this->gap('gp_allergen_konfidenz', self::METRIK_LABELS['gp_allergen_konfidenz'], $allergenKonfidenzFehlt, SignalTyp::DatenqualitaetGpLa, 'dq-gp-allergen-konfidenz',
                'Die Allergene der Lieferantenartikel wurden für diese Grundprodukte nie zusammengeführt. Die Allergenangaben in den Rezepten sind dadurch nicht gesichert.'),
            $this->gap('gp_anker_fehlt', self::METRIK_LABELS['gp_anker_fehlt'], $ankerFehlt, SignalTyp::AnkerFehlt, 'dq-gp-anker-fehlt',
                'Diese Grundprodukte stecken in Rezepten, haben aber kein Aromaprofil. Für Kombinationsvorschläge sind sie dadurch unsichtbar.'),
            $this->gap('gp_tentative_genutzt', self::METRIK_LABELS['gp_tentative_genutzt'], $tentativeGenutzt, SignalTyp::DatenqualitaetGpLa, 'dq-gp-tentative-genutzt',
                'Nicht freigegebene Grundprodukte sollten nicht in Rezepten stecken. Grundprodukt prüfen und freigeben oder in den Rezepten ersetzen.'),
            $this->gap('gp_konformitaet', self::METRIK_LABELS['gp_konformitaet'], $konfGp, SignalTyp::KonformitaetGp, 'dq-gp-konformitaet',
                'Bei diesen Grundprodukten verstoßen Angaben gegen die Anlageregeln, zum Beispiel bei Benennung oder Pflichtangaben. Grundprodukt öffnen und die markierten Felder prüfen.'), $this->gap(\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::KEY, self::METRIK_LABELS[\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::KEY], app(\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::class)->abfrage($team)->count(), null, null, null, SignalSeverity::Info),   // Pflegeliste Stückgewicht, nie rot
        ];
    }

    /** @return list<array<string,mixed>> */
    private function basisrezepte(Team $team): array
    {
        $ekNull = $this->rezepte($team, false)->whereNull('ek_total_eur')->count();
        $ekTeil = $this->rezepte($team, false)->whereNotNull('ek_total_eur')
            ->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total')->count();
        $ankerFehlt = $this->rezepte($team, false)->whereNotExists($this->rezeptHatAnker())->count();

        return [
            $this->gap('br_ek_null', self::METRIK_LABELS['br_ek_null'], $ekNull, SignalTyp::EkKetteUnvollstaendig, 'dq-br-ek-null',
                'Für keine Zutat dieser Basisrezepte ist ein Preis hinterlegt, der Einkaufspreis lässt sich nicht berechnen. Zutaten Grundprodukten mit Lieferantenpreis zuordnen.'),
            $this->gap('br_ek_teil', self::METRIK_LABELS['br_ek_teil'], $ekTeil, SignalTyp::EkKetteUnvollstaendig, 'dq-br-ek-teil',
                'Einzelne Zutaten haben keinen Preis. Der Einkaufspreis ist dadurch zu niedrig. Fehlende Preise ergänzen.'),
            $this->gap('br_anker_fehlt', self::METRIK_LABELS['br_anker_fehlt'], $ankerFehlt, SignalTyp::AnkerFehlt, 'dq-br-anker-fehlt',
                'Diese Basisrezepte haben kein Aromaprofil. Für Kombinationsvorschläge sind sie dadurch unsichtbar.'), $this->gap(\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::KEY_REZEPT, self::METRIK_LABELS[\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::KEY_REZEPT], \Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::rezepteOhneErtrag($team)->count(), null, null, null, SignalSeverity::Info),   // Pflegeliste Stück-Ertrag, nie rot
        ];
    }

    /** @return list<array<string,mixed>> */
    private function gerichte(Team $team): array
    {
        $ekNull = $this->rezepte($team, true)->whereNull('ek_total_eur')->count();
        $ekTeil = $this->rezepte($team, true)->whereNotNull('ek_total_eur')
            ->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total')->count();
        $ankerFehlt = $this->rezepte($team, true)->whereNotExists($this->rezeptHatAnker())->count();
        $unbestimmt = $this->unbestimmteServierform($team);

        return [
            $this->gap('vk_ek_null', self::METRIK_LABELS['vk_ek_null'], $ekNull, SignalTyp::EkKetteUnvollstaendig, 'dq-vk-ek-null',
                'Für keine Zutat dieser Gerichte ist ein Preis hinterlegt. Wareneinsatz und Marge lassen sich nicht berechnen. Zutaten Grundprodukten mit Lieferantenpreis zuordnen.'),
            $this->gap('vk_ek_teil', self::METRIK_LABELS['vk_ek_teil'], $ekTeil, SignalTyp::EkKetteUnvollstaendig, 'dq-vk-ek-teil',
                'Einzelne Zutaten haben keinen Preis. Der Wareneinsatz ist dadurch zu niedrig und die Marge zu hoch ausgewiesen. Fehlende Preise ergänzen.'),
            $this->gap('vk_anker_fehlt', self::METRIK_LABELS['vk_anker_fehlt'], $ankerFehlt, SignalTyp::AnkerFehlt, 'dq-vk-anker-fehlt',
                'Diese Gerichte haben kein Aromaprofil. Für Kombinationsvorschläge und Menüplanung sind sie dadurch unsichtbar.'),
            $this->gap('vk_servierform_unbestimmt', self::METRIK_LABELS['vk_servierform_unbestimmt'], $unbestimmt, SignalTyp::ServierformUnbestimmt, 'dq-vk-servierform-unbestimmt',
                'Die Standard-Servierform steht noch auf „unbestimmt“. Im Gericht festlegen, wie es serviert wird.'),
        ];
    }

    /**
     * Spec 21 Tranche A — Inhalts-Qualität der Rezeptur selbst (deterministisch, 0-Egress).
     *
     * Abgrenzung: die Ebenen `basisrezept`/`gericht` messen Geld (EK-Kette), Erdung (Anker)
     * und Servierform — also ob ein Rezept *auflöst*. Diese Sektion misst, ob es *taugt*:
     * Zubereitung, Mengen, Ausbeute, Benennung, Kategorie, Allergen-Belastbarkeit.
     * Sie spannt bewusst über beide Rezept-Arten (Basisrezept + VK-Gericht), weil die
     * Rezeptur-Regeln (Regelwerk_Basisrezepte) für beide gelten.
     *
     * @return list<array<string,mixed>>
     */
    private function rezeptqualitaet(Team $team): array
    {
        $out = [];
        foreach ($this->rezeptQualitaet->rezeptQualitaetChecks() as $key => $c) {
            $out[] = $this->gap($key, $c['label'], ($c['q'])($team)->count(), $c['typ'], $c['dedup'], $c['desc'], $c['sev']);
        }

        return $out;
    }

    /**
     * Spec 21 Tranche C — Qualität der Komposition (Konzept-Ebene, deterministisch, 0-Egress).
     *
     * Bis hierher endete die Kaskade am Gericht: LA → GP → Basisrezept → VK-Gericht. Das
     * Konzept ist aber die Einheit, die der Kunde kauft — ein vollständiges Gericht in einem
     * halb gefüllten Menü ist trotzdem ein Mangel, und er fällt heute erst im Angebot auf.
     *
     * **Arbeitsmenge = nur was in Gebrauch ist** (s. konzepteInGebrauch). Das ist die
     * tragende Abgrenzung dieser Tranche: anders als ein Rezept ist ein Konzept über
     * längere Zeit *bewusst* unfertig (der Entwurf IST der Arbeitsstand). Würde man alle
     * Entwürfe messen, zählte der Check die normale Arbeit als Fehler — genau das
     * Rauschen, das Spec 21 §9 ausschließt.
     *
     * @return list<array<string,mixed>>
     */
    private function konzept(Team $team): array
    {
        $out = [];
        foreach ($this->konzepte->konzeptChecks() as $key => $c) {
            $out[] = $this->gap($key, $c['label'], ($c['q'])($team)->count(), $c['typ'], $c['dedup'], $c['desc'], $c['sev']);
        }

        return $out;
    }

    /**
     * Spec 21 Tranche D — Qualität des Kundendokuments (Foodbook-Ebene, deterministisch, 0-Egress).
     *
     * Letzte Stufe der Kaskade: LA → GP → Basisrezept → VK-Gericht → Konzept → **Foodbook**.
     * Das Buch ist das Artefakt, das der Kunde in die Hand bekommt — ein leeres Kapitel darin
     * fällt heute erst beim Lesen des PDF auf, und dann steht es schon beim Kunden.
     *
     * **Drei Arbeitsmengen, nicht eine** — das ist die tragende Abgrenzung dieser Tranche und
     * der Unterschied zu Tranche C (dort genügte `konzepteInGebrauch` für alle Checks):
     *  · `foodbook_kapitel_leer` + `foodbook_ziel_verfehlt` messen nur BENUTZTE Bücher
     *    ({@see foodbooksInGebrauch}) — ein Entwurf hat leere Kapitel, weil er ein Entwurf ist.
     *  · `foodbook_skizze_ungeerdet` hängt am **Kapitel-Go**: dort hat ein Mensch „Anlegen"
     *    gedrückt, und damit ist der Arbeitsstand erklärt — unabhängig vom Buch-Status.
     *  · `foodbook_stale` misst nur FREIGEGEBENE Bücher ({@see foodbooksFreigegeben}) — in der
     *    Kalkulation sollen Preise sich bewegen, „überholt" gibt es erst draußen.
     *
     * @return list<array<string,mixed>>
     */
    private function foodbook(Team $team): array
    {
        $out = [];
        foreach ($this->foodbooks->foodbookChecks() as $key => $c) {
            $out[] = $this->gap($key, $c['label'], ($c['q'])($team)->count(), $c['typ'], $c['dedup'], $c['desc'], $c['sev']);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function quer(Team $team): array
    {
        // Zutat-Mappings aus KI-Vorschlag, noch nicht menschlich verifiziert.
        $geminiUnverifiziert = DB::table('foodalchemist_recipe_ingredients as ri')
            ->where('ri.match_method', 'gemini_proposed')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipes as r')
                ->whereColumn('r.id', 'ri.recipe_id')->whereNull('r.deleted_at'))
            ->count();

        return [
            $this->gap('ri_gemini_unverifiziert', self::METRIK_LABELS['ri_gemini_unverifiziert'], $geminiUnverifiziert, null, null),
        ];
    }

    // ---- Wiederverwendete Sub-Queries ------------------------------------

    /** Basis-Query der Rezepte einer Sicht (VK = true, Basisrezept = false). */
    private function rezepte(Team $team, bool $vk): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', $vk);
    }

    // ---- Namens-Checks (PHP statt SQL, bewusst) ---------------------------
    //
    // Naming und Dubletten brauchen Normalisierung + Muster-Erkennung. In SQL wäre das
    // entweder dialekt-gebunden (REGEXP kennt MySQL, SQLite nicht) oder eine Kette
    // verschachtelter REPLACE/LOWER-Aufrufe, die auf beiden Dialekten unterschiedlich mit
    // Umlauten umgeht. Ein Pass über (id, name) ist bei Bestandsgrößen im Tausender-Bereich
    // billig und auf jedem Dialekt bit-identisch. Bewusst ohne Memoisierung: der Service
    // wird auch NACH einem Fix erneut gefragt (countFor ⇒ Signal schließen) — ein Cache
    // würde dort den alten Stand melden.

    /** EXISTS: GP ist in mindestens einer Rezept-Zutat genutzt. */
    private function gpGenutzt(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_ingredients as ri')
            ->whereColumn('ri.gp_id', 'foodalchemist_gps.id');
    }

    /** EXISTS: GP hat ein LEBENDES Anker-Mapping (soft-gelöschte zählen nicht — B-7 räumt alte ai_inferred so weg). */
    private function gpHatAnker(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_gp_anchor_mappings as m')
            ->whereColumn('m.gp_id', 'foodalchemist_gps.id')->whereNull('m.deleted_at');
    }

    /**
     * EXISTS: Lead-LA des GP hat einen aktiven Preis — in der Lesart des Money-Paths.
     *
     * Spec 22 · H2b (V-053): das Prädikat wird NICHT mehr hier geschrieben, sondern von
     * `PriceService::scopeAktiv` geholt — derselben Stelle, aus der Rezept-EK
     * (`RecipeRecomputeService::laMitPreis` → `activePriceSubquery`), Preis-Alarm und
     * `ingest.STATUS` ihre Definition beziehen. Vorher fehlte der Ampel der **Status**
     * (GL-11 T1: nur `0` Standard und `2` Aktion sind aktiv), weshalb sie eine
     * statusfremde Zeile als Versorgung durchgehen ließ, die der EK nicht findet —
     * ein Fehler in Richtung „zu wenige Lücken". Jede künftige Statusregel zieht jetzt
     * automatisch mit; `deleted_at IS NULL` kommt aus dem SoftDeletes-Scope des Models.
     *
     * Zwei Entscheidungen, die diese Etappe bewusst trifft (statt sie mitzunehmen):
     *  1. **`valid_to` wird NICHT gefiltert.** `PriceService::activeFor` sortiert nur
     *     danach (`valid_to IS NULL DESC, valid_to DESC, id DESC`) und liefert bei einer
     *     nur noch abgelaufenen Zeile eben diese. Filterte die Ampel hier, meldete sie
     *     eine Lücke, wo der EK rechnet — eine *dritte* Preis-Wahrheit statt einer
     *     geteilten. Ob eine abgelaufene Zeile überhaupt ein aktiver EK sein darf, ist
     *     eine Frage an den Money-Path, nicht an die Ampel (→ V-069).
     *  2. **`price > 0` bleibt strenger als `scopeAktiv` (`>= 0`).** 0,00 € ist nach
     *     GL-11 / Spec 13 S1b kein Preis, sondern eine Datenlücke, die als aktiver
     *     Standard-EK die Kosten jedes nutzenden Rezepts auf null zöge. Die eine
     *     Abweichung steht hier sichtbar, statt das ganze Prädikat zu duplizieren.
     */
    private function aktivPreisFuerLead(): \Illuminate\Database\Eloquent\Builder
    {
        // Spec 82 3a: Lead-Ampel ist global → nur Listenpreis (kein Mandantenpreis versorgt den Stamm-Lead).
        return $this->preise->nurListenpreis($this->preise->scopeAktiv(FoodAlchemistPrice::query()))
            ->select(DB::raw(1))
            ->whereColumn('foodalchemist_prices.supplier_item_id', 'foodalchemist_gps.lead_la_supplier_item_id')
            ->where('foodalchemist_prices.price', '>', 0);
    }

    /**
     * EXISTS: an diesem GP hängt mindestens ein Lieferantenartikel — über die
     * Struktur-Tabelle, aus der auch der Money-Path seine LAs holt.
     *
     * Spec 22 · H2c (V-014): das ist bewusst NICHT `n_las_total`. Der Zähler wird
     * inkrementell geführt (`LeadLaService::verknuepfe`/`loese`, Import setzt ihn) und
     * driftet dadurch — im Bestand weicht er bei 9 von 183 approved GPs von der
     * Struktur-Tabelle ab, in 8 Fällen steht er auf 0, obwohl LAs verknüpft sind. Der
     * EK rechnet auf der Struktur-Tabelle (`RecipeRecomputeService::laMitPreis`, Join
     * über `foodalchemist_supplier_item_structures`), die Ursachen-Kette prüft sie
     * ebenfalls (`SignalCauseService::gpBeschaffung`) — die Ampel liest jetzt dieselbe
     * Quelle statt einen Zähler über sie.
     *
     * @param  bool  $nurBepreist  zusätzlich: der LA hat einen aktiven Preis (GL-11 T1)
     */
    private function laDiesesGp(Team $team, bool $nurBepreist = false): \Closure
    {
        return function ($q) use ($nurBepreist, $team) {
            $q->select(DB::raw(1))
                ->from('foodalchemist_supplier_item_structures as s')
                ->whereColumn('s.gp_id', 'foodalchemist_gps.id')
                ->whereNull('s.deleted_at')
                ->whereIn('s.supplier_item_id', FoodAlchemistSupplierItem::visibleToTeam($team)->select('foodalchemist_supplier_items.id'));

            if ($nurBepreist) {
                $q->whereExists($this->aktivPreisFuer('s.supplier_item_id'));
            }

            return $q;
        };
    }

    /**
     * EXISTS: dieser Lieferantenartikel hat einen aktiven Preis — dieselbe Lesart wie
     * {@see aktivPreisFuerLead()} (H2b), nur auf eine beliebige Artikel-Spalte
     * korreliert statt fest auf den Lead. Die zwei Entscheidungen dort (`valid_to`
     * ungefiltert, `price > 0` strenger als `scopeAktiv`) gelten hier mit.
     */
    private function aktivPreisFuer(string $itemSpalte): \Illuminate\Database\Eloquent\Builder
    {
        // Spec 82 3a: wie aktivPreisFuerLead() nur Listenpreis.
        return $this->preise->nurListenpreis($this->preise->scopeAktiv(FoodAlchemistPrice::query()))
            ->select(DB::raw(1))
            ->whereColumn('foodalchemist_prices.supplier_item_id', $itemSpalte)
            ->where('foodalchemist_prices.price', '>', 0);
    }

    /**
     * Die drei Beschaffungs-Lagen eines approved GP als Query — EINE Definition für
     * Zählung (`gp()`), Liste/Fixer-Satz (`queryFor()`) und Objekt-Frage
     * (`trifftObjekt()`).
     *
     * Reihenfolge und Schnitt spiegeln `SignalCauseService::gpBeschaffung`:
     *  - `kein_la`    → kein LA am GP: Beschaffungs-Lücke, kein Fixer kann etwas setzen
     *  - `kein_preis` → LAs da, keiner bepreist: Preispflege/Einkauf, kein Fixer
     *  - `kein_lead`  → bepreister LA da, aber kein Lead gewählt: **der fixbare Fall**
     * Die Lagen sind trennscharf (jeder GP in höchstens einer) und decken zusammen die
     * alte `gp_ohne_lead`-Menge ab — ohne deren Zähler-Fehlalarm (s. `laDiesesGp`).
     *
     * **Warum `kein_preis` einen leeren Lead verlangt:** ohne diese Bedingung wäre die
     * Lage lead-agnostisch und überschnitte sich mit der bestehenden Metrik
     * `gp_lead_ohne_preis` (H2b) — ein GP mit gesetztem Lead und lauter unbepreisten LAs
     * stünde in beiden, die Ampel zählte ihn zweimal. Am Bestand gemessen sind das 8 von
     * 11 Fällen, also der Regelfall und nicht der Rand. Die drei Lagen beantworten
     * darum ausschließlich die Frage „**dieser GP hat keine gewählte Preisquelle**";
     * ist ein Lead gewählt und trägt nur keinen Preis, ist das `gp_lead_ohne_preis`.
     */
    private function gpLage(Team $team, string $lage): \Illuminate\Database\Eloquent\Builder
    {
        $q = FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->where('requires_la', true);

        return match ($lage) {
            'kein_la' => $q->whereNotExists($this->laDiesesGp($team)),
            'kein_preis' => $q->whereExists($this->laDiesesGp($team))->whereNotExists($this->laDiesesGp($team, true))
                ->whereNull('lead_la_supplier_item_id'),
            'kein_lead' => $q->whereExists($this->laDiesesGp($team, true))->whereNull('lead_la_supplier_item_id'),
        };
    }

    /** EXISTS: Rezept hat ein Aromenprofil (Spec 60: abgeleitet aus den Zutaten-Ankern). */
    private function rezeptHatAnker(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_profile_anker as m')
            ->whereColumn('m.recipe_id', 'foodalchemist_recipes.id');
    }

    /** VK-Gerichte, deren Standard-Darreichung auf der Servierform „unbestimmt" steht. */
    private function unbestimmteServierform(Team $team): int
    {
        // F2 (unique je Team): „unbestimmt“ kann global UND als Mandanten-Kopie existieren → alle sichtbaren
        $unbIds = FoodAlchemistServierform::visibleToTeam($team)->where('code', 'unbestimmt')->pluck('id')->all();
        if ($unbIds === []) {
            return 0;
        }

        return $this->rezepte($team, true)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_presentations as p')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                ->whereIn('p.serving_form_id', $unbIds)->where('p.is_standard', true)->whereNull('p.deleted_at'))
            ->count();
    }

    // ---- „Reinschauen": betroffene Objekte je Metrik (read-only) ---------

    /**
     * Listet die konkreten Objekte hinter einer Lücken-Metrik — dieselben Prädikate
     * wie die Zähl-Query oben (eine Regel-Stelle, kein Drift), nur SELECT statt COUNT.
     * Für das Signal-Cockpit („reinschauen"): der Detektor speichert im Payload nur
     * die Anzahl, die Objekte werden hier on-demand aufgelöst.
     *
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    public function betroffene(Team $team, string $metrik, int $limit = 50): array
    {
        [$q, $kind] = $this->queryFor($team, $metrik);
        if ($q === null) {
            return [];
        }

        return match ($kind) {
            'gp' => $this->gpItems($q, $limit),
            'la' => app(\Platform\FoodAlchemist\Services\Einkauf\LaBeschaffungsLagen::class)->liste($q, $limit),
            'concept' => $this->conceptItems($q, $limit),
            'foodbook' => $this->foodbookItems($q, $limit),
            default => $this->recipeItems($q, $limit),
        };
    }

    /**
     * Umgekehrte Frage (Spec 21 · P·2, objekt-zentrische Sicht): trifft das Prädikat
     * dieser Metrik ein KONKRETES Objekt? Dasselbe `queryFor` wie Zählung und Liste —
     * nur mit `whereKey`, damit „was hat dieses Rezept noch?" nicht die volle
     * Trefferliste jeder Metrik laden muss (ein EXISTS je Metrik statt n Zeilen).
     *
     * $kind muss zur Metrik passen ('gp'|'recipe'|'concept'|'foodbook') — eine GP-Metrik
     * trifft nie ein Rezept, auch wenn die IDs zufällig gleich sind.
     */
    public function trifftObjekt(Team $team, string $metrik, string $kind, int $id): bool
    {
        [$q, $metrikKind] = $this->queryFor($team, $metrik);
        if ($q === null || $metrikKind !== $kind) {
            return false;
        }

        return $q->whereKey($id)->exists();
    }

    /**
     * Re-Count einer einzelnen Metrik (nach einem Fix). Dieselbe Query wie betroffene()
     * (queryFor), nur COUNT — für die Lifecycle-Entscheidung „Signal schließen" (count 0).
     */
    public function countFor(Team $team, string $metrik): int
    {
        [$q] = $this->queryFor($team, $metrik);

        return $q?->count() ?? 0;
    }

    /**
     * EINE Query-Definition je Metrik (kein Drift): betroffene() (SELECT) + countFor() (COUNT)
     * teilen sich diese. Rückgabe [?Builder, kind:'gp'|'recipe'|'concept'|'foodbook'];
     * null = keine Objekte.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder|null, 1: string}
     */
    private function queryFor(Team $team, string $metrik): array
    {
        // Die Register (Tranche A = Rezept, C = Konzept, D = Foodbook) definieren ihr
        // Prädikat selbst — hier nur nachschlagen, nicht nachbauen. Der `kind` gehört ans
        // Register, nicht an den Aufrufer: er entscheidet, WELCHES Objekt die Liste zeigt.
        // Der switch unten ist der Altbestand der Kaskaden-Ebenen (s. V-005).
        foreach ([
            ['recipe', $this->rezeptQualitaet->rezeptQualitaetChecks()],
            ['la', app(\Platform\FoodAlchemist\Services\Einkauf\LaBeschaffungsLagen::class)->checks()], ['gp', app(\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::class)->checks()], ['recipe', app(\Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht::class)->checksRezept()],   // Pflegelisten Stückgewicht (Dominique 23.11., Kurator 26.11.)
            ['concept', $this->konzepte->konzeptChecks()],
            ['foodbook', $this->foodbooks->foodbookChecks()],
        ] as [$kind, $checks]) {
            if (isset($checks[$metrik])) {
                return [($checks[$metrik]['q'])($team), $kind];
            }
        }

        switch ($metrik) {
            case 'gp_kein_la':
            case 'gp_kein_preis':
            case 'gp_kein_lead':
                // H2c/V-014: dieselbe Definition wie die Zählung in gp() — der Satz, den
                // der lead_la-Fixer mutiert, ist der Satz, den die Metrik zeigt.
                return [$this->gpLage($team, substr($metrik, 3)), 'gp'];
            case 'gp_lead_ohne_preis':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->where('requires_la', true)
                    ->whereNotNull('lead_la_supplier_item_id')->whereNotExists($this->aktivPreisFuerLead()), 'gp'];
            case 'gp_allergen_konfidenz':
                return [FoodAlchemistGp::visibleToTeam($team)->lebensmittel()->where('status', 'approved')->whereNull('allergens_confidence'), 'gp'];
            case 'gp_anker_fehlt':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')->whereExists($this->gpGenutzt())->whereNotExists($this->gpHatAnker()), 'gp'];
            case 'gp_tentative_genutzt':
                return [FoodAlchemistGp::visibleToTeam($team)->where('status', 'tentative')->whereExists($this->gpGenutzt()), 'gp'];
            case 'br_ek_null':
                return [$this->rezepte($team, false)->whereNull('ek_total_eur'), 'recipe'];
            case 'br_ek_teil':
                return [$this->rezepte($team, false)->whereNotNull('ek_total_eur')->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total'), 'recipe'];
            case 'br_anker_fehlt':
                return [$this->rezepte($team, false)->whereNotExists($this->rezeptHatAnker()), 'recipe'];
            case 'vk_ek_null':
                return [$this->rezepte($team, true)->whereNull('ek_total_eur'), 'recipe'];
            case 'vk_ek_teil':
                return [$this->rezepte($team, true)->whereNotNull('ek_total_eur')->whereColumn('ek_n_ingredients_priced', '<', 'ek_n_ingredients_total'), 'recipe'];
            case 'vk_anker_fehlt':
                return [$this->rezepte($team, true)->whereNotExists($this->rezeptHatAnker()), 'recipe'];
            case 'vk_servierform_unbestimmt':
                $unbIds = FoodAlchemistServierform::visibleToTeam($team)->where('code', 'unbestimmt')->pluck('id')->all();
                if ($unbIds === []) {
                    return [null, 'recipe'];
                }

                return [$this->rezepte($team, true)->whereExists(fn ($x) => $x->select(DB::raw(1))
                    ->from('foodalchemist_recipe_presentations as p')->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')
                    ->whereIn('p.serving_form_id', $unbIds)->where('p.is_standard', true)->whereNull('p.deleted_at')), 'recipe'];
            case 'ri_gemini_unverifiziert':
                return [FoodAlchemistRecipe::visibleToTeam($team)->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('foodalchemist_recipe_ingredients as ri')->whereColumn('ri.recipe_id', 'foodalchemist_recipes.id')
                    ->where('ri.match_method', 'gemini_proposed')), 'recipe'];
            default:
                return [null, 'gp'];
        }
    }

    /** @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}> */
    private function gpItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn ($g) => ['kind' => 'gp', 'id' => (int) $g->id, 'name' => (string) $g->name, 'is_sales_recipe' => false])->all();
    }

    /**
     * Konzept-Objekte (Tranche C). `is_sales_recipe` ist hier immer false — das Feld
     * unterscheidet im Panel nur Basisrezept von VK-Gericht und hat für ein Konzept
     * keine Bedeutung; die Fallunterscheidung im Panel läuft über `kind`.
     *
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    private function conceptItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn ($c) => ['kind' => 'concept', 'id' => (int) $c->id, 'name' => (string) $c->name, 'is_sales_recipe' => false])->all();
    }

    /**
     * Foodbook-Objekte (Tranche D). Das Buch trägt seinen Namen in `label`, nicht in
     * `name` — die Panel-Zeile erwartet `name`, also wird hier umbenannt (statt das Panel
     * um einen zweiten Feldnamen zu erweitern). `is_sales_recipe` ist wie beim Konzept
     * ohne Bedeutung; die Fallunterscheidung im Panel läuft über `kind`.
     *
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    private function foodbookItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('label')->limit($limit)->get(['id', 'label'])
            ->map(fn ($f) => ['kind' => 'foodbook', 'id' => (int) $f->id, 'name' => (string) $f->label, 'is_sales_recipe' => false])->all();
    }

    /** @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}> */
    private function recipeItems(\Illuminate\Database\Eloquent\Builder $q, int $limit): array
    {
        return $q->orderBy('name')->limit($limit)->get(['id', 'name', 'is_sales_recipe'])
            ->map(fn ($r) => ['kind' => 'recipe', 'id' => (int) $r->id, 'name' => (string) $r->name, 'is_sales_recipe' => (bool) $r->is_sales_recipe])->all();
    }

    // ---- Metrik-Konstruktoren --------------------------------------------

    /** Informations-Metrik (Total o. ä.) — nie ampel-relevant. */
    private function info(string $key, string $label, int $wert): array
    {
        return ['key' => $key, 'kind' => self::KIND_INFO, 'label' => $label, 'wert' => $wert, 'severity' => 'info', 'signal' => null];
    }

    /**
     * Lücken-Metrik: grün bei 0, gelb bis Schwelle, rot darüber. Optionaler
     * Signal-Deskriptor (Typ + dedup_key + Beschreibung) für die --signals-Emission.
     *
     * $sev setzt den Signal-Schweregrad fix, statt ihn aus der Trefferzahl abzuleiten —
     * die Ampel-Farbe der Metrik zeigt weiter die Menge. Einzige Ausnahme: ein als
     * `Info` deklarierter Check wird NIE rot/gelb — eine Pflege-Liste („verwaiste
     * Rezepte") ist kein Alarm, und 300 davon sind kein roter Zustand.
     */
    private function gap(string $key, string $label, int $wert, ?SignalTyp $typ = null, ?string $dedup = null, ?string $desc = null, ?SignalSeverity $sev = null): array
    {
        $severity = $wert === 0 ? 'gruen' : ($sev === SignalSeverity::Info ? 'info' : ($wert > self::ROT_SCHWELLE ? 'rot' : 'gelb'));
        $signal = ($typ !== null && $dedup !== null)
            ? ['typ' => $typ, 'dedup' => $dedup, 'desc' => $desc, 'sev' => $sev]
            : null;

        return ['key' => $key, 'kind' => self::KIND_GAP, 'label' => $label, 'wert' => $wert, 'severity' => $severity, 'signal' => $signal];
    }
}
