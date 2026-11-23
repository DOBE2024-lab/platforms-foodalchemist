<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

/**
 * #1003 PR7 · Planung am Handy — bedienbar, aber nur der Handy-Umfang (Dominique 30.10.): Auftrag geben für Gericht,
 * Basisrezept und Concept (Import liegt in der eigenen Komponente RezeptImport), Kaskade starten, Lauf abbrechen und
 * wieder aufnehmen, Ergebnis lesen und freigeben (einzeln, je Stufe, je Cluster) samt Anreicherung, Fotos zu einem Schritt. Alle KI-Starts laufen als UI-Klick
 * über die Plattform-KI, dieselben Methoden wie am Computer.
 * Gesperrt bleiben: Verwerfen (Schritt, alle, Planung), Plan ändern, Konzept nachhängen, Favorit, Lücken melden,
 * Neu generieren, Erzeugen, Bilder neu, Ausgabe-Formen aus dem Brief, Composer, Trendradar-Kombinierer, Duplizieren, Vorlagen speichern.
 */
trait HandyPlanung
{
    use HandyLesebereich;

    public function handyLeseMethoden(): array
    {
        return [
            // Übersicht und Editor
            'neuePlanung', 'schnellErstellen', 'schnellImport', 'oeffne', 'waehle', 'speichern', 'kaskadeGestartet', 'importAngelegt',
            // Auftrag (Brief, Leitplanken, Diktat, KI-Plan) und Start
            'reglerPill', 'briefVorlage', 'titelVorschlagen', 'suchbegriffEntfernen', 'suchbegriffHinzufuegen', 'leitplankenAusBriefing',
            'briefDiktatUebernehmen', 'agentDiktatUebernehmen', 'voiceKomponentenUebernehmen', 'wissenVorschau', 'kiKopf',
            'planFeldSpeichern', 'planVerwerfen', 'goKaskade',
            // Ergebnis: Lauf beobachten, abbrechen, wieder aufnehmen; freigeben und anreichern (Dominique 30.10.: „wenn ich das
            // plane, will ich es auch freigeben, so dass alles angereichert wird“ — UI-Klick über die Plattform-KI)
            // (Spec 85 C1 PR-3: die Lauf-Methoden selbst liegen im Kind Planung\KaskadenLauf, s. KaskadenLauf::handyLeseMethoden; hier nur die
            // Parent-Empfänger, die nichts schreiben: Board neu zeichnen, Lauf-Stand und Meldungen des Kinds anzeigen)
            'laufGeaendert', 'laufStatus', 'kindMeldung', 'laufAbbrechen',   // laufAbbrechen: Stopp-Knopf am Board
            '_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload',   // Brief-Diktat (Audio)
            'nextPage', 'previousPage', 'gotoPage', 'setPage', 'resetPage',
        ];
    }
}
