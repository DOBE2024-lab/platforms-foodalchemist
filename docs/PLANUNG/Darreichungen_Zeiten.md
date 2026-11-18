# Darreichungen und zentrale Zeitpflege

Stand: 18.11.2026. Auftrag: Dominique, Konzept und Mockup in diesem Chat.
Funktionsbezug: RE-12/13/15/16, PR-01/04/07 der [Funktionsmatrix](25_Business_Case_Funktionsmatrix.md).
Status: Implementierung im separaten Branch, noch keine Produktionsfreigabe.

## Entscheidung

Kalkulation bündelt die Angaben je Darreichung. Eine Standard-Darreichung entsteht
weiterhin bei der Gerichtsanlage. Sie kann eine beliebige Servierform und ein Gefäß
bekommen. Der Standard bestimmt die Verkaufs-Kennzahlen, die Auswahl im Editor
bestimmt nur, welche Variante bearbeitet wird.

Mehrere Varianten derselben Servierform sind erlaubt (z. B. zwei Buffetgläser).
Eigene Bezeichnungen unterscheiden sie auch in Konzept, Produktionsauswahl und
Speiseplan. Explizite Darreichungs-IDs bleiben die maßgebliche Auswahl. Ein reiner
Form-Fallback wählt bevorzugt den Standard dieser Form, sonst die älteste aktive
Darreichung; dies entspricht der bisherigen Auswahl bei eindeutigem Bestand.

Aktive Arbeitszeiten werden an der Darreichung gespeichert. Jede Tätigkeit hat
eine explizite Basis und einen Arbeitsort. Sekunden: Portion, Glas/Einheit, Stück.
Minuten: Ausgabeplatte/-behälter, Kilogramm, Auftragszeile. Zeitbasis und Servierform
sind unabhängig. Küche zählt in Personenminuten und Küchenlohn. Vor-Ort-Arbeit wird
separat ausgewiesen und als Servicekosten mit dem bestehenden allgemeinen
Team-/Betriebsstundensatz gerechnet (Dominique, 18.11.2026). Lohnnebenkosten und
Gemeinkosten laufen einmal über das vorhandene Auftragsschema. Keine zusätzliche
Satzpflege. Küchenplanung enthält keine Service-Personenminuten.

Ein Ausgabe-Posten je Darreichung ersetzt nur deren Anrichte-Verteilung.
Regeneration bleibt berechnet und verwendet weiterhin ihre bisherige Verteilung.
Ohne expliziten Ausgabe-Posten bleibt die bisherige Komponenten-/Gerichtsverteilung.
Passive Standzeit bleibt am Gericht, wird aber in der Kalkulation gepflegt.
Die Prozess-Tabs Regeneration, Fertigstellen und Anrichten bleiben erhalten.
Wirkungslose Batch- und Fertigstellungsfelder werden nicht mehr angezeigt.

## Persistenz, Kompatibilität und Rückweg

Additive Spalten an der bestehenden teamgebundenen Darreichung: `name`,
`arbeitszeiten` (JSON), `ausgabe_station_id`, `menge_vom_standard` und eine
Referenzgrammatur für eigene Komponentenänderungen. Die Liste ist Bestandteil der
Darreichung und benötigt weder einen unabhängigen Tenant noch eine zweite
Schreibberechtigung. Alle Änderungen laufen über `DarreichungService` und dessen
Ownership-/Referenzprüfung. Neue Zeitwerte werden zentral validiert und berechnet.

`arbeitszeiten = NULL` erhält die bisherige Berechnung vollständig. Beim Lesen
wird kein Datensatz umgeschrieben. Die bisherige Rechnung wird im Editor als
Bestand/Vorgabe angezeigt. Erst „Zeiten übernehmen“ speichert die explizite Liste
und ersetzt die alte Rechnung vollständig. `[]` bedeutet bewusst keine aktive
Arbeit; Altwerte dürfen dann nicht erneut hinzugezählt werden. Fehlende
Einheiten-/Plattenmengen bleiben als unvollständig erkennbar; Planung und
Auftragskalkulation erhalten dieselbe Warnung.

Der frühere Unique-Index (Rezept, Servierform) wird durch einen normalen Index
ersetzt; die Ein-Standard-Regel bleibt bestehen. Softgelöschte Varianten werden
bei einer Neuanlage nicht mehr gelöscht. Ein Schema-Rollback verweigert den
Rückbau, solange Mehrfachvarianten existieren, statt Bestandsdaten zu löschen.

Keine produktive Migration, kein Backfill, kein Recompute-Lauf und kein Deployment
wurden mit diesem Arbeitspaket ausgeführt. Vor Freigabe: Backup, MySQL-Smoke und
Prüfung der Mehrfachvarianten gegen angebundene WaWi-Importe. Ein etwaiger späterer
Bestandslauf braucht einen eigenen Befehl mit Trockenlauf und Backup-Nachweis.

KI-Vorschläge für Zeiten liegen zunächst ausschließlich im Formular. Erst die
Übernahme speichert sie mit KI-Herkunft und Confidence. Handänderungen entfernen
die KI-Kennzeichnung der betroffenen Zeile. Bestehende Generator-/OneShot-Pfade
bleiben kompatibel über den Legacy-Rückfall; sie überschreiben keine explizite
Zeitliste. Ihre vollständige Umstellung ist ein Folgepaket.

## Fachliche Entscheidungen im Chat

1. Gericht und Darreichung beschreiben jeweils eine Portion. Kein Anzahl-Feld,
   auch kein erweiterter Mehrglas-Modus. Neue Varianten haben eine Einheit.
   Abweichend gezählter Bestand bleibt bis zu einer geprüften Umstellung erhalten
   und wird als Altbestand gekennzeichnet.
2. Varianten ohne eigene Mengen folgen dem neuen Standard. Eigene Grammaturen,
   Komponentenwerte und weggelassene Komponenten bleiben erhalten. Eine zum
   Standard beförderte Variante behält ihre wirksame Komposition.
3. Vor-Ort-Arbeitszeit erzeugt eigenen Servicelohn in der Auftragskalkulation,
   mit dem allgemeinen Team-/Betriebsstundensatz.

## Abnahme

- 100 Buffetgläser × 20 s plus 5 min Vorbereitung = 38 min 20 s Küchenarbeit,
  unabhängig von Ausgabeplatten und Gerichts-Altwerten.
- 2 Einheiten je Portion skalieren die Abfüllzeit, die Pauschale zählt einmal.
- Vor-Ort-Arbeit zählt separat; passive Standzeit und Regeneration werden nicht
  als manuelle Tätigkeit doppelt berechnet.
- Standardwechsel erhält genau einen Standard; gleichartige Servierformen sind
  separat pflegbar. Mengen- und Preislogik über vorhandene Fachservices.
- Ungültige Zeitwerte, Fremdteam- und Parent-Schreibzugriffe werden abgewiesen.
- MCP und UI verwenden denselben Schreib- und Rechenpfad.
- Nachweise: `DarreichungArbeitszeitenTest`, bestehende Produktions-,
  Kalkulations-, Policy-, UI- und Architekturwächter. Browserprüfung separat
  dokumentieren; eine gerenderte View allein ist keine Interaktionsabnahme.
