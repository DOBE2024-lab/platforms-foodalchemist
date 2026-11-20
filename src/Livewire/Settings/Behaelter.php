<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Services\Einstellungen\BehaelterService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * R5 (Dominique): Behälter & Geräte als EIGENE Settings-Seite mit Anlegen —
 * die 3 Container-Vokabulare (D-6 §4.6) PLUS das Koch-Equipment der
 * Basisrezepte (D-5 §2.3) an einem Ort. Lösch-Schutz V-06: nur deaktivieren.
 * 98 · 2.3a: alle Schreibwege samt Regeln liegen im BehaelterService — hier nur Formular und Meldungen.
 */
class Behaelter extends Component
{
    /** Spec 65: Bereich settings.behaelter je Team — Sofort-Aktionen, Leiste Bearbeiten → Fertig. (Zeilen-Abbrechen heißt zeileAbbrechen — bearbeitenAbbrechen gehört der Sperre.) */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'behaelter';
    }

    /** Whitelist: vokabular-key => [tabelle, label, hat kapazitaet_kg] — die Regeln dazu im BehaelterService. */
    public const VOKABULARE = BehaelterService::VOKABULARE;

    /** Spec 51: Felder, die nur der Behälter-Katalog trägt (siehe BehaelterService). */
    public const BEHAELTER_FELDER = BehaelterService::BEHAELTER_FELDER;

    public const FAMILIEN = ['GN', 'EN600x400', 'Eimer', 'Kanne', 'Schale', 'Blech', 'Kiste', 'Traeger', 'frei'];

    /** @var array<string, array<string, mixed>> Add-Form je Vokabular */
    public array $neu = [];

    /** Offene Bearbeitung eines Behälters (leer = keine). */
    public array $edit = [];

    public ?int $editId = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        foreach (array_keys(self::VOKABULARE) as $key) {
            $this->neu[$key] = $this->leereForm($key);
        }
    }

    /**
     * Führt eine Service-Aktion aus; Ablehnungen landen als Meldung in $fehler. »Gibt es nicht« bleibt still
     * (wie vor 98 · 2.3a), wenn $stillBeiFehlend. Erfolg setzt $fehler NICHT zurück — das entscheidet der Aufrufer.
     */
    private function versuche(callable $aktion, bool $stillBeiFehlend = true): mixed
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            return $aktion(app(BehaelterService::class), $team) ?? true;
        } catch (NichtGefundenException $e) {
            if (! $stillBeiFehlend) {
                $this->fehler = $e->getMessage();
            }
        } catch (NichtGefundenException|GeerbtNurLesenException|NochVerwendetException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();
        }

        return null;
    }

    public function create(string $vokabular): void
    {
        $name = $this->versuche(fn ($svc, $team) => $svc->anlegen($team, $vokabular, $this->neu[$vokabular] ?? []));
        if ($name === null) {
            return;
        }

        $this->neu[$vokabular] = $this->leereForm($vokabular);
        $this->fehler = null;
        $this->meldung = "«{$name}» angelegt.";
    }

    /** Tooltip einer Katalog-Zeile: was gepflegt ist, sieht man sonst nirgends. */
    public static function titel(object $zeile): string
    {
        $teile = [$zeile->slug];

        if (($zeile->laenge_mm ?? null) !== null && ($zeile->breite_mm ?? null) !== null) {
            $mm = fn ($w) => rtrim(rtrim(number_format((float) $w, 1, ',', ''), '0'), ',');
            $teile[] = $mm($zeile->laenge_mm).'×'.$mm($zeile->breite_mm)
                .(($zeile->tiefe_mm ?? null) !== null ? '×'.$mm($zeile->tiefe_mm) : '').' mm';
        }
        foreach (['volumen_l' => 'l', 'kapazitaet_kg' => 'kg', 'max_fuellgewicht_kg' => 'kg max'] as $feld => $einheit) {
            if (($zeile->{$feld} ?? null) !== null) {
                $teile[] = rtrim(rtrim(number_format((float) $zeile->{$feld}, 3, ',', '.'), '0'), ',').' '.$einheit;
            }
        }
        if (($zeile->ist_traeger ?? false)) {
            $teile[] = 'Träger'.(($zeile->traeger_plaetze ?? null) !== null ? " ({$zeile->traeger_plaetze} Plätze)" : '');
        }
        $eignung = ($zeile->eignung ?? null) !== null ? (json_decode((string) $zeile->eignung, true) ?: []) : [];
        $teile[] = $eignung === [] ? 'Freigaben nicht gepflegt' : 'für '.implode(', ', $eignung);

        return implode(' · ', $teile);
    }

    /** Leere Formularzeile — Behälter tragen mehr Felder als die anderen Vokabulare. */
    private function leereForm(string $vokabular): array
    {
        $form = ['name' => '', 'group_name' => '', 'kapazitaet_kg' => ''];
        if (! (self::VOKABULARE[$vokabular]['kapazitaet'] ?? false)) {
            return $form;
        }

        foreach (self::BEHAELTER_FELDER as $feld) {
            $form[$feld] = $feld === 'ist_traeger' ? false : '';
        }
        $form['nutzfaktor'] = '0,85';
        $form['eignung'] = [];

        return $form;
    }

    /** Bestandszeilen müssen pflegbar sein — sonst bleibt der Katalog auf dem Import stehen. */
    public function bearbeitenStart(int $id): void
    {
        $zeile = $this->versuche(fn ($svc, $team) => $svc->eigenerBehaelter($team, $id));
        if ($zeile === null) {
            return;
        }

        $zahl = fn ($w) => $w === null ? '' : rtrim(rtrim(number_format((float) $w, 3, ',', ''), '0'), ',');

        $this->editId = $id;
        $this->edit = [
            'name' => $zeile->name,
            'group_name' => $zeile->group_name ?? '',
            'kapazitaet_kg' => $zahl($zeile->kapazitaet_kg),
            'eignung' => $zeile->eignung !== null ? (json_decode((string) $zeile->eignung, true) ?: []) : [],
        ];
        foreach (self::BEHAELTER_FELDER as $feld) {
            $roh = $zeile->{$feld} ?? null;
            $this->edit[$feld] = match (true) {
                $feld === 'ist_traeger' => (bool) $roh,
                in_array($feld, BehaelterService::ZAHLEN, true) => $zahl($roh),
                default => (string) ($roh ?? ''),
            };
        }
        $this->fehler = null;
    }

    public function zeileAbbrechen(): void
    {
        $this->editId = null;
        $this->edit = [];
    }

    public function bearbeitenSpeichern(): void
    {
        if ($this->editId === null) {
            return;
        }
        // Owner-Guard auch am Write (im Service) — editId ist vom Client setzbar.
        $name = $this->versuche(fn ($svc, $team) => $svc->aktualisieren($team, (int) $this->editId, $this->edit), false);
        if ($name === null) {
            return;
        }

        $this->meldung = "«{$name}» gespeichert.";
        $this->fehler = null;
        $this->zeileAbbrechen();
    }

    /** V-06: nur deaktivieren — inaktive bleiben an Rezepten sichtbar. */
    public function toggleInactive(string $vokabular, int $id): void
    {
        if ($this->versuche(fn ($svc, $team) => $svc->aktivUmschalten($team, $vokabular, $id)) !== null) {
            $this->meldung = 'Aktualisiert — inaktive Einträge bleiben an Rezepten sichtbar (V-06).';
        }
    }

    /** Phase 5: hart löschen, wenn von keinem Rezept genutzt (sonst locked → deaktivieren). */
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
                )->orderBy('group_name')->orderBy('sort_order')->orderBy('name')->get(),
            ];
        }

        return view('foodalchemist::livewire.settings.behaelter', ['sperr' => $this->sperrZustand(), 'listen' => $listen]);
    }
}
