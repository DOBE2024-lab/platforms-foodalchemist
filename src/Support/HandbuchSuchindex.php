<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Str;

/**
 * Suchindex des öffentlichen Handbuchs (Spec 86). Wird beim Rendern aus dem eingefrorenen Snapshot
 * gebaut und als data-suche an jede Karte gehängt; die Suche selbst läuft im Browser.
 *
 * Allowlist: nur Namen und Texte, die das Handbuch ohnehin zeigt (Gericht, Zutaten- und
 * Bestandteilnamen, Basisrezepte, Schritte, Regeneration, Behälter, Allergene, freie Kapitel).
 * Keine Mengen, keine Preise, keine sonstigen Snapshot-Felder.
 *
 * normalisiere() muss zu norm() im Handbuch-Script passen: klein, ß→ss, Akzente/Umlaute ohne
 * Zeichen (ä→a), alles außer Buchstaben/Ziffern wird Leerzeichen.
 */
final class HandbuchSuchindex
{
    public static function normalisiere(?string $text): string
    {
        $t = mb_strtolower((string) $text, 'UTF-8');
        $t = str_replace('ß', 'ss', $t);
        $nfd = class_exists(\Normalizer::class) ? \Normalizer::normalize($t, \Normalizer::FORM_D) : false;
        $t = $nfd !== false ? preg_replace('/\p{Mn}+/u', '', $nfd) : Str::ascii($t);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $t);

        return trim((string) $t);
    }

    /**
     * @param  array<string,mixed>  $g  Gerichtskarte aus dem Snapshot
     * @param  array<string,array<string,mixed>>  $basis  Basisrezepte des Snapshots
     * @param  array<string,string>  $codeLabel  Code → Bezeichnung (Legende)
     */
    public static function fuerGericht(array $g, array $basis = [], array $codeLabel = []): string
    {
        $teile = [$g['name'] ?? null];
        foreach (['form', 'geschirr', 'vehikel'] as $f) {
            $teile[] = $g['portion'][$f] ?? null;
        }
        $gesehen = [];
        foreach ($g['zutaten']['zeilen'] ?? [] as $zi) {
            $teile[] = $zi['name'] ?? null;
            if (! empty($zi['sub'])) {
                $teile = [...$teile, ...self::basisTeile((string) $zi['sub'], $basis, $gesehen, 1)];
            }
        }
        foreach (['zubereitung', 'anrichten'] as $abschnitt) {
            foreach ($g[$abschnitt] ?? [] as $s) {
                $teile[] = $s['text'] ?? null;
            }
        }
        foreach ($g['regeneration'] ?? [] as $r) {
            $teile[] = $r['komponente'] ?? null;
            $teile[] = $r['geraet'] ?? null;
            $teile[] = $r['hinweis'] ?? null;
            $teile[] = ! empty($r['kalt']) ? 'Kalt servieren' : null;
        }
        foreach ($g['behaelter'] ?? [] as $bh) {
            $teile[] = $bh['zweck'] ?? null;
            $teile[] = $bh['name'] ?? null;
        }
        foreach ($g['gefaess'] ?? [] as $zeile) {   // 20.11.2026: Gefäß + Zubehör statt Bestandteile
            $teile[] = is_string($zeile) ? preg_replace('/^[\d.,]+\s*×\s*/u', '', $zeile) : null;
        }
        foreach ($g['codes'] ?? [] as $c) {
            $code = rtrim((string) $c, '*');
            $teile[] = $code;
            $teile[] = $codeLabel[$code] ?? null;
        }

        return self::verbinde($teile);
    }

    /** @param  array<string,array<string,mixed>>  $basis */
    public static function fuerBasis(string $key, array $basis): string
    {
        $gesehen = [];

        return self::verbinde(self::basisTeile($key, $basis, $gesehen, 1));
    }

    /** @param  array<string,mixed>  $kapitel  freies Kapitel ({title, text}) */
    public static function fuerKapitel(array $kapitel): string
    {
        return self::verbinde([$kapitel['title'] ?? null, $kapitel['text'] ?? null]);
    }

    /**
     * Name, Zutaten, Schritte und Allergene eines Basisrezepts, Unterrezepte über alle Ebenen (A-5; 2.1c: vorher bis Tiefe 3), `$gesehen` bricht Zyklen.
     *
     * @param  array<string,array<string,mixed>>  $basis
     * @param  array<string,bool>  $gesehen
     * @return list<?string>
     */
    private static function basisTeile(string $key, array $basis, array &$gesehen, int $tiefe): array
    {
        $b = $basis[$key] ?? null;
        if (! is_array($b) || isset($gesehen[$key])) {
            return [];
        }
        $gesehen[$key] = true;
        $teile = [$b['name'] ?? null];
        foreach ($b['zeilen'] ?? [] as $zi) {
            $teile[] = $zi['name'] ?? null;
            if (! empty($zi['sub'])) {
                $teile = [...$teile, ...self::basisTeile((string) $zi['sub'], $basis, $gesehen, $tiefe + 1)];
            }
        }
        $zubereitung = $b['zubereitung'] ?? array_map(fn ($text) => ['text' => $text], $b['schritte'] ?? []);
        foreach ([...$zubereitung, ...($b['anrichten'] ?? [])] as $s) {
            $teile[] = is_array($s) ? ($s['text'] ?? null) : (string) $s;
        }
        foreach (['enthalten', 'spuren'] as $gruppe) {
            foreach ($b['kennzeichnung'][$gruppe] ?? [] as $a) {
                $teile[] = $a['code'] ?? null;
                $teile[] = $a['label'] ?? null;
            }
        }

        return $teile;
    }

    /** @param  list<mixed>  $teile */
    private static function verbinde(array $teile): string
    {
        $woerter = [];
        foreach ($teile as $t) {
            if (! is_string($t) || trim($t) === '') {
                continue;
            }
            $n = self::normalisiere($t);
            if ($n !== '') {
                $woerter[] = $n;
            }
        }

        return implode(' ', array_values(array_unique($woerter)));
    }
}
