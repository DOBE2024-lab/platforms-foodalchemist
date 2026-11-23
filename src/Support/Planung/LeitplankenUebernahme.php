<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Platform\FoodAlchemist\Livewire\Planung\Index;

/**
 * Spec 85 C1 PR-4 (C-c) · den Leitplanken-Vorschlag aus dem Briefing ({@see \Platform\FoodAlchemist\Services\BriefingLeitplankenService::ausBriefing})
 * auf den Formularstand eines Scopes anwenden, aus `Livewire\Planung\Brief::leitplankenAusBriefing` herausgelöst (wortgleich
 * verschoben, Muster {@see ReglerParams}). Zustandsfrei: bekommt eingabe/regler, liefert den neuen Stand und den Befund
 * (gesetzt / verworfen / ignoriert / unklar) zurück. Prüfungen, Service-Aufruf und Meldungen bleiben in der Komponente.
 */
final class LeitplankenUebernahme
{
    /**
     * @param  array<string, mixed>  $r  Ergebnis von BriefingLeitplankenService::ausBriefing
     * @param  array<string, array<string, mixed>>  $eingabe
     * @param  array<string, array<string, mixed>>  $regler
     * @return array{eingabe: array, regler: array, befund: array{scope: string, gesetzt: list<string>, verworfen: mixed, ignoriert: list<string>, unklar: mixed, begruendung: mixed, confidence: mixed}}
     */
    public static function anwenden(string $scope, array $r, array $eingabe, array $regler): array
    {
        $gesetzt = [];
        // Scope-fremde Felder (Basisrezept: pax/Portion/Anlass …) hat der Service schon entfernt —
        // hier nur melden, damit nichts still verschwindet.
        $ignoriert = array_values((array) ($r['scope_fremd'] ?? []));
        foreach ($r['leitplanken'] as $feld => $wert) {
            // Bestand hat außerhalb des Basisrezept-Tabs keinen eigenen Regler — dort steuert der
            // Kreativ-Modus die Reuse-Achse (reglerParams). »Nur Bestand« aus dem Briefing landet
            // deshalb im Modus, statt im verdeckten Regler-Wert unterzugehen.
            if ($feld === 'bestand' && $scope !== 'rezept') {
                if ($wert === 'nur_bestand') {
                    $eingabe[$scope]['creative_mode'] = 'datenbank';
                    $gesetzt[] = 'bestand';
                } else {
                    $ignoriert[] = 'bestand';
                }

                continue;
            }
            if (! array_key_exists($feld, Index::REGLER_DEFAULT)) {
                // Im Sitzungs-Param-Raum gültig, aber dieser Tab hat den Regler nicht
                // (z. B. Menü-Quoten). Melden statt verschlucken.
                $ignoriert[] = (string) $feld;

                continue;
            }
            // Formtreue: Multi-Regler bleibt Array, Single-Regler bleibt Skalar — ein String
            // in `diaet_hart` würde die Pill-Logik (reglerPill) zerlegen.
            $regler[$scope][$feld] = is_array(Index::REGLER_DEFAULT[$feld])
                ? array_values(array_filter((array) $wert, static fn ($v): bool => is_scalar($v)))
                : (is_array($wert) ? (string) (reset($wert) ?: '') : $wert);
            $gesetzt[] = (string) $feld;
        }

        // Spec 80 A3: KI-Suchbegriffe ersetzen die bisherigen KI-Chips; was der Mensch ergänzt hat, bleibt.
        $eingabe[$scope]['suchbegriffe'] = self::suchbegriffeZusammenfuehren(
            (array) ($eingabe[$scope]['suchbegriffe'] ?? []),
            (array) ($r['suchbegriffe'] ?? []),
        );

        $befund = [
            'scope' => $scope,
            'gesetzt' => $gesetzt,
            'verworfen' => $r['verworfen'],
            'ignoriert' => $ignoriert,
            'unklar' => $r['unklar'],
            'begruendung' => $r['begruendung'],
            'confidence' => $r['confidence'],
        ];

        return ['eingabe' => $eingabe, 'regler' => $regler, 'befund' => $befund];
    }

    /**
     * Spec 80 A3: neue KI-Begriffe + die vom Menschen gesetzten. KI-Chips des Vorlaufs fallen weg,
     * Mensch-Chips bleiben (auch wenn die KI denselben Begriff liefert — dann zählt er als Mensch).
     *
     * @param  list<array{t: string, g: string, q: string}>  $bisher
     * @param  list<array{t: string, g: string, q: string}>  $neuKi
     * @return list<array{t: string, g: string, q: string}>
     */
    public static function suchbegriffeZusammenfuehren(array $bisher, array $neuKi): array
    {
        $mensch = array_values(array_filter($bisher, static fn ($b) => is_array($b) && ($b['q'] ?? '') === 'mensch'));
        $belegt = array_flip(array_map(static fn ($b) => mb_strtolower((string) $b['t']), $mensch));
        foreach ($neuKi as $b) {
            if (is_array($b) && isset($b['t']) && ! isset($belegt[mb_strtolower((string) $b['t'])])) {
                $mensch[] = ['t' => (string) $b['t'], 'g' => (string) ($b['g'] ?? 'eigene'), 'q' => 'ki'];
                $belegt[mb_strtolower((string) $b['t'])] = true;
            }
        }

        return $mensch;
    }
}
