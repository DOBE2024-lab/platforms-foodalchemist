<?php

namespace Platform\FoodAlchemist\Services\Gericht;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AngebotStatus;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentation;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Gericht-Verwaltung (Plan „Verwaltung am Gericht“, Kurator/Dominique 23.11.2026): ALLE Verwendungen eines Gerichts,
 * je klassifiziert. Eine Quelle für Vorschau, Tausch und Löschsperre — liest nur, schreibt nie.
 *
 * Status einer Verwendung:
 *  - `tausch`     eigene, aktive Verwendung — wird beim Ersetzen umgehängt
 *  - `haken`      aktives Foodbook (Status aktiv oder live Präsentation) bzw. Katalog-Konzept daran — nur mit dem Haken
 *                 „auch aktive Foodbooks“ (Live-Inhalt beim Kunden ändert sich, eingefrorene Snapshots bleiben)
 *  - `bleibt`     aktiv, aber hier nicht tauschbar (fremdes Team, eingefrorenes offenes Angebot, offene Produktion, Abo)
 *                 — sperrt das Löschen, mit Namen. Offene Angebote mit manuellem Preis werden getauscht, der Preis bleibt
 *                 (Hinweis „Preis manuell – bitte prüfen“, Dominique 23.11.)
 *  - `historisch` Vergangenheit: vergangene Speiseplan-Tage, abgelaufene Speisekarten, versendete/angenommene/abgelehnte
 *                 Angebote, inaktive/archivierte Foodbooks, abgeschlossene Produktion, Archiv-Konzepte — sperrt nicht,
 *                 behält das weich gelöschte Gericht als Verweis
 *
 * Bewusst UNGESCOPED gelesen (wie RecipeService::referenzen): auch aktive Nutzung anderer Teams (Kind-Teams eines
 * Masters) muss das Löschen sperren. Getauscht wird nur, was dem eigenen Team gehört.
 *
 * Archiv-Konzept (F8, Kurator 23.11.): ein Katalog-Konzept, das ausschließlich an versendeten/abgeschlossenen Angeboten
 * bzw. inaktiven/archivierten Foodbooks (oder vergangenen Plan-Tagen, abgelaufenen Karten) hängt. Hängt es zusätzlich an
 * etwas Aktivem, ist es lebendig und wird getauscht; an einem aktiven Foodbook entscheidet der Haken.
 */
final class GerichtVerwendungen
{
    /** Ziel-Modelle für anklickbare Orte (Sicht wird je Ort geprüft). */
    private const ZIEL_MODELLE = [
        'konzept' => FoodAlchemistConcept::class, 'angebot' => FoodAlchemistAngebot::class, 'speiseplan' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::class,
        'speisekarte' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::class, 'foodbook' => FoodAlchemistFoodbook::class,
        'gericht' => FoodAlchemistRecipe::class, 'rezept' => FoodAlchemistRecipe::class,
    ];

    public const OFFENE_ANGEBOTE = ['anfrage', 'in_arbeit', 'angebot'];

    public const BEREICHE = ['konzepte', 'komponenten', 'speiseplan', 'speisekarte', 'angebote', 'foodbooks', 'ersatz', 'produktion', 'abos'];

    /** Diese Bereiche hängt der Tausch um; Produktion und Abos sperren nur. */
    public const TAUSCH_BEREICHE = ['konzepte', 'komponenten', 'speiseplan', 'speisekarte', 'angebote', 'foodbooks', 'ersatz'];

    /**
     * @return list<array{bereich:string, id:int, name:string, ort:string, team_id:?int, status:string, grund:?string, hinweis:?string, meta:array<string,mixed>}>
     */
    public function alle(Team $team, int $gerichtId, ?Carbon $heute = null): array
    {
        $heute = ($heute ?? Carbon::today())->copy()->startOfDay();
        $this->teamNamen = [];

        $alle = array_merge(
            $this->konzepte($team, $gerichtId, $heute),
            $this->komponenten($team, $gerichtId),
            $this->speiseplan($team, $gerichtId, $heute),
            $this->speisekarte($team, $gerichtId, $heute),
            $this->angebotsBloecke($team, $gerichtId),
            $this->foodbooks($team, $gerichtId),
            $this->ersatz($team, $gerichtId),
            $this->produktion($gerichtId),
            $this->abos($gerichtId),
        );

        return array_map(fn ($v) => $v + ['ziel' => $this->ziel($team, $v['meta']['ziel'] ?? null)], $alle);
    }

    /**
     * Anklickbarer Ort (Kurator/Dominique 23.11.: wie früher „Wo verwendet?“) — nur, wenn das Team ihn sehen darf;
     * fremde bzw. nicht sichtbare Orte bleiben Text.
     *
     * @param  array{0:string,1:int}|null  $ziel
     * @return array{typ:string, id:int}|null
     */
    private function ziel(Team $team, ?array $ziel): ?array
    {
        if ($ziel === null || ! isset(self::ZIEL_MODELLE[$ziel[0]])) {
            return null;
        }
        $schluessel = $ziel[0] . ':' . $ziel[1];
        $this->sichtbar[$schluessel] ??= self::ZIEL_MODELLE[$ziel[0]]::visibleToTeam($team)->whereKey($ziel[1])->exists();

        return $this->sichtbar[$schluessel] ? ['typ' => $ziel[0], 'id' => (int) $ziel[1]] : null;
    }

    /** @var array<string, bool> */
    private array $sichtbar = [];

    /**
     * Lösch-Bilanz eines Gerichts: aktive Verwendungen sperren (mit Ort und Grund), historische nicht. Eine Regel für
     * Panel, Editor und MCP (SalesRecipeService::deleteDish).
     *
     * @return array{frei:bool, aktiv: list<array{bereich:string,ort:string,ziel:?array,grund:?string}>, historisch:int, eigen:bool}
     */
    public function loeschBilanz(Team $team, int $gerichtId): array
    {
        $gericht = FoodAlchemistRecipe::withTrashed()->find($gerichtId);
        $eigen = $gericht !== null && (int) $gericht->team_id === (int) $team->id;
        $alle = $gericht !== null ? $this->alle($team, $gerichtId) : [];
        $aktiv = array_values(array_map(fn ($v) => ['bereich' => $v['bereich'], 'ort' => $v['ort'], 'ziel' => $v['ziel'] ?? null,
            'grund' => $v['grund'] ?? ($v['status'] === 'haken' ? 'aktives Foodbook' : 'aktive Verwendung')],
            array_filter($alle, fn ($v) => $v['status'] !== 'historisch')));

        return [
            'frei' => $eigen && $aktiv === [] && ! $gericht->trashed(),
            'aktiv' => $aktiv,
            'historisch' => count(array_filter($alle, fn ($v) => $v['status'] === 'historisch')),
            'eigen' => $eigen,
        ];
    }

    // ── Konzepte und Pakete (Katalog) + angebots-lokale Konzepte (Bereich Angebote) ──────────────────────────

    private function konzepte(Team $team, int $gerichtId, Carbon $heute): array
    {
        $out = [];
        $slots = FoodAlchemistConceptSlot::where('sales_recipe_id', $gerichtId)->with('concept:id,team_id,name,kind,offer_id')->get();
        foreach ($slots as $slot) {
            $c = $slot->concept;
            if ($c === null) {
                continue;
            }
            $art = $c->kind === 'paket' ? 'Paket' : 'Konzept';
            $basis = ['id' => (int) $slot->id, 'name' => (string) ($slot->dish?->name ?? ''), 'team_id' => (int) $c->team_id,
                'meta' => ['ziel' => ['konzept', (int) $c->id], 'concept_id' => (int) $c->id, 'presentation_id' => $slot->presentation_id, 'quantity' => $slot->quantity,
                    'unit_vocab_id' => $slot->unit_vocab_id, 'wording' => $slot->wording]];

            if ($c->offer_id !== null) {   // angebots-lokal: gehört dem Angebot, Regel der Angebote
                $angebot = FoodAlchemistAngebot::find($c->offer_id);
                $out[] = $basis + $this->angebotsRegel($team, $angebot, "{$art} „{$c->name}“") + ['bereich' => 'angebote',
                    'ort' => "{$art} „{$c->name}“ im Angebot „" . ($angebot?->name ?? '?') . '“'];
                $out[array_key_last($out)]['meta']['offer_id'] = $angebot?->id;
                $out[array_key_last($out)]['meta']['art'] = 'slot';
                $out[array_key_last($out)]['meta']['ziel'] = $angebot !== null ? ['angebot', (int) $angebot->id] : null;

                continue;
            }

            $ort = "{$art} „{$c->name}“";
            if ((int) $c->team_id !== (int) $team->id) {
                $out[] = $basis + ['bereich' => 'konzepte', 'ort' => $ort, 'status' => 'bleibt', 'grund' => $this->fremd($c->team_id), 'hinweis' => null];

                continue;
            }
            [$status, $grund, $hinweis] = $this->konzeptLage((int) $c->id, $heute);
            $out[] = $basis + ['bereich' => 'konzepte', 'ort' => $ort, 'status' => $status, 'grund' => $grund, 'hinweis' => $hinweis];
        }

        return $out;
    }

    /**
     * Lage eines Katalog-Konzepts aus seinen Anhängern.
     *
     * @return array{0:string,1:?string,2:?string} [status, grund, hinweis]
     */
    private function konzeptLage(int $conceptId, Carbon $heute): array
    {
        $historisch = [];
        $aktiv = [];
        $aktivFoodbook = [];

        // Angebote: Pivot (Katalog-Konzept am Angebot) und concept_ref-Blöcke
        $offerIds = DB::table('foodalchemist_offer_concept')->where('concept_id', $conceptId)->pluck('offer_id')
            ->merge(FoodAlchemistOfferBlock::where('concept_id', $conceptId)->pluck('chapter_id')
                ->pipe(fn ($k) => FoodAlchemistOfferChapter::whereIn('id', $k)->pluck('offer_id')))
            ->unique();
        foreach (FoodAlchemistAngebot::whereIn('id', $offerIds)->get(['id', 'name', 'status']) as $a) {
            $this->istOffen($a) ? ($aktiv[] = "Angebot „{$a->name}“") : ($historisch[] = "versendetem Angebot „{$a->name}“");
        }
        // Foodbooks: concept_ref-Blöcke und gebuchte Formate (Format-Kapitel rendern ihre Konzepte live)
        $kapitelIds = FoodAlchemistFoodbookBlock::where('concept_id', $conceptId)->pluck('chapter_id');
        $formatIds = FoodAlchemistFormatSlot::where('concept_id', $conceptId)->pluck('format_id')->unique();
        if ($formatIds->isNotEmpty()) {
            $aktiv[] = 'Format';   // ein Format ist lebender Katalog
            $kapitelIds = $kapitelIds->merge(FoodAlchemistFoodbookKapitel::whereIn('format_id', $formatIds)->pluck('id'));
            $formatOffers = FoodAlchemistOfferChapter::whereIn('format_id', $formatIds)->pluck('offer_id');
            foreach (FoodAlchemistAngebot::whereIn('id', $formatOffers)->get(['id', 'name', 'status']) as $a) {
                $this->istOffen($a) ? ($aktiv[] = "Angebot „{$a->name}“") : ($historisch[] = "versendetem Angebot „{$a->name}“");
            }
        }
        $fbIds = FoodAlchemistFoodbookKapitel::whereIn('id', $kapitelIds)->pluck('foodbook_id')->unique();
        foreach (FoodAlchemistFoodbook::whereIn('id', $fbIds)->get() as $fb) {
            match ($this->foodbookLage($fb)) {
                'historisch' => $historisch[] = "Foodbook „{$fb->label}“",
                'aktiv' => $aktivFoodbook[] = "aktivem Foodbook „{$fb->label}“",
                default => $aktiv[] = "Foodbook-Entwurf „{$fb->label}“",
            };
        }
        // Speisekarte (Menü-Verweis) und Speiseplan (Konzept eingeplant)
        foreach (FoodAlchemistSpeisekartePosition::where('concept_id', $conceptId)->with('rubrik.menuCard')->get() as $p) {
            $this->karteGilt($p, $heute) ? ($aktiv[] = 'Speisekarte') : ($historisch[] = 'abgelaufener Speisekarte');
        }
        $plan = FoodAlchemistSpeiseplanEintrag::where('concept_id', $conceptId);
        if ((clone $plan)->whereDate('entry_date', '>=', $heute)->exists()) {
            $aktiv[] = 'Speiseplan';
        }
        if ((clone $plan)->whereDate('entry_date', '<', $heute)->exists()) {
            $historisch[] = 'vergangenen Speiseplan-Tagen';
        }
        // In ein anderes Konzept eingebettet (Paket) — lebendiger Katalog
        if (FoodAlchemistConceptSlot::where('embedded_concept_id', $conceptId)->exists()) {
            $aktiv[] = 'Konzept (eingebettet)';
        }

        $hinweisHist = $historisch !== [] ? 'hängt auch an ' . implode(', ', array_unique($historisch)) : null;
        if ($aktivFoodbook !== []) {
            return ['haken', 'hängt an ' . implode(', ', array_unique($aktivFoodbook)), $hinweisHist];
        }
        if ($aktiv === [] && $historisch !== []) {
            return ['historisch', 'Archiv: hängt nur an ' . implode(', ', array_unique($historisch)), null];
        }

        return ['tausch', null, $hinweisHist];
    }

    // ── Komponente in Gerichten / Basisrezepten ──────────────────────────────────────────────────────────

    private function komponenten(Team $team, int $gerichtId): array
    {
        $out = [];
        $zeilen = FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $gerichtId)
            ->whereHas('recipe')->with('recipe:id,name,team_id,is_sales_recipe')->get(['id', 'recipe_id']);
        foreach ($zeilen as $z) {
            $r = $z->recipe;
            $eigen = (int) $r->team_id === (int) $team->id;
            $out[] = ['bereich' => 'komponenten', 'id' => (int) $z->id, 'name' => (string) $r->name,
                'ort' => ($r->is_sales_recipe ? 'Gericht' : 'Basisrezept') . " „{$r->name}“", 'team_id' => (int) $r->team_id,
                'status' => $eigen ? 'tausch' : 'bleibt', 'grund' => $eigen ? null : $this->fremd($r->team_id), 'hinweis' => null,
                'meta' => ['recipe_id' => (int) $r->id, 'ziel' => [$r->is_sales_recipe ? 'gericht' : 'rezept', (int) $r->id]]];
        }

        return $out;
    }

    // ── Speiseplan: ab heute tauschen, Vergangenheit bleibt ────────────────────────────────────────────────

    private function speiseplan(Team $team, int $gerichtId, Carbon $heute): array
    {
        $out = [];
        foreach (FoodAlchemistSpeiseplanEintrag::where('sales_recipe_id', $gerichtId)->with('mealPlan:id,name,team_id')->get() as $e) {
            $plan = $e->mealPlan;
            if ($plan === null) {
                continue;
            }
            $ort = "Speiseplan „{$plan->name}“, " . $e->entry_date?->format('d.m.Y');
            $vergangen = $e->entry_date !== null && $e->entry_date->lt($heute);
            $eigen = (int) $plan->team_id === (int) $team->id;
            $out[] = ['bereich' => 'speiseplan', 'id' => (int) $e->id, 'name' => $ort, 'ort' => $ort, 'team_id' => (int) $plan->team_id,
                'status' => $vergangen ? 'historisch' : ($eigen ? 'tausch' : 'bleibt'),
                'grund' => $vergangen ? 'vergangener Tag' : ($eigen ? null : $this->fremd($plan->team_id)), 'hinweis' => null,
                'meta' => ['ziel' => ['speiseplan', (int) $plan->id], 'plan_id' => (int) $plan->id, 'presentation_id' => $e->presentation_id]];
        }

        return $out;
    }

    // ── Speisekarte: gültige Karten tauschen (gueltig_bis leer oder ≥ heute) ─────────────────────────────────

    private function speisekarte(Team $team, int $gerichtId, Carbon $heute): array
    {
        $out = [];
        foreach (FoodAlchemistSpeisekartePosition::where('sales_recipe_id', $gerichtId)->with('rubrik.menuCard')->get() as $p) {
            $karte = $p->rubrik?->menuCard;
            if ($karte === null) {
                continue;
            }
            $ort = "Speisekarte „{$karte->name}“";
            $gilt = $this->karteGilt($p, $heute);
            $eigen = (int) $karte->team_id === (int) $team->id;
            $out[] = ['bereich' => 'speisekarte', 'id' => (int) $p->id, 'name' => $ort, 'ort' => $ort, 'team_id' => (int) $karte->team_id,
                'status' => ! $gilt ? 'historisch' : ($eigen ? 'tausch' : 'bleibt'),
                'grund' => ! $gilt ? 'Karte abgelaufen' : ($eigen ? null : $this->fremd($karte->team_id)), 'hinweis' => null,
                'meta' => ['ziel' => ['speisekarte', (int) $karte->id], 'karte_id' => (int) $karte->id, 'presentation_id' => $p->presentation_id, 'wording' => $p->wording]];
        }

        return $out;
    }

    // ── Angebote: Kapitel-Blöcke (angebots-lokale Konzepte laufen über konzepte()) ───────────────────────────

    private function angebotsBloecke(Team $team, int $gerichtId): array
    {
        $out = [];
        foreach (FoodAlchemistOfferBlock::where('sales_recipe_id', $gerichtId)->with('chapter:id,offer_id')->get() as $b) {
            $angebot = $b->chapter?->offer_id !== null ? FoodAlchemistAngebot::find($b->chapter->offer_id) : null;
            if ($angebot === null) {
                continue;
            }
            $ort = "Angebot „{$angebot->name}“";
            $out[] = ['bereich' => 'angebote', 'id' => (int) $b->id, 'name' => $ort, 'ort' => $ort, 'team_id' => (int) $angebot->team_id,
                'meta' => ['ziel' => ['angebot', (int) $angebot->id], 'offer_id' => (int) $angebot->id, 'art' => 'block', 'presentation_id' => $b->presentation_id, 'wording' => $b->wording]]
                + $this->angebotsRegel($team, $angebot, $ort);
        }

        return $out;
    }

    /** @return array{status:string, grund:?string, hinweis:?string} */
    private function angebotsRegel(Team $team, ?FoodAlchemistAngebot $angebot, string $ort): array
    {
        if ($angebot === null) {
            return ['status' => 'historisch', 'grund' => 'Angebot fehlt', 'hinweis' => null];
        }
        if (! $this->istOffen($angebot)) {
            return ['status' => 'historisch', 'grund' => 'Angebot ' . $this->statusText($angebot), 'hinweis' => null];
        }
        if ((int) $angebot->team_id !== (int) $team->id) {
            return ['status' => 'bleibt', 'grund' => $this->fremd($angebot->team_id), 'hinweis' => null];
        }
        // wie AngebotEinfrierService::aktive() — direkt gelesen, damit die Lese-Seite (Löschsperre in deleteDish) keinen Dienst-Kreis zieht
        if (FoodAlchemistAngebotEinfrierung::where('angebot_id', $angebot->id)->whereNull('verworfen_at')->exists()) {
            return ['status' => 'bleibt', 'grund' => 'Angebot eingefroren (Stand der Abo-Rezepte)', 'hinweis' => null];
        }
        if (($angebot->price_mode ?? 'auto') !== 'auto') {   // Dominique 23.11.: Inhalt tauschen, manueller Preis bleibt
            return ['status' => 'tausch', 'grund' => null, 'hinweis' => 'Preis manuell – bitte prüfen'];
        }

        return ['status' => 'tausch', 'grund' => null, 'hinweis' => null];
    }

    // ── Foodbooks: Entwurf tauschen, aktiv nur mit Haken, inaktiv/archiviert historisch ──────────────────────

    private function foodbooks(Team $team, int $gerichtId): array
    {
        $out = [];
        foreach (FoodAlchemistFoodbookBlock::where('sales_recipe_id', $gerichtId)->with('chapter:id,foodbook_id')->get() as $b) {
            $fb = $b->chapter?->foodbook_id !== null ? FoodAlchemistFoodbook::find($b->chapter->foodbook_id) : null;
            if ($fb === null) {
                continue;
            }
            $ort = "Foodbook „{$fb->label}“";
            $lage = $this->foodbookLage($fb);
            $eigen = (int) $fb->team_id === (int) $team->id;
            [$status, $grund] = match (true) {
                $lage === 'historisch' => ['historisch', 'Foodbook ' . $fb->statusWert()->value],
                ! $eigen => ['bleibt', $this->fremd($fb->team_id)],
                $lage === 'aktiv' => ['haken', 'aktives Foodbook (Live-Inhalt beim Kunden)'],
                default => ['tausch', null],
            };
            $out[] = ['bereich' => 'foodbooks', 'id' => (int) $b->id, 'name' => $ort, 'ort' => $ort, 'team_id' => (int) $fb->team_id,
                'status' => $status, 'grund' => $grund, 'hinweis' => null,
                'meta' => ['ziel' => ['foodbook', (int) $fb->id], 'foodbook_id' => (int) $fb->id, 'presentation_id' => $b->presentation_id, 'wording' => $b->wording]];
        }

        return $out;
    }

    /** 'historisch' (inaktiv/archiviert) · 'aktiv' (Status aktiv oder live Präsentation) · 'entwurf'. */
    public function foodbookLage(FoodAlchemistFoodbook $fb): string
    {
        $status = $fb->statusWert();
        if (in_array($status, [AusgabeStatus::Inaktiv, AusgabeStatus::Archiviert], true)) {
            return 'historisch';
        }
        if ($status === AusgabeStatus::Aktiv || $fb->isPresentationLive()) {
            return 'aktiv';
        }
        $betrieb = FoodAlchemistPresentation::where('presentable_type', PresentationService::TYPE_FOODBOOK)
            ->where('presentable_id', $fb->id)->get()->contains(fn ($p) => $p->istLive());

        return $betrieb ? 'aktiv' : 'entwurf';
    }

    // ── Ersatz-Verknüpfungen (6. Mini-Bereich), Produktion und Abos (sperren nur) ──────────────────────────

    private function ersatz(Team $team, int $gerichtId): array
    {
        $out = [];
        $equiv = FoodAlchemistComponentEquivalent::where(fn ($w) => $w
            ->where(fn ($q) => $q->where('source_kind', FoodAlchemistComponentEquivalent::KIND_RECIPE)->where('source_id', $gerichtId))
            ->orWhere(fn ($q) => $q->where('alt_kind', FoodAlchemistComponentEquivalent::KIND_RECIPE)->where('alt_id', $gerichtId)))->get();
        foreach ($equiv as $e) {
            $gegen = (int) $e->source_id === $gerichtId && $e->source_kind === FoodAlchemistComponentEquivalent::KIND_RECIPE ? $e->alt() : $e->source();
            $ort = 'Ersatz-Verknüpfung mit „' . ($gegen?->name ?? '?') . '“';
            $eigen = (int) $e->team_id === (int) $team->id;
            $out[] = ['bereich' => 'ersatz', 'id' => (int) $e->id, 'name' => $ort, 'ort' => $ort, 'team_id' => $e->team_id !== null ? (int) $e->team_id : null,
                'status' => $eigen ? 'tausch' : 'bleibt', 'grund' => $eigen ? null : $this->fremd($e->team_id), 'hinweis' => null, 'meta' => []];
        }

        return $out;
    }

    private function produktion(int $gerichtId): array
    {
        $out = [];
        foreach (FoodAlchemistProductionOrderLine::where('recipe_id', $gerichtId)->with('productionOrder:id,name,status,team_id')->get() as $z) {
            $o = $z->productionOrder;
            if ($o === null) {
                continue;
            }
            $offen = in_array($o->status?->value ?? $o->status, ['planned', 'in_progress'], true);
            $ort = "Produktionsauftrag „{$o->name}“";
            $out[] = ['bereich' => 'produktion', 'id' => (int) $z->id, 'name' => $ort, 'ort' => $ort, 'team_id' => (int) $o->team_id,
                'status' => $offen ? 'bleibt' : 'historisch', 'grund' => $offen ? 'offener Produktionsauftrag' : 'abgeschlossener Produktionsauftrag',
                'hinweis' => null, 'meta' => []];
        }

        return $out;
    }

    private function abos(int $gerichtId): array
    {
        if (! Schema::hasTable('foodalchemist_abos')) {
            return [];
        }
        $n = DB::table('foodalchemist_sammlung_objekte AS o')->join('foodalchemist_abos AS a', 'a.sammlung_id', '=', 'o.sammlung_id')
            ->where('o.typ', 'recipe')->where('o.objekt_id', $gerichtId)->where('a.status', 'aktiv')->count();

        return $n === 0 ? [] : [['bereich' => 'abos', 'id' => 0, 'name' => "{$n} aktive(s) Abo(s)", 'ort' => "{$n} aktive(s) Abo(s)",
            'team_id' => null, 'status' => 'bleibt', 'grund' => 'aktives Abo — nur Zurückziehen möglich', 'hinweis' => null, 'meta' => []]];
    }

    // ── Hilfen ─────────────────────────────────────────────────────────────────────────────────────────

    public function istOffen(FoodAlchemistAngebot $a): bool
    {
        return in_array($a->status instanceof AngebotStatus ? $a->status->value : (string) $a->status, self::OFFENE_ANGEBOTE, true);
    }

    private function statusText(FoodAlchemistAngebot $a): string
    {
        return $a->status instanceof AngebotStatus ? mb_strtolower($a->status->label()) : (string) $a->status;
    }

    private function karteGilt(FoodAlchemistSpeisekartePosition $p, Carbon $heute): bool
    {
        $bis = $p->rubrik?->menuCard?->gueltig_bis;

        return $bis === null || ! $bis->lt($heute);
    }

    /** @var array<int, string> */
    private array $teamNamen = [];

    private function fremd(?int $teamId): string
    {
        if ($teamId === null) {
            return 'gehört dem Basis-Katalog';
        }
        $this->teamNamen[$teamId] ??= (string) (Team::find($teamId)?->name ?? "#{$teamId}");

        return "wird von Team „{$this->teamNamen[$teamId]}“ aktiv genutzt";
    }
}
