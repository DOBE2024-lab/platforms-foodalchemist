<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Enums\AussageTyp;
use Platform\FoodAlchemist\Enums\Kantenart;

/**
 * 98 · 3.3 (KI-Strang c): M5-07 Pairing-Netz (D-7) — Netz je Basisrezept und Gericht, Netz für eine Anker-Auswahl (Composer),
 * Composer-Kohäsion und -Anker-Browser, Kontrast/Konflikt, komplementäre Basisrezepte, Kreis-Layout. Aus {@see PairingService}
 * geschnitten; Rümpfe unverändert. Die Kohäsion liest das Netz über die schmale Schnittstelle {@see PairingKohaesionQuelle}
 * (die Fassade reicht sich selbst herein) — so entsteht keine Kante Netz → Fassade.
 */
class PairingNetz
{
    public function __construct(private PairingKohaesionQuelle $kohaesion) {}

    /** Spec 60 · P2: einzige Lesestelle für die Harmonie zwischen Ankern. */
    private function graph(): AnkerGraph
    {
        return app(AnkerGraph::class);
    }

    private const CANVAS_W = 1200.0;

    private const CANVAS_H = 980.0;

    private const R_ANKER = 150.0;      // Innenring Kern-Anker

    private const R_BEST = 320.0;

    /** Gericht-Netz: Bestandteile tragen lange Rezeptnamen — weiter innen als die Anker (150) überlappten sie. */
    private const R_BESTANDTEIL = 215.0;       // mittlerer Vollkreis: best-Kandidaten (Inspire L3, ★★★)

    private const R_OUTER = 470.0;      // Aussenkreis: harmonie (★★/★) + kontrast (⇄) + Basisrezepte

    private const KANDIDATEN_PRO_TYP = 14;   // Cap je Typ

    private const BASIS_MAX = 10;

    /** Spec 60: Kontrast-Lieferanten je offenem Bedarf im Netz. */
    private const KONTRAST_JE_BEDARF = 4;

    private const INNER_ANKER_MAX = 12;

    /**
     * Pairing-Empfehler fürs Netz (2026-07-22 Redesign): beantwortet »was passt
     * zum Gericht, getrennt nach erprobt / aroma / kontrast«.
     *
     * Zentrum = Gericht, Innenring = eigene Kern-Anker (Geschmacks-Identität),
     * aussen die PAIRING-KANDIDATEN in Typ-Sektoren (erprobt oben, aroma oben-
     * rechts, kontrast oben-links) — jeder Kandidat ist ein Aroma-Partner der
     * Kern-Anker, gerankt nach der Zahl passender Zutaten im Profil +
     * Gewicht. Unten die KOMPLEMENTÄREN Basisrezepte (Rezepte, die auf einem
     * passenden Partner aufbauen). Typ-Filterung passiert clientseitig (Chips) —
     * jeder Kandidat/jede Kante trägt ihren `typ`. Positionen serverseitig fix
     * (reine Formel, keine Simulation).
     *
     * @param  int  $vorschlaegeProAnker  Legacy-Parameter, ignoriert (Kandidaten
     *                                     sind jetzt immer Teil des Netzes).
     * @return array{nodes: list<array>, edges: list<array>, meta: array}
     */
    public function pairingNetz(Team $team, int $recipeId, int $vorschlaegeProAnker = 0): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['recipe_id' => $recipeId]];
        }

        // Spec 60 · P7: im Gericht stehen Basisrezepte im Vordergrund, die Anker im Hintergrund.
        if ((bool) $recipe->is_sales_recipe) {
            return $this->gerichtNetz($team, $recipe);
        }
        // Basisrezept: Innenring = Kern-Anker seines Aromenprofils, mit Anteil (Hintergrund sichtbar).
        $profile = app(RezeptProfil::class);
        $aroma = $profile->fuer((int) $recipe->id);
        $anzeige = $profile->ankerFuerAnzeige($aroma);
        $vokabular = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', array_column($anzeige, 'anchor_id'))->whereNull('deleted_at')
            ->get(['id', 'slug', 'display_de'])->keyBy('id');
        $inner = collect($anzeige)->filter(fn ($a) => $vokabular->has($a['anchor_id']))
            ->map(fn ($a) => $a + (array) $vokabular[$a['anchor_id']]);

        return $this->baueNetz($team, $inner, (string) $recipe->name, $recipeId, quellen: $aroma['anker_quellen']);
    }

    /**
     * Spec 60 · P7: Netz eines Gerichts — Basisrezepte statt Anker (Dominique 06.10.: „im Gericht-Netz
     * keine Anker, sondern andere Basisrezepte, die dazu passen").
     *
     *   Innenring   die Bestandteile des Gerichts (Basisrezepte, einzeln eingesetzte GPs)
     *   Linien      zwischen Bestandteilen: harmoniert / Klassiker (gold), Spannung (türkis), Konflikt (rot)
     *   Mittelring  Basisrezepte aus dem Bestand, die mit einem Bestandteil harmonieren
     *   Außenring   Basisrezepte, die einen offenen Bedarf decken (Kontrast)
     * Dieselbe Kombinationslogik wie „Passt das zusammen?" im Panel.
     */
    private function gerichtNetz(Team $team, FoodAlchemistRecipe $gericht): array
    {
        $cx = self::CANVAS_W / 2;
        $cy = self::CANVAS_H / 2;
        $logik = app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class);
        $analyse = $logik->analysiere($gericht);
        $diaet = $gericht->spec_is_vegan === true ? 'vegan' : ($gericht->spec_is_vegetarian === true ? 'vegetarisch' : null);
        $teamId = $gericht->team_id !== null ? (int) $gericht->team_id : (int) $team->id;

        $nodes = [['id' => 'z', 'kind' => 'zentrum', 'label' => (string) $gericht->name, 'x' => $cx, 'y' => $cy]];
        $edges = [];
        $teile = array_values($analyse['bestandteile']);
        $anteile = $logik->portionsanteile($teile);
        foreach ($teile as $i => $t) {
            $anteil = isset($anteile[$t['schluessel']]) ? round(100 * $anteile[$t['schluessel']], 1) : null;
            [$x, $y] = $this->positionAufKreis($i, max(1, count($teile)), self::R_BESTANDTEIL, $cx, $cy);
            $nodes[] = ['id' => 't:'.$t['schluessel'], 'kind' => 'bestandteil', 'label' => $t['label'],
                'recipe_id' => $t['recipe_id'], 'ohne_profil' => $t['profil'] === null,
                'anteil' => $anteil, 'gramm' => $t['gramm'] !== null ? round($t['gramm'], 1) : null,
                'kern' => $t['profil'] !== null ? array_slice(array_map(fn ($a) => (int) $a['anchor_id'], $t['profil']['anker']), 0, 3) : [],
                'x' => $x, 'y' => $y];
            $edges[] = ['source' => 'z', 'target' => 't:'.$t['schluessel'], 'kind' => 'zentrum_anker', 'anteil' => $anteil, 'visible' => true];
        }
        $typ = [AussageTyp::Harmoniert->value => 'stern3', AussageTyp::Kombination->value => 'stern3', AussageTyp::Spannung->value => 'kontrast'];
        $gesehen = [];
        foreach ($analyse['aussagen'] as $a) {
            if (count($a->bestandteile) !== 2) {
                continue;
            }
            [$p, $q] = $a->bestandteile;
            if ($a->typ === AussageTyp::Konflikt) {
                $edges[] = ['source' => 't:'.$p, 'target' => 't:'.$q, 'kind' => 'konflikt', 'text' => $a->text, 'visible' => true];
            } elseif ($a->typ === AussageTyp::Hinweis && str_starts_with($a->text, Kombinationslogik::HINWEIS_BEGLEITAROMA)) {
                $edges[] = ['source' => 't:'.$p, 'target' => 't:'.$q, 'kind' => 'hinweis', 'text' => $a->text, 'visible' => true];
            } elseif (isset($typ[$a->typ->value]) && ! isset($gesehen[$a->typ->value.$p.$q])) {
                $gesehen[$a->typ->value.$p.$q] = true;
                $edges[] = ['source' => 't:'.$p, 'target' => 't:'.$q, 'kind' => 'teil_teil', 'typ' => $typ[$a->typ->value],
                    'text' => $a->text, 'visible' => true];
            }
        }

        $passend = $logik->passendeBasisrezepte($analyse, $diaet, $gericht->taste_direction, $teamId, self::KANDIDATEN_PRO_TYP);
        foreach ($passend as $i => $b) {
            [$x, $y] = $this->positionAufKreis($i, max(1, count($passend)), self::R_BEST, $cx, $cy);
            $nodes[] = ['id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => 'stern3', 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['mit'],
                'mit_anteil' => isset($anteile[$b['mit_schluessel']]) ? round(100 * $anteile[$b['mit_schluessel']], 1) : null,
                'x' => $x, 'y' => $y];
            $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 't:'.$b['mit_schluessel'], 'kind' => 'basis', 'typ' => 'stern3',
                'text' => $b['name'].' harmoniert mit '.$b['mit'], 'visible' => true];
        }
        $schon = array_flip(array_column($passend, 'recipe_id'));
        $kontrast = [];
        foreach ($logik->vorschlaegeFuer($analyse, $diaet, $gericht->taste_direction, $teamId, 3) as $v) {
            $achse = \Platform\FoodAlchemist\Enums\Achse::from($v['achse'])->label();
            foreach ($v['basisrezepte'] as $b) {
                if (! isset($schon[$b['recipe_id']]) && ! isset($kontrast[$b['recipe_id']])) {
                    $kontrast[$b['recipe_id']] = $b + ['achse' => $achse];
                }
            }
        }
        $kontrast = array_values($kontrast);
        foreach ($kontrast as $i => $b) {
            [$x, $y] = $this->positionAufKreis($i, max(1, count($kontrast)), self::R_OUTER, $cx, $cy);
            $nodes[] = ['id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => 'kontrast', 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['achse'], 'achse' => $b['achse'], 'x' => $x, 'y' => $y];
            if ($b['mit_schluessel'] !== null) {
                $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 't:'.$b['mit_schluessel'], 'kind' => 'basis', 'typ' => 'kontrast',
                    'text' => $b['name'].' deckt '.$b['achse'], 'visible' => true];
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'recipe_id' => (int) $gericht->id,
                'art' => 'gericht',
                'canvas_w' => self::CANVAS_W,
                'canvas_h' => self::CANVAS_H,
                'sig' => substr(md5(json_encode([$nodes, $edges])), 0, 10),
                'typ_default' => ['stern3' => true, 'kontrast' => true],
                'counts' => [
                    'bestandteile' => count($teile),
                    'stern3' => count($passend),
                    'kontrast' => count($kontrast),
                    'basis' => count($passend) + count($kontrast),
                    'konflikt' => count(array_filter($edges, fn ($e) => $e['kind'] === 'konflikt')),
                ],
            ],
        ];
    }

    /**
     * Ad-hoc-Netz aus einer freien Anker-Menge (Planungs-Composer): kein Rezept,
     * Zentrum = frei benennbare „Komposition". Gleiche Downstream-Logik wie
     * {@see pairingNetz} — nur der Innenring kommt direkt als Anker-ID-Liste
     * (Auswahl-Reihenfolge bleibt erhalten).
     *
     * @param  array<int>  $ankerIds
     */
    public function pairingNetzForAnkers(Team $team, array $ankerIds, string $centerLabel = 'Komposition'): array
    {
        $ids = array_values(array_unique(array_map('intval', $ankerIds)));
        if ($ids === []) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['recipe_id' => 0]];
        }
        $inner = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)
            ->get(['id', 'slug', 'display_de'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de])
            ->sortBy(fn ($a) => array_search($a['id'], $ids, true))->values();

        return $this->baueNetz($team, $inner, $centerLabel, 0, true);
    }

    /**
     * Gemeinsamer Netz-Aufbau (rezept- ODER anker-getrieben). $inner = roher Innenring
     * (wird auf INNER_ANKER_MAX gekappt). $recipeId nur für meta + Selbst-Ausschluss der
     * komplementären Basisrezepte (0 = keiner). $centerLabel = Label des Zentrum-Knotens.
     *
     * @param  \Illuminate\Support\Collection<int,array{id:int,slug:string,display_de:?string}>  $inner
     */
    private function baueNetz(Team $team, \Illuminate\Support\Collection $inner, string $centerLabel, int $recipeId, bool $composer = false, array $quellen = []): array
    {
        $cx = self::CANVAS_W / 2;
        $cy = self::CANVAS_H / 2;

        $inner = $inner->unique('id')->take(self::INNER_ANKER_MAX)->values();
        $innerIds = $inner->filter(fn ($a) => $a['kern'] ?? true)->pluck('id')->all();

        $edges = [];
        $ankerNodes = [];
        $nInner = max(1, $inner->count());
        foreach ($inner as $i => $a) {
            $w = 2 * M_PI * $i / $nInner - M_PI / 2;
            $x = round($cx + self::R_ANKER * cos($w), 1);
            $y = round($cy + self::R_ANKER * sin($w), 1);
            $ankerNodes[] = [
                'id' => 'a:'.$a['id'], 'kind' => 'anker', 'label' => $a['display_de'], 'slug' => $a['slug'],
                'kern' => $a['kern'] ?? true, 'anteil' => $a['anteil'] ?? null, 'staerke' => $a['staerke'] ?? null,
                'x' => $x, 'y' => $y,
            ];
            $edges[] = ['source' => 'z', 'target' => 'a:'.$a['id'], 'kind' => 'zentrum_anker', 'visible' => true];
        }

        // ── Innere Ebene: Kanten ZWISCHEN den Kern-Ankern ───────────────────
        // Zeigt, wie die ausgewählten Anker laut Foodpairing-Matrix zusammenhängen
        // (die eigentliche Beweisführung): Best-/Good-Match zwischen den violetten
        // Ankern selbst, nicht nur Anker→Zutat. Kein Match → keine Linie.
        $ankerKanten = $this->innerAnkerKanten($innerIds);
        $edges = array_merge($edges, $ankerKanten);

        // ── Kandidaten: Aroma-Partner der Kern-Anker (ausserhalb), typisiert ──
        [$kandidaten, $candMeta] = app(NetzKandidaten::class)->fuerAnker($innerIds, $quellen);
        $nebenIds = $inner->filter(fn ($a) => isset($a['kern']) && ! $a['kern'])->pluck('id')->all();
        $schwacheKonflikte = app(RezeptGraph::class)->schwacheKonflikte(
            ['nebenanker' => array_fill_keys($nebenIds, true)],
            ['anker' => array_map(fn ($id) => ['anchor_id' => $id, 'anteil' => 0.0], $innerIds)],
        );
        $ankerNamen = $inner->pluck('display_de', 'id');
        foreach ($schwacheKonflikte as $k) {
            $edges[] = ['source' => 'a:'.$k->von, 'target' => 'a:'.$k->zu, 'kind' => 'hinweis',
                'text' => "{$ankerNamen[$k->von]} und {$ankerNamen[$k->zu]} können sich stören (Begleitaroma, Dossier geprüft)",
                'visible' => true];
        }

        // ── Spec 60: Kombinationslogik über die Anker-Auswahl — dieselbe Aussage wie im Panel.
        // Daraus: Kontrast-Ring (Lieferanten für offene Bedarfe), Konflikt-Linien und — nur im
        // Composer — der Hinweis „kein gemessener Bezug" an Ankern ohne jede Verbindung (neutral).
        [$kontrast, $konfliktKanten, $verbunden] = $this->kontrastUndKonflikt($innerIds, ohneSalz: $composer);
        // Kein Partner mit dem Grundnamen einer gewählten Zutat („Augenbohne" neben „Augenbohne, gekocht"):
        // Vergleich über die Spalte grundname (AnkerVarianten), nie über Teilstrings (Bohne ≠ Bohnenkraut).
        $innerGrund = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $innerIds)->get(['grundname', 'slug'])
            ->map(fn ($r) => mb_strtolower(trim((string) ($r->grundname ?: $r->slug))))->flip()->all();
        $kandidaten = array_values(array_filter($kandidaten, fn ($c) => ! isset($innerGrund[$c['grundname']])));
        $kontrastGrund = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_column($kontrast, 'id'))->get(['id', 'grundname', 'slug'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => mb_strtolower(trim((string) ($r->grundname ?: $r->slug)))])->all();
        $kontrast = array_values(array_filter($kontrast, fn ($c) => ! isset($innerGrund[$kontrastGrund[(int) $c['id']] ?? ''])));
        // Ein ★★★-Partner, der zugleich einen offenen Bedarf deckt, bleibt im ★★★-Ring und trägt die
        // Kontrast-Achse als Markierung (Dominique 15.10.: vorher wanderten alle 6 ★★★-Partner des
        // Kürbis in den Kontrast-Ring, der ★★★-Ring war leer). Der Kontrast-Ring zeigt den Rest.
        $kontrastVon = [];
        foreach ($kontrast as $c) {
            $kontrastVon[(int) $c['id']] = $c;
        }

        $edges = array_merge($edges, $konfliktKanten);
        if ($composer && count($innerIds) >= 2) {
            foreach ($ankerNodes as &$an) {
                $an['orphan'] = ! isset($verbunden[(int) substr($an['id'], 2)]);
            }
            unset($an);
        }

        // Rang (vorher bei Gleichstand alphabetisch: „Afrikanische Tagetes, Alpenpfeffer …" vorne):
        // verbindet mehr gewählte Anker · deckt einen offenen Bedarf · Klassiker aus dem Dossier · Gruppen-Klassiker ·
        // Grundform vor Variante · Verbreitung im Katalog · Name. Vielfalt: je Grundname einer.
        $jeTyp = fn ($typ) => array_slice(
            (function () use ($kandidaten, $typ, $kontrastVon) {
                $l = array_values(array_filter($kandidaten, fn ($c) => $c['typ'] === $typ));
                $schluessel = fn ($c) => [$c['cover'], isset($kontrastVon[(int) $c['id']]) ? 1 : 0,
                    $c['klassiker'] ? 2 : ($c['gruppen_klassiker'] ? 1 : 0), $c['grundform'] ? 1 : 0, $c['verbreitung'], $c['weight']];
                usort($l, fn ($x, $y) => [...$schluessel($y), $x['slug']] <=> [...$schluessel($x), $y['slug']]);
                $gesehen = [];

                return array_values(array_filter($l, function ($c) use (&$gesehen) {
                    if (isset($gesehen[$c['grundname']])) {
                        return false;
                    }

                    return $gesehen[$c['grundname']] = true;
                }));
            })(),
            0, self::KANDIDATEN_PRO_TYP
        );
        // Spec 60: Harmonie-Kandidaten nur ★★★; der äußere Ring trägt den Kontrast (offene Bedarfe).
        $stern3 = $jeTyp('stern3');
        // Wer im ★★★-Ring steht, erscheint nicht ein zweites Mal im Kontrast-Ring.
        $stern3Ids = array_flip(array_map(fn ($c) => (int) $c['id'], $stern3));
        $kontrast = array_values(array_filter($kontrast, fn ($c) => ! isset($stern3Ids[(int) $c['id']])));

        $basis = $this->komplementaerBasisrezepte($team, $recipeId, $candMeta);

        $kandidatNodes = [];
        $basisNodes = [];

        // Mittlerer Vollkreis: ★★★-Kandidaten (Inspire L3, Best-Match) gleichmässig rundum.
        $mB = max(1, count($stern3));
        foreach ($stern3 as $idx => $c) {
            [$x, $y] = $this->positionAufKreis($idx, $mB, self::R_BEST, $cx, $cy);
            $k = $kontrastVon[(int) $c['id']] ?? null;
            $kandidatNodes[] = [
                'id' => 'k:'.$c['id'], 'kind' => 'kandidat', 'typ' => 'stern3', 'level' => 3,
                'label' => $c['display_de'], 'slug' => $c['slug'], 'cover' => $c['cover'],
                'cover_einheit' => $composer ? 'Ankern' : 'Zutaten', 'anker_treffer' => count($c['partner']), 'x' => $x, 'y' => $y,
                'auch_kontrast' => $k['achse_label'] ?? null,
            ];
            foreach ($c['partner'] as $p) {
                $edges[] = ['source' => 'k:'.$c['id'], 'target' => 'a:'.$p['anker_id'], 'kind' => 'kandidat',
                    'typ' => $p['typ'], 'level' => $p['level'] ?? 1, 'weight' => $p['weight'],
                    'visible' => true];
            }
        }

        // Äusserer Vollkreis: Kontrast-Lieferanten (decken einen offenen Bedarf) + Basisrezepte.
        $outerTotal = max(1, count($kontrast) + count($basis));
        $oi = 0;
        foreach ($kontrast as $c) {
            [$x, $y] = $this->positionAufKreis($oi++, $outerTotal, self::R_OUTER, $cx, $cy);
            $kandidatNodes[] = [
                'id' => 'k:'.$c['id'], 'kind' => 'kandidat', 'typ' => 'kontrast', 'level' => 0,
                'label' => $c['display_de'], 'slug' => $c['slug'], 'cover' => count($c['fuer']),
                'achse' => $c['achse_label'], 'x' => $x, 'y' => $y,
            ];
            foreach ($c['fuer'] as $aid) {
                $edges[] = ['source' => 'k:'.$c['id'], 'target' => 'a:'.$aid, 'kind' => 'kandidat',
                    'typ' => 'kontrast', 'level' => 0, 'weight' => 1.0, 'achse' => $c['achse_label'], 'visible' => true];
            }
        }
        foreach ($basis as $b) {
            [$x, $y] = $this->positionAufKreis($oi++, $outerTotal, self::R_OUTER, $cx, $cy);
            $basisNodes[] = [
                'id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => $b['typ'], 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['via_slug'], 'x' => $x, 'y' => $y,
            ];
            $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 'a:'.$b['anker_id'], 'kind' => 'basis',
                'typ' => $b['typ'], 'visible' => true];
        }

        $nodes = array_merge(
            [['id' => 'z', 'kind' => 'zentrum', 'label' => $centerLabel, 'x' => $cx, 'y' => $cy]],
            $ankerNodes, $kandidatNodes, $basisNodes
        );

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'recipe_id' => $recipeId,
                'canvas_w' => self::CANVAS_W,
                'canvas_h' => self::CANVAS_H,
                // Inhalts-Signatur (Knoten-IDs in Reihenfolge): keyt die wire:ignore-D3-Insel
                // in Vorschau + Modal. Ändert sich der Ankersatz (o. die Kandidaten), ändert
                // sich sig → wire:key wechselt → Livewire ersetzt die Insel → D3 re-initialisiert
                // mit frischen Daten. Ohne das friert das Modal auf dem Erst-Öffnungsstand ein.
                'sig' => substr(md5(json_encode([$nodes, $edges])), 0, 10),
                // Filter-Defaults: beide Stern-Stufen an (zweistufige Inspire-Harmonie).
                'typ_default' => ['stern3' => true, 'kontrast' => true],
                'counts' => [
                    'stern3' => count(array_filter($kandidatNodes, fn ($n) => $n['typ'] === 'stern3')),
                    'kontrast' => count(array_filter($kandidatNodes, fn ($n) => $n['typ'] === 'kontrast')),
                    'basis' => count($basisNodes),
                    // Kanten zwischen den Kern-Ankern (innere Ebene).
                    'anker_anker' => count($ankerKanten),
                    'konflikt' => count($konfliktKanten),
                ],
            ],
        ];
    }

    /**
     * Composer: Kohäsion einer freien Anker-Menge — „passen die gewählten Anker zusammen?".
     * Baut die komponenten-Struktur aus den Ankern und delegiert an {@see cohesionFor}
     * (liefert score/min_score/weakest_pair + je Anker fit/is_orphan = der „passt-nicht"-Flag).
     *
     * @param  array<int>  $ankerIds
     */
    public function composerCohesion(array $ankerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $ankerIds)));
        $leer = ['score' => 0, 'min_score' => 0, 'rated_pairs' => 0, 'total_pairs' => 0,
            'coverage_pct' => 0, 'weakest_pair' => null, 'unrated_pairs' => [], 'komponenten' => []];
        if (count($ids) < 2) {
            return $leer;
        }
        $rows = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)->get(['id', 'slug', 'display_de'])->keyBy('id');

        $komponenten = [];
        foreach ($ids as $id) {
            $a = $rows->get($id);
            if ($a === null) {
                continue;
            }
            $komponenten[] = [
                'kern' => (int) $a->id,
                'prozess' => [],
                'label' => $a->display_de ?: $a->slug,
                'via' => $a->slug,
            ];
        }

        return $this->kohaesion->cohesionFor($komponenten);
    }

    /**
     * Composer-Picker: browsebare Anker-Liste (wie der GP-Picker `IngredientEditor::browseKatalog`).
     * Gefiltert nach Kategorie + Suche, aktuelle Auswahl ausgeschlossen; je Anker ein Best/Good-Badge
     * relativ zur Auswahl (aus {@see NetzKandidaten::fuerAnker}) + das Kategorie-Vokabular für den Dropdown.
     *
     * @param  array<int>  $selectedIds
     * @return array{items: list<array>, total: int, kategorien: list<string>}
     */
    public function composerAnkerBrowse(Team $team, string $q, ?string $category, array $selectedIds, int $limit = 200, ?int $focusId = null): array
    {
        $selected = array_values(array_unique(array_map('intval', $selectedIds)));
        $q = trim($q);

        $query = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.$q.'%';
                $w->where(fn ($x) => $x->where('slug', 'like', $like)->orWhere('display_de', 'like', $like));
            })
            ->when($category !== null && $category !== '', fn ($w) => $w->where('category', $category))
            ->when($selected !== [], fn ($w) => $w->whereNotIn('id', $selected));

        $total = (clone $query)->count();
        $rows = $query->orderBy('display_de')->limit($limit)->get(['id', 'slug', 'display_de', 'category']);

        // Best/Good-Badge: bei Fokus relativ zum EINEN fokussierten Anker („was passt zu X"),
        // sonst relativ zur ganzen Auswahl.
        $badgeBasis = $focusId !== null ? [$focusId] : $selected;
        $badge = [];
        $auchKontrast = [];
        if ($badgeBasis !== []) {
            // Spec 60: ★★★ = harmoniert · kontrast = deckt einen offenen Bedarf · konflikt = stört sich.
            // Wie im Netz (#393): ★★★, der zugleich einen Bedarf deckt, bleibt ★★★ und trägt die Achse
            // (auch_kontrast); „kontrast" nur für Lieferanten ohne ★★★ (Klassiker). Konflikt vor allem.
            [$kand] = app(NetzKandidaten::class)->fuerAnker($badgeBasis);
            foreach ($kand as $c) {
                $badge[(int) $c['id']] = $c['typ'];
            }
            foreach ($this->kontrastUndKonflikt($badgeBasis, ohneSalz: true)[0] as $c) {
                if (($badge[(int) $c['id']] ?? null) === 'stern3') {
                    $auchKontrast[(int) $c['id']] = $c['achse_label'];
                } else {
                    $badge[(int) $c['id']] = 'kontrast';
                }
            }
            foreach ($this->graph()->beziehungen($badgeBasis, Kantenart::Konflikt) as $k) {
                $badge[$k->zu] = 'konflikt';
            }
        }

        $kategorien = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category')->all();

        return [
            'items' => $rows->map(fn ($a) => [
                'id' => (int) $a->id,
                'slug' => $a->slug,
                'label' => $a->display_de ?: $a->slug,
                'category' => $a->category,
                'typ' => $badge[(int) $a->id] ?? null,
                'auch_kontrast' => ($badge[(int) $a->id] ?? null) === 'stern3' ? ($auchKontrast[(int) $a->id] ?? null) : null,
            ])->all(),
            'total' => $total,
            'kategorien' => $kategorien,
        ];
    }

    /**
     * Kanten ZWISCHEN den Kern-Ankern (innere Ebene des Netzes). Beantwortet
     * „wie hängen die ausgewählten Anker untereinander zusammen" — die eigentliche
     * Beweisführung des Foodpairing-Modells, die im Zutat→Anker-Netz fehlt.
     *
     * Signal = die gemessene Foodpairing-Harmonie ({@see AnkerGraph}): je ungeordnetem Paar
     * mit Stufe ★★★ eine Linie. Darunter (2★ oder keine Kante) entsteht keine Linie.
     *
     * @param  array<int>  $innerIds
     * @return list<array{source:string,target:string,kind:string,typ:string,level:int,weight:float,visible:bool}>
     */
    private function innerAnkerKanten(array $innerIds): array
    {
        if (count($innerIds) < 2) {
            return [];
        }

        $out = [];
        foreach ($this->graph()->kanten($innerIds, $innerIds, AnkerGraph::HARMONIERT) as $k) {   // Spec 60: nur ★★★
            if ($k->von > $k->zu) {
                continue;                                   // jedes Paar steht in beiden Richtungen — einmal zeigen
            }
            $out[] = [
                'source' => 'a:'.$k->von,
                'target' => 'a:'.$k->zu,
                'kind' => 'anker_anker',
                'typ' => 'stern'.$k->stufe,
                'level' => $k->stufe,
                'weight' => AnkerGraph::GEWICHT[$k->stufe],
                'visible' => true,
            ];
        }

        return $out;
    }

    /**
     * Spec 60: Kontrast-Lieferanten und Konflikte einer Anker-Auswahl aus der Kombinationslogik
     * (Anker als Einzel-Bestandteile — dieselbe Analyse wie „Passt das zusammen?").
     *
     *   kontrast   je offenem Bedarf die stärksten Lieferanten (abgeleitete Kontrast-Kante)
     *   konflikt   Linie zwischen zwei gewählten Ankern, die sich laut Anker-Wissen stören
     *   verbunden  Anker, die mit einem anderen harmonieren, Spannung bilden oder ein Klassiker sind
     *
     * @param  array<int>  $innerIds
     * @return array{0: list<array{id: int, slug: string, display_de: ?string, achse_label: string, fuer: list<int>}>, 1: list<array>, 2: array<int, true>}
     */
    public function kontrastUndKonflikt(array $innerIds, bool $ohneSalz = false): array
    {
        if ($innerIds === []) {
            return [[], [], []];
        }
        $logik = app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class);
        $analyse = $logik->analysiereBestandteile($logik->bestandteileAusAnkern($innerIds));
        if ($ohneSalz) {
            // Composer (Dominique 16.10.): Salz ist keine Pairing-Frage → kein Salz-Lieferant im Ring
            $analyse['offene_bedarfe'] = array_values(array_filter($analyse['offene_bedarfe'],
                fn ($b) => $b['achse'] !== \Platform\FoodAlchemist\Enums\Achse::Salz->value));
        }
        $id = fn (string $schluessel) => (int) substr($schluessel, 1);           // 'a123' → 123

        // Ein Lieferant muss zur Auswahl passen: ★★★ zu mindestens einem gewählten Anker ODER Klassiker
        // aus dem Dossier („Verträgt"), auch als Gruppe („verträgt Fisch" + Lieferant ist Fisch, schwächer). Das gilt auch für reine Träger (Stufe 3: Öl, Meersalz, Zucker).
        // ★★ zählt nicht (Dominique 15.10.: „2 Sterne ist die Grund-Aroma-Passform", Rauschen).
        // Vorher durfte ein Träger ohne jede Harmonie stehen → Wildfond für Erdbeere + Basilikum,
        // Tikka Masala für Kürbis. (2026-10-06: Olivenöl für Tomate fehlte mit reinem ★★★-Filter —
        // heute trägt das der Klassiker aus dem Dossier.)
        // Sortiert: Harmonien mit der Auswahl, Klassiker, belegte Träger (Nährwert gemessen / Dossier
        // geprüft) vor ungeprüften, dann Rang der Kontrast-Kante.
        $jeBedarf = [];
        foreach ($analyse['offene_bedarfe'] as $b) {
            $fuer = $id((string) $b['bestandteil']);
            $jeBedarf[] = [$fuer, (string) $b['achse'], $this->graph()->beziehungen([$fuer], Kantenart::Kontrast)
                ->where('achse', (string) $b['achse'])->reject(fn ($k) => in_array($k->zu, $innerIds, true))->values()];
        }
        $alle = collect($jeBedarf)->flatMap(fn ($x) => $x[2]->pluck('zu'))->unique()->values()->all();
        $harmonien = [];
        foreach ($alle === [] ? [] : $this->graph()->kanten($alle, $innerIds, AnkerGraph::HARMONIERT) as $k) {
            $harmonien[$k->von] = ($harmonien[$k->von] ?? 0) + 1;
        }
        // Klassiker aus dem Dossier („Verträgt") lässt einen Lieferanten auch ohne ★★★ zu
        $klassiker = $alle === [] ? [] : $this->graph()->beziehungen($alle, Kantenart::Kombination, $innerIds)
            ->merge($this->graph()->beziehungen($innerIds, Kantenart::Kombination, $alle))
            ->flatMap(fn ($k) => [$k->von, $k->zu])->flip()->all();
        $gruppenKlassiker = $this->graph()->gruppenKlassikerZwischen($alle, $innerIds);   // „verträgt Fisch", schwächer
        $stoert = $alle === [] ? [] : $this->graph()->beziehungen($alle, Kantenart::Konflikt, $innerIds)
            ->merge($this->graph()->beziehungen($innerIds, Kantenart::Konflikt, $alle))
            ->flatMap(fn ($k) => [$k->von, $k->zu])->flip()->all();
        $traeger = [];
        foreach ($alle === [] ? [] : DB::table('foodalchemist_anchor_eigenschaften')->whereIn('anchor_id', $alle)
            ->where('stufe', '>=', 3)->where('status', '!=', \Platform\FoodAlchemist\Enums\WissensStatus::Verworfen->value)
            ->get(['anchor_id', 'achse', 'quelle', 'status']) as $e) {
            $belegt = $e->quelle === 'naehrwert' || $e->status === \Platform\FoodAlchemist\Enums\WissensStatus::Geprueft->value;
            $schluessel = (int) $e->anchor_id.'|'.$e->achse;
            $traeger[$schluessel] = ($traeger[$schluessel] ?? 0) === 2 || $belegt ? 2 : 1;   // 2 = belegt, 1 = ungeprüft
        }
        $kontrast = [];
        foreach ($jeBedarf as [$fuer, $achse, $lieferanten]) {
            $passend = $lieferanten
                ->filter(fn ($k) => ! isset($stoert[$k->zu])
                    && (isset($harmonien[$k->zu]) || isset($klassiker[$k->zu]) || isset($gruppenKlassiker[$k->zu])))
                ->sortBy([
                    fn ($x, $y) => ($harmonien[$y->zu] ?? 0) <=> ($harmonien[$x->zu] ?? 0),
                    // Klassiker-Stufe: Einzel 2, nur Gruppe 1 (eine Gruppe hebt nie über einen Einzel-Klassiker)
                    fn ($x, $y) => (isset($klassiker[$y->zu]) ? 2 : (isset($gruppenKlassiker[$y->zu]) ? 1 : 0))
                        <=> (isset($klassiker[$x->zu]) ? 2 : (isset($gruppenKlassiker[$x->zu]) ? 1 : 0)),
                    fn ($x, $y) => ($traeger[$y->zu.'|'.$achse] ?? 0) <=> ($traeger[$x->zu.'|'.$achse] ?? 0),
                    fn ($x, $y) => $y->rang <=> $x->rang,
                ])
                ->take(self::KONTRAST_JE_BEDARF);
            foreach ($passend as $k) {
                $kontrast[$k->zu] ??= ['id' => $k->zu, 'achse_label' => \Platform\FoodAlchemist\Enums\Achse::from($achse)->label(), 'fuer' => []];
                $kontrast[$k->zu]['fuer'][] = $fuer;
            }
        }
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_keys($kontrast))
            ->get(['id', 'slug', 'display_de'])->keyBy('id');
        $kontrast = array_values(array_filter(array_map(fn ($c) => isset($namen[$c['id']])
            ? $c + ['slug' => $namen[$c['id']]->slug, 'display_de' => $namen[$c['id']]->display_de] : null, $kontrast)));

        $konflikt = [];
        $verbunden = [];
        foreach ($analyse['aussagen'] as $a) {
            if (count($a->bestandteile) !== 2) {
                continue;
            }
            [$x, $y] = array_map(fn ($k) => $id((string) $k), $a->bestandteile);
            if ($a->typ === AussageTyp::Konflikt) {
                $konflikt[min($x, $y).':'.max($x, $y)] = ['source' => 'a:'.min($x, $y), 'target' => 'a:'.max($x, $y),
                    'kind' => 'konflikt', 'text' => $a->text, 'visible' => true];
            } elseif (in_array($a->typ, [AussageTyp::Harmoniert, AussageTyp::Spannung, AussageTyp::Kombination], true)) {
                $verbunden[$x] = true;
                $verbunden[$y] = true;
            }
        }

        return [$kontrast, array_values($konflikt), $verbunden];
    }

    /**
     * Komplementäre Basisrezepte: Basisrezepte (is_sales_recipe=0), deren Kern-
     * Anker ein Pairing-Partner der Gericht-Anker ist — also Rezepte, die auf
     * einer passenden Zutat AUFBAUEN (ergänzend, nicht bloss »ähnlich«). Typ +
     * Andock-Anker aus der stärksten Partner-Beziehung.
     *
     * @param  array<int,array>  $candMeta  candId → [typ, anker_id, slug]
     * @return list<array>
     */
    private function komplementaerBasisrezepte(Team $team, int $recipeId, array $candMeta): array
    {
        if ($candMeta === []) {
            return [];
        }
        $candIds = array_keys($candMeta);

        // Spec 60 · P7c: Basisrezepte, deren Aromenprofil einen Kandidaten als Kern trägt (≥ 10 %).
        $rows = DB::table('foodalchemist_recipe_profile_anker AS m')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'm.recipe_id')
            ->whereIn('m.anchor_id', $candIds)
            ->where('m.recipe_id', '!=', $recipeId)
            ->where('m.anteil', '>=', \Platform\FoodAlchemist\Services\Pairing\RezeptGraph::KERN)
            ->where('r.is_sales_recipe', 0)
            ->whereNull('r.deleted_at')
            ->get(['m.recipe_id', 'm.anchor_id', 'r.name']);

        // Nur team-sichtbare Rezepte (Tenancy).
        $sichtbar = FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', $rows->pluck('recipe_id')->unique()->all())->pluck('id')->flip();

        $perRecipe = [];
        foreach ($rows as $r) {
            $rid = (int) $r->recipe_id;
            if (! $sichtbar->has($rid)) {
                continue;
            }
            $meta = $candMeta[(int) $r->anchor_id] ?? null;
            if ($meta === null) {
                continue;
            }
            if (! isset($perRecipe[$rid])) {
                $perRecipe[$rid] = ['recipe_id' => $rid, 'name' => $r->name, 'treffer' => 0,
                    'typ' => $meta['typ'], 'anker_id' => $meta['anker_id'], 'via_slug' => $meta['slug']];
            }
            $perRecipe[$rid]['treffer']++;
        }

        $out = array_values($perRecipe);
        usort($out, fn ($x, $y) => [$y['treffer'], $x['name']] <=> [$x['treffer'], $y['name']]);

        return array_slice($out, 0, self::BASIS_MAX);
    }

    /** Gleichmässige Position auf einem Vollkreis: Slot idx von n, Start oben (270°/-90°). */
    private function positionAufKreis(int $idx, int $n, float $radius, float $cx, float $cy): array
    {
        $rad = 2 * M_PI * $idx / max(1, $n) - M_PI / 2;

        return [round($cx + $radius * cos($rad), 1), round($cy + $radius * sin($rad), 1)];
    }
}
