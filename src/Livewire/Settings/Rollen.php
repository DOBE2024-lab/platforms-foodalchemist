<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\Einstellungen\KuechenRollenService;
use Platform\FoodAlchemist\Services\Plattform\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Stufe 3 P3.1 — Küchen-Rollen mit Kostensatz pflegen (Küchenchef / Koch / Hilfskoch …).
 *
 * Muster 1:1 wie `Posten`: team-eigene Betriebsstammdaten, `feldSetzen`-Inline-Edit,
 * Lösch-Schutz (nur stilllegen), geerbte Rollen sind Vorlagen (nicht editierbar).
 *
 * ⚠️ Rolle ≠ Mensch. Kein Name einer Person, keine Schicht — nur die Rolle und ihr Satz.
 * Die Posten-Besetzung (Anzahl je Rolle) rechnet daraus Kapazität + Kosten (P3.1 Slice 2).
 */
class Rollen extends Component
{
    /** Spec 65: Bereich settings.rollen je Team — Felder schreiben sofort, Leiste Bearbeiten → Fertig. Der Betriebs-Wähler (outletId) bleibt bedienbar. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'rollen';
    }

    /** @var array{name: string, satz: string} */
    public array $neu = ['name' => '', 'satz' => ''];

    /** Ebene 2: Bearbeitungs-Scope der €/Std-Sätze (null = Team-Satz, sonst Betriebs-Override). */
    public ?int $outletId = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    /** Betrieb-Scope der Rollensatz-Overrides (team-eigen, aktiv) oder null (Team-Satz). */
    private function scopeOutlet(): ?FoodAlchemistOutlet
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($this->outletId === null || $team === null) {
            return null;
        }

        return FoodAlchemistOutlet::where('team_id', $team->id)->where('is_inactive', false)->find($this->outletId);
    }

    public function create(): void
    {
        // Rollen selbst sind Team-Stammdaten — im Betrieb-Scope nur Sätze überschreiben, nicht anlegen.
        if ($this->outletId !== null) {
            return;
        }
        $this->fehler = null;
        $this->meldung = null;

        $name = trim($this->neu['name']);
        if ($this->versuche(fn ($svc, $team) => $svc->anlegen($team, $this->neu), 'Name ist Pflicht.') === null) {
            return;
        }

        $this->neu = ['name' => '', 'satz' => ''];
        $this->meldung = "«{$name}» angelegt.";
    }

    public function feldSetzen(int $id, string $feld, string $wert): void
    {
        $this->fehler = null;
        $this->meldung = null;

        // Ebene 2: im Betrieb-Scope wird nur der €/Std-Satz je Rolle überschrieben (leer = erbt Team-Satz).
        $outlet = $this->scopeOutlet();
        if ($outlet !== null) {
            if ($feld !== 'satz') {
                return;  // Name/Bestand bleiben Team-Sache
            }
            $team = Auth::user()?->currentTeamRelation;
            $rolle = $team !== null ? FoodAlchemistKitchenRole::visibleToTeam($team)->find($id) : null;
            if ($rolle === null) {
                $this->fehler = 'Rolle nicht sichtbar.';

                return;
            }
            app(OutletSettingsService::class)->setRoleRate($team, $outlet, $id, $this->satz($wert));
            $this->meldung = 'Kalkulationsprofil-Satz gespeichert.';

            return;
        }

        if ($this->versuche(fn ($svc, $team) => $svc->feldSetzen($team, $id, $feld, $wert)) !== null) {
            $this->meldung = 'Gespeichert.';
        }
    }

    public function aktivToggle(int $id): void
    {
        if ($this->outletId !== null) {
            return;  // Bestand ist Team-Sache; Betrieb überschreibt nur Sätze
        }
        $rolle = $this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $id));
        if ($rolle !== null) {
            $this->meldung = $rolle->is_inactive ? "«{$rolle->name}» stillgelegt." : "«{$rolle->name}» wieder aktiv.";
        }
    }

    /**
     * Führt eine Schreib-Aktion des KuechenRollenService aus (98 · 2.3a). Nur Rollen des EIGENEN Teams sind editierbar —
     * die Regel liegt im Service; Ablehnungen landen als Meldung in $fehler, Ergebnis null.
     */
    private function versuche(callable $aktion, string $ohneTeam = 'Rolle nicht im Schreibzugriff.'): ?FoodAlchemistKitchenRole
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = $ohneTeam;

            return null;
        }
        try {
            return $aktion(app(KuechenRollenService::class), $team);
        } catch (GeerbtNurLesenException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
    }

    /** €/Std robust lesen: leer ⇒ null (= flacher Team-Satz greift), sonst ≥ 0, Komma erlaubt. */
    private function satz(string $roh): ?float
    {
        return KuechenRollenService::satz($roh);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $outlet = $this->scopeOutlet();

        $betriebe = $team !== null
            ? FoodAlchemistOutlet::where('team_id', $team->id)->where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all()
            : [];

        // Betriebs-Rollensätze (Map roleId→€/Std) fürs Anzeigen im Betrieb-Scope; Keys als int.
        $outletRates = [];
        foreach ($team !== null ? app(TeamSettingsService::class)->outletRoleRates($team, $outlet) : [] as $k => $v) {
            $outletRates[(int) $k] = $v;
        }

        return view('foodalchemist::livewire.settings.rollen', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'rollen' => $team !== null
                ? FoodAlchemistKitchenRole::visibleToTeam($team)
                    ->orderBy('sort_order')->orderBy('name')->get()
                : collect(),
            'eigenesTeamId' => $team?->id,
            'betriebe' => $betriebe,
            'scopeOutletName' => $outlet?->name,
            'outletRates' => $outletRates,
        ]);
    }
}
