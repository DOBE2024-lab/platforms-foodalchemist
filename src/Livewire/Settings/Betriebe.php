<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\Plattform\OutletService;

/**
 * Spec 33 · P2 — Pflege der Betriebe/Standorte (Outlets).
 *
 * **Warum das hier entsteht.** `foodalchemist_outlets` gibt es seit Spec 19, das Model sagt
 * ausdrücklich „team-pflegbar über die Einstellungen" — nur wurde diese Oberfläche nie gebaut.
 * Ergebnis: die Tabelle war leer (0 Datensätze im Dev-Bestand), das Feld `outlet_id` an der
 * Speisekarte hatte nicht einmal ein Eingabefeld, und die Achse war damit tot.
 *
 * Mit Spec 33 trägt der Betrieb die **Betriebsbrille** der Portfolio-Übersicht — ohne gepflegte
 * Outlets bleibt sie leer. Die Pflege ist deshalb keine Zugabe, sondern Voraussetzung.
 *
 * Bewusst schlank gehalten (Muster {@see Einsatzorte}): Name, Farbe fürs Tag-Rendering,
 * Reihenfolge, aktiv/inaktiv. **Keine Hierarchie** (Region → Betrieb → Küche) — eine Ebene
 * darüber ist ein eigenes Vorhaben und würde hier nur halb entstehen.
 *
 * **Löschen ist bewusst nicht drin**: ein Outlet hängt an Ausgaben und Kapiteln. Statt einer
 * Kaskade, die stillschweigend Zuordnungen kappt, wird inaktiv geschaltet — dieselbe
 * Zurückhaltung wie beim Ausgabe-Status.
 */
class Betriebe extends Component
{
    /** Spec 65: Bereich settings.betriebe je Team — Zeilen-Speichern/Anlegen/Logo schreiben sofort, Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'betriebe';
    }

    use WithFileUploads;

    public ?int $editId = null;

    /** @var array{name:string,color:string,sort_order:int|string,vorlage:string} */
    public array $form = ['name' => '', 'color' => '', 'sort_order' => 100, 'vorlage' => ''];

    /** Präsentations-Logo je Betrieb (temporärer Upload) — ersetzt beim Betriebs-Link das Dokument-Logo. */
    public $logoUpload = null;

    public string $neuName = '';

    public ?string $fehler = null;

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function anlegen(): void
    {
        $this->fehler = null;
        $team = $this->team();
        $name = trim($this->neuName);
        if ($team === null || $name === '') {
            return;
        }

        try {
            app(OutletService::class)->anlegen($team, $name);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->neuName = '';
    }

    /** Spec 77c: Betrieb eines Standorts (Unter-Team) festlegen — leer = Standard (erbt komplett). */
    public function standortBetriebSetzen(int $unterTeamId, mixed $outletId): void
    {
        $this->fehler = null;
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\Plattform\StandortService::class)
                ->betriebZuordnen($team, $unterTeamId, $outletId === '' || $outletId === null ? null : (int) $outletId, Auth::user());
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function edit(int $id): void
    {
        $o = $this->eigenes($id);
        if ($o === null) {
            return;
        }
        $this->editId = $id;
        $this->form = [
            'name' => (string) $o->name,
            'color' => (string) ($o->color ?? ''),
            'sort_order' => (int) $o->sort_order,
            'vorlage' => (string) ($o->presentation_design ?? ''),
        ];
    }

    public function abbrechen(): void
    {
        $this->reset('editId', 'form', 'fehler', 'logoUpload');
    }

    /** Betriebs-Logo hochladen (sofort beim Auswählen) — ersetzt beim Betriebs-Link das Dokument-Logo. */
    public function updatedLogoUpload(): void
    {
        if ($this->schreibenAbgewiesen('updatedLogoUpload')) {   // Spec 65: Upload-Hook schreibt direkt
            $this->logoUpload = null;

            return;
        }
        $this->fehler = null;
        $o = $this->editId !== null ? $this->eigenes($this->editId) : null;
        $team = $this->team();
        if ($o === null || $team === null || $this->logoUpload === null) {
            return;
        }
        \Platform\FoodAlchemist\Support\BildUpload::pruefen($this, 'logoUpload');

        app(OutletService::class)->logoErsetzen($team, $o->id, $this->logoUpload);   // 98 · 2.3a: altes Logo weg, neues rein
        $this->logoUpload = null;
    }

    /** Betriebs-Logo entfernen → beim Betriebs-Link greift wieder das Dokument-Logo. */
    public function logoLoeschen(): void
    {
        $o = $this->editId !== null ? $this->eigenes($this->editId) : null;
        $team = $this->team();
        if ($o === null || $team === null) {
            return;
        }
        app(OutletService::class)->logoEntfernen($team, $o->id);
    }

    public function speichern(): void
    {
        $this->fehler = null;
        $o = $this->editId !== null ? $this->eigenes($this->editId) : null;
        $name = trim((string) ($this->form['name'] ?? ''));
        if ($o === null || $name === '') {
            return;
        }

        // 98 · 2.3a: Eindeutigkeit, Farb-/Vorlagen-Regeln und der Write liegen im OutletService.
        try {
            app(OutletService::class)->aktualisieren($this->team(), $o->id, $this->form);
        } catch (NichtGefundenException|GeerbtNurLesenException|NochVerwendetException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        // Ebene 2: Kosten-Overrides je Betrieb wohnen jetzt in „Herstellkosten & Zuschläge"
        // (Team/Betrieb-Wähler) — hier nur noch Identität, Farbe, Reihenfolge, Präsentations-Vorlage.
        $this->abbrechen();
    }

    /** Aktiv/inaktiv statt löschen — an einem Outlet hängen Ausgaben und Kapitel. */
    public function aktivUmschalten(int $id): void
    {
        $team = $this->team();
        if ($team !== null && $this->eigenes($id) !== null) {
            app(OutletService::class)->aktivUmschalten($team, $id);
        }
    }

    /** Nur team-EIGENE Outlets sind änderbar (Vokabular gehört dem Team, das es pflegt). */
    private function eigenes(int $id): ?FoodAlchemistOutlet
    {
        $team = $this->team();
        try {
            return $team === null ? null : app(OutletService::class)->eigenes($team, $id);
        } catch (NichtGefundenException) {
            return null;
        }
    }

    public function render()
    {
        $team = $this->team();
        $betriebe = $team === null
            ? collect()
            : FoodAlchemistOutlet::where('team_id', $team->id)
                ->orderBy('sort_order')->orderBy('name')->get();

        // Wo wird der Betrieb schon benutzt? Zeigt, was eine Deaktivierung berührt.
        $ids = $betriebe->pluck('id');
        $nutzung = [];
        foreach ([
            'foodalchemist_menu_cards' => 'Speisekarten',
            'foodalchemist_menu_plans' => 'Speisepläne',
            'foodalchemist_foodbooks' => 'Foodbooks',
            'foodalchemist_foodbook_chapters' => 'Kapitel',
        ] as $tabelle => $klartext) {
            if ($ids->isEmpty()) {
                break;
            }
            foreach (DB::table($tabelle)->whereIn('outlet_id', $ids)->whereNull('deleted_at')
                ->selectRaw('outlet_id, COUNT(*) as n')->groupBy('outlet_id')->get() as $r) {
                $nutzung[(int) $r->outlet_id][$klartext] = (int) $r->n;
            }
        }

        // Slice F: Präsentations-Vorlagen (Built-ins + team-eigene Designs) für die „Vorlage je Betrieb"-Wahl.
        $vorlagenOptionen = $team === null ? []
            : app(\Platform\FoodAlchemist\Services\PresentationDesignService::class)->pickerOptions($team, 'foodbook');

        // Betriebs-Logo-Vorschau (outlet_id → URL), für die Edit-Zeile.
        $media = app(FoodAlchemistMediaService::class);
        $logoUrls = [];
        foreach ($betriebe as $o) {
            if (($o->logo_context_file_id ?? null) || ($o->logo_path ?? null)) {
                $logoUrls[(int) $o->id] = $media->url($o->logo_context_file_id, $o->logo_path);
            }
        }

        return view('foodalchemist::livewire.settings.betriebe', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'betriebe' => $betriebe,
            'nutzung' => $nutzung,
            'vorlagenOptionen' => $vorlagenOptionen,
            'logoUrls' => $logoUrls,
            'standorte' => $team === null ? [] : app(\Platform\FoodAlchemist\Services\Plattform\StandortService::class)->unterTeams($team),
        ]);
    }
}
