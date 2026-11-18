# Spec 43 — Präsentation als digitales, konfigurierbares Kundenbuch

**Status:** ✅ Phase 1 (Foodbook) · Phase 2 (Speisekarte) · Phase 3 (Speiseplan) — alle gebaut + getestet (36 Spec-Tests grün, 0 Regressionen).

## Ziel

Die bisher unbrauchbare **Präsentation**-Ansicht wird die digitale Kundenausgabe aller drei
Ausgabeformen (Foodbook, Speiseplan, Speisekarte):

- **teilbarer Public-Link ohne Login** (`/p/{typ}/{token}`), mit **Freigabe-Datum + Gültigkeit**
  (Pflicht-Datum); abgelaufen/zurückgezogen → 404;
- **absoluter Snapshot** per „Veröffentlichen"-Knopf — danach kann weitergearbeitet werden,
  ohne den Link zu ändern; erneutes Veröffentlichen aktualisiert den Snapshot;
- **visueller Struktur-Builder** (Einstellungen → Präsentations-Designs): Block-Palette ·
  Live-Vorschau · Style-Panel · Drag-Reorder → wiederverwendbare Designs;
- Gestaltung über den erweiterten Tab **„Branding & Präsentation"**.

Dokument (PDF) und Report bleiben unverändert.

## Architektur

- **Datenmodell:** additive `presentation_*`-Spalten je Head-Tabelle (`enabled`, `token`,
  `design`, `published_at`, `published_by`, `expires_at`, `snapshot_json`, `settings_json`) +
  `foodalchemist_presentation_designs` (form-agnostisch, team-gescopt, `layout_json`/`tokens_json`).
  Trait `HasPresentation` (Casts + `isPresentationLive` + `scopeByPresentationToken`).
- **`PresentationService`:** `publish`/`withdraw`/`resolveByToken` (kein Team-Scope, nur aus dem
  Snapshot) / `previewData` (live, team-gescopt) / `buildSnapshot` / `hydrateImages` / `designPreview`.
- **Interna-Freiheit = Allowlist-Neubau** (nicht der `intern=false`-Flag, der code-verifiziert leck
  ist: einzel-`ek`, ungegatetes `title_intern`, `gesamt`-EK-Triple). `normalizeFoodbook` baut die
  Kundensicht aus bekannten, sicheren Keys neu.
- **Bilder:** im Snapshot nur Identifier (`context_file_id`/`path`); die signierte URL wird zur
  Render-Zeit frisch erzeugt (`FoodAlchemistMediaService::url`) — nie eine kurzlebige URL einfrieren.
- **Design-System:** `PresentationDesignService` (3 Built-ins editorial/menu/kiosk + CRUD +
  `resolveLayout`/`resolveTokens`). **Das Design besitzt die Palette** (Branding liefert Logo/Cover/
  Footer, überschreibt die Design-Farben nicht — sonst maskiert der `brand_color`-DB-Default jede
  Design-Farbe). Der Snapshot friert das **aufgelöste** Design ein (Layout+Tokens) → stabil trotz
  späterem Design-Weiterbau; die Live-Vorschau zeigt den neuen Stand.
- **Rendering:** chrome-freies, self-contained Layout (`layouts/presentation`, Tokens→CSS-Vars) +
  Block-Renderer (`presentation/show` + `presentation/blocks/*`, Typ→Partial per Whitelist, kein LFI).
- **Public-Route:** `routes/public.php` `/p/foodbook/{token}` (`NoCacheHeaders`, kein Auth).
- **Interne Vorschau:** `/foodbooks/{id}/praesentation` → `PresentationController::preview` (auth,
  team-gescopt, live, `?design`-Override).
- **MCP:** `foodalchemist.foodbook_presentation.{PUBLISH,WITHDRAW,GET}` +
  `foodalchemist.presentation_designs.{POST,PUT,GET,SEARCH,DELETE}`.

## Tests (28, alle grün)

`PresentationServiceTest` (Sanitizer/Interna-Freiheit, Pflicht-Datum, 404-Matrix, Snapshot-/Token-
Stabilität, isOwnedBy) · `PresentationPublicTest` (ohne Login, 404-Matrix, Snapshot-Stabilität) ·
`PresentationInternalPreviewTest` (auth/team-scope, live, `?design`) · `PresentationDesignTest`
(Builder-CRUD, Reorder, Token-Präzedenz, eingefrorenes Design) · `PresentationEditorTabTest`
(Publish/Withdraw-Wiring) · `PresentationMcpTest` (Round-Trips, Tenancy, Registry-Smoke).

## Umgesetzt je Phase

- **Phase 2 (Speisekarte):** `presentation_*` auf `foodalchemist_menu_cards`, `normalizeSpeisekarte`
  (à la carte, Menü-Gänge eingerückt), Route `/p/speisekarte/{token}`, Editor-Tab, 3 MCP-Tools, interne Vorschau.
- **Phase 3 (Speiseplan):** Branding-Neubau (Spalten + `SpeiseplanService`-Methoden + Editor-Tab) +
  `presentation_*` auf `foodalchemist_menu_plans` + `normalizeSpeiseplan` (Wochen-Grid) +
  `adaptLayoutForType` (Grid in die 3 Vorlagen) + `grid`-Block (Raster + Kostformen + DGE-Ø) + Route +
  3 MCP-Tools. LMIV-Kennzeichnung customer-pflichtig, GV-Aushang preislos.

## Gestaltungsprüfung Dominique, 18.11.2026 (Vorschlag, nicht umgesetzt)

- Bestehender Aufbau: Designs und Blockpalette links, Vorschau in der Mitte,
  Struktur, ausgewählter Block, Farben/Schriften und CSS rechts. Vorgeschlagen:
  links die Dokumentstruktur, eine größere zentrale Vorschau mit Handy-/Desktop-
  Umschaltung, rechts ausschließlich die Optionen des ausgewählten Bausteins;
  Markenfarben/Schriften in einen eigenen Bereich. Bestehende Branding-Werte und
  Veröffentlichungsregeln erhalten.
- Preise je Konzept sind bereits in den Präsentationsblöcken vorhanden.
  `preis_aufschluesselung` enthält bei Angeboten bereits Kapitel, Gäste,
  €/Person, Zwischensummen und Gesamtpreis. Es fehlt vor allem eine verständliche
  Auswahl dieser Darstellung und eine feinere Konzept-Aufschlüsselung innerhalb
  der Kapitel. Keine zweite Preisberechnung in der View ergänzen.
- Vorgeschlagene Darstellung: Konzeptpreis am Konzept, Kapitelzwischensumme im
  Kapitel und Gesamtübersicht am Ende. Alternative Konzepte eines Foodbooks als
  Einzelpreise/Preisspanne zeigen; nur ausgewählte additive Leistungen summieren.
  Paket-Festpreise dürfen nicht zusätzlich mit ihren enthaltenen Einzelpreisen
  addiert werden. Netto-/Brutto-Basis und Bezugsmenge ausdrücklich anzeigen.
- Für die mobile Kundenausgabe Preiszeilen untereinander statt vier starrer
  Spalten; aktuelle `preis_aufschluesselung` reserviert bereits 18,5 rem für drei
  Zahlenspalten plus Abstände. Sichtbare Quelle/Leerzustand in der Designvorschau,
  wenn ein Block für die gewählte Ausgabe keine Daten hat.
- Der Design-Baukasten steuert Kundenpräsentationen, nicht die eigene
  Küchenhandbuch-View. Gemeinsame Markenfarben sind möglich; das Handbuch behält
  seine auf Nachkochen, Bedarf und Navigation ausgerichtete Darstellung.

Die Beispiele wurden als separater anklickbarer Gestaltungsvorschlag mit
synthetischen Daten bei 375 und 1024 px geprüft (Baum, Mengen, Preis-Kontext,
Vorschau-Umschaltung, kein horizontaler Seitenüberlauf). Kein Nachweis einer
bereits ausgelieferten Produktfunktion.

## Offen (Nachzug)

- Cleanup: alte Livewire-Komponenten `Foodbooks/Praesentation` + `Speisekarte/Praesentation`
  (Foodbooks noch von `FoodbookServiceTest` genutzt) entfernen + Test migrieren.
- Speiseplan-Aushang: Mahlzeit/Woche-Auswahl beim Veröffentlichen (aktuell Default mittag / Plan-Start).
- Bewusste Grenze: freie Canva-Leinwand bleibt späterer, additiver Aufsatz.
- Deploy: FA-scoped migrate (4 neue Migrationen: foodbooks/menu_cards/designs/menu_plans).
