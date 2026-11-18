# Spec 86 – Handbuch (Standards zum Nachschlagen)

Status: **Entwurf**, Dominique entscheidet. Stand 2026-10-23. Abgestimmt mit dem Kurator (Spec 82/84/85).

## Ziel
Zu jedem Foodbook, jeder Speisekarte, jedem Angebot und jedem Speiseplan gibt es ein **digitales Handbuch** für den Kunden und dessen Team (Franchise-Prinzip: Standards anklicken statt Zettel). Man sieht, **wie ein Gericht gemacht, angerichtet und regeneriert wird**. Tablet-first, Handy muss gehen. Nur lesen, kein Login, keine Preise, keine Interna. Die Druckversion bleibt unverändert bestehen.

## Festlegungen (Dominique)
- Je Quelldokument ein **eigener Handbuch-Link**, erzeugt dort, wo heute der Kundenlink (Präsentation) veröffentlicht wird – als zweiter Link daneben, mit eigenem Token, Ablauf und Widerruf.
- Zugriff und Mechanik wie die Veröffentlichung des Foodbooks (Spec 43).
- **Eingefroren** (Snapshot); Änderungen erst nach erneutem Veröffentlichen.
- Navigation folgt dem Aufbau des Quelldokuments (Sektionen, Kapitel, Wochentage); man klickt ins Gericht.
- Optik und Bedienung wie der Wandmonitor (große Flächen, schnell erfassbar). **Der Wandmonitor selbst bleibt unverändert.**
- Zunächst nur Deutsch.

## Inhalt je Gericht
Zubereitung (Schritte, Schrittfotos) · Anrichten (Text, Fotos) · Regeneration je Komponente (Gerät, Temperatur, Zeit, Kerntemperatur, Behälter) · Bestandteile (Garnitur markiert) · Portion, Vehikel/Geschirr · Allergene und Kennzeichnung. Dazu freie Kapitel (z. B. Hygiene, Ausgabe-Regeln).
Fehlt ein Abschnitt, wird er im Handbuch weggelassen; beim Veröffentlichen zeigt der Editor die Gerichte mit Lücken. KI-Entwürfe werden nicht eingeblendet.
**Nie im Snapshot:** EK, VK, Marge, Lieferant, interne Notizen.

## Technik (Entwurf)
- Neuer Typ `handbuch` im bestehenden `PresentationService` (kein zweiter Link-Mechanismus), Route `/p/{type}/{token}`, Tabelle `foodalchemist_presentations`.
- Neuer Snapshot-Builder neben `buildSnapshot`; Darreichungsform der Position (Speiseplan: `presentation_id`) bestimmt Anrichten und Regeneration.
- Daten: `recipe_steps`, `recipe_step_photos`, `recipes.plating_text`, `recipe_regenerations`, `recipe_containers`, `recipe_presentations`, Zutatenrolle/-funktion für Garnitur, Allergen-Spalten am Rezept.
- Eigene mobile/Tablet-View; Stilvorbild `foodalchemist::layouts.kiosk`.
- Veröffentlichen-Bereich in Foodbook, Speisekarte, Angebot, Speiseplan; MCP-Tools im Lockstep.
- Migrationen im Zeitstempelbereich `2026_11_14_*`, nur falls nötig.

## Nicht in dieser Spec
Mehrsprachigkeit, PIN-Schutz, Live-Variante, Umbau des Wandmonitors.

## Abnahme
Pest: Negativtest „keine Preisfelder im Snapshot", Token/Ablauf/Widerruf, Lücken-Report, je Quelltyp ein Test, PolicyTest, Dateigrößen-Wächter. Browser: Tablet quer und 375 px, per Link ohne Login durchklicken.

## Bau-Regeln (Kurator)
Eigener Branch/Worktree, Team-Sicht beachten (`TeamSicht`, `FremdSchreiben::erlaubt`), lokal committen, Branch+Commit+Tests an den Kurator, der pusht und deployt. Nur ein Testlauf gleichzeitig, Slot vom Kurator.

## Erweiterung vom 18.11.2026 (Dominique)

- Format → Konzept → Gericht wird als Baum vollständig zugeklappt geöffnet.
  Die Konzeptgruppen kommen aus dem bestehenden Ausgabedokument, ohne Preise.
- Portionen werden je geöffneter Speise unabhängig gehalten; +/− zählt jeweils
  eine Portion. Ganze Mengen behalten ihre Nullen (120 g bleiben 120 g).
- Basisrezept als Kurzvorschau über die volle Tabellenbreite und als eigene
  vollständige Rezeptansicht. Die Vorschau folgt dem Gericht: 10 × 80 g Soße =
  800 g Soße. Die Vollansicht übernimmt diesen Bedarf und erlaubt eine eigene
  weitere Hochrechnung. Der Rückweg erhält Gerichtsportionen und Scrollposition.
- Der Originalansatz bleibt ausdrücklich umschaltbar. Schritttexte werden nicht
  verändert; Zutatenmengen stammen aus der Bedarfsliste. Ohne verlässliche
  Ausbeute/Umrechnung wird der Originalansatz mit Hinweis statt eines geratenen
  Bedarfs angezeigt. Weitere Basisrezepte bleiben über den Referenzgraphen erreichbar;
  ein bereits aufgebauter Knoten verhindert endloses Aufbauen bei Zyklen.
- Zentraler Mengenweg: `DarreichungService::produktionsAnteil` für die wirksamen
  Komponenten des Standards, `Hochrechnung::faktor` für den Basisrezeptbedarf.
  Gepflegte Ausbeute vor errechneter, Stück-Ausbeute für stückbezogene Referenzen.
  Der Snapshot trägt den Faktor je Referenz; im Browser wird nur damit multipliziert.
- Allgemeine Rezept-/Ergebnisbilder als kleine anklickbare Vorschau bei der
  Überschrift, Schrittfotos bei den Schritten. Bilddialog ohne Verlust der Mengen.
- Enthaltene Allergene direkt sichtbar, Spuren separat aufklappbar. Keine
  doppelte Allergenlegende am Seitenende.
- Die vorhandene Snapshot- und Freigabelogik bleibt: neue Bilder, Bedarfsfaktoren
  und Konzeptgruppen erscheinen nach erneutem Veröffentlichen des Handbuchs.
  Alte Snapshots bleiben lesbar; ohne Bedarfsfaktor zeigen sie den Originalansatz.
  Bild-URLs werden weiterhin beim Lesen aus gespeicherten Identifiern erzeugt.

Nachweise im separaten Branch: 103 Handbuch-/Policy-/UI-Tests (407 Assertions),
anschließend 8 gezielte Bedarfs-/Darstellungstests (33 Assertions) grün. Browser:
375, 390, 768 und 1280 px; unabhängige Portionen, 800-g-Vorschau, 1,6-kg-Vollansicht,
Originalansatz-Umschalter, Rückweg, Bilderdialog und geschlossene Navigation geprüft.
`node --test tests/Unit/handbuch-format.test.cjs` sichert den ausgelieferten
Zahlenformatter ab. MySQL-8.4.2-Migrations-Smoke mit 12 Checks grün (siehe Darreichungen_Zeiten.md).
Vollständige Modulsuite auf dem gemeinsamen Stand separat dokumentieren.
Der ergänzte Ergebnisbild-Fall ist ebenfalls grün (1 Test, 6 Assertions).

## Ergänzende Prüfung vom 18.11.2026: Paketbaum und Standardmengen

Prüfauftrag von Dominique, noch **nicht als Produktfunktion umgesetzt**:
Format → Konzept → Paket (auch verschachtelt) → Gericht → Basisrezept → Zutaten.
Alle Zweige zunächst geschlossen. Bestätigt: je Gericht Menge pro Gast und
Gesamtbedarf zeigen, zum Beispiel 0,5 Portionen/Gast × 100 Gäste = 50 Portionen.
Der eigene Portionsrechner jedes Gerichts bleibt erhalten; die Basisrezept-Vorschau
folgt weiterhin der dort eingestellten Menge. Handbuch und Kundenansicht sind für
Tablet und Handy vorgesehen. Bestehende mobile Funktionen bleiben erhalten;
der Pflege-Editor wird hierdurch nicht neu für Handy gestaltet.

Code-Befund: `HandbuchQuellenService` übernimmt bislang nur Gerichtzeilen mit
`recipe_id`; Paketüberschriften und Mengen gehen dabei verloren. `WordingResolver`
liefert zwar eingerückte Paketzeilen, aber keine vollständige Mengenstruktur.
`ConceptService::mengenHochrechnung` löst alte Pakete und direkte Gerichte auf,
noch nicht rekursiv die eingebetteten Konzepte. Eine reine Baumansicht aus der
aktuellen flachen Gerichtsliste reicht deshalb fachlich nicht.

Für die Erweiterung erforderlich: eindeutiger Positionspfad samt gewählter
Darreichung (dasselbe Gericht darf mit verschiedenen Mengen mehrfach vorkommen),
rekursiv aufgelöste Mengen aus einem zentralen Fachservice, getrennte Zustände für
Gästezahl und Gerichtsportionen sowie Snapshot-Übernahme ohne Preisfelder.
Alternative Konzepte eines Formats bleiben getrennt. Fehlende Mengen werden
als fehlend gezeigt, nicht als null oder automatisch gleichverteilt geraten.
Die Mengenregel ist abgestimmt; die zusätzliche Baum-Implementierung ist offen.

Gesamtabnahme auf Commit `56a1eb6c`, Basis main `7f42e846`: 8.956 Tests,
49.887 Assertions; 8.940 bestanden, 13 übersprungen, 3 fehlgeschlagen. Zwei
KI-Pflichtlisteneinträge fehlten; der dritte Fehler (historischer
Untergruppen-Migrationstest) wurde auf unverändertem Main reproduziert.
Nach Korrektur der Dokumentation und des expliziten Test-Ausgangsbestands:
10 betroffene Tests mit 50 Assertions grün. Kein erneuter vollständiger Lauf
für diese Dokumentations-/Fixture-Korrekturen behauptet.

Späterer Deploy laut Dominique (18.11.2026) über die Plattform io und deren
`update.sh`; konkreten Hostpfad/Parameter vor Ausführung prüfen.
