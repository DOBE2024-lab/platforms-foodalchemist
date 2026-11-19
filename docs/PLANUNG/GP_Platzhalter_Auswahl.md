# GP-Editor und manuelle Platzhalterauswahl

Stand: 19.11.2026. Auftrag Dominique. Bezug: ST-19 und RE-04 der Funktionsmatrix.
Korrektur im bestehenden separaten Branch; keine Live-Datenänderung und kein Deploy.

## Verhalten

- Der GP-Editor bleibt auch bei sichtbaren geerbten/globalen Grundprodukten
  erreichbar: „Im Editor ansehen“. Ein Hinweis erklärt die Besitzer-Pflege.
  Editor-Inhalte sind schreibgeschützt; „Speichern“ wird dort nicht angeboten.
  Eigene Grundprodukte behalten „Bearbeiten“ und den vorhandenen Sperrpfad.
- Beide GP-Tauschsuchen lassen verwendbare sichtbare Lebensmittel-Platzhalter
  zu und kennzeichnen sie. Abgelehnte, gemergte, fremde und Non-Food-Ziele bleiben
  ausgeschlossen. Der Austausch unterliegt weiterhin den vorhandenen Besitzerrechten.
- Beim bewussten Tausch auf einen Platzhalter wechseln nur die Rezept-Zuordnungen.
  Lieferantenartikel bleiben am ursprünglichen GP. Die Bestätigung benennt dies.
- Der Basisrezept-Zutatenkatalog kennzeichnet Platzhalter ausdrücklich. Der
  vorhandene manuelle Such-/Speicherpfad muss auch erst nach dem Öffnen neu
  angelegte Platzhalter finden und speichern. Automatisches Matching bleibt getrennt.
- Die Preislogik wird nicht geändert: ein Platzhalter ohne Preis ist nicht
  automatisch ein mit 0 Euro bepreistes Lebensmittel. Der Nutzer präzisierte
  den Auftrag als allgemeine manuelle Auswahl, nicht als Freigabe einer Nullpreisregel.
- Im Gericht bleibt die bestehende Regel „nur Basisrezepte“ erhalten.

## Prüfung

`GpPlatzhalterAuswahlTest`: neue Platzhalter in beiden Tauschsuchen; Neuerstellung
nach geöffnetem Zutateneditor, Suche und tatsächliches Speichern im Basisrezept;
Artikel bleiben beim Tausch an der Quelle; geerbter Editor bleibt schreibgeschützt;
Fremdteam-/Status-/Non-Food-Ausschluss. Bestehende Tausch-, GP-Modal-, Policy-,
UI- und Größenprüfungen ergänzen die Abnahme. Keine Bestandskorrektur ausgeführt.

Nachweise: Abschließende 5 Platzhalter-/Editor-Tests mit 25 Assertions grün.
Im begleitenden Lauf bestanden alle 33 bestehenden GP-/Tausch-/Policy-/UI-/
Größenprüfungen; ein falscher Relationsname im neuen Test wurde korrigiert und
der neue Testblock anschließend vollständig grün wiederholt. CSS-Build,
Composer-Validierung, PHP-Syntax und Diff-Prüfung bestanden.
Browser-Screenshots der gerenderten Fixture bei 1280/768 px zeigen den
Platzhalter-Treffer ohne Überlauf; die GP-Detailansicht zeigt den Lese-Einstieg.
Das ist visuelle SSR-Prüfung, keine echte Browser-Schreibstrecke. Schreiben
über Livewire bis zum gespeicherten Zutaten-Datensatz ist separat getestet.
Vollständige Modulsuite nicht ausgeführt; kein Deploy.
