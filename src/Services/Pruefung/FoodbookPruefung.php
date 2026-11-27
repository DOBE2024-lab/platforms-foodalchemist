<?php

namespace Platform\FoodAlchemist\Services\Pruefung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;

/**
 * Register der Foodbook-Qualität (Tranche D, 98 · 3.3 aus DataQualityService verschoben): je Check Label,
 * Signal-Deskriptor und Prädikat, dazu die Helfer (Foodbooks in Gebrauch, Kapitel-Prädikate, Kapitel-Ziele über
 * CoverageService, Preis-Drift). foodbookGerichtIds hat derzeit keinen Aufrufer (wörtlich mitverschoben). Nur lesend.
 */
final class FoodbookPruefung
{
    public function __construct(
        private \Platform\FoodAlchemist\Services\Concepter\CoverageService $coverage,
        private KonzeptPruefung $konzepte,
    ) {}

    /**
     * Tranche-D-Register — gleiche Bauart wie {@see rezeptQualitaetChecks} und
     * {@see konzeptChecks}: Label, Signal-Deskriptor und Prädikat je Check an EINER
     * Stelle, aus der Zähl-Seite, Objekt-Liste (`betroffene`) und Lifecycle (`countFor`)
     * gemeinsam ziehen. Objekt ist immer das **Foodbook**, nicht das Kapitel: es ist das,
     * was man öffnet und was einen Namen trägt — dieselbe Wahl wie in Tranche C, wo das
     * Konzept und nicht der Slot das Objekt ist.
     *
     * @return array<string,array{label:string,typ:SignalTyp,dedup:string,sev:SignalSeverity,desc:string,q:\Closure}>
     */
    public function foodbookChecks(): array
    {
        return [
            'foodbook_kapitel_leer' => [
                'label' => 'Foodbooks im Einsatz mit leerem Kapitel oder ganz ohne Inhalt',
                'typ' => SignalTyp::FoodbookKapitelLeer,
                'dedup' => 'dq-foodbook-kapitel-leer',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens ein Kapitel dieses Foodbooks hat keinen Inhalt: weder ein Paket oder Konzept '
                    . 'noch ein einzelnes Gericht. Überschriften, Texte, Abstände und Bilder zählen nicht als Inhalt. '
                    . 'Kapitel mit Unterkapiteln werden nicht geprüft, ihr Inhalt steht darunter. Ein ausgeblendeter '
                    . 'Block zählt ebenfalls nicht: im Kundendokument erscheint das Kapitel dann leer. Gemeldet '
                    . 'werden auch Foodbooks ganz ohne befülltes Kapitel.',
                'q' => fn (Team $t) => $this->foodbooksInGebrauch($t)->where(fn ($w) => $w
                    ->whereExists($this->kapitelOhneInhalt())
                    ->orWhereNotExists($this->kapitelMitInhalt())),
            ],
            'foodbook_skizze_ungeerdet' => [
                'label' => 'Foodbooks mit Gerichtsidee, die nie umgesetzt wurde',
                'typ' => SignalTyp::FoodbookSkizzeUngeerdet,
                'dedup' => 'dq-foodbook-skizze-ungeerdet',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Beim Anlegen eines Kapitels wurde eine Gerichtsidee an die KI übergeben, aber daraus ist '
                    . 'nie ein Gericht geworden. Der Hinweis erscheint erst ' . self::SKIZZE_STUCK_STUNDEN . ' Stunden '
                    . 'danach: vorher ist die Idee in Arbeit, danach hängt sie fest. Es geht um verlorene '
                    . 'Kreativarbeit: die Idee steht im Foodbook-Entwurf, im Sortiment fehlt sie.',
                'q' => fn (Team $t) => $this->foodbooksNichtArchiviert($t)->whereExists($this->ungeerdeteSkizze()),
            ],
            'foodbook_ziel_verfehlt' => [
                'label' => 'Foodbooks im Einsatz mit verfehltem Kapitelziel',
                'typ' => SignalTyp::FoodbookZielVerfehlt,
                'dedup' => 'dq-foodbook-ziel-verfehlt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens ein Kapitel dieses Foodbooks verfehlt sein Ziel: für die geplante Anzahl '
                    . 'Gerichte ist kein einziges Gericht da, oder der durchschnittliche VK im Kapitel liegt '
                    . 'außerhalb der geplanten Preisspanne. Unterkapitel übernehmen das Ziel des Kapitels darüber. '
                    . 'Gemeldet wird nur der rote Fall, „3 von 5 Gerichten da“ bleibt Arbeitsstand. Ohne '
                    . 'Planungsvorgabe gibt es keinen Befund.',
                'q' => fn (Team $t) => $this->foodbooksMitKapitelZielBefund($t),
            ],
            'foodbook_stale' => [
                'label' => 'Freigegebene Foodbooks mit überholtem Preis',
                'typ' => SignalTyp::FoodbookStale,
                'dedup' => 'dq-foodbook-stale',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Das Foodbook ist freigegeben oder beim Kunden, aber mindestens ein Preis im veröffentlichten Link '
                    . 'weicht stärker vom aktuell berechneten VK ab, als das Team erlaubt. Der Link nennt damit einen Preis, '
                    . 'den die Kalkulation nicht mehr ergibt — neu veröffentlichen übernimmt die aktuellen Preise. Foodbooks '
                    . 'in der Kalkulation zählen nicht mit, dort dürfen Preise sich bewegen.',
                'q' => fn (Team $t) => $this->foodbooksMitPreisDrift($t),
            ],
            // Spec 03 · L2b hat den Fixer nachgeliefert (Kapitel-Textfeld + KI-Knopf je Kapitel),
            // damit ist der §9-Vorbehalt erledigt und der Check darf scharf sein.
            'foodbook_kapitel_ohne_text' => [
                'label' => 'Foodbooks im Einsatz mit Kapitel ohne Hinführung',
                'typ' => SignalTyp::FoodbookKapitelOhneText,
                'dedup' => 'dq-foodbook-kapitel-ohne-text',
                // Bewusst `info`, nicht `warnung`: das Kapitel ist druckbar und inhaltlich
                // vollständig, es ist nur nicht ausformuliert. Als Warnung stünde eine
                // Formulierungs-Aufgabe neben einem falschen Kundenpreis.
                'sev' => SignalSeverity::Info,
                'desc' => 'Mindestens ein befülltes Kapitel dieses Foodbooks hat keinen Text für den Gast: im '
                    . 'Dokument folgt auf die Kapitelüberschrift direkt die Liste. Leere Kapitel zählen hier nicht, '
                    . 'sie brauchen zuerst Gerichte. Ergänzen im Kapitelkopf der Leitstelle über das Feld '
                    . '„Hinführung“, auf Wunsch mit KI-Text.',
                'q' => fn (Team $t) => $this->foodbooksInGebrauch($t)->whereExists($this->kapitelOhneText()),
            ],
        ];
    }

    /**
     * Spec 21 · S4c-2 — die frame-gestützte Hälfte von Tranche D. Baugleich zu
     * {@see konzepteMitFrameBefund} (PHP-Pass → `whereIn`, keine Memoisierung), aber auf
     * die **Kapitel-Befunde** geschnitten: `CoverageService::pruefeKapitel` stempelt genau
     * diesen Befunden eine `chapter_id` auf, alles ohne ist ein Frame-Kopf- oder Slot-Befund
     * und damit ein anderer Sachverhalt (der wäre das Foodbook-Gegenstück zu
     * `konzept_preisband_verletzt`, nicht das Kapitel-Ziel).
     *
     * **Die Ziel-Vererbung n-tief steckt in `kapitelKettenZiel`** (self + Eltern hoch, erstes
     * gesetztes gewinnt) und der Ist-Rollup über alle Nachfahren in `istFoodbook` — beides
     * wird hier bewusst NICHT nachgebaut. Eine eigene Auflösung wäre die zweite Wahrheit,
     * an der Signal und Planungs-Rail auseinanderlaufen.
     *
     * Bekannte Grenze, bewusst so: ohne Planungs-Gerüst am Buch gibt `coverage()` früh
     * `hat_geruest=false` zurück, ein von Hand gesetztes Kapitel-Ziel wird dann nirgends
     * ausgewertet — auch nicht in der UI. Der Signal-Layer richtet das nicht einseitig
     * (→ Verbesserungs-Backlog), sonst meldete er einen Befund, den keine Fläche zeigt.
     */
    private function foodbooksMitKapitelZielBefund(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $kandidaten = $this->foodbooksInGebrauch($team)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_planning_frames as pf')
                ->where('pf.owner_type', 'foodbook')
                ->whereColumn('pf.owner_id', 'foodalchemist_foodbooks.id')
                ->whereNull('pf.deleted_at'))
            ->pluck('id')->all();

        $treffer = [];
        foreach ($kandidaten as $id) {
            foreach ($this->coverage->coverage($team, 'foodbook', (int) $id)['befunde'] as $b) {
                if ($b['ampel'] === 'verletzt' && ($b['chapter_id'] ?? null) !== null) {
                    $treffer[] = (int) $id;
                    break;
                }
            }
        }

        return $this->foodbooksInGebrauch($team)->whereIn('id', $treffer);
    }

    /**
     * Spec 21 · S4c-2 — die Snapshot-Hälfte von Tranche D; Spec 85 F5 (Dominique 19.10.): die Quelle ist die
     * Ausgabe-Drift des veröffentlichten Links ({@see AusgabeDriftService}), nicht mehr eine separate VK-Freigabe.
     *
     * „Stale" heißt: der beim Veröffentlichen eingefrorene Kundenpreis weicht über die Team-Leitplanke vom live
     * gerechneten ab. Dieselbe Rechnung füttert das Signal `vk_anpassung_empfohlen` (je Ausgabe) — hier die Frage
     * „welches Buch steht mit einem falschen Preis beim Kunden". Geprüft wird der Team-Link (Kopf-Snapshot);
     * Betriebs-Links meldet das Signal je Betrieb.
     */
    private function foodbooksMitPreisDrift(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $treffer = [];
        foreach (app(AusgabeDriftService::class)->abgedriftet($team) as $d) {
            if ($d['doc_type'] === \Platform\FoodAlchemist\Services\Ausgabe\PresentationService::TYPE_FOODBOOK) {
                $treffer[] = (int) $d['doc_id'];
            }
        }

        return $this->foodbooksFreigegeben($team)->whereIn('id', $treffer);
    }

    /**
     * Dritte Arbeitsmenge der Tranche (nach `foodbooksInGebrauch` und der Go-Menge):
     * Bücher, die **draußen** sind. Gegenüber `foodbooksInGebrauch` fällt genau die Phase
     * `kalkulation` weg — dort ist das Verschieben von Preisen die Arbeit selbst, und ein
     * Signal darauf wäre exakt das Über-Flaggen, das Spec 21 §9 ausschließt. Der
     * Versand-Snapshot am Kapitel bleibt drin: was versendet wurde, ist beim Kunden.
     */
    private function foodbooksFreigegeben(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return $this->foodbooksNichtArchiviert($team)
            ->where(fn ($w) => $w
                ->whereIn('status', self::FOODBOOK_STATUS_IN_GEBRAUCH)
                ->orWhere('phase', 'freigabe')
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as sk')
                    ->whereColumn('sk.foodbook_id', 'foodalchemist_foodbooks.id')
                    ->whereNull('sk.deleted_at')
                    ->whereNotNull('sk.snapshot_at')));
    }

    /**
     * Die Gerichte EINES Foodbooks über beide Befüllungs-Arten der Spec-19-Duality:
     * `recipe_ref`-Block (Einzelgericht) und `concept_ref`-Block (Paket/Konzept, dessen
     * Gerichte über {@see konzeptGerichtIds} kommen — inklusive Paket-Slots).
     *
     * **Nur sichtbare Blöcke**, dieselbe Grenze wie bei {@see kapitelOhneInhalt}: beurteilt
     * wird, was im Kundendokument LANDET. Ein ausgeschalteter Block trägt keinen Preis nach
     * draußen und kann deshalb auch keinen überholten zeigen.
     *
     * @return list<int>
     */
    private function foodbookGerichtIds(int $foodbookId): array
    {
        $bloecke = DB::table('foodalchemist_foodbook_blocks as fb')
            ->join('foodalchemist_foodbook_chapters as k', 'k.id', '=', 'fb.chapter_id')
            ->where('k.foodbook_id', $foodbookId)
            ->whereNull('k.deleted_at')
            ->whereNull('fb.deleted_at')
            ->where('fb.visible', true)
            ->get(['fb.sales_recipe_id', 'fb.concept_id']);

        $ids = [];
        foreach ($bloecke as $b) {
            if ($b->sales_recipe_id !== null) {
                $ids[] = (int) $b->sales_recipe_id;
            }
            if ($b->concept_id !== null) {
                $ids = array_merge($ids, $this->konzepte->konzeptGerichtIds((int) $b->concept_id));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Foodbooks, die real benutzt werden — nur hier ist ein leeres Kapitel ein Mangel.
     * Drei Wege in den Gebrauch, jeder für sich ausreichend:
     *  · `status` — das Buch ist live bzw. schon beim Kunden (s. FOODBOOK_STATUS_IN_GEBRAUCH).
     *  · `phase` ab `kalkulation` — im Planungs-Workflow ist die Befüllung erklärt-fertig
     *    (PhaseService::PHASEN: kontext → struktur → befuellung → **kalkulation** → freigabe).
     *    Das ist der belastbarere Marker als der Status: die Phase führt die Leitstelle
     *    selbst, den Status setzt jemand von Hand (im Bestand steht er durchweg auf `draft`).
     *  · ein Kapitel mit `snapshot_at` — der Versand hat es eingefroren, es war beim Kunden.
     */
    private function foodbooksInGebrauch(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return $this->foodbooksNichtArchiviert($team)
            ->where(fn ($w) => $w
                ->whereIn('status', self::FOODBOOK_STATUS_IN_GEBRAUCH)
                ->orWhereIn('phase', self::FOODBOOK_PHASEN_IN_GEBRAUCH)
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as sk')
                    ->whereColumn('sk.foodbook_id', 'foodalchemist_foodbooks.id')
                    ->whereNull('sk.deleted_at')
                    ->whereNotNull('sk.snapshot_at')));
    }

    /**
     * Basismenge beider Tranche-D-Checks: alles außer `archiviert`. Ein archiviertes Buch
     * ist ausgemustert — dieselbe Grenze wie bei den Konzepten.
     */
    private function foodbooksNichtArchiviert(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)->where('status', '!=', 'archiviert');
    }

    /** Ab dieser Phase gilt die Befüllung als erklärt-fertig (s. PhaseService::PHASEN). */
    private const FOODBOOK_PHASEN_IN_GEBRAUCH = ['kalkulation', 'freigabe'];

    /**
     * Status-Werte, die „nicht mehr Arbeitsstand" bedeuten.
     *
     * Hier standen bis Spec 33 drei Werte (`aktiv`, `active`, `versendet`), weil das Vokabular
     * im Bestand widersprüchlich war und der Check lieber zu viel als zu wenig messen sollte.
     * **Der Kanon steht jetzt:** es gibt nur noch `aktiv` — Versenden SETZT darauf, es ist kein
     * eigener Zustand mehr ({@see \Platform\FoodAlchemist\Enums\AusgabeStatus}). Die
     * Normalisierungs-Migration hat den Bestand mitgezogen.
     */
    private const FOODBOOK_STATUS_IN_GEBRAUCH = [\Platform\FoodAlchemist\Enums\AusgabeStatus::Aktiv->value];

    /**
     * Block-Typen, die Inhalt TRAGEN. Der Rest des Vokabulars (`header`/`text`/`spacer`/
     * `image`) beschreibt oder gliedert ihn — dieselbe Unterscheidung wie
     * `ConceptService::STRUKTUR_TYPEN` auf der Konzept-Ebene.
     */
    private const INHALTS_BLOCK_TYPEN = ['concept_ref', 'recipe_ref'];

    /**
     * So lange nach dem Kapitel-Go darf eine Skizze queued bleiben, ohne aufzufallen.
     * Der Go dispatcht die Jobs sofort (`verarbeiteFreitextQueue`); wer nach zwei Tagen
     * noch nichts erzeugt hat, wartet nicht, sondern steckt. Kürzer wäre Rauschen (ein
     * Provider-Ausfall über Nacht), länger würde verlorene Kreativarbeit verschweigen.
     */
    private const SKIZZE_STUCK_STUNDEN = 48;

    /**
     * EXISTS: ein Kapitel dieses Foodbooks ohne eine einzige Inhalts-Zeile.
     *
     * Drei Einschränkungen, jede mit eigenem Grund:
     *  · **Blattkapitel only** — ein Kapitel mit Unterkapiteln ist eine Klammer; sein
     *    Inhalt hängt an den Kindern (Spec 19: Kapitel-Scope = Kapitel + Nachfahren).
     *    Ohne diese Grenze wäre jede saubere Baum-Gliederung ein Befund.
     *  · **`archived`-Kapitel draußen** — dieselbe Logik wie beim Buch-Status.
     *  · **`visible=true`** am Inhalts-Block: das Kapitel wird danach beurteilt, was im
     *    Kundendokument LANDET. Ein ausgeschalteter Block ist im Export nicht da.
     */
    private function kapitelOhneInhalt(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->where('k.status', '!=', 'archived')
            ->whereNotExists(fn ($kind) => $kind->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as kk')
                ->whereColumn('kk.parent_id', 'k.id')
                ->whereNull('kk.deleted_at'))
            ->whereNotExists(fn ($b) => $b->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                ->whereColumn('fb.chapter_id', 'k.id')
                ->whereNull('fb.deleted_at')
                ->where('fb.visible', true)
                ->whereIn('fb.type', self::INHALTS_BLOCK_TYPEN));
    }

    /**
     * EXISTS: irgendein Kapitel dieses Foodbooks trägt Inhalt. Gegenstück zum zweiten
     * Zweig von `foodbook_kapitel_leer` — dieselbe Lücke, die auf der Konzept-Ebene
     * `belegterInhaltsSlot` schließt: ohne diesen Zweig wäre ein Buch mit NULL Kapiteln
     * unauffällig, weil es kein leeres Kapitel hat.
     */
    private function kapitelMitInhalt(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->where('k.status', '!=', 'archived')
            ->whereExists(fn ($b) => $b->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                ->whereColumn('fb.chapter_id', 'k.id')
                ->whereNull('fb.deleted_at')
                ->where('fb.visible', true)
                ->whereIn('fb.type', self::INHALTS_BLOCK_TYPEN));
    }

    /**
     * EXISTS: ein Kapitel dieses Foodbooks, das Inhalt trägt, aber keinen Kundentext.
     *
     * Die tragende Grenze ist **„trägt Inhalt"** und damit die Umkehrung von
     * {@see kapitelOhneInhalt}: beide Checks teilen dieselbe Inhalts-Definition (sichtbarer
     * `concept_ref`/`recipe_ref`-Block), aber sie schließen sich aus. Ein leeres Kapitel
     * braucht keine Hinführung, sondern Gerichte — es dort zusätzlich zu melden hieße,
     * einen Sachverhalt zweimal zu zählen (Spec 21 §9).
     *
     * Anders als beim Inhalts-Check ist **kein Blatt-Filter** nötig: geprüft wird, was
     * Inhalt trägt, und das kann auch ein Eltern-Kapitel mit eigenen Blöcken sein. Eine
     * reine Klammer ohne eigene Blöcke fällt über dieselbe Bedingung heraus.
     *
     * `visible` wird bewusst NICHT am Text geprüft — das Feld hat kein Sichtbarkeits-Flag:
     * `dokumentDaten` gibt `description` als `text` je Kapitel heraus, sobald das Kapitel
     * gedruckt wird (Projektion mit L2b nachgezogen; vorher las sie niemand).
     */
    private function kapitelOhneText(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->where('k.status', '!=', 'archived')
            // Haus-Muster der Wording-Checks: NULL, leer und „nur Leerzeichen" sind dasselbe
            // (das Formular schreibt '' statt NULL, ein versehentliches Space wäre kein Text).
            ->whereRaw("LENGTH(TRIM(COALESCE(k.description, ''))) = 0")
            ->whereExists(fn ($b) => $b->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                ->whereColumn('fb.chapter_id', 'k.id')
                ->whereNull('fb.deleted_at')
                ->where('fb.visible', true)
                ->whereIn('fb.type', self::INHALTS_BLOCK_TYPEN));
    }

    /**
     * EXISTS: eine Freitext-Skizze, die der Kapitel-Go in die KI-Queue gestellt hat und
     * die dort hängen geblieben ist.
     *
     * Der Zustand ist exakt der, den `materialisiereFreitextIdee` als „noch offen" liest
     * (`sales_recipe_id` null · `generation_status='queued'` · `materialized_at` null) —
     * gespiegelt statt neu formuliert, damit Signal und Queue nicht auseinanderlaufen.
     * Dazu `generated_recipe_id` null: ein erzeugtes Rezept ist das Ergebnis, auch wenn
     * der Rest-Stempel fehlen sollte.
     *
     * `fehlgeschlagen` zählt bewusst NICHT: dort ist die KI gelaufen und hat verloren, das
     * meldet die Leitstelle am Kapitel selbst. Hier geht es um den stillen Fall — niemand
     * bekommt mit, dass nichts passiert ist.
     */
    private function ungeerdeteSkizze(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_chapters as k')
            ->whereColumn('k.foodbook_id', 'foodalchemist_foodbooks.id')
            ->whereNull('k.deleted_at')
            ->whereNotNull('k.released_at')
            ->where('k.released_at', '<', now()->subHours(self::SKIZZE_STUCK_STUNDEN))
            ->whereExists(fn ($i) => $i->select(DB::raw(1))->from('foodalchemist_dish_ideas as di')
                ->whereColumn('di.chapter_id', 'k.id')
                ->whereNull('di.deleted_at')
                ->where('di.generation_status', 'queued')
                ->whereNull('di.sales_recipe_id')
                ->whereNull('di.generated_recipe_id')
                ->whereNull('di.materialized_at')
                ->where('di.status', '!=', 'verworfen'));
    }
}
