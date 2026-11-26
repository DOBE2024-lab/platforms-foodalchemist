<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Support\Suche;

/**
 * Verwaltungs-Block für Basisrezepte (Spec 49, Dominique 2026-09-04): „in allen Verwendungen
 * tauschen" + „löschen" — das Pendant zum GP-Verwaltungsblock, gleichzeitig im Detail-Panel UND
 * im Editor. Der GP-Tausch lag anfangs nur im Panel und wurde später in den Editor KOPIERT; die
 * Kopie lief auseinander (roher Status-String, unfindbarer Reiter). Deshalb liegt die Mechanik
 * hier von Anfang an EINMAL im Trait und die Oberfläche in EINEM Blade-Partial.
 *
 * Erwartet an der einbindenden Komponente: `public ?int $recipeId`.
 */
trait TauschtRezept
{
    public string $tauschSuche = '';

    public ?string $fehlerTausch = null;

    public ?string $hinweisTausch = null;

    /** Gericht-Verwaltung (Plan 23.11.): gewähltes Ziel-Gericht → Vorschau; erst „Ersetzen“ schreibt. */
    public ?int $tauschZielId = null;

    /** Gericht-Verwaltung: Haken „auch aktive Foodbooks“ (Standard aus, Live-Inhalt beim Kunden ändert sich). */
    public bool $tauschAuchAktiveFoodbooks = false;

    /** Gericht-Verwaltung: gewählte Bereiche (Standard alle). @var list<string> */
    public array $tauschBereiche = [];

    /** Alle eigenen Eltern-Zeilen dieses Rezepts auf $zielId umhängen + neu rechnen. */
    public function rezeptErsetzen(int $zielId): void
    {
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            $ergebnis = app(RecipeService::class)->ersetzeInVerwendungen($team, $this->recipeId, $zielId);
        } catch (\RuntimeException $e) {
            $this->fehlerTausch = $e->getMessage();

            return;
        }
        $this->tauschSuche = '';
        $this->hinweisTausch = $this->tauschMeldung($zielId, $ergebnis);
        $this->dispatch('recipe-gespeichert');                     // Browser + Panels: frische Aggregate
    }

    /** Ziel-Vorschläge zum Suchtext — Veraltet-Rezepte und das Rezept selbst sind keine Ziele. */
    protected function tauschKandidaten(): Collection
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || trim($this->tauschSuche) === '') {
            return collect();
        }

        return Suche::like(
            FoodAlchemistRecipe::visibleToTeam($team)
                ->whereKeyNot($this->recipeId)
                ->where('status', '!=', RecipeStatus::Deprecated->value)
                ->when($this->istGerichtTausch(), fn ($q) => $q->where('is_sales_recipe', true)),   // F1: Gericht nur durch Gericht
            'name',
            $this->tauschSuche,
        )->orderBy('name')->limit(6)->get(['id', 'name', 'status', 'team_id', 'is_sales_recipe']);
    }

    /**
     * @return array{zeilen:int, rezepte:int, fremd_zeilen:int, fremd_rezepte:int,
     *               eltern_namen: list<array{id:int,name:string,ist_gericht:bool}>,
     *               fremd_namen: list<array{id:int,name:string,ist_gericht:bool}>}
     */
    protected function tauschBilanz(): array
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null && $this->recipeId !== null
            ? app(RecipeService::class)->verwendungsBilanz($team, $this->recipeId)
            // Die Namens-Schlüssel MÜSSEN auch im Leerfall stehen — das Partial liest sie
            // direkt, ein fehlender Key wäre ein „Undefined array key" im Render.
            : ['zeilen' => 0, 'rezepte' => 0, 'fremd_zeilen' => 0, 'fremd_rezepte' => 0,
                'eltern_namen' => [], 'fremd_namen' => []];
    }

    /**
     * Lösch-Bilanz — `null` heißt „hier gibt es keinen Lösch-Knopf": Gerichte gehen über den
     * VK-Editor (`SalesRecipeService::deleteDish`), geerbte Rezepte gar nicht (D1).
     *
     * @return array{eltern_zeilen:int, eltern:int, ersatz:int, ausgaben:int, produktion_offen:int,
     *               produktion_historie:int, instanzen:int, blocker:int, blocker_teile:list<string>,
     *               eltern_namen: list<array{id:int,name:string,ist_gericht:bool}>}|null
     */
    protected function tauschReferenzen(): ?array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return null;
        }
        $rezept = FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId, ['id', 'team_id', 'is_sales_recipe']);
        if ($rezept === null || $rezept->is_sales_recipe || ! $rezept->isOwnedBy($team)) {
            return null;
        }

        return app(RecipeService::class)->referenzen($this->recipeId);
    }

    /**
     * Rezept löschen (Soft-Delete) — der Service blockt bei jeder harten Referenz.
     * 98 · 2.4d: auch im Ansichtsmodus; holt die Sperre für die Dauer der Aktion (MitBearbeitungssperre::mitKurzsperre).
     * Nutzer tragen rezeptLoeschen in kurzsperrMethoden() ein.
     */
    public function rezeptLoeschen(): void
    {
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        if ($this->istGerichtTausch()) {
            $this->mitKurzsperre(function () use ($team) {
                try {
                    app(\Platform\FoodAlchemist\Services\Rezept\SalesRecipeService::class)->deleteDish($team, $this->recipeId);
                } catch (\Throwable $e) {
                    $this->fehlerTausch = $e->getMessage();

                    return;
                }
                $this->dispatch('recipe-gespeichert');
                $this->nachRezeptLoeschung();
            });

            return;
        }
        if ($this->tauschReferenzen() === null) {
            $this->fehlerTausch = 'Löschen hier nicht möglich — Gerichte laufen über den Verkaufs-Editor, geerbte Rezepte über ihr Besitzer-Team (D1).';

            return;
        }
        $this->mitKurzsperre(function () use ($team) {
            try {
                app(RecipeService::class)->delete($team, $this->recipeId);
            } catch (\Throwable $e) {
                $this->fehlerTausch = $e->getMessage();

                return;
            }
            $this->dispatch('recipe-gespeichert');                     // Browser: Zeile verschwindet
            $this->nachRezeptLoeschung();
        });
    }

    // ── Gericht-Verwaltung (Plan „Verwaltung am Gericht“, 23.11.2026): Vorschau → Ersetzen → Löschen ──────────

    /** Ist das Rezept dieses Blocks ein Gericht? Dann gilt der Gericht-Weg (alle Ausgaben, Soft-Delete). */
    protected function istGerichtTausch(): bool
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null && $this->recipeId !== null
            && (bool) FoodAlchemistRecipe::visibleToTeam($team)->whereKey($this->recipeId)->value('is_sales_recipe');
    }

    /**
     * Daten des Gericht-Blocks — `null` bei Basisrezepten (dort gilt der Komponenten-Block wie bisher).
     *
     * @return array{loesch: array, vorschau: ?array, bereiche: list<string>}|null
     */
    protected function gerichtVerwaltung(): ?array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || ! $this->istGerichtTausch()) {
            return null;
        }
        $vorschau = null;
        if ($this->tauschZielId !== null) {
            try {
                $vorschau = app(\Platform\FoodAlchemist\Services\Gericht\GerichtTauschService::class)
                    ->vorschau($team, $this->recipeId, $this->tauschZielId, $this->tauschAuchAktiveFoodbooks);
            } catch (\RuntimeException $e) {
                $this->fehlerTausch = $e->getMessage();
                $this->tauschZielId = null;
            }
        }

        return [
            'loesch' => app(\Platform\FoodAlchemist\Services\Gericht\GerichtVerwendungen::class)->loeschBilanz($team, $this->recipeId),
            'vorschau' => $vorschau,
            'bereiche' => \Platform\FoodAlchemist\Services\Gericht\GerichtVerwendungen::TAUSCH_BEREICHE,
        ];
    }

    /** Ziel-Gericht wählen → Vorschau zeigen (schreibt nichts). */
    public function gerichtTauschVorbereiten(int $zielId): void
    {
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
        $this->tauschZielId = $zielId;
        $this->tauschBereiche = \Platform\FoodAlchemist\Services\Gericht\GerichtVerwendungen::TAUSCH_BEREICHE;
        $this->tauschSuche = '';
    }

    public function gerichtTauschAbbrechen(): void
    {
        $this->tauschZielId = null;
        $this->tauschAuchAktiveFoodbooks = false;
        $this->tauschBereiche = [];
    }

    /** Ersetzen in den gewählten Bereichen — alles oder nichts. Auch im Ansichtsmodus (Kurzsperre wie Löschen). */
    public function gerichtErsetzen(): void
    {
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->tauschZielId === null) {
            return;
        }
        $this->mitKurzsperre(function () use ($team) {
            try {
                $e = app(\Platform\FoodAlchemist\Services\Gericht\GerichtTauschService::class)
                    ->ersetze($team, $this->recipeId, $this->tauschZielId, $this->tauschBereiche, $this->tauschAuchAktiveFoodbooks);
            } catch (\RuntimeException $ex) {
                $this->fehlerTausch = $ex->getMessage();

                return;
            }
            $ziel = FoodAlchemistRecipe::visibleToTeam($team)->find($this->tauschZielId)?->name ?? "#{$this->tauschZielId}";
            $teile = array_filter(array_map(fn ($b, $n) => $n > 0 ? "{$n} {$b}" : null, array_keys($e['getauscht']), $e['getauscht']));
            $this->hinweisTausch = 'Auf „' . $ziel . '“ umgehängt: ' . ($teile !== [] ? implode(', ', $teile) : 'nichts') . '.'
                . ($e['bleibt'] !== [] ? ' Bleibt: ' . implode(' · ', array_map(fn ($b) => $b['ort'] . ($b['grund'] ? " ({$b['grund']})" : ''), array_slice($e['bleibt'], 0, 6))) . '.' : '')
                . ($e['historisch'] > 0 ? " {$e['historisch']} historische Einträge behalten das alte Gericht als Verweis." : '');
            $this->gerichtTauschAbbrechen();
            $this->dispatch('recipe-gespeichert');
        });
    }

    /** Aufräumen nach dem Löschen — das Panel leert seine Auswahl, der Editor schließt zusätzlich. */
    protected function nachRezeptLoeschung(): void
    {
        $this->recipeId = null;
    }

    /** Ergebnis-Satz — nennt AUCH das Übersprungene (geerbt / Zyklus / Ziel schon drin). */
    private function tauschMeldung(int $zielId, array $e): string
    {
        $ziel = FoodAlchemistRecipe::find($zielId)?->name ?? "#{$zielId}";
        $satz = "{$e['zeilen']} Zeile(n) in {$e['rezepte']} Rezept(en) auf „{$ziel}“ umgehängt — Rezepte neu berechnet.";
        if (($e['fremd_rezepte'] ?? 0) > 0) {
            $satz .= " {$e['fremd_rezepte']} geerbtes/geerbte Rezept(e) unberührt (read-only, D1).";
        }
        if (($e['zyklus'] ?? []) !== []) {
            $satz .= ' Übersprungen (Zyklus): ' . implode(', ', $e['zyklus']) . '.';
        }
        if (($e['doppelt'] ?? []) !== []) {
            $satz .= ' Ziel war schon enthalten in: ' . implode(', ', $e['doppelt'])
                . ' — dort stehen jetzt zwei Zeilen (Mengen prüfen).';
        }

        return $satz;
    }
}
