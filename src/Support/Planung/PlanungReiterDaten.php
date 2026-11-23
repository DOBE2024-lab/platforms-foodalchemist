<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Services\Planung\PlanningSessionService;
use Platform\FoodAlchemist\Services\Trend\TrendService;

/**
 * Code-Qualität 2.1d · Daten der Planungs-Reiter, die nur der jeweils **aktive** Reiter braucht (Composer, Trendradar,
 * Auswahllisten der Ausgabe-Reiter). Vorher baute `Planung/Index::render()` sie bei jedem Render für alle Reiter —
 * auch für unsichtbare. Leere Struktur = Reiter nicht aktiv; die Views lesen dieselben Schlüssel wie bisher.
 */
final class PlanungReiterDaten
{
    /** Ausgabe-Reiter → Auswahlliste (View-Variable). */
    public const AUSWAHL = ['foodbook' => 'fbAuswahl', 'speisekarte' => 'skAuswahl', 'speiseplan' => 'spAuswahl', 'angebot' => 'offerAuswahl', 'format' => 'fmtAuswahl'];

    /**
     * Composer-Reiter: Ad-hoc-Netz, „Passt das zusammen?" (Kombinationslogik, Spec 60) und der browsebare Picker.
     *
     * @param  list<int>  $ankerIds
     * @return array{composerNetz: array, composerKombination: ?array, composerBrowse: array}
     */
    public function composer(?Team $team, bool $aktiv, array $ankerIds, string $term, string $kategorie, ?int $fokus, string $modus): array
    {
        $out = ['composerNetz' => ['nodes' => [], 'edges' => [], 'meta' => []], 'composerKombination' => null,
            'composerBrowse' => ['items' => [], 'total' => 0, 'kategorien' => []]];
        if ($team === null || ! $aktiv) {
            return $out;
        }
        $pairing = app(PairingService::class);
        if ($ankerIds !== []) {
            // Netz: ★★★-Partner, Kontrast-Lieferanten für offene Bedarfe, Konflikt-Linien.
            $out['composerNetz'] = $pairing->pairingNetzForAnkers($team, $ankerIds);
        }
        if (count($ankerIds) >= 2) {
            // Spec 60: dieselbe Aussage wie im Gericht-Panel und im MCP (composer.KOHAESION).
            $out['composerKombination'] = app(Kombinationslogik::class)->datenAusAnkern($ankerIds, null, null, (int) $team->id, $modus);
        }
        // Fokus (falls gesetzt) → Picker-Badge/Sortierung relativ zum fokussierten Anker.
        $out['composerBrowse'] = $pairing->composerAnkerBrowse($team, $term, $kategorie !== '' ? $kategorie : null, $ankerIds, 200, $fokus);

        return $out;
    }

    /**
     * Trendradar-Reiter (Spec 79): Kandidaten, Fundstücke und die Vorschau der gewählten Kombination.
     *
     * @return array{trendKandidaten: Collection, fundKandidaten: Collection, trendVorschau: ?string, trendMeldung: ?string}
     */
    public function trend(?Team $team, bool $aktiv, string $suche, array $wahl): array
    {
        $out = ['trendKandidaten' => collect(), 'fundKandidaten' => collect(), 'trendVorschau' => null, 'trendMeldung' => null];
        if ($team === null || ! $aktiv) {
            return $out;
        }
        $tsvc = app(TrendService::class);
        $out['trendKandidaten'] = $tsvc->liste($team, ['suche' => $suche, 'status' => ['auf_radar', 'in_umsetzung', 'geprueft', 'gesichtet']])
            ->sortBy(fn ($t) => [in_array($t->status, ['auf_radar', 'in_umsetzung'], true) ? 0 : 1, $t->name])->values();
        $out['fundKandidaten'] = $tsvc->inspirationen($team, 'alle', $suche)->take(60)
            ->map(fn ($i) => ['i' => $i, 'url' => ($bild = $tsvc->titelbild($i)) ? $tsvc->dateiUrl($bild) : null]);
        if (count($wahl['trends'] ?? []) + count($wahl['inspirationen'] ?? []) > 0) {
            try {
                $out['trendVorschau'] = app(PlanningSessionService::class)->trendKombination($team,
                    array_map('intval', $wahl['trends'] ?? []), array_map('intval', $wahl['inspirationen'] ?? []))['brief'];
            } catch (\RuntimeException $e) {
                $out['trendMeldung'] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * Auswahllisten bestehender Ausgabe-Objekte (Stage 2) — nur die des aktiven Ausgabe-Reiters.
     *
     * @return array<string, Collection>
     */
    public function auswahl(?Team $team, ?string $reiter): array
    {
        $out = array_fill_keys(array_values(self::AUSWAHL), collect());
        if ($team === null || ! isset(self::AUSWAHL[$reiter])) {
            return $out;
        }
        $out[self::AUSWAHL[$reiter]] = match ($reiter) {
            'foodbook' => FoodAlchemistFoodbook::visibleToTeam($team)->orderByDesc('id')->get(['id', 'label']),
            'speisekarte' => FoodAlchemistSpeisekarte::visibleToTeam($team)->orderByDesc('id')->get(['id', 'name']),
            'speiseplan' => FoodAlchemistSpeiseplan::visibleToTeam($team)->orderByDesc('id')->get(['id', 'name']),
            'angebot' => FoodAlchemistAngebot::visibleToTeam($team)->orderByDesc('id')->get(['id', 'name']),
            'format' => FoodAlchemistFormat::visibleToTeam($team)->orderByDesc('id')->get(['id', 'name']),
        };

        return $out;
    }
}
