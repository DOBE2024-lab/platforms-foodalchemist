# Plan 98 · 3.5 „Datenmodell bereinigen“ — Bestand, Reihenfolge, Risiken

Stand 27.11.2026 (Lisa). Bestand gemessen auf main 30b695fdf (23.11.), Entscheidungen des Kurators eingearbeitet.
Schema-Angaben aus dem migrierten Test-Schema (SQLite); io wurde per Waisen-Zählung gegengeprüft (siehe 2).

## Stand je Schritt

| # | Schritt | Stand |
|---|---|---|
| 1a | Aliase melden sich zur Laufzeit (`Support\VeralteterAlias`) | fertig, Branch `feat/cq-3-5-schritt-1-2`, noch nicht auf main |
| 1b | Aufrufer auf englische Namen | fertig, gleicher Branch |
| 1c | Aliase + Helfer löschen | wartet: eine Woche `[fa-alias]` im io-Log beobachten, Start erst mit dem Deploy von 1a |
| 2 | Waisen-Report `foodalchemist:diagnose:waisen` + Wächter | fertig, gleicher Branch |
| 3 | Waisen bereinigen | **entfällt**: io hat 0 Verweise ins Leere |
| 4 | `sales_recipe_id` → `recipe_id` | offen |
| 5 | Fremdschlüssel ergänzen | offen; wartet auf die io-Liste der Spalten mit fk_sqlite=nein |
| 6 | Spiegelfelder am Rezept entfernen | offen; 6c braucht Dominiques Ja |
| 7 | Alt-Pakete abbauen | Dominique entscheidet (io: 3 packages, 9 package_dishes, 3 concept_slots.package_id) |

## 1 Bestand

### A · Gespiegelte Darreichungsfelder am Rezept (B11/U6) — Schritt 6

| Spalte am Rezept | Gegenstück an der Darreichung | Schreiber des Spiegels | Fundstellen (src + views, 23.11.) |
|---|---|---|---|
| `sales_quantity_per_unit_g` | `quantity_per_unit_g` | `DarreichungService::kopfSpiegeln` | 57 Stellen / 23 Dateien; MCP `VerkaufsrezeptePutTool` (nur durchreichen, Änderung = Fehler) |
| `sales_unit_count` | keins — am Gericht fest 1 (Modell 23.11.) | Editor, Generator | 110 Stellen / ~40 Dateien inkl. 2 Blades, meist als Teiler |
| `sales_unit_vocab_id` | `unit_vocab_id` | `SalesRecipeService::syncStandardDarreichung` | 13 Stellen / 6 Dateien |
| `container_warm_vocab_id`, `container_cold_vocab_id` | gleiche Namen | `syncStandardDarreichung` | Rezept-Leser: `VkModal` (Formular), `DarreichungService` (Fallback bei Anlage) |
| `serving_vehicle_vocab_id` | gleicher Name | `syncStandardDarreichung`, `spiegeleStandardDarreichung` (KI-Anreicherung) | `SalesRecipeService`, `DarreichungService`, `BulkEnrichService` |
| Altspalten `container_warm_legacy_id`, `container_cold_legacy_id`, `serving_vehicle_legacy_id` | — | — | nur `Einstellungen/BehaelterService` liest |

**Bleiben am Rezept (Kurator, nicht in 3.5):** `container_warm_count`, `container_cold_count`, `serving_vehicle_source`, `serving_vehicle_ai_confidence`.
Schon weg: `markup_class_id`, `sales_net` am Rezept (Spec 85 D-1).

### B · `sales_recipe_id` → `recipe_id` — Schritt 4

- Tabellen (7): `foodbook_blocks`, `concept_slots`, `offer_blocks`, `menu_card_items`, `menu_plan_entries` (mit FK), `dish_ideas` (**ohne FK**), `package_dishes` (Alt-Paket, Schritt 7). Keine hat schon `recipe_id`.
- Code (23.11.): 84 src-Dateien, 3 Blades (Concepter-Editor, Speiseplan-Editor, Speisekarte-Rubrik), 188 Testdateien.
- MCP: 18 Tools, 8 mit dem Feld im Eingabe-Schema (ConceptSlotsPost, FoodbookBlocksPost, OfferBlockPost, SpeisekartePositionenPost, SpeiseplanEintraegePost/Put, KapitelIdeenPost, PaketGerichteSet).
- Feldlisten Kopie/Einfrieren: `OfferCompositionService`, `FoodbookService`, `AngebotEinfrierService`. Fassung: `ConceptFassungService::SLOT_NIE` und `kopiere()`, `FassungsVergleich`, `AboKuendigungService`.

### C · Fremdschlüssel — Schritt 5

- Kernkette: 117 Verweis-Spalten (genau die des Waisen-Reports). 49 ohne FK im Test-Schema.
- io (Master-Zählung): 0 Verweise ins Leere; zu keiner Spalte, die im Test-Schema einen FK hat, fehlt er auf io; io hat zusätzlich `recipe_presentations.vessel_gp_id`.
- Verweise auf soft-gelöschte Eltern (20 Spalten, z. B. `gp_textures.gp_id` 131, `recipe_steps` 73, `recipe_ingredients` 14) bleiben bewusst — Soft-Delete soll wiederherstellbar sein.
- Ausgeklammert: polymorphe Verweise (`ref_id`, `objekt_id`, `target_id`, `source_id`), modulfremde (`crm_*`, `*context_file_id`), Log-Tabellen, Alt-Pakete.

### D · Veraltete Aliase — Schritt 1

39 `@deprecated #486` in 17 Models (Audit nannte 47). Reflektierende Tests (`ModelRelationsResolveTest`) überspringen sie über den Docblock.

## 2 Waisen-Report

`foodalchemist:diagnose:waisen [--alle] [--json]`, nur lesend, Spalten zur Laufzeit aus dem Schema (gleich auf MySQL und SQLite). Exit 1 nur bei Verweisen ins Leere. Bleibt als Wächter (Test `WaisenReportTest`) und als Vorbedingung von Schritt 5.

## 3 Reihenfolge der offenen Schritte (je Migration Trockenlauf + `down()`)

| # | Schritt | Art | Daten weg? |
|---|---|---|---|
| 4 | `RENAME COLUMN` in 6 Tabellen (MySQL 8 nimmt FK + Index mit; `down()` zurück). Code mechanisch per Skript. MCP nimmt **beide** Namen an und gibt beide aus bis 4 Wochen nach dem Rename-Deploy, dann mit Ankündigung entfernen. `SLOT_NIE` auf `recipe_id` (R1). Code + Migration in einem Release, Worker sofort neu starten. Notion-Skills/Office prüft der Kurator vorher. | Migration + Code | nein |
| 5 | FKs für alle Spalten mit fk_sqlite=nein, gruppiert (Rezept-Kette, Concept/Format, Ausgabe-Blöcke, Lager, GP-Nebentabellen). `restrict`, Selbstbezug Fassung/Kopie `set null`, kein cascade. Jede Migration prüft „ins_leere = 0“ über `WaisenReportService` und bricht sonst ab. Dazu `vessel_gp_id`-FK ins Test-Schema. ALTER-Dauer an einer io-Kopie messen. | Migration | nein |
| 6a | Leser auf die Darreichung (DarreichungResolver), Teiler `sales_unit_count` raus (fest 1). In Leser-Gruppen schneiden: Preis/Marge, Concepter, Ausgabe, Produktion — je mit Test. | Code | nein |
| 6b | Schreiber stoppen (`kopfSpiegeln`, `syncStandardDarreichung`). MCP nimmt die Felder weiter an (ignoriert, Hinweis), GET liest von der Darreichung. | Code | nein |
| 6c | Abweichungsreport Rezept ≠ Standard-Darreichung (Trockenlauf), Backup-Tabelle (id + Werte), `dropColumn` der Spiegelfelder + `*_legacy_id`, Rehash der Fassungen (R1). `down()` legt an und füllt aus dem Backup. | Migration | **ja → Dominiques Ja** |
| 1c | Aliase + `VeralteterAlias` löschen, wenn das io-Log eine Woche still bleibt. | Code | nein |

## 4 Risiken

- **R1 Fassungs-Hash.** Der Rezept-Hash nimmt alle Rezept-Spalten außer NIE_KOPIEREN: Droppen der Spiegelfelder (6c) ändert jeden Rezept-Hash → jede Fassung „weicht ab“. Gegenmittel in 6c: für Arbeitszeilen, deren alter Hash zur jüngsten Fassung passte, den neuen Hash dort nachtragen; Zwillingstest mit dokumentierter Änderung. Beim Rename (4) muss `ConceptFassungService::SLOT_NIE` `recipe_id` enthalten, sonst ändern sich alle Konzept-Hashes (Zwillingstest fängt das).
- **R2 MCP.** Siehe 4: beide Namen bis 4 Wochen nach Deploy.
- **R3 JSON-Kopien** (eingefrorene Angebote, KI-/Kaskaden-Parameter, `payload_json`): Leser akzeptieren beide Schlüssel, keine JSON-Migration.
- **R4 Deploy-Fenster** beim Rename: Worker sofort neu starten, Job-Payloads tolerant lesen.
- **R5 Soft-Delete:** kein cascade auf Fassungen, Abos, Angebote.
- **R6 ALTER-Dauer** auf großen Tabellen vorher an einer Kopie messen.
- **R7 Tests:** 188 Dateien mit `sales_recipe_id`; Fixtures mit Spiegelfeldern (`sales_quantity_per_unit_g` 35, `sales_unit_count` 69 Testdateien).
- **R8 Konflikte zwischen 4 und 6a:** beide fassen viele derselben Dateien an (Concepter, Ausgabe). Entweder nacheinander oder 6a nach 4 rebasen.
