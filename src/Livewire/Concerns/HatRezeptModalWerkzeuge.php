<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Plattform\BulkEnrichService;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SensorikService;

/**
 * 98 · 2.2c: was Rezept-Modal (Basisrezept) und VK-Modal (Gericht) gleich tun — Gerichtsbild, KI-Produktfoto,
 * Sensorik, „Alles anreichern", KI-Überarbeitung und die Sperr-Haken. Vorher stand jede Methode zweimal da.
 * Wo sich die Modale fachlich unterscheiden (Rezept- vs. Gericht-Service, Beispieltext, Form-Übernahme), fragen
 * die Methoden über kleine Haken nach; das Verhalten beider Modale ist unverändert.
 *
 * Erwartet von der Komponente: `$recipeId`, `$fehler`, `$form`, `$zutatenVersion`, `oeffnen()`, sowie
 * {@see HatAnreicherungsLauf}, {@see InteractsWithSavedToast} und {@see MitBearbeitungssperre}.
 */
trait HatRezeptModalWerkzeuge
{
    /** Ist das Modal gerade offen? Beim Schließen per X (modal.closed) und beim Löschen wieder false. */
    public bool $istOffen = false;

    public bool $ueberarbeitenOffen = false;

    public string $anweisung = '';

    public ?array $ueberarbeitung = null;

    /** Re-Mount-Zähler für den eingebetteten Step-Editor (trägt die Fotos) — s. kiProduktfoto. */
    public int $fotoVersion = 0;

    public ?int $bulkRunId = null;

    public ?array $anreicherung = null;

    public bool $produktfotoLaeuft = false;

    public ?string $produktfotoFehler = null;

    // ── Haken je Modal ───────────────────────────────────────────────────

    /** Rezept bzw. Gericht für „Alles anreichern" (null = nicht sichtbar, nichts tun). */
    abstract protected function anreicherungsRezept(Team $team): ?FoodAlchemistRecipe;

    /** Fehlertext ohne Anweisung, mit einem Beispiel passend zum Modal. */
    abstract protected function ueberarbeitungsPflichtText(): string;

    /** Rezept bzw. Gericht für die KI-Überarbeitung (null = nicht sichtbar, nichts tun). */
    abstract protected function ueberarbeitungsRezept(Team $team): ?FoodAlchemistRecipe;

    /** @return array{werte: array<string, mixed>, confidence: mixed} roher Vorschlag des RecipeReviseService */
    abstract protected function ueberarbeitungsVorschlag(Team $team, FoodAlchemistRecipe $r): array;

    /** Enthält der Vorschlag etwas Verwertbares (Zutaten oder einen der Texte des Modals)? */
    abstract protected function ueberarbeitungVerwertbar(array $werte): bool;

    /**
     * Der Schreib-Moment beim Übernehmen samt Übernahme der Texte ins Formular. Darf \RuntimeException werfen
     * (wird als Fehler gezeigt). false = nichts übernommen, Vorschlag bleibt offen.
     */
    abstract protected function ueberarbeitungSchreiben(Team $team, array $werte, mixed $konfidenz): bool;

    // ── Sperre (Spec 65) ─────────────────────────────────────────────────

    /** Spec 65: Sperre gilt dem Rezept-Datensatz — derselbe Schlüssel wie in den eingebetteten Zutaten-/Schritt-Editoren. */
    protected function sperrZiel(): ?array
    {
        return $this->recipeId !== null ? ['recipe', $this->recipeId] : null;
    }

    /** Spec 65: Editor geschlossen → eigene Sperre frei (einziger modal.closed-Handler dieser Komponente). */
    #[On('modal.closed')]
    public function beiModalGeschlossen(?string $name = null): void
    {
        if ($name !== null && $name === $this->sperrModalName()) {
            $this->istOffen = false;   // 98 · 2.2c: sonst reagiert beiZutatenPersistiert auch nach dem Schließen
        }
        $this->sperreBeiSchliessen($name);
    }

    /**
     * #1b: Der eingebettete Zutaten-/Komponenten-Editor hat erfolgreich gespeichert → Bearbeitung beenden. Adressiert wie
     * MVP-046: nur, wenn genau dieses OFFENE Rezept gemeint ist. Ein geschlossenes Modal hält keine Sperre mehr; ein
     * bearbeitenBeenden() hier gäbe sonst die Sperre eines anderen offenen Editors desselben Nutzers auf demselben Rezept
     * frei (gleicher Schlüssel recipe/id, 98 · 2.2c). Der Erfolgs-Toast kommt vom Zutaten-Editor selbst (ein Toast, kein Doppel).
     */
    #[On('zutaten-persistiert')]
    public function beiZutatenPersistiert(?int $recipeId = null): void
    {
        if ($this->istOffen && $recipeId !== null && $this->recipeId === $recipeId) {
            // Spec 65 (Dominique 2026-10-07): Speichern beendet die Bearbeitung und gibt die Sperre frei, der Editor
            // BLEIBT offen im Lesemodus — man prüft das Ergebnis (frisch gerechnete Kennzahlen) und schließt selbst.
            $this->bearbeitenBeenden();
        }
    }

    // ── Gerichtsbild ─────────────────────────────────────────────────────

    /** Spec 43 (Bild-Epic): Gericht-Foto — dasselbe Bild-Feld an Basisrezept und Gericht (recipes.image_*). */
    public $dishImageUpload = null;

    public function updatedDishImageUpload(): void
    {
        if ($this->recipeId === null || $this->dishImageUpload === null) {
            return;
        }
        // Spec 65: updated-Hooks laufen NICHT über den call-Haken — ohne eigene Sperre nicht schreiben.
        if (self::sperreAktiv() && ! $this->darfSchreiben()) {
            $this->reset('dishImageUpload');

            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        \Platform\FoodAlchemist\Support\BildUpload::pruefen($this, 'dishImageUpload');
        try {
            app(RecipeService::class)->storeDishImage($team, $this->recipeId, $this->dishImageUpload);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->reset('dishImageUpload');
    }

    public function dishImageEntfernen(): void
    {
        if ($this->recipeId !== null) {
            app(RecipeService::class)->clearDishImage(Auth::user()?->currentTeamRelation, $this->recipeId);
        }
    }

    // ── ✨ Sensorik ───────────────────────────────────────────────────────

    /** ✨ Sensorik: KI bewertet das GEGARTE Rezept bzw. Gericht (Zutaten + Zubereitung) → Recipe-Sensorik-Tabellen. */
    public function sensorikBewerten(): void
    {
        $this->fehler = null;
        if ($this->recipeId !== null) {
            try {
                app(SensorikService::class)->bewerteRezept($this->recipeId, true);
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }
    }

    // ── ✨ KI-Überarbeitung (Freitext-Anweisung) ──────────────────────────

    public function kiUeberarbeiten(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || trim($this->anweisung) === '') {
            $this->fehler = $this->ueberarbeitungsPflichtText();

            return;
        }
        $this->fehler = null;
        $r = $this->ueberarbeitungsRezept($team);
        if ($r === null) {
            return;
        }

        try {
            // Spec 03 L1a + Workstream W: die grounded Freitext-Revision liegt im geteilten
            // RecipeReviseService (Regelwerk-Erdung via contextFor) — Web + MCP fahren dieselbe Strecke.
            $roh = $this->ueberarbeitungsVorschlag($team, $r);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $werte = $roh['werte'];

        if (! $this->ueberarbeitungVerwertbar($werte)) {
            $this->fehler = 'KI lieferte keine verwertbare Überarbeitung — echter Provider nötig (FakeProvider-Grenze).';

            return;
        }
        $this->ueberarbeitung = [
            'werte' => $werte,
            'confidence' => $roh['confidence'],
            // E3 (#508): Vorschau, wie das Grounding beim Übernehmen greift.
            'match_vorschau' => app(RecipeReviseService::class)->vorschau($team, $r, $werte['zutaten'] ?? []),
        ];
    }

    /** Übernehmen = der EINE Schreib-Moment: Zutaten-/Komponenten-Sync + Text-Felder mit Lineage ki. */
    public function ueberarbeitungUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->ueberarbeitung === null) {
            return;
        }

        try {
            // Spec 84 C5: derselbe Schreib-Moment wie recipes.REVISE bzw. verkaufsrezepte.REVISE (Eigentum, Override-First, Lineage im Service)
            if (! $this->ueberarbeitungSchreiben($team, $this->ueberarbeitung['werte'], $this->ueberarbeitung['confidence'])) {
                return;
            }
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $this->ueberarbeitung = null;
        $this->anweisung = '';
        $this->ueberarbeitenOffen = false;
        $this->zutatenVersion++;                                      // eingebetteten Editor neu mounten (Client-rows!)
        $this->dispatch('recipe-gespeichert');
    }

    public function ueberarbeitungVerwerfen(): void
    {
        $this->ueberarbeitung = null;                                 // reject lässt Fachdaten unberührt (GL-07)
    }

    // ── ✨ Alles anreichern (D-5 §4.4 auf EIN Rezept — Bulk-Mechanik M7-06) ──

    public function allesAnreichern(): void
    {
        $this->fehler = null;
        $this->anreicherung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            if ($this->anreicherungsRezept($team) === null) {
                return;
            }
            $this->bulkRunId = null;
            $this->starteAnreicherung($team);   // Hintergrund-Job + Polling, s. HatAnreicherungsLauf
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function bulkAlleUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->bulkRunId !== null) {
            app(BulkEnrichService::class)->alleUebernehmen($team, $this->bulkRunId);
            $id = $this->recipeId;
            $this->bulkRunId = null;
            $this->oeffnen($id);                                       // Form mit den übernommenen Werten neu laden
            $this->dispatch('recipe-gespeichert');
        }
    }

    // ── Spec 53 Bildstil-Dossier/Produktfoto-Knopf ────────────────────────

    /**
     * „KI-Produktfoto erzeugen" — dispatcht {@see EnrichRecipeJob} im `nurProduktfoto`-Modus
     * (ersetzt NUR das Hero-Foto, keine Schrittfotos, keine Anreicherung). Der Web-Request kehrt
     * sofort zurück (kein 502), die UI pollt über {@see self::pruefeProduktfotoErgebnis}.
     *
     * Doppel-Enqueue-Guard: kein `$stepId` in diesen Modalen (auch Rezepte ausserhalb jeder
     * Kaskade editierbar) — darum ein eigener Cache-Schlüssel je Team+Rezept statt des
     * step-gebundenen `deferred.bilder`-Mechanismus (s. {@see EnrichRecipeJob}-Klassendoc).
     */
    public function kiProduktfoto(): void
    {
        $this->produktfotoFehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $key = EnrichRecipeJob::produktfotoCacheKey((int) $team->id, (int) $this->recipeId);
        $stand = Cache::get($key);
        if (is_array($stand) && in_array($stand['status'] ?? null, ['queued', 'running'], true)) {
            $this->produktfotoLaeuft = true;   // Lauf ist schon unterwegs — nicht doppelt dispatchen
            return;
        }
        Cache::put($key, ['status' => 'queued', 'at' => now()->toIso8601String()], now()->addMinutes(15));
        EnrichRecipeJob::dispatch(
            teamId: (int) $team->id,
            userId: (int) (Auth::id() ?? 0),
            recipeId: (int) $this->recipeId,
            nurProduktfoto: true,
        );
        $this->produktfotoLaeuft = true;
        $this->savedToast('✨ KI-Produktfoto wird erzeugt …');
    }

    /** Poll-Ziel (wire:poll während $produktfotoLaeuft): liest den Job-Ausgang aus dem Cache. */
    public function pruefeProduktfotoErgebnis(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            $this->produktfotoLaeuft = false;

            return;
        }
        $stand = Cache::get(EnrichRecipeJob::produktfotoCacheKey((int) $team->id, (int) $this->recipeId));
        if (! is_array($stand) || in_array($stand['status'] ?? null, ['queued', 'running'], true)) {
            return;   // noch am Rechnen → weiter pollen
        }
        $this->produktfotoLaeuft = false;
        if (($stand['status'] ?? null) === 'failed') {
            $this->produktfotoFehler = $stand['error'] ?? 'KI-Produktfoto fehlgeschlagen.';
            $this->errorToast('KI-Produktfoto fehlgeschlagen.');

            return;
        }
        // done → NUR den eingebetteten Step-Editor (trägt die Fotos) neu mounten lassen, wie beim
        // Zutaten-Editor ($zutatenVersion) — NICHT oeffnen(): das würde das ganze Formular verwerfen
        // und damit noch ungespeicherte Eingaben in anderen Feldern löschen, nur weil im Hintergrund
        // ein Foto fertig wurde.
        $this->fotoVersion++;
        $this->savedToast('✅ KI-Produktfoto erzeugt.');
    }
}
