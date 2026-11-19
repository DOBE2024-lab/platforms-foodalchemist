# 97 Code-Qualitäts-Audit (Zwischenbericht Phase 0 + 1)

Stand: 2026-11-17 · Methode: nur lesende Analyse (grep, Referenzzählung, Code lesen), drei parallele Stränge, Stichproben nachgeprüft · Status: Entwurf

Nichts ist gemessen. Abfrage-Zahlen sind aus dem Code geschätzt und werden in Phase 2 per Test belegt.

## Größe

| Bereich | Umfang |
|---|---|
| `src/Services` | 435 Dateien, ~147k Zeilen, 260 Dateien flach im Root |
| `src/Tools` (MCP) | 722 Tools in `_liste.php` (Stand 19.11.), ~62k Zeilen |
| `src/Livewire` | 184 Dateien, ~54k Zeilen |
| Blade | 394 Dateien, ~60k Zeilen |
| Tests / Migrationen / Commits | 1.164 / 510 / ~3.100 |

**Hotspots** (Größe × Änderungen der letzten 6 Monate): `Livewire/Planung/Index` (3.427 Z., 132 Änderungen), `PlanningCascadeService` (3.542 Z.), `FoodAlchemistServiceProvider` (914 Z., 348 Änderungen), `FoodbookService`, `PairingService`, `KnowledgeContextService`, `Foodbooks/Index`, `RecipeGeneratorService`.

## Ampeln

| # | Frage | Ampel | Kern |
|---|---|---|---|
| 1 | Headless / MCP-Parität | 🟡 | Tools sind fast immer Adapter auf Services (nur ~8 kleine Direkt-Schreiber). Aber: „X aus Brief“-Abläufe doppelt in Livewire und Tool, ~20 Livewire-Dateien mit Schreiblogik, mehrere Funktionen nur im UI. |
| 2 | Modulgrenzen | 🟡 / 🔴 im Kern | Wissen/KI sauber. Rezept ↔ Concepter ↔ Foodbook ↔ Kaskade zyklisch verflochten; `PlanningCascadeService` hält 22 Services zusammen. |
| 3 | Datenmodell GP → Rezept → Gericht → Concept → Foodbook | 🔴 | Paket doppelt (alte Tabelle + Concept `kind=paket`), UI und MCP schreiben in verschiedene Modelle. Gespiegelte Felder, Fremdschlüssel nur im Service. |
| 4 | Zyklen bei Basisrezepten | 🟡 | Keine Endlosschleife möglich. Aber 4 Schreibwege ohne Prüfung, uneinheitliches Verhalten im Zyklusfall, Tiefe-3-Abschnitte trotz A-5. |
| 5 | Unnötige Abfragen | 🟡 / 🔴 Planung | Recompute ohne GP-Memo (~5–6 Abfragen je Zeile), synchrone Eltern-Neuberechnung im Request, Planungs-Poll rendert alle 1,5 s die ganze 3,4k-Komponente. |
| 6 | Doppelter / toter Code | 🟡 | Verdrahtung sauber (Tool-Liste per Test erzwungen). Kopierte Familien (4 Fassungs-Services, Wareneinsatz 18× von Hand), ~2.050 Zeilen sicher tot, 66 Einmal-/Mess-Commands (~9.600 Z.). |
| 7 | Taxonomie Schema vs. Code | 🟡 / 🔴 | Verkaufsachse sauber in der DB. Bedeutung der Warengruppen als Code-Literale (`'15'`, `'16'`, `'09.6'`), Planung führt Saison/Anlass/Serviceform/Sektor hart im Code. |
| 8 | Tailwind gebündelt? | 🟡 | `x-fa`-Bausteine stark genutzt (button 1.066×). Keine Bausteine für Typografie (~1.000 Stellen), Menü, Checkbox, Tabelle, Karte. `$leise` in 58 Dateien neu definiert. |
| 9 | Vereinfachung | 🔴 | 11 Livewire-Komponenten > 1.000 Z., 11 Services > 1.500 Z., Pakete und Concepts/Pakete als zweite Oberfläche neben dem Concepter. |

## Kritische Befunde (falsche Zahlen oder Sicherheit)

| ID | Befund | Ort | Geprüft |
|---|---|---|---|
| K1 | `kalkulation.GET` mit `package_id` sucht in der alten Tabelle `foodalchemist_packages`. Neue Pakete (Concept `kind=paket`) werden nicht gefunden, oder es trifft ein falsches altes Paket mit gleicher ID. | `src/Tools/KalkulationGetTool.php:80` | ✅ |
| K2 | Preisänderung (ItemModal, MCP, Preislisten-Import) markiert nur `recipe_team_kosten` als veraltet. `ek_total_eur` am Rezept wird nicht nachgerechnet, aber an ~120 Stellen gelesen (u. a. `RecipesGetTool`, `KalkulationService`). | `PriceService.php:470`, `KostenCacheService.php:105` | ✅ am 17.11.; **seit 18.11. behoben** (`KostenFolgeJob`, Commits `0bbc6056`, `35528f62`) – offen nur Fehlerbehandlung/Eindeutigkeit, s. 98 · 1.1b |
| K3 | `ConceptService::fillSlot` prüft nicht, ob das eingesetzte Rezept/Paket für das Team sichtbar ist und ob ein Gericht wirklich ein Gericht ist. Livewire reicht Browser-IDs ungeprüft durch. | `ConceptService.php:463`, `Livewire/Concepts/Index.php:319` | ✅ |
| K4 | Paket in zwei Modellen: UI (`/pakete`, Concepter Typ `pakete`) schreibt über `PaketService` in die alte Tabelle, MCP über `ConceptPaketService` in Concepts. 145 Stellen nutzen noch `package_id`. | `ConceptPaketService.php:8`, `Concepter/Editor.php:399` | Strang A + C unabhängig |
| K5 | **Nachtrag Codex-Gegenprüfung 19.11.:** Allergen-/Diät-Rollup, Nährwerte und EK eines Concepts ignorieren eingebettete Pakete des neuen Modells (`embedded_concept_id`); nur der Preis berücksichtigt sie. | `ConcepterAggregateService.php:175`, `ConceptService.php:873` | ✅ |

## Befunde nach Bereich

### Headless und MCP-Parität

- **Doppelte Use Cases:** Foodbook/Speisekarte/Speiseplan/Angebot/Format „aus Brief“ sind in `Planung/Index.php:838-1225` und in 5 `*PlanFromBriefTool` jeweils eigen programmiert.
- **Schreiblogik im UI:** 12 Settings-Seiten (Stammdaten-CRUD ohne Service), `Recipes/StepEditor`, `Concerns/ManagesCanvas` (11 Komponenten), `Gps/DetailPanel:322` (KI-Allergene), `Knowledge/Browser:454` (hartes Löschen trotz Soft-Delete).
- **Nur im UI, per MCP nicht erreichbar:** Foodbook löschen/duplizieren, Kapitelbilder/Cover/Logo, manuelle Produktionszeilen, Concept-Kalkulation mit Gästezahl, Dichteklasse/Feld-Herkunft, Einzelschritte und Schrittfotos, Team-Neuberechnung nach Preis-Einstellungen.
- **Schichtverletzung:** Services/Tools lesen Konstanten aus Livewire-Klassen (`VoiceCommandService:336`, `KiKlassenGetTool:9`).

### Modulgrenzen

Abhängigkeiten (Paare Datei→Klasse, Näherung):

| von \ nach | Rezept | Concepter | Foodbook | Produktion | Kaskade | Wissen |
|---|---|---|---|---|---|---|
| Rezept | – | 6 | 5 | 3 | 7 | 28 |
| Concepter | 20 | – | 2 | 1 | 5 | 5 |
| Foodbook | 3 | 5 | – | 0 | 2 | 2 |
| Produktion | 14 | 4 | 3 | – | 0 | 0 |
| Kaskade | 12 | 10 | 5 | 0 | – | 1 |
| Wissen | 3 | 3 | 1 | 0 | 1 | – |

Kreise: `RecipeDependencyWorkflowService` ↔ `PlanningCascadeService`, `PlanningCascadeService` ↔ `FoodbookService`/`PlanningFrameService`, `RecipeRecomputeService` → `PaketService` (Concepter) bei 20 Rückaufrufen.

### Datenmodell

- Basisrezept, Gericht und Fassung in einer Tabelle `foodalchemist_recipes` (`is_sales_recipe`, `ist_fassung` + globaler Scope). Vertretbar, aber rohe Zählungen zählen Fassungen mit (`Dashboard.php:55`, `KpiService.php:41`).
- Zutat zeigt auf `gp_id` **oder** `referenced_recipe_id`; „genau eines“ prüft nur der Service.
- Concept-Slot zeigt auf `package_id` (alt), `embedded_concept_id` (neu) oder `sales_recipe_id` (auch Basisrezepte!). Die Kette weicht von der Doku ab: Basisrezept direkt im Concept, Gericht direkt im Foodbook-Block.
- Darreichungsfelder am Rezept werden auf die Standard-Darreichung gespiegelt (`SalesRecipeService.php:545`).
- Concept-Slot, Foodbook-Block und Angebots-Block sind drei fast gleiche Strukturen mit eigenen Staffel-Tabellen.
- Viele Verweise ohne Fremdschlüssel in der DB; 47 veraltete Beziehungs-Aliase.

### Zyklen

- Zentraler Schutz `RezeptGraphService::pruefeVerknuepfung` (`Rezept/RezeptGraphService.php:18`) deckt `syncIngredients` und damit UI, MCP, KI-Generator, Import, Tausch ab.
- **Ohne Prüfung:** `ComponentEquivalentService::tauscheZutat:189`, `ImportSliceCommand:429`, `AboKuendigungService::haengeUm:893`, `FassungsUmstellung:44`. Kein Lock zwischen Prüfen und Schreiben.
- **Im Zyklusfall:** `recomputeAll`/`kostenFuerTeam` werfen eine Exception, `topoOrder` rechnet still mit veralteten Werten, `explodiere` (Produktions-/Bestellbedarf) bricht ab und liefert vermutlich still falschen Bedarf.
- **Tiefenregel uneinheitlich:** A-5 (03.08.2026) hat das 3-Ebenen-Limit aufgehoben, aber Sensorik-Nährwerte, Convenience-Quote, Aromenprofil, Handbuch, Machbarkeit, KI-Kontext schneiden weiter bei 3 ab; `PROPAGATION_LIMIT=10`; `docs/rezepte.md:14` sagt noch „drei Ebenen“.

### Abfragen

| Stelle | Problem | Größenordnung (geschätzt) |
|---|---|---|
| Recompute (`RecipeRecomputeService:95ff`, `GpAggregateService`) | 5–6 Abfragen je GP-Zeile, `laProfile` doppelt, LA-Cache je Rezept geleert | Gericht mit 12 Zeilen ≈ 70; Preisänderung über 300 Rezepte ≈ 20.000 |
| `syncIngredients` → `recomputeAndPropagate` | Alle Eltern synchron im Request (widerspricht ARCHITEKTUR §8) | Fond in 100 Gerichten = 100 Pipelines je Speichern |
| `pruefeVerknuepfung` | Berechnet nie gelesene Tiefen, prüft auch unveränderte Zeilen | ~4 Suchen je Unterrezept-Zeile je Speichern |
| Planung „Freies Gericht“ → Kaskade | `wire:poll.1500ms="pruefeLauf"` rendert ganze `Planung/Index`; Lauf doppelt geladen, `LaufCockpit::cockpit()` je Rezept, Composer/KI-Status/Trends auch für inaktive Tabs | 60–80 Abfragen je Poll ≈ 40–50/s je offenem Tab |
| Concepter Aufbau-Tab | ~5 Abfragen je Slot je Render | 12 Slots ≈ 60 |
| Eltern-Neuberechnung | `PlanningCascadeService:2671`, `RecipeDependencyWorkflowService:895` rechnen dieselben Eltern n-mal | – |

### Doppelter und toter Code

- **Kopierte Familien:** `FassungService`, `ConceptFassungService`, `FormatFassungService`, `PaketFassungService` (~85 % gleich). Wareneinsatz `round($ek / $vk * 100, 1)` 18× statt `MargeService`. EK je Portion 3× statt `DarreichungResolver::ekProPortion`. Umlaut-Normalisierer in 27 Dateien mit **unterschiedlichen** Regeln (Risiko für Matching/Dubletten). Allergen-Labels 3× (`egg` vs. `eggs`). `RecipeModal` ≈ `VkModal` (23 gleichnamige Methoden). kg↔g 7× von Hand.
- **Sicher tot (~2.050 Z.):** `Livewire/Kalkulator/Index` + View + `KalkulationDokService`; `Settings/KonzeptTaxonomie` + View; `Foodbooks/LeitstelleRail`, `Foodbooks/KundeDnaPanel` + Views; 7 Blade-Partials; 7 Komponenten (`chips`, `kpi-bar`, `master-detail`, `panel-section`, `section`, `tree-node`, `tri-state` – nur Selbstnennung im Docblock).
- **Wahrscheinlich tot:** `Concepts/Index` + `Pakete/Index` (1.364 Z., nicht in Navigation), `Settings/Einsatzorte`.
- **Console:** 41 Einmal-Läufe (6.082 Z.) + ~1.100 Z. nur von ihnen genutzte Services; 25 Mess-/Eval-Commands (3.549 Z.).
- **Altlasten:** 9 ungetrackte Import-Artefakte im Modul-Root (~1 MB), `database/database.sqlite` (0 Byte, getrackt), `docs/UI_INVENTAR.md` seit 08.10. veraltet.

### Taxonomie

| Taxonomie | Wahrheit | Hart im Code | Ohne Deployment änderbar? |
|---|---|---|---|
| Warengruppen (PKL = alte WaWi-Nummerierung, Verdacht) | DB `lookup_commodity_groups` | 29 Vergleiche in 16 Dateien (`'15'`, `'16'` …) trotz `NonFoodRegeln::WG_CODE` | nur Namen, nicht Bedeutung |
| WG-Unterkategorien | DB | 14 Literale in 7 Dateien | nein |
| Speisen-Klasse / Hauptgruppe | DB | `PlanningFrameRule::DIET_FORMS` spiegelt DB | weitgehend |
| Preisklasse | DB | – | ja |
| Servierform | DB | `'unbestimmt'` + `Planung::SERVICEFORM_OPTIONEN` | teilweise |
| Anlass, Saison | DB | `Planung::OCCASION_OPTIONEN`, `SAISON_OPTIONEN` | in der Planung nein |
| Sektor | nur Code, 3 abweichende Fassungen | Planung, Wissen, Trend | nein |
| Zustand | Code (Regelwerk §9, bewusst) | 42 Literale in 18 Dateien | nein |
| Gänge / Menüfolge, Kostform | Code | mehrere | nein |

### UI und Tailwind

- Bausteine vorhanden und genutzt: `x-fa::button` 1.066×, `field` 954×, `input` 849×, `badge` 815×.
- Umgangen: 635 rohe `<button>`, ~100 `<input>`, 58 `<select>`, 157 Checkboxen, 37 Uploads.
- Ohne Baustein: Typografie (1.997× `text-[length:var(--fa-text-*)]`, „leise“ 448× + `$leise` in 58 Dateien), Dropdown-Menü (64× in 42 Dateien), Tabelle (216), Karte (243).
- Drei Wege nebeneinander: `x-foodalchemist::` (41), `x-fa::` (21), `Support/Ui::maps()`.

## Top-10-Maßnahmen (Vorschlag, Reihenfolge = Priorität)

| # | Maßnahme | Befunde | Aufwand | Welle |
|---|---|---|---|---|
| 1 | `kalkulation.GET` auf Concept `kind=paket` umstellen | K1 | S | 1 |
| 2 | Preisänderung stößt Recompute der Rezept-Spalten an (Queue-Job über `rezepteMitGps` → `recomputeMany`) | K2 | S–M | 1 |
| 3 | Sichtbarkeits- und Typprüfung in `fillSlot` und allen Service-Schreibmethoden mit Fremd-IDs, je ein Fremd-Team-Test | K3 | S | 1 |
| 4 | Zyklusschutz als `saving`-Hook der Zutat mit Lock; im Zyklusfall Signal statt stiller Werte; Tiefenregel vereinheitlichen | Zyklen | S–M | 1 |
| 5 | Aufräum-PR: sicher toter Code, 41 Einmal-Commands archivieren, Root-Artefakte raus, UI_INVENTAR neu | Tot | S | 1 |
| 6 | Planungs-Poll in schlanke Status-Komponente, Daten nur für aktiven Tab | Abfragen | M | 2 |
| 7 | Recompute: Lauf-Memo je GP, Batch-Abfragen, Eltern asynchron; `pruefeVerknuepfung` verschlanken | Abfragen | M | 2 |
| 8 | Zentrale Helfer: Wareneinsatz, EK/Portion, Einheiten, ein Normalisierer, eine Allergen-Liste + Grep-Sperre im Regel-Test | Duplikate | S | 2 |
| 9 | „X aus Brief“ als je eine Service-Use-Case-Methode; MCP-Lücken schließen; Settings-/StepEditor-Schreiblogik in Services | Headless | M | 2 |
| 10 | Paket→Concept abschließen, `/concepts` + `/pakete` entfernen, Fassungs-Basisklasse, `Planung/Index` und `PlanningCascadeService` zerlegen, Taxonomie-Rollen in die DB, UI-Bausteine `text`/`menu`/`checkbox`/`table`/`card` | Struktur | L | 3 |

## Nächste Schritte

- **Phase 2 – Messen:** Feature-Tests mit `DB::listen`-Zähler: (1) 4-stufiger Rezeptbaum, Speichern am Fond; (2) Preisänderung an einem LA, prüfen ob `ek_total_eur` des Gerichts sich ändert; (3) Zyklus per `DB::table` einfügen und alle rekursiven Dienste aufrufen; (4) `tauscheZutat` mit Zyklus; (5) `Planung\Index::pruefeLauf` mit laufendem Lauf; (6) Concepter mit 12 Slots; (7) Allergen auf Ebene 5.
- **Phase 3:** Befunde mit Messwerten finalisieren.
- **Phase 4:** Wellen 1–3 als Issues/Planner-Aufgaben schneiden → Umsetzungsplan in [98](98_Umsetzungsplan_Code_Qualitaet.md).

Offene Frage: Bedeutung von „PKL“ fachlich bestätigen (vermutlich alte WaWi-Warengruppen-Nummerierung).

---

## UI-Usertest Teil A – Expertentest (2026-11-17)

Umgebung: foodalchemist.io, Mandant **Sandbox** (38 Rezepte, alle im Status Entwurf), Desktop 1512 px. Testdaten mit Präfix `UX-TEST`. Ergänzend kurz die lokale Sandbox (gleicher Code, dort fehlt die Plattform-Navigation).

### Aufgaben

| # | Rolle | Aufgabe | Ergebnis | Aufwand |
|---|---|---|---|---|
| 1 | Küchenchef | GP suchen, Preis und Allergene prüfen | erledigt, aber Allergen-Anzeige irreführend (U1) | 3 Aktionen + Tab |
| 2 | Küchenchef | Basisrezept mit Unterrezept anlegen | Rezept ja, **Unterrezept blockiert** (U2) | ~12 Aktionen |
| 3 | Kalkulation | Gericht: EK/VK/Marge finden | erledigt, sehr gut | 1 Klick |
| 4 | Konzepter | Concept mit Gerichten bauen | **blockiert** (U2) | – |
| 5 | Konzepter | Foodbook-Vorschau | Vorschau gut; Erzeugen nicht testbar (hängt an 4) | 1 Klick |
| 6 | Produktion | Tagesplan ansehen | Seite ok, keine Daten im Mandanten | – |
| 7 | Einkauf | Bestellungen | leer, nicht bewertbar | – |
| 8 | Planung | Board ansehen (KI-Lauf nicht gestartet) | Board horizontal abgeschnitten (U4) | – |

Ladezeiten: TTFB 0,3–0,45 s, Seite fertig 0,55–0,75 s, GP-Suche ~0,75 s. Planung im Leerlauf pollt nur alle 10 s (`wire:poll.10s.visible`); der 1,5-s-Poll aus Strang B greift nur während eines KI-Laufs und wurde nicht gemessen.

### Befunde

| ID | Befund | Wo | Schwere | Bezug zum Code |
|---|---|---|---|---|
| U1 | Kopfkachel „Allergenangaben: **none**“ bei Zanderfilet (Fisch). Englisch und liest sich wie „allergenfrei“; der Tab sagt „14 von 14 ohne Angabe (unbekannt)“. Status trotzdem „Freigegeben“. Live und lokal gleich. | GP-Modal | hoch | Rohwert statt Label; Allergen-Labels 3× im Code |
| U2 | Picker blenden Entwürfe aus, **ohne Hinweis**: Zutaten-Editor zeigt „Basisrezepte 0“, Concepter-Picker zeigt Chip „HG 3“, Liste „0 Gerichte“. Neue Rezepte starten als Entwurf → ein frisch angelegter Fond ist nicht als Unterrezept nutzbar, ein Concept aus eigenen neuen Gerichten nicht baubar. | Zutaten-Editor, Concepter | hoch | `IngredientEditor.php:456`, `PaketService.php:533` (Zähler zählt Entwürfe, Liste nicht – Kommentar fordert gleiche Semantik) |
| U3 | Uneinheitliches Anlegen: Basisrezept = Dialog zuerst; „Neues Concept“ legt sofort einen Datensatz an. Ein verwaistes „Neues Foodbook“ im Mandanten deutet auf dasselbe Muster. | Concepter, Foodbook | mittel | – |
| U4 | Uneinheitliches Detail-Muster: GP-Liste kündigt rechts „anklicken, dann erscheinen hier …“ an, der Klick öffnet aber ein Vollbild-Modal. Concepter/Foodbook nutzen das Panel. Das leere Panel kostet ~¼ der Breite; GP-Namen brechen dreizeilig um, das Planungs-Board ist abgeschnitten. | GPs, Planung | mittel | drei Layout-Wege (Strang C) |
| U5 | Widersprüchliche Zahlen: Foodbook-Vorschau „28,50 € pro Person“, Detail-KPI „VK/Person: Preis fehlt“. Rezept-Kopf „Preis fehlt“, während die Zutatentabelle schon 1,92 € rechnet (bis Speichern). Gericht-Kopf EK 2,61 €, Tabellensumme 2,60 €. GP-Pill „Alle 9.271“ bei 26 Treffern. | Foodbook, Rezept, Gericht, GPs | mittel | mehrere Rechenwege (Strang C Duplikate) |
| U6 | Doppelte Felder sichtbar: Gericht-Kalkulation „Verkaufseinheit – g je Einheit“ leer, darunter Darreichung 324 g. | Gericht › Kalkulation | mittel | Strang A B11 (gespiegelte Felder) |
| U7 | Suche: „Butter“ listet Butteraroma und Butter-Alternativen vor „Butter: frisch“. Treffer erscheinen in der linken Spalte, das Suchfeld liegt rechts (zwei Picker in einem Editor). | Zutaten-Editor | mittel | – |
| U8 | Benennung: eine Seite heißt „Tagesplan“ (Navi), „Tagesplanung“ (Kopf), „Auslastung und Tageshorizont“ (H1), „Küchenleiter-Dashboard“ (Eyebrow). „Bearbeiten“ ist gleichzeitig Modus-Knopf und Ansicht-Umschalter. Concept/Konzept gemischt. Formular zeigt technische Kennung (`ux_test_sauce_beurre_blanc`). | mehrere | niedrig | – |
| U9 | Löschen ist schwer zu finden und zu bedienen: „Concept löschen“ steht nur im Menü des Seitenpanels, nicht im Menü des Editors (dort heißt Drucken auch anders: „Kundenkarte drucken“ vs. „Karte drucken“). Im Ansichtsmodus antwortet der Eintrag nur mit dem Toast „Zum Ändern zuerst ‚Bearbeiten‘ klicken“. Bestätigt wird mit dem nativen Browser-Dialog `confirm()` statt eines App-Dialogs. Rezepte: Löschen nur im Editor-Menü, nicht in der Mehrfachauswahl. | Concepter, Basisrezepte | mittel | – |
| U10 | Zwei Farbwelten: helle Seiten, dunkle Modals und Panels; harter Kontrastwechsel bei jedem Öffnen. | global | niedrig | – |

**Positiv:** Gericht öffnen → VK, Wareneinsatz und EK sofort sichtbar; Darreichungen verständlich; Live-Rechnung im Zutaten-Editor; Foodbook-Gastvorschau gut lesbar; Ladezeiten unkritisch.

**Nicht getestet:** Navigation mit echten Datenmengen, Handy-Ansicht und Wandmonitor, Etiketten, laufender KI-Lauf in der Planung.

### Voraussetzung für Teil B

Der Sandbox-Mandant braucht **freigegebene** Testdaten (mind. 5 Basisrezepte, 8 Gerichte über 3 Hauptgruppen, 1 Concept, Produktionsaufträge für morgen). Sonst scheitern Testpersonen an U2 statt an der eigentlichen Bedienung.

## UI-Usertest Teil B – Leitfaden für echte Nutzer

**Wer:** 3–5 Personen aus den Caterern: 2 Köche, 1 Kalkulation/Verkauf, 1 Produktionsleitung, optional 1 Konzeptentwicklung. Niemand aus dem Entwicklungsteam.
**Wie:** 45 Minuten je Person, Bildschirm geteilt, lautes Denken, ein Moderator, ein Protokollant. Moderator hilft nicht, fragt nur „Was erwarten Sie jetzt?“.

| Min | Block |
|---|---|
| 0–5 | Begrüßung: „Wir testen die Software, nicht Sie.“ Aufzeichnung klären. |
| 5–35 | Aufgaben (unten), je max. 5 Minuten, danach abbrechen und notieren |
| 35–40 | SUS-Fragebogen |
| 40–45 | Nachgespräch: Was hat am meisten genervt? Was würden Sie vermissen? |

**Aufgaben** (in Alltagssprache vorlesen, keine Menünamen nennen):

| # | Aufgabe | Erfolg, wenn … |
|---|---|---|
| T1 | „Was kostet uns ein Kilo Zanderfilet, und welche Allergene hat es?“ | Preis genannt und Allergenstatus richtig verstanden (unbekannt ≠ frei) |
| T2 | „Legen Sie eine Beurre Blanc an, die unseren Fischfond verwendet.“ | Rezept mit Unterrezept gespeichert |
| T3 | „Wie viel Wareneinsatz hat das Gericht Königsberger Klopse?“ | Wert genannt |
| T4 | „Stellen Sie ein Lunch-Buffet mit je zwei Vorspeisen, Hauptgängen und Desserts zusammen.“ | Concept mit 6 Positionen gespeichert |
| T5 | „Machen Sie daraus eine Mappe, die Sie dem Kunden schicken könnten.“ | Foodbook-Vorschau oder Dokument geöffnet |
| T6 | „Was muss der Saucier morgen produzieren?“ | Posten-Ansicht für morgen gefunden |

**Protokoll je Aufgabe:** Zeit · Klicks (grob) · gelöst ja/teilweise/nein · Sackgassen (wo zurück?) · Zitate · Schweregrad (1 kosmetisch … 4 blockiert).

**SUS-Fragebogen** (1 = stimme gar nicht zu … 5 = stimme voll zu):
1. Ich würde das System gern regelmäßig nutzen.
2. Ich fand das System unnötig kompliziert.
3. Ich fand das System leicht zu bedienen.
4. Ich bräuchte technische Hilfe, um das System zu nutzen.
5. Die Funktionen sind gut zusammengeführt.
6. Das System wirkt an vielen Stellen widersprüchlich.
7. Die meisten Kollegen würden das System schnell lernen.
8. Das System ist umständlich zu bedienen.
9. Ich fühlte mich bei der Bedienung sicher.
10. Ich musste viel lernen, bevor ich loslegen konnte.

Auswertung: ungerade Fragen (Wert − 1), gerade Fragen (5 − Wert), Summe × 2,5 → 0–100. Unter 68 = unterdurchschnittlich.

**Auswertung gesamt:** Befunde aus allen Sitzungen sammeln, nach Häufigkeit × Schwere sortieren, mit U1–U10 und den Code-Befunden abgleichen, in die Wellen 1–3 einsortieren.
