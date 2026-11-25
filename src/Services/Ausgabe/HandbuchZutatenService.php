<?php

namespace Platform\FoodAlchemist\Services\Ausgabe;

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\Hochrechnung;

/**
 * Spec 86 — Zutaten und Basisrezepte fürs Handbuch (Küchen-Sicht). Liest aus dem Report-Weg
 * ({@see ReportExportService}, Profil „Küche": Zutaten, Schritte, Basisrezepte — ohne Preise und
 * Lieferanten) und gibt NUR eine Allowlist weiter: Name, Menge, Einheit, Basisrezept-Verweis.
 * EK, Lieferant, Marge, Nährwert-Rohdaten und KI-Lineage bleiben draußen.
 *
 * Mengen am Gericht: je EINE Portion (gewählte Darreichung, sonst Standard); fehlt Portionsgewicht
 * oder Ausbeute, steht die Ansatz-Menge mit Hinweis (`basis = ansatz`) — nichts wird geschätzt.
 * Basisrezepte stehen einmal je Snapshot in `basisrezepte` (Schlüssel `b<id>`) in ihrem eigenen Ansatz.
 */
class HandbuchZutatenService
{
    /** @var array<string,array> */
    private array $basis = [];

    public function __construct(private ReportExportService $report)
    {
    }

    public function zuruecksetzen(): void
    {
        $this->basis = [];
    }

    /** @return array<string,array> */
    public function basisrezepte(): array
    {
        return $this->basis;
    }

    /**
     * @return array{basis:string, ansatz_kg:?float, zeilen:list<array{name:string, menge:?float, einheit:?string, sub:?string}>}
     */
    public function fuerGericht(Team $team, FoodAlchemistRecipe $recipe, ?\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung $dar = null): array
    {
        $leer = ['basis' => 'ansatz', 'ansatz_kg' => null, 'zeilen' => []];
        try {
            $data = $this->report->rezeptDaten($team, (int) $recipe->id, $this->optionen(['ziel_menge' => 1]));
            $knoten = $data['recipe'];
            $portion = (bool) ($data['hochrechnung']['aktiv'] ?? false)
                || ($dar !== null && (float) $dar->quantity_per_unit_g > 0 && Hochrechnung::ansatzKg($recipe) !== null);
            if ($portion) {
                $recipe->load(['ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe']);
            }
            $dar ??= $recipe->presentations()->orderByDesc('is_standard')->orderBy('id')->first();
            $skalierung = $portion && $dar !== null
                ? app(\Platform\FoodAlchemist\Services\DarreichungService::class)->produktionsAnteil($recipe, $dar, 1, $team) : null;

            return [
                'basis' => $portion ? 'portion' : 'ansatz',
                'ansatz_kg' => $knoten['yield_kg'] !== null ? (float) $knoten['yield_kg'] : null,
                'zeilen' => $this->zeilen($team, $recipe, $knoten['ingredients'] ?? [], $skalierung),
            ];
        } catch (\Throwable $e) {
            // Fail-soft: ein Report-Fehler darf das Veröffentlichen nicht kippen — der Abschnitt fehlt dann (Lücke sichtbar).
            Log::warning('Handbuch-Zutaten nicht lesbar', ['recipe_id' => $recipe->id, 'fehler' => $e->getMessage()]);

            return $leer;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $zutaten  Report-Knoten
     * @return list<array{name:string, menge:?float, einheit:?string, sub:?string}>
     */
    private function zeilen(Team $team, FoodAlchemistRecipe $recipe, array $zutaten, ?array $skalierung = null): array
    {
        $refs = $recipe->ingredients->keyBy('id');
        $out = [];
        foreach ($zutaten as $z) {
            $sub = null;
            $zeile = $refs->get((int) ($z['id'] ?? 0));
            $refId = $zeile?->referenced_recipe_id;
            $faktor = $skalierung !== null ? ($skalierung['je_zutat'][$zeile?->id] ?? $skalierung['ansaetze']) : 1.0;
            if ($skalierung !== null && $zeile !== null) {
                $z['quantity'] = Hochrechnung::skaliere($zeile->quantity, $faktor);
            }
            if ($refId !== null) {
                $sub = $this->basisrezept($team, (int) $refId);
            }
            $subFaktor = null;
            if ($sub !== null && $zeile !== null) {
                $ansatzKg = $this->basis[$sub]['ansatz_kg'] ?? null;
                if ($zeile->unit?->dimension === 'count' && ($stueck = $this->basis[$sub]['ansatz_stueck'] ?? null) > 0) {
                    $subFaktor = isset($z['quantity']) && (float) $z['quantity'] === 0.0 ? 0.0
                        : Hochrechnung::faktor((float) ($z['quantity'] ?? 0), $stueck)['faktor'];
                } elseif ($ansatzKg !== null) {
                    $masseG = app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->bruttoMasseG($zeile) * $faktor;
                    $subFaktor = isset($z['quantity']) && (float) $z['quantity'] === 0.0 ? 0.0
                        : ($masseG > 0 ? Hochrechnung::faktor($masseG / 1000, $ansatzKg)['faktor'] : null);
                }
            }
            $out[] = [
                'name' => (string) ($z['name'] ?? ''),
                'menge' => isset($z['quantity']) ? (float) $z['quantity'] : null,
                'einheit' => Hochrechnung::einheitKurz($z['unit'] ?? null),   // „g" statt „Gramm" — Anzeige-Regel Hochrechnung::menge
                'sub' => $sub,
                'sub_faktor' => $subFaktor,
            ];
        }

        return $out;
    }

    private function basisrezept(Team $team, int $id): ?string
    {
        $key = 'b' . $id;
        if (isset($this->basis[$key])) {
            return $key;
        }
        $this->basis[$key] = ['name' => '', 'ansatz_kg' => null, 'zeilen' => [], 'schritte' => []];   // Zyklus-Schutz
        try {
            $recipe = FoodAlchemistRecipe::visibleToTeam($team)->with(['ingredients' => fn ($q) => $q->whereNull('deleted_at'), 'steps.photos', 'platingSteps.photos'])->find($id);
            if ($recipe === null) {
                unset($this->basis[$key]);

                return null;
            }
            $knoten = $this->report->rezeptDaten($team, $id, $this->optionen([]))['recipe'];
            $this->basis[$key] = [
                'name' => (string) $recipe->name,
                'kennzeichnung' => HandbuchService::kennzeichnungSnapshot($recipe, $team),
                'ansatz_kg' => Hochrechnung::ansatzKg($recipe),
                'ansatz_stueck' => $recipe->yield_pieces !== null ? (float) $recipe->yield_pieces : null,
                'zeilen' => $this->zeilen($team, $recipe, $knoten['ingredients'] ?? []),
                'schritte' => array_map(fn ($s) => (string) $s['text'], $knoten['steps'] ?? []), // bisherige Snapshots bleiben lesbar
                'zubereitung' => HandbuchService::schritteSnapshot($recipe->steps),
                'anrichten' => HandbuchService::schritteSnapshot($recipe->platingSteps),
                'foto' => HandbuchService::rezeptFotoSnapshot($recipe),
                'bilder' => HandbuchService::rezeptBilderSnapshot($recipe),
            ];
        } catch (\Throwable $e) {
            Log::warning('Handbuch-Basisrezept nicht lesbar', ['recipe_id' => $id, 'fehler' => $e->getMessage()]);
            unset($this->basis[$key]);

            return null;
        }

        return $key;
    }

    /** Küchen-Profil: Zutaten + Schritte, Preise/Lieferanten/Kaskade-Tiefe/Sensorik aus (Basisrezepte holt der Dienst selbst). */
    private function optionen(array $zusatz): array
    {
        return $this->report->optionen([
            'profil' => 'produktion', 'preise' => '0', 'lieferanten' => '0', 'kaskade' => '0', 'steps' => '1',
            'regeneration' => '0', 'behaelter' => '0', 'sensorik' => '0', 'bilder' => '0', 'naehrwerte' => '0', 'notizen' => '0',
        ] + $zusatz, 'recipe');
    }
}
