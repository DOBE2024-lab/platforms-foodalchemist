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

    /**
     * @return array{html: string, umgestellt: array<string,int>, pruefen: list<string>, ohne_rolle: array<string,int>, leise: array<string,int>}
     */
    public function umwandeln(string $html, string $pfad): array
    {
        $bericht = ['umgestellt' => [], 'pruefen' => [], 'ohne_rolle' => [], 'leise' => []];
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
                $bericht['leise'][$rolle ?? ('ohne Rolle: '.$m[3])] = ($bericht['leise'][$rolle ?? ('ohne Rolle: '.$m[3])] ?? 0) + 1;

                return $rolle === null ? $m[0] : $m[1].$m[2].$neu.$m[2];
            },
            $html
        );

        return ['html' => $html] + $bericht;
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
