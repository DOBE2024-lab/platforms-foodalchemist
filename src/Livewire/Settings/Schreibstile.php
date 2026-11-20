<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Einstellungen\SchreibstileService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * R5 (Dominique): Schreibstile als EIGENE Settings-Seite mit Anlegen +
 * Inline-Edit — vorher nur Lese-Liste in der VK-Taxonomie. `sprach_duktus`
 * ist Prompt-Material (GL-06-Feld-Hülle), darum hier voll editierbar.
 * Lösch-Schutz V-06: nur deaktivieren, nie löschen.
 */
class Schreibstile extends Component
{
    /** Spec 65: Bereich settings.schreibstile je Team — Sofort-Aktionen (Liste), Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'schreibstile';
    }

    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['name' => '', 'sprach_duktus' => '', 'beispiele_md' => '', 'description' => ''];

    public ?string $fehler = null;

    public function edit(int $id): void
    {
        $zeile = DB::table('foodalchemist_writing_styles')->where('id', $id)->first();
        if ($zeile === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = ['name' => $zeile->name, 'sprach_duktus' => $zeile->sprach_duktus, 'beispiele_md' => $zeile->beispiele_md, 'description' => $zeile->description, 'sort_order' => $zeile->sort_order];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    /** Führt eine Service-Aktion aus (Regeln im SchreibstileService, 98 · 2.3a); Ablehnungen landen als Meldung in $fehler. */
    private function versuche(callable $aktion): mixed
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            return $aktion(app(SchreibstileService::class), $team) ?? true;
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

    public function toggleInactive(int $id): void
    {
        $this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $id));
    }

    /** Phase 5: hart löschen, wenn nicht von Concepts/Foodbooks referenziert (sonst locked). */
    public function delete(int $id): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->loeschen($team, $id)) === true) {
            $this->fehler = null;
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.schreibstile', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'stile' => TeamScope::applyVisible(
                DB::table('foodalchemist_writing_styles')->whereNull('deleted_at'),
                'team_id', Auth::user()?->currentTeamRelation
            )->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
