<?php

// Spec 84 · Bestand des Wächters „Sichtbar ist nicht eigen" (Spec84ServiceEigentumWaechterTest).
// Je Methode der fachliche Grund, warum sie nach visibleToTeam ohne Eigentums-Riegel schreiben darf
// (Urteile aus docs/PLANUNG/84_B2_Pruefliste.md §4). Die Liste darf nur schrumpfen.
// Stand 2026-10-17: 17 Methoden.

return [
    'Services/BulkEnrichService.php::verarbeiteGp' => 'legt nur eigene KI-Vorschläge an (team_id = Team); der Riegel sitzt in uebernehmenGp',
    'Services/BulkEnrichService.php::verarbeiteRezept' => 'legt nur eigene KI-Vorschläge an (team_id = Team); der Riegel sitzt in uebernehmen',
    'Services/AngebotKopieService.php::kopieren' => 'Kopie ins eigene Team (team_id = Team, neue Ids, eigene Bilddateien); das Original wird nur gelesen',
    'Services/ConceptService.php::alsVorlageSpeichern' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/ConceptService.php::duplicate' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/ConceptService.php::forkVonVorlage' => 'Kopie einer sichtbaren Vorlage ins eigene Team; die Vorlage wird nur gelesen',
    'Services/FeedbackService.php::erstelle' => 'eigene Feedback-Zeile (team_id = Team) zu einem sichtbaren Rezept; das Rezept wird nicht verändert',
    'Services/FoodbookService.php::dupliziere' => 'Kopie ins eigene Team, auch geerbter Foodbooks (team_id = Team, neue Ids, eigene Bilddateien über DuplikatBilderService); das Original wird nur gelesen (98 · Bild-Löschschutz PR 2)',
    'Services/MatchService.php::bulkFuerLieferant' => 'legt nur eigene Match-Vorschläge an; sichtbare Artikel werden nur gelesen',
    'Services/OrderService.php::addManualLine' => 'eigene Bestellzeile, die einen sichtbaren Lieferantenartikel referenziert (bestellbar = sichtbar)',
    'Services/PaketService.php::duplicate' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/PreislistenImportService.php::stage' => 'nur Staging-Zeilen des eigenen Teams; sichtbare Lieferanten werden nur gelesen',
    'Services/RecipeService.php::duplicate' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/SalesRecipeService.php::createFromBasis' => 'neues eigenes Gericht, das ein sichtbares Basisrezept referenziert (Master-Vererbung)',
    'Services/StammLieferantService.php::setStamm' => 'eigene Stamm-Zeile (team_id = Team), die einen sichtbaren Lieferanten referenziert',
    'Services/SupplierItemService.php::create' => 'eigener Artikel (team_id = Team) an einem sichtbaren Lieferanten',
    'Services/SupplierService.php::addContact' => 'eigener Kontakt (team_id = Team) an einem sichtbaren Lieferanten',
    'Services/WettbewerbService.php::vorschlagen' => 'eigener Fund (team_id = Team des Artikels), der ein sichtbares Wettbewerber-Profil referenziert; das Profil wird nur gelesen',
    'Services/VocabularyService.php::createEinheit' => 'globale Einheit nur durch den Master (nurPlattformPflege); exists() ist nur Dubletten-Schutz',
    'Services/Einstellungen/BehaelterService.php::anlegen' => 'eigene Katalog-Zeile (team_id = Team); applyVisible()->exists() ist nur Dubletten-Schutz in der eigenen Sicht (98 · 2.3a, vorher Livewire Behaelter::create)',
    'Services/Einstellungen/ConcepterDimensionenService.php::anlegen' => 'eigene Vokabular-Zeile (team_id = Team); applyVisible()->exists() ist nur Dubletten-Schutz in der eigenen Sicht (98 · 2.3a)',
    'Services/VocabularyService.php::createRecipeCategory' => 'eigene Kategorie, die eine sichtbare Hauptgruppe referenziert',
    'Services/Kaskade/KaskadeSpeiseplanVoll.php::starteSpeiseplanVollkaskade' => 'legt nur eigene Lauf-/Step-Zeilen an (team_id = Team); der Plan wird hier nur gelesen, eingetragen wird über SpeiseplanService::addEintrag; bis 3.3 S1 privat hinter starteKaskade. Durch Verschiebung sichtbar geworden (98 · 3.3 S1), keine neue Fläche',
];
