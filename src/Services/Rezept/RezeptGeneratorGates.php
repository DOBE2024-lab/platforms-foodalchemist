<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;

/**
 * 98 · 3.3 (KI-Strang b): die drei Nach-Prüfungen des Rezept-Generators nach dem Verdrahten — Kohärenz-Gate (Regel süß/herzhaft
 * + Kritiker), Diät-/Allergen-Gate (L3) und Dedup-Guard (Spec 41 P3). Jede nimmt das Ergebnis `{recipe, statistik, offene}`
 * und gibt es ergänzt zurück, fail-open. Aus {@see RecipeGeneratorService} geschnitten; Rümpfe unverändert, die Fassade
 * behält die drei Methoden als private Delegatoren (DedupGuardTest ruft dedupGate per Reflection). Ruft die Fassade nie.
 */
final class RezeptGeneratorGates
{
    public function __construct(
        private \Platform\FoodAlchemist\Services\GpStamm\IngredientMatchService $matcher,
        private MatchHeuristics $heuristik,
    ) {
    }

    public function kohaerenzGate(Team $team, array $result, callable $melde): array
    {
        /** @var FoodAlchemistRecipe $recipe */
        $recipe = $result['recipe'];
        $statistik = $result['statistik'];
        $offene = $result['offene'];
        $statistik['kritiker'] = ['geprueft' => false, 'uebersprungen_gating' => false, 'entdrahtet' => 0, 'befunde_abgelegt' => 0];

        try {
            $zeilen = $recipe->ingredients()
                ->with(['referencedRecipe:id,name,taste_direction', 'gp:id,name'])->get()->keyBy('id');
            $wiredSubs = $zeilen->filter(fn ($z) => $z->referenced_recipe_id !== null);

            // Treffer sammeln: ingredient_id => ['grund','konfidenz','quelle']. Regel gewinnt (billiger, hart).
            $treffer = [];

            // Stufe a — Regel: Geschmacks-Konflikt Gericht × verdrahtetes Sub (null/neutral = kein Urteil).
            $parentTaste = $recipe->taste_direction;
            if (in_array($parentTaste, ['suess', 'herzhaft'], true)) {
                foreach ($wiredSubs as $z) {
                    $subTaste = $z->referencedRecipe?->taste_direction;
                    if (in_array($subTaste, ['suess', 'herzhaft'], true) && $subTaste !== $parentTaste) {
                        $treffer[(int) $z->id] = [
                            'grund' => "Geschmacks-Konflikt: Gericht {$parentTaste}, Komponente «{$z->referencedRecipe->name}» {$subTaste}.",
                            'konfidenz' => 1.0,
                            'quelle' => 'regel',
                        ];
                    }
                }
            }

            // Stufe b — Kritiker (GEGATET auf verdrahtete Sub-Rezepte). Übrige Befunde in die Ablage.
            // Eigenes try/catch: scheitert der KI-Call, bleibt die deterministische Regel (Stufe a)
            // trotzdem wirksam — sie ist die billige, ausfallsichere Vorstufe, kein Anhängsel.
            if ($wiredSubs->isNotEmpty()) {
                try {
                    $melde('Kohärenz-Gate: Fremdkörper werden geprüft …');
                    $review = app(RecipeReviewService::class)->pruefe($team, $recipe->id);
                    $rest = [];
                    foreach ($review['befunde'] as $b) {
                        $istFremd = ($b['art'] ?? '') === 'fremdkoerper' && ($b['zutat_id'] ?? null) !== null;
                        if ($istFremd && (float) ($b['konfidenz'] ?? 0) >= \Platform\FoodAlchemist\Services\Pruefung\RecipeFindingService::KONFIDENZ_SCHWELLE) {
                            $id = (int) $b['zutat_id'];
                            if (! isset($treffer[$id])) {                // Regel gewinnt bei Doppel-Flag
                                $treffer[$id] = [
                                    'grund' => (string) ($b['begruendung'] ?? 'Passt fachlich nicht ins Gericht.'),
                                    'konfidenz' => (float) $b['konfidenz'],
                                    'quelle' => 'ki',
                                ];
                            }
                        } else {
                            $rest[] = $b;                                // menge/einheit/fehlt/hinweis + Fremdkörper < Schwelle
                        }
                    }
                    if ($rest !== []) {                                  // Copilot/Signale sehen die übrigen Befunde
                        $abgelegt = app(\Platform\FoodAlchemist\Services\Pruefung\RecipeFindingService::class)->speichere($team, $recipe->id, $rest);
                        $statistik['kritiker']['befunde_abgelegt'] = (int) ($abgelegt['neu'] ?? 0);
                    }
                    $statistik['kritiker']['geprueft'] = true;
                } catch (\Throwable $e) {
                    $statistik['kritiker']['fehler'] = true;             // Regel bleibt, Generierung läuft weiter
                }
            } else {
                $statistik['kritiker']['uebersprungen_gating'] = true;
            }

            // Entdrahten + offene[]-Eintrag je Treffer (Regel + Kritiker).
            foreach ($treffer as $id => $info) {
                $z = $zeilen->get($id);
                if ($z === null || ($z->gp_id === null && $z->referenced_recipe_id === null)) {
                    continue;                                            // nicht mehr da / schon offen
                }
                $wasSub = $z->referenced_recipe_id !== null;
                // Ziel-id VOR dem Entdrahten sichern — „Trotzdem verwenden" bindet genau dieses
                // Objekt wieder (hardstopVerknuepfen als Override).
                $zielRefId = (int) ($wasSub ? $z->referenced_recipe_id : $z->gp_id);
                $zielName = (string) (($wasSub ? $z->referencedRecipe?->name : $z->gp?->name) ?? $z->display_name ?? $z->raw_text);
                $text = (string) (($z->raw_text ?? '') !== '' ? $z->raw_text : ($z->display_name ?: $zielName));
                $position = (int) $z->position;

                app(\Platform\FoodAlchemist\Services\Pruefung\HardstopResolveService::class)->entdrahte($team, $recipe->id, $id);
                $statistik['kritiker']['entdrahtet']++;
                // Zähler ehrlich halten: die Zeile ist nicht mehr verdrahtet, sondern offen.
                if ($wasSub) {
                    $statistik['bestand_sub'] = max(0, (int) ($statistik['bestand_sub'] ?? 0) - 1);
                } else {
                    $statistik['bestand_gp'] = max(0, (int) ($statistik['bestand_gp'] ?? 0) - 1);
                }
                $statistik['offen'] = (int) ($statistik['offen'] ?? 0) + 1;

                $istSub = $wasSub || $this->heuristik->istSubRezeptKandidat($text);
                $offene[] = [
                    'index' => $position - 1,                            // Kontrakt afterGenerated: position === index + 1
                    'text' => $text,
                    'primaer' => $istSub ? 'basisrezept_anlegen' : 'lieferantenartikel_waehlen',
                    'shortlist' => $this->matcher->candidatesFor($team, $text, null, 5),
                    'la_kandidaten' => [],
                    'lieferantenstrategie' => null,
                    'schwacher_treffer' => null,
                    // Kohärenz-Gate: WARUM die Zeile ENTdrahtet wurde (Review-Fläche Phase 3).
                    'kritiker' => [
                        'name' => $zielName,
                        'target' => $wasSub ? 'sub_recipe' : 'gp',
                        'ziel_id' => $zielRefId,                        // „Trotzdem verwenden" bindet dieses Objekt wieder
                        'grund' => $info['grund'],
                        'konfidenz' => round((float) $info['konfidenz'], 3),
                        'quelle' => $info['quelle'],
                    ],
                ];
            }

            if ($statistik['kritiker']['entdrahtet'] > 0) {
                $result['recipe'] = $recipe->refresh();
            }
        } catch (\Throwable $e) {
            // Fail-open: das Gate ist Qualität/Diagnose, kein Blocker. Der Lauf kommt normal durch.
            $statistik['kritiker'] = ['geprueft' => false, 'uebersprungen_gating' => false,
                'entdrahtet' => 0, 'befunde_abgelegt' => 0, 'fehler' => true];
        }

        $result['statistik'] = $statistik;
        $result['offene'] = $offene;

        return $result;
    }

    /**
     * Diät-/Allergen-Gate (L3) — deterministischer Post-Check nach dem Verdrahten, für BEIDE Modi.
     * Ein verdrahteter GP, der eine harte Diät-Vorgabe (`diaet_hart`) oder ein No-Go-Allergen
     * (`allergen_nogo`) EXPLIZIT verletzt, wird ENTdrahtet (Zeile bleibt offener Hard-Stop, primär
     * »Lieferantenartikel wählen« = konforme Alternative) und als Befund gemeldet. Doktrin:
     * NULL/unbewertet blockt NIE (unbekannt ≠ Verstoß), `low_carb` ist per GP-Flag nicht prüfbar →
     * bewusst übersprungen. Prüft die GP-Diät-Tags (per-GP-Wahrheit) + Allergen-Override »enthalten«.
     * Fail-open: jeder Fehler lässt den Lauf unangetastet (Diagnose, kein Blocker).
     *
     * @param  array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}  $result
     * @param  array<string,mixed>  $parameter
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}
     */
    public function diaetGate(Team $team, array $result, array $parameter): array
    {
        /** @var FoodAlchemistRecipe $recipe */
        $recipe = $result['recipe'];
        $statistik = $result['statistik'];
        $offene = $result['offene'] ?? [];
        $statistik['diaet'] = ['geprueft' => false, 'uebersprungen' => false, 'entdrahtet' => 0, 'befunde' => []];

        $diaetHart = array_values(array_filter((array) ($parameter['diaet_hart'] ?? []), 'is_string'));
        $allergenNogo = array_values(array_filter((array) ($parameter['allergen_nogo'] ?? []), 'is_string'));
        if ($diaetHart === [] && $allergenNogo === []) {
            $statistik['diaet']['uebersprungen'] = true;
            $result['statistik'] = $statistik;

            return $result;
        }

        try {
            $zeilen = $recipe->ingredients()->whereNull('deleted_at')->whereNotNull('gp_id')->with('gp')->orderBy('position')->get();
            foreach ($zeilen as $z) {
                $gp = $z->gp;
                if ($gp === null) {
                    continue;
                }
                $gruende = $this->diaetVerstoesse($gp, $diaetHart, $allergenNogo);
                if ($gruende === []) {
                    continue;
                }
                $text = (string) (($z->raw_text ?? '') !== '' ? $z->raw_text : ($z->display_name ?: $gp->name));
                $position = (int) $z->position;
                $zielGpId = (int) $z->gp_id;

                app(\Platform\FoodAlchemist\Services\Pruefung\HardstopResolveService::class)->entdrahte($team, (int) $recipe->id, (int) $z->id);
                $statistik['diaet']['entdrahtet']++;
                $statistik['bestand_gp'] = max(0, (int) ($statistik['bestand_gp'] ?? 0) - 1);
                $statistik['offen'] = (int) ($statistik['offen'] ?? 0) + 1;
                $statistik['diaet']['befunde'][] = $text . ': ' . implode(', ', $gruende);

                $offene[] = [
                    'index' => $position - 1,   // Kontrakt afterGenerated: position === index + 1
                    'text' => $text,
                    // Konforme Alternative suchen (kein Auto-Sub — ein Diät-Verstoß ist keine Zerlegungs-Frage).
                    'primaer' => 'lieferantenartikel_waehlen',
                    'shortlist' => $this->matcher->candidatesFor($team, $text, null, 5),
                    'la_kandidaten' => [],
                    'lieferantenstrategie' => null,
                    'schwacher_treffer' => null,
                    // Diät-/Allergen-Gate: WARUM die Zeile entdrahtet wurde (Review-Fläche + kein Auto-Plan).
                    'diaet_verstoss' => [
                        'ziel_id' => $zielGpId,          // „Trotzdem verwenden" bindet den GP wieder (Override)
                        'gruende' => $gruende,
                    ],
                ];
            }
            $statistik['diaet']['geprueft'] = true;
            if ($statistik['diaet']['entdrahtet'] > 0) {
                $result['recipe'] = $recipe->refresh();
            }
        } catch (\Throwable $e) {
            $statistik['diaet'] = ['geprueft' => false, 'uebersprungen' => false, 'entdrahtet' => 0, 'befunde' => [], 'fehler' => true];
        }

        $result['statistik'] = $statistik;
        $result['offene'] = $offene;

        return $result;
    }

    /**
     * Dedup-Guard (Spec 41 P3 / FIX-4, Entscheid DF-1) — modus-**orthogonaler** Immer-an-Check gegen
     * stilles Duplizieren (Qualitäts-Log E2/E3: »Voll kreativ« mintete Kürbispüree/Rinderjus frisch,
     * obwohl im Bestand). Read-only Post-Check NACH der Anlage: findet ein bestehendes Rezept GLEICHEN
     * TYPS (is_sales_recipe) mit identischem Namens-Token-Set (wie {@see RecipeService::findByTokenSet},
     * self ausgeschlossen) und FLAGGT die Kollision über den etablierten Kanal (statistik['dedup'] +
     * offene[]) — nie stilles `_2`. Der Kreativ-Modus (`bestand`) steuert NUR die empfohlene Aktion:
     * nur_bestand/hybrid → Bestand übernehmen (`primaer=bestand_uebernehmen`), komplett_neu → als
     * Variante behalten (`primaer=dedup_kollision`). Fail-open (Diagnose, kein Blocker).
     *
     * @param  array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}  $result
     * @param  array<string,mixed>  $parameter
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}
     */
    public function dedupGate(Team $team, array $result, array $parameter): array
    {
        /** @var FoodAlchemistRecipe $recipe */
        $recipe = $result['recipe'];
        $statistik = $result['statistik'];
        $offene = $result['offene'] ?? [];
        $bestand = (string) ($parameter['bestand'] ?? 'hybrid');
        $statistik['dedup'] = ['geprueft' => false, 'kollision' => false];

        try {
            $engine = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class);
            $ziel = $engine->tokenize((string) $recipe->name);
            sort($ziel);
            if ($ziel === []) {
                $result['statistik'] = $statistik;

                return $result;
            }

            $existing = null;
            foreach (\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)
                ->where('is_sales_recipe', (bool) $recipe->is_sales_recipe)
                ->where('id', '!=', (int) $recipe->id)
                ->whereNull('deleted_at')
                ->orderBy('id')->cursor() as $r) {
                $tokens = $engine->tokenize((string) $r->name);
                sort($tokens);
                if ($tokens === $ziel) {
                    $existing = $r;

                    break;   // ältestes gleichnamiges Rezept = der Bestands-Kandidat
                }
            }

            $statistik['dedup']['geprueft'] = true;
            if ($existing !== null) {
                // nur_bestand/hybrid = Bestand bevorzugen; komplett_neu = bewusste Variante.
                $reuseModus = in_array($bestand, ['nur_bestand', 'hybrid'], true);
                $statistik['dedup']['kollision'] = true;
                $statistik['dedup']['existing_id'] = (int) $existing->id;
                $statistik['dedup']['existing_name'] = (string) $existing->name;
                $statistik['dedup']['modus'] = $bestand;

                $offene[] = [
                    'index' => -1,   // rezept-/gericht-weit, nicht an eine Zutaten-Zeile gebunden
                    'text' => (string) $recipe->name,
                    'primaer' => $reuseModus ? 'bestand_uebernehmen' : 'dedup_kollision',
                    'shortlist' => [],
                    'la_kandidaten' => [],
                    'lieferantenstrategie' => null,
                    'schwacher_treffer' => null,
                    'dedup_kollision' => [
                        'existing_id' => (int) $existing->id,
                        'existing_name' => (string) $existing->name,
                        'modus' => $bestand,
                        'hinweis' => 'existiert bereits als «' . $existing->name . '» (#' . $existing->id . ') — '
                            . ($reuseModus ? 'Bestand übernehmen oder als Variante behalten?' : 'als Variante behalten oder übernehmen?'),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            $statistik['dedup'] = ['geprueft' => false, 'kollision' => false, 'fehler' => true];
        }

        $result['statistik'] = $statistik;
        $result['offene'] = $offene;

        return $result;
    }

    /**
     * Explizite Diät-/Allergen-Verstöße eines GP (L3). Prüft die per-GP-Diät-Tags (tri-state:
     * NULL=unbewertet blockt nicht) + Allergen-Override »enthalten«. Liefert Klartext-Gründe.
     *
     * @param  list<string>  $diaetHart      vegan|vegetarisch|glutenfrei|laktosefrei|halal|low_carb
     * @param  list<string>  $allergenNogo   EU-14-Keys (gluten|milk|sesame|…)
     * @return list<string>
     */
    private function diaetVerstoesse(FoodAlchemistGp $gp, array $diaetHart, array $allergenNogo): array
    {
        $g = [];
        foreach ($diaetHart as $form) {
            $verstoss = match ($form) {
                'vegan'       => $gp->tag_is_vegan === false,
                'vegetarisch' => $gp->tag_is_vegetarian === false,
                'halal'       => $gp->tag_is_halal === false || $gp->tag_contains_pork === true,
                'glutenfrei'  => $gp->tag_is_gluten_free === false || $this->gpAllergenEnthalten($gp, 'gluten'),
                'laktosefrei' => $gp->tag_is_lactose_free === false || $this->gpAllergenEnthalten($gp, 'milk'),
                default       => false,   // low_carb: kein GP-Flag → unprüfbar, nie blocken
            };
            if ($verstoss) {
                $g[] = 'verletzt ' . $form;
            }
        }
        foreach ($allergenNogo as $key) {
            if ($this->gpAllergenEnthalten($gp, $key)) {
                $g[] = 'enthält ' . $key;
            }
        }

        return $g;
    }

    /** Allergen-Override des GP auf »enthalten« (NULL/spuren/unbekannt blockt bewusst nicht). */
    private function gpAllergenEnthalten(FoodAlchemistGp $gp, string $field): bool
    {
        if (! in_array($field, FoodAlchemistGp::ALLERGEN_FIELDS, true)) {
            return false;
        }

        return (string) $gp->getAttribute("allergen_{$field}") === 'enthalten';
    }
}
