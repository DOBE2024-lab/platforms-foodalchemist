---
title: Kalkulation
order: 6
---

# Kalkulation

Die Kalkulation besitzt zwei getrennte Rechenkreisläufe. Ein Katalogpreis ist mengenunabhängig;
ein belastbarer Produktions-HK2 entsteht erst mit einer konkreten Personenzahl.

## Katalogpreis ohne Pax

```text
MEK je Darreichung
→ dynamischer Unternehmens-Basissatz
→ relativer Klassenfaktor
→ Auto-Vorschlag
→ gültiger Katalog-VK
```

Der Basissatz normalisiert die bestätigten Monatswerte `MEK`, `FEK` und `HK` auf einen Euro
Wareneinsatz. Aktive Gemeinkostenblöcke auf MEK, FEK und HK sowie der Gewinnaufschlag auf HK2
fließen in den Faktor ein. Fehlen belastbare Monatsbasen, verwendet das System sichtbar den
Fallback `100 / Ziel-Wareneinsatzquote`.

```text
Auto-VK netto = Darreichungs-MEK × Basissatz × Klassenfaktor / 100
```

Preisklassen sind relative Produktabweichungen. `100 %` übernimmt den Unternehmenssatz
unverändert; sie sind kein harter Gesamtaufschlag mehr. Der Katalogpreis verwendet weder
Produktionszeit noch eine angenommene Auftragsgröße.

Preisklassen haben einen Bereich (Spec 94): `speisen` für Darreichungen, Concepter und den
Team-Standard, `leistung` für Personal, Equipment und Logistik im Konfigurator. Beide rechnen mit
derselben Formel (`CatalogPricingService::klassenVk`), mischen sich aber nie in einer Auswahl.
Eine Leistung ohne Klasse fällt nicht auf den Team-Standard zurück, sondern rechnet neutral mit
100 % und zeigt eine Warnung.

## Darreichungen pflegen

Ein Gericht und jede seiner Darreichungen beschreiben jeweils eine Portion.
120 g im Glas sind eine Portion; zwei bestellte Gläser sind zwei Portionen.
Ein zusätzlicher Anzahl-Zähler wird nicht gepflegt. Abweichender Altbestand wird
angezeigt und erst nach einer geprüften Umstellung geändert.

Links wählst du eine Darreichung, rechts pflegst du deren Bezeichnung, Servierform,
Gefäß, Menge, Preisklasse und Preis. Beim Anlegen eines Gerichts entsteht eine
Standard-Darreichung. Sie kann zum Beispiel auf Buffet im Schraubglas umgestellt
werden. Über „Darreichung hinzufügen“ entstehen weitere Varianten, auch mit
derselben Servierform. Die Verkaufs-Kennzahlen im Kopf beziehen sich immer auf
den Standard, unabhängig von der gerade geöffneten Variante.

Unter „Komponenten & Mengen“ passt du bestehende Komponenten an oder lässt sie
weg. Eigene Komponentenmengen ergeben zusammen die Grammatur dieser Darreichung.
Neue Zutaten gehören in ein eigenes Gericht.

Neue Varianten übernehmen zunächst die Standardmenge. Mit „Standardmenge verwenden“
folgt die Standard-Darreichung der Rezeptur und eine weitere Variante dem aktuellen
Standard. Die Gesamtgrammatur ist nur eine Anzeige der Komponentensumme. Schalte
den Haken aus, um die Mengen unten einzeln zu bearbeiten; die bisherigen Werte
bleiben zunächst erhalten. Einschalten setzt eigene Komponentenabweichungen dieser
Darreichung bewusst zurück. Geerbte Mengen folgen einem Standardwechsel; eigene
Mengen und weggelassene Komponenten bleiben erhalten. Der Editor nennt die Herkunft
je Komponente.

„Arbeitsschritte & Zeiten“ erfasst die konkrete Tätigkeit, Zeitbasis und den
Arbeitsort. Abfüllen kann auch bei Buffet je Glas in der Küche gerechnet werden.
Die Vorschau zeigt den gespeicherten Stand für 100 Portionen. Fehlende Mengen
werden als unvollständige Berechnung angezeigt. Vor-Ort-Zeit und Service-Grundlohn
werden separat gezeigt. Der Auftrag rechnet den Service mit dem allgemeinen
Team-Stundensatz und ergänzt die geltenden Lohnnebenkosten und Aufschläge.
Ein expliziter Ausgabe-Posten gilt
nur für die Küchenarbeit dieser Variante. Regeneration wird weiterhin berechnet.
Die passive Standzeit und gemeinsame Nebenkosten stehen unter den eingeklappten
„Übergreifenden Angaben“ des Gerichts.

Bisherige Zeiten bleiben wirksam, bis du die neuen Arbeitsschritte ausdrücklich
übernimmst. Eine bewusst leere Liste ersetzt die bisherigen Zeiten durch keine
aktive Arbeit. „Zeiten vorschlagen“ zeigt einen ungeprüften KI-Vorschlag, der erst
mit „Zeiten übernehmen“ oder dem allgemeinen „Speichern“ gespeichert wird.
Zeitentwürfe bleiben auch bei anderen Feldänderungen im Editor erhalten. Beim
Wechsel der Servierform behalten explizite bisherige Zeiten ihre Bezugsgröße:
Sekunden je Portion werden dadurch nicht zu Minuten je Platte.

## Preiszustände

- `auto`: Vergleichspreis und gültiger VK werden neu berechnet.
- `fixed`: Der Vergleichspreis läuft weiter, der freigegebene VK bleibt stehen.
- `manuell`: nur als lesbarer Legacy-Zustand; neue Fixierungen verwenden `fixed`.

Eine neue Fixierung benötigt Preis und Begründung. Benutzer, Zeitpunkt und optionales Ablaufdatum
werden protokolliert. Abgelaufene Fixierungen fallen auf `auto` zurück. Preisänderungen werden in
`foodalchemist_price_change_audits` mit altem und neuem Vergleichs- und Effektivpreis festgehalten.

MwSt wird als Schlüssel `regulaer` oder `ermaessigt` gespeichert. Der aktuelle Prozentsatz kommt
aus den zentralen Einstellungen; ein freies Prozentfeld an der Darreichung existiert nicht mehr.

## Auftrag mit Pax

```text
Concept-Menge pro Person × Pax
→ rekursiver, konsolidierter Rezeptbedarf
→ Produktionsvorgänge, Rüst- und variable Zeit
→ MEK + FEK + direkte Kosten + Gemeinkosten
→ auftragsspezifischer HK2
→ Mindestpreis und Zielpreis
```

Ein Angebot zeigt Katalogpreis, tatsächlichen Auftrags-HK2, Deckungsbeitrag, Mindestpreis,
Zielpreis und aktive Personenzeit. Liegt der Katalog- oder Angebotspreis unter dem Zielpreis,
erscheint eine Warnung. Der Preis wird nicht still erhöht.

## Fixkosten und Bezugsbasen

Abgeleitete Sätze berechnen sich je Block aus `Fixkosten pro Monat / Bezugsbasis × 100`.
Positive Fixkosten mit Basis `0` ergeben weiterhin `0 %` und eine Warnung. Die Einstellungen bieten
einen ausdrücklich gekennzeichneten, editierbaren Catering-Beispielsatz mit typischen Kostenarten
und Monatsbasen. Diese Werte sind Rechenbeispiele, keine Branchen-Norm.

## Rechenverantwortung

- `CatalogPricingService`: mengenunabhängiger Darreichungs- und Katalogpreis.
- `ProductionTimeService`: Vorgänge, aktive Personenzeit und passive Standzeit.
- `OrderCostingService`: Bedarfsexplosion und auftragsspezifischer HK2.
- `PricingCascadeService`: Darreichung → Paket → Concept → offenes Angebot.
- `KonfiguratorPricingService`: Leistungs-VK, Pakete schnüren und All-in je Concept und Pax-Paket (Spec 94).

Die Datenumstellung läuft trocken oder schreibend:

```bash
php artisan foodalchemist:pricing-v2
php artisan foodalchemist:pricing-v2 --apply --chunk=200
```

Der Lauf ist fortsetzbar und wert-idempotent. Aktive Preise werden auf `auto` neu gesetzt;
versendete, angenommene und abgelehnte Angebote bleiben historische Snapshots.
