<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\Preis\CatalogPricingService;
use Platform\FoodAlchemist\Services\Einstellungen\AufschlagsklassenService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Preisklassen als relative Abweichung vom dynamischen Unternehmens-Basissatz.
 * Legacy-Rohaufschläge bleiben nur als Migrationsquelle in der Datenbank.
 */
class Aufschlagsklassen extends Component
{
    /** Spec 65: Bereich settings.aufschlagsklassen je Team — Sofort-Aktionen, Leiste Bearbeiten → Fertig. Der Betriebs-Wähler (outletId) ist nur Vorschau. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'aufschlagsklassen';
    }

    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['code' => '', 'label' => '', 'class_factor_pct' => '100', 'vat_profile_key' => '', 'rounding_decimals' => '', 'rounding_mode' => '', 'note' => '', 'bereich' => 'speisen'];

    public ?string $fehler = null;

    /**
     * Ebene 2: lokaler Vorschau-Scope für den Basissatz (null = Team, sonst ein Betrieb).
     * Verändert NUR die Basissatz-/Gesamtfaktor-Vorschau — die Klassenfaktoren/MwSt/Rundung
     * bleiben teamweit (relative Multiplikatoren, keine Outlet-Spalten). Nicht die globale Brille.
     */
    public ?int $outletId = null;

    /** MVP-039 (P0): mutierbar nur fürs Besitzer-Team — die Regel liegt im AufschlagsklassenService (98 · 2.3a). */
    private function eigeneKlasse(int $id): ?FoodAlchemistMarkupClass
    {
        $team = Auth::user()?->currentTeamRelation;
        try {
            $ak = $team !== null ? app(AufschlagsklassenService::class)->eigene($team, $id) : null;
        } catch (NichtGefundenException|GeerbtNurLesenException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
        $this->fehler = null;

        return $ak;
    }

    /** Führt eine Service-Aktion aus; Ablehnungen landen als Meldung in $fehler. */
    private function versuche(callable $aktion): bool
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return false;
        }
        try {
            $aktion(app(AufschlagsklassenService::class), $team);
        } catch (NichtGefundenException|GeerbtNurLesenException|NochVerwendetException|\InvalidArgumentException $e) {   // DB-Fehler laufen weiter hoch
            $this->fehler = $e->getMessage();

            return false;
        }
        $this->fehler = null;

        return true;
    }

    public function edit(int $id): void
    {
        // Öffnen nur, wenn speicherbar — sonst zeigt das Formular eine Aktion, die immer scheitert.
        $ak = $this->eigeneKlasse($id);
        if ($ak === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = [
            'label' => $ak->label,
            'class_factor_pct' => (string) ($ak->class_factor_pct ?? 100),
            'vat_profile_key' => (string) ($ak->vat_profile_key ?? ''),
            'rounding_decimals' => $ak->rounding_decimals !== null ? (string) $ak->rounding_decimals : '',
            'rounding_mode' => (string) ($ak->rounding_mode ?? ''),
            'note' => $ak->note,
        ];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        // MVP-039: Owner-Guard auch am Write (im Service) — ein manipuliertes editId kommt nicht durch.
        if ($this->versuche(fn ($svc, $team) => $svc->aktualisieren($team, (int) $this->editId, $this->form))) {
            $this->cancel();
            $this->dispatch('recipe-gespeichert');                    // Marge-Anzeigen (Cockpit) neu rechnen
        }
    }

    public function create(): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->anlegen($team, $this->neu))) {
            $this->reset('neu', 'fehler');
        }
    }

    public function toggleInactive(int $id): void
    {
        $this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $id));   // MVP-039
    }

    public function setDefault(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $ak = FoodAlchemistMarkupClass::visibleToTeam($team)->bereich('speisen')
            ->whereKey($id)->where('is_inactive', false)->first();
        if ($ak === null) {
            $this->fehler = 'Die Preisklasse ist nicht verfügbar, deaktiviert oder eine Leistungsklasse (Standard gilt nur für Speisen).';

            return;
        }

        app(TeamSettingsService::class)->update($team, ['default_markup_class_id' => $ak->id]);
        app(PricingCascadeService::class)->recomputeTeam($team);
        $this->fehler = null;
        $this->dispatch('recipe-gespeichert');
    }

    /** Phase 5: hart löschen, wenn unbenutzt (sonst locked → deaktivieren). */
    public function delete(int $id): void
    {
        $this->versuche(fn ($svc, $team) => $svc->loeschen($team, $id));       // MVP-039/-040
    }

    /** @return array<int, int> Klasse → Zahl der sichtbaren Gerichte über ihre Darreichungen (Spec 85 D-1 PR-C: Altspalte gedroppt). */
    private function zaehler($team): array
    {
        return TeamScope::applyVisible(DB::table('foodalchemist_recipe_presentations'), 'team_id', $team)
            ->whereNull('deleted_at')->whereNotNull('markup_class_id')
            ->selectRaw('markup_class_id, COUNT(DISTINCT recipe_id) AS n')->groupBy('markup_class_id')->pluck('n', 'markup_class_id')->map(fn ($v) => (int) $v)->all();
    }

    public function render()
    {
        // Mandanten-Sichtbarkeit (D1): globaler Seed (team_id NULL) + eigenes Team/Master-Kette.
        $team = Auth::user()?->currentTeamRelation;

        // Ebene 2: Basissatz-Vorschau folgt dem gewählten Betrieb (team-eigen, aktiv) — sonst Team-Baseline.
        $outlet = null;
        if ($team !== null && $this->outletId !== null) {
            $outlet = FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->find($this->outletId);
        }
        $base = $team !== null ? app(CatalogPricingService::class)->enterpriseBaseRate($team, $outlet) : null;

        $betriebeOptionen = $team !== null
            ? FoodAlchemistOutlet::where('team_id', $team->id)->where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all()
            : [];

        return view('foodalchemist::livewire.settings.aufschlagsklassen', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'team' => $team,
            'base' => $base,
            'betriebeOptionen' => $betriebeOptionen,
            'scopeOutletName' => $outlet?->name,
            'standardId' => $team !== null ? app(TeamSettingsService::class)->defaultMarkupClassId($team) : null,
            'klassen' => TeamScope::applyVisible(FoodAlchemistMarkupClass::query(), 'team_id', $team)->orderBy('code')->get(),
            // MVP-040: nur sichtbare Gerichte zählen — der Zähler verriet sonst fremde Team-Nutzung.
            // Spec 85 D-1 PR-B: Gerichte je Klasse über ihre Darreichungen (DISTINCT recipe_id) (Spec 85 D-1 PR-C: nur noch Darreichungen).
            'zaehler' => $this->zaehler($team),
        ]);
    }
}
