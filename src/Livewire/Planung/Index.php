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
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyPlanung;   // Spec 80 Teil D; #1003 PR7 Handy-Umfang

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

    /** Deep-Link `?session=X&open=1` (z.B. vom Trendradar-Carry-in) öffnet den Editor direkt. */
    public function mount(): void
    {
        // Spec 85 C1 PR-4: Tab-Eingaben und Leitplanken je Scope leben im Kind Planung\Brief (eigener mount()).
        if (request()->boolean('open') && $this->sessionId !== null && $this->aktiveSession() !== null) {
            $this->ladeForm();
            // Der Speiseplan-Editor leitet nach dem Go HIERHER um (mit `open=1`), und seine eigene
            // Komponente stirbt mit dem Redirect — ein dort gesetzter Hinweis wäre nie gerendert.
            // Ohne dieses Laden bliebe die Landung also stumm, obwohl der Deckel am Lauf steht.
            $this->ladeLetztenLauf();
            // Spec 53/D: Sprachbefehl-Handoff (VoiceModal::planungStarten()) — `?tab=` sagt, auf
            // welchem Scope-Tab der Editor öffnet; ohne bekannten Wert bleibt tabInit vom Modal (Default 'gericht').
            // Das Vorbefüllen des Tab-Briefings aus der Session macht Planung\Brief::mount() (Spec 85 C1 PR-4).
            $tab = in_array(request('tab'), ['basisrezept', 'gericht', 'concept'], true) ? request('tab') : null;
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
        // Das Briefing (Ziel-Ebene überschreiben, andere nur leer füllen) setzt Planung\Brief auf dasselbe Event (Spec 85 C1 PR-4).
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

    /**
     * Spec 85 C1 PR-4: Planung\Brief hat einen Lauf gestartet. Parent übernimmt laufId (das Kind KaskadenLauf mountet neu)
     * und lädt den Session-Spiegel nach, den der Go geschrieben hat (sonst schriebe „Planung speichern“ einen alten Brief zurück).
     */
    #[On('planung.lauf-gestartet')]
    public function laufGestartet(int $laufId): void
    {
        $this->laufId = $laufId;
        $this->laeuft = true;
        $this->ladeForm();
    }

    /** Spec 85 C1 PR-4: Planung\Brief hat Brief/Kreativ-Modus auf die Session gespiegelt (KI-Kopf) → Spiegel nachladen. */
    #[On('planung.brief-gespiegelt')]
    public function briefGespiegelt(): void
    {
        $this->ladeForm();
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

        // Spec 85 C1 PR-4: Suchbegriffe, Trend-Vorbefüllung, KI-Kopf-Plan und Leitplanken liest Planung\Brief::mount() aus der Session.
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


    /**
     * EU-14-Allergen-No-Go (L3): Key => Label. Harter Ausschluss (getrennt vom Diät-Ausschluss) —
     * ein verdrahteter GP, der ein angehaktes Allergen »enthält«, wird im Diät-/Allergen-Gate
     * entdrahtet + gemeldet. Keys spiegeln FoodAlchemistGp::ALLERGEN_FIELDS 1:1.
     */
    public const ALLERGEN_LABELS = \Platform\FoodAlchemist\Support\Allergene::KURZ;

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

        $this->diktatAnhaengen($ziel, $text);
        $this->fehler = null;
        $this->meldung = 'Diktat übernommen — bitte gegenlesen.';
    }

    /**
     * Kurskorrektur „pro Tab genau EINE Diktierfunktion" (2026-09-19): das Agent-Panel ist in
     * den drei Creation-Scopes der einzige Diktier-Weg (der alte Recorder-Knopf dieses Tabs
     * ist raus, siehe erstellen-tab.blade.php). Sein eigenes Mikro transkribiert selbst
     * (VoiceModal::updatedAudio()) und liefert das Rohtranskript hier an — Richtung also
     * UMGEKEHRT zur vorherigen Fassung (die ging vom alten Diktat-Knopf ZUM Panel). Gleiche
     * Anhängen-nie-überschreiben-Semantik wie briefDiktatUebernehmen()/diktatAnhaengen().
     */
    #[On('voice.diktat-transkribiert')]
    public function agentDiktatUebernehmen(string $scope, string $text): void
    {
        if (! in_array($scope, self::SCOPES, true) || trim($text) === '') {
            return;
        }
        $this->diktatAnhaengen('eingabe.' . $scope . '.brief', $text);
        $this->meldung = 'Diktat übernommen — bitte gegenlesen.';
    }

    /**
     * Text an das Whitelist-Ziel ANHÄNGEN (nie ersetzen). `eingabe.<scope>.brief` lebt im Kind Planung\Brief (Spec 85 C1 PR-4):
     * dorthin geht der Text per `planung.brief-uebernehmen`, das Kind hängt an. Die flachen Ausgabeform-Briefings (fbBrief/…)
     * bleiben hier bis E4 (Kurator: Diktat bis E4 komplett im Parent).
     */
    private function diktatAnhaengen(string $ziel, string $text): void
    {
        if (str_starts_with($ziel, 'eingabe.')) {
            $this->dispatch('planung.brief-uebernehmen', scope: explode('.', $ziel)[1] ?? '', werte: ['brief_anhaengen' => $text]);

            return;
        }
        $alt = trim((string) ($this->{$ziel} ?? ''));
        $this->{$ziel} = $alt === '' ? $text : $alt . ' ' . $text;
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
        // Spec 85 C1 PR-4: Brief-Vorbefüllung (nur wenn leer) und Seed-Pin gehen an Planung\Brief, das den Go macht.
        $labels = array_values(array_filter(array_map(fn ($a) => trim((string) ($a['label'] ?? $a['slug'] ?? '')), $this->composerAnker)));
        $this->dispatch('planung.brief-uebernehmen', scope: $scope, werte: [
            'brief_wenn_leer' => 'Foodpairing-Kreation auf Basis von: ' . implode(', ', $labels) . '.',
            'seed_pin' => $slugs,
        ]);
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

        // Spec 55 Nachtrag: den Formularstand an die Agenten-Panels dispatcht Planung\Brief bei jedem eigenen Render (Spec 85 C1 PR-4).

        return view('foodalchemist::livewire.planung.index', [
            ...$composerDaten, ...$reiter->auswahl($team, $this->editorTab),
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
            'active' => $active,
            'composerFocus' => $this->composerFocus,
            'composerFokusLabel' => $composerFokusLabel,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
