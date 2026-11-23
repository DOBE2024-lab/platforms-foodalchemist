<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Support\Planung\PlanungOptionen;
use Platform\FoodAlchemist\Support\Planung\KaskadenAnsicht;
use Platform\FoodAlchemist\Support\Planung\PlanungReiterDaten;
use Platform\FoodAlchemist\Support\Planung\ReglerParams;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\PlanungsEinstiegService;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TitelVorschlagService;
use Platform\FoodAlchemist\Services\WorkerHealthService;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Support\VoiceFehlerText;
use Platform\FoodAlchemist\Support\VoiceMime;

/**
 * Planungs-/Kreativ-Cockpit (Doppel-Diamant, Spec 08). Haus-Layout: links Kategorie→Klasse +
 * Session-Liste, Mitte Dashboard/Vorschau, rechts Detail; „Öffnen" → Fullscreen-Dark-Editor mit
 * Tabs Analyse · Skizzen · Planung + Go-Leiste. Read-mostly Container VOR dem Grounding — erst
 * „Go" erzeugt Basisrezept/Gericht/Concept (Draft), Lineage zurück in die Session.
 */
class Index extends Component
{
    use WithFileUploads;
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyPlanung, \Platform\FoodAlchemist\Livewire\Concerns\PlanBestehendesConcept, \Platform\FoodAlchemist\Livewire\Concerns\PlanGerichtVorgaben, \Platform\FoodAlchemist\Livewire\Concerns\PlanRezepturFuerGericht;   // Spec 80 Teil D; #1003 PR7 Handy-Umfang

    public ?string $fehler = null;

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    /** Neue-Planung-Formular (Mitte-Dashboard). */
    public string $neuTitel = '';

    /** Landing-Liste (finale Etappe #17): Volltext-Suche über den Session-Titel (live). */
    public string $sucheListe = '';

    /** Landing-Liste (finale Etappe #17): Status-Filter (''=alle | entwurf|läuft|prüfen|fertig|fehlgeschlagen). */
    public string $filterStatus = '';

    /** Landing-Liste (finale Etappe #17): Typ-Filter (''=alle | rezept|gericht|concept — aus dem Lauf-Scope). */
    public string $filterTyp = '';

    /** Editor-Felder der aktiven Session. */
    public array $form = ['title' => '', 'brief' => '', 'analysis' => '', 'creative_mode' => 'voll_kreativ'];

    /** Composer-Tab: gewählte Anker (Einträge {id, slug, label}), Cap = INNER_ANKER_MAX (12). */
    public array $composerAnker = [];

    /** Composer-Suchfeld (live). */
    public string $composerTerm = '';

    /** Composer-Picker: Kategorie-Filter (leer = alle). */
    public string $composerCategory = '';

    /** Composer-Fokus: aktiv fokussierter Anker (Klick im Netz) — Netz dimmt auf ihn, Picker rankt relativ zu ihm. */
    public ?int $composerFocus = null;

    /** „Passt das zusammen?" als Gericht (Teller aus Komponenten) oder als Basisrezept (eine Zubereitung) — Dominique 16.10. */
    public string $composerModus = \Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::MODUS_GERICHT;

    /**
     * Foodpairing-Composer (2026-08-24): PERSISTENTES Seed-Pin für den „in den Erstellen-Tab springen"-
     * Flow. Überlebt den Tab-Wechsel als Livewire-State, damit
     * der reguläre Go im Erstellen-Tab (mit vollem Leitplanken-Regler) die Anker als seed_anker mitnimmt.
     * Form: ['scope' => 'rezept'|'gericht', 'slugs' => [...]]. Wird in goKaskade nach Verwendung geleert.
     */
    public array $composerSeedPin = [];

    // ── Leitstelle: PER-TAB Eingabe + Leitplanken (jeder Scope eigener Zustand) ──
    /**
     * Eingabe je Creation-Tab (rezept|gericht|concept): Titel/Beschreibung/Kreativ-Modus.
     * Jeder Tab ist UNABHÄNGIG — Werte auf einem Tab wirken nicht auf die anderen. In mount()
     * initialisiert. Am Go zählt der Satz des Start-Tabs.
     * @var array<string,array{titel:string,brief:string,creative_mode:string}>
     */
    public array $eingabe = [];

    /**
     * Richtungs-Regler (Leitplanken) JE Scope — jeder Tab hat einen eigenen kompletten Satz
     * (inkl. favoriten/favoriten_conv_only/ziel_vk/voll_anreichern/ki_bilder). In mount() je Tab
     * aus REGLER_DEFAULT kopiert. **Kaskaden-Regel (User-Entscheid 2026-08-14):** am Go zählt NUR
     * der Satz des Start-Tabs; er wird als `generation_params` persistiert und propagiert die ganze
     * Kaskade nach unten (Start-Tab gilt für alles darunter). Spiegelt Generator-/VkGenerator-Modal.
     * @var array<string,array<string,mixed>>
     */
    public array $regler = [];

    /**
     * Spec 55: welche `$regler[$scope]`-Felder zuletzt vom Sprachbefehl-Agenten übernommen
     * wurden (`$scope => [$feld => true]`) — reine Anzeige-Markierung für die Leitplanken-UI
     * („vom Agenten"), kein eigener Speicherort. Ein manueller Edit des Feldes löscht seine
     * eigene Markierung nicht automatisch (kein Watcher pro Feld) — vertretbar für eine reine
     * Kennzeichnung, sie verschwindet spätestens beim nächsten Tab-Wechsel/Neuladen.
     */
    public array $reglerVonAgent = [];

    /** Die drei Creation-Scopes (Tabs mit eigener Eingabe + Leitplanken). */
    public const SCOPES = ['rezept', 'gericht', 'concept'];

    /** Default-Leitplanken-Satz je Scope (in mount() je Tab kopiert). */
    public const REGLER_DEFAULT = [
        // Frische = Multi-Select Erlaubnis-Liste (L1.5): [] = egal (kein Zustands-Filter). Ein/mehrere
        // Werte = harte Erlaubnis (nur diese gps.condition). `bestand` wird aus dem Kreativ-Modus
        // abgeleitet (nicht mehr eigener Chip, s. reglerParams) — Default bleibt als Fallback stehen.
        'convenience' => '', 'frische' => [], 'bestand' => 'hybrid',
        'bio_praeferenz' => 'konventionell', 'level' => '', 'sektor' => '',
        // aroma = Freitext-Feinjustierung, aroma_kueche = kuratierte Küche (11 + Frei, L1.5).
        // allergen_nogo (L3): EU-14-Allergen-Ausschluss (hart geprüft, getrennt vom Diät-Ausschluss).
        'diaet_hart' => [], 'allergen_nogo' => [], 'aroma' => '', 'aroma_kueche' => '',
        // L6 »Menge & Ziel« (KI-Vorgaben): Pax (Gäste), Ziel-Portionsgröße g, Saison, Ziel-Wareneinsatz %.
        // Basisrezept (Halbfabrikat, kein Teller): Ziel-Menge + Einheit statt Pax/Portion (scope-abhängig, s. Blade).
        'pax' => '', 'ziel_portion_g' => '', 'saison' => '', 'ziel_we_pct' => '',
        'ziel_einheit' => '', 'ziel_menge' => '',
        'occasion' => '', 'eventtyp' => '', 'serviceform' => '', 'kompositions_stil' => '',
        'favoriten' => false, 'favoriten_conv_only' => false,
        // `voll_anreichern` Default AN (Entscheid Dominique 2026-09-07): mit AUS ueberspringt
        // die Anreicherung `coverageGlieder` — Step-by-Step, Sensorik, Arbeits-/Ruestzeit,
        // Equipment, Posten, Prozess-/Aromaanker, Pairings. In Lauf 65 war der Schalter aus,
        // deshalb stand `work_time_min` auch am FREIGEGEBENEN Rezept auf null, waehrend das
        // Badge einen Abschluss zeigte. `ki_bilder` bleibt AUS — das ist die teure Achse
        // (ein KI-Call je Bild), die Coverage kostet einen Textlauf.
        'ziel_vk' => '', 'voll_anreichern' => true, 'ki_bilder' => false,
        // Concept-Typ (#35): Menü (Gänge) vs. Buffet (Stationen) — steuert Label + Positionen-Logik.
        // Default 'menue' (byte-identisch bisher); nur 'buffet' ist ein Signal (station-Slots).
        'menue_typ' => 'menue',
        // Menü-Leitplanken (nur Concept-Tab, Etappe 2a): Anzahl Gänge + Zielpreis-Korridor je Person.
        // Als Text/Zahl gehalten (leer = keine Vorgabe); reglerParams parst sie in kanonische _pp-Keys.
        'menue_gaenge' => '', 'menue_preis_min' => '', 'menue_preis_ziel' => '', 'menue_preis_max' => '',
        // Diät-Quoten (Etappe 2a, Teil 2): Portfolio-Anteil vegan/vegetarisch in % (leer = keine Vorgabe).
        // reglerParams parst sie in kanonische _pct-Keys. Anteil ≠ harter Ausschluss (diaet_hart).
        'menue_quote_vegan' => '', 'menue_quote_vegetarisch' => '',
        // Portfolio-Balance (Etappe 2a, Rest Teil 2): Menü-Vielfalt (leer = keine Vorgabe). reglerParams
        // reicht nur einen erlaubten MENUE_BALANCE-Enum-Wert durch (Concept-Scope). Weich, kein Filter.
        'menue_balance' => '',
    ];

    /**
     * Portfolio-Balance-Achse (Menü-Vielfalt, Etappe 2a, Rest Teil 2) — Wert=>Label.
     * WEICHE Zusammenstellungs-Vorgabe (kein harter Filter): wie breit das Menü über Proteine/
     * Warengruppen/Garmethoden streut. Enum, damit reglerParams gegen einen definierten Satz prüft
     * (Select kann nicht vertippt werden → kein Zahl-Guard wie bei Gänge/Quote nötig). Nur Concept.
     */
    public const MENUE_BALANCE = [
        'ausgewogen' => 'Ausgewogen (breite Vielfalt)',
        'fokussiert' => 'Fokussiert (ein roter Faden)',
    ];

    /**
     * Concept-Typ-Achse (#35) — Wert=>Label. Bestimmt, WIE die Positionen des Concepts gebaut werden:
     * »Menü« = zeitliche Dramaturgie (gang-Slots, „Anzahl Gänge"), »Buffet« = parallele Stationen
     * (station-Slots, „Anzahl Stationen"). Ein Buffet ist KEIN Menü mit vielen Gängen — es hat eine
     * eigene Positionen-Logik (die Gänge-Achse gilt nur fürs Menü). Default = Menü: nur der explizite
     * `buffet`-Wert ist ein Signal, `menue`/leer bleibt byte-identisch zum bisherigen Verhalten.
     * Nur Concept-Scope.
     */
    public const MENUE_TYPEN = [
        'menue' => 'Menü (Gänge nacheinander)',
        'buffet' => 'Buffet (Stationen parallel)',
    ];

    /**
     * Brief-Vorlagen je Sektor/Anlass (Etappe 4 — „Schnellstart statt Blank Page"). Kuratierte
     * Starter-Briefings, die die leere Seite bewusst füllen; der Mensch passt danach an. Jede Vorlage
     * trägt den Kontext, für den sie steht — `sektor`/`occasion`/`serviceform` spiegeln 1:1 die
     * Leitplanken-Enums (leitplanken.blade.php), es werden also KEINE neuen Vokabeln erfunden.
     * `scopes` = auf welchen Creation-Tabs die Vorlage erscheint. Teil 1: nur Gericht (Sektor/Anlass
     * sind Gericht-/Menü-Kontext; Basisrezept ist komponentenhaft sektor-agnostisch, Concept eigener
     * Tab → Folge-Chunk). Der `brief` ist Guidance (nüchtern, keine harten Fakten/Werte als Wahrheit).
     * @var array<string,array{label:string,scopes:list<string>,titel:string,brief:string,sektor:string,occasion:string,serviceform:string}>
     */
    public const BRIEF_VORLAGEN = [
        'catering_empfang_flying' => [
            'label' => 'Catering — Empfang / Flying',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Fingerfood-Häppchen für einen Steh-Empfang, in einem Bissen ohne Besteck essbar und formstabil (auch nach kurzer Wartezeit auf der Platte). Ansprechend im Flying Service zu reichen.',
            'sektor' => 'event_privat',
            'occasion' => 'empfang',
            'serviceform' => 'flying',
        ],
        'catering_galadinner' => [
            'label' => 'Catering — Galadinner (Hauptgang)',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Warmer Hauptgang für ein gesetztes Galadinner im Tellerservice, gehobenes Niveau, sauber anrichtbar und regenerierfähig für den Bankett-Ausstoß.',
            'sektor' => 'event_privat',
            'occasion' => 'dinner',
            'serviceform' => 'tellerservice',
        ],
        'bgm_mittagstisch' => [
            'label' => 'Betriebsgastro — Mittagstisch',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Mittagsgericht für die Betriebsgastronomie am Buffet, warmhalte- und ausgabestabil über die Mittagslinie, kalkulierbarer Wareneinsatz und alltagstaugliche Zutaten.',
            'sektor' => 'business',
            'occasion' => 'lunch',
            'serviceform' => 'buffet',
        ],
        'care_mittag' => [
            'label' => 'Care / Klinik — Mittagsverpflegung',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Mittagsgericht für die Care-/Klinikverpflegung im Tellerservice, gut kaufähig und bekömmlich, zurückhaltend gewürzt und regenerierfähig aus der Zentralküche.',
            'sektor' => 'care',
            'occasion' => 'lunch',
            'serviceform' => 'tellerservice',
        ],
        'schule_mittag' => [
            'label' => 'Schule / Kita — Mittagsverpflegung',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Kindgerechtes Mittagsgericht für Schule/Kita am Buffet, mild gewürzt und akzeptanzstark, an DGE-Qualitätsstandards orientiert und in Serie ausgabefähig.',
            'sektor' => 'kita_schule',
            'occasion' => 'lunch',
            'serviceform' => 'buffet',
        ],
        'restaurant_hauptgang' => [
            'label' => 'Restaurant — à la carte Hauptgang',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'À-la-carte-Hauptgang fürs Restaurant im Tellerservice, à la minute abrufbar, sauberes Plating und eine klare geschmackliche Handschrift.',
            'sektor' => 'restaurant',
            'occasion' => 'dinner',
            'serviceform' => 'tellerservice',
        ],
    ];

    /** #1b Grounding-Preview: welches Wissen/Pairing/Template ein Basisrezept-Lauf ziehen würde (on-demand, ohne Generierung). */
    public ?array $wissenVorschau = null;

    /** Welche Schnellstart-Vorlage je Scope aktuell geladen ist (id) — fürs Chip-Highlight. Eine manuelle Brief-Änderung hebt sie auf ({@see updated()}). */
    public array $aktiveVorlage = [];

    /** Eingabe für „Als Vorlage speichern" (Snapshot-Name), im Erstell-Tab gebunden. */
    public string $vorlageName = '';

    /**
     * Pill-Gruppen fürs Cockpit-View (Parität zu GeneratorModal::RICHTUNGEN). Inline
     * gehalten statt aus dem Modal referenziert — die Leitstelle ist der neue Ort der
     * Steuerung; die Modal-Knöpfe der Browser-Seiten entfallen.
     */
    public const RICHTUNGEN = [
        ['field' => 'convenience', 'label' => 'Eigenleistung', 'optionen' => ['' => 'Keine Vorgabe', 'from_scratch' => 'Alles selbst', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Die KI wählt passend zur Beschreibung.', 'from_scratch' => 'Rohware und eigene Basisrezepte, keine Fertigprodukte.', 'teil_convenience' => 'Halbfabrikate sind erlaubt.', 'voll_convenience' => 'Fertigprodukte werden bevorzugt.']],
        ['field' => 'level', 'label' => 'Niveau', 'optionen' => ['' => 'Keine Vorgabe', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Die KI wählt passend zur Beschreibung.']],
        // »Bestand-Nutzung« (Chips) entfernt (2026-08-17): die Reuse-Achse ist jetzt EIN Regler — der
        // Kreativ-Modus-Select im Eingabe-Block (voll_kreativ|hybrid|datenbank). `bestand` wird daraus
        // in reglerParams abgeleitet, keine zweite konkurrierende Achse mehr.
        ['field' => 'bio_praeferenz', 'label' => 'Bio', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Keine Präferenz'], 'hint' => ['konventionell' => 'Voreinstellung: Bio wird nicht erzwungen.', 'bio' => 'Bio-Ware wird bevorzugt.', 'egal' => 'Bio und konventionell gleichrangig.']],
        // »Frische-Hook« ist jetzt Multi-Select (Erlaubnis-Liste) → eigener Block in leitplanken.blade
        // (FRISCHE_OPTIONEN), nicht mehr Single-Pill hier.
    ];

    /** 98 · 1.3b: Quelle {@see PlanungOptionen::SEKTOR_OPTIONEN} — hier nur noch für Blade/Komponente gespiegelt. */
    public const SEKTOR_OPTIONEN = PlanungOptionen::SEKTOR_OPTIONEN;

    /** 98 · 1.3b: Quelle {@see PlanungOptionen::MANDATORY_LEITPLANKEN} — hier nur noch für Blade/Komponente gespiegelt. */
    public const MANDATORY_LEITPLANKEN = PlanungOptionen::MANDATORY_LEITPLANKEN;

    /** 98 · 1.3b: Quelle {@see PlanungOptionen::AGENT_SCHREIBBARE_REGLER} — hier nur noch für Blade/Komponente gespiegelt. */
    public const AGENT_SCHREIBBARE_REGLER = PlanungOptionen::AGENT_SCHREIBBARE_REGLER;

    /**
     * Frische-Erlaubnis-Liste (L1.5, Multi-Select): UI-Slug => Label. Nichts angehakt = egal (kein
     * Zustands-Filter). Angehakt = harte Erlaubnis auf `gps.condition` (raw-Werte §9, seit v3.6 auch die drei
     * Behandlungen). Deckt endlich »trocken« ab (§9, 1.301 GPs) — im Gegensatz zum alten 3-Wert-Hook.
     */
    public const FRISCHE_OPTIONEN = [
        'frisch' => 'Frisch', 'tk' => 'TK', 'trocken' => 'Trocken', 'konserve' => 'Konserve/haltbar', 'behandelt' => 'Gepökelt/geräuchert/luftgetrocknet',
    ];

    /** UI-Slug => roher `gps.condition`-Wert bzw. Werte (für den Post-Match-Zustands-Filter, spiegelt §9). @var array<string, string|list<string>> */
    public const FRISCHE_CONDITION = [
        'frisch' => 'frisch', 'tk' => 'TK', 'trocken' => 'trocken', 'konserve' => 'konserviert', 'behandelt' => \Platform\FoodAlchemist\Services\GpNamingService::BEHANDLUNG,
    ];

    /**
     * Aroma-Küchen (L1.5, Achse 4 aus _Entscheidungsachsen.md v1.9): Slug => Label. »Frei« (leer) =
     * KI wählt zur Beschreibung. Jede Küche trägt zusätzlich Würz-Anker/Technik/Archetyp in den
     * Prompt (RecipeGenerationContextService::aromaKuecheBlock) — verbindliches Regelwerk, keine Erfindung.
     */
    public const AROMA_KUECHEN = [
        '' => 'Frei (KI wählt)',
        'klassisch_de' => 'Klassisch DE', 'franzoesisch' => 'Französisch', 'mediterran' => 'Mediterran',
        'italienisch' => 'Italienisch', 'asiatisch' => 'Asiatisch (allg.)', 'japanisch' => 'Japanisch',
        'thai' => 'Thai', 'indisch' => 'Indisch', 'orient' => 'Orient', 'lateinamerika' => 'Lateinamerika',
        'neu_nordisch' => 'Neu-Nordisch',
    ];

    /** 98 · 1.3b: Quelle {@see PlanungOptionen::MENGE_EINHEITEN} — hier nur noch für Blade/Komponente gespiegelt. */
    public const MENGE_EINHEITEN = PlanungOptionen::MENGE_EINHEITEN;

    public ?string $meldung = null;

    /**
     * Was der Lauf NICHT erzeugt hat — aus `cascade_runs.deckel_hinweise`. `null` = kein Deckel
     * gegriffen; ein Nichts wird nicht als Warnung verkauft.
     *
     * Gefüllt aus dem LAUF, nicht per Flash: der Deckel ist eine Eigenschaft des Laufs und muss
     * einen Reload und ein Zu-und-wieder-Aufklappen überleben. Ein Deckel, der erst später in
     * einem Job greift (Concept-Slots), erreicht dieselbe Zeile über den Poll.
     */
    public ?string $deckelHinweis = null;

    /** Aktiver Kaskaden-Lauf (in-place „Go") — beobachtet von {@see LaufStatus}. */
    public ?int $laufId = null;

    /**
     * Spec 85 C1 PR-3: Lauf-Steuerung, Gate 2, Poll und Foto-Picker leben im Kind {@see KaskadenLauf}. Der Parent hält
     * `laufId`/`laeuft` als schreibgeschützte Kopie (Reiter-Badge, Sperren im Brief) und zeigt Meldungen des Kinds im Modal-Kopf.
     */
    public ?string $margenWarnung = null;

    /** 2.1d: aktiver Editor-Reiter (Alpine `tab`, per $watch gemeldet) — nur seine Daten werden geladen. */
    public string $editorTab = 'gericht';

    /** true, solange der Lauf im Hintergrund rechnet (steuert das Polling). */
    public bool $laeuft = false;

    /**
     * Geplanter Pfad (Etappe 2b, „KI-Kopf"): die ID des vorab ausgearbeiteten, im Conceptor geprüften
     * Draft-Concepts ({@see kiKopf} → {@see ConceptGeneratorService::planAusBrief}). Gesetzt = der nächste
     * Concept-Go referenziert diesen Plan (`existing_concept_id`, KEIN neuer GenerateConceptJob), statt neu
     * zu generieren. `null` = Schnell-Pfad (Brief → Go generiert frisch). **Transient** (keine DB-Spalte) —
     * lebt nur, solange die Fläche gemountet ist; nach dem Go verbraucht ({@see goKaskade} setzt zurück).
     */
    public ?int $planConceptId = null;

    /**
     * Editierbare Semantik-Skalare des vorbereiteten KI-Kopf-Plans (Leitidee/Name+Claim/USP/Inszenierung/
     * intern) — INLINE in der Leitstelle sichtbar + änderbar (statt Wegsprung in den Conceptor). Geladen
     * aus der `concept.plan`-Canvas ({@see ladePlanForm}); {@see planFeldSpeichern} schreibt zurück. Diese
     * Felder SIND der LLM-Kontext für die Concept-/Gericht-Erzeugung (fließen beim Go mit) — „ein Ort, ein
     * Kontext". Geschmackswelten + die Menü-Positionen bleiben Lese-Anzeige ({@see planVorschau}).
     *
     * @var array<string,string>
     */
    public array $planForm = [];

    /** Deep-Link `?session=X&open=1` (z.B. vom Trendradar-Carry-in) öffnet den Editor direkt. */
    public function mount(): void
    {
        // Per-Tab-State initialisieren — jeder Scope eigene Eingabe + eigener Leitplanken-Satz.
        foreach (self::SCOPES as $s) {
            $this->eingabe[$s] = ['titel' => '', 'brief' => '', 'creative_mode' => 'voll_kreativ',
                'suchbegriffe' => [], 'suchbegriff_neu' => ''];
            $this->regler[$s] = self::REGLER_DEFAULT;
        }
        if (request()->boolean('open') && $this->sessionId !== null && $this->aktiveSession() !== null) {
            $this->ladeForm();
            // Der Speiseplan-Editor leitet nach dem Go HIERHER um (mit `open=1`), und seine eigene
            // Komponente stirbt mit dem Redirect — ein dort gesetzter Hinweis wäre nie gerendert.
            // Ohne dieses Laden bliebe die Landung also stumm, obwohl der Deckel am Lauf steht.
            $this->ladeLetztenLauf();
            // Spec 53/D: Sprachbefehl-Handoff (VoiceModal::planungStarten()) — `?tab=` sagt, auf
            // welchem Scope-Tab der Editor öffnet (Scope-Treue wie beim Composer-Übernehmen-Knopf
            // weiter unten); ohne bekannten Wert bleibt tabInit vom Modal (Default 'gericht').
            // Die Session trägt bereits den scope-spezifischen Brief (PlanningSessionService::create
            // im Voice-Pfad) — hier nur ins Tab-Briefing vorbefüllen, wenn dort noch nichts steht.
            $tab = in_array(request('tab'), ['basisrezept', 'gericht', 'concept'], true) ? request('tab') : null;
            if ($tab !== null) {
                $scope = $tab === 'basisrezept' ? 'rezept' : $tab;
                $brief = trim((string) $this->aktiveSession()?->brief);
                if ($brief !== '' && trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
                    $this->eingabe[$scope]['brief'] = $brief;
                }
            }
            $this->oeffneEditor($tab);
        }
        // Spec 42 F2 — Handoff aus dem Foodbook: Leitstelle im Owner-Kontext öffnen, „Foodbook aus
        // Brief"-Panel für DIESES bestehende Foodbook vorgeklappt (kein neues Foodbook anlegen).
        if (request()->filled('fb_owner')) {
            $this->fbOwnerId = (int) request('fb_owner');
            $this->fbPanelAuf = true;
        }
        // Spec 42 (Speisekarte-Parität) — Handoff aus dem Speisekarte-Modul: Leitstelle im Owner-Kontext
        // öffnen, „Speisekarte aus Brief"-Panel vorgeklappt + jüngste Session dieser Karte reaktivieren
        // (weiterplanen statt neu). Der Aufruf ist no-op, wenn es noch keinen Lauf zur Karte gibt.
        if (request()->filled('sk_owner')) {
            $this->skOwnerId = (int) request('sk_owner');
            $this->skPanelAuf = true;
            $this->aktiviereOwnerSession('speisekarte', $this->skOwnerId);
        }
        // #5 (2026-08-28) — Handoff aus dem Angebots-Modul (owner_type=offer).
        if (request()->filled('offer_owner')) {
            $this->offerOwnerId = (int) request('offer_owner');
            $this->offerPanelAuf = true;
            $this->aktiviereOwnerSession('offer', $this->offerOwnerId);
        }
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    // ── Session-Lifecycle ──────────────────────────────────────────────

    public function neuePlanung(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Planung kann nicht angelegt werden.';

            return;
        }
        // „+" ohne Titel legt trotzdem eine Planung an (Default = Placeholder) und öffnet sie
        // sofort — sonst reagiert der Button bei leerem Feld still gar nicht (Bug 2026-08-03).
        // Umbenennen geht danach im Editor-Kopf (form.title).
        $titel = trim($this->neuTitel) !== '' ? trim($this->neuTitel) : 'Neue Planung';
        $session = $svc->create($team, ['title' => $titel, 'created_via' => 'ui']);
        $this->neuTitel = '';
        $this->fehler = null;
        $this->oeffne($session->id);
    }

    /**
     * Freie 1-Klick-Erstellung (Leitstelle, de-trend): legt eine leichte Session an
     * (created_via=cockpit_frei, kein Trend) und öffnet den Editor direkt auf dem Planung-Tab,
     * wo die Regler-Leitplanken + der Go liegen. Ein Klick bis zum Regler — kein Trend-Umweg.
     */
    public function schnellErstellen(string $scope, PlanningSessionService $svc): void
    {
        if (! in_array($scope, ['rezept', 'gericht', 'concept'], true)) {
            return;
        }
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        // Platzhalter (PlanningSessionService::PLATZHALTER_TITEL) — wird beim ersten Ergebnis durch dessen Namen ersetzt.
        $titel = match ($scope) {
            'gericht' => 'Freies Gericht',
            'concept' => 'Freies Konzept',
            default => 'Freies Basisrezept',
        };
        $session = $svc->create($team, ['title' => $titel, 'created_via' => 'cockpit_frei']);
        $this->fehler = null;
        // Scope-Treue (Etappe 1): der Frei-Start setzt die Ebene korrekt — Basisrezept-Scope öffnet den
        // Basisrezept-Tab (nicht den Gericht-Tab). scope 'rezept' → Tab-Key 'basisrezept'; sonst = scope-Key.
        $startTab = $scope === 'rezept' ? 'basisrezept' : $scope;
        $this->oeffne($session->id, $startTab);
    }

    /** Board-Einstieg: leere Session anlegen + Editor direkt auf dem Import-Tab öffnen (bestehende Rezeptur einfügen). */
    public function schnellImport(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        $session = $svc->create($team, ['title' => 'Rezept-Import', 'created_via' => 'cockpit_import']);
        $this->fehler = null;
        $this->oeffne($session->id, 'import');
    }

    /** Spec 79 · Board-Einstieg „Aus dem Trendradar": leere Session + Editor auf dem Trendradar-Reiter. */
    public function schnellTrendradar(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        $session = $svc->create($team, ['title' => 'Aus dem Trendradar', 'created_via' => 'cockpit_trendradar']);
        $this->fehler = null;
        $this->oeffne($session->id, 'trendradar');
    }

    /**
     * Spec 79 · das Kind {@see Trendradar} hat die gewählten Impulse in die Session geschrieben (Spec 85 C1 PR-2). Hier:
     * das Briefing der Ziel-Ebene ÜBERSCHREIBEN (ausdrücklicher Klick), die anderen Ebenen nur füllen, wenn leer.
     * Danach Wechsel auf den Tab — gestartet wird wie immer per Go (nichts läuft still). Am Handy gesperrt wie der Kombinierer.
     */
    #[On('planung.trend-uebernommen')]
    public function trendUebernommen(string $scope): void
    {
        $session = in_array($scope, self::SCOPES, true) ? $this->aktiveSession() : null;
        if ($session === null) {
            return;
        }
        $this->form['title'] = (string) $session->title;
        $this->form['brief'] = (string) $session->brief;
        $this->form['analysis'] = (string) $session->analysis;
        $this->eingabe[$scope]['brief'] = PlanningSessionService::briefFuerScope((string) $session->brief, $scope);
        $this->eingabe[$scope]['titel'] = (string) $session->title;
        $this->seedBriefingAusTrendSession($session);
        $this->oeffneEditor($scope === 'rezept' ? 'basisrezept' : $scope);
    }

    /** Board-Einstieg: leere Session anlegen + Editor direkt auf dem Composer-Tab öffnen (Foodpairing). */
    public function schnellComposer(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        $session = $svc->create($team, ['title' => 'Composer', 'created_via' => 'cockpit_composer']);
        $this->fehler = null;
        $this->oeffne($session->id, 'composer');
    }

    /** Foodbook-aus-Brief-Eingabe (Spec 42 F1): Rahmen wird in der Leitstelle geplant, nicht im Modul. */
    public string $fbTitel = '';

    public string $fbBrief = '';

    public ?string $fbMeldung = null;

    /** Gesetzt beim Handoff aus einem bestehenden Foodbook (Spec 42 F2): dann kein neues Foodbook anlegen. */
    public ?int $fbOwnerId = null;

    /** Panel im Landing sofort aufklappen (Handoff aus dem Modul). */
    public bool $fbPanelAuf = false;

    /** Speisekarte-aus-Brief-Eingabe (Spec-42-Vollzug Stufe 1) — spiegelt fb*, owner_type=speisekarte. */
    public string $skTitel = '';

    public string $skBrief = '';

    public ?string $skMeldung = null;

    /** Handoff aus einer bestehenden Speisekarte: dann keine neue Karte anlegen. */
    public ?int $skOwnerId = null;

    public bool $skPanelAuf = false;

    /** Speiseplan-aus-Brief-Eingabe (Spec-42-Vollzug Stufe 4) — spiegelt sk*, owner_type=speiseplan. */
    public string $spTitel = '';

    public string $spBrief = '';

    public ?string $spMeldung = null;

    /** Handoff aus einem bestehenden Speiseplan: dann keinen neuen anlegen. */
    public ?int $spOwnerId = null;

    public bool $spPanelAuf = false;

    /** #5 (2026-08-28): Angebot-aus-Brief-Eingabe — spiegelt sk*, owner_type=offer (Andock + Kaskade existieren). */
    public string $offerTitel = '';

    public string $offerBrief = '';

    public ?string $offerMeldung = null;

    /** Handoff aus einem bestehenden Angebot: dann kein neues anlegen. */
    public ?int $offerOwnerId = null;

    public bool $offerPanelAuf = false;

    /** Format-aus-Brief-Eingabe (Kickoff-Tab) — owner_type=format (Kaskade/Gerüst/Dock existieren via MCP-Pipeline). */
    public string $fmtTitel = '';

    public string $fmtBrief = '';

    public ?string $fmtMeldung = null;

    /** Handoff aus einem bestehenden Format: dann kein neues anlegen. */
    public ?int $fmtOwnerId = null;

    public bool $fmtPanelAuf = false;

    /** #5: Angebot-Auswähler — bestehendes Angebot wählen → Owner + jüngste Session (spiegelt updatedSkOwnerId). */
    public function updatedOfferOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->offerOwnerId === null) {
            return;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($this->offerOwnerId);
        if ($angebot === null) {
            $this->offerOwnerId = null;

            return;
        }
        $this->aktiviereOwnerSession('offer', $this->offerOwnerId);
    }

    /** Format-Auswähler — bestehendes Format wählen → Owner + jüngste Session (spiegelt updatedOfferOwnerId). */
    public function updatedFmtOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->fmtOwnerId === null) {
            return;
        }
        $format = FoodAlchemistFormat::visibleToTeam($team)->find($this->fmtOwnerId);
        if ($format === null) {
            $this->fmtOwnerId = null;

            return;
        }
        $this->aktiviereOwnerSession('format', $this->fmtOwnerId);
    }

    /**
     * Stage 2 (Leitstelle-Auswähler, 2026-08-23): ein BESTEHENDES Foodbook zum Planen wählen. Setzt den
     * Owner-Kontext (fbOwnerId) → Buch-Ebene + Kapitel-Steuerung erscheinen; die jüngste Planungs-Session
     * dieses Foodbooks wird reaktiviert (Owner-Banner + Kaskaden-Cockpit). „Gerüst planen" (Brief) und
     * „Kapitel erzeugen" laufen danach wie gehabt. Deselect (leer) nimmt nur die Auswahl zurück.
     */
    public function updatedFbOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->fbOwnerId === null) {
            return;
        }
        $fb = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->find($this->fbOwnerId);
        if ($fb === null) {
            $this->fbOwnerId = null;

            return;
        }
        $this->aktiviereOwnerSession('foodbook', $this->fbOwnerId);
    }

    /** Speisekarte-Auswähler (Stage 2, SK/SP-Parität): bestehende Speisekarte wählen → Owner + jüngste Session. */
    public function updatedSkOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->skOwnerId === null) {
            return;
        }
        $karte = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::visibleToTeam($team)->find($this->skOwnerId);
        if ($karte === null) {
            $this->skOwnerId = null;

            return;
        }
        $this->aktiviereOwnerSession('speisekarte', $this->skOwnerId);
    }

    /** Speiseplan-Auswähler (Stage 2, SK/SP-Parität): bestehenden Speiseplan wählen → Owner + jüngste Session. */
    public function updatedSpOwnerId(): void
    {
        $team = $this->team();
        if ($team === null || $this->spOwnerId === null) {
            return;
        }
        $plan = \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::visibleToTeam($team)->find($this->spOwnerId);
        if ($plan === null) {
            $this->spOwnerId = null;

            return;
        }
        $this->aktiviereOwnerSession('speiseplan', $this->spOwnerId);
    }

    /** Reaktiviert die jüngste Planungs-Session eines Ausgabe-Owners (Banner/Kaskaden-Cockpit). */
    private function aktiviereOwnerSession(string $ownerType, int $ownerId): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $run = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->where('source_owner_type', $ownerType)->where('source_owner_id', $ownerId)
            ->whereNotNull('planning_session_id')
            ->orderByDesc('id')->first();
        if ($run !== null) {
            $this->sessionId = (int) $run->planning_session_id;
        }
    }

    /**
     * F1 (Spec 42) — „Foodbook aus Brief" IN der Leitstelle: Shell anlegen → Gerüst aus Brief →
     * Struktur anwenden → Session + Voll-Kaskade (owner_type=foodbook). Spiegelt den bisherigen
     * Foodbook-Kickoff (`Foodbooks/Index::frameAusBriefVorschlagen` + `strukturAnwenden` +
     * `vollKaskadeStarten`), aber der Rahmen entsteht jetzt hier — das Foodbook wird zur reinen
     * Ausgabe. Ergebnis dockt via `attachToOutput` automatisch ins Foodbook zurück; der Owner-Banner
     * (Spec 40 E1b) leuchtet, sobald der Lauf `source_owner_type='foodbook'` trägt.
     */
    public function foodbookAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->fbMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->fbMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->fbBrief) === '') {
            $this->fbMeldung = 'Bitte einen Brief eingeben (Anlass, Gäste, Saison, Niveau, Budget …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->foodbookAusBrief($team, $this->fbBrief, [
                'owner_id' => $this->fbOwnerId,
                'titel' => $this->fbTitel,
                'created_via' => 'leitstelle_foodbook_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->fbTitel = '';
            $this->fbBrief = '';
            $this->fbOwnerId = null;
            $this->fbPanelAuf = false;
            $this->oeffne($r['session']->id, 'worker');
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->fbMeldung = $e->getMessage();
        }
    }

    /**
     * Spec-42-Vollzug Stufe 1 — „Speisekarte aus Brief" IN der Leitstelle. Spiegelt {@see foodbookAusBrief},
     * aber owner_type=speisekarte: die „aus Brief"-Kette ist owner-generisch (der Gerüst-Prompt ist
     * owner-neutral), und der Container wird pro Slot als Rubrik angelegt — DAHER KEIN `strukturAusGeruest`:
     * `PlanningCascadeService::vollkaskadeSlots` ruft `SpeisekarteService::rubrikFuerSlot` inline im Fan-out.
     * Ergebnis dockt via `GenerateConceptJob::attachEinmal` (speisekarte→`addPosition(menue_ref)`) zurück.
     */
    public function speisekarteAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->skMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->skMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->skBrief) === '') {
            $this->skMeldung = 'Bitte einen Brief eingeben (Anlass, Gäste, Saison, Niveau, Budget …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->speisekarteAusBrief($team, $this->skBrief, [
                'owner_id' => $this->skOwnerId,
                'titel' => $this->skTitel,
                'created_via' => 'leitstelle_speisekarte_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->skTitel = '';
            $this->skBrief = '';
            $this->skOwnerId = null;
            $this->skPanelAuf = false;
            $this->oeffne($r['session']->id, 'worker');
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->skMeldung = $e->getMessage();
        }
    }

    /**
     * Spec-42-Vollzug Stufe 4 — „Speiseplan aus Brief" IN der Leitstelle. Anders als Foodbook/Speisekarte
     * braucht der Speiseplan kein Gänge-Gerüst: {@see SpeiseplanService::create} legt die GV-Standard-Linien
     * (Menü 1 / Vegetarisch / Dessert) + Zyklus (cycle_weeks) + Start-Montag an. Der Zyklus wird — falls im
     * Brief genannt („4 Wochen") — übernommen. Danach startet die Grid-Voll-Kaskade
     * ({@see PlanningCascadeService::starteSpeiseplanVollkaskade}): je leerer Zelle (Tag × Mahlzeit × Linie)
     * ein Gericht, das den Brief-Kontext der Session erbt. Linien bleiben im Speiseplan-Editor frei änderbar.
     */
    public function speiseplanAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->spMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->spMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->spBrief) === '') {
            $this->spMeldung = 'Bitte einen Brief eingeben (Anlass, Saison, Linien, Zyklus …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->speiseplanAusBrief($team, $this->spBrief, [
                'owner_id' => $this->spOwnerId,
                'titel' => $this->spTitel,
                'created_via' => 'leitstelle_speiseplan_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->spTitel = '';
            $this->spBrief = '';
            $this->spOwnerId = null;
            $this->spPanelAuf = false;
            $this->oeffne($r['session']->id, 'worker');
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->spMeldung = $e->getMessage();
        }
    }

    /**
     * #5 (Dominique 2026-08-28) — „Angebot aus Brief" IN der Leitstelle. Spiegelt {@see speisekarteAusBrief},
     * owner_type=offer: der Gerüst-Prompt ist owner-neutral, die Voll-Kaskade erzeugt je Slot ein Konzept,
     * das via `GenerateConceptJob`-Andock direkt ans Angebot referenziert wird (Pivot foodalchemist_offer_concept;
     * KEIN Zwischen-Container wie Kapitel/Rubrik — containerId = Angebots-ID). Andock + Kaskade existieren bereits;
     * hier fehlte nur der Leitstellen-Einstieg.
     */
    public function angebotAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->offerMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->offerMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->offerBrief) === '') {
            $this->offerMeldung = 'Bitte einen Brief eingeben (Anlass, Gäste, Saison, Niveau, Budget …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->angebotAusBrief($team, $this->offerBrief, [
                'owner_id' => $this->offerOwnerId,
                'titel' => $this->offerTitel,
                'created_via' => 'leitstelle_offer_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->offerTitel = '';
            $this->offerBrief = '';
            $this->offerOwnerId = null;
            $this->offerPanelAuf = false;
            $this->oeffne($r['session']->id, 'worker');
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->offerMeldung = $e->getMessage();
        }
    }

    /**
     * Format aus Brief (Kickoff-Tab) — Leitstellen-Einstieg zum bestehenden MCP-Pfad
     * (FormatPlanFromBriefTool). owner_type=format: Marken-Identität + eigenständige Concepte je Slot,
     * die automatisch ans Format docken (Kaskade/Gerüst/Dock kennen 'format' bereits).
     */
    public function formatAusBrief(PlanungsEinstiegService $einstieg): void
    {
        $this->fmtMeldung = null;
        $team = $this->team();
        if ($team === null) {
            $this->fmtMeldung = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        if (trim($this->fmtBrief) === '') {
            $this->fmtMeldung = 'Bitte einen Brief eingeben (Marke, Anlass, Ausrichtung, Niveau …).';

            return;
        }

        try {
            // 98 · 1.3d: derselbe Use-Case wie das MCP-Tool — Hülle (bestehend+eigen oder neu), Gerüst, Session, Voll-Kaskade.
            $r = $einstieg->formatAusBrief($team, $this->fmtBrief, [
                'owner_id' => $this->fmtOwnerId,
                'titel' => $this->fmtTitel,
                'created_via' => 'leitstelle_format_brief',
            ]);

            // Eingabe leeren + Worker-Cockpit öffnen (Owner-Banner + Round-Trip greifen automatisch).
            $this->fmtTitel = '';
            $this->fmtBrief = '';
            $this->fmtOwnerId = null;
            $this->fmtPanelAuf = false;
            $this->oeffne($r['session']->id, 'worker');
        } catch (\Throwable $e) {
            // LLM nicht verfügbar/deaktiviert, geerbte Hülle o.ä. → Meldung statt 500. Eine ggf. schon angelegte leere
            // Hülle bleibt in der Liste (verwerfbar) — bewusst kein Rollback, die Kaskaden-Jobs sind nicht transaktional.
            $this->fmtMeldung = $e->getMessage();
        }
    }

    public function oeffne(int $id, ?string $startTab = null): void
    {
        $this->sessionId = $id;
        $this->fehler = null;
        $this->meldung = null;
        $this->ladeForm();
        $this->ladeLetztenLauf();
        // Scope-Treue: ein „Freies Basisrezept"-Start öffnet direkt auf dem Basisrezept-Tab (Ebene ≠ Gericht).
        // Ohne $startTab: hat die Planung schon einen Lauf (Ergebnisse/Fortschritt), öffnet sie auf „Fortschritt"
        // (Dominique 2026-10-05) — sonst auf dem Editor-Default (tabInit='gericht').
        $this->oeffneEditor($startTab ?? ($this->laufId !== null ? 'worker' : null));
    }

    /** Editor öffnen; der Start-Reiter ist zugleich der aktive Reiter für die Daten (2.1d). null = Modal-Default „gericht". */
    private function oeffneEditor(?string $tab): void
    {
        $this->editorTab = $tab ?? 'gericht';
        $this->dispatch('modal.open', name: 'planung-editor', tab: $tab);
    }

    public function waehle(int $id): void
    {
        $this->sessionId = $id;
        $this->ladeLetztenLauf();
    }

    /**
     * S3a: die Kapitel-Steuerung (KapitelRail) hat einen per-Kapitel-Kaskaden-Teil-Lauf gestartet →
     * jüngsten Lauf neu laden, damit der Worker-Tab ihn zeigt.
     */
    #[\Livewire\Attributes\On('kaskade-gestartet')]
    public function kaskadeGestartet(): void
    {
        $this->ladeLetztenLauf();
    }

    /**
     * Spec 55 (Design-Punkt b): „Übernehmen"-Klick eines `komponenten_uebernahme`-Vorschlags im
     * eingebetteten Sprachbefehl-Panel ({@see \Platform\FoodAlchemist\Livewire\VoiceModal::komponentenUebernehmen()}) —
     * schreibt DIREKT in die Regler/den Brief DIESER Komponente (kein MCP-Tool, kein Katalog-
     * Wachstum). Whitelist der Leitplanken-Schlüssel schon in VoiceCommandService gefiltert,
     * hier NOCHMAL geprüft (Browser-Events sind kein vertrauenswürdiger Server-Kanal).
     *
     * @param  array<string, mixed>  $felder
     */
    #[\Livewire\Attributes\On('voice.komponenten-uebernahme')]
    public function voiceKomponentenUebernehmen(string $scope, array $felder, ?string $brief = null): void
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return;
        }
        $erlaubt = array_intersect_key($felder, array_flip(self::AGENT_SCHREIBBARE_REGLER));
        foreach ($erlaubt as $feld => $wert) {
            $this->regler[$scope][$feld] = $wert;
            $this->reglerVonAgent[$scope][$feld] = true;
        }
        if ($brief !== null && trim($brief) !== '') {
            $this->eingabe[$scope]['brief'] = trim($brief);
            $this->reglerVonAgent[$scope]['brief'] = true;
        }
        $this->meldung = 'Vorschlag des Sprachbefehl-Agenten übernommen.';
    }

    /**
     * Zuletzt-Karte: Planung verwerfen (Soft-Delete, reversibel; finale Etappe #17). Team-owned (D1)
     * über den Service. War die verworfene Session gerade aktiv, wird der Editor-/Lauf-Kontext gelöst.
     */
    public function planungVerwerfen(
        int $id,
        PlanningSessionService $svc,
        PlanningCascadeService $cascade,
    ): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            // Verwerfen ist zugleich der sichere Stop fuer alle Queue-Arbeit dieser Planung.
            // Wichtig: VOR dem Soft-Delete, solange Session und Laeufe noch eindeutig aufloesbar sind.
            $abgebrochen = $cascade->brecheSessionLaeufeAb($team, $id);
            $svc->verwerfen($team, $id);
            if ((int) $this->sessionId === $id) {
                $this->sessionId = null;
                $this->laufId = null;
                $this->laeuft = false;
            }
            $this->meldung = $abgebrochen > 0
                ? "Planung verworfen und {$abgebrochen} laufende Generierung(en) gestoppt."
                : 'Planung verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Verwerfen nicht möglich: ' . $e->getMessage();
        }
    }

    /**
     * Zuletzt-Karte: Planung duplizieren (frischer team-eigener Entwurf; finale Etappe #17). Team-owned
     * (D1) über den Service. Wählt die Kopie NICHT automatisch aus — sie erscheint in der Liste.
     */
    public function planungDuplizieren(int $id, PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $kopie = $svc->duplizieren($team, $id);
            $this->meldung = 'Planung dupliziert — „' . $kopie->title . '".';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Duplizieren nicht möglich: ' . $e->getMessage();
        }
    }

    /** Beim Öffnen/Wählen den letzten Kaskaden-Lauf laden — läuft er noch, wird das Polling fortgesetzt. */
    /**
     * Spec 85 C1 PR-1: der Rezept-Import ist ein eigenes Kind ({@see RezeptImport}). Nach der Anlage
     * übergibt es an den Worker und meldet sich hier — der Parent zieht den neuen Import-Lauf nach, damit
     * der Worker-Reiter ihn zeigt (früher direkt `$this->ladeLetztenLauf()` im Import-Code).
     */
    #[On('planung.import-angelegt')]
    public function importAngelegt(): void
    {
        $this->ladeLetztenLauf();
    }

    /** 2.1d: {@see LaufStatus} meldet einen geänderten Stand. Das Kind KaskadenLauf liest den Lauf; hier zeichnet sich nur das Board neu. */
    #[On('planung.lauf-geaendert')]
    public function laufGeaendert(): void {}

    /** Sicherer Abbruch eines einzelnen Planungs-Laufs (Board-Knopf je laufendem Lauf); der globale Queue-Worker bleibt aktiv. */
    public function laufAbbrechen(int $runId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        if ($cascade->brecheLaufAb($team, $runId)) {
            $this->laeuft = false;   // Kopie; das Kind liest den neuen Stand über laufGeaendert
            $this->meldung = 'Planung abgebrochen — es werden keine weiteren Kaskaden-Schritte gestartet.';
            $this->fehler = null;
        } else {
            $this->meldung = 'Der Lauf war bereits beendet oder ist nicht mehr abbrechbar.';
        }
    }

    /** Spec 85 C1 PR-3: das Kind meldet Lauf-Stand (schreibgeschützte Kopie für Reiter-Badge und Sperren). */
    #[On('planung.lauf-status')]
    public function laufStatus(?int $laufId, bool $laeuft): void
    {
        if ($laufId === $this->laufId) {
            $this->laeuft = $laeuft;
        }
    }

    /** Spec 85 C1 PR-3: Meldungen des Kinds erscheinen wie bisher im Modal-Kopf (art: meldung|fehler|margen|deckel). */
    #[On('planung.meldung')]
    public function kindMeldung(?string $text, string $art = 'meldung'): void
    {
        match ($art) {
            'fehler' => $this->fehler = $text,
            'margen' => $this->margenWarnung = $text,
            'deckel' => $this->deckelHinweis = $text,
            default => $this->meldung = $text,
        };
    }

    private function ladeLetztenLauf(): void
    {
        $team = $this->team();
        if ($team === null || $this->sessionId === null) {
            $this->laufId = null;
            $this->laeuft = false;
            $this->deckelHinweis = null;   // sonst bliebe der Hinweis eines FREMDEN Laufs stehen

            return;
        }
        $lauf = app(PlanningCascadeService::class)->letzterLauf($team, $this->sessionId);
        $this->laufId = $lauf?->id;
        $this->laeuft = $lauf !== null && $lauf->status === 'running';
        $this->deckelHinweis = $this->deckelHinweisText($lauf);
    }

    /**
     * Der Deckel-Satz des Laufs für die Fläche. Reine Weiterleitung an
     * {@see FoodAlchemistCascadeRun::deckelMeldung()} — der Wortlaut gehört dorthin, wo der
     * Deckel greift, nicht in die Anzeige.
     */
    private function deckelHinweisText(?FoodAlchemistCascadeRun $lauf): ?string
    {
        return $lauf?->deckelMeldung();
    }

    private function ladeForm(): void
    {
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        $this->form = [
            'title' => (string) $session->title,
            'brief' => (string) $session->brief,
            'analysis' => (string) $session->analysis,
            'creative_mode' => (string) $session->creative_mode,
        ];

        // Spec 80: gespeicherte Suchbegriffe je Tab zurückholen (nur Tabs, die welche haben).
        foreach ((is_array($session->suchbegriffe) ? $session->suchbegriffe : []) as $sc => $liste) {
            if (isset($this->eingabe[$sc]) && is_array($liste)) {
                $this->eingabe[$sc]['suchbegriffe'] = array_values(array_filter($liste, static fn ($b) => is_array($b) && isset($b['t'])));
            }
        }

        // Trendradar-Anbindung (Spec 79): eine aus dem Trendradar eröffnete Session (`source_trend_refs`,
        // Carry-in via `?open=1`) füllt ihren Brief ins Tab-Briefing je Ebene vor (nur leere Felder) —
        // der Nutzer startet mit einem gefüllten Briefing statt Blank Page.
        if (! empty($session->source_trend_refs)) {
            $this->seedBriefingAusTrendSession($session);
        }
        // Spec 79: die Auswahl im Trendradar-Reiter stellt das Kind Planung\Trendradar beim Mount aus der Herkunft wieder her.

        // #53 Persistenz: einen vorbereiteten KI-Kopf-Plan (plan_concept_id) über den Reload retten —
        // aber nur, wenn das Draft-Concept noch existiert + team-eigen ist (sonst still auf null; ein
        // toter/verwaister Zeiger darf den Concept-Go nicht auf einen Geister-Plan schicken). Spiegelt
        // die Fail-soft-Ownership-Prüfung in goKaskade.
        $this->planConceptId = null;
        $team = $this->team();
        if ($team !== null && $session->plan_concept_id !== null
            && FoodAlchemistConcept::where('team_id', $team->id)->whereKey($session->plan_concept_id)->exists()) {
            $this->planConceptId = (int) $session->plan_concept_id;
        }
        // Inline-Plan-Panel: die editierbaren Semantik-Felder aus der Canvas nachziehen (leert, wenn kein Plan).
        $this->ladePlanForm();

        // L5: die beim Go persistierten Leitplanken (generation_params) in die Regler zurücklesen — sonst
        // zeigt jeder Tab nach einem Reload wieder die Defaults, während der Lauf mit anderen Werten fuhr.
        $this->rehydriereReglerAusParams($session);
    }

    private function rehydriereReglerAusParams(FoodAlchemistPlanningSession $session): void
    {
        $h = $this->reglerHelfer();
        $h->rehydriereReglerAusParams($session);
        $this->regler = $h->regler;   // Spec 84 C1-1: Logik in ReglerParams, der neue Stand kommt zurück
    }

    /**
     * Trend-Herkunft → Go-Briefing: den Brief/Titel einer Trend-Session in alle Tab-Briefings
     * ({@see SCOPES}) übertragen. **Nur befüllen, nie überschreiben** — ein bereits (vom Nutzer
     * oder einer Vorlage) getipptes Tab-Briefing bleibt unangetastet. Der Session-Brief ist bewusst
     * scope-agnostisch formuliert (die Session hat keine Ebene); beim Übertragen wird der Lead je Tab
     * ebenen-spezifisch geschärft ({@see PlanningSessionService::briefFuerScope} — Basisrezept/Gericht/
     * Konzept), Einordnung/Kernaussage bleiben unverändert. Kein „Go" — nur Prefill (Nordstern: nichts
     * läuft still).
     */
    private function seedBriefingAusTrendSession(FoodAlchemistPlanningSession $session): void
    {
        $brief = trim((string) $session->brief);
        $titel = trim((string) $session->title);
        if ($brief === '') {
            return;
        }
        foreach (self::SCOPES as $scope) {
            if (trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
                $this->eingabe[$scope]['brief'] = PlanningSessionService::briefFuerScope($brief, $scope);
            }
            if ($titel !== '' && trim((string) ($this->eingabe[$scope]['titel'] ?? '')) === '') {
                $this->eingabe[$scope]['titel'] = $titel;
            }
        }
    }

    public function speichern(PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        $svc->update($team, $session->id, [
            'title' => $this->form['title'] ?? '',
            'brief' => $this->form['brief'] ?? null,
            'analysis' => $this->form['analysis'] ?? null,
        ]);
        if (in_array($this->form['creative_mode'] ?? null, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $svc->setCreativeMode($team, $session->id, $this->form['creative_mode']);
        }
        $this->meldung = 'Gespeichert.';
    }

    // ── Leitstelle: Regler-Bedienung ───────────────────────────────────

    /** Multi-Select-Regler (Toggle in/aus der Liste): Diät + Frische-Erlaubnis + Allergen-No-Go. */
    private const MULTI_REGLER = ['diaet_hart', 'frische', 'allergen_nogo'];

    /**
     * EU-14-Allergen-No-Go (L3): Key => Label. Harter Ausschluss (getrennt vom Diät-Ausschluss) —
     * ein verdrahteter GP, der ein angehaktes Allergen »enthält«, wird im Diät-/Allergen-Gate
     * entdrahtet + gemeldet. Keys spiegeln FoodAlchemistGp::ALLERGEN_FIELDS 1:1.
     */
    public const ALLERGEN_LABELS = \Platform\FoodAlchemist\Support\Allergene::KURZ;

    /** Pill-Toggle für die Richtungs-Regler EINES Scopes (MULTI_REGLER togglen, sonst Single-Set). */
    public function reglerPill(string $scope, string $feld, string $wert): void
    {
        if (! isset($this->regler[$scope])) {
            return;
        }
        // Spec 55 Nachtrag: ein Pill-Klick ist ein DIREKTER Server-Write (kein `wire:model`-
        // Property-Sync) — der generische `updated()`-Hook sieht ihn nie, darum hier explizit
        // dasselbe "manuelle Änderung löscht den Agent-Marker"-Verhalten wie dort.
        unset($this->reglerVonAgent[$scope][$feld]);
        if (in_array($feld, self::MULTI_REGLER, true)) {
            $cur = (array) ($this->regler[$scope][$feld] ?? []);
            $this->regler[$scope][$feld] = in_array($wert, $cur, true)
                ? array_values(array_diff($cur, [$wert]))
                : [...$cur, $wert];

            return;
        }
        if (array_key_exists($feld, $this->regler[$scope])) {
            $this->regler[$scope][$feld] = $wert;
        }
    }

    /**
     * Brief-Vorlagen, die für den gegebenen Creation-Tab gelten (Schnellstart statt Blank Page,
     * Etappe 4). Die Blade blendet den Vorlagen-Block nur ein, wenn die Liste nicht leer ist —
     * so trägt der Basisrezept-Tab (Teil 1: keine Vorlagen) keine leere Auswahl.
     *
     * @return array<string,array<string,mixed>>
     */
    public function vorlagenFuer(string $scope): array
    {
        $team = $this->team();

        return $team ? app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->fuer($team, $scope) : [];
    }

    /**
     * Lädt eine Brief-Vorlage in den Start-Tab (Schnellstart statt Blank Page, Etappe 4). Füllt das
     * Briefing bewusst (Starter — die leere Seite soll weg) und setzt Sektor/Anlass/Serviceform als
     * Kontext-Vorschlag; der Titel wird NUR gesetzt, wenn der Nutzer noch keinen getippt hat (nicht
     * überschreiben). Alles bleibt frei anpassbar. Kein Go — der Mensch prüft und drückt selbst.
     */
    public function briefVorlage(string $scope, string $key): void
    {
        if (! isset($this->eingabe[$scope]) || ! isset($this->regler[$scope])) {
            return;
        }
        $team = $this->team();
        $tpl = $team ? app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->lade($team, (int) $key, $scope) : null;
        if ($tpl === null) {
            $this->fehler = 'Unbekannte oder für diesen Tab ungültige Vorlage.';

            return;
        }
        $payload = is_array($tpl->payload) ? $tpl->payload : [];
        $this->eingabe[$scope]['brief'] = (string) $tpl->brief;
        if (trim((string) ($this->eingabe[$scope]['titel'] ?? '')) === '' && trim((string) $tpl->titel) !== '') {
            $this->eingabe[$scope]['titel'] = (string) $tpl->titel;
        }
        if (! empty($payload['creative_mode']) && array_key_exists('creative_mode', $this->eingabe[$scope])) {
            $this->eingabe[$scope]['creative_mode'] = (string) $payload['creative_mode'];
        }
        // Leitplanken-Snapshot anwenden — NUR Keys, die der Ziel-Regler-Satz dieses Scopes führt
        // (kein Fremd-Key-Erbe; z. B. VK-/Menü-Achsen eines Gericht-Snapshots landen nicht im Basisrezept).
        foreach (($payload['regler'] ?? []) as $feld => $wert) {
            if (array_key_exists($feld, $this->regler[$scope])) {
                $this->regler[$scope][$feld] = $feld === 'sektor' && is_string($wert) ? (\Platform\FoodAlchemist\Support\Sektor::kanonisch($wert) ?? $wert) : $wert;   // CQ 2.5a: Altwert → Slug
            }
        }
        $this->aktiveVorlage[$scope] = (string) $key;   // Chip-Highlight: sichtbar, WELCHE Vorlage geladen wurde
        $this->fehler = null;
        $this->meldung = 'Vorlage „'.$tpl->label.'" geladen — Briefing, Kreativ-Modus und Leitplanken gesetzt, bitte prüfen und anpassen.';
    }

    /**
     * Eine MANUELLE Brief-Änderung hebt die Schnellstart-Markierung des Scopes auf — die Vorlage war
     * nur Startpunkt, ab hier ist es ein eigenes Briefing (ein stehender Chip-Highlight wäre irreführend).
     * Feuert NICHT beim programmatischen Setzen in {@see briefVorlage()} (nur client-originierte Updates).
     *
     * Spec 55: dieselbe Logik für `reglerVonAgent` — der „vom Agenten"-Marker ist eine Aussage
     * über den AKTUELLEN Feldwert („das steht so, weil der Agent es vorgeschlagen hat"); ändert
     * der Mensch das Feld manuell, stimmt die Aussage nicht mehr. `updated()` feuert NUR bei
     * client-originierten `wire:model`-Updates (Tippen/Regler-Bedienung), NICHT wenn
     * {@see voiceKomponentenUebernehmen()} selbst den Marker setzt (das ist ein Server-Write
     * in derselben Methode, kein Property-Update-Zyklus).
     */
    public function updated(string $name): void
    {
        if (preg_match('#^eingabe\.([^.]+)\.brief$#', $name, $m)) {
            unset($this->aktiveVorlage[$m[1]]);
            unset($this->reglerVonAgent[$m[1]]['brief']);
        }
        if (preg_match('#^regler\.([^.]+)\.([^.]+)$#', $name, $m)) {
            unset($this->reglerVonAgent[$m[1]][$m[2]]);
        }
    }

    /**
     * „Als Vorlage speichern": nimmt den AKTUELLEN Tab-Stand (Brief + Kreativ-Modus + kompletter
     * Leitplanken-Satz) als benannte, team-eigene Schnellstart-Vorlage auf — genau dort, wo die Regler
     * schon eingestellt sind (kein nachgebautes Formular). Erscheint danach als Chip auf DIESEM Scope.
     */
    public function alsVorlageSpeichern(string $scope): void
    {
        $team = $this->team();
        if ($team === null || ! isset($this->eingabe[$scope]) || ! isset($this->regler[$scope])) {
            return;
        }
        try {
            $tpl = app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->speichere(
                $team, $scope, $this->vorlageName, (string) ($this->eingabe[$scope]['brief'] ?? ''),
                $this->regler[$scope],
                $this->eingabe[$scope]['titel'] ?? null,
                $this->eingabe[$scope]['creative_mode'] ?? null,
                Auth::id(),
            );
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->vorlageName = '';
        $this->aktiveVorlage[$scope] = (string) $tpl->id;   // die gerade gespeicherte ist aktiv markiert
        $this->fehler = null;
        $this->meldung = 'Vorlage „'.$tpl->label.'" gespeichert — steht ab jetzt als Schnellstart bereit.';
    }

    /** Eine team-EIGENE Vorlage löschen (Globals sind read-only → Service wirft). Inline aus dem Chip. */
    public function loeschenVorlage(string $scope, int $id): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->loeschen($team, $id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if (($this->aktiveVorlage[$scope] ?? null) === (string) $id) {
            unset($this->aktiveVorlage[$scope]);
        }
        $this->fehler = null;
        $this->meldung = 'Vorlage gelöscht.';
    }

    /**
     * Et.4 (Eingabe-Reife) »Titel-/Namensvorschlag aus dem Brief« — Teil 3 (UI). Schlägt aus dem
     * Tab-Briefing einen nüchternen, §-konformen Titel vor ({@see TitelVorschlagService}, Teil 2 —
     * `recipe.titel_vorschlag` §1 bzw. `vk.titel_vorschlag` §4.4-Pipe) und füllt ihn ins Tab-Titelfeld
     * — **nur wenn dort noch keiner steht** (empty-only, Muster {@see briefVorlage});
     * ein bereits getippter Titel bleibt unangetastet. Kein Go — reiner Vorschlag, der Mensch prüft.
     *
     * `concept` ist bewusst außen vor (dessen `name_claim` liefert der kreative KI-Kopf, nicht der
     * nüchterne Titel-Prompt) — die Blade blendet den Knopf nur im Basisrezept-/Gericht-Tab ein; der
     * Scope-Guard hier fängt einen Fehl-Aufruf fail-soft ab.
     */
    public function titelVorschlagen(string $scope, TitelVorschlagService $svc): void
    {
        if (! in_array($scope, self::SCOPES, true) || ! isset($this->eingabe[$scope])) {
            return;
        }
        // Empty-only: ein bereits gesetzter Titel wird NIE überschrieben.
        if (trim((string) ($this->eingabe[$scope]['titel'] ?? '')) !== '') {
            $this->fehler = null;
            $this->meldung = 'Es steht bereits ein Titel — Vorschlag übersprungen (nicht überschrieben).';

            return;
        }
        $brief = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($brief === '') {
            $this->fehler = 'Für den Titelvorschlag erst ein Briefing im Tab eingeben.';

            return;
        }
        $vorschlag = $svc->titelVorschlag($scope, $brief);
        if ($vorschlag === null) {
            // concept (kein nüchterner Titel) ODER KI weg/leeres Ergebnis — fail-soft, kein Titel gefüllt.
            $this->fehler = $scope === 'concept'
                ? 'Für Konzepte liefert der KI-Kopf den Namen — der Titelvorschlag gilt nur für Basisrezept/Gericht.'
                : 'Kein Titelvorschlag möglich — bitte das Briefing schärfen oder manuell benennen.';

            return;
        }
        $this->eingabe[$scope]['titel'] = $vorschlag;
        $this->fehler = null;
        $this->meldung = 'Titel vorgeschlagen: „'.$vorschlag.'" — bitte prüfen und anpassen.';
    }

    /**
     * Befund des letzten Leitplanken-Vorschlags — bewusst SICHTBAR statt still:
     * was gesetzt wurde, was verworfen (erfundener Wert), was ignoriert (gültiger
     * Sitzungs-Parameter, aber kein Regler dieses Tabs) und was offen blieb.
     *
     * @var array{scope:string,gesetzt:list<string>,verworfen:list<string>,ignoriert:list<string>,unklar:list<string>,begruendung:?string,confidence:float}|null
     */
    public ?array $leitplankenBefund = null;

    /** Diktat: Audio-Blob fürs Briefing (WithFileUploads) + Ziel-Feld. */
    public $briefAudio = null;

    /**
     * Ziel des Diktats — eine Property-Pfad-Angabe, die vom CLIENT kommt und darum
     * gegen eine Whitelist läuft. Ohne sie könnte ein manipulierter Aufruf beliebige
     * Livewire-Properties überschreiben; Fail-closed ist hier keine Formalie.
     *
     * Deckt ALLE Briefing-Felder der Planungsstelle ab (Dominique 2026-09-02: „in der
     * planungsstelle"): die drei Erstell-Scopes plus die fünf Ausgabeformen.
     */
    public string $diktatZiel = 'eingabe.rezept.brief';

    public const DIKTAT_ZIELE = [
        'eingabe.rezept.brief', 'eingabe.gericht.brief', 'eingabe.concept.brief',
        'fbBrief', 'skBrief', 'spBrief', 'offerBrief', 'fmtBrief',
    ];

    /**
     * BRIEFING → LEITPLANKEN in der Oberfläche. Bis hierher war die Brücke nur per MCP
     * erreichbar — ein Produkt muss das in sich lösen (Dominique 2026-09-02).
     *
     * Die Sitzung wird ABSICHTLICH nicht mitgegeben: der Vorschlag füllt nur die Regler
     * DIESES Tabs, damit der Mensch die Pills vor dem Go sieht. Persistiert wird am Go
     * (Start-Tab-Regel) — die Entscheidung bleibt menschlich.
     */
    /**
     * Spec 80 A3: neue KI-Begriffe + die vom Menschen gesetzten. KI-Chips des Vorlaufs fallen weg,
     * Mensch-Chips bleiben (auch wenn die KI denselben Begriff liefert — dann zählt er als Mensch).
     *
     * @param  list<array{t: string, g: string, q: string}>  $bisher
     * @param  list<array{t: string, g: string, q: string}>  $neuKi
     * @return list<array{t: string, g: string, q: string}>
     */
    private static function suchbegriffeZusammenfuehren(array $bisher, array $neuKi): array
    {
        $mensch = array_values(array_filter($bisher, static fn ($b) => is_array($b) && ($b['q'] ?? '') === 'mensch'));
        $belegt = array_flip(array_map(static fn ($b) => mb_strtolower((string) $b['t']), $mensch));
        foreach ($neuKi as $b) {
            if (is_array($b) && isset($b['t']) && ! isset($belegt[mb_strtolower((string) $b['t'])])) {
                $mensch[] = ['t' => (string) $b['t'], 'g' => (string) ($b['g'] ?? 'eigene'), 'q' => 'ki'];
                $belegt[mb_strtolower((string) $b['t'])] = true;
            }
        }

        return $mensch;
    }

    /** Spec 80 A3: Chip entfernen (Index in der Liste des Tabs). */
    public function suchbegriffEntfernen(string $scope, int $index): void
    {
        if (! isset($this->eingabe[$scope]['suchbegriffe'][$index])) {
            return;
        }
        $liste = $this->eingabe[$scope]['suchbegriffe'];
        unset($liste[$index]);
        $this->eingabe[$scope]['suchbegriffe'] = array_values($liste);
    }

    /** Spec 80 A3: eigenen Begriff ergänzen (Gruppe „eigene", Quelle Mensch). Komma trennt mehrere. */
    public function suchbegriffHinzufuegen(string $scope): void
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return;
        }
        $roh = (string) ($this->eingabe[$scope]['suchbegriff_neu'] ?? '');
        $liste = (array) ($this->eingabe[$scope]['suchbegriffe'] ?? []);
        $belegt = array_flip(array_map(static fn ($b) => mb_strtolower((string) ($b['t'] ?? '')), $liste));
        foreach (preg_split('/[,;]/u', $roh) ?: [] as $teil) {
            $t = trim($teil);
            if (mb_strlen($t) < 2 || mb_strlen($t) > 60 || isset($belegt[mb_strtolower($t)])) {
                continue;
            }
            $liste[] = ['t' => $t, 'g' => 'eigene', 'q' => 'mensch'];
            $belegt[mb_strtolower($t)] = true;
        }
        $this->eingabe[$scope]['suchbegriffe'] = array_values($liste);
        $this->eingabe[$scope]['suchbegriff_neu'] = '';
    }

    /**
     * Spec 80 A4: die Begriffe als Such-Liste für den Lauf — Gruppen-Priorität (Zutaten zuerst), dann
     * Eingabe-Reihenfolge. Nur die Wörter; Gruppe/Quelle bleiben an der Sitzung.
     *
     * @return list<string>
     */
    private function suchbegriffeFuerLauf(string $scope): array
    {
        return FoodAlchemistPlanningSession::suchbegriffeFuerLauf((array) ($this->eingabe[$scope]['suchbegriffe'] ?? []));
    }

    public function leitplankenAusBriefing(string $scope, BriefingLeitplankenService $svc): void
    {
        if (! in_array($scope, self::SCOPES, true) || ! isset($this->regler[$scope])) {
            return;
        }
        $brief = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($brief === '') {
            $this->fehler = 'Für die Leitplanken erst ein Briefing in diesem Tab eingeben.';

            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team im Kontext.';

            return;
        }

        try {
            $r = $svc->ausBriefing($team, $brief, null, $scope);
        } catch (\Throwable $e) {
            $this->fehler = 'Leitplanken-Vorschlag fehlgeschlagen: ' . $e->getMessage();

            return;
        }

        $gesetzt = [];
        // Scope-fremde Felder (Basisrezept: pax/Portion/Anlass …) hat der Service schon entfernt —
        // hier nur melden, damit nichts still verschwindet.
        $ignoriert = array_values((array) ($r['scope_fremd'] ?? []));
        foreach ($r['leitplanken'] as $feld => $wert) {
            // Bestand hat außerhalb des Basisrezept-Tabs keinen eigenen Regler — dort steuert der
            // Kreativ-Modus die Reuse-Achse (reglerParams). »Nur Bestand« aus dem Briefing landet
            // deshalb im Modus, statt im verdeckten Regler-Wert unterzugehen.
            if ($feld === 'bestand' && $scope !== 'rezept') {
                if ($wert === 'nur_bestand') {
                    $this->eingabe[$scope]['creative_mode'] = 'datenbank';
                    $gesetzt[] = 'bestand';
                } else {
                    $ignoriert[] = 'bestand';
                }

                continue;
            }
            if (! array_key_exists($feld, self::REGLER_DEFAULT)) {
                // Im Sitzungs-Param-Raum gültig, aber dieser Tab hat den Regler nicht
                // (z. B. Menü-Quoten). Melden statt verschlucken.
                $ignoriert[] = (string) $feld;

                continue;
            }
            // Formtreue: Multi-Regler bleibt Array, Single-Regler bleibt Skalar — ein String
            // in `diaet_hart` würde die Pill-Logik (reglerPill) zerlegen.
            $this->regler[$scope][$feld] = is_array(self::REGLER_DEFAULT[$feld])
                ? array_values(array_filter((array) $wert, static fn ($v): bool => is_scalar($v)))
                : (is_array($wert) ? (string) (reset($wert) ?: '') : $wert);
            $gesetzt[] = (string) $feld;
        }

        // Spec 80 A3: KI-Suchbegriffe ersetzen die bisherigen KI-Chips; was der Mensch ergänzt hat, bleibt.
        $this->eingabe[$scope]['suchbegriffe'] = self::suchbegriffeZusammenfuehren(
            (array) ($this->eingabe[$scope]['suchbegriffe'] ?? []),
            (array) ($r['suchbegriffe'] ?? []),
        );

        $this->leitplankenBefund = [
            'scope' => $scope,
            'gesetzt' => $gesetzt,
            'verworfen' => $r['verworfen'],
            'ignoriert' => $ignoriert,
            'unklar' => $r['unklar'],
            'begruendung' => $r['begruendung'],
            'confidence' => $r['confidence'],
        ];
        $this->fehler = null;
        $this->meldung = $gesetzt === []
            ? 'Keine Leitplanke ableitbar — das Briefing nennt keine der geschlossenen Achsen.'
            : count($gesetzt) . ' Leitplanke(n) vorgeschlagen — bitte prüfen, das Go bleibt bei dir.';
    }

    /**
     * Diktat fürs Briefing: gesprochener Text wird ANGEHÄNGT, nie ersetzt — ein Diktat
     * ist ein Nachtrag, und ein versehentlich überschriebenes Briefing wäre nicht
     * wiederherstellbar. Reines STT, kein Tool-Loop: das Briefing soll exakt das sein,
     * was der Mensch gesagt hat, nicht die Deutung eines Agenten. Die Deutung passiert
     * danach sichtbar in einem Schritt (Leitplanken vorschlagen).
     */
    public function updatedBriefAudio(): void
    {
        $this->briefDiktatUebernehmen();                             // dünner Hook, Logik testbar daneben
    }

    public function briefDiktatUebernehmen(): void
    {
        $ziel = in_array($this->diktatZiel, self::DIKTAT_ZIELE, true) ? $this->diktatZiel : null;
        if ($ziel === null) {
            $this->fehler = 'Unbekanntes Diktat-Ziel — nichts übernommen.';
            $this->briefAudio = null;

            return;
        }
        if ($this->briefAudio === null) {
            return;
        }
        try {
            $text = trim(app(SttServiceContract::class)->transcribe(
                (string) file_get_contents($this->briefAudio->getRealPath()),
                VoiceMime::aufgeloest($this->briefAudio),
            ));
        } catch (\Throwable $e) {
            $this->fehler = 'Diktat fehlgeschlagen: ' . VoiceFehlerText::aus($e)['text'];
            $this->briefAudio = null;

            return;
        }
        $this->briefAudio = null;
        if ($text === '') {
            // Kann auch der Prompt-Echo-Riegel im STT sein (Aufnahme ohne Sprache).
            $this->fehler = 'Diktat war leer — nichts übernommen.';

            return;
        }

        $alt = trim((string) $this->diktatWert($ziel));
        $this->diktatSetzen($ziel, $alt === '' ? $text : $alt . ' ' . $text);
        $this->fehler = null;
        $this->meldung = 'Diktat übernommen — bitte gegenlesen.';
    }

    /**
     * Kurskorrektur „pro Tab genau EINE Diktierfunktion" (2026-09-19): das Agent-Panel ist in
     * den drei Creation-Scopes der einzige Diktier-Weg (der alte Recorder-Knopf dieses Tabs
     * ist raus, siehe erstellen-tab.blade.php). Sein eigenes Mikro transkribiert selbst
     * (VoiceModal::updatedAudio()) und liefert das Rohtranskript hier an — Richtung also
     * UMGEKEHRT zur vorherigen Fassung (die ging vom alten Diktat-Knopf ZUM Panel). Gleiche
     * Anhängen-nie-überschreiben-Semantik wie briefDiktatUebernehmen()/diktatSetzen().
     */
    #[On('voice.diktat-transkribiert')]
    public function agentDiktatUebernehmen(string $scope, string $text): void
    {
        if (! in_array($scope, self::SCOPES, true) || trim($text) === '') {
            return;
        }
        $ziel = 'eingabe.' . $scope . '.brief';
        $alt = trim((string) $this->diktatWert($ziel));
        $this->diktatSetzen($ziel, $alt === '' ? $text : $alt . ' ' . $text);
        $this->meldung = 'Diktat übernommen — bitte gegenlesen.';
    }

    /** Liest das Whitelist-Ziel; `eingabe.<scope>.brief` ist verschachtelt, der Rest flach. */
    private function diktatWert(string $ziel): string
    {
        if (str_starts_with($ziel, 'eingabe.')) {
            $scope = explode('.', $ziel)[1] ?? '';

            return (string) ($this->eingabe[$scope]['brief'] ?? '');
        }

        return (string) ($this->{$ziel} ?? '');
    }

    private function diktatSetzen(string $ziel, string $wert): void
    {
        if (str_starts_with($ziel, 'eingabe.')) {
            $scope = explode('.', $ziel)[1] ?? '';
            if (isset($this->eingabe[$scope])) {
                $this->eingabe[$scope]['brief'] = $wert;
            }

            return;
        }
        $this->{$ziel} = $wert;
    }

    /** Spec 84 C1-1: Leitplanken-Logik liegt in {@see ReglerParams}; hier nur der Zugang mit dem aktuellen Stand. */
    private function reglerHelfer(): ReglerParams
    {
        return new ReglerParams($this->regler, $this->eingabe);
    }

    private function reglerParams(string $scope): array
    {
        return $this->reglerHelfer()->reglerParams($scope);
    }

    private function menueAchsenFuer(string $scope): array
    {
        return $this->reglerHelfer()->menueAchsenFuer($scope);
    }

    private function menueLeitplankenFehler(string $scope): ?string
    {
        return $this->reglerHelfer()->menueLeitplankenFehler($scope);
    }

    private function zielVkEur(string $scope): ?float
    {
        return $this->reglerHelfer()->zielVkEur($scope);
    }

    private function effektiverBrief(string $scope): string
    {
        return $this->reglerHelfer()->effektiverBrief($scope);
    }

    /**
     * #1b Grounding-Preview: zeigt VOR dem Go, welches Wissen/Pairing/Template die Generierung für die
     * aktuellen Regler ziehen würde — OHNE zu generieren. Ruft denselben Kontext-Bau wie die Generierung
     * ({@see RecipeGenerationContextService::build}) und legt das `kontext`-Bündel für den Kontext-Inspektor
     * ab. Fail-soft: eine Preview darf den Editor nie brechen.
     */
    public function wissenVorschau(string $scope): void
    {
        $team = $this->team();
        if ($team === null || ! in_array($scope, self::SCOPES, true)) {
            return;
        }
        $brief = $this->effektiverBrief($scope);
        if ($brief === '') {
            $this->fehler = 'Für die Wissens-Vorschau erst Titel oder Beschreibung im Tab setzen.';

            return;
        }
        try {
            $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
                ->build($team, $brief, $this->reglerParams($scope), $scope !== 'rezept');
            $this->wissenVorschau = is_array($ctx['kontext'] ?? null) ? $ctx['kontext'] : null;
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Wissens-Vorschau fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── „KI-Kopf" — Concept-Plan vorab ausarbeiten (Etappe 2b, geplanter Pfad) ──

    /**
     * „KI-Kopf" (Etappe 2b): arbeitet aus dem Concept-Briefing einen vollständigen Plan-Entwurf aus
     * ({@see ConceptGeneratorService::planAusBrief} → Draft-Concept + Gerüst + kreative Canvas + LEERE
     * Fan-out-Slots) und öffnet den vollen inline-Conceptor direkt auf „Konzept & Planung" ({@see
     * Concepter\Editor::oeffnen} Start-Tab 'konzept') zur Prüfung/Korrektur. Startet KEINE Kaskade —
     * der geprüfte Plan geht später als `existing_concept_id` in den Go (nächster Chunk). Nur für den
     * Concept-Scope sinnvoll (ein Concept ist ein Menü, kein Einzelrezept).
     *
     * Fail-soft (Nordstern): ein Fehler (leerer Brief / KI aus) wird gesagt, nicht geschluckt — der
     * Absender ist ein Mensch, der korrigieren kann.
     */
    public function kiKopf(ConceptGeneratorService $svc, PlanningSessionService $sessions): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            $this->fehler = 'Kein Team/Session — KI-Kopf nicht möglich.';

            return;
        }
        $brief = $this->effektiverBrief('concept');
        if ($brief === '') {
            $this->fehler = 'Für den KI-Kopf erst ein Konzept-Briefing eingeben.';

            return;
        }
        // Menü-Leitplanken zuerst validieren (wie beim Go) — der KI-Kopf darf nicht mit einer
        // stillschweigend verworfenen Zahl-Vorgabe generieren.
        if (($menueFehler = $this->menueLeitplankenFehler('concept')) !== null) {
            $this->fehler = $menueFehler;

            return;
        }
        // Concept-Briefing auf die Session spiegeln + persistieren (nicht verlieren) — wie beim Go.
        $this->form['brief'] = (string) ($this->eingabe['concept']['brief'] ?? '');
        $this->form['creative_mode'] = (string) ($this->eingabe['concept']['creative_mode'] ?? 'voll_kreativ');
        $this->speichern($sessions);

        $titel = trim((string) ($this->eingabe['concept']['titel'] ?? ''));
        try {
            // Menü-Leitplanken (Gänge/Preis-Korridor/Quoten/Balance/Buffet) an den Plan durchreichen —
            // sonst arbeitet der plan-first-Standard-Pfad ohne sie (Bug: nur der Schnell-Go über den Job
            // reichte sie bisher). Gleiche menue_*-Teilmenge wie dispatchConceptStep. $via bleibt 'ui'
            // (der created_via-Marker `concept_plan_ui` wird andernorts erwartet — nicht verändern).
            $plan = $svc->planAusBrief($team, $brief, [], $titel !== '' ? $titel : null, 'ui', $this->menueAchsenFuer('concept'));
        } catch (\Throwable $e) {
            $this->fehler = 'KI-Kopf fehlgeschlagen: ' . $e->getMessage();

            return;
        }
        $this->fehler = null;
        // Draft-ID merken: der nächste Concept-Go referenziert diesen geprüften Plan statt neu zu
        // generieren („Beide Pfade behalten", Etappe 2b). Verbraucht wird sie in goKaskade.
        $this->planConceptId = (int) $plan['concept']->id;
        // #53 persistent: an die Session schreiben, damit der geprüfte Plan einen Reload übersteht
        // (rehydriert in ladeForm). Fail-soft: kippt die Persistenz, bleibt der Plan wenigstens in der
        // laufenden Session (transiente Prop) nutzbar — ein Speicher-Fehler darf den KI-Kopf nicht kippen.
        try {
            app(PlanningSessionService::class)->setzePlanConcept($team, (int) $session->id, $this->planConceptId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] plan_concept_id-Persistenz übersprungen (KI-Kopf bleibt für diese Session nutzbar) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // Inline-Plan-Panel: die Semantik-Felder in die Fläche laden — der Plan bleibt SICHTBAR in der
        // Leitstelle (kein Wegsprung mehr in den Conceptor). „Ein Ort, ein Kontext": Leitidee/Menü hier
        // prüfen + ändern (fließt in die LLM), dann „Go aus geprüftem Plan". Der Conceptor bleibt als
        // optionaler Tiefen-Editor per Knopf im Panel erreichbar (Entscheid 2026-08-18).
        $this->ladePlanForm();
        $this->meldung = 'KI-Kopf: Plan ausgearbeitet — prüfe/ändere Leitidee & Menü direkt hier, dann „Go aus geprüftem Plan".';
    }

    /**
     * Lädt die editierbaren Semantik-Skalare des vorbereiteten Plans aus der `concept.plan`-Canvas in
     * {@see $planForm} (leert sie, wenn kein Plan/kein Canvas). Rein lesend, fail-soft: ein fehlender
     * Canvas/Concept lässt das Panel leer, kippt aber nichts.
     */
    private function ladePlanForm(): void
    {
        $this->planForm = [];
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            return;
        }
        $canvas = app(CanvasService::class)->find('concept', 'concept', (int) $this->planConceptId);
        $werte = $canvas !== null ? app(CanvasService::class)->werte($canvas) : [];
        foreach (['name_claim', 'leitidee', 'usp_eignung', 'inszenierung', 'intern'] as $key) {
            $this->planForm[$key] = (string) ($werte[$key] ?? '');
        }
    }

    /**
     * Speichert die inline editierten Semantik-Felder ({@see $planForm}) zurück in die `concept.plan`-
     * Canvas des vorbereiteten Plans. Diese Felder sind der LLM-Kontext für die Concept-/Gericht-Erzeugung
     * — eine Änderung hier steuert also direkt, was beim nächsten Go entsteht („ein Ort, ein Kontext").
     * Fail-soft (Nordstern): ist der Plan inzwischen weg, wird das GESAGT statt still geschluckt.
     */
    public function planFeldSpeichern(): void
    {
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            $this->fehler = 'Kein vorbereiteter Plan — nichts zu speichern.';

            return;
        }
        $concept = FoodAlchemistConcept::where('team_id', $team->id)->whereKey($this->planConceptId)->first();
        if ($concept === null) {   // Plan gelöscht/Team-fremd → Referenz still lösen, Panel schließt sich
            $this->planConceptId = null;
            $this->planForm = [];
            $this->fehler = 'Der vorbereitete Plan ist nicht mehr da — bitte neu ausarbeiten.';

            return;
        }
        $canvas = app(CanvasService::class)->canvasFor($team, 'concept', 'concept', (int) $concept->id);
        app(CanvasService::class)->saveSkalare($canvas, $this->planForm);
        $this->fehler = null;
        $this->meldung = 'Plan aktualisiert — die geänderte Leitidee & co. fließen beim Erzeugen in die Gerichte.';
    }

    /**
     * Lese-Anzeige des vorbereiteten Plans für das Inline-Panel: Name, Geschmackswelten (repeatable) und
     * die geplanten Menü-Positionen ({@see FoodAlchemistConcept::slots} — Rolle/Titel/Pflicht). `null`,
     * wenn kein Plan vorbereitet ist. Rein lesend (2 Queries) — im Render aufgerufen, fail-soft.
     *
     * @return array{name:string,geschmackswelten:array<int,array<string,mixed>>,speisen:array<int,array<string,mixed>>}|null
     */
    public function planVorschau(): ?array
    {
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            return null;
        }
        $concept = app(ConceptService::class)->detail($team, (int) $this->planConceptId);
        if ($concept === null) {
            return null;
        }
        $canvas = app(CanvasService::class)->find('concept', 'concept', (int) $concept->id);
        $werte = $canvas !== null ? app(CanvasService::class)->werte($canvas) : [];

        return [
            'name' => (string) $concept->name,
            'geschmackswelten' => is_array($werte['geschmackswelten'] ?? null) ? $werte['geschmackswelten'] : [],
            'speisen' => $concept->slots->map(fn ($s) => [
                'rolle' => (string) ($s->role ?? ''),
                'titel' => (string) ($s->title ?? ''),
                'pflicht' => (bool) $s->is_pflicht,
            ])->values()->all(),
        ];
    }

    /**
     * Den vorbereiteten KI-Kopf-Plan verwerfen und zurück auf den Schnell-Pfad wechseln („Beide Pfade
     * behalten", Etappe 2b): nur die transiente Referenz wird gelöst — der Concept-Go generiert dann
     * wieder frisch aus dem Briefing. Das Draft-Concept selbst bleibt bestehen (löscht nichts).
     */
    public function planVerwerfen(): void
    {
        $this->planConceptId = null;
        // #53 persistent: auch den gespeicherten Zeiger lösen, sonst holt der Reload den verworfenen
        // Plan wieder (rehydrate in ladeForm). Das Draft-Concept selbst bleibt bestehen (löscht nichts).
        $session = $this->aktiveSession();
        if ($session !== null && $session->plan_concept_id !== null && ($team = Auth::user()?->currentTeamRelation) !== null) {
            try {
                app(PlanningSessionService::class)->setzePlanConcept($team, (int) $session->id, null);
            } catch (\Throwable) {
                // Persistenz-Fehler darf das Verwerfen nicht kippen — die Prop ist bereits null.
            }
        }
        $this->meldung = 'Vorbereiteter Plan verworfen — der Go generiert wieder frisch aus dem Briefing.';
        $this->fehler = null;
    }

    // ── „Go" — Tiefen-Leiter über den geteilten Kaskaden-Motor ─────────

    /**
     * Go → in-place Generierung über {@see PlanningCascadeService}. `$scope` = Einstiegs-Stufe
     * (`rezept`|`gericht`|`concept`). Sammelt die Richtungs-Regler (rezept/gericht), persistiert
     * sie als `generation_params` (Fan-out-Vererbung) und reicht sie als Lauf-`params` an den Motor.
     * Startet im Hintergrund; die Fläche pollt {@see pruefeLauf}. Kein Redirect.
     */
    public function goKaskade(string $scope, PlanningCascadeService $cascade, PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || ! in_array($scope, self::SCOPES, true)) {
            return;
        }
        $vk = $scope !== 'rezept';
        // Ziel-VK-Eingabe (nur wo VK) wird GESAGT statt still verworfen — der Absender ist ein
        // Mensch, der 8,5 statt 850 meinte, und kann korrigieren (L8b-2, Spiegel VkGeneratorModal).
        if ($vk && trim((string) ($this->regler[$scope]['ziel_vk'] ?? '')) !== '' && $this->zielVkEur($scope) === null) {
            $this->fehler = 'Ziel-VK: bitte einen Netto-Preis je Portion zwischen 0,50 € und 500,00 € angeben (z. B. 8,50) — oder das Feld leer lassen.';

            return;
        }
        // Menü-Leitplanken (nur Concept): eine mistgetippte Zahl wird GESAGT statt still verworfen —
        // derselbe Grundsatz wie bei Ziel-VK (der Absender ist ein Mensch, der korrigieren kann).
        if (($menueFehler = $this->menueLeitplankenFehler($scope)) !== null) {
            $this->fehler = $menueFehler;

            return;
        }
        // Kontext des Start-Tabs auf die Session spiegeln (Dashboard-Anzeige + creative_mode) und persistieren.
        $this->form['brief'] = (string) ($this->eingabe[$scope]['brief'] ?? '');
        $this->form['creative_mode'] = (string) ($this->eingabe[$scope]['creative_mode'] ?? 'voll_kreativ');
        $this->speichern($svc);
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        // NUR der Start-Tab zählt: seine Leitplanken werden persistiert und propagieren die ganze
        // Kaskade nach unten (Start-Tab gilt für alles darunter — User-Entscheid 2026-08-14).
        $params = $this->reglerParams($scope);
        // FAIL-SOFT: die Regler fließen ohnehin über die Lauf-`params` in den Depth-1-Job — die
        // Session-Persistenz ist NUR für die Fan-out-Vererbung. Kippt sie, darf der Go NICHT sterben.
        try {
            $svc->setGenerationParams($team, $session->id, $params);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] setGenerationParams übersprungen (Fan-out-Vererbung aus) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // Spec 80 A4: ohne Suchbegriffe (Leitplanken nie abgeleitet) einmal nachziehen — derselbe Aufruf,
        // nur die Begriffe werden übernommen, die Regler bleiben unangetastet. Fail-soft: ohne Begriffe
        // sucht der Generator wie bisher mit dem Briefing.
        if (in_array($scope, ['rezept', 'gericht'], true) && empty($this->eingabe[$scope]['suchbegriffe'])
            && trim((string) ($this->eingabe[$scope]['brief'] ?? '')) !== '') {
            try {
                $abgeleitet = app(BriefingLeitplankenService::class)
                    ->ausBriefing($team, (string) $this->eingabe[$scope]['brief'], null, $scope);
                $this->eingabe[$scope]['suchbegriffe'] = (array) ($abgeleitet['suchbegriffe'] ?? []);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Planung] Suchbegriffe beim Go nicht ableitbar', ['error' => $e->getMessage()]);
            }
        }
        $suchbegriffe = $this->suchbegriffeFuerLauf($scope);
        try {
            $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)
                ->setzeSuchbegriffe($team, (int) $session->id, $scope, (array) ($this->eingabe[$scope]['suchbegriffe'] ?? []));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] Suchbegriffe nicht gespeichert — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // L5: Titel als Namens-Anker — getippten Titel getrennt vom Brief in die Lauf-Params geben
        // (NICHT in die persistierten Session-Params, sonst erbte JEDES Fan-out-Kind den Gericht-Titel
        // als Namen). Nur der Depth-1-Job sieht `titel_vorgabe`; der Generator nimmt ihn als Namen.
        $laufParams = $params;
        $titelVorgabe = trim((string) ($this->eingabe[$scope]['titel'] ?? ''));
        if ($titelVorgabe !== '' && $scope !== 'concept') {   // concept: Name kommt aus dem KI-Kopf (name_claim)
            $laufParams['titel_vorgabe'] = $titelVorgabe;
        }
        // Foodpairing-Composer: gezielte Seed-Anker NUR in die Lauf-Params (Depth-1-lokal, exakt wie
        // titel_vorgabe) — NIE in setGenerationParams, sonst erbte jedes Fan-out-Kind die Anker.
        // Quelle: das persistente $composerSeedPin (composerUebernehmen → Erstellen-Tab, überlebt den
        // Tab-Wechsel), nur für den passenden Scope. Pin nach Verwendung leeren → kein Re-Seed bei
        // späterem unabhängigem Go.
        $seed = [];
        $seedAusPin = false;
        if (($this->composerSeedPin['scope'] ?? null) === $scope) {
            $seed = array_values($this->composerSeedPin['slugs'] ?? []);
            $seedAusPin = true;
        }
        if ($seed !== [] && $scope !== 'concept') {
            $laufParams['seed_anker'] = array_values($seed);
        }
        // Spec 80: Suchbegriffe NUR Depth-1 (wie titel_vorgabe) — Kind-Rezepte bekommen später die
        // Begriffe ihrer Komponente (Teil B), nicht die der Wurzel.
        if ($suchbegriffe !== [] && $scope !== 'concept') {
            $laufParams['suchbegriffe'] = $suchbegriffe;
        }
        if ($seedAusPin) {
            $this->composerSeedPin = [];
        }
        $optionen = [
            'created_via' => 'plan_go',
            'brief' => $this->effektiverBrief($scope),
            'params' => $laufParams,
            'voll_anreichern' => (bool) ($this->regler[$scope]['voll_anreichern'] ?? true),   // Default AN (s. $reglerDefaults)
            // Gericht: erst textlicher Bauplan (inkl. Komponenten), Rezept-Draft erst nach Blitz/Freigabe.
            'proposal_first' => $scope === 'gericht',
            // Spec 80 B1: Basisrezept erst als Komponenten-Plan (Mensch bestätigt), dann Bau.
            'plan_first' => $scope === 'rezept',
        ];
        // GEPLANTER PFAD (Etappe 2b, „Beide Pfade behalten"): wurde vorab ein KI-Kopf-Plan ausgearbeitet
        // und im Conceptor geprüft ($planConceptId), referenziert der Concept-Go dieses Draft-Concept
        // (existing_concept_id → kein neuer GenerateConceptJob), statt neu zu generieren. Ohne Plan =
        // SCHNELL-PFAD (frische Generierung). FAIL-SOFT: ist der Plan inzwischen weg (gelöscht/Team-fremd),
        // NICHT hart blocken — Prop still verwerfen und frisch generieren (ein leerer/toter Plan kippt den Go nicht).
        if ($scope === 'concept' && (int) ($this->planConceptId ?? 0) > 0) {
            if (FoodAlchemistConcept::where('team_id', $team->id)->whereKey($this->planConceptId)->exists()) {
                $optionen['existing_concept_id'] = (int) $this->planConceptId;
            } else {
                $this->planConceptId = null;
            }
        }
        try {
            $run = $cascade->starteKaskade($team, $scope, $session, (string) ($this->eingabe[$scope]['creative_mode'] ?? 'voll_kreativ'), $optionen);
            $this->laufId = $run->id;
            $this->laeuft = true;
            // Verbrauch AN DEN SCOPE binden: nur der Concept-Go verbraucht den KI-Kopf-Plan. Sonst löschte ein
            // Basisrezept-/Gericht-Go den vorbereiteten Concept-Plan, obwohl er für diesen Lauf nie gelesen wurde.
            if ($scope === 'concept') {
                $this->planConceptId = null;    // Plan verbraucht (referenziert ODER frisch generiert) → nächster Go Schnell-Pfad
                // #53 persistent: auch den gespeicherten Zeiger lösen (Plan verbraucht) — sonst böte ein
                // Reload nach dem Go den bereits verbrauchten Plan wieder an. Fail-soft.
                if ($session->plan_concept_id !== null) {
                    try {
                        app(PlanningSessionService::class)->setzePlanConcept($team, (int) $session->id, null);
                    } catch (\Throwable) {
                        // Persistenz-Fehler darf den bereits gestarteten Lauf nicht kippen.
                    }
                }
            }
            $this->wissenVorschau = null;   // neue Kaskade → Vorschau weg; die Steps zeigen dann das ECHTE Wissen (#1a)
            $this->meldung = 'Kaskade gestartet — Entwurf wird erzeugt …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function landingKaskadenMap(?Team $team, array $sessionIds): array
    {
        return app(KaskadenAnsicht::class)->landingKaskadenMap($team, $sessionIds);
    }

    // ── Composer-Tab (Foodpairing-Fläche: Anker zusammenstellen) ───────

    /** Einen Anker in die Auswahl aufnehmen (aus Suchtreffer ODER Kandidaten-Klick im Netz). */
    public function composerAdd(int $id): void
    {
        $ids = array_column($this->composerAnker, 'id');
        if (in_array($id, $ids, true) || count($this->composerAnker) >= 12) {
            return;
        }
        $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)->first(['id', 'slug', 'display_de']);
        if ($a === null) {
            return;
        }
        $this->composerAnker[] = ['id' => (int) $a->id, 'slug' => $a->slug, 'label' => $a->display_de ?: $a->slug];
        $this->composerTerm = '';
    }

    /** Einen Anker aus der Auswahl entfernen. */
    public function composerRemove(int $id): void
    {
        $this->composerAnker = array_values(array_filter(
            $this->composerAnker,
            fn ($a) => (int) $a['id'] !== $id
        ));
        if ($this->composerFocus === $id) {
            $this->composerFocus = null;
        }
    }

    /** Fokus auf einen Anker setzen/aufheben (Klick im Netz; 0 oder erneut derselbe = aufheben). */
    public function composerFocus(int $id): void
    {
        if ($id === 0 || $this->composerFocus === $id) {
            $this->composerFocus = null;

            return;
        }
        if (in_array($id, array_map('intval', array_column($this->composerAnker, 'id')), true)) {
            $this->composerFocus = $id;
        }
    }

    /**
     * Gemeinsame Composer-Vorbereitung: Scope/Anker prüfen + Brief aus den Anker-Labels vorbefüllen
     * (getippten Brief NICHT überschreiben — die Anker SIND die kreative Vorgabe, reisen zusätzlich als
     * seed_anker). Gibt die Slug-Liste zurück oder null (Scope ungültig / keine Anker → fehler gesetzt).
     */
    private function composerVorbereiten(string $scope): ?array
    {
        if (! in_array($scope, ['rezept', 'gericht'], true)) {
            return null;
        }
        $slugs = array_values(array_filter(array_map(
            fn ($a) => trim((string) ($a['slug'] ?? '')),
            $this->composerAnker,
        )));
        if ($slugs === []) {
            $this->fehler = 'Bitte zuerst Anker im Composer wählen.';

            return null;
        }
        if (trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
            $labels = array_values(array_filter(array_map(
                fn ($a) => trim((string) ($a['label'] ?? $a['slug'] ?? '')),
                $this->composerAnker,
            )));
            $this->eingabe[$scope]['brief'] = 'Foodpairing-Kreation auf Basis von: ' . implode(', ', $labels) . '.';
        }

        return $slugs;
    }

    /**
     * Foodpairing-Composer (2026-08-24): aus den gewählten Ankern in den Erstellen-Tab springen statt
     * sofort zu generieren — Brief vorbefüllen + Seed-Pin setzen, damit Dominique dort die Leitplanken
     * (voller Regler) setzt und regulär per Go startet (der Go nimmt die Anker via composerSeedPin mit).
     * Der Tab-Wechsel selbst passiert per Alpine (@click) am Button. Bewusst KEIN goKaskade hier.
     */
    public function composerUebernehmen(string $scope): void
    {
        $slugs = $this->composerVorbereiten($scope);
        if ($slugs === null) {
            return;
        }
        $this->composerSeedPin = ['scope' => $scope, 'slugs' => $slugs];
    }

    // ── Datenbeschaffung ───────────────────────────────────────────────

    private function aktiveSession(): ?FoodAlchemistPlanningSession
    {
        if ($this->sessionId === null) {
            return null;
        }
        $team = $this->team();

        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId);
    }

    public function render()
    {
        $team = $this->team();

        // Sessions team-sichtbar. Gruppe: „Aus dem Trendradar" (Spec 79, Herkunft source_trend_refs) oder frei.
        $sessions = TeamScope::applyVisible(
            DB::table('foodalchemist_planning_sessions as s')->whereNull('s.deleted_at'),
            's.team_id', $team
        )->orderByDesc('s.updated_at')
            ->get(['s.id', 's.title', 's.analysis', 's.status', 's.source_trend_refs', 's.updated_at'])
            ->each(function ($s) {
                $s->category = $s->source_trend_refs !== null ? 'Aus dem Trendradar' : null;
            });

        // Finale Etappe (Hauptseite): Kaskaden-Status je Session (Badge + Stufen-Fortschritt) — ein
        // Query-Pass über die VOLLE Liste (vor dem Filter, damit der Status-Filter greifen kann).
        $kaskaden = $this->landingKaskadenMap($team, $sessions->pluck('id')->map(fn ($v) => (int) $v)->all());

        // Filter/Suche (finale Etappe #17): Volltext über den Titel + Status-Filter (aus $kaskaden).
        // Filtert Liste UND Zuletzt-Karten konsistent; leere Filter = unveränderte Liste.
        $suche = mb_strtolower(trim($this->sucheListe));
        $filterStatus = trim($this->filterStatus);
        $filterTyp = trim($this->filterTyp);
        if ($suche !== '' || $filterStatus !== '' || $filterTyp !== '') {
            $sessions = $sessions->filter(function ($s) use ($suche, $filterStatus, $filterTyp, $kaskaden) {
                if ($suche !== '' && ! str_contains(mb_strtolower((string) $s->title), $suche)) {
                    return false;
                }
                if ($filterStatus !== '' && ($kaskaden[(int) $s->id]['status'] ?? 'entwurf') !== $filterStatus) {
                    return false;
                }
                // Typ = Scope (rezept/gericht/concept) ODER Ausgabe-Ziel (foodbook/speisekarte/speiseplan) des
                // jüngsten Laufs. Sessions ohne Lauf (kein Scope/Owner) fallen bei gesetztem Typ raus.
                if ($filterTyp !== '') {
                    $kk = $kaskaden[(int) $s->id] ?? [];
                    if (($kk['scope'] ?? null) !== $filterTyp && ($kk['owner_type'] ?? null) !== $filterTyp) {
                        return false;
                    }
                }

                return true;
            })->values();
        }

        // Baum: Kategorie → Sessions (Frei-Bucket für ohne-Trend). Auf der gefilterten Liste.
        // UX: „prüfen" je Kategorie zuerst (offene Freigaben oben), sonst die bestehende updated_at-Reihenfolge
        // (sortBy ist stabil).
        $baum = $sessions->groupBy(fn ($s) => $s->category ?: '__frei')
            ->map(fn ($grp, $cat) => [
                'category' => $cat === '__frei' ? 'Frei / ohne Kategorie' : $cat,
                'sessions' => $grp->sortBy(fn ($s) => ($kaskaden[(int) $s->id]['status'] ?? 'entwurf') === 'prüfen' ? 0 : 1)->values(),
            ])->values();

        $active = $this->aktiveSession();
        // 2.1d: Composer, Trendradar und Auswahllisten nur für den aktiven Reiter ({@see PlanungReiterDaten}).
        $reiter = app(PlanungReiterDaten::class);
        $composerDaten = $reiter->composer($team, $this->editorTab === 'composer', array_map('intval', array_column($this->composerAnker, 'id')),
            (string) $this->composerTerm, $this->composerCategory, $this->composerFocus, $this->composerModus);

        // Fokus-Label (und Fokus verwerfen, wenn der Anker nicht mehr in der Auswahl ist).
        $composerFokusLabel = null;
        if ($this->composerFocus !== null) {
            foreach ($this->composerAnker as $a) {
                if ((int) $a['id'] === $this->composerFocus) {
                    $composerFokusLabel = $a['label'];
                    break;
                }
            }
            if ($composerFokusLabel === null) {
                $this->composerFocus = null;
            }
        }

        // Worker-Präsenz PROAKTIV (Etappe 8, Teil 2): die Ampel des Heartbeat-Service (Teil 1) VOR dem
        // Go zeigen — ergänzend zum reaktiven Watchdog `hinweis` (der erst nach ~90 s eines hängenden
        // Laufs anschlägt). Ist kein lebender `queue:work` da (`still`/`unbekannt`), bleibt jeder Go in
        // der Queue liegen → der Nutzer sieht nur einen Spinner. Rein lesend/fail-soft; `gesund` = keine
        // Warnung (Prompt/Fläche byte-unverändert wie bisher).
        $workerStatus = app(WorkerHealthService::class)->status();
        $workerState = $workerStatus['state'];
        $workerAlter = $workerStatus['alter_sek'] ?? null;
        $workerWarnung = $workerState === 'gesund'
            ? null
            : 'Die Hintergrund-Erstellung läuft gerade nicht. Neue Aufträge warten in der Warteschlange, bis sie wieder anläuft.';
        // Board-Poll-Gate: nur pollen, wenn irgendein Lauf tatsächlich läuft (kein Dauer-Poll im Ruhezustand).
        $irgendeinLaeuft = collect($kaskaden)->contains(fn ($k) => (bool) ($k['running'] ?? false));
        $laufendeIds = collect($kaskaden)->filter(fn ($k) => (bool) ($k['running'] ?? false))->pluck('run_id')->filter()->values()->all();

        // Spec 53 / Paket C: globaler KI-Status (Board-Kopf + über der Editor-Tab-Leiste) — EIN Aggregat
        // über alle Steps des Teams, unabhängig davon, ob gerade ein Lauf im Cockpit offen ist.
        $kiStatus = $team !== null ? app(PlanningCascadeService::class)->kiStatusFuerTeam($team) : null;

        // E1b (Spec 40): Owner-Kontext der offenen Session — für Banner „Planung für Foodbook ‚Adler'"
        // + Zurück-Link. null bei freier Cockpit-Planung ohne Ausgabe-Owner (dann kein Banner).
        $ownerKontext = ($team !== null && $active !== null)
            ? app(PlanningCascadeService::class)->ownerKontext($team, (int) $active->id)
            : null;

        // Spec 55 Nachtrag (Agent-am-Brief): der Formularstand geht bei JEDEM Render an alle
        // gemounteten Agenten-Panels (Browser-Event, kein `#[Reactive]`-Prop — Livewire kennt
        // das für Geschwister-Komponenten nicht). EIN Dispatch-Ort statt an jeder Mutations-
        // Stelle (reglerPill/updated/leitplankenAusBriefing/...) einzeln — `render()` läuft
        // nach JEDER Interaktion, ein verpasster Aufrufer kann die Panels nicht veralten
        // lassen. Nur die AGENT_SCHREIBBARE_REGLER-Teilmenge + Brief je Scope, nicht der ganze
        // Regler-Satz (Payload klein halten, kein Datenleck an Felder, die der Agent nie liest).
        foreach (self::SCOPES as $scope) {
            $this->dispatch('voice.formularstand-aktualisiert', scope: $scope,
                regler: array_intersect_key($this->regler[$scope] ?? [], array_flip(self::AGENT_SCHREIBBARE_REGLER)),
                brief: (string) ($this->eingabe[$scope]['brief'] ?? ''),
            );
        }

        return view('foodalchemist::livewire.planung.index', [
            ...$composerDaten, ...$reiter->auswahl($team, $this->editorTab),
            // Spec 55: Agenten-Panel — sichtbar, wenn das Team es nicht abgeschaltet hat
            // (Default AN). Eigener Schlüssel, siehe TeamSettingsService::voiceAgentPanelPlanung().
            'agentPanelSichtbar' => $team !== null && app(TeamSettingsService::class)->voiceAgentPanelPlanung($team),
            'sessions' => $sessions,
            'baum' => $baum,
            'kaskaden' => $kaskaden,
            'ownerKontext' => $ownerKontext,
            'workerState' => $workerState,
            'workerAlter' => $workerAlter,
            'workerWarnung' => $workerWarnung,
            'kiStatus' => $kiStatus,
            'irgendeinLaeuft' => $irgendeinLaeuft,
            'laufendeIds' => $laufendeIds,
            // CQ 2.5b: Saison, Anlass, Eventtyp, Service-Art aus den DB-Vokabularen (Einstellungen › Konzepter-Dimensionen)
            'planungVokabular' => collect(\Platform\FoodAlchemist\Support\Planung\PlanungVokabular::MODELLE)->map(fn ($m, $feld) => app(\Platform\FoodAlchemist\Support\Planung\PlanungVokabular::class)->optionen($team, $feld))->all(),
            'active' => $active,
            'composerFocus' => $this->composerFocus,
            'composerFokusLabel' => $composerFokusLabel,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
