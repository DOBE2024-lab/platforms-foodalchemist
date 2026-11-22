# 3.3 Schnittplan: große Klassen zerlegen

Stand 22.11.2026 · Hans · nur Plan, kein Code. Gebaut wird auf dem gemergten main nach Charge 1 und 2.
Vermessen auf `refactor/cq-richtung-3-4` (main e23067a5 + 2.6b + 3.4). Zeilen schwanken bis zum Merge um wenige Prozent.
Baut auf Spec 85 (§5 C1, §6 C2, §9 C6) auf und ersetzt sie nicht.

**Ziel:** keine Klasse über 1.500 Zeilen; Rückkanten der Schichtregel (3.4) auf 0; die große Kreis-Komponente aufgelöst.
**Grundregel für jeden Schnitt:** Die alte Klasse bleibt **Fassade** mit gleichnamigen öffentlichen Methoden (1–4 Zeilen, delegieren),
bis alle Aufrufer umgezogen sind. MCP-Tools, Jobs und Tests rufen weiter die Fassade. Methoden werden **verschoben, nicht umgeschrieben**:
ein PR = Verschiebung + Fassade + Größen-Basis senken, keine Verhaltensänderung. Umzug der Aufrufer und Abbau der Fassade sind eigene PRs.

## 1 · Schnittlinien je Klasse

### A · `Services/PlanningCascadeService` (3.386 Z., 82 Methoden; Aufrufer: 13 Tools, 13 Jobs, 7 Livewire, 9 Services, 33 Testdateien)

| neue Klasse (`Services/Kaskade/…`) | Inhalt (Abschnitt heute) | ≈ Z. |
|---|---|---|
| `KaskadeAusgabeVoll` | P3/P4 Voll-Kaskade Ausgabe-Frame → Concept je Slot (`starteVollkaskade`, `legeStrukturAn` …) | 460 |
| `KaskadeSpeiseplanVoll` | P5 Speiseplan-Voll-Kaskade (`starteSpeiseplanVollkaskade`, `materialisiereSpeiseplanZelle` …) | 640 |
| `KaskadeErfinden` | P1b Fan-out (`fanoutConceptInvention`, `materialisiereConceptGericht`) + Kohärenz-Gate (`scoreConceptCohesionIfComplete`, `persistConceptCohesion`) | 390 |
| `KaskadeFreigabe` | Gate 2: Freigeben/Verwerfen/Erzeugen geplant/Regenerieren (`gibStepFrei`, `verwirfStep`, `regeneriereStep` …) | 930 |
| `KaskadeAbfragen` | Lese-Seite für Fläche und MCP (`laufStatus`, `letzterLauf` …) | 300 |
| **Fassade `PlanningCascadeService`** | Kopf, `starteKaskade`, Step-Status-Fassaden (3.4), Delegatoren | ≈ 600 |

Gemeinsame Helfer (Params, Lauf/Step-Anlage) zuerst in einen `KaskadeKontext` (≈ 150), den alle Teile nutzen, sonst rufen sich die Teile gegenseitig.

### B · `Services/OrderService` (3.740 Z., 127 Methoden; 18 Tools, 5 Livewire, 6 Services, 15 Testdateien)

Spec 85 §6 (C2) gilt: Zyklen A–D erst brechen, Charakterisierungstests für Reklamation/Kontingent, Herkunft, Mail/Dokument **vorher**.

| neue Klasse (`Services/Bestellung/…`) | Inhalt | ≈ Z. |
|---|---|---|
| `BestellBedarf` | Bedarf in Schienen (Abschnitt E9/E10: `previewFromSources`, `generateDraftsFromSources`, `previewZiel`, `nachbearbeitePreview`, Mengen-Quellen) | 1.050 → in 2 PRs: Vorschau (≈ 500) / Übernahme (≈ 550) |
| `BestellPflege` | Manuelle Pflege im Entwurf + Recompute + Status (`setStatus` …) | 680 |
| `BestellLesen` | `detail`, Aggregate, Summen (`OrderSummaries` aus Spec 85) | 700 |
| `BestellDokument` | `dokument`, Herkunft (Read-Model), Ampeln | 700 |
| **Fassade `OrderService`** | Schiene, Guards, Delegatoren | ≈ 400 |

### C · `Services/SpeiseplanService` (2.876 Z., 93 Methoden; 25 Tools, 4 Livewire, 6 Services, 36 Testdateien)

| neue Klasse (`Services/Speiseplan/…`) | Inhalt | ≈ Z. |
|---|---|---|
| `SpeiseplanErnaehrung` | Spec 31 Kennzeichnung/Kostformen + Stufe D DGE-Bilanz/Abwechslung | 730 |
| `SpeiseplanAusgabe` | Paket 6 Ausgabe-Formate (`dokumentDaten`, `buffetKarten`, `ausgabeFormat`) + Branding | 390 |
| `SpeiseplanAnsicht` | Wochen-Matrix/Kalender + Zelle „auf einen Blick“ + Zielband | 450 |
| `SpeiseplanVorlage` | Paket 7 Vorlage für Betriebe + Paket 8 Plan/Ist | 290 |
| **Kern `SpeiseplanService`** | Plan, Linien, Einträge, Umbauen, Mengen, Bedarf | ≈ 1.050 |

### D · `Services/FoodbookService` (2.354 Z., 86 Methoden; 27 Tools, 8 Livewire, 15 Services, 86 Testdateien)

Abstimmung mit C4 (Komposition) und Pauls 2.4e (Zahlen-Konsistenz, „Aggregat / Preis“): **Aggregat zuletzt**, nach 2.4e.

| neue Klasse (`Services/Foodbook/…`) | Inhalt | ≈ Z. |
|---|---|---|
| `FoodbookDokument` | versendbares Dokument (`dokumentDaten` 253 Z.) + Branding | 480 |
| `FoodbookKapitelBild` | Spec 43 Kapitel-Bild | 210 |
| `FoodbookVokabular` | Zielgruppen-Vokabular + Default-Dimensionen + CRM-Link | 320 |
| `FoodbookKundentext` | KI-Kundentext (L2) | 190 |
| `FoodbookAggregat` | Aggregat/Preis (M11 Cockpit) — nach 2.4e | 240 |
| **Kern `FoodbookService`** | Foodbook, Kapitel-Baum, Blöcke, Picker, Format buchen, Helfer | ≈ 900 |

### E · `Livewire/Planung/Index` (3.200 Z. + Blade 1.228)

Spec 85 §5 (C1) gilt, PR-1 (RezeptImport) ist erledigt. Stand heute: Gate 2 im Index 547 Z., Regler 537 + KI-Kopf 192 + Go 256.

| PR | Kind | ≈ Z. |
|---|---|---|
| E1 (= C1 PR-2) | `Planung\Trendradar` | 120 |
| E2 (= C1 PR-3) | `Planung\KaskadenLauf` (Gate 2, Lauf-Steuerung, Poll `pruefeLauf`, Foto-Picker) | 850 |
| E3 (= C1 PR-4) | `Planung\Brief` (Leitplanken, Regler, KI-Kopf, Go, Voice-Brücke `#[On('voice.…')]`) | 1.050 → 850 mit `goKaskade`/`leitplankenAusBriefing` als Support-Klassen |
| E4 (= C1 PR-5) | 5 × `*AusBrief` + Owner-Session (seit 1.3d ein Use-Case `PlanungsEinstiegService`, Kind wird kleiner) | 250 |
| Parent danach | Session-Lifecycle, Tabs, Datenbeschaffung, Konstanten | ≈ 900 |

Events wie Spec 85 §5 (`planung.lauf-status`, `planung.lauf-gestartet`, `planung.meldung`, `planung.brief-uebernehmen`); Konstanten bleiben in `Index`.

### F · `Livewire/Produktion/Tagesplan` (1.529 Z. + Blade 1.627) — „nach Modus“

| Kind / Klasse | Inhalt | ≈ Z. |
|---|---|---|
| `Support/Produktion/TagesplanAbfrage` (Query-Service) | Aufbereitung `dashboard`, `wall*`-Bausteine (Arbeitszeilen, Behälter, Posten, Pass) | 600 |
| `Produktion\TagesplanWall` | Wall-Modus (Ansicht, Gruppenfilter, Abhaken) + Blade-Teil | 300 + Blade ≈ 600 |
| `Produktion\TagesplanAnleitung` | Anleitung (`anleitung*`, `oeffneAnleitung`) + Blade-Teil | 200 + Blade ≈ 300 |
| `Produktion\TagesplanFeedback` | Feedback inkl. Audio (`feedback*`, `updatedFeedbackAudio`) | 150 |
| Parent | Tag/Zeitraum, Dashboard, Editor, Vorschläge | ≈ 400 + Blade ≈ 700 |

### G · Restliche Dateien über 1.500 (Bestand Größen-Wächter), Vorschlag Spec 85 §9

`PairingService` 2.591 (Lesen/Bewerten/Pflege), `Ai/KnowledgeContextService` 2.171 (Routing/Budget/Prompt-Bau), `DataQualityService` 2.040
(Metriken je Bereich hinter Registry), `Angebote/Editor` 1.754 und `Foodbooks/Index` 1.625 (C4 erst Inventur), `Concepter/Editor` 1.602
(+ Blade 1.588), `RecipeGeneratorService` 1.528. Nicht im 3.3-Auftrag benannt — bitte entscheiden, ob 3.3 sie einschließt (dann +5–7 PRs).

## 2 · Reihenfolge, Risiko, Parallelität

Ein PR je Schnitt, Reihenfolge nach Nutzen ÷ Risiko. Jede Spur fasst nur ihre Dateien an; Konfliktdateien für alle:
`DateigroessenBasis.php`, `FoodAlchemistServiceProvider.php` (nur falls Bindungen nötig, möglichst keine), `tests/Fixtures/abhaengigkeit_richtung_baseline.json`.
Diese drei Listen je PR nur um die eigenen Zeilen ändern; wer später mergt, rebast.

| Spur | Pakete (Reihenfolge) | Risiko | parallel zu |
|---|---|---|---|
| S1 Kaskade | A: `KaskadeKontext` → `KaskadeAbfragen` → `KaskadeErfinden` → `KaskadeAusgabeVoll` → `KaskadeSpeiseplanVoll` → `KaskadeFreigabe` | mittel (Jobs/Tools über Fassade) | S3, S4, S5 |
| S2 Planung-UI | E1 → E2 → E3 → E4 | **hoch** (Livewire-State, 31 Testdateien, ≈ 255 `Livewire::test`) | S3, S4, S5; **nicht** gleichzeitig mit S1-`KaskadeFreigabe` (beide Gate 2) |
| S3 Speiseplan | C: Ausgabe → Vorlage → Ansicht → Ernährung | niedrig | alle |
| S4 Foodbook | D: Kapitel-Bild → Kundentext → Vokabular → Dokument → (nach 2.4e) Aggregat | niedrig–mittel (86 Testdateien) | alle; Aggregat erst nach 2.4e |
| S5 Bestellung | B: Charakterisierungstests → Zyklen A–D (Spec 85 §6) → Lesen → Dokument → Pflege → Bedarf ×2 | **hoch** (dünne Tests) | alle |
| S6 Produktion | F: `TagesplanAbfrage` → Anleitung → Feedback → Wall | mittel (Blade-Pfade, Kiosk) | alle |
| S7 Kreis | Abschnitt 3 | mittel | nach S1–S4 je Klasse, Events in eigenen PRs |

Vorschlag Verteilung auf vier: **S1+S7** (eine Person, Kaskade und Kreis hängen zusammen) · **S2** (Lisa, kennt Planung/Index) ·
**S3+S4** (Paul nach 2.4e, oder geteilt) · **S5+S6**. Erster Schritt jeder Spur ist der risikoärmste Schnitt (Abfragen, Ausgabe, Kapitel-Bild, Abfrage-Service).
Geschätzt 25–28 PRs für A–F, M–L je Spur.

## 3 · Kreis-Komponente (113) und Rückkanten → 0, mit der Klinke aus 3.4

Befund auf dem 3.4-Stand: Die Komponente hält vor allem über **„Preis/Bestand ändert sich → Rezept neu rechnen“** zusammen.
`RecipeRecomputeService` wird in der Komponente von 24 Diensten aufgerufen, darunter von Basis-artigen: `GpService`, `KostenCacheService`,
`LeadLaService`, `InventurService`, `BulkEnrichService`, `FassungService`, `HardstopResolveService`, `SignalDetektorService` (8 Kanten).
Dazu `TeamVkService → DarreichungService`, `WordingResolver → ConceptService`, `CoverageService → Concept/Foodbook`,
`InhaltsFreigabeService → Recipe/Concept/PaketService`, `Pairing/Kombinationslogik → RezeptProfil`, `LieferantenRechnungService → OrderService`.

Schritte (je eigener PR, Muster aus 3.4: synchrone Events, try/catch bleibt an der Aufrufstelle, `Event::hasListeners`-Test):
1. **K1 · Preis-/Bestandsänderung als Event:** `RezepteNeuRechnen(recipeIds, grund)` aus den 8 Basis-Diensten, Listener in der Rezept-Schicht ruft
   `RecipeRecomputeService` wie bisher. Erwartung: die meisten Basis-Dienste fallen aus der Komponente.
2. **K2 · Schichtzuordnung erweitern** (3.3/3.8): GP-Stamm, Kosten, Lieferant, Lager/Inventur, Einkauf (Order) und Wissen/KI eintragen.
   Dadurch werden heute neutrale Kanten zu gezählten Rückkanten; die Klinke bekommt sie einmal als Bestand (neue Regel, Ausgangszählung erlaubt).
3. **K3 · Lese-Rückwege über Schnittstellen:** `WordingResolver`, `CoverageService`, `InhaltsFreigabeService` (Verwendungs-/Freigabe-Abfragen nach oben)
   über ein Register: obere Schicht meldet einen Anbieter (`interface …Quelle`, getaggt im Container), untere Schicht fragt das Register.
4. **K4 · Rest je Fund**, z. B. `Pairing/Kombinationslogik → RezeptProfil` (Profil ist Rezept-Lesemodell → `RezeptProfil` nach Basis verschieben,
   wenn es nur Modelle liest), `GerichtHuelleService → PlanningSessionService`, `DarreichungsZeitService → PlanungsblattService` (die zwei 3.4-Bestände).
5. **K5 · Schärfen der Klinke:** Wenn Rückkanten 0, Test von „darf nur sinken“ auf „muss 0 sein“ umstellen und die Kreis-Klinke auf
   „keine Komponente mit Klassen aus zwei Schichten“.

Zielmessung je PR in der PR-Beschreibung: Rückkanten alt → neu, größte Komponente alt → neu (Skript aus 3.4, `AbhaengigkeitsGraph::zaehlen`).
Die Zerlegungen aus Abschnitt 1 ändern die Kanten kaum (neue Klassen erben die Kanten ihres Teils); sie machen K3/K4 aber kleiner,
weil die Rückwege dann in kleinen Klassen sitzen (z. B. `FoodbookDokument` statt ganz `FoodbookService`).

## 4 · Risiken und Gegenmittel

**Livewire zerlegen (S2, S6):**
- State: Eigenschaften ziehen mit ins Kind; der Parent behält höchstens schreibgeschützte Kopien (Spec 85 §5). `wire:model`-Pfade im Blade
  ändern sich von `eingabe.x` im Parent zu `eingabe.x` im Kind: Blade-Teil zieht mit, Tests `set('eingabe…')` ziehen mit (Liste alt → neu je PR).
- Events: `#[On]` nicht in Traits; Voice-Brücke (`voice.diktat-transkribiert`, `voice.formularstand-aktualisiert`) dispatcht nur noch bei
  Brief-Renders → VoiceInterfaceTest muss vorher grün sein und danach unverändert grün.
- Jedes Kind mit stabilem `key`; Kinder in versteckten Tabs `lazy`, Polls nur bei aktivem Lauf (Last).
- Uploads: `WithFileUploads` im Kind, nicht im Parent.
- Bearbeitungssperre (Spec 65) und `MitBearbeitungssperre`: Sperr-Haken gelten je Komponente → Kinder, die schreiben, brauchen den Trait
  oder delegieren an den Parent; Spec84SperreModalTest-Muster nutzen.
- Kiosk/Wall (Tagesplan): Wall-Modus läuft auf Geräten ohne Login-Wechsel; vor dem Schnitt Screenshot-Test bzw. Render-Test je Modus.

**MCP-Tools (alle Spuren):** 13–27 Tools je Service rufen Methoden direkt. Deshalb Fassaden; Tools ziehen erst in eigenem PR um.
Absicherung: vor dem ersten Schnitt je Service ein **Tool-Rauchtest** (jedes betroffene Tool einmal mit gültigen Argumenten, `success` true),
damit ein verlorener Delegator auffällt. Spec90KiPflichtliste keyt `propose(` nach Datei::Methode → beim Verschieben die Liste mitziehen.

**Sonstige:**
- Wächter mit Dateilisten (EnumStatus, Spec84Sicht-Ausnahmen, Spec90-Pflichtliste, ArchitekturRegeln-Baseline) folgen verschobenem Code
  (gesehen in 3.4: EnumStatus). Jeder PR prüft die Wächter.
- Spec84SichtWaechter zählt je Datei: verschobene Abfragen behalten ihr Sicht-Token in denselben 8 Zeilen.
- Größen-Basis je PR senken (Sperrklinke), sonst rot.
- Fassaden-Methoden, die nur delegieren, sind Kanten im Graphen: Fassade → Teil ist erlaubt (gleiche Schicht).

## 5 · Offene Fragen an den Kurator

1. Gehören die Dateien aus 1G (Pairing, KnowledgeContext, DataQuality, Concepter/Editor, Angebote/Editor, Foodbooks/Index, RecipeGenerator) zu 3.3?
2. S5 Bestellung: Spec 85 nennt „test“ als zuständig, nach Spec 84. Bleibt das, oder gehört es in die Verteilung?
3. Fassaden-Abbau (Aufrufer umziehen): in 3.3 oder später (eigene Welle)? Vorschlag: später, 3.3 endet mit Fassaden.
4. K2 macht heute neutrale Kanten sichtbar (die Rückkanten-Zahl steigt einmalig). Einverstanden, das als neue Regel mit Ausgangszählung einzuführen?
