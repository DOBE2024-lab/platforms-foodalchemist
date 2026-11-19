<?php

/**
 * Welle 1, Paket 1.4b (Plan 98) — Codemod: Typografie-Literale in Blade-Views → Text-Rollen (fa-t-*).
 *
 * Regeln (bewusst konservativ, damit sich keine Optik verschiebt):
 *  - Nur statische class="…"-Attribute und lokale $leise-Definitionen. Gebundene :class / x-bind:class,
 *    @class([...]) und Tokens, die an Blade-Ausdrücken kleben, bleiben unberührt.
 *  - Nur Tokens ohne Variante (kein md:, hover:, group-…:, kein !). Varianten bleiben stehen und übersteuern
 *    die Rolle weiterhin (Rollen liegen in @layer components, Varianten sind Utilities).
 *  - Umgestellt wird nur, wenn die Basis-Tokens EXAKT eine Rolle ergeben: gleiche Größe, gleiche Tinte,
 *    gleiches Gewicht (kein Gewicht = kein Gewicht). Alles andere bleibt und erscheint im Bericht.
 *  - Elemente mit Kollisionsgefahr bleiben unverändert und kommen auf die Prüfliste: bisher gewann die
 *    Utility-Ebene immer; künftig steht die Rolle in derselben Ebene wie z. B. `.fa-table th`
 *    oder `[data-fa-kpis] .kpi-label`, und dort entscheidet die Spezifität.
 *
 * Die Klasse ist rein (String rein, String raus); Dateien liest und schreibt nur scripts/codemod/text-rollen.php.
 */
final class TextRollenCodemod
{
    /** Rolle => [Größe, Tinte, Gewicht|null, weitere Pflicht-Tokens] — Werte wie resources/css/foodalchemist-text.css */
    public const ROLLEN = [
        'fa-t-h1' => ['3xl', 'ink', 'font-semibold', ['tracking-tight', 'leading-tight']],
        'fa-t-h2' => ['lg', 'ink', 'font-semibold', []],
        'fa-t-h3' => ['base', 'ink', 'font-semibold', []],
        'fa-t-strong' => ['md', 'ink', 'font-medium', []],
        'fa-t-body' => ['md', 'ink', null, []],
        'fa-t-label' => ['sm', 'ink-2', 'font-medium', []],
        'fa-t-muted' => ['sm', 'ink-3', null, []],
    ];

    private const GEWICHTE = ['font-thin', 'font-extralight', 'font-light', 'font-normal', 'font-medium', 'font-semibold', 'font-bold', 'font-extrabold', 'font-black'];

    /** Tags/Klassen, bei denen eine Komponenten-Regel mit höherer Spezifität dieselben Eigenschaften setzt. */
    private const KOLLISION_TAGS = ['th', 'dt'];

    private const KOLLISION_KLASSEN = ['fa-control', 'fa-titel-name', 'kpi-label', 'kpi-value', 'kpi-hint'];

    /** @var (callable(string): ?string)|null liefert den Quelltext einer eingebundenen View oder null */
    private $includeLeser;

    /**
     * @param  bool  $leiseAufloesen  Schritt 2: $leise-Definition entfernen und Nutzungen einsetzen (sonst nur Wert umstellen)
     * @param  (callable(string): ?string)|null  $includeLeser  View-Name (z. B. 'foodalchemist::livewire.x') → Quelltext; null = unbekannt
     */
    public function __construct(private bool $leiseAufloesen = true, ?callable $includeLeser = null)
    {
        $this->includeLeser = $includeLeser;
    }

    /**
     * @return array{html: string, umgestellt: array<string,int>, pruefen: list<string>, ohne_rolle: array<string,int>, leise: array<string,int>, leise_aufgeloest: int}
     */
    public function umwandeln(string $html, string $pfad): array
    {
        $bericht = ['umgestellt' => [], 'pruefen' => [], 'ohne_rolle' => [], 'leise' => [], 'leise_aufgeloest' => 0];
        $hatChip = str_contains($html, 'fa-chip');

        // 1) statische class-Attribute (nicht :class, x-bind:class, wire:… usw.)
        $html = preg_replace_callback(
            '/(?<![\w:.-])class=(["\'])(.*?)\1/s',
            function (array $m) use ($html, $pfad, $hatChip, &$bericht) {
                $start = strrpos(substr($html, 0, $m[0][1]), '<');
                $tag = $start !== false && preg_match('/^<([a-zA-Z][\w.:-]*)/', substr($html, $start), $t) ? strtolower($t[1]) : '';
                $m = [$m[0][0], $m[1][0], $m[2][0]];
                [$neu, $rolle, $tokens] = $this->klassenUmstellen($m[2]);

                if ($rolle === null) {
                    if ($tokens !== null) {
                        $bericht['ohne_rolle'][$tokens] = ($bericht['ohne_rolle'][$tokens] ?? 0) + 1;
                    }

                    return $m[0];
                }

                $grund = $this->kollision($tag, $m[2], $hatChip);
                if ($grund !== null) {
                    $bericht['pruefen'][] = "{$pfad}: <{$tag}> {$rolle} — {$grund}";

                    return $m[0];
                }

                $bericht['umgestellt'][$rolle] = ($bericht['umgestellt'][$rolle] ?? 0) + 1;

                return 'class='.$m[1].$neu.$m[1];
            },
            $html,
            -1,
            $anzahl,
            PREG_OFFSET_CAPTURE
        );

        // 2) lokale $leise-Definitionen (@php $leise = '…'; bzw. @php($leise = '…'))
        $html = preg_replace_callback(
            '/(\$leise\s*=\s*)([\'"])(.*?)\2/s',
            function (array $m) use (&$bericht) {
                [$neu, $rolle] = $this->klassenUmstellen($m[3]);
                $schluessel = $rolle ?? ($this->rolleIn($m[3]) ?? ('ohne Rolle: '.$m[3]));
                $bericht['leise'][$schluessel] = ($bericht['leise'][$schluessel] ?? 0) + 1;

                return $rolle === null ? $m[0] : $m[1].$m[2].$neu.$m[2];
            },
            $html
        );

        // 3) $leise auflösen (Kurator 19.11.): Wert in die class-Attribute einsetzen, Definition entfernen
        if ($this->leiseAufloesen) {
            $html = $this->leiseAufloesen($html, $pfad, $bericht);
        }

        return ['html' => $html] + $bericht;
    }

    /**
     * Nur wenn: genau eine Definition, Wert enthält eine Rolle und ist rein statisch, JEDE weitere Nennung ist
     * `{{ $leise }}` innerhalb eines statischen class="…", und keine eingebundene View (@include*, @each,
     * @extends — die erben lokale Variablen) nutzt $leise oder ist unauflösbar. Sonst: Prüfliste, nichts geändert.
     */
    private function leiseAufloesen(string $html, string $pfad, array &$bericht): string
    {
        $definitionen = preg_match_all('/\$leise\b\s*=(?!=)/', $html);
        if ($definitionen === 0) {
            return $html;
        }
        $nein = function (string $grund) use (&$bericht, $pfad, $html) {
            $bericht['pruefen'][] = "{$pfad}: \$leise bleibt — {$grund}";

            return $html;
        };
        if ($definitionen > 1) {
            return $nein('mehrfach zugewiesen');
        }
        if (! preg_match('/\$leise\s*=\s*([\'"])([^\'"{}$]*)\1/', $html, $d) || $this->rolleIn($d[2]) === null) {
            return $nein('Wert ist keine Text-Rolle oder nicht statisch');
        }
        $wert = trim(preg_replace('/\s+/', ' ', $d[2]));

        // Jede Nennung außer der Definition muss {{ $leise }} in einem statischen class-Attribut sein.
        $erlaubt = [];
        preg_match_all('/(?<![\w:.-])class="([^"]*)"/', $html, $attr, PREG_OFFSET_CAPTURE);
        foreach ($attr[1] as [$inhalt, $pos]) {
            preg_match_all('/\{\{\s*\$leise\s*\}\}/', $inhalt, $e, PREG_OFFSET_CAPTURE);
            foreach ($e[0] as [, $ep]) {
                $erlaubt[] = $pos + $ep + strpos(substr($inhalt, $ep), '$leise');
            }
        }
        preg_match_all('/\$leise\b/', $html, $alle, PREG_OFFSET_CAPTURE);
        $definitionPos = strpos($html, $d[0]);
        foreach ($alle[0] as [, $pos]) {
            if ($pos !== $definitionPos && ! in_array($pos, $erlaubt, true)) {
                $zeile = substr_count(substr($html, 0, $pos), "\n") + 1;

                return $nein("Nutzung außerhalb von class=\"{{ \$leise }}\" (Zeile {$zeile})");
            }
        }

        // Eingebundene Views erben lokale Variablen.
        preg_match_all('/@(?:include\w*|each|extends)\s*\((?:[^\'")]*?,\s*)?([\'"])([^\'"]+)\1/', $html, $inc);
        foreach ($inc[2] as $view) {
            $quelle = $this->includeLeser !== null ? ($this->includeLeser)($view) : null;
            if ($quelle === null) {
                return $nein("eingebundene View {$view} nicht prüfbar");
            }
            if (preg_match('/\$leise\b/', $quelle)) {
                return $nein("eingebundene View {$view} nutzt \$leise");
            }
        }

        // Umsetzen: Definition entfernen (eigene @php(...)-Zeile, Zeile im @php-Block oder Anweisung), dann Nutzungen ersetzen.
        $q = '([\'"])[^\'"]*\g{-1}';
        $neu = preg_replace('/^[ \t]*@php\(\s*\$leise\s*=\s*'.$q.'\s*\)[ \t]*\R/m', '', $html, 1, $n);
        if ($n === 0) {
            $neu = preg_replace('/^[ \t]*\$leise\s*=\s*'.$q.'\s*;[ \t]*\R/m', '', $html, 1, $n);
        }
        if ($n === 0) {
            $neu = preg_replace('/@php\(\s*\$leise\s*=\s*'.$q.'\s*\)|\$leise\s*=\s*'.$q.'\s*;\s*/', '', $html, 1, $n);
        }
        if ($n === 0 || preg_match('/\$leise\s*=(?!=)/', $neu)) {
            return $nein('Definition nicht entfernbar (unbekannte Form)');
        }
        $html = preg_replace_callback('/(?<![\w:.-])class="([^"]*)"/', fn ($m) => 'class="'.preg_replace('/\{\{\s*\$leise\s*\}\}/', $wert, $m[1]).'"', $neu);
        if (preg_match('/\$leise\b/', $html)) {
            return $nein('nach dem Einsetzen noch eine Nennung übrig');
        }
        // Leer gewordene @php-Blöcke entfernen (samt Zeilenumbruch).
        $html = preg_replace('/^[ \t]*@php\s*@endphp[ \t]*\R?/m', '', $html);
        $html = preg_replace('/@php\s*@endphp/', '', $html);

        $bericht['leise_aufgeloest']++;

        return $html;
    }

    private function rolleIn(string $wert): ?string
    {
        return preg_match('/(?<![\w-])(fa-t-[a-z0-9]+)(?![\w-])/', $wert, $r) ? $r[1] : null;
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} [neuer Wert, Rolle oder null, Kurzform der Typo-Tokens (für den Bericht) oder null]
     */
    private function klassenUmstellen(string $wert): array
    {
        // Statische Tokens mit Position; Tokens, die an Blade-Ausdrücken kleben, sind tabu.
        $blade = [];
        preg_match_all('/\{\{.*?\}\}|\{!!.*?!!\}|@\w+(?:\([^)]*\))?/s', $wert, $bm, PREG_OFFSET_CAPTURE);
        foreach ($bm[0] as [$text, $pos]) {
            $blade[] = [$pos, $pos + strlen($text)];
        }
        preg_match_all('/\S+/', $wert, $tm, PREG_OFFSET_CAPTURE);

        $groesse = $tinte = $gewicht = null;
        $extra = [];
        foreach ($tm[0] as [$token, $pos]) {
            if ($this->beruehrtBlade($pos, $pos + strlen($token), $blade)) {
                continue;
            }
            if (preg_match('/^text-\[length:var\(--fa-text-([a-z0-9]+)\)\]$/', $token, $g)) {
                $groesse = [$g[1], $pos, $token];
            } elseif (preg_match('/^text-\[var\(--fa-(ink(?:-[23])?)\)\]$/', $token, $g)) {
                $tinte = [$g[1], $pos, $token];
            } elseif (in_array($token, self::GEWICHTE, true)) {
                $gewicht = [$token, $pos, $token];
            } elseif (in_array($token, ['tracking-tight', 'leading-tight'], true)) {
                $extra[$token] = [$token, $pos, $token];
            }
        }

        if ($groesse === null && $tinte === null) {
            return [$wert, null, null];
        }
        $kurz = trim(($groesse[0] ?? '–').' '.($tinte[0] ?? '–').' '.($gewicht[0] ?? ''));

        foreach (self::ROLLEN as $rolle => [$g, $t, $w, $pflicht]) {
            if (($groesse[0] ?? null) !== $g || ($tinte[0] ?? null) !== $t || ($gewicht[0] ?? null) !== $w) {
                continue;
            }
            if (array_diff($pflicht, array_keys($extra)) !== []) {
                continue;
            }
            // h1 nimmt tracking/leading mit; bei anderen Rollen bleiben sie als Utility stehen.
            $weg = array_filter([$groesse, $tinte, $gewicht, ...array_map(fn ($p) => $extra[$p], $pflicht)]);
            usort($weg, fn ($a, $b) => $a[1] <=> $b[1]);

            return [$this->ersetzen($wert, $weg, $rolle), $rolle, $kurz];
        }

        return [$wert, null, $kurz];
    }

    /** Ersetzt das erste Token durch die Rolle und entfernt die übrigen samt einem Leerzeichen. */
    private function ersetzen(string $wert, array $tokens, string $rolle): string
    {
        foreach (array_reverse($tokens, true) as $i => [, $pos, $text]) {
            if ($i === 0) {
                $wert = substr_replace($wert, $rolle, $pos, strlen($text));
                continue;
            }
            $ende = $pos + strlen($text);
            $vor = $pos > 0 && $wert[$pos - 1] === ' ' ? 1 : 0;
            $wert = substr_replace($wert, '', $pos - $vor, $ende - $pos + $vor);
        }

        return $wert;
    }

    private function beruehrtBlade(int $von, int $bis, array $blade): bool
    {
        foreach ($blade as [$a, $b]) {
            if ($von < $b && $bis > $a) {
                return true;
            }
        }

        return false;
    }

    private function kollision(string $tag, string $klassen, bool $hatChip): ?string
    {
        if (in_array($tag, self::KOLLISION_TAGS, true)) {
            return "Element {$tag} (.fa-table th / KPI-Kopf dt setzen Schrift in @layer components)";
        }
        foreach (self::KOLLISION_KLASSEN as $k) {
            if (preg_match('/(?<![\w-])'.preg_quote($k, '/').'(?![\w-])/', $klassen)) {
                return "Klasse {$k} setzt Schrift in @layer components";
            }
        }
        if ($tag === 'span' && $hatChip) {
            return 'span in Datei mit .fa-chip (.fa-chip > span setzt Schrift)';
        }

        return null;
    }
}
