<?php

namespace Platform\FoodAlchemist\Services\Pruefung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;

/**
 * Register der Konzept-Qualität (Tranche C, 98 · 3.3 aus DataQualityService verschoben): je Check Label,
 * Signal-Deskriptor und Prädikat, dazu die Helfer (Konzepte in Gebrauch, Frame-Befunde über CoverageService,
 * Wiederholungen über PairingService, Slot-Prädikate). Nur lesend.
 */
final class KonzeptPruefung
{
    public function __construct(
        private \Platform\FoodAlchemist\Services\Concepter\CoverageService $coverage,
        private \Platform\FoodAlchemist\Services\Pairing\PairingService $pairing,
    ) {}

    /**
     * Tranche-C-Register — gleiche Bauart wie {@see rezeptQualitaetChecks}: Label,
     * Signal-Deskriptor und Prädikat je Check an EINER Stelle, aus der Zähl-Seite,
     * Objekt-Liste (`betroffene`) und Lifecycle (`countFor`) gemeinsam ziehen.
     *
     * @return array<string,array{label:string,typ:SignalTyp,dedup:string,sev:SignalSeverity,desc:string,q:\Closure}>
     */
    public function konzeptChecks(): array
    {
        return [
            'konzept_slot_luecke' => [
                'label' => 'Konzepte im Einsatz mit unbesetzter Pflichtposition',
                'typ' => SignalTyp::KonzeptSlotLuecke,
                'dedup' => 'dq-konzept-slot-luecke',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Das Konzept ist im Einsatz (aktiv, in einem Angebot oder in einem Foodbook), aber '
                    . 'mindestens eine Pflichtposition ist weder mit einem Gericht noch mit einem Paket belegt, oder '
                    . 'es ist gar keine Position belegt. Die Lücke schlägt bis ins Angebot und ins Kundendokument '
                    . 'durch: der Preis pro Person rechnet ohne die fehlende Position, die Zeile fehlt im Menü.',
                'q' => fn (Team $t) => $this->konzepteInGebrauch($t)->where(fn ($w) => $w
                    ->whereExists($this->offenerPflichtSlot())
                    ->orWhereNotExists($this->belegterInhaltsSlot())),
            ],
            'konzept_ohne_wording' => [
                'label' => 'Konzepte im Einsatz mit Gericht ohne Kundentext',
                'typ' => SignalTyp::KonzeptOhneWording,
                'dedup' => 'dq-konzept-ohne-wording',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens ein Gericht in diesem Konzept hat keinen Text für den Gast, weder an der Position '
                    . 'noch am Gericht. Dann erscheint dort der interne Arbeitsname mit Kürzeln und Trennstrichen, '
                    . 'genau der Text, der nie beim Kunden landen darf. Kundentext am Gericht oder an der Position '
                    . 'ergänzen.',
                'q' => fn (Team $t) => $this->konzepteInGebrauch($t)->whereExists($this->slotOhneWording()),
            ],
            'konzept_preisband_verletzt' => [
                'label' => 'Konzepte im Einsatz außerhalb der Preisspanne',
                'typ' => SignalTyp::KonzeptPreisbandVerletzt,
                'dedup' => 'dq-konzept-preisband-verletzt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Der Preis pro Person liegt außerhalb der Spanne aus der Planungsvorgabe, oder der '
                    . 'Preisrahmen einer Position ist nicht eingehalten. Gemeldet wird nur der rote Fall: eine Abweichung '
                    . 'vom Zielpreis innerhalb der Spanne bleibt eine Kalkulationsfrage. Ohne Planungsvorgabe gibt '
                    . 'es keinen Befund.',
                'q' => fn (Team $t) => $this->konzepteMitFrameBefund($t, 'preis'),
            ],
            'konzept_regel_verletzt' => [
                'label' => 'Konzepte im Einsatz mit verletzter Kundenvorgabe',
                'typ' => SignalTyp::KonzeptRegelVerletzt,
                'dedup' => 'dq-konzept-regel-verletzt',
                'sev' => SignalSeverity::Warnung,
                'desc' => 'Mindestens eine Kundenvorgabe aus der Planung ist verletzt: der geforderte Anteil einer '
                    . 'Ernährungsform ist nicht erreicht, eine ausgeschlossene Zutat oder ein ausgeschlossenes '
                    . 'Allergen kommt vor, oder eine geforderte Saison fehlt. Das sind Zusagen an den Kunden, und '
                    . 'eine gebrochene Zusage fällt beim Lesen nicht auf. Weiche Vorgaben zählen hier nicht mit.',
                'q' => fn (Team $t) => $this->konzepteMitFrameBefund($t, 'regel'),
            ],
            'konzept_dramaturgie' => [
                'label' => 'Konzepte im Einsatz mit wiederholter Hauptzutat',
                'typ' => SignalTyp::KonzeptDramaturgie,
                'dedup' => 'dq-konzept-dramaturgie',
                'sev' => SignalSeverity::Info,
                'desc' => 'Zwei oder mehr Gänge dieses Konzepts haben dieselbe Hauptzutat, also die mengenmäßig '
                    . 'größte Zutat des Gerichts. Sorten zählen zusammen (Lachs und Wildlachs). Nur ein Hinweis: '
                    . 'ein Themenmenü darf sich wiederholen. Gerichte ohne vergleichbare Mengen oder ohne '
                    . 'Aromaprofil bleiben unbewertet.',
                'q' => fn (Team $t) => $this->konzepteMitWiederholung($t),
            ],
        ];
    }

    /**
     * Befund-Dimensionen aus {@see CoverageService}, die als Regel-Verletzung zählen.
     * Bewusst NUR die Regel-Dimensionen (`RULE_TYPES`): `menge`/`dramaturgie` kommen aus
     * den Gerüst-Slots, nicht aus einer Regel — sie gehören begrifflich zur Slot-Lücke
     * und würden hier zwei verschiedene Sachverhalte in einem Signal vermischen.
     */
    private const FRAME_REGEL_DIMENSIONEN = ['diaet', 'nogo', 'saison'];

    /**
     * Spec 21 · S4b — die frame-gestützte Hälfte von Tranche C.
     *
     * Beide Checks fallen aus EINEM `CoverageService::coverage()`-Aufruf je Konzept
     * (Preis-Kopf bzw. Regel-Befunde), darum EIN Helfer statt zwei: die Lade- und
     * Auflöse-Logik existiert genau einmal, und wer sie ändert, ändert beide Checks.
     *
     * Anders als die S4a-Checks ist das **kein Builder-Prädikat** — `coverage()` lädt je
     * Konzept Slots, Regeln, Gerichte und ruft `preisCockpit()`. Muster ist deshalb der
     * PHP-Pass aus S1b (`namingVerstossIds`): erst die IDs bestimmen, dann `whereIn`,
     * damit `betroffene()`/`countFor()`/`trifftObjekt()` unverändert weiterfunktionieren.
     *
     * Zwei Dinge halten die Kosten klein:
     *  · Die Arbeitsmenge ist doppelt geschnitten — in Gebrauch (S4a) UND mit Gerüst.
     *    Ohne Gerüst gibt es kein Soll; ein Konzept ohne gesetzte Preis-/Regel-Erwartung
     *    kann sie nicht verletzen (`coverage()` gäbe dort `hat_geruest=false` zurück).
     *  · Bewusst OHNE Memoisierung, aus demselben Grund wie {@see nameScan}: der Service
     *    wird auch NACH einem Fix erneut gefragt (`countFor` ⇒ Signal schließen), ein
     *    Cache meldete dort den alten Stand.
     *
     * Gemeldet wird ausschließlich `ampel='verletzt'` — also genau das, was das Concepter-
     * Cockpit rot zeigt. `teilerfuellt` bleibt draußen: „noch keine bepreisten Positionen"
     * oder „>10 % vom Zielpreis, aber in der Spanne" sind Arbeitsstände, keine Fehler.
     * Damit sagen Ampel und Signal dasselbe — sonst gäbe es zwei Wahrheiten über dasselbe
     * Konzept.
     *
     * @param  'preis'|'regel'  $art
     */
    private function konzepteMitFrameBefund(Team $team, string $art): \Illuminate\Database\Eloquent\Builder
    {
        $kandidaten = $this->konzepteInGebrauch($team)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_planning_frames as pf')
                ->where('pf.owner_type', 'concept')
                ->whereColumn('pf.owner_id', 'foodalchemist_concepts.id')
                ->whereNull('pf.deleted_at'))
            ->pluck('id')->all();

        $treffer = [];
        foreach ($kandidaten as $id) {
            foreach ($this->coverage->coverage($team, 'concept', (int) $id)['befunde'] as $b) {
                if ($b['ampel'] !== 'verletzt') {
                    continue;
                }
                $passt = $art === 'preis'
                    ? $b['dimension'] === 'preis'
                    : in_array($b['dimension'], self::FRAME_REGEL_DIMENSIONEN, true);
                if ($passt) {
                    $treffer[] = (int) $id;
                    break;
                }
            }
        }

        return $this->konzepteInGebrauch($team)->whereIn('id', $treffer);
    }

    /**
     * Spec 21 · S4b-2 — die Anker-Graph-Hälfte von Tranche C.
     *
     * Anders als {@see konzepteMitFrameBefund} braucht dieser Check **kein Planungs-Gerüst**:
     * er misst das Konzept gegen sich selbst (welche Gänge tragen dieselbe Hauptzutat) und
     * greift damit auch dort, wo niemand ein Soll gesetzt hat. Die Arbeitsmenge bleibt die
     * aus S4a (in Gebrauch), enger geschnitten wird nur über die Sache selbst: unter zwei
     * Gerichten gibt es keine Menüfolge.
     *
     * Der Befund kommt aus {@see PairingService::menuRepetitions} — bewusst NICHT aus
     * `menuCohesion`, obwohl der Fahrplan das zuerst so vorsah: dessen Score liest einen
     * geteilten Anker als maximale Nähe (1,0), was für den Teller stimmt und für die
     * Menüfolge die Diagnose umdreht; und seine Anker-Union je Gericht enthält jede
     * Nebenzutat, „zweimal Butter" wäre dort dasselbe Signal wie „zweimal Lachs".
     * Begründung im Detail am Service.
     *
     * `severity=info`: eine Wiederholung ist kein Fehler, sondern eine Frage an die
     * Dramaturgie — ein Themen-Menü (Kürbis-Menü) wiederholt bewusst. Als Warnung wäre
     * das genau das Über-Flaggen, das Spec 21 §9 ausschließt.
     */
    private function konzepteMitWiederholung(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $treffer = [];
        foreach ($this->konzepteInGebrauch($team)->pluck('id') as $id) {
            if ($this->pairing->menuRepetitions($this->konzeptGerichtIds((int) $id)) !== []) {
                $treffer[] = (int) $id;
            }
        }

        return $this->konzepteInGebrauch($team)->whereIn('id', $treffer);
    }

    /**
     * Die Gerichte EINES Konzepts über beide Befüllungs-Arten — Gericht-Slot und
     * Paket-Slot (dieselben zwei Wege wie {@see slotOhneWording}; ein Paket bringt
     * seine Gerichte in die Menüfolge ein, auch wenn sie an keinem eigenen Slot hängen).
     *
     * @return list<int>
     */
    private function konzeptGerichtIds(int $conceptId): array
    {
        $direkt = DB::table('foodalchemist_concept_slots')
            ->where('concept_id', $conceptId)
            ->whereNull('deleted_at')
            ->whereNotNull('sales_recipe_id')
            ->pluck('sales_recipe_id')->all();

        return array_values(array_unique(array_map('intval', $direkt)));
    }

    /**
     * Konzepte, die real benutzt werden — nur hier ist Unvollständigkeit ein Mangel.
     * Vier Wege in den Gebrauch: Status `aktiv`, an einem Angebot (direkt oder über die
     * Angebots-Zuordnung), oder in einem Foodbook-Block referenziert. Vorlagen
     * (`is_template`) sind per Definition Gerüste und bleiben draußen, `archiviert` ist
     * ausgemustert.
     */
    private function konzepteInGebrauch(Team $team): \Illuminate\Database\Eloquent\Builder
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->konzepte()   // Kaskade: Datenqualität prüft Konzepte, nicht Pakete
            ->where('is_template', false)
            ->where('status', '!=', 'archiviert')
            ->where(fn ($w) => $w
                ->where('status', 'active')   // kanonisch englisch (ConceptService schreibt 'active')
                ->orWhereNotNull('offer_id')
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_offer_concept as oc')
                    ->whereColumn('oc.concept_id', 'foodalchemist_concepts.id'))
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_foodbook_blocks as fb')
                    ->whereColumn('fb.concept_id', 'foodalchemist_concepts.id')
                    ->whereNull('fb.deleted_at')));
    }

    /**
     * EXISTS: ein Pflicht-Slot ohne Befüllung. „Genau eines von Paket/Gericht" erzwingt
     * der ConceptService — hier zählt nur, dass keines von beiden steht. Struktur-Slots
     * (Header/Text/Leerzeile) sind bewusst ausgenommen: sie tragen nie Inhalt
     * (ConceptService::STRUKTUR_TYPEN).
     */
    private function offenerPflichtSlot(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_concept_slots as cs')
            ->whereColumn('cs.concept_id', 'foodalchemist_concepts.id')
            ->whereNull('cs.deleted_at')
            ->whereNotIn('cs.type', \Platform\FoodAlchemist\Services\Concepter\ConceptService::STRUKTUR_TYPEN)
            ->where('cs.is_pflicht', true)
            ->whereNull('cs.sales_recipe_id');
    }

    /** EXISTS: mindestens ein Inhalts-Slot ist belegt (Gericht oder Paket). */
    private function belegterInhaltsSlot(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_concept_slots as cs')
            ->whereColumn('cs.concept_id', 'foodalchemist_concepts.id')
            ->whereNull('cs.deleted_at')
            ->whereNotIn('cs.type', \Platform\FoodAlchemist\Services\Concepter\ConceptService::STRUKTUR_TYPEN)
            ->whereNotNull('cs.sales_recipe_id');
    }

    /**
     * EXISTS: eine Gericht-Zeile dieses Konzepts würde den internen Namen drucken.
     *
     * Spiegelt {@see WordingResolver} — beide Befüllungs-Arten, weil beide im
     * Kundendokument als Zeile erscheinen:
     *   · Gericht-Slot → `slot.wording` → `dish.sales_wording_standard`
     *   · Paket-Slot   → Paket-Gerichte gehen direkt auf `sales_wording_standard`
     *     (ein Slot-Override existiert für sie nicht, s. WordingResolver::gerichtZeilen)
     */
    private function slotOhneWording(): \Closure
    {
        $ohneStandard = fn ($spalte) => fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipes as wr')
            ->whereColumn('wr.id', $spalte)
            ->whereNull('wr.deleted_at')
            ->whereRaw("LENGTH(TRIM(COALESCE(wr.sales_wording_standard, ''))) = 0");

        return function ($q) use ($ohneStandard) {
            return $q->select(DB::raw(1))->from('foodalchemist_concept_slots as cs')
                ->whereColumn('cs.concept_id', 'foodalchemist_concepts.id')
                ->whereNull('cs.deleted_at')
                ->whereNotNull('cs.sales_recipe_id')
                ->whereRaw("LENGTH(TRIM(COALESCE(cs.wording, ''))) = 0")
                ->whereExists($ohneStandard('cs.sales_recipe_id'));
        };
    }
}
