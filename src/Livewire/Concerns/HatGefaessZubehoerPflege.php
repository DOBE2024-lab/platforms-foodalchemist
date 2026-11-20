<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\GefaessZubehoerService;
use Platform\FoodAlchemist\Support\ZubehoerAuswahl;

/**
 * Zubehör am Gefäß-GP pflegen (Entscheid Dominique 20.11.2026, „Weg 2“) — im GP-Detail unter „Art als Gefäß“: „gehört dazu: n × Deckel …“.
 * Betriebsdatum des Teams wie Art und Preis (ohne „Bearbeiten“-Sperre, die Methoden stehen in `sperrFreiExtra`). Nur Non-Food als Zubehör.
 * Erwartet `$gpId` an der Komponente.
 */
trait HatGefaessZubehoerPflege
{
    public string $gefaessZubehoerSuche = '';

    public string $gefaessZubehoerMenge = '1';

    public ?string $gefaessZubehoerFehler = null;

    public function gefaessZubehoerHinzu(int $zubehoerGpId): void
    {
        $this->gefaessZubehoerAusfuehren(fn ($svc, $team) => $svc->setzen($team, (int) $this->gpId, $zubehoerGpId, $this->gefaessZubehoerMenge), 'Zubehör hinzugefügt');
        if ($this->gefaessZubehoerFehler === null) {
            $this->gefaessZubehoerSuche = '';
            $this->gefaessZubehoerMenge = '1';
        }
    }

    public function gefaessZubehoerMengeSetzen(int $zubehoerGpId, mixed $menge): void
    {
        $this->gefaessZubehoerAusfuehren(fn ($svc, $team) => $svc->setzen($team, (int) $this->gpId, $zubehoerGpId, $menge), 'Menge gespeichert');
    }

    public function gefaessZubehoerWeg(int $zubehoerGpId): void
    {
        $this->gefaessZubehoerAusfuehren(fn ($svc, $team) => $svc->entfernen($team, (int) $this->gpId, $zubehoerGpId), 'Zubehör entfernt');
    }

    /** @param  callable(GefaessZubehoerService, \Platform\Core\Models\Team): int  $tun */
    private function gefaessZubehoerAusfuehren(callable $tun, string $meldung): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->gpId === null) {
            return;
        }
        $this->gefaessZubehoerFehler = null;
        try {
            $n = $tun(app(GefaessZubehoerService::class), $team);
            $this->dispatch('fa-saved', message: $meldung.($n > 0 ? " — {$n} Darreichungen neu gerechnet." : '.'), type: 'success');
        } catch (\RuntimeException $e) {
            $this->gefaessZubehoerFehler = $e->getMessage();
        }
    }

    /**
     * Anzeige: wirksame Liste mit Art und Preis je Teil, Summe je Gefäß, Herkunft (geerbt) und Treffer zum Suchtext (nur Non-Food).
     *
     * @return array{zeilen: list<array<string,mixed>>, summe: float, preis_fehlt: bool, geerbt_von: ?string, treffer: \Illuminate\Support\Collection}
     */
    private function gefaessZubehoerLaden($team, FoodAlchemistGp $gp): array
    {
        $liste = app(GefaessZubehoerService::class)->liste((int) $gp->id, $team);
        $kosten = app(DarreichungService::class)->gefaessZubehoerKosten($gp, $team);
        $schon = array_column($kosten['zeilen'], 'gp_id');
        $treffer = trim($this->gefaessZubehoerSuche) !== ''
            ? ZubehoerAuswahl::treffer(FoodAlchemistGp::visibleToTeam($team), $this->gefaessZubehoerSuche)
                ->reject(fn ($t) => $t->id === (int) $gp->id || in_array($t->id, $schon, true))->values()
            : collect();

        return [...$kosten, 'treffer' => $treffer,
            'geerbt_von' => $liste['team_id'] !== null && $liste['team_id'] !== (int) $team->id ? \Platform\Core\Models\Team::find($liste['team_id'])?->name : null];
    }
}
