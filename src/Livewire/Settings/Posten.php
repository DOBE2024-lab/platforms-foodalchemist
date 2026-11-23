<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\Produktion\ProductionStationService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 30 E3 — Posten (Küchen-Arbeitsplätze) pflegen.
 *
 * Bewusst NICHT in die `Behaelter::VOKABULARE`-Whitelist gehängt: das ist ein uniformer
 * `DB::table`-Pfad für Vokabulare und verträgt weder den Wochentag-Editor noch die
 * Team-Pflicht. Posten sind Betriebsstammdaten, keine Vokabel — siehe Migrations-Docblock.
 *
 * ⚠️ Wir pflegen ARBEITSPLÄTZE, keine Menschen. Kapazität ist NETTO (Rüsten/Reinigen/Pause
 * abgezogen) und optional: ohne Zahl warnt der Posten nie. Das ist der Anti-Nerv-Schalter —
 * wer keine Kapazität pflegt, merkt vom ganzen Feature nichts außer der Minutensumme.
 *
 * Lösch-Schutz V-06: nur deaktivieren. Zuteilungen an Auftragszeilen sollen nicht ins Leere
 * laufen, nur weil jemand einen Posten aufräumt.
 */
class Posten extends Component
{
    /** Spec 65: Bereich settings.posten je Team — Felder/Besetzung schreiben sofort, Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'posten';
    }

    /** @var array{name: string, group_name: string, kapazitaet: string} */
    public array $neu = ['name' => '', 'group_name' => '', 'kapazitaet' => ''];

    public ?string $fehler = null;

    public ?string $meldung = null;

    /** Team-Standard-Topf-Deckel (Fallback für die Produktionszeit) — leer = System-Standard. */
    public string $standardTopfKg = '';

    public string $standardTopfStueck = '';

    /** Team-Vorgabe "Posten für Gerichte" (Pass): ID als String, leer = keine Vorgabe (Spec 83 B2). */
    public string $gerichteStationId = '';

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $s = app(TeamSettingsService::class)->for($team);
        $this->standardTopfKg = $s->default_batch_max_kg !== null ? (string) (float) $s->default_batch_max_kg : '';
        $this->standardTopfStueck = $s->default_batch_max_pieces !== null ? (string) (float) $s->default_batch_max_pieces : '';
        $this->gerichteStationId = (string) (app(TeamSettingsService::class)->gerichtePostenId($team) ?? '');
    }

    /**
     * Team-Vorgabe "Posten für Gerichte" speichern (Spec 83 B2): Gerichte gehen an diesen Posten (meist der Pass), wenn das Rezept keinen eigenen
     * Standard-Posten trägt. Nur ein für das Team sichtbarer, aktiver Posten; leer löscht die Vorgabe. Unter-Teams erben sie vom Oberteam.
     */
    public function gerichtePostenSpeichern(): void
    {
        $this->fehler = null;
        $this->meldung = null;

        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team im Zugriff.';

            return;
        }
        $id = $this->gerichteStationId === '' ? null : (int) $this->gerichteStationId;
        if ($id !== null && ! FoodAlchemistProductionStation::visibleToTeam($team)->whereKey($id)->where('is_inactive', false)->exists()) {
            $this->fehler = 'Dieser Posten ist für dein Team nicht verfügbar.';

            return;
        }
        app(TeamSettingsService::class)->update($team, ['gerichte_station_id' => $id]);
        $this->meldung = $id === null ? 'Vorgabe für Gerichte entfernt.' : 'Posten für Gerichte gespeichert.';
    }

    /** Team-Standard-Topf-Deckel speichern — greift als Fallback, wenn Rezept/Posten keinen Deckel haben. */
    public function standardDeckelSpeichern(): void
    {
        $this->fehler = null;
        $this->meldung = null;

        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team im Zugriff.';

            return;
        }

        app(TeamSettingsService::class)->update($team, [
            'default_batch_max_kg' => $this->zahl($this->standardTopfKg),
            'default_batch_max_pieces' => $this->zahl($this->standardTopfStueck),
        ]);
        $this->meldung = 'Standard-Topf-Deckel gespeichert.';
    }

    public function create(): void
    {
        $this->fehler = null;
        $this->meldung = null;

        $name = trim($this->neu['name']);
        if ($this->versuche(fn ($svc, $team) => $svc->anlegen($team, $this->neu), 'Name ist Pflicht.') === null) {
            return;
        }

        $this->reset('neu');
        $this->neu = ['name' => '', 'group_name' => '', 'kapazitaet' => ''];
        $this->meldung = "«{$name}» angelegt.";
    }

    public function feldSetzen(int $id, string $feld, string $wert): void
    {
        $this->fehler = null;
        $this->meldung = null;

        if ($this->versuche(fn ($svc, $team) => $svc->feldSetzen($team, $id, $feld, $wert)) !== null) {
            $this->meldung = 'Gespeichert.';
        }
    }

    /**
     * Wochentag-Abweichung setzen (ISO 1=Mo…7=So). Leerer Wert = Abweichung löschen, der
     * Posten fällt an dem Tag auf seine Normalkapazität zurück. Gepflegt werden in der UI
     * nur Sa/So — das ist im Catering der reale Sonderfall.
     */
    public function wochentagSetzen(int $id, int $iso, string $wert): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->wochentagSetzen($team, $id, $iso, $wert)) !== null) {
            $this->meldung = 'Gespeichert.';
        }
    }

    /** Stufe 3 — Anzahl einer Rolle am Posten setzen (0/leer = Rolle raus). Leitet Kapazität + Kosten ab. */
    public function besetzungSetzen(int $id, int $roleId, string $wert): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->besetzungSetzen($team, $id, $roleId, $wert)) !== null) {
            $this->meldung = 'Besetzung gespeichert.';
        }
    }

    public function aktivToggle(int $id): void
    {
        $posten = $this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $id));
        if ($posten !== null) {
            $this->meldung = $posten->is_inactive ? "«{$posten->name}» stillgelegt." : "«{$posten->name}» wieder aktiv.";
        }
    }

    /**
     * Führt eine Schreib-Aktion des ProductionStationService aus (98 · 2.3a). Nur Posten des EIGENEN Teams sind
     * editierbar (D1) — die Regel liegt im Service; Ablehnungen landen als Meldung in $fehler, Ergebnis null.
     */
    private function versuche(callable $aktion, string $ohneTeam = 'Posten nicht im Schreibzugriff (D1).'): ?FoodAlchemistProductionStation
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = $ohneTeam;

            return null;
        }
        try {
            return $aktion(app(ProductionStationService::class), $team);
        } catch (GeerbtNurLesenException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
    }

    /** Dezimal-Eingabe (Topf-Deckel): leer ⇒ null, Komma erlaubt, ≥ 0. */
    private function zahl(string $roh): ?float
    {
        return ProductionStationService::zahl($roh);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.posten', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'posten' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)
                    ->orderBy('sort_order')->orderBy('name')->get()
                : collect(),
            'rollen' => $team !== null
                ? FoodAlchemistKitchenRole::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get()
                : collect(),
            'eigenesTeamId' => $team?->id,
            // Spec 83 B2: woher die Vorgabe "Posten für Gerichte" kommt (eigenes Team oder geerbt vom Oberteam)
            'gerichteVorgabeGeerbt' => $team !== null && ($q = app(TeamSettingsService::class)->quelleTeamId($team, 'gerichte_station_id')) !== null && (int) $q !== (int) $team->id,
        ]);
    }
}
