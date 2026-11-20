<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * Zeitplan der Modul-Läufe (98 · 1.2d: aus dem ServiceProvider herausgezogen, wie `_liste.php`).
 *
 * Warum der Plan im Modul hängt und nicht im Host-Kernel, und warum nur diese Läufe: siehe Docblock von
 * `FoodAlchemistServiceProvider::planeLaeufe()`. Der Host kann alles über `foodalchemist.scheduler.enabled` abschalten.
 * Neuer Lauf: eigener Block mit Kommentar (warum, wann, Schalter); `CommandListeTest` prüft, dass jeder geplante
 * Command auch in `_liste.php` steht.
 */

return static function (Schedule $schedule): void {

    $schedule->command(\Platform\FoodAlchemist\Console\SignaleDetektorCommand::class)
        ->dailyAt(config('foodalchemist.scheduler.detektor_zeit', '03:20'))
        ->withoutOverlapping()   // ein zweiter Lauf würde in dieselbe Zeitreihe schreiben
        ->onOneServer()          // Hausschreibweise des Hosts (routes/console.php)
        ->runInBackground()
        ->description('FoodAlchemist: Qualitäts-Lauf (Signale, DQ-Kaskade, Zeitreihen-Snapshot, Drift)');

    // Fällige vorgemerkte Preise (Preisliste + Stammdaten): früh morgens, idempotent, Protokoll im Log und am Lauf.
    $schedule->command(\Platform\FoodAlchemist\Console\FaelligePreiseCommand::class)
        ->dailyAt(config('foodalchemist.scheduler.faellige_preise_zeit', '05:30'))
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground()
        ->description('FoodAlchemist: fällige vorgemerkte Preise schreiben (Preislisten + Stammdaten)');

    // Necta-Abgleich (PR 2): nachts Preis-Delta + neue Artikel, wöchentlich Vollvergleich. NUR bewusst eingeschaltet
    // (foodalchemist.necta.abgleich_aktiv, Standard aus): erst Probelauf und Dominiques Ja, dann einschalten.
    if (config('foodalchemist.necta.abgleich_aktiv', false)) {
        \Platform\FoodAlchemist\Console\NectaAbgleichCommand::planen($schedule);
    }

    // Paket „Grundeinstellungen“ (Dominique 20.11.2026): was der Master neu anlegt, kommt stündlich bei den Mandanten mit gebuchtem Paket an.
    // Nur neue Zeilen, nie überschreiben; abschaltbar.
    if (config('foodalchemist.scheduler.grundeinstellungen_aktiv', true)) {
        \Platform\FoodAlchemist\Console\GrundeinstellungenNachziehenCommand::planen($schedule);
    }

    // KI-Kosten (Dominique 18.10.2026): EZB-Tageskurs EUR/USD, 16:30 Europe/Berlin — fern vom Necta-/Gebühren-Fenster (nachts)
    if (config('foodalchemist.scheduler.wechselkurs_aktiv', true)) {
        \Platform\FoodAlchemist\Console\WechselkursHolenCommand::planen($schedule);
    }

    // Spec 82 §13 D: FA-Gebühren täglich zählen (Tageswerte in team_billable_usages). Idempotent; verpasste Tage mit --von/--bis nachholen.
    // Nur mit bewusst eingeschalteter Abrechnung (config foodalchemist.gebuehren.zaehlen_aktiv, Default aus): der Lauf schreibt in die Core-Rechnungstabelle.
    if (config('foodalchemist.gebuehren.zaehlen_aktiv', false)) {
        $schedule->command(\Platform\FoodAlchemist\Console\PlanNutzungZaehlenCommand::class)
            ->dailyAt(config('foodalchemist.scheduler.plan_zaehlen_zeit', '02:10'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Gebühren je Mandant zählen (Basis, User, Unter-Teams, Stamm-Paket, Abo)');
    }

    // Wissens-Steuerdaten-Wächter (2026-09-03). Rein LESEND (`--verify`), kein
    // Provider-Call, kein Schreibvorgang an den Steuerdaten — er meldet nur.
    //
    // Warum er sein muss: die Routings/Bindings sind per Hand editierbar (MCP, SQL), und
    // genau das ist schon passiert (Live-Tabelle `regelwerk|discovery|4x8000` gegen
    // Migration `always|1|7000`). Ein Regelwerk, das leise aus dem Prompt fällt, erzeugt
    // KEINEN Fehler — der Generator läuft weiter und liefert nur schlechtere Rezepte.
    // Ohne diesen Lauf ist jede Token- und Qualitätsarbeit an den Prompts in Wochen
    // wieder aufgebraucht, ohne dass jemand den Grund benennen kann.
    //
    // Der Befund geht als SIGNAL ins Cockpit, nicht in ein Log: eine Log-Zeile hätte
    // dieselbe Eigenschaft wie der alte Deckel-Vermerk in `params` — technisch vorhanden,
    // faktisch unsichtbar.
    $schedule->command(\Platform\FoodAlchemist\Console\WissenSteuerdatenW0Command::class, ['--verify'])
        ->weeklyOn(1, config('foodalchemist.scheduler.steuerdaten_zeit', '06:30'))
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground()
        ->description('FoodAlchemist: Wissens-Steuerdaten gegen das Soll prüfen (Drift → Signal)');

    // Spec 50 · Etappe 8b — der Deckel- und Verdrahtungs-Wächter.
    //
    // Zwei Invarianten, die beide STILL brechen: ein Dossier über 4.000 Zeichen fällt
    // erst auf, wenn jemand die Fehlermeldung in der Oberfläche sieht; ein Dossier, das
    // im Register fehlt, fällt GAR NICHT auf — `ablauf.GET` liefert es einfach nie.
    // Nach dem Split liegen sieben Dossiers zwischen 3.900 und 3.993 Zeichen, die
    // nächste Ergänzung kippt eines davon. Deshalb wöchentlich statt auf Zuruf.
    $schedule->command(\Platform\FoodAlchemist\Console\WissenDeckelCheckCommand::class)
        ->weeklyOn(1, config('foodalchemist.scheduler.deckel_check_zeit', '06:45'))
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground()
        ->description('FoodAlchemist: Wissens-Dossiers gegen Zeichen-Deckel und Vorgangs-Verdrahtung prüfen');

    // Spec 52 · Paket 3 — hält die Kanon-Sicherung gegen den Live-Stand.
    //
    // Der Kanon entsteht nur über MCP und lebt danach ausschliesslich als DB-Zeilen (H7).
    // Jede Kuration, die nicht exportiert wurde, ist bei einem Neuaufbau weg — und weil
    // `hasCanon()` dann false liefert, greift der Bindungs-Fallback, den es dort ebenfalls
    // nicht gibt. Der Generator läuft ohne Regelwerk, ohne Fehler. Genau die Sorte Verlust,
    // die niemand bemerkt, solange niemand danach fragt: also wöchentlich fragen.
    // `kanon_team = 0` schaltet ihn ab — eine frische Instanz ohne dieses Team soll
    // keinen wöchentlich fehlschlagenden Wächter erben, sondern gar keinen.
    $kanonTeam = (int) config('foodalchemist.scheduler.kanon_team', 6);
    if ($kanonTeam > 0) {
        $schedule->command(\Platform\FoodAlchemist\Console\WissenKanonSicherungCommand::class, [
            'richtung' => 'pruefen', '--team' => $kanonTeam,
        ])
            ->weeklyOn(1, config('foodalchemist.scheduler.kanon_sicherung_zeit', '06:50'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Kanon-Sicherung gegen den Live-Kanon prüfen (Drift → Signal)');
    }

    // Spec 84 B3 Regel B: Abo-Hüllen nachts nachführen (gelöschte Verweise lassen ältere Fassungen sonst zu lange sichtbar).
    // 01:40 = vor Gebühren-Zählung (02:10) und Necta-Abgleich (02:30, eigene Sperre); eigene Sperre über withoutOverlapping.
    $schedule->command(\Platform\FoodAlchemist\Console\AboHuelleNachfuehrenCommand::class)
        ->dailyAt(config('foodalchemist.scheduler.abo_huelle_zeit', '01:40'))
        ->withoutOverlapping(30)
        ->onOneServer()
        ->runInBackground()
        ->description('FoodAlchemist: Abo-Hüllen nachführen (Regel B: ältere Fassungen nur mit eigenem Verweis)');

    // Spec 57 · E9: Speiseplan-Aushänge „immer die laufende Woche“ jeden Montag früh neu
    // einfrieren. Nur lesend+veröffentlichend, kein Provider-Call.
    $schedule->command(\Platform\FoodAlchemist\Console\SpeiseplanAushangRollierenCommand::class)
        ->weeklyOn(1, config('foodalchemist.scheduler.speiseplan_rollieren_zeit', '00:20'))
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground()
        ->description('FoodAlchemist: Speiseplan-Aushänge auf die laufende Woche neu einfrieren');

    // Spec 79: wöchentliche Google-Trends-Messung — nur Teams mit trend_dataforseo_enabled, Budget-Stopp je Team.
    if (config('foodalchemist.scheduler.trends_messen_enabled', true)) {
        $schedule->command(\Platform\FoodAlchemist\Console\TrendsMessenCommand::class)
            ->weeklyOn(2, config('foodalchemist.scheduler.trends_messen_zeit', '06:10'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Trendradar → Google Trends je Trend messen (DataForSEO)');
    }

    // Markt in Zahlen (Dominique 07.11.2026): monatlich Destatis holen (Master-Team), danach stößt der Abruf die Einordnung an.
    if (config('foodalchemist.scheduler.marktdaten_enabled', true)) {
        $schedule->command(\Platform\FoodAlchemist\Console\MarktdatenAbrufenCommand::class)
            ->monthlyOn((int) config('foodalchemist.scheduler.marktdaten_tag', 25), config('foodalchemist.scheduler.marktdaten_zeit', '06:20'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Observatorium → Markt in Zahlen (Destatis) holen, Einordnung anstoßen');
    }

    // Spec 93 A: täglicher Quellen-Abruf (nur fällige plattform-Quellen). Standard AUS, bis Dominique einschaltet.
    if (config('foodalchemist.scheduler.trend_quellen_enabled', false)) {
        $schedule->command(\Platform\FoodAlchemist\Console\TrendQuellenAbrufenCommand::class)
            ->dailyAt(config('foodalchemist.scheduler.trend_quellen_zeit', '05:50'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Trendradar → fällige Quellen in den Eingang holen');
    }

    // Spec 93 C: Trend-Automatik täglich NACH dem Abruf (05:50) und dem Erschließen (Queue). Standard AUS.
    if (config('foodalchemist.scheduler.trend_automatik_enabled', false)) {
        $schedule->command(\Platform\FoodAlchemist\Console\TrendAutomatikCommand::class)
            ->dailyAt(config('foodalchemist.scheduler.trend_automatik_zeit', '06:40'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Trendradar → Themen einordnen, Trends anlegen (Automatik)');
    }

    // Vertiefung: Marktbewegungen und harte Quellen, höchstens trends.vertiefung.auto_je_tag je Team. Standard AUS.
    if (config('foodalchemist.scheduler.trend_vertiefung_enabled', false)) {
        $schedule->command(\Platform\FoodAlchemist\Console\TrendEingangVertiefenCommand::class)
            ->dailyAt(config('foodalchemist.scheduler.trend_vertiefung_zeit', '07:10'))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: Trendradar → Marktbewegungen vertiefen (Kernaussagen mit Beleg)');
    }
};
