<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M3-03/05/07 / P-1: GP-DetailPanel — eigene Livewire-Komponente in der rechten
 * Page-Sidebar (Platzierungs-Entscheid), hört auf `gp-selected` (kein Full-Reload).
 * Sektionen (Allergene/Zusatzstoffe/Nährwerte/LAs) laden LAZY; der Aufklapp-Zustand
 * übersteht den GP-Wechsel (Kontext-Erhalt-Gebot).
 *
 * LA-Aktionen (M3-07): global (Lead setzen, lösen, verknüpfen) nur fürs Kurations-
 * Team (canCurate); team-eigen (Sperre, Pin — V-27-Overlay) für jedes Team.
 */
class DetailPanel extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65
    use \Platform\FoodAlchemist\Livewire\Concerns\HatGpAromaKi;            // Aroma: Anker + Profil per KI (ein Weg mit dem GP-Dialog)
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;        // #1003: am Handy nur lesen

    /** #1003: am Handy laufen Auswahl und Aufklappen; Verknüpfen, Lead, KI und Löschen bleiben am Computer. */
    public function handyLeseMethoden(): array
    {
        return ['zeige', 'ankerNetzUmschalten'];
    }

    /** Spec 65: gleiche Sperre wie der GP-Editor (Ziel gp) — auch eingebettet als Kartei im GP-Dialog. */
    protected function sperrZiel(): ?array
    {
        return $this->gpId !== null ? ['gp', $this->gpId] : null;
    }

    /**
     * Ohne Sperre: Anzeige/Navigation (GP wechseln, Anker-Netz aufklappen) und das Verwerfen transienter
     * Vorschläge (KI-Schätzung, LA-Kandidaten, Ersatz-Vorschläge) — nichts davon schreibt.
     */
    protected function sperrFreiExtra(): array
    {
        // Spec 67: Stammplatz ist Betriebs-, nicht GP-Stammdatum — ohne „Bearbeiten“ setzbar.
        return ['zeige', 'ankerNetzUmschalten', 'ersatzKiVerwerfen', 'laVorschlaegeVerwerfen', 'kiVerwerfen', 'stammplatzSetzen', 'lagerartikelSetzen', 'gefaessKostenartSetzen'];
    }

    public ?int $gpId = null;

    public string $laSuche = '';

    /** Ersatz-Logik: Suchtext für die Gegenseite (GP/Rezept) im Verknüpfen-Feld. */
    public string $ersatzSuche = '';

    /** @var ?list<array{kind:string,id:int,name:string,score:float,reason:string,supplier:?string}> */
    public ?array $ersatzKiVorschlaege = null;

    /** Verwaltung: Suchtext für das Ersetzungsziel („GP in allen Rezepten tauschen"). */
    public string $tauschSuche = '';

    /** @var list<array{id: int, bezeichnung: string, grund: string}> Artikel, die der Allergen-Guard beim Ersetzen am Quell-GP gelassen hat */
    public array $abgelehnteArtikel = [];

    public ?string $fehler = null;

    /** Erfolgs-Feedback (z.B. nach Rezept-Ersetzung) — grünes Pendant zu $fehler. */
    public ?string $hinweis = null;

    /** Spec 53 Paket J: welcher Anker-Chip die „weitere GPs mit diesem Anker"-Liste aufklappt. */
    public ?int $ankerNetzOffenId = null;

    public function ankerNetzUmschalten(int $ankerId): void
    {
        $this->ankerNetzOffenId = $this->ankerNetzOffenId === $ankerId ? null : $ankerId;
    }

    /** Eingebettet im GP-Modal (recipe-modal-Muster): ohne Panel-Chrome, section = eine Kartei. */
    public bool $embedded = false;

    /** Nur diese Sektion rendern (null = alle, Sidebar-Vollansicht): las|allergene|zusatzstoffe|naehrwerte. */
    public ?string $section = null;

    public function mount(?int $gpId = null, bool $embedded = false, ?string $section = null): void
    {
        $this->gpId = $gpId;
        $this->embedded = $embedded;
        $this->section = $section;
    }

    #[On('gp-selected')]
    public function zeige(int $id): void
    {
        if ($this->gpId !== $id) {
            $this->sperreBeiWechselFreigeben();   // Spec 65: Wechsel gibt eine hier geholte Sperre frei
        }
        $this->gpId = $id;
        $this->laSuche = '';
        $this->ersatzSuche = '';
        $this->ersatzKiVorschlaege = null;
        $this->tauschSuche = '';
        $this->abgelehnteArtikel = [];
        $this->fehler = null;
        $this->hinweis = null;
        $this->kiVorschlag = null;
        $this->laKandidaten = null;
        $this->platzhalterFehler = null;
    }

    // ── Ersatz-Logik (make-or-buy + Artikel-Ersatz): Katalog am GP pflegen ──

    public function ersatzVerknuepfen(string $kind, int $id): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        try {
            $ki = collect($this->ersatzKiVorschlaege ?? [])->first(
                fn (array $c) => $c['kind'] === $kind && (int) $c['id'] === $id
            );
            app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)
                ->verknuepfe(
                    Auth::user()->currentTeamRelation,
                    'gp', $gp->id, $kind, $id,
                    notes: $ki['reason'] ?? null,
                    matchConfidence: isset($ki['score']) ? (float) $ki['score'] : null,
                );
            $this->ersatzSuche = '';
            $this->ersatzKiVorschlaege = collect($this->ersatzKiVorschlaege ?? [])
                ->reject(fn (array $c) => $c['kind'] === $kind && (int) $c['id'] === $id)
                ->values()->all();
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function ersatzKiRelevant(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        $this->ersatzKiVorschlaege = app(\Platform\FoodAlchemist\Services\ReplacementSuggestionService::class)
            ->forGp(Auth::user()->currentTeamRelation, $gp);
        if ($this->ersatzKiVorschlaege === []) {
            $this->fehler = 'Keine fachlich belastbare Ersatz-Alternative im aktuellen Datenbestand gefunden.';
        }
    }

    public function ersatzKiVerwerfen(): void
    {
        $this->ersatzKiVorschlaege = null;
    }

    public function ersatzLaAlsGpAnlegen(int $laId): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        $candidate = collect($this->ersatzKiVorschlaege ?? [])->first(
            fn (array $c) => $c['kind'] === 'supplier_item' && (int) $c['id'] === $laId
        );
        if ($candidate === null) {
            $this->fehler = 'Der Lieferantenartikel gehört nicht mehr zum geprüften Vorschlag.';

            return;
        }
        $this->dispatch(
            'gp-modal.oeffnen',
            id: null,
            laId: $laId,
            autoSuggest: true,
            equivalentSourceKind: 'gp',
            equivalentSourceId: (int) $gp->id,
            equivalentReason: (string) $candidate['reason'],
            equivalentConfidence: (float) $candidate['score'],
        );
    }

    public function ersatzLoesen(int $equivId): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)
            ->loese(Auth::user()->currentTeamRelation, $equivId);
    }

    // ── M3-07: LA-Aktionen ──────────────────────────────────────────────

    /** R9.2 (E5): optionaler Grund für den manuellen Lead-Override → protokolliert via gp_la_preferences.reason (LogsActivity). */
    public string $leadReason = '';

    public function leadSetzen(int $laId): void
    {
        $reason = trim($this->leadReason) ?: null;
        // R9.2: Override mit Begründung + Recompute der GP-nutzenden Rezepte (neuer Lead-EK schlägt durch).
        $this->laAktion(fn ($svc, $gp, $team) => $svc->setLeadLa($team, $gp, $laId, $reason, recompute: true), nurKurator: true);
        $this->leadReason = '';
    }

    public function sperreToggle(int $laId, bool $locked): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->sperren($team, $gp, $laId, $locked));
    }

    public function pinToggle(int $laId, bool $pinnen): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->pinnen($team, $gp, $pinnen ? $laId : null));
    }

    public function loesen(int $laId): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->entknuepfen($team, $gp, $laId), nurKurator: true);
        $this->dispatch('gp-las-geaendert'); // Browser-Tabelle (LAs-Spalte) kann reagieren
    }

    public function verknuepfe(int $laId): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->verknuepfen($team, $gp, $laId), nurKurator: true);
        $this->laSuche = '';
        $this->laKandidaten = null;
        $this->dispatch('gp-las-geaendert');
    }

    // ── R12 (Jarvis): ✨ KI-Vorschlag — unverknüpfte LA-Kandidaten zum GP ──

    /** @var ?array<int, array{id:int, designation:string, supplier:?string, score:float}> */
    public ?array $laKandidaten = null;

    public function laVorschlaege(): void
    {
        $gp = $this->kuratierbaresGp();                            // verknuepfen ist Katalog-Aktion (D1)
        if ($gp === null) {
            return;
        }
        $team = Auth::user()->currentTeamRelation;
        $this->laKandidaten = app(LeadLaService::class)->kandidatenFuerGp($team, $gp)
            ->map(fn ($la) => [
                'id' => (int) $la->id,
                'designation' => (string) $la->designation,
                'supplier' => $la->supplier_name,
                'score' => round((float) $la->match_score, 2),
            ])->all();
        if ($this->laKandidaten === []) {
            $this->fehler = 'Keine unverknüpften Artikel gefunden, die zum GP-Namen passen.';
            $this->laKandidaten = null;
        }
    }

    public function laVorschlaegeVerwerfen(): void
    {
        $this->laKandidaten = null;
    }

    // ── R10 (Ist-Feature): ✨ Allergene/Nährwerte per KI schätzen, wenn keine LA-Daten ──

    /** @var ?array{typ: string, werte: array, confidence: float} Vorschau — NICHTS persistiert (GL-07) */
    public ?array $kiVorschlag = null;

    // kiAllergene() entfernt (Dominique 2026-10-13): Allergene kommen nur aus den Lieferantenartikeln.
    // Bestehende KI-Overrides (allergens_source = ki) bleiben sichtbar und lassen sich zurücksetzen.

    public function kiNaehrwerte(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        try {
            $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('gp.naehrwerte', [
                'name' => $gp->name, 'condition' => $gp->condition, 'commodity_group' => $gp->commodity_group?->name,
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $werte = [];
        foreach (['kcal', 'protein_g', 'fat_g', 'carbs_g', 'salt_g'] as $feld) {
            $wert = $v->werte[$feld] ?? null;
            if (is_numeric($wert) && (float) $wert >= 0) {
                $werte[$feld] = round((float) $wert, 2);
            }
        }
        if (! isset($werte['kcal'])) {                              // kcal = Leit-Indikator (GL-08)
            $this->fehler = 'KI lieferte keine verwertbaren Nährwerte (kcal fehlt) — echter Provider nötig.';

            return;
        }
        $this->kiVorschlag = ['type' => 'naehrwerte', 'werte' => $werte, 'confidence' => max(0.0, min(1.0, $v->confidence))];
    }

    /** Übernehmen = der EINE Schreib-Moment (GL-07): Override-Layer bzw. Fallback-Schicht. */
    public function kiUebernehmen(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null || $this->kiVorschlag === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\GpService::class)->kiVorschlagUebernehmen($gp, $this->kiVorschlag);   // 98 · 1.3e
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
        $this->kiVorschlag = null;
    }

    public function kiVerwerfen(): void
    {
        $this->kiVorschlag = null;                                 // reject lässt Fachdaten unberührt (GL-07)
    }

    // ── Verwaltung (2026-07-02): GP ersetzen & löschen — Löschen nur ohne Referenzen ──

    /** GP in allen Rezept-Zeilen durch $zielId ersetzen (Vorstufe zum Löschen). */
    public function gpErsetzen(int $zielId): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        $ziel = $team !== null ? FoodAlchemistGp::visibleToTeam($team)->find($zielId) : null;
        if ($ziel === null) {
            $this->fehler = 'Ziel-GP nicht gefunden.';

            return;
        }
        $this->abgelehnteArtikel = [];
        try {
            $ergebnis = app(\Platform\FoodAlchemist\Services\GpService::class)->ersetzeKomplett($team, $gp, $ziel);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->tauschSuche = '';
        $this->abgelehnteArtikel = $ergebnis['artikel_abgelehnt'];
        $teile = array_filter([
            $ergebnis['artikel_umgehaengt'] > 0 ? "{$ergebnis['artikel_umgehaengt']} Artikel" : null,
            $ergebnis['zeilen'] > 0 ? "{$ergebnis['zeilen']} Zeile(n) in {$ergebnis['rezepte']} Rezept(en)" : null,
        ]);
        $this->hinweis = ($teile !== [] ? implode(' und ', $teile) : 'Nichts')
            . " auf „{$ziel->name}\" umgehängt" . ($ergebnis['zeilen'] > 0 ? ' — Rezepte neu berechnet' : '')
            . ($ergebnis['artikel_abgelehnt'] !== [] ? '; ' . count($ergebnis['artikel_abgelehnt']) . ' Artikel blieben stehen (Allergen-Prüfung, unten aufgelistet)' : '') . '.';
        $this->dispatch('gp-gespeichert'); // Browser: Rezept-Zähler/Zeile aktualisieren
    }

    /** GP löschen (Soft-Delete) — Service blockt bei Referenzen. */
    public function gpLoeschen(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\GpService::class)->deleteGp($gp);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->bearbeitenBeenden();   // Spec 65: Sperre des gelöschten GP sofort frei
        $this->gpId = null;
        $this->dispatch('gp-geloescht');
    }

    /** Curate-Gate + frisches GP — KI-Schätzung ist eine globale Katalog-Aktion (D1). */
    protected function aromaGp(): ?FoodAlchemistGp
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null && $this->gpId !== null ? FoodAlchemistGp::visibleToTeam($team)->with('commodity_group')->find($this->gpId) : null;
    }

    protected function aromaMeldung(?string $fehler, ?string $hinweis = null): void
    {
        $this->fehler = $fehler;
        $this->hinweis = $hinweis;
    }

    private function kuratierbaresGp(): ?FoodAlchemistGp
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null && $this->gpId !== null
            ? FoodAlchemistGp::visibleToTeam($team)->with('commodity_group')->find($this->gpId)
            : null;
        if ($gp === null) {
            return null;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1).';

            return null;
        }

        return $gp;
    }

    private function laAktion(\Closure $aktion, bool $nurKurator = false): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null && $this->gpId !== null
            ? FoodAlchemistGp::visibleToTeam($team)->find($this->gpId)
            : null;
        if ($gp === null) {
            return;
        }
        if ($nurKurator && ! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1). Team-eigene Alternative: Sperre/Pin.';

            return;
        }

        try {
            $aktion(app(LeadLaService::class), $gp, $team);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Preis-Band über die LAs: min/max des Vergleichspreises in der DOMINANTEN Einheit
     * (Einheiten nie mischen — €/kg ≠ €/l ≠ €/Stk). Gesperrte LAs zählen nicht.
     *
     * @return array{min:float,max:float,unit:string,n:int,lead:?float}|null
     */
    private function preisBand(?\Illuminate\Support\Collection $kette, ?int $leadId): ?array
    {
        if ($kette === null) {
            return null;
        }
        $mitVp = $kette->filter(fn ($la) => $la->vergleichspreis !== null && ! $la->locked);
        if ($mitVp->isEmpty()) {
            return null;
        }
        $unit = $mitVp->groupBy(fn ($la) => $la->vergleichspreis['unit'])
            ->sortByDesc(fn ($g) => $g->count())->keys()->first();
        $inEinheit = $mitVp->filter(fn ($la) => $la->vergleichspreis['unit'] === $unit);
        $werte = $inEinheit->map(fn ($la) => (float) $la->vergleichspreis['value']);
        $lead = $inEinheit->firstWhere('id', $leadId);

        return [
            'min' => (float) $werte->min(),
            'max' => (float) $werte->max(),
            'unit' => (string) $unit,
            'n' => $werte->count(),
            'lead' => $lead !== null ? (float) $lead->vergleichspreis['value'] : null,
        ];
    }

    /** Spec 74: Lagerartikel (Grundvorrat) mit Mindest-/Sollbestand — Betriebsdatum, ohne „Bearbeiten“ pflegbar. */
    public array $lagerartikelForm = ['ist' => false, 'min' => '', 'soll' => ''];

    public ?string $lagerartikelFehler = null;

    public function lagerartikelSetzen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->gpId === null) {
            return;
        }
        $this->lagerartikelFehler = null;
        try {
            app(\Platform\FoodAlchemist\Services\LagerartikelService::class)->setzen($team, $this->gpId, (bool) $this->lagerartikelForm['ist'], $this->lagerartikelForm['min'], $this->lagerartikelForm['soll']);
            $this->dispatch('fa-saved', message: 'Lagerartikel gespeichert.', type: 'success');
        } catch (\RuntimeException $e) {
            $this->lagerartikelFehler = $e->getMessage();
        }
    }

    /** Art + Preis als Gefäß (17.11.2026): Betriebsdatum des Teams wie der Lagerartikel — ohne „Bearbeiten“, nur Non-Food. */
    public string $gefaessKostenart = '';

    public string $gefaessPreis = '';

    public ?string $gefaessKostenartFehler = null;

    public function gefaessKostenartSetzen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->gpId === null) {
            return;
        }
        $this->gefaessKostenartFehler = null;
        try {
            $n = app(\Platform\FoodAlchemist\Services\GefaessKostenartService::class)->setzen($team, $this->gpId, $this->gefaessKostenart, $this->gefaessPreis);
            $this->dispatch('fa-saved', message: 'Gefäß gespeichert'.($n > 0 ? " — {$n} Darreichungen neu gerechnet." : '.'), type: 'success');
        } catch (\RuntimeException $e) {
            $this->gefaessKostenartFehler = $e->getMessage();
        }
    }

    /**
     * Platzhalter (19.11.2026): Allergene, Zusatzstoffe, Nährwerte von Hand — Formular je GP.
     *
     * @var array{gp?: int, allergene?: array<string,string>, zusatzstoffe?: array<string,string>, naehrwerte?: array<string,string>}
     */
    public array $platzhalterForm = [];

    public ?string $platzhalterFehler = null;

    /** Formular wie Leitungswasser vorbelegen: alle Allergene nicht enthalten, keine Zusatzstoffe (schreibt nichts, erst „Speichern“). */
    public function platzhalterAllesFreiVorbelegen(): void
    {
        foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $this->platzhalterForm['allergene'][$feld] = 'nicht_enthalten';
        }
        foreach (array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE) as $stoff) {
            $this->platzhalterForm['zusatzstoffe'][$stoff] = 'nein';
        }
    }

    /** Werte von Hand speichern — der Dienst lehnt Nicht-Platzhalter ab (KeinPlatzhalterException), nicht nur die Anzeige. */
    public function platzhalterDeklarationSpeichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->gpId === null) {
            return;
        }
        $this->platzhalterFehler = null;
        try {
            $eingabe = [
                'allergene' => (array) ($this->platzhalterForm['allergene'] ?? []),
                'zusatzstoffe' => (array) ($this->platzhalterForm['zusatzstoffe'] ?? []),
            ];
            // Nährwerte nur mitschicken, wenn etwas eingetragen ist oder sie schon von Hand stehen — leere Felder löschen sonst eine KI-Schätzung
            $naehr = (array) ($this->platzhalterForm['naehrwerte'] ?? []);
            if (collect($naehr)->contains(fn ($v) => trim((string) $v) !== '')
                || FoodAlchemistGp::visibleToTeam($team)->whereKey($this->gpId)->value('nutri_source') === 'manual') {
                $eingabe['naehrwerte'] = $naehr;
            }
            $r = app(\Platform\FoodAlchemist\Services\PlatzhalterDeklarationService::class)->setzen($team, $this->gpId, $eingabe);
            $this->platzhalterForm = [];   // beim nächsten Rendern aus dem gespeicherten Stand neu
            $this->dispatch('fa-saved', message: 'Platzhalter gespeichert' . ($r['rezepte_neu_gerechnet'] > 0 ? " — {$r['rezepte_neu_gerechnet']} Rezepte neu gerechnet." : '.'), type: 'success');
        } catch (\RuntimeException $e) {
            $this->platzhalterFehler = $e->getMessage();
        }
    }

    /** Formular einmal je GP aus dem gespeicherten Stand füllen. */
    private function platzhalterFormLaden(FoodAlchemistGp $gp): array
    {
        $stand = app(\Platform\FoodAlchemist\Services\PlatzhalterDeklarationService::class)->lesen($gp);
        if (($this->platzhalterForm['gp'] ?? null) !== $gp->id) {
            $this->platzhalterForm = [
                'gp' => $gp->id,
                'allergene' => array_map(fn ($v) => (string) $v, $stand['allergene']),
                'zusatzstoffe' => array_map(fn ($v) => (string) $v, $stand['zusatzstoffe']),
                'naehrwerte' => array_map(fn ($v) => $v === null ? '' : str_replace('.', ',', (string) $v), $stand['naehrwerte']),
            ];
        }

        return $stand;
    }

    /** Spec 67: Stammplatz dieses Grundprodukts in einem Lagerort setzen ('' = entfernen). */
    public function stammplatzSetzen(int $locationId, $binId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->gpId === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class)
                ->zuordnen($team, $locationId, [$this->gpId], $binId !== '' && $binId !== null ? (int) $binId : null);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // fremder Lagerort/Stellplatz — still ignorieren, die Anzeige zeigt den echten Stand
        }
    }

    public function render(GpAggregateService $aggregate, LeadLaService $leads)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = null;
        if ($this->gpId !== null && $team !== null) {
            $gp = FoodAlchemistGp::visibleToTeam($team)
                ->with(['commodity_group', 'preferredCountUnit', 'leadLa', 'derivedFrom'])
                ->find($this->gpId);
        }

        // R9 (Dominique: «Anzeige komplett Bug»): Sektionen IMMER sichtbar — die
        // Lazy-Klapperei der Ist-Abnahme versteckte alle Inhalte und Aktionen.
        // Aggregate sind DB-MAX/Ø-Queries über die LAs (M8-04: Panels 2–16 ms).
        // N+1-Gating (2026-08): Das GP-Modal bettet 5 detail-panel-Instanzen mit je EINER
        // fixen $section ein (alle gleichzeitig via x-show im DOM) — ohne Gating berechnet
        // jede Instanz ALLE Aggregate = 5x Last. Flags entscheiden nur, OB die (teure)
        // Berechnung laeuft; die @if($section)-Guards im Blade steuern weiter die Sichtbarkeit.
        // null (Sidebar-Vollmodus) => alle Flags true => laedt ALLES wie bisher (Verhalten 1:1).
        $vollmodus = $this->section === null;
        $brauchtLas = $vollmodus || $this->section === 'las';                 // kette, effektiverLead, leadSteuerung, preisBand, preisTrend, verknuepfSuche, verwendungen
        $brauchtAllergene = $vollmodus || $this->section === 'allergene';     // allergene, allergenKonfidenz (auch null-Cockpit)
        $brauchtZusatz = $vollmodus || $this->section === 'zusatzstoffe';     // zusatzstoffe
        $brauchtNaehr = $vollmodus || $this->section === 'naehrwerte';        // naehrwerte
        $brauchtErsatz = $vollmodus || $this->section === 'ersatz';           // ersatz, ersatzKandidaten
        $brauchtEinkauf = $vollmodus || $this->section === 'einkauf';         // einkauf, bestandJetzt (Spec 66 §4)
        $brauchtLager = $this->section === 'lager';                           // lagerOrte, lagerBewegungen (Spec 67)
        $kette = ($gp !== null && $brauchtLas) ? $leads->rangliste($gp, $team) : null;
        $effektiverLeadId = ($gp !== null && $brauchtLas) ? $leads->effektiverLead($gp, $team)?->id : null;
        // R9.2 (E5): Lead-Steuerungs-Sicht (gesetzter vs. effektiver Lead, Vorschlag, Override-Begründung, Ausweichquellen).
        $leadSteuerung = ($gp !== null && $brauchtLas) ? $leads->leadSteuerung($gp, $team) : null;

        return view('foodalchemist::livewire.gps.detail-panel', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'gp' => $gp,
            'team' => $team,
            'kannKuratieren' => $gp !== null && Curate::canCurate(Auth::user(), $gp),
            'allergene' => ($gp !== null && $brauchtAllergene) ? $aggregate->allergene($gp) : null,
            'allergenKonfidenz' => ($gp !== null && $brauchtAllergene) ? $aggregate->allergenKonfidenz($gp) : null,
            'zusatzstoffe' => ($gp !== null && $brauchtZusatz) ? $aggregate->zusatzstoffe($gp) : null,
            'naehrwerte' => ($gp !== null && $brauchtNaehr) ? $aggregate->naehrwerte($gp, mitKiFallback: true, sicht: $team) : null,
            // Platzhalter (19.11.2026): Werte von Hand — Formular nur dort, wo die Allergene stehen
            'platzhalterStand' => ($gp !== null && $gp->is_platzhalter && $brauchtAllergene) ? $this->platzhalterFormLaden($gp) : null,
            'kette' => $kette,
            'effektiverLeadId' => $effektiverLeadId,
            'leadSteuerung' => $leadSteuerung,
            'preisBand' => $this->preisBand($kette, $effektiverLeadId),
            'preisTrend' => $kette !== null
                ? app(\Platform\FoodAlchemist\Services\PriceService::class)->preisTrendBulk($kette->pluck('id')->all(), $team)
                : [],
            // Ersatz-Logik: Äquivalenzen dieses GP + Such-Kandidaten fürs Verknüpfen
            'ersatz' => $gp !== null && $team !== null && $brauchtErsatz
                ? app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)->fuer($team, 'gp', $gp->id)
                : collect(),
            'ersatzKandidaten' => $gp !== null && $team !== null && $this->ersatzSuche !== ''
                ? app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)->sucheZiele($team, $this->ersatzSuche, 'gp', $gp->id)
                : collect(),
            // Bugfix 27.10.: Relevanz + Lieferanten-Rang, 20 Treffer, Hinweis auf weitere, Gebinde + Preis je Treffer
            'verknuepfSuche' => $gp !== null && $team !== null && $this->laSuche !== ''
                ? app(\Platform\FoodAlchemist\Services\Matching\VerknuepfbareArtikelSuche::class)->suche($team, $this->laSuche, $gp->commodity_group_code)
                : ['treffer' => collect(), 'weitere' => 0, 'mehr_als' => null],
            // Verwaltung (2026-07-02): Referenz-Zähler fürs Lösch-Gate + Kandidaten für den Rezept-Tausch
            'referenzen' => ($gp !== null && $vollmodus) ? app(\Platform\FoodAlchemist\Services\GpService::class)->referenzen($gp) : null,
            'tauschKandidaten' => $gp !== null && $team !== null && $this->tauschSuche !== ''
                ? \Platform\FoodAlchemist\Support\Suche::like(
                    FoodAlchemistGp::visibleToTeam($team)->lebensmittel(), 'name', $this->tauschSuche)
                    ->whereNotIn('status', ['merged', 'rejected'])
                    ->where('id', '!=', $gp->id)
                    ->orderBy('name')->limit(8)->get(['id', 'name', 'status', 'is_platzhalter'])
                : collect(),
            // Spec 66 §4: was wurde von diesem GP eingekauft (Monate, Lieferanten) + was liegt am Lager
            'einkauf' => ($gp !== null && $team !== null && $brauchtEinkauf)
                ? app(\Platform\FoodAlchemist\Services\PurchaseJournalService::class)->gpEinkauf($team, $gp->id)
                : null,
            'bestandJetzt' => ($gp !== null && $team !== null && $brauchtEinkauf)
                ? \Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock::where('team_id', $team->id)->where('gp_id', $gp->id)
                    ->selectRaw('base_unit, SUM(qty_base) AS menge')->groupBy('base_unit')->pluck('menge', 'base_unit')
                    ->map(fn ($m, $u) => ['menge' => app(\Platform\FoodAlchemist\Services\InventurService::class)->anzeigeMenge((float) $m, (string) $u), 'einheit' => app(\Platform\FoodAlchemist\Services\InventurService::class)->anzeigeEinheit((string) $u)])
                    ->values()->all()
                : [],
            // Spec 67: das Grundprodukt „trägt sein Lager": Bestand + Stammplatz je Lagerort, letzte Bewegungen
            'lagerOrte' => ($gp !== null && $team !== null && $brauchtLager) ? $this->lagerOrte($team, $gp) : [],
            'lagerartikelInfo' => ($gp !== null && $team !== null && $brauchtLager) ? $this->lagerartikelLaden($team, $gp) : null,
            'gefaessKostenartInfo' => ($gp !== null && $team !== null && $brauchtLager && $gp->istNonFood()) ? $this->gefaessKostenartLaden($team, $gp) : null,
            'lagerBewegungen' => ($gp !== null && $team !== null && $brauchtLager)
                ? \Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement::where('team_id', $team->id)->where('gp_id', $gp->id)
                    ->with('location:id,name')->where('qty_base', '<>', 0)->orderByDesc('moved_at')->orderByDesc('id')->limit(10)->get()
                : collect(),
            // M9-05 (GP-Blickwinkel): in welchen Rezepten eingesetzt — klickbar
            'verwendungen' => ($gp !== null && $brauchtLas)
                ? \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients AS ri')
                    ->join('foodalchemist_recipes AS r', 'r.id', '=', 'ri.recipe_id')
                    ->where('ri.gp_id', $gp->id)->whereNull('ri.deleted_at')->whereNull('r.deleted_at')
                    ->whereIn('r.team_id', FoodAlchemistGp::teamAncestryIds($team))
                    ->orderBy('r.name')->distinct()
                    ->limit(30)->get(['r.id', 'r.name', 'r.is_sales_recipe'])
                : collect(),
            // Spec 53 Paket J: Aroma-Anker als Chip + „weitere GPs mit diesem Anker" (Reverse-Lookup,
            // kein Graph — s. PR-Body für die spätere Anker-zentrierte Netz-Idee).
            // 27.10.2026: Geschmacksprofil (Rohprofil) auch in der Detail-Ansicht — mit „Mit KI schätzen" + „Anker vorschlagen"
            'sensorik' => ($gp !== null && $vollmodus) ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerGp($gp->id) : null,
            'gpAnker' => ($gp !== null && $vollmodus)
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)->gpAnkerAlle($gp->id)
                : collect(),
            'ankerNetz' => ($gp !== null && $team !== null && $this->ankerNetzOffenId !== null)
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)
                    ->gpsForAnkerIds($team, [$this->ankerNetzOffenId])
                    ->where('id', '!=', $gp->id)
                : collect(),
        ]);
    }

    /** Spec 74: Formular einmal je GP vorbelegen, Anzeige-Einheit liefern. */
    private function lagerartikelLaden($team, FoodAlchemistGp $gp): array
    {
        $la = \Platform\FoodAlchemist\Models\FoodAlchemistGpLagerartikel::where('team_id', $team->id)->where('gp_id', $gp->id)->first();
        $inv = app(\Platform\FoodAlchemist\Services\InventurService::class);
        $base = $la?->base_unit ?? ($inv->basisEinheit((string) ($gp->leadLa?->unit_code ?? 'kg')) ?: 'g');
        if (($this->lagerartikelForm['gp'] ?? null) !== $gp->id) {
            $fmt = fn ($v) => $v !== null ? str_replace('.', ',', (string) $inv->anzeigeMenge((float) $v, $base)) : '';
            $this->lagerartikelForm = ['gp' => $gp->id, 'ist' => $la !== null, 'min' => $fmt($la?->mindestbestand), 'soll' => $fmt($la?->sollbestand)];
        }

        return ['einheit' => $inv->anzeigeEinheit($base)];
    }

    /** Art + Preis als Gefäß: eigener Eintrag ins Formular (einmal je GP), geerbter Eintrag des Oberteams als Hinweis. */
    private function gefaessKostenartLaden($team, FoodAlchemistGp $gp): array
    {
        $svc = app(\Platform\FoodAlchemist\Services\GefaessKostenartService::class);
        $vorgabe = $svc->teamVorgabe((int) $gp->id, $team);
        $eigen = $vorgabe['team_id'] === (int) $team->id;
        if ($this->gefaessKostenartGp !== $gp->id) {
            $this->gefaessKostenartGp = $gp->id;
            $this->gefaessKostenart = $eigen ? (string) $vorgabe['kostenart'] : '';
            $this->gefaessPreis = $eigen && $vorgabe['preis'] !== null ? str_replace('.', ',', (string) $vorgabe['preis']) : '';
        }

        return ['geerbt' => ! $eigen && $vorgabe['kostenart'] !== null
                ? \Platform\FoodAlchemist\Services\GefaessKostenartService::ARTEN[$vorgabe['kostenart']].($vorgabe['preis'] !== null ? ', '.number_format($vorgabe['preis'], 2, ',', '.').' €/Stk' : '') : null,
            'geerbt_von' => ! $eigen && $vorgabe['team_id'] !== null ? (\Platform\Core\Models\Team::find($vorgabe['team_id'])?->name) : null];
    }

    public ?int $gefaessKostenartGp = null;

    private function lagerOrte($team, FoodAlchemistGp $gp): array
    {
        return app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class)->gpLager($team, $gp);
    }
}
