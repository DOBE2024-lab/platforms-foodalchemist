<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FreigabeGesperrtException;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Rezept\FreigabeSperre;

/**
 * Code-Qualität 1.1f · Freigabe-Meldung mit Knopf „Alles mitfreigeben“ für Rezept-, Gericht- und Concept-Flächen. Die Fläche fängt die
 * {@see FreigabeGesperrtException} aus ihrem Status-Aufruf mit {@see freigabeGesperrt()} ab und bindet die Meldung per
 * `<x-fa::freigabe-sperre :sperre="$freigabeSperre" />` ein. Logik und Rechte prüft {@see FreigabeSperre} (Service).
 */
trait HatFreigabeSperre
{
    /** @var array{art:string, id:int, meldung:string, freigebbar:list<array>, blockiert:list<array>, darf:bool}|null */
    public ?array $freigabeSperre = null;

    /** @param 'rezept'|'concept' $art */
    protected function freigabeGesperrt(FreigabeGesperrtException $e, string $art, ?int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        // Mehrfachauswahl (ohne Ziel-Id): nur die Meldung mit den offenen Komponenten, kein „Alles mitfreigeben“
        $v = $team !== null && $id !== null ? app(FreigabeSperre::class)->vorschau($team, $art, $id) : ['freigebbar' => [], 'blockiert' => []];
        $this->freigabeSperre = ['art' => $art, 'id' => (int) $id, 'meldung' => $e->getMessage()] + $v + [
            'darf' => $team !== null && $id !== null && app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Kuratieren),
        ];
    }

    /** „Alles mitfreigeben“: Komponenten freigeben, dann das Ziel über seinen normalen Status-Weg (der die Sperre erneut prüft). */
    public function allesMitfreigeben(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $s = $this->freigabeSperre;
        if ($team === null || $s === null) {
            return;
        }
        try {
            app(FreigabeSperre::class)->mitFreigeben($team, $s['art'], $s['id']);
            $s['art'] === 'concept'
                ? app(ConceptService::class)->setStatus($team, $s['id'], 'active')
                : app(RecipeService::class)->setStatus($team, $s['id'], 'approved');
            $this->freigabeSperre = null;
            $this->dispatch('recipe-gespeichert');
        } catch (FreigabeGesperrtException $e) {
            $this->freigabeGesperrt($e, $s['art'], $s['id']);
        } catch (\RuntimeException $e) {
            $this->freigabeSperre = ['meldung' => $e->getMessage(), 'freigebbar' => [], 'darf' => false] + $s;
        }
    }

    public function freigabeSperreSchliessen(): void
    {
        $this->freigabeSperre = null;
    }
}
