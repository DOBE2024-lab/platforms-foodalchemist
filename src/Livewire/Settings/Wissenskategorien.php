<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;
use Platform\FoodAlchemist\Services\Einstellungen\WissenskategorienService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Wissens-Modul #469: Pflege des Kategorien-Vokabulars (knowledge_categories).
 * Kategorien klassifizieren Wissens-Docs (such-/filterbar) und tragen die grobe
 * Routing-Ebene (Feature × Kategorie). Slug = weiche Referenz auf knowledge_documents.category.
 * Löschen nimmt die Kategorie SAMT aller darin liegenden (löschbaren) Docs mit — echtes
 * Aufräumen; kein Undo (siehe delete()).
 */
class Wissenskategorien extends Component
{
    /** Spec 65: Bereich settings.wissenskategorien je Team — Sofort-Aktionen (Liste), Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'wissenskategorien';
    }

    use InteractsWithSavedToast;

    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['label' => '', 'description' => ''];

    public ?string $fehler = null;

    public function edit(int $id): void
    {
        $zeile = DB::table('foodalchemist_knowledge_categories')->where('id', $id)->first();
        if ($zeile === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = [
            'label' => $zeile->label,
            'description' => $zeile->description,
            'sort_order' => $zeile->sort_order,
        ];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    /**
     * Führt eine Service-Aktion aus; Ablehnungen landen als Meldung in $fehler. Die Regeln (Schreibrecht wie
     * WissensSichtbarkeit::mayWrite — eigene Zeile immer, global nur als Master, fremde nie; Löschen samt Docs)
     * leben im WissenskategorienService (98 · 2.3a).
     */
    private function versuche(callable $aktion): mixed
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            return $aktion(app(WissenskategorienService::class), $team) ?? true;
        } catch (NichtGefundenException|GeerbtNurLesenException|NochVerwendetException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
    }

    public function save(): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->aktualisieren($team, (int) $this->editId, $this->form)) !== null) {
            $this->cancel();
        }
    }

    public function create(): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->anlegen($team, $this->neu)) !== null) {
            $this->reset('neu', 'fehler');
        }
    }

    public function toggleActive(int $id): void
    {
        $this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $id));
    }

    /** Kategorie SAMT aller darin liegenden (löschbaren) Docs endgültig löschen (HARD, kein Undo) — siehe WissenskategorienService::loeschen(). */
    public function delete(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $ergebnis = app(WissenskategorienService::class)->loeschen($team, $id);
        } catch (GeerbtNurLesenException|NochVerwendetException|NichtGefundenException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if ($ergebnis === null) {
            return;
        }
        $this->fehler = null;
        $this->savedToast("Kategorie «{$ergebnis['label']}» + {$ergebnis['docs']} Dokument(e) gelöscht");
    }

    public function render()
    {
        // Spec 82 §13 H (Sofort-Fix): nur globale Kategorien + die eigene Team-Kette, nie die Kategorien fremder Teams. Gleiche Regel wie der Wissens-Browser.
        $team = Auth::user()?->currentTeamRelation;
        $rows = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at'), 'team_id', $team,
        )->orderBy('sort_order')->orderBy('label')->get();
        // Nutzungs-Zähler je Kategorie (Docs): nur sichtbare Dokumente, sonst zählt die Zahl fremde Dossiers mit.
        $counts = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at'), 'team_id', $team,
        )->select('category', DB::raw('COUNT(*) as n'))->groupBy('category')->pluck('n', 'category');

        return view('foodalchemist::livewire.settings.wissenskategorien', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'kategorien' => $rows,
            'docCounts' => $counts,
        ]);
    }
}
