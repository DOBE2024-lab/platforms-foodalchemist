# 2.6b Runbook: Alt-Pakete → Concept auf io

Stand 22.11.2026 · Entwurf Hans · Grundlage: `foodalchemist:pakete-to-concepts` aus 2.6a (cf9b3528, 0c471d8d, 827ac3e4)

**Wer macht was:** Kurator kündigt an, Dominique gibt das Go je Schritt, der Master führt aus. Kein Schritt auf io ohne Go.
**Wann:** an einem ruhigen Werktag, nicht in der Woche vor Weihnachten (Plan 98, Zeitplan). Dauer bei heutigem Bestand: unter 30 Minuten.

## Bestand (19.11., nur lesend gezählt, vor dem Lauf neu zählen → Schritt 0)

3 Alt-Pakete (Team 3, „Green & Tasty“ ×3), 9 Positionen, 0 Fassungen, 0 angebotslokal (`offer_id`), 0 Verweise aus
Concept-Slots, Speiseplan, Bestellvorlagen, Abo-, Sammlungs- und Freigabe-Objekten. Concept-Kopien 11–13 (uuid-Reuse)
sind seit 13.11. soft-deleted. Entscheid Dominique 19.11.: „Green & Tasty“ wird gebraucht.

## 0 · Vorab-Zählung (lesend, SQL)

```sql
SELECT COUNT(*) FROM foodalchemist_packages WHERE deleted_at IS NULL AND ist_fassung = 0;          -- erwartet 3
SELECT COUNT(*) FROM foodalchemist_packages WHERE deleted_at IS NULL AND ist_fassung = 1;          -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_packages WHERE deleted_at IS NULL AND offer_id IS NOT NULL;     -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_packages WHERE deleted_at IS NULL AND kopie_von_id IS NOT NULL; -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_package_dishes WHERE deleted_at IS NULL;                        -- erwartet 9
SELECT COUNT(*) FROM foodalchemist_concept_slots WHERE package_id IS NOT NULL AND deleted_at IS NULL; -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_menu_plan_entries WHERE package_id IS NOT NULL;                 -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_order_template_lines WHERE paket_id IS NOT NULL;                -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_abo_objekte WHERE typ = 'paket';                                -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_sammlung_objekte WHERE typ = 'paket';                           -- erwartet 0
SELECT COUNT(*) FROM foodalchemist_paket_kopien WHERE tabelle = 'foodalchemist_packages';          -- erwartet 0
SELECT id, uuid, kind, ist_fassung, deleted_at FROM foodalchemist_concepts
 WHERE uuid IN (SELECT uuid FROM foodalchemist_packages WHERE deleted_at IS NULL);                 -- erwartet 11–13, kind=paket, gelöscht
```

**Abbruchregel:** Weicht eine Zahl ab, stoppen und an den Kurator. Das Kommando blockiert Fassungen, Abo-/Sammlungs-Objekte,
Kündigungskopien, Speiseplan- und Bestellvorlagen-Verweise ohnehin (dann wird nichts geschrieben), aber eine neue Fassung oder
ein neues Abo seit dem 19.11. ist eine Fachfrage, keine Technikfrage.

## 1 · Ankündigung

- Wer: Kurator an Dominique, Dominique an Team 3 („Green & Tasty“).
- Text (Vorschlag): „Am <Datum> zwischen <Uhrzeit> und <Uhrzeit> ziehen die drei Pakete ‚Green & Tasty‘ ins neue Paketmodell
  um. Bitte in dieser Zeit keine Pakete bearbeiten. Danach findet ihr sie unter Concepts (Art Paket); Preise und Inhalte bleiben gleich.“
- Bearbeitungsstopp: Ab Ankündigung keine Änderungen an `/pakete` in Team 3.

## 2 · Backup

Nur die betroffenen Tabellen, als eigener Dump vor dem Lauf (zusätzlich zur regulären Sicherung):

```bash
mysqldump --single-transaction --no-tablespaces <db> \
  foodalchemist_packages foodalchemist_package_dishes \
  foodalchemist_concepts foodalchemist_concept_slots foodalchemist_concept_slot_staffel \
  foodalchemist_abos foodalchemist_abo_objekte foodalchemist_sammlungen foodalchemist_sammlung_objekte \
  foodalchemist_paket_kopien foodalchemist_menu_plan_entries foodalchemist_order_template_lines \
  foodalchemist_offers foodalchemist_offer_concept foodalchemist_offer_blocks \
  > paket-umzug-backup-$(date +%Y%m%d-%H%M).sql
```

Prüfen: Datei nicht leer, `grep -c "INSERT INTO \`foodalchemist_packages\`"` ≥ 1. Ablage außerhalb des Web-Roots, Pfad im Protokoll notieren.
(Angebote haben keine `package_id`-Spalte; sie erreichen Alt-Pakete nur über Concept-Slots bzw. `packages.offer_id`. Die Angebots-Tabellen sind trotzdem im Dump, damit „Angebote alt = neu“ nachprüfbar bleibt.)

## 3 · Trockenlauf (schreibt nichts)

```bash
php artisan foodalchemist:pakete-to-concepts --json=storage/app/foodalchemist/paket-umzug/trockenlauf-$(date +%Y%m%d-%H%M).json
```

Erwartung je Paket: Aktion `WIEDERHERSTELLEN Concept #11/#12/#13`, keine BLOCKER-Zeile, Exit-Code 0.
Zu lesen:
- **„Kopie veraltet“**: Wurde ein Alt-Paket nach dem Stand der Kopie (24.08.) geändert, steht der Grund dort. Kein Abbruch: `--apply`
  schreibt Kopf und Positionen aus dem Alt-Paket neu, die Kopie wird also aktualisiert, nicht nur wiederhergestellt.
- **Parität** im Trockenlauf vergleicht Alt-Paket mit der *heutigen* (gelöschten, evtl. veralteten) Kopie. DIFF-Zeilen sind hier erwartbar und kein Abbruchgrund.
- JSON-Datei an den Kurator.

## 4 · Apply

```bash
php artisan foodalchemist:pakete-to-concepts --apply --id=<id1> --id=<id2> --id=<id3> \
  --json=storage/app/foodalchemist/paket-umzug/apply-$(date +%Y%m%d-%H%M).json
```

- `--id` explizit setzen (die drei Ids aus Schritt 0), damit ein seit der Zählung neu angelegtes Paket nicht mitläuft.
- Läuft in einer Transaktion. Bei Blocker: nichts geschrieben, Exit-Code 1.
- Ausgabe nennt den Journal-Pfad (`storage/app/foodalchemist/paket-umzug/journal-<zeit>.json`). Journal sichern (Kopie neben den Dump).
- Danach Caches: Preis-Cockpit rechnet beim Öffnen nach; kein Extra-Schritt nötig.
- Danach Schreibschutz einschalten (Abschnitt „package_id nur lesend“).

## 5 · Verify

1. **Paritäts-Report** steht schon in der Apply-Ausgabe und im JSON. Erwartung: `0 mit Paritätsabweichung`, jede Zeile `ok`:
   `preis_gespeichert`, `preis_gerechnet`, `allergene_diaet`, `naehrwerte`, `ek_arbeitszeit`, `mengen_einheiten`,
   `darreichungen`, `rechte`, `fassungsherkunft`, `historische_referenzen`.
   Erneut prüfbar jederzeit mit dem Trockenlauf aus Schritt 3 (Aktion dann `AKTUALISIEREN`, Parität muss `ok` sein).
2. **Stichprobe UI** (Dominique, lesend): Concepts → Art Paket → die drei Pakete öffnen; Positionen, Mengen, Darreichungen, Preis pro Person mit `/pakete` vergleichen.
3. **SQL-Gegenprobe:**
   ```sql
   SELECT id, kind, status, deleted_at, price_per_person_manual FROM foodalchemist_concepts WHERE id IN (11,12,13); -- deleted_at NULL
   SELECT concept_id, COUNT(*) FROM foodalchemist_concept_slots WHERE concept_id IN (11,12,13) AND deleted_at IS NULL GROUP BY concept_id; -- Summe 9
   ```
4. **Alt-Tabellen unverändert:** Zählungen aus Schritt 0 erneut, alle gleich.

Abbruchregel: Eine DIFF-Zeile im Apply-Report → Rückweg (Schritt 6), dann Analyse.

## 6 · Rückweg

Stufe 1, Journal (bevorzugt, chirurgisch):
```bash
php artisan foodalchemist:pakete-to-concepts --rueckweg=storage/app/foodalchemist/paket-umzug/journal-<zeit>.json
```
Stellt Concepts 11–13 (inkl. Soft-Delete-Stand), ihre Slots und etwaige Einbettungen exakt auf den Stand vor dem Apply zurück.
Die Alt-Tabellen hat der Umzug nicht angefasst; `/pakete` funktioniert ohne Rückweg weiter. Schreibschutz wieder aus.

Stufe 2, Dump (nur wenn Stufe 1 scheitert): die Tabellen aus Schritt 2 aus dem Dump einspielen. Achtung: überschreibt auch
Änderungen an Concepts/Slots, die seit dem Dump gemacht wurden; deshalb nur im Bearbeitungsfenster aus Schritt 1.

## `package_id` ab dann nur noch lesend

Schreibwege heute (Stand main e23067a5):

| Weg | Ort | nach 2.6b |
|---|---|---|
| Alt-Paket anlegen/ändern/löschen, Positionen | `PaketService`, `/pakete`, Paket-Tools auf der Alt-Tabelle | gesperrt |
| Alt-Paket-Fassung veröffentlichen | `PaketFassungService::kopiere` (:237) | gesperrt |
| Concept-Slot mit Alt-Paket befüllen | `ConceptService::fillSlot` (`package_id`-Zweig, :478), `Concepts/Index:321` | abgelehnt, Hinweis auf `embedded_concept_id` |
| Speiseplan-Eintrag mit `package_id` | `SpeiseplanService` (Prüfung :720), `speiseplan.eintraege.POST/PUT` | abgelehnt |
| Kündigungskopie eines Alt-Pakets / Slot-Umhängen | `AboKuendigungService::kopiereInhaltPaket` (:765), `:789` | nur noch Bestand (0 auf io) |
| Concept-Fassung übernimmt Slot-`package_id` | `ConceptFassungService:261` | kopiert vorhandene Werte (lesend) |

Vorschlag (Entscheid nötig, siehe Bericht): ein Schalter `foodalchemist.paket_alt_nur_lesen` (Standard aus, auf io nach Apply an).
Er sperrt zentral: Model-Hooks `saving`/`deleting` auf `FoodAlchemistPaket` und `FoodAlchemistPaketGericht` (fängt UI, Services,
Tools, Fassungen und Kündigungskopien), dazu eine fachliche Ablehnung mit klarer Meldung in `fillSlot` und Speiseplan, bevor
die Hooks greifen. Rückweg = Schalter aus. Ein Architektur-Test verhindert neue Schreibwege auf `package_id`. Entfernt wird
der alte Code erst in 2.6c, die Spalten in 3.1.
