<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Formular-Zustand der Darreichungs-Zeilen im Gericht-Modal (Reiter Kalkulation).
 *
 * Aus VkModal herausgelöst (Spec 85 §1), damit der Festpreis-Weg dazukommen konnte:
 *  - Zahlen stehen deutsch im Feld („3,90“ statt „3.9“). Der Service liest das Komma schon.
 *  - Ein fester Preis speichert beim Verlassen von Preis- ODER Begründungsfeld, sobald beide
 *    gefüllt sind (Dominique 13.11.2026). Fehlt eins, wird nicht gespeichert und kein Fehler
 *    gesetzt — die Zeile zeigt „Preis fehlt“ bzw. „Begründung fehlt“.
 *
 * Erwartet in der Komponente: $darForm, $darDeltaOffen, $darNeueForm, $recipeId,
 * darreichungSpeichern(), savedToast().
 */
trait HatDarreichungsFormular
{
    public ?int $darAktiv = null;

    public array $darZeitForm = [];

    #[\Livewire\Attributes\Locked]
    public array $darZeitStart = [];

    /** Portionen der Zeitvorschau (Entscheid 19.11.2026): nur Vorschau, wird nie gespeichert. Freitext aus dem Feld. */
    public $darVorschauPortionen = 100;

    /** Vorschau-Portionen als Zahl: Komma erlaubt, leer/ungültig = 100, begrenzt auf 1–100.000. */
    private function vorschauPortionen(): float
    {
        $wert = str_replace(',', '.', trim((string) $this->darVorschauPortionen));

        return is_numeric($wert) && is_finite((float) $wert) ? max(1.0, min(100000.0, (float) $wert)) : 100.0;
    }

    private function darZeitEntwuerfe(): array
    {
        return array_filter($this->darZeitForm, fn ($zeilen, $id) => isset($this->darForm[$id])
            && $zeilen != ($this->darZeitStart[$id] ?? []), ARRAY_FILTER_USE_BOTH);
    }

    private function darZeitEntwuerfeSpeichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $svc = app(\Platform\FoodAlchemist\Services\DarreichungService::class);
        $normal = [];
        foreach ($this->darZeitEntwuerfe() as $id => $zeilen) {
            $normal[$id] = app(\Platform\FoodAlchemist\Services\DarreichungsZeitService::class)->normalisieren($zeilen);
        }
        foreach ($normal as $id => $zeilen) {
            $svc->aktualisieren($team, (int) $id, ['arbeitszeiten' => $zeilen]);
        }
    }

    public function updatedDarZeitForm(mixed $value, string $key): void
    {
        $teile = explode('.', $key);
        if (count($teile) === 3 && isset($this->darZeitForm[$teile[0]][$teile[1]])) {
            unset($this->darZeitForm[$teile[0]][$teile[1]]['quelle'], $this->darZeitForm[$teile[0]][$teile[1]]['confidence']);
            if ($teile[2] === 'use_case') {
                $basis = ['abfuellen' => 'einheit', 'anrichten_teller' => 'portion', 'portionieren_platte' => 'behaelter', 'stueck' => 'stueck', 'pauschale' => 'auftragszeile'][$value] ?? null;
                if ($basis !== null && $this->darZeitForm[$teile[0]][$teile[1]]['basis'] !== $basis) {
                    $this->darZeitForm[$teile[0]][$teile[1]]['basis'] = $basis;
                    $this->darZeitForm[$teile[0]][$teile[1]]['wert'] = '';
                }
            } elseif ($teile[2] === 'basis') {
                $this->darZeitForm[$teile[0]][$teile[1]]['wert'] = '';
            }
        }
    }

    public function darZeitVorschlagen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $dar = $team !== null ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::visibleToTeam($team)
            ->where('recipe_id', $this->recipeId)->find($id) : null;
        if ($dar === null || ! $dar->recipe->isOwnedBy($team)) {
            $this->fehler = 'Zeiten können nur für eine eigene Darreichung vorgeschlagen werden.';
            return;
        }
        try {
            $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('darreichung.zeiten', [
                'gericht' => $dar->recipe->name, 'darreichung' => $dar->name,
                'servierform' => $dar->servingForm?->label, 'gefaess' => $dar->vessel?->name,
                'gramm_je_einheit' => $dar->quantity_per_unit_g, 'einheiten_je_portion' => $dar->unit_count,
                'komponenten' => $dar->recipe->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->display_name)->all(),
            ], ['target_table' => $dar->getTable(), 'target_id' => $dar->id]);
            $zeilen = app(\Platform\FoodAlchemist\Services\DarreichungsZeitService::class)->normalisieren($v->werte['arbeitszeiten'] ?? null);
            $this->darZeitForm[$id] = array_map(fn ($z) => $z + ['quelle' => 'ki_vorschlag', 'confidence' => $v->confidence], $zeilen);
            $this->fehler = null;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function darZeitHinzu(int $id): void
    {
        if (isset($this->darForm[$id])) {
            $this->darZeitForm[$id][] = ['use_case' => 'abfuellen', 'basis' => 'einheit', 'ort' => 'kueche', 'wert' => ''];
        }
    }

    public function darZeitenSpeichern(int $id): void
    {
        if (isset($this->darForm[$id])) {
            $this->darServiceCall(fn ($svc, $team) => $svc->aktualisieren($team, $id, ['arbeitszeiten' => $this->darZeitForm[$id] ?? []]));
        }
    }

    public function darStandardmenge(int $id, bool $verwenden): void
    {
        $this->darServiceCall(fn ($svc, $team) => $svc->setzeStandardmenge($team, $id, $verwenden));
    }

    public function darZeitEntfernen(int $id, int $index): void
    {
        if (! isset($this->darZeitForm[$id][$index])) {
            return;
        }
        unset($this->darZeitForm[$id][$index]);
        $this->darZeitForm[$id] = array_values($this->darZeitForm[$id]);
        $this->darZeitenSpeichern($id);
    }

    private function ladeDarreichungen(): void
    {
        $this->darForm = [];
        $this->darZeitForm = [];
        $this->darZeitStart = [];
        $this->darDeltaOffen = null;
        $this->darNeueForm = '';
        if ($this->recipeId === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        $defaultMarkupClassId = $team !== null
            ? app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->defaultMarkupClassId($team)
            : null;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::with('recipe')->where('recipe_id', $this->recipeId)->get() as $d) {
            $this->darForm[$d->id] = [
                'name' => $d->name,
                'ausgabe_station_id' => $d->ausgabe_station_id,
                'menge_vom_standard' => $d->menge_vom_standard,
                'quantity_per_unit_g' => self::darZahl($d->quantity_per_unit_g),
                'unit_count' => self::darZahl($d->unit_count),
                'unit_vocab_id' => $d->unit_vocab_id,
                'markup_class_id' => $d->markup_class_id ?? $defaultMarkupClassId,
                'price_mode' => $d->price_mode,
                'sales_net' => self::darZahl($d->sales_net, 2),
                'vat_profile_key' => $d->vat_profile_key,
                'price_override_reason' => $d->price_override_reason,
                'price_override_expires_at' => $d->price_override_expires_at?->format('Y-m-d'),
                'vessel_gp_id' => $d->vessel_gp_id,
                'vessel_cost_type' => $d->vessel_cost_type,   // Art des Gefäßes übersteuern (einweg|mehrweg|extern), leer = Eintrag des Teams
                'serving_vehicle_vocab_id' => $d->serving_vehicle_vocab_id,
                // Spec 83 B3i/B3l: Anrichten je Darreichung (Einheit aus der Form) + Pauschale je Auftragszeile
                'anrichten_wert' => self::darZahl($d->anrichten_wert),
                'anrichten_pauschal_min' => self::darZahl($d->anrichten_pauschal_min),
            ];
            $this->darZeitForm[$d->id] = app(\Platform\FoodAlchemist\Services\DarreichungsZeitService::class)->formular($d);
            $this->darZeitStart[$d->id] = $this->darZeitForm[$d->id];
            if ($d->is_standard && $this->darAktiv === null) {
                $this->darAktiv = (int) $d->id;
            }
        }
        if (! isset($this->darForm[$this->darAktiv])) {
            $this->darAktiv = array_key_first($this->darForm);
        }
    }

    /** Deutsch fürs Eingabefeld: feste Nachkommastellen (Preis) oder ohne überflüssige Nullen. */
    private static function darZahl(?float $wert, ?int $stellen = null): ?string
    {
        if ($wert === null) {
            return null;
        }
        if ($stellen !== null) {
            return number_format($wert, $stellen, ',', '');
        }

        return rtrim(rtrim(number_format($wert, 3, ',', ''), '0'), ',');
    }

    /**
     * Preis- oder Begründungsfeld einer festen Zeile verlassen (wire:change wie die übrigen Zeilenfelder,
     * damit Bearbeitungssperre und Handy-Lesemodus über den globalen call-Haken greifen).
     */
    public function darreichungFestpreisFeld(int $id): void
    {
        $zeile = $this->darForm[$id] ?? null;
        if ($zeile === null || ! in_array($zeile['price_mode'] ?? null, ['fixed', 'manuell'], true)
            || self::darFestpreisFehlt($zeile) !== null) {
            return;                                              // unvollständig: Hinweis an der Zeile, kein Fehler
        }
        $this->darreichungSpeichern($id);
        if ($this->fehler === null) {
            $this->savedToast('Festpreis gespeichert');
        }
    }

    /**
     * Kopf-Felder des Gerichts, die `SalesRecipeService::updateVk` in die Standard-Darreichung spiegelt
     * (`syncStandardDarreichung`). Der Kopf lädt sie einmal beim Öffnen; ändert der Mensch danach die Darreichungs-Zeile,
     * stünde im Kopf ein alter Stand. `sales_unit_count` gehört dazu: geht es mit, rechnet der Sync die Grammatur der
     * Standard-Darreichung aus Ertrag und Anzahl neu.
     */
    private const KOPF_SPIEGEL = ['sales_quantity_per_unit_g', 'sales_unit_count', 'sales_unit_vocab_id', 'container_warm_vocab_id', 'container_cold_vocab_id'];

    /**
     * Speichern im Kopf (13.11.2026): ein gespiegeltes Feld geht nur mit, wenn es im Kopf wirklich geändert wurde.
     * Vorher schrieb „Speichern“ den ganzen Kopf zurück und überschrieb damit, was zwischenzeitlich in der Zeile der
     * Standard-Darreichung gesetzt worden war (Dominique: Preisklasse in der Zeile gewählt, nach dem Speichern weg).
     * „Geändert“ heißt: weicht vom gespeicherten Wert am Gericht ab.
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    private function ohneUnveraenderteSpiegelFelder(array $update): array
    {
        $team = Auth::user()?->currentTeamRelation;
        $gericht = $team !== null && $this->recipeId !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->whereKey($this->recipeId)->first(self::KOPF_SPIEGEL) : null;
        if ($gericht === null) {
            return $update;
        }
        $norm = static fn ($v) => $v === null || $v === '' ? null : (is_numeric($v) ? (float) $v : (string) $v);
        foreach (self::KOPF_SPIEGEL as $feld) {
            if (array_key_exists($feld, $update) && $norm($update[$feld]) === $norm($gericht->{$feld})) {
                unset($update[$feld]);
            }
        }

        return $update;
    }

    /** „Preis fehlt“ / „Begründung fehlt“ für eine feste Zeile, sonst null. */
    public static function darFestpreisFehlt(array $zeile): ?string
    {
        return match (true) {
            trim((string) ($zeile['sales_net'] ?? '')) === '' => 'Preis fehlt',
            trim((string) ($zeile['price_override_reason'] ?? '')) === '' => 'Begründung fehlt',
            default => null,
        };
    }
}
