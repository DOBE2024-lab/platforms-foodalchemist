<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\BedarfDeckung;
use Platform\FoodAlchemist\Enums\Verfahren;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\Rezept\SensorikService;

/**
 * Spec 60 · P5: Aromenprofil eines Basisrezepts (Idee Dominique 2026-10-06).
 *
 * Ein Basisrezept wird zu einem eigenen Profil aus Kern-Ankern mit prozentualem Anteil:
 *
 *   Aromamasse je Zutat = Gramm × Aroma-Intensität des Ankers × Rollen-Gewicht
 *                         (Gramm nach der T1-Kaskade von Yield/Kosten, {@see RecipeRecomputeService::grammJeZeile})
 *   Würzzutat           = nach Wirk-Dosis statt nach Gramm ({@see WIRKDOSIS}): Rezeptmasse × WUERZ_GEWICHT
 *                         × Intensität × √(Anteil / Wirk-Dosis), gedeckelt bei WUERZ_DECKEL, × Rollen-Gewicht.
 *                         Gewürze und Kräuter werden in Gramm dosiert, prägen das Aroma aber weit über
 *                         ihre Masse — linear fielen 58 % der Gewürz- und 63 % der Kräuter-Zeilen aus dem
 *                         Profil (demo 2026-10-07, z. B. „Mousse Dulcey-Ras el Hanout" = 100 % Sahne).
 *   Unterrezept         = sein eigenes Profil, anteilig nach der eingesetzten Menge (max. 3 Ebenen)
 *   Profil              = Aromamassen je Anker, normiert auf 100 %; Anker < 5 % fallen weg, neu normiert
 *
 * Zubereitung wählt die Inspire-Variante: steht im Zutatentext „geröstet" und führt Inspire
 * „Kürbis, im Ofen geröstet", zählt die Variante (dort gemessen). Das Verfahren aus dem
 * Rezeptnamen („Geröstete Bundmöhren") gilt nur für die mengenmäßig stärkste Zutat.
 *
 * Dazu je Rezept:
 *   abdeckung       Anteil der Rezeptmasse mit Anker (ehrliche Aussagekraft)
 *   eigenschaften   je Achse die Stufe 0–3: aus den Ankern (Stufe × min(1, Anteil/10 %)), aus den
 *                   KI-Texturen des Rezepts (knusprig, cremig, saftig, weich; ungeprüft) und aus
 *                   der Sensorik (Süße, Salz, Fett aus Nährwerten) — die höhere gilt
 *   offene_bedarfe  Bedarfe der Kern-Anker (≥ 10 %), die das Rezept selbst nicht deckt (Stufe < 2);
 *                   Salz/Säure gelten schon als gedeckt, wenn eine deutlich salzende/säuernde Zutat drin ist
 *
 * Materialisiert mit Quell-Hash: {@see fuer} rechnet, vergleicht und schreibt nur bei Änderung.
 */
final class RezeptProfil
{
    public const SCHWELLE_ANTEIL = 5.0;

    public const KERN_FUER_BEDARF = 10.0;

    public const VOLL_AB_ANTEIL = 10.0;

    /** Würz-Achsen: schon eine kleine Menge eines deutlichen Lieferanten deckt den Bedarf ({@see abgeschmeckt}). */
    public const ABGESCHMECKT = ['salz', 'saeure'];

    /**
     * Wirk-Dosis je Anker-Kategorie (längstes Präfix gewinnt), in % der Rezeptmasse: der Median der
     * tatsächlichen Dosierung über 2.336 Basisrezepte (gemessen 2026-10-07, T1-Gramm, Kern-Anker).
     * Nur Zutaten, die in Gramm dosiert werden und trotzdem aromaprägend sind; alles andere bleibt linear.
     * Salz hat Intensität 0 und kommt gar nicht erst hierher.
     */
    public const WIRKDOSIS = [
        'Gewuerze' => 0.5,
        'Gewuerze/Zitrusgewuerze' => 1.3,
        'Kräuter' => 0.7,
        'Gemüse/Fruchtgemüse/Chili' => 0.3,
    ];

    /** Eine Würzzutat in Wirk-Dosis zählt wie WUERZ_GEWICHT × Intensität der Rezeptmasse (Gewürz 5 × 0,04 = ⅕). */
    public const WUERZ_GEWICHT = 0.04;

    /** Überdosis wirkt nur bis zum Anderthalbfachen — sonst verdrängt ein Datenfehler (123 g Gewürz) das ganze Profil. */
    public const WUERZ_DECKEL = 1.5;

    /** @var array<int, ?float> anchor_id → Wirk-Dosis (null = linear) */
    private array $dosisCache = [];

    /** Textur-Vokabular → Achse (nur lieferbare Textur-Achsen; kühlend/wärmend/körnig … haben keine Achse). */
    private const TEXTUR_ACHSE = ['knusprig' => 'knusprig', 'cremig' => 'cremig', 'saftig' => 'saftig', 'weich' => 'weich'];

    /** Sensorik-Dimension → Achse; nur die aus Nährwerten belegten (Roh-Fallback ohne gegartes Profil). */
    private const SENSORIK_BELEGT = ['suess' => 'suesse', 'salzig' => 'salz', 'fettig' => 'fett'];

    /**
     * K4a (Kurator für Dominique, 29.10.2026: „Wahrnehmung gewinnt"): Sensorik-Dimension → Achse für alle 7 Geschmacksachsen.
     * Hat das Rezept ein GEGARTES Profil (KI oder Hand), ersetzt dessen Wahrnehmung die Anker-Stufe auf diesen Achsen — auch nach
     * unten (Roastbeef: Pfeffer-Anker Schärfe ●●○, gegart wahrgenommen 0,15 → ●○○). Frische bleibt Anker-Wissen (keine Sensorik-Achse).
     */
    private const WAHRNEHMUNG_ACHSE = ['suess' => 'suesse', 'salzig' => 'salz', 'fettig' => 'fett', 'sauer' => 'saeure',
        'bitter' => 'bitter', 'umami' => 'umami', 'scharf' => 'schaerfe'];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly PairingService $pairing,
        private readonly SensorikService $sensorik,
        private readonly RecipeRecomputeService $recompute,
    ) {}

    /**
     * Profil eines Rezepts (gespeichert, bei Änderung neu gebaut).
     *
     * @return array{recipe_id: int, anker: list<array{anchor_id: int, anteil: float, verfahren: ?string}>, mehrkern: array<int, true>, nebenanker: array<int, true>, abdeckung: float, eigenschaften: array<string, array{stufe: float, quelle: string}>, offene_bedarfe: list<array{achse: string, staerke: string, von: int, deckung?: string}>, roh: array<int, float>, masse: float}
     */
    public function fuer(int $recipeId, int $tiefe = 1, array $besucht = []): array
    {
        if (isset($this->cache[$recipeId])) {
            return $this->cache[$recipeId];
        }
        $p = $this->rechne($recipeId, $tiefe, $besucht);
        // Abgeschnitten (Zyklus, Tiefe > 3) oder Rezept fehlt: nie speichern, nie cachen —
        // sonst überschriebe ein leeres Teilergebnis das echte Profil.
        if ($p['vollstaendig']) {
            $this->speichere($p);
            $this->cache[$recipeId] = $p;
        }

        return $p;
    }

    /**
     * Profil eines Rezepts und aller Rezepte, die es (transitiv) verwenden, neu rechnen — z. B. nach
     * der KI-Sensorik (neue Texturen), ohne Preis-Recompute. Alle Ebenen nach oben (A-5, 2.1c: ein Graph-Dienst mit Besucht-Liste),
     * Zyklen werden übersprungen. Kinder vor Eltern (Eltern-Hash enthält sie).
     *
     * @return list<int> neu gerechnete recipe_ids
     */
    public function nachziehen(int $recipeId): array
    {
        $this->cache = [];
        $reihenfolge = app(\Platform\FoodAlchemist\Services\Rezept\RezeptGraphService::class)->vorfahren([$recipeId]);
        foreach ($reihenfolge as $id) {
            $this->fuer($id);
        }

        return $reihenfolge;
    }

    /**
     * Profil eines einzeln eingesetzten Grundprodukts bzw. Ankers (Gericht ohne Basisrezept):
     * derselbe Aufbau wie ein Rezept-Profil, mit 100 % des (ggf. Varianten-)Ankers.
     *
     * @return array{anker: list<array{anchor_id: int, anteil: float, verfahren: ?string}>, abdeckung: float, eigenschaften: array<string, array{stufe: float, quelle: string}>, offene_bedarfe: list<array{achse: string, staerke: string, von: int, deckung?: string}>}
     */
    public function einzel(int $anker, ?Verfahren $verfahren = null): array
    {
        return $this->einzelMitKernankern([$anker], $verfahren);
    }

    /** Einzelnes Grundprodukt für die Kombination seiner Zutaten mit anderen Bestandteilen. */
    public function einzelMitKernankern(array $ankerIds, ?Verfahren $verfahren = null, array $nebenanker = []): array
    {
        $varianten = array_unique(array_map(fn ($id) => $this->variante((int) $id, $verfahren), $ankerIds));
        $anteile = array_fill_keys($varianten, 100.0 / max(1, count($varianten)));
        $eigenschaften = [];
        foreach (DB::table('foodalchemist_anchor_eigenschaften')->whereIn('anchor_id', $varianten)
            ->where('status', '!=', WissensStatus::Verworfen->value)->get(['achse', 'stufe', 'quelle']) as $e) {
            if ((float) $e->stufe > ($eigenschaften[$e->achse]['stufe'] ?? -1)) {
                $eigenschaften[$e->achse] = ['stufe' => (float) $e->stufe, 'quelle' => (string) $e->quelle];
            }
        }

        return [
            'anker' => array_map(fn ($id, $anteil) => ['anchor_id' => $id, 'anteil' => $anteil, 'verfahren' => $verfahren?->value],
                array_keys($anteile), array_values($anteile)),
            'abdeckung' => 100.0,
            'nebenanker' => array_diff_key(array_fill_keys(array_map(fn ($id) => $this->variante((int) $id, $verfahren), $nebenanker), true), $anteile),
            'eigenschaften' => $eigenschaften,
            'offene_bedarfe' => $this->offeneBedarfe($anteile, $eigenschaften),
        ];
    }

    /**
     * Profil frisch rechnen, OHNE zu speichern oder zu cachen (Messung vorher/nachher, {@see \Platform\FoodAlchemist\Console\Diagnose\RezeptLueckenVergleichCommand}).
     *
     * @return array<string, mixed>
     */
    public function berechne(int $recipeId): array
    {
        return $this->rechne($recipeId, 1, []);
    }

    /** Für Tests und Neuaufbau: Speicher-Cache leeren. */
    public function vergiss(): void
    {
        $this->cache = [];
        $this->dosisCache = [];
    }

    /** @return list<array{anchor_id: int, kern: bool, staerke: ?string, anteil: ?float}> */
    public function ankerFuerAnzeige(array $profil): array
    {
        $anteile = array_column($profil['anker'], 'anteil', 'anchor_id');
        $anzeige = [];
        foreach (array_keys($anteile + $profil['mehrkern'] + $profil['nebenanker']) as $id) {
            $staerke = $profil['staerken'][$id] ?? null;
            $anzeige[] = [
                'anchor_id' => $id,
                'kern' => ! isset($profil['nebenanker'][$id]),
                'staerke' => $staerke,
                'anteil' => $staerke !== null || isset($profil['mehrkern'][$id]) ? null : ($anteile[$id] ?? null),
            ];
        }

        return $anzeige;
    }

    /** @return array<string, mixed> */
    private function rechne(int $recipeId, int $tiefe, array $besucht): array
    {
        $leer = ['recipe_id' => $recipeId, 'anker' => [], 'mehrkern' => [], 'nebenanker' => [], 'staerken' => [], 'anker_quellen' => [],
            'abdeckung' => 0.0, 'eigenschaften' => [], 'offene_bedarfe' => [], 'roh' => [], 'masse' => 0.0, 'hash_teile' => [], 'vollstaendig' => false];
        if (isset($besucht[$recipeId])) {                          // 2.1c: kein Tiefen-Deckel (A-5), die Besucht-Liste fängt Zyklen
            return $leer;
        }
        $besucht[$recipeId] = true;

        $rezept = FoodAlchemistRecipe::find($recipeId);
        $zeilen = FoodAlchemistRecipeIngredient::query()->with(['unit', 'gp', 'referencedRecipe'])
            ->where('recipe_id', $recipeId)->where('is_optional', false)
            ->orderBy('position')->orderBy('id')->get();
        if ($rezept === null) {
            return $leer;
        }
        if ($zeilen->isEmpty()) {
            return ['vollstaendig' => true, 'eigenschaften' => $this->eigenschaften($recipeId, [])] + $leer;
        }

        $gpAnker = $this->pairing->gpAnkerZuordnungen($zeilen->pluck('gp_id')->filter()->unique()->all());
        $neutral = $this->pairing->neutralAnker();
        $namensVerfahren = Verfahren::ausText((string) $rezept->name);
        $istGericht = (bool) $rezept->is_sales_recipe;
        $standard = $istGericht ? app(DarreichungService::class)->standardProEinheit($rezept, Team::find($rezept->team_id)) : [];
        $gramm = $zeilen->mapWithKeys(fn ($z) => [
            $z->id => $istGericht ? (float) ($standard[$z->id]['masse_g'] ?? 0.0) : $this->recompute->grammJeZeile($z),
        ]);
        $staerksteZeile = $gramm->sortDesc()->keys()->first();
        $gesamt = (float) $gramm->sum();

        $roh = [];          // anchor_id => Aromamasse
        $mehrkern = [];
        $nebenanker = [];
        $staerken = [];
        $ankerQuellen = [];
        $verfahrenJe = [];  // anchor_id => Verfahren
        $masse = 0.0;
        $masseMitAnker = 0.0;
        $hash = [];
        $wuerzGps = [];       // alle GP-Zeilen (auch < 5 % Aromamasse) — für „abgeschmeckt"
        $wuerzAnker = [];
        $abgeschmeckt = [];   // achse => true
        foreach ($zeilen as $z) {
            $g = (float) $gramm[$z->id];
            if ($istGericht && $g <= 0) {
                continue;
            }
            // Spec 58 (Dominique 06.10., übertragen auf die Teller-Rollen 11.10.): Gewicht aus der Regel pairing.rollen_gewicht.
            $rolle = $istGericht ? 1.0 : \Platform\FoodAlchemist\Support\TellerRolle::gewicht($z->role);
            $masse += $g;
            if ($z->referenced_recipe_id !== null) {
                $sub = $this->fuer((int) $z->referenced_recipe_id, $tiefe + 1, $besucht);
                $hash[] = ['sub', (int) $z->referenced_recipe_id, $g, $rolle, $sub['hash_teile'] ?? []];
                $abgeschmeckt += array_fill_keys($sub['abgeschmeckt'] ?? [], true);
                $mehrkern += $sub['mehrkern'];
                $nebenanker += $sub['nebenanker'];
                foreach ($sub['anker_quellen'] as $id => $quellen) {
                    $ankerQuellen[$id] = ($ankerQuellen[$id] ?? []) + $quellen;
                }
                foreach ($sub['staerken'] as $id => $staerke) {
                    $this->merkeStaerke($staerken, $id, $staerke);
                }
                if ($sub['masse'] <= 0 || $sub['roh'] === []) {
                    continue;
                }
                $faktor = $istGericht ? $g / array_sum($sub['roh']) : $g / $sub['masse'] * $rolle;
                foreach ($sub['roh'] as $aid => $wert) {
                    $roh[$aid] = ($roh[$aid] ?? 0) + $wert * $faktor;
                }
                $masseMitAnker += $g * min(1.0, $sub['abdeckung'] / 100);

                continue;
            }

            $text = (string) ($z->display_name ?: $z->raw_text ?: '');
            $gpName = $z->gp?->name;
            $mappings = $gpAnker->get($z->gp_id, collect());
            $kerne = $mappings->where('role', 'kern')->pluck('anchor_id')->all();
            $neben = $mappings->where('role', 'neben')->pluck('anchor_id')->all();
            if ($kerne === [] && $neben === []) {
                $fallback = $this->pairing->ankerIdExakt($z->gp_id !== null ? $gpName : $z->raw_text);
                $kerne = $fallback === null ? [] : [$fallback];
            }
            if ($z->gp_id !== null) {
                $wuerzGps[] = (int) $z->gp_id;
            }
            $verfahren = Verfahren::ausText($text) ?? Verfahren::ausText((string) strstr((string) $gpName, ':'))
                ?? ($z->id === $staerksteZeile ? $namensVerfahren : null);
            foreach ($mappings as $mapping) {
                $id = $this->variante((int) $mapping->anchor_id, $verfahren);
                $this->merkeStaerke($staerken, $id, $mapping->staerke);
            }
            $kerne = array_values(array_unique(array_map(fn ($id) => $this->variante((int) $id, $verfahren), array_diff($kerne, [$neutral]))));
            $neben = array_map(fn ($id) => $this->variante((int) $id, $verfahren), array_diff($neben, [$neutral]));
            $hash[] = ['z', $z->gp_id, $z->raw_text, $g, $rolle, $kerne, $neben, $verfahren?->value];
            $nebenanker += array_fill_keys($neben, true);
            $anzahl = count($kerne);
            if ($anzahl > 1) {
                $mehrkern += array_fill_keys($kerne, true);
            }
            if ($anzahl > 0 || $neben !== []) {
                $masseMitAnker += $g;
            }
            foreach ($kerne as $anker) {
                $quelle = $z->gp_id !== null ? 'gp:'.$z->gp_id : 'zutat:'.$z->id;
                $ankerQuellen[$anker][$quelle] = true;
                $wuerzAnker[] = $anker;
                $intensitaet = $this->intensitaet($anker);
                if ($intensitaet <= 0) {
                    continue;
                }
                // Internes Rechengewicht je Zutat, keine gemessene Verteilung ihrer Kernaromen.
                $roh[$anker] = ($roh[$anker] ?? 0) + $this->aromamasse($anker, $g, $gesamt, $intensitaet) * $rolle / $anzahl;
                $verfahrenJe[$anker] = $verfahren?->value ?? ($verfahrenJe[$anker] ?? null);
            }
        }

        $anteile = $this->normiere($roh);
        $eigenschaften = $this->eigenschaften($recipeId, $anteile);
        $abgeschmeckt = array_keys($abgeschmeckt + $this->abgeschmeckt($wuerzGps, $wuerzAnker));
        sort($abgeschmeckt);
        $offen = $this->offeneBedarfe($anteile, $eigenschaften, $abgeschmeckt);

        return [
            'recipe_id' => $recipeId,
            'anker' => array_map(fn ($aid, $a) => ['anchor_id' => $aid, 'anteil' => $a, 'verfahren' => $verfahrenJe[$aid] ?? null],
                array_keys($anteile), array_values($anteile)),
            'mehrkern' => $mehrkern,
            'nebenanker' => array_diff_key($nebenanker, $anteile, $mehrkern),
            'staerken' => $staerken,
            'anker_quellen' => $ankerQuellen,
            'abdeckung' => $masse > 0 ? round(100 * $masseMitAnker / $masse, 2) : 0.0,
            'eigenschaften' => $eigenschaften,
            'offene_bedarfe' => $offen,
            'abgeschmeckt' => $abgeschmeckt,
            'roh' => $roh,
            'masse' => $masse,
            'hash_teile' => [$hash, $anteile, $staerken, $eigenschaften, $offen],
            'vollstaendig' => true,
        ];
    }

    private function merkeStaerke(array &$staerken, int $anker, ?string $staerke): void
    {
        // Eine abweichende Einstufung in einer anderen Zutat ergibt keine gemeinsame Stufe.
        $staerken[$anker] = array_key_exists($anker, $staerken) && $staerken[$anker] !== $staerke ? null : $staerke;
    }

    /**
     * @param  array<int, float>  $roh
     * @return array<int, float> anchor_id → Anteil in %, absteigend
     */
    private function normiere(array $roh): array
    {
        $summe = array_sum($roh);
        if ($summe <= 0) {
            return [];
        }
        $anteile = array_map(fn ($w) => 100 * $w / $summe, $roh);
        $anteile = array_filter($anteile, fn ($a) => $a >= self::SCHWELLE_ANTEIL);
        $summe = array_sum($anteile);
        $out = array_map(fn ($a) => round(100 * $a / $summe, 2), $anteile);
        arsort($out);

        return $out;
    }

    /**
     * @param  array<int, float>  $anteile
     * @return array<string, array{stufe: float, quelle: string}>
     */
    private function eigenschaften(int $recipeId, array $anteile): array
    {
        $out = [];
        if ($anteile !== []) {
            foreach (DB::table('foodalchemist_anchor_eigenschaften')->whereIn('anchor_id', array_keys($anteile))
                ->where('status', '!=', WissensStatus::Verworfen->value)->get(['anchor_id', 'achse', 'stufe', 'quelle']) as $e) {
                $wert = round((float) $e->stufe * min(1.0, $anteile[(int) $e->anchor_id] / self::VOLL_AB_ANTEIL), 2);
                if ($wert > ($out[$e->achse]['stufe'] ?? -1)) {
                    $out[$e->achse] = ['stufe' => $wert, 'quelle' => (string) $e->quelle];
                }
            }
        }
        // KI-Texturen des Rezepts (foodalchemist_recipe_textures, Intensität 0–1): Croutons sind knusprig,
        // auch wenn ihr Anker es nicht ist. Ungeprüft, darum eigene Quelle (Grundlage „KI-Einschätzung").
        foreach (DB::table('foodalchemist_recipe_textures as t')->join('foodalchemist_vocab_textures as v', 'v.id', '=', 't.texture_vocab_id')
            ->where('t.recipe_id', $recipeId)->whereIn('v.slug', array_keys(self::TEXTUR_ACHSE))
            ->get(['v.slug', 't.intensity']) as $t) {
            $achse = self::TEXTUR_ACHSE[$t->slug];
            $wert = round(3 * (float) $t->intensity, 2);
            if ($wert > 0 && $wert > ($out[$achse]['stufe'] ?? -1)) {
                $out[$achse] = ['stufe' => $wert, 'quelle' => 'ki_textur'];
            }
        }
        $s = $this->sensorik->fuerRezept($recipeId);
        $sensorik = $s['geschmack'] ?? [];
        if (in_array($s['source'] ?? null, ['ki', 'manual'], true)) {
            foreach (self::WAHRNEHMUNG_ACHSE as $dim => $achse) {
                $wert = round(3 * (float) ($sensorik[$dim] ?? 0), 2);
                if ($wert > 0) {
                    $out[$achse] = ['stufe' => $wert, 'quelle' => 'wahrnehmung'];
                } else {
                    unset($out[$achse]);                               // gegart nicht wahrnehmbar → liefert die Achse nicht
                }
            }
        } else {
            foreach (self::SENSORIK_BELEGT as $dim => $achse) {
                $wert = round(3 * (float) ($sensorik[$dim] ?? 0), 2);
                if ($wert > 0 && $wert > ($out[$achse]['stufe'] ?? -1)) {
                    $out[$achse] = ['stufe' => $wert, 'quelle' => 'sensorik'];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<int, float>  $anteile
     * @param  array<string, array{stufe: float, quelle: string}>  $eigenschaften
     * @return list<array{achse: string, staerke: string, von: int, deckung: string}>
     */
    private function offeneBedarfe(array $anteile, array $eigenschaften, array $abgeschmeckt = []): array
    {
        $abgeschmeckt = array_flip($abgeschmeckt);
        $kern = array_keys(array_filter($anteile, fn ($a) => $a >= self::KERN_FUER_BEDARF));
        if ($kern === []) {
            return [];
        }
        $offen = [];
        foreach (DB::table('foodalchemist_anchor_bedarfe')->whereIn('anchor_id', $kern)
            ->where('status', '!=', WissensStatus::Verworfen->value)
            ->orderByRaw("CASE staerke WHEN 'muss' THEN 0 ELSE 1 END")->orderBy('achse')
            ->get(['anchor_id', 'achse', 'staerke', 'deckung']) as $b) {
            if (Achse::tryFrom((string) $b->achse) === null || ($eigenschaften[$b->achse]['stufe'] ?? 0) >= 2
                || isset($abgeschmeckt[$b->achse])) {
                continue;
            }
            // je Achse einmal — der stärkste Bedarf zählt; Deckung vereint (Partner + Zubereitung = beides)
            $deckung = BedarfDeckung::vereine($offen[$b->achse]['deckung'] ?? $b->deckung, $b->deckung);
            if (! isset($offen[$b->achse]) || ($b->staerke === 'muss' && $offen[$b->achse]['staerke'] !== 'muss')) {
                $offen[$b->achse] = ['achse' => (string) $b->achse, 'staerke' => (string) $b->staerke, 'von' => (int) $b->anchor_id];
            }
            $offen[$b->achse]['deckung'] = $deckung;
        }

        return array_values($offen);
    }

    /**
     * Abgeschmeckt (Dominique 15.10.): Salz und Säure sind Würze — eine Prise Salz oder ein Spritzer
     * Zitrone deckt den Bedarf, obwohl sie nach Masse kaum zählen. Gemessen vorher: 418 Basisrezepte
     * MIT Salz in der Zutatenliste meldeten „Salz fehlt" (normal gesalzen = Nährwert-Stufe 1–1,8).
     * Keine neue Schwelle: eine Zutat zählt, wenn sie die Achse selbst deutlich liefert (Stufe ≥ 2 —
     * dieselbe Lieferanten-Grenze wie überall): Anker-Eigenschaft, bei Salz auch der gemessene
     * Nährwert des GP über die Sensorik-Kurve.
     *
     * @param  list<int>  $gpIds
     * @param  list<int>  $ankerIds
     * @return array<string, true>
     */
    private function abgeschmeckt(array $gpIds, array $ankerIds): array
    {
        $out = [];
        if ($ankerIds !== []) {
            foreach (DB::table('foodalchemist_anchor_eigenschaften')->whereIn('anchor_id', array_values(array_unique($ankerIds)))
                ->whereIn('achse', self::ABGESCHMECKT)->where('stufe', '>=', 2)
                ->where('status', '!=', WissensStatus::Verworfen->value)->distinct()->pluck('achse') as $achse) {
                $out[(string) $achse] = true;
            }
        }
        if (! isset($out['salz']) && $gpIds !== []) {
            foreach ($this->sensorik->erdungBulk($gpIds) as $dims) {
                if (3 * (float) ($dims['salzig']['wert'] ?? 0) >= 2) {
                    $out['salz'] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /** Inspire-Variante zum Verfahren, falls vorhanden (gleicher Grundname bzw. Grund-Anker). */
    private function variante(int $anker, ?Verfahren $verfahren): int
    {
        if ($verfahren === null || $verfahren === Verfahren::Roh) {
            return $anker;
        }
        $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $anker)->first(['grundname', 'verfahren']);
        if ($a === null || $a->grundname === null || $a->verfahren === $verfahren->value) {
            return $anker;
        }
        $treffer = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where('grundname', $a->grundname)->where('verfahren', $verfahren->value)->orderBy('id')->pluck('id');

        return $treffer->count() === 1 ? (int) $treffer->first() : $anker;
    }

    /** Aromamasse einer Zutatenzeile: linear nach Gramm, Würzzutaten nach Wirk-Dosis (siehe Klassen-Doc). */
    private function aromamasse(int $anker, float $g, float $gesamt, float $intensitaet): float
    {
        $dosis = $this->wirkDosis($anker);
        if ($dosis === null || $gesamt <= 0) {
            return $g * $intensitaet;
        }
        $wirkung = min(self::WUERZ_DECKEL, sqrt((100 * $g / $gesamt) / $dosis));

        return $gesamt * self::WUERZ_GEWICHT * $intensitaet * $wirkung;
    }

    private function wirkDosis(int $anker): ?float
    {
        if (! array_key_exists($anker, $this->dosisCache)) {
            $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $anker)->first(['category', 'subcategory']);
            $pfad = (string) ($a->subcategory ?? $a->category ?? '');
            $treffer = null;
            foreach (self::WIRKDOSIS as $praefix => $dosis) {
                if (($pfad === $praefix || str_starts_with($pfad, $praefix.'/')) && ($treffer === null || strlen($praefix) > strlen($treffer))) {
                    $treffer = $praefix;
                }
            }
            $this->dosisCache[$anker] = $treffer !== null ? self::WIRKDOSIS[$treffer] : null;
        }

        return $this->dosisCache[$anker];
    }

    private function intensitaet(int $anker): float
    {
        $wert = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $anker)->value('aroma_intensitaet');

        return $wert !== null ? (float) $wert : 1.0;
    }

    /** @param  array<string, mixed>  $p */
    private function speichere(array $p): void
    {
        $hash = hash('sha256', json_encode($p['hash_teile']));
        $alt = DB::table('foodalchemist_recipe_profile')->where('recipe_id', $p['recipe_id'])->value('quelle_hash');
        if ($alt === $hash) {
            return;
        }
        DB::transaction(function () use ($p, $hash, $alt) {
            $daten = ['abdeckung' => $p['abdeckung'], 'eigenschaften' => json_encode($p['eigenschaften']),
                'offene_bedarfe' => json_encode($p['offene_bedarfe']), 'quelle_hash' => $hash, 'updated_at' => now()];
            if ($alt === null) {
                DB::table('foodalchemist_recipe_profile')->insert($daten + ['recipe_id' => $p['recipe_id'], 'created_at' => now()]);
            } else {
                DB::table('foodalchemist_recipe_profile')->where('recipe_id', $p['recipe_id'])->update($daten);
            }
            DB::table('foodalchemist_recipe_profile_anker')->where('recipe_id', $p['recipe_id'])->delete();
            if ($p['anker'] !== []) {
                DB::table('foodalchemist_recipe_profile_anker')->insert(array_map(
                    fn ($a) => $a + ['recipe_id' => $p['recipe_id']], $p['anker']));
            }
        });
    }
}
