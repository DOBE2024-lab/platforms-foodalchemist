<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * fa-pass Welle 0 — Design-Regeln (Sperrklinke) für UiRegelnTest. Eigene Datei, weil Test-Helfer
 * im Parallel-Lauf nicht als globale Funktion in einer Testdatei liegen dürfen (fa_test.sh).
 */
final class UiRegeln
{
    public const REGELN = [
        // Farbe gehört in Tokens (--fa-*), nicht in die View.
        'hex_farbe' => '/#[0-9a-fA-F]{6}\b/',
        // Überschreib-Kaskaden sind der Grund, warum der alte Dunkel-Editor nicht wartbar war.
        'important' => '/!important|(?<=[\s"\'])![a-z][a-z0-9-]*[\[-]/',
        // Symbole kommen aus Heroicons, nicht als Emoji.
        'emoji' => '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u',
        // Schriftskala: 12/13/14/16/24/28 — alles darunter ist unlesbar.
        'mini_schrift' => '/text-\[(?:8|9|10|11)px\]/',
        // Eigene <style>-Blöcke in Views umgehen die Tokens.
        'style_block' => '/<style\b/',
        // Inline-Stil nur für echte Laufzeitwerte (enthält {{ … }}).
        // (x-bind:style / :style sind gebundene Laufzeitwerte und zählen nicht.)
        // Ausnahme (Kurator 23.10., #520): ein style, der NUR Grid-Platzierung trägt (grid-column/grid-row/grid-area), ist Layout-Geometrie,
        // kein Design-Wert. Sie steht bewusst inline, weil io bei Modul-Deploys keine Assets baut — eine Klasse aus Tailwind oder der
        // Modul-CSS griffe dort erst nach einem Build (Preisverlauf: SVG schrumpfte auf 56 × 24 px). Sobald etwas anderes im selben style
        // steht (Farbe, Breite, Abstand …), zählt er wieder.
        'style_statisch' => '/(?<![:\w-])style="(?![^"]*\{\{)(?!\s*(?:grid-(?:column|row|area)\s*:\s*[^;"]+;?\s*)+")[^"]+"/',
        // Alter Komponenten-Namensraum (2.4a): Bausteine heißen x-fa::…; die alten Dateien unter components/ sind nur noch
        // Weiterleitungen für fremde Aufrufe (Bestand 0 seit 2.4a PR A). Benannte Ausnahme: x-foodalchemist::shell.* (page-navbar, text-css, fa-menu-script),
        // weil der Host food-alchemist.de components/shell/page-navbar überschreibt und ein x-fa-Name den Override still umginge.
        'alter_namensraum' => '/<\/?x-foodalchemist::(?!shell\.)/',
    ];

    /**
     * Welle 1, Paket 1.4d (Plan 98): Regeln für die Tailwind-Umstellung. Gelten NICHT unter components/ —
     * die Bausteine sind die eine Stelle, an der diese Literale/Elemente stehen dürfen.
     */
    public const REGELN_AUSSER_BAUSTEINE = [
        // Schriftgröße als Literal statt Text-Rolle (.fa-t-*, kommt mit 1.4a). Responsive Varianten zählen mit.
        'typo_groesse' => '/(?<![\w-])text-\[length:var\(--fa-text-[a-z0-9-]+\)\]/',
        // Textfarbe der Tinte-Skala als Literal (ink/ink-2/ink-3 = Rollen strong/body/muted). Status-Farben (crit/warn/ok) nicht.
        'typo_tinte' => '/(?<![\w-])text-\[var\(--fa-ink(?:-[23])?\)\]/',
        // Lokale Kurz-Definition der „leisen“ Schrift je View — gehört in eine Klasse.
        'leise_definition' => '/\$leise\s*=(?!=)/',
        // Rohe Knöpfe statt x-fa::button.
        'roher_button' => '/<button\b/',
        // Rohe Checkboxen statt x-fa::checkbox (1.4c). Attribute in Anführungszeichen und -> / => überspringen.
        // Ausnahme (Kurator, 1.4c): die Chip-Checkbox im .fa-chip-Label (input mit sr-only). Sie ist kein Haken, sondern ein
        // Chip; wo sie 1:1 auf x-fa::choice passt, wird sie dorthin umgestellt, sonst bleibt sie (Einzel-Booleans,
        // Alpine-x-model, Tag-für-Tag-Sperre, Häkchen-Symbol, title/truncate je Chip).
        'rohe_checkbox' => '/<input\b(?!(?:->|=>|[^>])*?\bsr-only\b)(?:->|=>|"[^"]*"|\'[^\']*\'|[^>"\'])*?\btype\s*=\s*["\']checkbox["\']/',
        // Handgebautes Aufklapp-Menü statt x-fa::menu (1.4c): x-data="faMenu()" gehört in den Baustein.
        // Bestand 0 seit 2.4a: die zwei Aufklapp-Panels ohne Menü-Liste („Neue Bestellrunde“, „KI-Assistent“) nutzen x-fa::popover.
        'rohes_menue' => '/x-data\s*=\s*["\']faMenu\(\)["\']/',
        // Rohe Menü-Einträge statt x-fa::menu-item (1.4c, Bestand 0 seit PR 3): role="menuitem" am eigenen <button>/<a>/<span>.
        'rohes_menue_item' => '/\brole\s*=\s*["\']menuitem["\']/',
        // Rohe Tabellen statt x-fa::table (2.4a). Bestand: Druckvorlagen (dokumente/*, presentation/*, DomPDF ohne Modul-CSS), Handbuch-Druck (handbuch/*, eigene handbuch.css), Observatorium-
        // Marktdaten (eigene Klasse) und drei Mini-Tabellen ohne .fa-table (fortschritt/rezept, settings/einkauf, speiseplan-chips).
        'roher_table' => '/<table\b/',
        // Handgebaute Karte statt x-fa::card (2.4a): Klasse fa-surface ODER bg-surface + border + Karten-Radius (surface/card/lg/xl/2xl)
        // in einem class-Attribut. Bestand: klickbare Karten (a, button, label), Tags mit Direktiven im Tag, figure.
        'rohe_karte' => '/(?<![:\w-])class="(?:(?:[^"]*\s)?fa-surface(?=[\s"])|(?=[^"]*(?<![\w-])bg-\[var\(--fa-surface\)\])(?=[^"]*(?<![\w-])border(?![\w-]))(?=[^"]*(?<![\w-])rounded-(?:\[var\(--fa-radius-(?:surface|card)\)\]|lg|xl|2xl)(?![\w-])))/',
        // Rohes Datei-Feld statt x-fa::upload (2.4a). Ausnahme: der Kamera-Knopf am Handy (label mit input sr-only).
        'roher_upload' => '/<input\b(?!(?:->|=>|[^>])*?\bsr-only\b)(?:->|=>|"[^"]*"|\'[^\']*\'|[^>"\'])*?\btype\s*=\s*["\']file["\']/',
        // Handgebauter Umschalter statt x-fa::segmented (2.4a): Spur mit p-0.5/p-1 auf neutral-soft/ground oder eine lokale
        // $segment/$seg/$segKnopf-Definition. Bestand: Reiter-Leisten (role="tablist"), Kiosk-Tagesplan, Raster-Spur in
        // orders/editor, die a-Knöpfe im Rezept-Schritt-Editor (bleiben im gesperrten fieldset bedienbar).
        'rohes_segment' => '/(?<![:\w-])class="(?=[^"]*(?<![\w-])p-(?:0\.5|1)(?![\w-]))(?=[^"]*(?<![\w-])bg-\[var\(--fa-(?:neutral-soft|ground)\)\])[^"]*"|\$seg(?:ment)?(?:Knopf)?\s*=(?!=)/',
        // Handgebauter Seitenrahmen statt x-fa::page (2.4a).
        'roher_seitenrahmen' => '/min-h-full bg-\[var\(--fa-ground\)\]/',
        // Alte Tailwind-Palette statt Tokens (--fa-*).
        'alte_palette' => '/(?<![\w-])(?:text|bg|border(?:-[xytrbl])?|ring|ring-offset|divide|outline|from|via|to|placeholder|fill|stroke|decoration|accent|caret|shadow)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(?:50|[1-9]00|950)(?![\w-])/',
    ];

    /**
     * Klassen-Strings, die aus Views in PHP gezogen wurden (gemeinsame Quelle für Livewire-Kinder). Sie zählen mit, sonst
     * fielen sie aus der Klinke. Schlüssel = Pfad relativ zum Modul; neue Einträge nur mit Paket-Verweis.
     */
    public const PHP_STILQUELLEN = [
        'src/Support/Produktion/WallStil.php',   // 98 · 3.3 S6: Küchenmonitor-Stil für Tagesplan-Wall und Kinder
    ];

    public static function zaehlen(): array
    {
        $basis = realpath(__DIR__ . '/../../resources/views');
        $ergebnis = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            $pfad = str_replace($basis . DIRECTORY_SEPARATOR, '', $datei->getPathname());
            $inhalt = file_get_contents($datei->getPathname());
            // Blade-Kommentare zählen nicht (Doku darf Beispiele zeigen).
            $inhalt = preg_replace('/\{\{--.*?--\}\}/s', '', $inhalt);
            $regeln = str_starts_with($pfad, 'components/') ? self::REGELN : self::REGELN + self::REGELN_AUSSER_BAUSTEINE;
            foreach ($regeln as $regel => $muster) {
                $anzahl = preg_match_all($muster, $inhalt);
                if ($anzahl > 0) {
                    $ergebnis[$pfad][$regel] = $anzahl;
                }
            }
        }
        foreach (self::PHP_STILQUELLEN as $pfad) {
            $inhalt = (string) file_get_contents(__DIR__ . '/../../' . $pfad);
            foreach (self::REGELN + self::REGELN_AUSSER_BAUSTEINE as $regel => $muster) {
                $anzahl = preg_match_all($muster, $inhalt);
                if ($anzahl > 0) {
                    $ergebnis[$pfad][$regel] = $anzahl;
                }
            }
        }
        ksort($ergebnis);

        return $ergebnis;
    }

    public static function baselinePfad(): string
    {
        return __DIR__ . '/../Fixtures/ui_regeln_baseline.json';
    }
}
