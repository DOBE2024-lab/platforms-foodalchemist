<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concerns\FortschrittAnsicht;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Kaskade\PlanningCascadeService;
use Platform\FoodAlchemist\Services\Rezept\SalesRecipeService;
use Platform\FoodAlchemist\Support\Planung\KaskadenAnsicht;
use Platform\FoodAlchemist\Support\Planung\LaufCockpit;

/**
 * Planungs-Leitstelle · Reiter „Fortschritt“ (Spec 85 C1 PR-3, Verschiebung aus {@see Index}): Lauf-Steuerung (Poll, Abbrechen,
 * Fortsetzen), Gate 2 (Freigeben, Verwerfen, Erzeugen geplant, Neu generieren), Anreicherung, Fotos und Foto-Picker.
 *
 * Der Parent bleibt Herr über Session und Lauf: `laufId` kommt als Prop, das Kind ist je Session und Lauf gekeyt. Zurück meldet das
 * Kind über zwei Events, ausgelöst in {@see rendering()} nur bei Änderung: `planung.lauf-status(laufId, laeuft)` (schreibgeschützte
 * Kopie im Parent für Reiter-Badge und Sperren im Brief) und `planung.meldung(text, art)` (Modal-Kopf wie bisher). Alle Schreibwege
 * gehen weiter über die Fassade PlanningCascadeService (Signaturen eingefroren, S1).
 */
class KaskadenLauf extends Component
{
    use WithFileUploads;
    use FortschrittAnsicht, HandyLesebereich;   // Spec 80 Teil D (Baum · Cluster · Rezept); #1003 Handy-Umfang

    public ?string $fehler = null;

    public ?string $meldung = null;

    /**
     * Was der Lauf NICHT erzeugt hat — aus `cascade_runs.deckel_hinweise`. `null` = kein Deckel
     * gegriffen; ein Nichts wird nicht als Warnung verkauft.
     *
     * Gefüllt aus dem LAUF, nicht per Flash: der Deckel ist eine Eigenschaft des Laufs und muss
     * einen Reload und ein Zu-und-wieder-Aufklappen überleben. Ein Deckel, der erst später in
     * einem Job greift (Concept-Slots), erreicht dieselbe Zeile über den Poll.
     */
    public ?string $deckelHinweis = null;

    /** Aktiver Kaskaden-Lauf (in-place „Go") — beobachtet von {@see LaufStatus}. Setzt nur der Parent beim Mount (Kind je Lauf gekeyt). */
    #[Locked]
    public ?int $laufId = null;

    /** true, solange der Lauf im Hintergrund rechnet (steuert das Polling). */
    public bool $laeuft = false;

    /** Etappe 7 Teil 2: manuell hochgeladene Fotos je Cockpit-Step (stepId → TemporaryUploadedFile) —
     *  die NICHT-KI-Alternative zur Bild-Erzeugung, gebunden pro Step (mehrere Drafts gleichzeitig). */
    public array $fotoUploads = [];

    /** Etappe 7 Teil 3b: der Step, dessen Foto-Wiederverwendungs-Picker gerade offen ist (null = zu).
     *  Nur einer offen zur Zeit — die Kandidatenliste rechnet render() nur für diesen Step. */
    public ?int $fotoPickerStep = null;

    /** Worker (T2): Name eines manuell ergänzten Basisrezepts (Sub-Step, den die KI nicht erkannt hat). */
    public string $neuerSubName = '';

    /**
     * A: Welche Draft-Steps ihren Inline-Zutaten-Editor offen haben (step_id-Liste).
     * Kontrolliertes On-Demand-Mounten statt N eingebetteter Editoren beim Fan-out —
     * erst beim Aufklappen wird der IngredientEditor für diesen Draft gerendert.
     */
    public array $zutatenOffen = [];

    /** E4 (Spec 40): on-demand geladene Favoriten-Kandidaten je Step (step_id → [{id,name}]). */
    public array $favoritVorschlaege = [];

    /**
     * A2 (per-Speise-Feedback): welche Worker-Steps ihr Kommentar-Feld offen haben (step_id-Liste) +
     * der getippte Feedback-Text je Step (step_id → Text). Ein Kommentar zu genau dieser Position geht
     * beim „neu generieren" als gezielte Anpassung an die Regeneration (nur diese Position), danach
     * geleert (Einweg-Feedback). {@see toggleKommentar}, {@see neuGenerieren}.
     *
     * @var array<int,int>
     */
    public array $kommentarOffen = [];

    /** @var array<int,string> */
    public array $speiseKommentar = [];

    /**
     * Etappe 6 Margen-Gate: Warnung, wenn bei einer Stufen-Freigabe Positionen UNTER ihrer
     * Preisklasse freigegeben wurden (fixierter VK < dynamischer Auto-Vorschlag). Reine Rückkopplung,
     * keine harte Sperre — der Mensch entscheidet (Nordstern). Wird je Freigabe frisch gesetzt.
     */
    public ?string $margenWarnung = null;

    /** 2.1d: in dieser Anfrage schon geladener Lauf (pruefeLauf → render lädt ihn nicht erneut). */
    private ?FoodAlchemistCascadeRun $laufGeladen = null;

    /** Läuft nach einer Freigabe noch eine async Anreicherung (deferred.enrich) ODER KI-Foto-Erzeugung (deferred.bilder, „neu erzeugen") nach (queued|running)? Hält das Polling am Leben, bis das Ergebnis wirklich brauchbar ist. */
    public bool $anreicherungLaeuft = false;

    /**
     * Queue-Watchdog (2026-08): gesetzt, wenn der Lauf ungewöhnlich lange auf `running` steht,
     * OHNE dass ein Schritt je Fortschritt machte — fast sicher kein Queue-Worker aktiv (ein echter
     * Fehler ruft markStepFailed → status=failed). Kein Abbruch, nur ein sichtbarer Hinweis statt
     * endlosem Spinner. Die Leitstelle ist der EINZIGE KI-Erstell-Pfad → derselbe Schutz, den die
     * Modals in Phase 0 bekamen (HatGeneratorLauf), gehört auch hierher.
     */
    public ?string $hinweis = null;

    /** Sekunden auf `running` ohne jeden Step-Fortschritt, ab denen der Watchdog anschlägt (über der realistischen Erst-Dauer). */
    protected const WATCHDOG_SEKUNDEN = 90;

    /**
     * Der Deckel-Satz des Laufs für die Fläche. Reine Weiterleitung an
     * {@see FoodAlchemistCascadeRun::deckelMeldung()} — der Wortlaut gehört dorthin, wo der
     * Deckel greift, nicht in die Anzeige.
     */
    private function deckelHinweisText(?FoodAlchemistCascadeRun $lauf): ?string
    {
        return $lauf?->deckelMeldung();
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    /** Lauf-Status aus der DB lesen (über {@see laufGeaendert}, sobald sich der Stand ändert). */
    public function pruefeLauf(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            $this->laeuft = false;

            return;
        }
        $lauf = $this->laufGeladen = $cascade->lauf($team, $this->laufId);
        // Spec 53 / Paket C: eine Phase (Konformitätsprüfung/Anreicherung/Bilder) kann auch nach dem
        // Ende des Laufs noch offen sein — dann darf pruefeLauf nicht früh abbrechen, sonst würde das
        // Poll-Ziel selbst suggerieren, es sei fertig, während render()s $pollAktiv weiter pollt.
        $phaseOffen = $lauf !== null && $lauf->steps->contains(fn ($s) => $s->phase !== null);
        if ($lauf === null || ($lauf->status !== 'running' && ! $phaseOffen)) {
            $this->laeuft = false;
            $this->hinweis = null;
            if ($lauf !== null && $lauf->status === 'review') {
                $this->meldung = 'Entwurf erzeugt — im Ergebnis unten prüfen.';
            } elseif ($lauf !== null && $lauf->status === 'failed') {
                $this->fehler = 'Generierung fehlgeschlagen — Details im Ergebnis unten.';
            }

            return;
        }
        if ($lauf->status !== 'running') {
            // Der Lauf selbst ist fertig, aber eine Phase läuft noch nach — der Watchdog unten
            // beobachtet nur laufende Kaskaden-Schritte und griffe hier ins Leere.
            $this->laeuft = false;

            return;
        }
        // Queue-Watchdog: „Job-Beweis" = ein Schritt ist done/failed (ein Generator-Job ist BEWIESEN
        // gelaufen). freigegeben/skipped/verworfen zählen bewusst NICHT — sonst wird der Wächter blind,
        // sobald eine Stufe freigegeben ist (Bug: stiller Endlos-Spinner, wenn danach der Worker fehlt).
        // Läuft der Run ungewöhnlich lange OHNE Job-Beweis und warten Schritte → fast sicher kein Worker.
        $jobBewiesen = $lauf->steps->contains(fn ($s) => in_array($s->status, ['done', 'failed'], true));
        $wartet = $lauf->steps->contains(fn ($s) => in_array($s->status, ['queued', 'running'], true));
        $alterSek = $lauf->created_at !== null ? $lauf->created_at->diffInSeconds(now()) : 0;
        $this->hinweis = (! $jobBewiesen && $wartet && $alterSek > self::WATCHDOG_SEKUNDEN && $cascade->kiWartend($lauf->steps) === [])   // wartet auf externe KI-Antwort (MCP, Lauf 62) → kein Alarm
            ? 'Die Erstellung dauert ungewöhnlich lange und kein Schritt kommt voran. Vermutlich läuft die Hintergrund-Erstellung gerade nicht. Sobald sie anläuft, geht es automatisch weiter.'
            : null;
    }

    /**
     * Recovery bei hängendem Lauf (Idempotenz/Resume, Etappe 8): verwaiste in-flight Steps als
     * `failed` markieren, damit der Lauf wieder handlungsfähig wird (neu generieren / verwerfen).
     * Greift NUR bei wirklich verwaisten Steps ({@see PlanningCascadeService::VERWAIST_NACH_MINUTEN})
     * — ein junger, evtl. noch lebender Job wird nicht abgewürgt; dann sagt es das ehrlich.
     */
    public function laufFortsetzen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $n = $cascade->reapeVerwaisteSteps($team, $this->laufId);
        // Spec 80 C7: EINE Meldung. Vorher stand „arbeitet vermutlich noch" neben „Generierung
        // fehlgeschlagen", obwohl der Schritt längst gescheitert war.
        $gescheitert = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::where('cascade_run_id', $this->laufId)
            ->where('status', 'failed')->count();
        if ($n > 0) {
            $this->meldung = $n . ' abgebrochene(r) Schritt(e) freigeräumt — jetzt unten neu generieren oder verwerfen.';
            $this->fehler = null;
        } elseif ($gescheitert > 0) {
            $this->meldung = null;
            $this->fehler = $gescheitert . ' Schritt(e) gescheitert. „Gescheiterte Schritte fortsetzen" startet sie neu, oder einzeln neu generieren.';
        } else {
            $this->meldung = 'Kein abgebrochener Schritt gefunden — der Lauf arbeitet vermutlich noch. Kurz warten.';
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Echtes Resume (Idempotenz/Resume, Etappe 8 Teil 3): alle GESCHEITERTEN generierbaren Steps des
     * Laufs auf einmal wieder aufnehmen — statt sie einzeln über {@see regeneriereStep} („neu
     * generieren") zu bedienen. Ergänzt {@see laufFortsetzen}: der Reaper (Teil 1) macht harte Hänger
     * erst zu `failed`, dieser Resume ({@see PlanningCascadeService::setzeLaufFort}) nimmt dann alle
     * `failed`-Steps (verwaist ODER regulär gescheitert) gebündelt wieder auf. Idempotent gegen
     * Doppel-Jobs (Service-Vertrag: nur `failed`-Steps, die sofort auf `running` flippen).
     */
    public function laufWiederAufnehmen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $n = $cascade->setzeLaufFort($team, $this->laufId);
        if ($n > 0) {
            $this->meldung = $n . ' gescheiterte(r) Schritt(e) werden neu erzeugt — der Fortschritt läuft oben durch.';
            $this->fehler = null;
        } else {
            $this->meldung = 'Kein gescheiterter Schritt zum Fortsetzen.';
        }
        $this->refreshLaeuft($cascade);
    }

    // ── Freigabe / Verwerfen (Gate 2 — inline im Editor) ───────────────

    /** Einen erzeugten Draft freigeben (→ live) — Rezept approved / Concept active. */
    public function gibFrei(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->gibStepFrei($team, $stepId);
            $this->meldung = 'Freigegeben.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Einen Draft verwerfen (soft-delete). */
    public function verwirf(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->verwirfStep($team, $stepId);
            $this->meldung = 'Verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Ein geplantes Sub-Rezept einzeln JETZT erzeugen (Etappe 1, Teil 2) — vorziehen, ohne auf die
     * Freigabe der Stufe darüber zu warten. Der Step geht auf `running`; die Fläche pollt wie beim Go.
     */
    public function erzeugeGeplant(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->erzeugeGeplantenStep($team, $stepId);
            $this->meldung = 'Sub-Rezept wird erzeugt …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Manuell ein Basisrezept in die Basisrezepte-Stufe ergänzen (T2): ein Sub-Rezept, das die KI nicht
     * als Komponente erkannt hat (z. B. ein fehlender Jus), als `geplant`-Step nachziehen. Erzeugt wird
     * es danach je Zeile mit „jetzt erzeugen". Fail-soft; kein aktiver Lauf/leerer Name = No-op.
     */
    public function ergaenzeSubRezept(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        $name = trim($this->neuerSubName);
        if ($team === null || $this->laufId === null || $name === '') {
            return;
        }
        try {
            $cascade->ergaenzeManuellenSubStep($team, (int) $this->laufId, $name);
            $this->neuerSubName = '';
            $this->meldung = 'Basisrezept ergänzt — mit „jetzt erzeugen" generieren.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Ergänzen nicht möglich: ' . $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Ein geplantes Sub-Rezept verwerfen („brauche ich nicht") — es wird bei der Stufen-Freigabe nicht erzeugt. */
    public function verwirfGeplant(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->verwirfGeplantenStep($team, $stepId);
            $this->meldung = 'Sub-Rezept verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * A: Inline-Zutaten-Review eines Drafts auf-/zuklappen (voll editierbar vor Freigabe).
     * On-Demand — der IngredientEditor wird erst beim Öffnen gemountet.
     */
    public function toggleZutaten(int $stepId): void
    {
        if (in_array($stepId, $this->zutatenOffen, true)) {
            $this->zutatenOffen = array_values(array_diff($this->zutatenOffen, [$stepId]));
        } else {
            $this->zutatenOffen[] = $stepId;
        }
    }

    /**
     * Spec 80 H3: alle neu gebauten Entwürfe (ohne offenen harten Regelwerk-Befund) anreichern und freigeben.
     * Im gestuften Lauf wird jedes Rezept erst nach erfolgreicher Anreicherung grün.
     */
    public function alleNeuenAnreichern(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $r = $cascade->gibNeueFrei($team, $this->laufId);
        $this->meldung = $r['freigegeben'] . ' Entwurf/Entwürfe werden angereichert und danach freigegeben.'
            . ($r['uebersprungen'] > 0 ? ' ' . $r['uebersprungen'] . ' mit offenem Regelwerk-Befund bleiben zur Prüfung stehen.' : '');
        $this->fehler = null;
        $this->refreshLaeuft($cascade);
    }

    /** Alle offenen Entwürfe des Laufs verwerfen. */
    public function alleVerwerfen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $cascade->verwirfRun($team, $this->laufId);
        $this->meldung = 'Alle Entwürfe verworfen.';
        $this->refreshLaeuft($cascade);
    }

    /**
     * E-P0 (Spec 40): einen fehlgeschlagenen Attach nachholen — das erzeugte Konzept eines Steps ans
     * gemerkte Ausgabe-Kapitel/die Rubrik hängen. Scheitert es erneut, wird der Fehler sichtbar gemeldet
     * (das Signal am Step bleibt bis zum Erfolg stehen).
     */
    public function haengeKonzeptNach(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->haengeKonzeptNach($team, $stepId);
            $this->meldung = 'Konzept nachträglich ans Ausgabe-Kapitel/die Rubrik gehängt.';
        } catch (\Throwable $e) {
            $this->fehler = 'Nachträgliches Einhängen fehlgeschlagen: ' . $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * E4 (Spec 40) — Rückkopplung: die Sourcing-Lücken eines erzeugten Rezept-Steps (GPs ohne beschaffbaren
     * Lead-LA) ins Signale-Cockpit melden. „Lücke ist Signal, kein Fehler."
     */
    public function sourcingLueckenMelden(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $gemeldet = $cascade->meldeSourcingLuecken($team, $stepId);
            $this->fehler = null;
            $this->meldung = $gemeldet === []
                ? 'Keine Sourcing-Lücke — alle GPs dieses Entwurfs sind beschaffbar.'
                : count($gemeldet) . ' Sortiments-Lücke(n) gemeldet: ' . implode(', ', $gemeldet) . ' — im Signale-Cockpit sichtbar.';
        } catch (\Throwable $e) {
            $this->fehler = 'Lücken-Meldung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    /**
     * E4 (Spec 40) — Rückkopplung: die noch nicht gepinnten GPs eines erzeugten Rezept-Steps als
     * Favoriten-Kandidaten laden (on-demand, kein Render-Query). Das Pinnen bleibt mensch-gated.
     */
    public function favoritVorschlaegeLaden(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $this->favoritVorschlaege[$stepId] = $cascade->favoritKandidatenFuerStep($team, $stepId);
        } catch (\Throwable $e) {
            $this->fehler = 'Favoriten-Vorschläge konnten nicht geladen werden: ' . $e->getMessage();
        }
    }

    /** E4 (Spec 40): einen vorgeschlagenen GP als Favorit pinnen (mensch-gated). Nur team-eigene GPs. */
    public function favoritPinnen(int $gpId, \Platform\FoodAlchemist\Services\GpStamm\FavoriteGpService $fav): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            $this->fehler = 'GP nicht gefunden — nicht gepinnt.';

            return;
        }
        try { $fav->pin($team, $gp); } catch (\RuntimeException $e) { $this->fehler = $e->getMessage(); return; }   // Spec 85 FAV-1: Liste des Teams, Kuratieren
        $this->meldung = 'GP „' . $gp->name . '" als Favorit gepinnt.';
        // aus allen geladenen Vorschlagslisten entfernen (ist jetzt Favorit)
        foreach ($this->favoritVorschlaege as $sid => $liste) {
            $this->favoritVorschlaege[$sid] = array_values(array_filter($liste, fn ($k) => (int) ($k['id'] ?? 0) !== $gpId));
        }
    }

    /**
     * Ganze Stufe freigeben (Stufen-Knopf im Cockpit): gibt alle offenen Entwürfe einer `kind` frei —
     * das startet die nächste Stufe (siehe PlanningCascadeService::gibStufeFrei/gibStepFrei).
     */
    public function gibStufeFrei(string $kind, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        // Etappe 6 Margen-Gate: die anstehenden Drafts VOR der Freigabe prüfen — danach sind sie
        // `freigegeben`, nicht mehr `done`, und der Klassen-Vergleich fände nichts mehr.
        $unterKlasse = $this->stepsUnterAufschlagsklasse($team, $kind, $cascade);
        $this->margenWarnung = null;
        try {
            $cascade->gibStufeFrei($team, $this->laufId, $kind);
            $this->meldung = 'Stufe freigegeben — die nächste Stufe wird erzeugt.';
            $this->fehler = null;
            $this->margenWarnung = $this->margenWarnungText($unterKlasse);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Etappe 6 Margen-Gate — welche der bei der Freigabe der Stufe $kind anstehenden Rezept-Drafts
     * würden UNTER ihrem Auto-Vorschlag freigegeben? Das ist ein FIXIERTER VK,
     * der den dynamischen Katalogvorschlag unterschreitet (source=`fixed`).
     *
     * Reuse `SalesRecipeService::cockpit` (die EINE Kalkulations-Wahrheit, GL-02 I9) — keine neue
     * Rechenlogik. Ein Auto-VK (source=`auto`) folgt dem Vorschlag exakt → nie drunter; ohne Vorschlag
     * (kein EK / keine Portionierung / Formel fehlt) gibt es keine Klassen-Schwelle → keine Aussage,
     * nicht geraten. Bewusst am Klassen-Vorschlag statt an der Food-Cost-Ampel (Team-Ziel-Wareneinsatz
     * ist oft nicht gepflegt → Ampel `unbekannt`, s. Backlog #87); die Klassen-Schwelle trägt das
     * Rezept selbst über seine markup_class.
     *
     * @return list<string> Namen der Positionen unter Aufschlagsklasse (leer = nichts zu warnen)
     */
    private function stepsUnterAufschlagsklasse(Team $team, string $kind, PlanningCascadeService $cascade): array
    {
        if ($this->laufId === null) {
            return [];
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        if ($lauf === null) {
            return [];
        }
        // Nur die freizugebenden Drafts (`done`) dieser Stufe — exakt die, die gibStufeFrei scharf schaltet.
        $refIds = $lauf->steps
            ->where('kind', $kind)
            ->where('ref_type', 'recipe')
            ->where('status', 'done')
            ->pluck('ref_id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
        if ($refIds === []) {
            return [];
        }
        $sales = app(SalesRecipeService::class);
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $refIds)->get();
        $unter = [];
        foreach ($rezepte as $rz) {
            $c = $sales->cockpit($rz, $team);
            $vk = is_array($c['vk'] ?? null) ? $c['vk'] : [];
            $vorschlag = is_array($vk['vorschlag'] ?? null) ? $vk['vorschlag'] : null;
            if (($vk['source'] ?? null) === 'fixed'
                && isset($vk['sales_net'], $vorschlag['sales_net'])
                && (float) $vk['sales_net'] < (float) $vorschlag['sales_net']) {
                $unter[] = (string) ($rz->name ?? ('#'.$rz->id));
            }
        }

        return $unter;
    }

    /**
     * Formuliert die Margen-Gate-Warnung aus den Namen der Positionen unter Aufschlagsklasse.
     * `null`, wenn nichts drunter liegt (kein Rauschen bei sauberer Freigabe).
     *
     * @param  list<string>  $namen
     */
    private function margenWarnungText(array $namen): ?string
    {
        if ($namen === []) {
            return null;
        }
        $anzahl = count($namen);
        $kopf = $anzahl === 1 ? '1 Position unter Auto-Vorschlag freigegeben' : $anzahl.' Positionen unter Auto-Vorschlag freigegeben';

        return sprintf('%s: %s — fixierten VK gegen den dynamischen Vorschlag prüfen.', $kopf, implode(', ', $namen));
    }

    /** A2: Kommentar-Feld eines Worker-Steps auf-/zuklappen (per-Speise-Feedback). */
    public function toggleKommentar(int $stepId): void
    {
        if (in_array($stepId, $this->kommentarOffen, true)) {
            $this->kommentarOffen = array_values(array_diff($this->kommentarOffen, [$stepId]));
        } else {
            $this->kommentarOffen[] = $stepId;
        }
    }

    /**
     * Per-Step-KI: einen Entwurf neu generieren (altes Draft wird verworfen). A2: liegt für DIESEN Step
     * ein Feedback-Kommentar vor ({@see $speiseKommentar}), geht er als gezielte Anpassung an genau diese
     * Position mit — die Nachbar-Positionen bleiben unberührt. Kommentar wird nach dem Anstoß geleert
     * (Einweg-Feedback: ein Klick = eine gezielte Änderung).
     */
    public function neuGenerieren(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $kommentar = trim((string) ($this->speiseKommentar[$stepId] ?? ''));
        try {
            $cascade->regeneriereStep($team, $stepId, $kommentar !== '' ? $kommentar : null);
            $this->meldung = $kommentar !== '' ? 'Wird mit deinem Feedback neu generiert …' : 'Wird neu generiert …';
            $this->fehler = null;
            unset($this->speiseKommentar[$stepId]);
            $this->kommentarOffen = array_values(array_diff($this->kommentarOffen, [$stepId]));
        } catch (\Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException $e) {
            // Guard, kein Fehlschlag — neutraler Hinweis statt rotem Banner.
            $this->meldung = $e->getMessage();
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Feedback auf einen reinen Gericht-Bauplan anwenden; noch keine Rezept-Materialisierung. */
    public function vorschlagUeberarbeiten(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $kommentar = trim((string) ($this->speiseKommentar[$stepId] ?? ''));
        try {
            $cascade->ueberarbeiteGerichtVorschlag($team, $stepId, $kommentar);
            $this->meldung = 'Gerichtsvorschlag wird mit deinem Feedback überarbeitet …';
            $this->fehler = null;
            unset($this->speiseKommentar[$stepId]);
            $this->kommentarOffen = array_values(array_diff($this->kommentarOffen, [$stepId]));
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Spec 84 C1-1: Aufbereitung liegt in {@see KaskadenAnsicht}; Blade (fortschritt) und Tests rufen sie weiter hier auf. */
    public function stufenAusSteps($steps): array
    {
        return app(KaskadenAnsicht::class)->stufenAusSteps($steps);
    }

    /** Test-/Direkteinstieg: Stufen des aktiven Laufs (lädt den Run selbst). */
    public function stufen(): array
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return [];
        }
        $lauf = app(PlanningCascadeService::class)->lauf($team, $this->laufId);

        return $lauf === null ? [] : $this->stufenAusSteps($lauf->steps);
    }

    /** Nach einer Freigabe/Regenerierung neu bestimmen, ob der Lauf (wieder) rechnet → Polling steuern. */
    private function refreshLaeuft(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            $this->laeuft = false;

            return;
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        $this->laeuft = $lauf !== null && $lauf->status === 'running';
        $this->anreicherungLaeuft = $this->anreicherungOffen($lauf);
        // Auch hier: ein Deckel, der erst IM JOB greift (Concept-Slots), wird sonst nie sichtbar.
        $this->deckelHinweis = $this->deckelHinweisText($lauf);
    }

    /**
     * Läuft nach einer Freigabe noch eine async Anreicherung (deferred.enrich=queued|running) ODER eine
     * KI-Foto-Erzeugung (deferred.bilder=queued|running, z. B. „neu erzeugen", Etappe 7 Teil 2b) eines
     * Rezept-/Gericht-Steps? Dann pollt die Fläche weiter, obwohl der Run schon „done" sein kann — so
     * kippt das Status-Badge live vom Spinner auf das Ergebnis (oder den sichtbaren Fehler).
     */
    private function anreicherungOffen($lauf): bool
    {
        if ($lauf === null) {
            return false;
        }

        return $lauf->steps->contains(function ($s) {
            // `skipped` mit aufnehmen: seit 2026-09-07 kann auch eine übernommene Zeile eine
            // laufende Anreicherung haben (unreifer eigener Entwurf). Ohne das pollte die
            // Fläche nicht und der Spinner an der Sub-Rezept-Zeile stand still.
            if (! in_array($s->status, ['freigegeben', 'skipped'], true)
                || ! in_array($s->kind, ['rezept', 'gericht'], true)) {
                return false;
            }
            $deferred = is_array($s->deferred) ? $s->deferred : [];
            $enrich = is_array($deferred['enrich'] ?? null) ? ($deferred['enrich']['status'] ?? null) : null;
            $bilder = is_array($deferred['bilder'] ?? null) ? ($deferred['bilder']['status'] ?? null) : null;

            return in_array($enrich, ['queued', 'running'], true)
                || in_array($bilder, ['queued', 'running'], true);
        });
    }

    /**
     * „Neu anreichern" (Cockpit): Anreicherung eines Steps erneut anstoßen — nach einem Fehler,
     * an einer unreifen Übernahme, oder mit `$voll` als „jetzt in voller Tiefe" (2026-09-07):
     * war der Lauf mit `complete_coverage=false` gefahren, fehlen Schritte, Sensorik und Zeiten,
     * und ein einfacher Neu-Lauf würde denselben flachen Pass wiederholen.
     */
    public function neuAnreichern(int $stepId, PlanningCascadeService $cascade, bool $voll = false): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->reAnreichern($team, $stepId, $voll ? true : null);
            $this->meldung = 'Anreicherung neu gestartet …';
            $this->fehler = null;
        } catch (\Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException $e) {
            $this->meldung = $e->getMessage();
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** „Neu erzeugen" (Cockpit, Etappe 7 Teil 2b): NUR die KI-Fotos eines freigegebenen Drafts erneut
     *  anstoßen (ohne Voll-Anreicherung) — nach deferred.bilder=failed. */
    public function bilderNeu(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->reBilder($team, $stepId);
            $this->meldung = 'KI-Fotos werden neu erzeugt …';
            $this->fehler = null;
        } catch (\Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException $e) {
            $this->meldung = $e->getMessage();
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Manuelles Foto hochladen (Cockpit, Etappe 7 Teil 2): ein eigenes Bild als Rezept-Foto eines
     *  freigegebenen Rezept-/Gericht-Drafts übernehmen — die NICHT-KI-Alternative zur Foto-Erzeugung,
     *  neben „neu erzeugen". `$istErgebnis=true` = Hero/Ergebnis-Foto (max. 1), sonst Pool-Foto. Kein
     *  KI-Call → das Foto überlebt einen späteren KI-Re-Trigger (loescheKiFotos). Empty-only: ohne
     *  gewählte Datei passiert nichts (gesagt). */
    public function fotoHochladen(int $stepId, bool $istErgebnis = false, ?PlanningCascadeService $cascade = null): void
    {
        $cascade ??= app(PlanningCascadeService::class);
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $datei = $this->fotoUploads[$stepId] ?? null;
        if ($datei === null) {
            $this->fehler = 'Kein Foto gewählt.';

            return;
        }
        \Platform\FoodAlchemist\Support\BildUpload::pruefen($this, "fotoUploads.$stepId");   // max. 14 MB, nur Bilder
        try {
            $cascade->uebernimmManuellesFotoFuerStep($team, $stepId, $datei, $istErgebnis);
            $this->meldung = $istErgebnis ? 'Ergebnis-Foto übernommen.' : 'Foto übernommen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        unset($this->fotoUploads[$stepId]);
        $this->refreshLaeuft($cascade);
    }

    /** Foto-Wiederverwendungs-Picker öffnen (Etappe 7 Teil 3b): zeigt vorhandene Team-Fotos zur
     *  Übernahme auf diesen Draft. Nur einer offen zur Zeit; die Kandidaten baut render(). */
    public function fotoPickerOeffnen(int $stepId): void
    {
        $this->fotoPickerStep = $stepId;
    }

    /** Foto-Wiederverwendungs-Picker schliessen. */
    public function fotoPickerSchliessen(): void
    {
        $this->fotoPickerStep = null;
    }

    /** Ein vorhandenes Team-Foto (aus dem Picker) auf den Draft übernehmen (Etappe 7 Teil 3b) —
     *  COPY-ON-REUSE, kein KI-Call. `$istErgebnis=true` = Hero/Ergebnis-Foto (max. 1), sonst Pool.
     *  Verdrahtet fotoUebernehmen → uebernimmVorhandenesFotoFuerStep (Teil 3a-Primitive). */
    public function fotoUebernehmen(int $stepId, int $fotoId, bool $istErgebnis = false, ?PlanningCascadeService $cascade = null): void
    {
        $cascade ??= app(PlanningCascadeService::class);
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->uebernimmVorhandenesFotoFuerStep($team, $stepId, $fotoId, $istErgebnis);
            $this->meldung = $istErgebnis ? 'Ergebnis-Foto übernommen.' : 'Foto übernommen.';
            $this->fehler = null;
            $this->fotoPickerStep = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Schicht 3: on-demand Konformitäts-Prüfung eines Rezept-/Gericht-Drafts. Stößt den
     * Async-Critic an (best-effort — der Job heilt + legt Hinweise ab); die Hinweise
     * erscheinen beim nächsten Render/Poll. Kein Sync-LLM im Web-Request (kein Timeout-Risiko).
     */
    public function konformitaetPruefen(int $recipeId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $recipeId <= 0) {
            return;
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return;
        }
        // Spec 53 / Paket C: den Step im AKTUELLEN Lauf auflösen (ref_type=recipe/ref_id=$recipeId) —
        // so wird die Phase „Konformität wird geprüft …" am Cockpit sichtbar und $pollAktiv sieht sie.
        // Kein offener Lauf (Check außerhalb des Cockpits, z. B. Rezept-Modal) → Phase bleibt leer,
        // der Job läuft trotzdem.
        $step = $this->laufId !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::visibleToTeam($team)
                ->where('cascade_run_id', $this->laufId)
                ->where('ref_type', 'recipe')->where('ref_id', $recipeId)
                ->first()
            : null;
        // Server-Guard gegen Doppel-Enqueue: läuft die Konformitätsprüfung an diesem Step schon, kein zweiter Job.
        if ($step !== null && $step->phase === PlanningCascadeService::PHASE_KONFORMITAET) {
            $this->meldung = 'Konformitätsprüfung läuft bereits.';

            return;
        }
        // Eine ausdrücklich neu gestartete Prüfung verwendet den heutigen Kanon.
        app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunService::class)->starteFuerRezept($team, $recipe);
        // Synchron VOR dem Dispatch setzen (nicht erst im Job) — sonst öffnet ein zweiter Klick,
        // solange der Job noch in der Queue wartet (noch nicht angelaufen), dieselbe Lücke, die der
        // Guard oben eigentlich schließen soll (der Job braucht Zeit, bis er die Phase selbst setzt).
        if ($step !== null) {
            app(PlanningCascadeService::class)->setzePhase((int) $step->id, PlanningCascadeService::PHASE_KONFORMITAET);
        }
        \Platform\FoodAlchemist\Jobs\ConformanceCheckJob::dispatch(
            $team->id, (int) Auth::id(), $recipe->is_sales_recipe ? 'gericht' : 'basisrezept', $recipeId, null, $step?->id,
        );
    }

    /**
     * Lese-Anzeige der geplanten Menü-Positionen eines Concept-Drafts (Worker-Concept-Step): Rolle/Titel
     * je Slot, damit man SIEHT, welche Speisen der Plan vorschlägt — statt nur „öffnen". `[]`, wenn das
     * Concept weg/Team-fremd ist. Rein lesend (eine Query, nur für Concept-Steps aufgerufen), fail-soft.
     *
     * @return array<int,array<string,mixed>>
     */
    public function conceptSpeisen(int $conceptId): array
    {
        $team = $this->team();
        if ($team === null || $conceptId <= 0) {
            return [];
        }
        $concept = app(ConceptService::class)->detail($team, $conceptId);
        if ($concept === null) {
            return [];
        }

        return $concept->slots->map(fn ($s) => [
            'rolle' => (string) ($s->role ?? ''),
            'titel' => (string) ($s->title ?? ''),
            'pflicht' => (bool) $s->is_pflicht,
        ])->values()->all();
    }

    /** 2.1d: {@see LaufStatus} meldet einen geänderten Stand → Lauf-Status übernehmen, danach rendert die Planung. */
    #[On('planung.lauf-geaendert')]
    public function laufGeaendert(PlanningCascadeService $cascade): void
    {
        $this->pruefeLauf($cascade);
    }

    /** Stand beim Laden der Anfrage; {@see rendering()} meldet dem Parent nur, was sich geändert hat. */
    private array $gemeldet = [];

    public function mount(): void
    {
        $this->refreshLaeuft(app(PlanningCascadeService::class));
        $this->gemeldet = $this->meldeStand();   // Erst-Render: der Parent kennt den Stand schon (ladeLetztenLauf/goKaskade)
    }

    public function hydrate(): void
    {
        $this->gemeldet = $this->meldeStand();
    }

    /** @return array<string, mixed> */
    private function meldeStand(): array
    {
        return ['laeuft' => $this->laeuft, 'meldung' => $this->meldung, 'fehler' => $this->fehler,
            'margen' => $this->margenWarnung, 'deckel' => $this->deckelHinweis];
    }

    /** Spec 85 C1 PR-3: geänderten Lauf-Stand und Meldungen an den Parent (Modal-Kopf, Reiter-Badge, Sperren im Brief). */
    public function rendering($view = null, $data = []): void
    {
        $neu = $this->meldeStand();
        if ($neu['laeuft'] !== ($this->gemeldet['laeuft'] ?? null)) {
            $this->dispatch('planung.lauf-status', laufId: $this->laufId, laeuft: $this->laeuft);
        }
        foreach (['meldung', 'fehler', 'margen', 'deckel'] as $art) {
            if ($neu[$art] !== ($this->gemeldet[$art] ?? null)) {
                $this->dispatch('planung.meldung', text: $neu[$art], art: $art);
            }
        }
        $this->gemeldet = $neu;
    }

    /** #1003 PR7: am Handy Ergebnis beobachten, abbrechen, wieder aufnehmen, freigeben samt Anreicherung, Fotos zu einem Schritt. */
    public function handyLeseMethoden(): array
    {
        return [
            'pruefeLauf', 'laufGeaendert', 'laufWiederAufnehmen', 'laufFortsetzen',
            'gibFrei', 'gibStufeFrei', 'clusterFreigeben', 'neuAnreichern', 'alleNeuenAnreichern',
            'waehleSchritt', 'waehleKnoten', 'setzeFortschrittFilter',
            'fotoHochladen', 'fotoPickerOeffnen', 'fotoPickerSchliessen', 'fotoUebernehmen',
            '_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload',
        ];
    }

    public function render()
    {
        $team = $this->team();
        // Aktiver Kaskaden-Lauf (in-place „Go") inkl. Steps — für Fortschritt + Ergebnis-Liste.
        $lauf = ($team !== null && $this->laufId !== null)
            ? ($this->laufGeladen?->id === $this->laufId ? $this->laufGeladen : app(PlanningCascadeService::class)->lauf($team, $this->laufId))
            : null;
        $this->laufGeladen = null;

        // Anreicherung läuft async NACH der Freigabe → Polling am Leben halten, bis das Ergebnis
        // wirklich angereichert (oder der Fehler sichtbar) ist. Bei einem flachen Gericht ist der Run
        // sonst sofort „done", während das Gericht noch ein roher Entwurf wäre.
        $this->anreicherungLaeuft = $this->anreicherungOffen($lauf);

        // Spec 53 / Paket C: Poll-Gate ABLEITEN statt merken. Vorher speiste ein gespeichertes Flag
        // (17 Aufrufstellen) das wire:poll — mit Lücken (goKaskade, konformitaetPruefen hatten keinen
        // eigenen Merker). Jetzt: aktiv, solange irgendein Signal noch etwas zu melden hat — inklusive
        // eines Steps, der eine Phase trägt (Konformitätsprüfung/Anreicherung/Bilder), auch wenn der
        // Run selbst schon nicht mehr `running` ist.
        $pollAktiv = $this->laeuft || $this->anreicherungLaeuft || ($lauf !== null && (
            $lauf->status === 'running'
            || $this->anreicherungOffen($lauf)
            || $lauf->steps->contains(fn ($s) => $s->phase !== null)
        ));

        // Spec 84 C1-1: Cockpit-Daten des Laufs (Kalkulation, Bild-Calls, Fotos, Konformität) in {@see LaufCockpit}
        ['kalkulation' => $kalkulation, 'bildCalls' => $bildCalls, 'bilderAngefordert' => $bilderAngefordert, 'fotoCounts' => $fotoCounts,
            'konformitaet' => $konformitaet, 'gpKonformitaet' => $gpKonformitaet] = app(LaufCockpit::class)->fuer($team, $lauf);

        // Etappe 7 Teil 3b — Foto-Wiederverwendungs-Picker: NUR wenn offen, die Kandidaten bauen
        // (vorhandene Team-Fotos zur Übernahme auf den offenen Draft). Team-scoped (visibleToTeam),
        // OHNE die eigenen Fotos des Ziel-Rezepts (Reuse = von woanders holen), jüngste zuerst,
        // gedeckelt (Kosten-/Runaway-Guard beim Rendern der Vorschau-URLs). Kein KI-Call — reine
        // Auswahl; die Übernahme selbst läuft über fotoUebernehmen (COPY-ON-REUSE, Teil 3a).
        $fotoPickerKandidaten = [];
        if ($team !== null && $this->fotoPickerStep !== null) {
            $pStep = DB::table('foodalchemist_cascade_run_steps')
                ->where('team_id', $team->id)->where('id', $this->fotoPickerStep)
                ->first(['ref_id', 'ref_type', 'kind']);
            if ($pStep !== null && $pStep->ref_type === 'recipe' && in_array($pStep->kind, ['rezept', 'gericht'], true)) {
                $kandidaten = FoodAlchemistRecipeStepPhoto::visibleToTeam($team)
                    ->when($pStep->ref_id !== null, fn ($q) => $q->where('recipe_id', '!=', (int) $pStep->ref_id))
                    ->with('recipe:id,name')
                    ->orderByDesc('id')
                    ->limit(24)
                    ->get();
                foreach ($kandidaten as $foto) {
                    $fotoPickerKandidaten[] = [
                        'id' => (int) $foto->id,
                        'url' => $foto->url(),
                        'caption' => (string) ($foto->caption ?? ''),
                        'rezept' => (string) ($foto->recipe?->name ?? ''),
                        'ergebnis' => (bool) $foto->is_result,
                    ];
                }
            }
        }

        return view('foodalchemist::livewire.planung.kaskaden-lauf', [
            'lauf' => $lauf, 'kiWartend' => $lauf !== null ? app(PlanningCascadeService::class)->kiWartend($lauf->steps) : [],   // Step-ID → offene KI-Aufträge (Lauf 62)
            'pollAktiv' => $pollAktiv,
            'kalkulation' => $kalkulation,
            'bildCalls' => $bildCalls,
            'bilderAngefordert' => $bilderAngefordert,
            'fotoCounts' => $fotoCounts,
            'konformitaet' => $konformitaet,
            'gpKonformitaet' => $gpKonformitaet,
            'fotoPickerKandidaten' => $fotoPickerKandidaten,
        ]);
    }
}
