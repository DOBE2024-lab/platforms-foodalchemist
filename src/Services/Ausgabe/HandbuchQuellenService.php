<?php

namespace Platform\FoodAlchemist\Services\Ausgabe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 86 — Quellen-Adapter des Handbuchs: liest aus dem Ausgabe-Dokument NUR die Struktur
 * (Kapitel/Rubriken/Tage), je Position den Namen, die Gericht-ID und die Allergen-Codes.
 * Preise werden nie angefasst (intern=false, mitKaskade=false).
 *
 * Rückgabe: `title`, `subtitle`, `sections[]` (title, text, items[] mit key `r<id>`, recipe_id, label, indent, codes),
 * `legende` ({allergene[], zusatzstoffe[]} mit code+label).
 */
class HandbuchQuellenService
{
    /** @var array<string,true> */
    private array $usedAlg = [];

    /** @var array<string,true> */
    private array $usedZus = [];

    /** @var array<int,FoodAlchemistRecipe> */
    private array $rezepte = [];

    private array $vorkommen = [];
    private array $kennzeichnungsKatalog = [];

    private const WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    public function __construct(private \Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService $agg)
    {
    }

    /** @return array{title:string, subtitle:?string, sections:list<array>, legende:mixed} */
    public function lies(Team $team, string $type, Model $entity): array
    {
        $this->usedAlg = [];
        $this->usedZus = [];
        $this->rezepte = [];
        $this->vorkommen = [];
        $this->kennzeichnungsKatalog = $this->agg->kennzeichnungKatalog($team);

        return match ($type) {
            HandbuchService::TYPE_FOODBOOK => $this->foodbook($team, $entity),
            HandbuchService::TYPE_SPEISEKARTE => $this->speisekarte($team, $entity),
            HandbuchService::TYPE_SPEISEPLAN => $this->speiseplan($team, $entity),
            HandbuchService::TYPE_ANGEBOT => $this->angebot($team, $entity),
            default => throw new \InvalidArgumentException("Handbuch-Typ '{$type}' wird nicht unterstützt."),
        };
    }

    private function foodbook(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $dok = app(\Platform\FoodAlchemist\Services\Foodbook\FoodbookService::class)->dokumentDaten($team, $fb, false, [], false, null, true);
        $sections = [];
        foreach ($dok['kapitel'] ?? [] as $k) {
            $items = [];
            $konzepte = [];
            // Format-Kapitel (live, Entscheid B 31.08.): Gerichte stehen in den Editionen, nicht in Blöcken — wie beim Angebot lesen (18.11.2026)
            $istFormat = (bool) ($k['ist_format'] ?? false);
            foreach (($istFormat ? ($k['editionen'] ?? []) : ($k['bloecke'] ?? [])) as $b) {
                $gruppe = $this->konzeptGruppe($b['gerichte'] ?? [], (string) ($b['name'] ?? $b['label'] ?? 'Konzept'));
                if ($gruppe['items'] !== []) {
                    array_push($items, ...$gruppe['items']);
                    $konzepte[] = $gruppe;
                }
            }
            $sections[] = ['title' => (string) ($k['title'] ?? ''), 'text' => $k['text'] ?? null, 'items' => $items, 'groups' => $konzepte];
        }

        return [
            'title' => (string) ($fb->label ?: $fb->code ?: 'Foodbook'),
            'subtitle' => $fb->description ? (string) $fb->description : null,
            'personen' => $fb->personen ?? null,
            'sections' => $sections,
            'legende' => $dok['legende'] ?? null,
        ];
    }

    private function speisekarte(Team $team, FoodAlchemistSpeisekarte $karte): array
    {
        $dok = app(SpeisekarteService::class)->dokumentDaten($team, $karte, false, [], false);
        $rubriken = $karte->sections()->with('items')->get()->keyBy('id');
        $sections = [];
        foreach ($dok['rubriken'] as $r) {
            // dokumentDaten läuft über DIESELBE Liste (sichtbar, nach Position) → Index-gleich.
            $positionen = $rubriken->get($r['id'])?->items->where('visible', true)->sortBy('position')->values() ?? collect();
            $items = [];
            foreach ($r['positionen'] as $i => $p) {
                $pos = $positionen->get($i);
                if (($p['typ'] ?? '') === 'gericht_ref' && $pos?->recipe_id !== null) {
                    $items[] = $this->item((int) $pos->recipe_id, (string) ($p['name'] ?? ''), 0, array_values((array) ($p['codes'] ?? [])));
                } elseif (($p['typ'] ?? '') === 'menue_ref') {
                    foreach ($p['gaenge'] ?? [] as $g) {
                        if (! empty($g['recipe_id'])) {
                            $items[] = $this->item((int) $g['recipe_id'], (string) ($g['text'] ?? ''), 1, array_values((array) ($g['codes'] ?? [])));
                        }
                    }
                }
            }
            $sections[] = ['title' => (string) ($r['title'] ?? ''), 'text' => $r['claim'] ?? null, 'items' => $items];
        }

        return [
            'title' => (string) ($karte->name ?: $karte->code ?: 'Speisekarte'),
            'subtitle' => $karte->description ? (string) $karte->description : null,
            'sections' => $sections,
            'legende' => $dok['legende'] ?? null,
        ];
    }

    /**
     * Speiseplan: die Woche mit Belegung (laufende Woche, sonst die erste belegte), alle Mahlzeiten,
     * ein Abschnitt je Tag. Konzept-/Paket-Einträge werden in ihre Gerichte aufgelöst.
     */
    private function speiseplan(Team $team, FoodAlchemistSpeiseplan $plan): array
    {
        $svc = app(\Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService::class);
        $plan->loadMissing('entries');
        $mit = $plan->entries->filter(fn ($e) => $e->entry_date !== null);
        $heute = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $mo = $mit->contains(fn ($e) => $e->entry_date->between($heute, $heute->copy()->addDays(6)))
            ? $heute
            : ($mit->isNotEmpty() ? Carbon::parse($mit->min('entry_date'))->startOfWeek(Carbon::MONDAY) : $heute);
        $ende = $mo->copy()->addDays(6)->endOfDay();

        $sections = [];
        foreach ($mit->filter(fn ($e) => $e->entry_date->between($mo, $ende))->groupBy(fn ($e) => $e->entry_date->format('Y-m-d'))->sortKeys() as $ymd => $eintraege) {
            $items = [];
            foreach ($eintraege as $e) {
                $gerichte = $svc->eintragGerichte($e);
                foreach ($gerichte as $g) {
                    $items[] = $this->item((int) $g->id, $gerichte->count() === 1 ? $svc->eintragName($e) : (string) $g->name, 0, $this->codesFuer((int) $g->id));
                }
            }
            if ($items !== []) {
                $tag = Carbon::parse($ymd);
                $sections[] = ['title' => self::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.'), 'text' => null, 'items' => $items];
            }
        }

        return [
            'title' => (string) ($plan->name ?: 'Speiseplan'),
            'subtitle' => 'KW ' . $mo->isoWeek() . ' · ' . $mo->format('d.m.') . ' bis ' . $mo->copy()->addDays(6)->format('d.m.Y'),
            'sections' => $sections,
            'legende' => $this->legende(),
        ];
    }

    private function angebot(Team $team, FoodAlchemistAngebot $angebot): array
    {
        $komp = app(OfferCompositionService::class)->komposition($team, $angebot, null, false, true);
        $sections = [];
        foreach ($komp['kapitel'] as $k) {
            $gruppen = $k['ist_format'] ? ($k['editionen'] ?? []) : ($k['bloecke'] ?? []);
            $items = [];
            $konzepte = [];
            foreach ($gruppen as $grp) {
                $gruppe = $this->konzeptGruppe($grp['gerichte'] ?? [], (string) ($grp['name'] ?? $grp['label'] ?? 'Konzept'));
                if ($gruppe['items'] !== []) {
                    array_push($items, ...$gruppe['items']);
                    $konzepte[] = $gruppe;
                }
            }
            $sections[] = ['title' => (string) ($k['title'] ?? ''), 'text' => $k['text'] ?? null, 'items' => $items, 'groups' => $konzepte];
        }

        return [
            'title' => (string) ($angebot->name ?: 'Angebot'),
            'subtitle' => $angebot->occasion ? (string) $angebot->occasion : null,
            'personen' => $angebot->personen ?? null,
            'sections' => $sections,
            'legende' => $this->legende(),
        ];
    }

    /** @param list<string> $codes */
    private function item(int $recipeId, string $label, int $indent, array $codes): array
    {
        $n = $this->vorkommen[$recipeId] = ($this->vorkommen[$recipeId] ?? 0) + 1;
        return ['key' => 'r' . $recipeId . ($n > 1 ? '-p'.$n : ''), 'recipe_id' => $recipeId, 'label' => $label, 'indent' => $indent, 'codes' => $codes];
    }

    /** Paketzeilen bleiben Knoten; flache Gerichtliste zusätzlich für den Kartenaufbau. */
    private function konzeptGruppe(array $zeilen, string $titel): array
    {
        $items = []; $i = 0;
        $walk = function (int $elternTiefe) use (&$walk, &$items, &$i, $zeilen): array {
            $knoten = [];
            while ($i < count($zeilen)) {
                $g = $zeilen[$i]; $tiefe = (int) ($g['einrueckung'] ?? 0);
                if ($tiefe <= $elternTiefe) { break; }
                $i++;
                if (($g['type'] ?? '') === 'paket') {
                    $kinder = $walk($tiefe);
                    if ($kinder !== []) { $knoten[] = ['kind' => 'paket', 'label' => (string) $g['text'], 'children' => $kinder]; }
                } elseif (!empty($g['recipe_id'])) {
                    $it = $this->item((int) $g['recipe_id'], (string) ($g['text'] ?? ''), $tiefe, $this->codesFuer((int) $g['recipe_id'])) + ($g['menge'] ?? []);
                    $items[] = $it;
                    $knoten[] = ['kind' => 'gericht', 'key' => $it['key']];
                } elseif (($g['type'] ?? '') === 'header') {
                    $knoten[] = ['kind' => 'header', 'label' => (string) $g['text']];
                }
            }
            return $knoten;
        };
        $nodes = $walk(-1);
        return ['title' => $titel, 'items' => $items, 'nodes' => $nodes];
    }

    /** @return list<string> */
    private function codesFuer(int $recipeId): array
    {
        $this->rezepte[$recipeId] ??= FoodAlchemistRecipe::find($recipeId);
        $dish = $this->rezepte[$recipeId];

        return $dish === null ? [] : $this->agg->gerichtCodes($dish, $this->usedAlg, $this->usedZus, $this->kennzeichnungsKatalog);
    }

    /** @return array{allergene:list<array{code:string,label:string}>, zusatzstoffe:list<array{code:string,label:string}>} */
    private function legende(): array
    {
        $katalog = $this->kennzeichnungsKatalog;
        $alg = [];
        foreach ($katalog['allergene'] as $slug => $e) {
            if (isset($this->usedAlg[$slug])) {
                $alg[] = ['code' => $e['code'], 'label' => $e['label']];
            }
        }
        $zus = [];
        foreach ($katalog['zusatzstoffe'] as $slug => $e) {
            if (isset($this->usedZus[$slug])) {
                $zus[] = ['code' => $e['code'], 'label' => $e['label']];
            }
        }

        return ['allergene' => $alg, 'zusatzstoffe' => $zus];
    }
}
