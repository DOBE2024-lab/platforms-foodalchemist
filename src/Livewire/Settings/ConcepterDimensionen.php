<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Einstellungen\ConcepterDimensionenService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Umbau-Spec Darreichungen Phase 4b (Review F3–F6): Pflege der Concepter-Facetten —
 * Einsatzmomente, Eventtypen, Saisons (team-eigen, FA-nativ) + Servierformen
 * (WaWi-Master-Spiegel: FA-native Zusätze erlaubt, Import-Einträge nur
 * deaktivierbar). Lösch-Schutz V-06: genutzte Einträge nur deaktivieren.
 */
class ConcepterDimensionen extends Component
{
    /** Spec 65: Bereich settings.concepter_dimensionen je Team — Sofort-Aktionen (Liste), Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'concepter_dimensionen';
    }

    /** Whitelist: key => [tabelle, label, hint] — Quelle ist der Service (98 · 2.3a), hier für die Blade gespiegelt. */
    public const VOKABULARE = ConcepterDimensionenService::VOKABULARE;

    /** @var array<string, string> Add-Form (Name) je Vokabular */
    public array $neu = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        foreach (array_keys(self::VOKABULARE) as $key) {
            $this->neu[$key] = '';
        }
    }

    /** Führt eine Service-Aktion aus; Ablehnungen landen als Meldung in $fehler. null = abgelehnt/kein Team. */
    private function versuche(callable $aktion): mixed
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            return $aktion(app(ConcepterDimensionenService::class), $team);
        } catch (NichtGefundenException|GeerbtNurLesenException|NochVerwendetException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
    }

    public function create(string $vokabular): void
    {
        $name = $this->versuche(fn ($svc, $team) => $svc->anlegen($team, $vokabular, (string) ($this->neu[$vokabular] ?? '')));
        if ($name === null) {
            return;
        }
        $this->neu[$vokabular] = '';
        $this->fehler = null;
        $this->meldung = "«{$name}» angelegt.";
    }

    /** V-06: nur deaktivieren — inaktive bleiben an bestehenden Concepts sichtbar. */
    public function toggleInactive(string $vokabular, int $id): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $vokabular, $id)) === true) {
            $this->meldung = 'Aktualisiert.';
        }
    }

    /** Hart löschen nur wenn ungenutzt; Servierform-Import-Einträge (legacy_id) nie löschen. */
    public function delete(string $vokabular, int $id): void
    {
        $name = $this->versuche(fn ($svc, $team) => $svc->loeschen($team, $vokabular, $id));
        if ($name === null) {
            return;
        }
        $this->fehler = null;
        $this->meldung = "«{$name}» gelöscht.";
    }

    public function render()
    {
        $listen = [];
        foreach (self::VOKABULARE as $key => $meta) {
            $listen[$key] = $meta + [
                'zeilen' => TeamScope::applyVisible(
                    DB::table($meta['tabelle'])->whereNull('deleted_at'),
                    'team_id', Auth::user()?->currentTeamRelation
                )->orderBy('sort_order')->orderBy($key === 'servierformen' ? 'label' : 'name')->get(),
            ];
        }

        return view('foodalchemist::livewire.settings.concepter-dimensionen', ['sperr' => $this->sperrZustand(), 'listen' => $listen]);
    }
}
