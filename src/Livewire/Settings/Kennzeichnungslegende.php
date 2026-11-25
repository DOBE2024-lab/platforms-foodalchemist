<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;

class Kennzeichnungslegende extends Component
{
    public bool $aktiv = false;

    public array $codes = [];

    public ?string $meldung = null;

    public function mount(ConcepterAggregateService $service): void
    {
        $entwurf = $service->kennzeichnungEntwurf($this->team());
        $this->aktiv = $entwurf['aktiv'];
        $this->codes = $entwurf['codes'];
    }

    public function tauschen(string $gruppe, string $slug, string $code): void
    {
        $aktuell = $this->codes[$gruppe][$slug] ?? null;
        $partner = $aktuell === null ? false : array_search($code, $this->codes[$gruppe], true);
        if ($partner !== false) {
            $this->codes[$gruppe][$partner] = $aktuell;
            $this->codes[$gruppe][$slug] = $code;
        }
        $this->meldung = null;
    }

    public function grundlegendeLaden(ConcepterAggregateService $service): void
    {
        $this->codes = $service->kennzeichnungStandardCodes();
        $this->meldung = null;
    }

    public function speichern(ConcepterAggregateService $service): void
    {
        try {
            $service->kennzeichnungSpeichern($this->team(), $this->aktiv, $this->codes);
            $this->resetErrorBag('codes');
            $this->meldung = 'Kennzeichnungslegende gespeichert.';
        } catch (\InvalidArgumentException $e) {
            $this->addError('codes', $e->getMessage());
        }
    }

    public function render(ConcepterAggregateService $service)
    {
        return view('foodalchemist::livewire.settings.kennzeichnungslegende', [
            'grundlegende' => $service->kennzeichnungKatalog(),
            'standardCodes' => $service->kennzeichnungStandardCodes(),
        ]);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
