<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Pakete im Sprungmenü (2026-11-20, Design-Haken `tokens_json.nav_pakete`): jede Paket-Zeile eines Kapitels
 * (z. B. „ITALIEN … 7,00 €“ in einem Format-Kapitel) bekommt einen festen Anker, das Sprungmenü führt sie als
 * Unterpunkte des Kapitels. Renderer (show) und Navigation (Layout) rufen dieselbe Funktion, die Anker passen
 * also immer zusammen. Kapitel-Anker wie in chapter_loop: `anker` aus dem Snapshot, sonst „pt-sec-<Index>“.
 */
final class PraesentationsPaketAnker
{
    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @return array<int,array<string,mixed>>
     */
    public static function ankern(array $sections): array
    {
        foreach ($sections as $si => $sec) {
            $secId = ! empty($sec['anker']) ? (string) $sec['anker'] : 'pt-sec-' . $si;
            $n = 0;
            foreach ($sec['blocks'] ?? [] as $bi => $blk) {
                foreach ($blk['items'] ?? [] as $ii => $it) {
                    if (($it['kind'] ?? null) === 'paket' && trim((string) ($it['label'] ?? '')) !== '') {
                        $sections[$si]['blocks'][$bi]['items'][$ii]['anker'] = $secId . '-p' . (++$n);
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * Unterpunkte eines Kapitels fürs Sprungmenü: je Paket {id, title}.
     *
     * @param  array<string,mixed>  $section  bereits durch {@see ankern} gelaufen
     * @return list<array{id:string, title:string}>
     */
    public static function unterpunkte(array $section): array
    {
        $punkte = [];
        foreach ($section['blocks'] ?? [] as $blk) {
            foreach ($blk['items'] ?? [] as $it) {
                if (! empty($it['anker'])) {
                    $punkte[] = ['id' => (string) $it['anker'], 'title' => trim((string) $it['label'])];
                }
            }
        }

        return $punkte;
    }
}
