<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanBasis;
use Platform\FoodAlchemist\Services\WordingResolver;

/**
 * Ausgabe des Speiseplans (98 · 3.3 S3, aus SpeiseplanService verschoben): Wochenaushang (dokumentDaten, Spec 31
 * Stufe B), Druck-Formate Tischaufsteller/Linienschild/Liste (Spec 57 Paket 6), Druckoptik, CSV und Branding (Spec 43).
 * buffetKarten folgt nach dem Merge von Charge 4b (Diät-Fix in derselben Methode). Nutzt Basis, Ernährung und Ansicht,
 * nie die Fassade.
 */
final class SpeiseplanAusgabe
{
    public const AUSGABE_FORMATE = ['woche', 'tag', 'schild', 'liste', 'buffet'];

    public function __construct(
        private SpeiseplanBasis $basis,
        private SpeiseplanErnaehrung $ernaehrung,
        private SpeiseplanAnsicht $ansicht,
    ) {}

    /**
     * Aushang-Daten (Spec 31 / Stufe B): druckbarer Wochen-Speiseplan als Grid Linien × Mo–Fr,
     * je Gericht Allergen-Buchstaben (A…) + Zusatzstoff-Nummern (1…), darunter eine Legende NUR
     * der tatsächlich vorkommenden Kennzeichen (LMIV). Spuren als »Code*« markiert.
     *
     * @return array{plan:FoodAlchemistSpeiseplan, mahlzeitLabel:string, kwLabel:string,
     *               tage:list<array{ymd:string,label:string}>,
     *               zeilen:list<array{linie:?string,color:?string,zellen:array<string,list<array{name:string,codes:list<string>}>>}>,
     *               legende:array{allergene:list<array{code:string,label:string}>, zusatzstoffe:list<array{code:string,label:string}>},
     *               kostformen:list, erzeugt:string}
     */
    public function dokumentDaten(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit = 'mittag', ?string $montag = null, bool $intern = false, bool $mitKaskade = false, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitPreis = false): array
    {
        $mahlzeit = array_key_exists($mahlzeit, SpeiseplanBasis::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = ($montag !== null ? Carbon::parse($montag) : ($plan->start_date ?? Carbon::now()))->startOfWeek(Carbon::MONDAY);

        // Codes: Allergene = Buchstaben in EU-Reihenfolge, Zusatzstoffe = Nummern.
        $allergenCode = [];
        $i = 0;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $slug => $label) {
            $allergenCode[$slug] = ['code' => chr(65 + $i), 'label' => $label];
            $i++;
        }
        $zusatzCode = [];
        $j = 1;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $slug => $label) {
            $zusatzCode[$slug] = ['code' => (string) $j, 'label' => $label];
            $j++;
        }

        $agg = app(ConcepterAggregateService::class);
        $tage = [];
        $raster = $this->ansicht->wochenRaster($plan, $mahlzeit, $mo);       // [line_id][Ymd] => [entries]
        $usedAlg = [];
        $usedZus = [];

        // Zellen-Inhalt je Eintrag → Name + Codes; sammelt nebenbei die Legende.
        $codesFuer = function (FoodAlchemistSpeiseplanEintrag $e) use (&$usedAlg, &$usedZus, $outlet, $mitPreis): array {
            $codes = $this->ernaehrung->eintragCodes($e, $usedAlg, $usedZus);

            $zelle = ['name' => $this->ernaehrung->eintragName($e), 'codes' => $codes];
            // Gericht-Foto als Identifier (frische URL erst im Aushang, PresentationService::hydrateImages).
            $dish = $e->sales_recipe_id !== null ? $e->dish : null;
            if ($dish !== null && ($dish->image_context_file_id || $dish->image_path)) {
                $zelle['image'] = ['context_file_id' => $dish->image_context_file_id, 'path' => $dish->image_path];
            }
            // Preis nur wenn ausdrücklich gewünscht (GV-Aushang ist per Default preislos), betriebs-aware.
            if ($mitPreis) {
                $zelle['vk'] = round((float) $this->ansicht->eintragPreis($e, $outlet)['vk'], 2);
            }

            return $zelle;
        };

        // Spec 57 · Paket 9: Spalten = Öffnungstage des Plans (Standard Mo–Fr).
        foreach ($this->basis->wochenTage($plan, $mo) as $tag) {
            $tage[] = ['ymd' => $tag->format('Y-m-d'), 'label' => SpeiseplanBasis::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.')];
        }
        $letzterTag = $tage !== [] ? Carbon::parse(end($tage)['ymd']) : $mo->copy()->addDays(4);

        // Zeilen = Menü-Linien dieser Mahlzeit (+ »Ohne Linie«, falls belegt). Spec 57 · E11: eine
        // Linie mit fester Mahlzeit erscheint nur dort.
        $linienListe = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($mahlzeit))
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'role' => $l->role, 'plu' => $l->plu])->values()->all();
        if (isset($raster[0])) {
            $linienListe[] = ['id' => 0, 'name' => 'Ohne Linie', 'color' => null];
        }

        $zeilen = [];
        foreach ($linienListe as $lin) {
            $zellen = [];
            foreach ($tage as $t) {
                $eintraege = $raster[$lin['id']][$t['ymd']] ?? [];
                $zellen[$t['ymd']] = array_map($codesFuer, $eintraege);
            }
            $zeilen[] = ['linie' => $lin['name'], 'color' => $lin['color'], 'role' => $lin['role'] ?? null, 'plu' => $lin['plu'] ?? null, 'zellen' => $zellen];
        }

        $legendeAlg = [];
        foreach ($allergenCode as $slug => $cl) {
            if (isset($usedAlg[$slug])) {
                $legendeAlg[] = $cl;
            }
        }
        $legendeZus = [];
        foreach ($zusatzCode as $slug => $cl) {
            if (isset($usedZus[$slug])) {
                $legendeZus[] = $cl;
            }
        }

        // #3: optionaler Produktions-Kaskaden-Anhang. Gericht-Rezepte je Wochen-Eintrag via
        // eintragGerichte (über alle Linien × Tage der gewählten Mahlzeit/Woche). Je Gericht der
        // rekursive Baum aus ReportExportService (report-recipe-node). EK nur bei $intern.
        $kaskaden = [];
        if ($mitKaskade) {
            $gerichtIds = [];
            foreach ($raster as $proLinie) {
                foreach ($proLinie as $eintraege) {
                    foreach ($eintraege as $e) {
                        foreach ($this->ernaehrung->eintragGerichte($e) as $g) {
                            if ($g !== null) {
                                $gerichtIds[] = (int) $g->id;
                            }
                        }
                    }
                }
            }
            $kOpt = [
                'stammdaten' => true, 'zutaten' => true, 'kaskade' => true,
                'steps' => false, 'sensorik' => false, 'produktion' => false, 'bilder' => false,
                'deklaration' => false, 'naehrwerte' => false, 'notizen' => false,
                'preise' => $intern, 'lieferanten' => $intern, 'ek' => $intern, 'intern' => $intern,
            ];
            $report = app(\Platform\FoodAlchemist\Services\ReportExportService::class);
            foreach (array_values(array_unique($gerichtIds)) as $gid) {
                try {
                    $d = $report->rezeptDaten($team, $gid, $kOpt);
                    $kaskaden[] = ['name' => $d['name'], 'recipe' => $d['recipe'], 'optionen' => $kOpt];
                } catch (\Throwable) {
                    // fail-soft
                }
            }
        }

        return [
            'plan' => $plan,
            'mahlzeitLabel' => SpeiseplanBasis::MAHLZEITEN[$mahlzeit],
            'kwLabel' => 'KW ' . $mo->isoWeek() . ' · ' . $mo->format('d.m.') . '–' . $letzterTag->format('d.m.Y'),
            'montag' => $mo->format('Y-m-d'),
            'mahlzeit' => $mahlzeit,
            'tage' => $tage,
            'zeilen' => $zeilen,
            'legende' => ['allergene' => $legendeAlg, 'zusatzstoffe' => $legendeZus],
            'kostformen' => $this->ernaehrung->kostformAbdeckung($plan, $mahlzeit, $mo),
            'naehrwerte' => $this->ernaehrung->wochenNaehrwerte($plan, $mahlzeit, $mo),
            'erzeugt' => Carbon::now()->format('d.m.Y'),
            // #3: Kaskaden-Anhang + interne Sicht.
            'intern' => $intern,
            'kaskaden' => $kaskaden,
        ];
    }

    /**
     * Daten für die Druck-Formate neben dem Wochenaushang. Baut auf {@see dokumentDaten}
     * (Codes, Legende, Kostformen) und {@see zellenKennzahlen} (Titel/Wording, Diät, VK,
     * Komponenten) auf — keine zweite Kennzeichnungs- oder Preislogik.
     *
     * - `tag`: ein Tag, alle Linien (Tischaufsteller)
     * - `schild`: ein Tag, je Linie ein Schild (optional nur eine Linie), mit Preis nur wenn gewünscht
     * - `liste`: Allergen- und Komponentenliste der Woche oder eines Tages (für den Ordner an der Ausgabe)
     *
     * Einträge ohne Linie (Raster-Schlüssel 0) laufen als „Weitere Gerichte“ mit — sonst fehlten
     * sie in der Allergenliste. Die Legende gilt für die gezeigten Einträge, nicht für die Woche.
     *
     * @return array<string, mixed>
     */
    public function ausgabeFormat(Team $team, FoodAlchemistSpeiseplan $plan, string $format, string $mahlzeit = 'mittag', ?string $montag = null, ?string $tag = null, ?int $lineId = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitPreis = false): array
    {
        $format = in_array($format, self::AUSGABE_FORMATE, true) ? $format : 'woche';
        $dok = $this->dokumentDaten($team, $plan, $mahlzeit, $montag ?? $tag, false, false, $outlet, $mitPreis);
        if ($format === 'woche') {
            return $dok + ['format' => 'woche'];
        }
        $mo = Carbon::parse($dok['montag']);
        $zk = $this->ansicht->zellenKennzahlen($team, $plan, $dok['mahlzeit'], $mo, $outlet, $format === 'liste');
        $raster = $this->ansicht->wochenRaster($plan, $dok['mahlzeit'], $mo);
        $tageDerWoche = array_column($dok['tage'], 'label', 'ymd');
        $tagYmd = $tag !== null ? Carbon::parse($tag)->format('Y-m-d') : null;
        $tage = $tagYmd !== null && isset($tageDerWoche[$tagYmd]) ? [$tagYmd] : array_keys($tageDerWoche);
        if ($format !== 'liste' && $tagYmd === null) {
            $tage = array_slice($tage, 0, 1);   // Tischaufsteller/Schild: ohne Tag der erste Öffnungstag
        }

        $linien = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($dok['mahlzeit']) && ($lineId === null || (int) $l->id === $lineId))
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'plu' => $l->plu, 'role' => $l->role])->values()->all();
        if ($lineId === null && isset($raster[0])) {
            $linien[] = ['id' => 0, 'name' => 'Weitere Gerichte', 'color' => null, 'plu' => null, 'role' => null];
        }
        $usedCodes = [];
        $bloecke = [];
        foreach ($tage as $ymd) {
            $zeilen = [];
            foreach ($linien as $l) {
                $eintraege = [];
                foreach ($raster[$l['id']][$ymd] ?? [] as $e) {
                    $k = $zk['eintraege'][$e->id] ?? [];
                    $eintraege[] = [
                        'titel' => $k['titel'] ?? $e->inhaltName(),
                        'untertitel' => $k['untertitel'] ?? null,
                        'codes' => $k['codes'] ?? [],
                        'diaet' => $k['diaet'] ?? [],
                        'vk' => $mitPreis ? ($k['vk'] ?? null) : null,
                        'komponenten' => $k['komponenten'] ?? [],
                        'kcal' => $k['kcal'] ?? null,
                    ];
                    foreach ($k['codes'] ?? [] as $c) {
                        $usedCodes[rtrim((string) $c, '*')] = true;
                    }
                }
                // Leeres Schild nur für echte Linien — „Weitere Gerichte“ erscheint nur, wenn belegt.
                if ($eintraege !== [] || ($format === 'schild' && $l['id'] !== 0)) {
                    $zeilen[] = ['linie' => $l['name'], 'color' => $l['color'], 'plu' => $l['plu'], 'role' => $l['role'], 'eintraege' => $eintraege];
                }
            }
            $bloecke[] = ['ymd' => $ymd, 'label' => $tageDerWoche[$ymd] ?? $ymd, 'zeilen' => $zeilen];
        }

        return [
            'format' => $format, 'plan' => $plan, 'mahlzeitLabel' => $dok['mahlzeitLabel'], 'kwLabel' => $dok['kwLabel'],
            'bloecke' => $bloecke, 'legende' => $this->legendeAusCodes(array_keys($usedCodes)), 'mitPreis' => $mitPreis, 'erzeugt' => $dok['erzeugt'],
            'rollen' => FoodAlchemistSpeiseplanLinie::ROLLEN, 'optik' => $this->druckOptik($team, $plan),
        ];
    }

    /**
     * Legende nur für die Codes, die im Dokument wirklich stehen (Allergen-Buchstaben,
     * Zusatzstoff-Nummern; `*` = Spuren ist vorher abgeschnitten). Reihenfolge wie im Katalog.
     *
     * @param  list<string>  $codes
     * @return array{allergene: list<array{code:string,label:string}>, zusatzstoffe: list<array{code:string,label:string}>}
     */
    private function legendeAusCodes(array $codes): array
    {
        $katalog = app(ConcepterAggregateService::class)->kennzeichnungKatalog();
        $da = array_flip(array_map('strval', $codes));

        return [
            'allergene' => array_values(array_filter($katalog['allergene'], fn ($a) => isset($da[$a['code']]))),
            'zusatzstoffe' => array_values(array_filter($katalog['zusatzstoffe'], fn ($z) => isset($da[$z['code']]))),
        ];
    }

    /**
     * Optik der Gäste-Drucke (Tischaufsteller, Linienschild, Buffetschild) aus dem Präsentationsmodus:
     * Logo + Footer aus dem Branding des Plans, Farben + Schriftcharakter aus dem gewählten
     * Präsentations-Design. Gedruckt wird immer auf weißem Papier — ein dunkles Design (Kiosk)
     * liefert deshalb nur den Akzent, abgedunkelt bis er auf Weiß lesbar ist (≥ 4,5 : 1).
     *
     * @return array{logo:?string, footer:?string, akzent:string, band:string, text:string, muted:string, serif:bool, schrift_kopf:string, schrift_text:string}
     */
    public function druckOptik(Team $team, FoodAlchemistSpeiseplan $plan): array
    {
        $tokens = app(PresentationDesignService::class)->resolveTokens($plan->presentation_design ?: 'kiosk', $team);
        $pal = $tokens['palette'] ?? [];
        $hell = $this->luminanz((string) ($pal['bg'] ?? '#ffffff')) >= 0.5;
        $akzent = $this->lesbarAufWeiss((string) ($pal['primary'] ?? ''), '#6d28d9');
        $serif = in_array($tokens['typography']['heading'] ?? 'display-serif', ['display-serif', 'serif'], true);

        return [
            'logo' => app(FoodAlchemistMediaService::class)->dataUri($plan->logo_context_file_id ?? null, $plan->logo_path ?? null),
            'footer' => trim((string) $plan->footer_text) !== '' ? trim((string) $plan->footer_text) : null,
            'akzent' => $akzent,
            'band' => $this->lesbarAufWeiss((string) ($plan->band_color ?? ''), $akzent),
            'text' => $hell ? $this->lesbarAufWeiss((string) ($pal['text'] ?? ''), '#1a1712') : '#1a1712',
            'muted' => $hell ? $this->lesbarAufWeiss((string) ($pal['muted'] ?? ''), '#5f5850') : '#5f5850',
            'serif' => $serif,
            // Schrift-Stacks fürs Template; PT Serif bettet partials/speiseplan-druck-schrift ein.
            'schrift_kopf' => $serif ? '"PT Serif", "DejaVu Serif", Georgia, serif' : '"DejaVu Sans", Arial, sans-serif',
            'schrift_text' => '"DejaVu Sans", Arial, sans-serif',
        ];
    }

    /** Relative Leuchtdichte (WCAG) eines #rrggbb-Werts; Nicht-Hex zählt als Weiß. */
    private function luminanz(string $hex): float
    {
        if (! preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            return 1.0;
        }
        $kanal = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));

        return 0.2126 * $kanal($r) + 0.7152 * $kanal($g) + 0.0722 * $kanal($b);
    }

    /** Farbe so weit abdunkeln, bis sie auf Weiß 4,5 : 1 erreicht; Nicht-Hex → Ersatzfarbe. */
    private function lesbarAufWeiss(string $hex, string $ersatz): string
    {
        $hex = trim($hex);
        if (! preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            return $ersatz;
        }
        [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));
        for ($i = 0; $i < 30; $i++) {
            $farbe = sprintf('#%02x%02x%02x', $r, $g, $b);
            if (1.05 / ($this->luminanz($farbe) + 0.05) >= 4.5) {
                return $farbe;
            }
            [$r, $g, $b] = [(int) floor($r * 0.9), (int) floor($g * 0.9), (int) floor($b * 0.9)];
        }

        return $ersatz;
    }

    /**
     * Buffetschilder: ein Zeltkärtchen je Gericht eines Tages — Pakete und Concepts in ihre
     * Gerichte aufgelöst ({@see eintragGerichte}), optional dazu je Unterrezept (eine Ebene,
     * z. B. Jus oder Beilage) ein eigenes Kärtchen. Allergene ausgeschrieben, nicht als Code.
     *
     * Diät-Hinweise nur aus gesetzten Flags (vegan/vegetarisch, Schwein/Rind/Fisch) — das
     * unbestimmte „mit Fleisch“ aus {@see diaetMerkmale} steht auf keinem Buffetschild, weil
     * Unterrezepte die Flags oft gar nicht tragen. Unbewertete Allergene → `unvollstaendig`.
     *
     * @return array<string, mixed>
     */
    public function buffetKarten(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit = 'mittag', ?string $montag = null, ?string $tag = null, ?int $lineId = null, bool $mitUnterrezepten = true): array
    {
        $mahlzeit = array_key_exists($mahlzeit, SpeiseplanBasis::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = Carbon::parse($montag ?? $tag ?? ($plan->start_date ?? Carbon::now()))->startOfWeek(Carbon::MONDAY);
        $tage = [];
        foreach ($this->basis->wochenTage($plan, $mo) as $t) {
            $tage[$t->format('Y-m-d')] = SpeiseplanBasis::WOCHENTAGE[$t->isoWeekday()] . ' ' . $t->format('d.m.');
        }
        $tagYmd = $tag !== null ? Carbon::parse($tag)->format('Y-m-d') : null;
        if ($tagYmd === null || ! isset($tage[$tagYmd])) {
            $tagYmd = array_key_first($tage) ?? $mo->format('Y-m-d');
        }

        $raster = $this->ansicht->wochenRaster($plan, $mahlzeit, $mo);
        $linienIds = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($mahlzeit) && ($lineId === null || (int) $l->id === $lineId))
            ->map(fn ($l) => (int) $l->id)->values()->all();
        if ($lineId === null) {
            $linienIds[] = 0;
        }
        $linienNamen = $plan->lines->mapWithKeys(fn ($l) => [(int) $l->id => $l->name])->all();

        $agg = app(ConcepterAggregateService::class);
        $katalog = $agg->kennzeichnungKatalog();
        $wording = app(WordingResolver::class);
        $karten = [];
        $gesehen = [];
        $karte = function (FoodAlchemistRecipe $r, ?string $zu, ?string $linie) use ($agg, $katalog, $wording): array {
            $text = $wording->fuerGericht($r)['text'];
            if (trim((string) $r->sales_wording_standard) === '') {
                $text = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $text) ?? $text);   // „(Basis)“-Suffix ist intern
            }
            $teile = array_values(array_filter(array_map('trim', explode('|', $text)), fn ($t) => $t !== ''));
            $k = $agg->kennzeichnungFromGerichte(collect([$r]));
            $allergene = [];
            $unvollstaendig = false;
            foreach ($k['allergene'] as $a) {
                if ($a['status'] === 'enthalten' || $a['status'] === 'spuren') {
                    $allergene[] = ['code' => $katalog['allergene'][$a['slug']]['code'], 'label' => $a['label'], 'spuren' => $a['status'] === 'spuren'];
                } elseif ($a['status'] === 'unbekannt') {
                    $unvollstaendig = true;
                }
            }
            $zusatz = [];
            foreach ($k['zusatzstoffe'] as $z) {
                if ($z['status'] === 'ja') {
                    $zusatz[] = ['code' => $katalog['zusatzstoffe'][$z['slug']]['code'], 'label' => $z['label']];
                }
            }
            $diaet = match (true) {
                $r->spec_is_vegan === true => ['vegan'],               // Diät-Fix 98: nur ein echtes true, NULL = unbekannt
                $r->spec_is_vegetarian === true => ['vegetarisch'],
                default => array_values(array_filter([
                    $r->spec_contains_pork === true ? 'schwein' : null,
                    $r->spec_contains_beef === true ? 'rind' : null,
                    collect($k['allergene'])->contains(fn ($a) => $a['slug'] === 'fish' && $a['status'] === 'enthalten') ? 'fisch' : null,
                ])),
            };

            return [
                'recipe_id' => (int) $r->id, 'titel' => $teile[0] ?? (string) $r->name,
                'wording' => count($teile) > 1 ? implode(' · ', array_slice($teile, 1)) : null,
                'zu' => $zu, 'linie' => $linie, 'diaet' => $diaet,
                'allergene' => $allergene, 'zusatzstoffe' => $zusatz, 'unvollstaendig' => $unvollstaendig,
            ];
        };

        foreach ($linienIds as $lid) {
            foreach ($raster[$lid][$tagYmd] ?? [] as $e) {
                foreach ($this->ernaehrung->eintragGerichte($e) as $g) {
                    if (isset($gesehen[(int) $g->id])) {
                        continue;
                    }
                    $gesehen[(int) $g->id] = true;
                    $gk = $karte($g, null, $linienNamen[$lid] ?? null);
                    $karten[] = $gk;
                    if (! $mitUnterrezepten) {
                        continue;
                    }
                    $subs = $g->ingredients()->whereNotNull('referenced_recipe_id')->with('referencedRecipe')->orderBy('position')->get()
                        ->pluck('referencedRecipe')->filter();
                    foreach ($subs as $sub) {
                        if (isset($gesehen[(int) $sub->id])) {
                            continue;
                        }
                        $gesehen[(int) $sub->id] = true;
                        $karten[] = $karte($sub, $gk['titel'], $linienNamen[$lid] ?? null);
                    }
                }
            }
        }

        return [
            'format' => 'buffet', 'plan' => $plan, 'mahlzeitLabel' => SpeiseplanBasis::MAHLZEITEN[$mahlzeit],
            'tagLabel' => $tage[$tagYmd] ?? $tagYmd, 'karten' => $karten, 'mitUnterrezepten' => $mitUnterrezepten,
            'optik' => $this->druckOptik($team, $plan), 'erzeugt' => Carbon::now()->format('d.m.Y'),
        ];
    }

    /**
     * CSV-Zeilen der Woche (eine Mahlzeit): je Eintrag Datum, Tag, Mahlzeit, Linie, Kassen-Nr.,
     * Gericht, Kennzeichnung, Essen, VK netto, EK, Wareneinsatz. Für Controlling und Kasse.
     *
     * @return list<list<string|int|float|null>>
     */
    public function csvZeilen(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, SpeiseplanBasis::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $zk = $this->ansicht->zellenKennzahlen($team, $plan, $mahlzeit, $montag, $outlet);
        $linien = $plan->lines->keyBy(fn ($l) => (int) $l->id);
        $tage = array_map(fn (Carbon $t) => $t->format('Y-m-d'), $this->basis->wochenTage($plan, $montag));
        $zahl = fn ($v, int $d = 2) => $v === null ? '' : number_format((float) $v, $d, ',', '');

        $zeilen = [['Datum', 'Tag', 'Mahlzeit', 'Linie', 'Kassen-Nr.', 'Gericht', 'Kennzeichnung', 'Essen', 'VK netto', 'EK', 'Wareneinsatz %']];
        foreach ($this->ansicht->wochenRaster($plan, $mahlzeit, $montag) as $lineId => $proTag) {
            foreach ($proTag as $ymd => $liste) {
                if (! in_array($ymd, $tage, true)) {
                    continue;
                }
                foreach ($liste as $e) {
                    $k = $zk['eintraege'][$e->id] ?? [];
                    $l = $linien->get((int) $lineId);
                    $zeilen[] = [
                        Carbon::parse($ymd)->format('d.m.Y'), SpeiseplanBasis::WOCHENTAGE[Carbon::parse($ymd)->isoWeekday()],
                        SpeiseplanBasis::MAHLZEITEN[$mahlzeit], $l?->name ?? 'Ohne Linie', $l?->plu ?? '',
                        $this->ernaehrung->eintragName($e), implode(' ', $k['codes'] ?? []), (int) ($k['pax'] ?? 0),
                        $zahl($k['vk'] ?? null), $zahl($k['ek'] ?? null), $zahl($k['wes'] ?? null, 1),
                    ];
                }
            }
        }
        // Sortiert nach Datum, dann Linien-Reihenfolge des Plans („Ohne Linie“ zuletzt) — so liest
        // sich die Datei wie der Plan (vorher alphabetisch nach Linienname).
        $kopf = array_shift($zeilen);
        $rang = array_flip($plan->lines->pluck('name')->all());
        $schluessel = fn ($z) => [Carbon::createFromFormat('d.m.Y', $z[0])->format('Ymd'), $rang[$z[3]] ?? PHP_INT_MAX];
        usort($zeilen, fn ($a, $b) => $schluessel($a) <=> $schluessel($b));

        return [$kopf, ...$zeilen];
    }

    public function setBranding(Team $team, int $planId, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->basis->guard($plan, $team);

        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $this->normHexOderThrow($in['brand_color'], 'Markenfarbe') ?? '#6d28d9';
        }
        if (array_key_exists('band_color', $in)) {
            $daten['band_color'] = $this->normHexOderThrow($in['band_color'], 'Bandfarbe', true);
        }
        if (array_key_exists('footer_text', $in)) {
            $t = trim((string) $in['footer_text']);
            $daten['footer_text'] = $t !== '' ? $t : null;
        }
        if ($daten !== []) {
            $plan->update($daten);
        }

        return $plan->refresh();
    }

    public function storeLogo(Team $team, int $planId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $planId, $file, 'logo_path');
    }

    public function storeCover(Team $team, int $planId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $planId, $file, 'cover_image_path');
    }

    public function clearLogo(Team $team, int $planId): FoodAlchemistSpeiseplan
    {
        return $this->loescheBrandingBild($team, $planId, 'logo_path');
    }

    public function clearCover(Team $team, int $planId): FoodAlchemistSpeiseplan
    {
        return $this->loescheBrandingBild($team, $planId, 'cover_image_path');
    }

    private function speichereBrandingBild(Team $team, int $planId, UploadedFile $file, string $spalte): string
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->basis->guard($plan, $team);
        $contextSpalte = $spalte === 'logo_path' ? 'logo_context_file_id' : 'cover_context_file_id';
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($plan->{$contextSpalte}, (string) $plan->{$spalte}, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($plan, $spalte)]);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.speiseplan', $planId, "foodalchemist/branding/speiseplan/{$planId}",
        );
        $plan->update([$spalte => $media['path'], $contextSpalte => $media['context_file_id']]);

        return $media['path'];
    }

    private function loescheBrandingBild(Team $team, int $planId, string $spalte): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->basis->guard($plan, $team);
        $contextSpalte = $spalte === 'logo_path' ? 'logo_context_file_id' : 'cover_context_file_id';
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($plan->{$contextSpalte}, (string) $plan->{$spalte}, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($plan, $spalte)]);
        $plan->update([$spalte => null, $contextSpalte => null]);

        return $plan->refresh();
    }

    private function normHexOderThrow($wert, string $feld, bool $erlaubeLeer = false): ?string
    {
        $wert = trim((string) $wert);
        if ($wert === '') {
            if ($erlaubeLeer) {
                return null;
            }
            throw new \RuntimeException("{$feld} fehlt.");
        }
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $wert)) {
            throw new \RuntimeException("{$feld}: ungültiger Farbwert „{$wert}“ (erwartet #RRGGBB).");
        }

        return $wert;
    }
}
