<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Exceptions\FreigabeGesperrtException;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Code-Qualität 1.1f · Freigabe-Sperre (Entscheid Dominique 19.11.2026, E1): Ein Rezept, Gericht oder Concept geht nur auf „freigegeben“,
 * wenn alle seine Komponenten — rekursiv — freigegeben sind. Eine Stelle für Einzel-Freigabe, Mehrfachauswahl, Concept-Status, MCP-Tools,
 * Kaskade und Anreicherung; die Aufrufer rufen nur {@see pruefeRezept()} / {@see pruefeConcept()} bzw. {@see offenFuerRezept()}.
 *
 * Komponenten: Unterrezepte (Zutaten → `referenced_recipe_id`); beim Concept die Slot-Rezepte (`recipe_id`), eingebettete Pakete/Concepts
 * (`embedded_concept_id`, freigegeben = `active`) samt deren Inhalt. Eine Fassung (Spec 82) ist ein
 * veröffentlichter Stand und gilt als freigegeben. Abwärts wird immer weiter gesucht, auch unter freigegebenen Komponenten (ein freigegebenes
 * Basisrezept aus der Zeit vor der Sperre kann noch einen Entwurf enthalten). Zyklen terminieren über die Besucht-Liste.
 *
 * „Alles mitfreigeben“ ({@see vorschau()} / {@see mitFreigeben()}, nur Rolle Kuratieren): gibt nur EIGENE Komponenten im Status Entwurf oder
 * Prüfen mit frei; Platzhalter, Veraltete und geerbte/fremde Komponenten zeigt die Vorschau getrennt — sie blockieren weiter.
 */
class FreigabeSperre
{
    /** Status, die „Alles mitfreigeben“ heben darf (Rezept bzw. eingebettetes Paket/Concept). */
    private const MITFREIGEBBAR = ['draft', 'review'];

    /**
     * @throws FreigabeGesperrtException
     */
    public function pruefeRezept(Team $team, int $recipeId): void
    {
        $offen = $this->offenFuerRezept($recipeId);
        if ($offen !== []) {
            throw new FreigabeGesperrtException($offen, $this->vorschauAus($team, $offen)['freigebbar'] !== []);
        }
    }

    /**
     * @throws FreigabeGesperrtException
     */
    public function pruefeConcept(Team $team, int $conceptId): void
    {
        $offen = $this->offenFuerConcept($conceptId);
        if ($offen !== []) {
            throw new FreigabeGesperrtException($offen, $this->vorschauAus($team, $offen)['freigebbar'] !== []);
        }
    }

    /**
     * Mehrfachauswahl (Bulk-Status): beim Ziel „freigegeben“ alle offenen Komponenten der ausgewählten Rezepte sammeln — Komponenten, die selbst
     * in der Auswahl stehen, zählen als mitfreigegeben (alles oder nichts).
     *
     * @param  list<int>  $ids  eigene Rezepte der Auswahl
     *
     * @throws FreigabeGesperrtException
     */
    public function pruefeAuswahl(array $ids, string $status): void
    {
        if ($status !== RecipeStatus::Approved->value) {
            return;
        }
        $offen = collect($ids)->flatMap(fn ($id) => $this->offenFuerRezept((int) $id))
            ->reject(fn ($k) => $k['art'] === 'rezept' && in_array($k['id'], $ids, true))->unique(fn ($k) => $k['art'] . $k['id'])->values()->all();
        if ($offen !== []) {
            throw new FreigabeGesperrtException($offen);
        }
    }

    /**
     * Status-Wechsel über generische Update-Wege (Rezept-Editor/MCP-PUT, Concept-Update): prüft nur beim Wechsel AUF freigegeben
     * (Rezept `approved`, Concept `active`) — wer ein schon freigegebenes Objekt speichert, wird nicht nachträglich gesperrt.
     *
     * @throws FreigabeGesperrtException
     */
    public function wechsel(Team $team, \Illuminate\Database\Eloquent\Model $ziel, string $neu): string
    {
        $alt = $ziel->status instanceof \BackedEnum ? $ziel->status->value : (string) $ziel->status;
        if ($ziel instanceof \Platform\FoodAlchemist\Models\FoodAlchemistRecipe && $alt !== $neu) {
            // 1.1i: jeder Statuswechsel am Rezept braucht Kuratieren — auch über das generische Update (wie setStatus/bulkStatus)
            app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Rezept-Status setzen');
        }
        if ($ziel instanceof \Platform\FoodAlchemist\Models\FoodAlchemistRecipe && $neu === RecipeStatus::Approved->value && $alt !== $neu) {
            $this->pruefeRezept($team, (int) $ziel->id);
        } elseif ($ziel instanceof \Platform\FoodAlchemist\Models\FoodAlchemistConcept && $neu === 'active' && $alt !== $neu) {
            $this->pruefeConcept($team, (int) $ziel->id);
        }

        return $neu;
    }

    /**
     * Offene (nicht freigegebene) Komponenten eines Rezepts/Gerichts, rekursiv, ohne das Rezept selbst.
     *
     * @return list<array{art:string, id:int, name:string, status:string, team_id:?int}>
     */
    public function offenFuerRezept(int $recipeId): array
    {
        return $this->sammle([$recipeId], [], [$recipeId => true]);
    }

    /** @return list<array{art:string, id:int, name:string, status:string, team_id:?int}> */
    public function offenFuerConcept(int $conceptId): array
    {
        return $this->sammle([], [$conceptId], [], [$conceptId => true]);
    }

    /**
     * Vorschau für „Alles mitfreigeben“.
     *
     * @param  'rezept'|'concept'  $art
     * @return array{freigebbar: list<array>, blockiert: list<array>}
     */
    public function vorschau(Team $team, string $art, int $id): array
    {
        return $this->vorschauAus($team, $art === 'concept' ? $this->offenFuerConcept($id) : $this->offenFuerRezept($id));
    }

    /**
     * „Alles mitfreigeben“: alle freigebbaren Komponenten freigeben (eine Transaktion). Das Ziel selbst setzt danach der Aufrufer über seinen
     * normalen Status-Weg (der die Sperre erneut prüft) — so bleibt es bei EINER Freigabe-Stelle.
     *
     * @param  'rezept'|'concept'  $art
     * @return array{freigegeben: list<array>, blockiert: list<array>}
     */
    public function mitFreigeben(Team $team, string $art, int $id): array
    {
        app(FaRechte::class)->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Komponenten mitfreigeben');
        $v = $this->vorschau($team, $art, $id);
        DB::transaction(function () use ($v) {
            $nach = collect($v['freigebbar'])->groupBy('art');
            if ($nach->has('rezept')) {
                DB::table('foodalchemist_recipes')->whereIn('id', $nach['rezept']->pluck('id'))
                    ->update(['status' => RecipeStatus::Approved->value, 'last_modified_by' => 'editor', 'updated_at' => now()]);
            }
            if ($nach->has('paket')) {
                DB::table('foodalchemist_concepts')->whereIn('id', $nach['paket']->pluck('id'))->update(['status' => 'active', 'updated_at' => now()]);
            }
        });

        return ['freigegeben' => $v['freigebbar'], 'blockiert' => $v['blockiert']];
    }

    /** @return array{freigebbar: list<array>, blockiert: list<array>} */
    private function vorschauAus(Team $team, array $offen): array
    {
        $freigebbar = $blockiert = [];
        foreach ($offen as $k) {
            (int) $k['team_id'] === (int) $team->id && in_array($k['status'], self::MITFREIGEBBAR, true)
                ? $freigebbar[] = $k : $blockiert[] = $k;
        }

        return ['freigebbar' => $freigebbar, 'blockiert' => $blockiert];
    }

    /**
     * Breitensuche über Rezepte und Concepts. Startknoten selbst zählen nicht (sie sind in `$besuchtR`/`$besuchtC` vorbelegt).
     *
     * @param  list<int>  $rezepte
     * @param  list<int>  $concepts
     * @return list<array{art:string, id:int, name:string, status:string, team_id:?int}>
     */
    private function sammle(array $rezepte, array $concepts, array $besuchtR = [], array $besuchtC = []): array
    {
        $offen = [];
        while ($rezepte !== [] || $concepts !== []) {
            $neueR = [];
            $neueC = [];
            if ($concepts !== []) {
                $slots = DB::table('foodalchemist_concept_slots')->whereIn('concept_id', $concepts)->whereNull('deleted_at')
                    ->get(['recipe_id', 'embedded_concept_id']);
                $neueR = $slots->pluck('recipe_id')->filter()->all();
                $neueC = $slots->pluck('embedded_concept_id')->filter()->all();
            }
            if ($rezepte !== []) {
                $neueR = array_merge($neueR, DB::table('foodalchemist_recipe_ingredients')->whereIn('recipe_id', $rezepte)->whereNull('deleted_at')
                    ->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id')->all());
            }
            $rezepte = array_values(array_unique(array_filter(array_map('intval', $neueR), fn ($id) => ! isset($besuchtR[$id]))));
            $concepts = array_values(array_unique(array_filter(array_map('intval', $neueC), fn ($id) => ! isset($besuchtC[$id]))));
            foreach ($rezepte as $id) {
                $besuchtR[$id] = true;
            }
            foreach ($concepts as $id) {
                $besuchtC[$id] = true;
            }
            if ($rezepte !== []) {
                foreach (DB::table('foodalchemist_recipes')->whereIn('id', $rezepte)->whereNull('deleted_at')
                    ->get(['id', 'name', 'status', 'team_id', 'ist_fassung']) as $r) {
                    if (! $r->ist_fassung && $r->status !== RecipeStatus::Approved->value) {
                        $offen[] = ['art' => 'rezept', 'id' => (int) $r->id, 'name' => (string) $r->name, 'status' => (string) $r->status,
                            'team_id' => $r->team_id !== null ? (int) $r->team_id : null];
                    }
                }
            }
            if ($concepts !== []) {
                foreach (DB::table('foodalchemist_concepts')->whereIn('id', $concepts)->whereNull('deleted_at')
                    ->get(['id', 'name', 'status', 'team_id', 'ist_fassung']) as $c) {
                    if (! $c->ist_fassung && $c->status !== 'active') {
                        $offen[] = ['art' => 'paket', 'id' => (int) $c->id, 'name' => (string) $c->name, 'status' => (string) $c->status,
                            'team_id' => $c->team_id !== null ? (int) $c->team_id : null];
                    }
                }
            }
        }

        return $offen;
    }
}
