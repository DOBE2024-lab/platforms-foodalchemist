<?php

namespace Platform\FoodAlchemist\Services\Ausgabe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistHandbuch;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 86 — Handbuch (Standards zum Nachschlagen). Friert zu einem Ausgabe-Dokument einen
 * kundentauglichen Snapshot ein: je Gericht Zubereitung, Anrichten, Regeneration, Gefäß (mit Zubehör),
 * Portion/Geschirr, Allergene. ALLOWLIST-Aufbau — Preise, EK, Lieferant, KI-Lineage und
 * interne Notizen gelangen nie in den Snapshot. Eigener Token/Ablauf/Widerruf, unabhängig vom
 * Kundenlink der Präsentation (Spec 43).
 *
 * Aufbau des Snapshots: `sections` spiegeln die Struktur des Quelldokuments (Kapitel/Rubriken/Tage),
 * jedes Item verweist per `key` auf eine eigene Positionskarte in `gerichte` (Mengen und Darreichung bleiben getrennt).
 */
class HandbuchService
{
    public const TYPE_FOODBOOK = 'foodbook';

    public const TYPE_SPEISEKARTE = 'speisekarte';

    public const TYPE_SPEISEPLAN = 'speiseplan';

    public const TYPE_ANGEBOT = 'angebot';

    public const SCHEMA_VERSION = 1;

    /** Quelltypen des Handbuchs (gleiche vier Ausgabe-Dokumente wie die Präsentation, Spec 43). */
    public const TYPEN = [self::TYPE_FOODBOOK, self::TYPE_SPEISEKARTE, self::TYPE_SPEISEPLAN, self::TYPE_ANGEBOT];

    public function __construct(
        private \Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService $media,
        private \Platform\FoodAlchemist\Services\Produktion\RegenerationCascadeService $regeneration,
        private HandbuchQuellenService $quellen,
        private HandbuchZutatenService $zutaten,
    ) {
    }

    // ── Publish / Withdraw ─────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $settings  expires_at (Pflicht)
     * @return array{token:string, url:string, published_at:string, expires_at:string, luecken:list<array{gericht:string, fehlt:list<string>}>}
     */
    public function publish(Team $team, string $type, int $id, array $settings): array
    {
        $expiresAt = $this->parseExpiry($settings['expires_at'] ?? null);
        if ($expiresAt === null) {
            throw new \RuntimeException('Ein gültig-bis-Datum ist Pflicht (kein Link ohne Ablauf).');
        }
        $entity = $this->resolveEntity($team, $type, $id, forWrite: true);
        $hb = FoodAlchemistHandbuch::firstOrNew(['quelle_type' => $type, 'quelle_id' => $entity->getKey()]);
        $hb->team_id = $team->id;
        // Freie Kapitel (Hygiene, Ausgabe-Regeln …): ohne Angabe bleiben die zuletzt veröffentlichten stehen.
        $kapitel = array_key_exists('kapitel', $settings)
            ? $this->bereinigeKapitel((array) $settings['kapitel'])
            : (array) ($hb->settings_json['kapitel'] ?? []);
        $snapshot = $this->buildSnapshot($team, $entity, $type, $kapitel);
        $token = $hb->token ?: $this->neuerToken();

        $hb->forceFill([
            'enabled' => true,
            'token' => $token,
            'snapshot_json' => $snapshot,
            'settings_json' => ['expires_at' => $expiresAt->toIso8601String(), 'kapitel' => $kapitel],
            'published_at' => now(),
            'published_by' => auth()->id(),
            'expires_at' => $expiresAt,
        ])->save();

        return [
            'token' => $token,
            'url' => $this->publicUrl($token),
            'published_at' => $hb->published_at->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'luecken' => $snapshot['luecken'],
        ];
    }

    /** Nimmt das Handbuch vom Netz (Snapshot + Token bleiben — Wieder-Freigabe möglich). */
    public function withdraw(Team $team, string $type, int $id): void
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: true);
        FoodAlchemistHandbuch::where('quelle_type', $type)->where('quelle_id', $entity->getKey())
            ->update(['enabled' => false]);
    }

    /**
     * Link-Status für den Editor. Bewusst OHNE Snapshot-Aufbau: läuft bei jedem Render des Editors,
     * die Komposition (Preise/Zeilen) darf dafür nicht ein zweites Mal gerechnet werden.
     *
     * @return array{veroeffentlicht:bool, live:bool, url:?string, expires_at:?string, published_at:?string}
     */
    public function status(Team $team, string $type, int $id): array
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: false);
        $hb = FoodAlchemistHandbuch::where('quelle_type', $type)->where('quelle_id', $entity->getKey())->first();

        return [
            'veroeffentlicht' => $hb !== null,
            'live' => $hb?->istLive() ?? false,
            'url' => $hb !== null ? $this->publicUrl((string) $hb->token) : null,
            'expires_at' => $hb?->expires_at?->toIso8601String(),
            'published_at' => $hb?->published_at?->toIso8601String(),
            'kapitel' => (array) ($hb?->settings_json['kapitel'] ?? []),
        ];
    }

    /**
     * Freie Kapitel auf Titel + Klartext reduzieren (kein HTML, begrenzt) — das Handbuch rendert sie escaped.
     *
     * @param  list<array<string,mixed>>  $roh
     * @return list<array{title:string, text:string}>
     */
    public function bereinigeKapitel(array $roh): array
    {
        $out = [];
        foreach (array_slice($roh, 0, 20) as $k) {
            $titel = trim(mb_substr(strip_tags((string) ($k['title'] ?? '')), 0, 120));
            $text = trim(mb_substr(strip_tags((string) ($k['text'] ?? '')), 0, 4000));
            if ($titel !== '' || $text !== '') {
                $out[] = ['title' => $titel !== '' ? $titel : 'Allgemein', 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * Lücken des AKTUELLEN Standes (live gerechnet, nicht persistiert) — nur auf Knopfdruck, weil der
     * Aufbau die ganze Komposition rechnet.
     *
     * @return list<array{gericht:string, fehlt:list<string>}>
     */
    public function luecken(Team $team, string $type, int $id): array
    {
        return $this->buildSnapshot($team, $this->resolveEntity($team, $type, $id, forWrite: false), $type)['luecken'];
    }

    // ── Public-Auflösung (KEIN Team-Scope) ─────────────────────────────────

    /**
     * Öffentliche Auflösung per Token. Rendert NUR aus dem eingefrorenen Snapshot; null → 404.
     *
     * @return array<string,mixed>|null
     */
    public function resolveByToken(string $token): ?array
    {
        $hb = FoodAlchemistHandbuch::query()->where('token', $token)->first();
        if ($hb === null || ! $hb->istLive() || ! is_array($hb->snapshot_json) || $hb->snapshot_json === []) {
            return null;
        }

        return $this->hydrateImages($hb->snapshot_json);
    }

    // ── Snapshot ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function buildSnapshot(Team $team, Model $entity, string $type, array $kapitel = []): array
    {
        $quelle = $this->quellen->lies($team, $type, $entity);
        $this->zutaten->zuruecksetzen();

        // Gerichte einmalig laden: alle vorkommenden Rezept-IDs → Karten.
        $refs = [];
        foreach ($quelle['sections'] as $sec) {
            foreach ($sec['items'] as $it) {
                $refs[$it['key']] = $it['recipe_id'];
            }
        }
        $recipes = $refs === [] ? collect() : FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', array_values(array_unique($refs)))
            ->with([
                'steps.photos', 'platingSteps.photos',
                'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
                'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name',
                'standardPresentation.servingForm', 'standardPresentation.vessel:id,name', 'containers.container',
            ])->get()->keyBy('id');

        $gerichte = [];
        $luecken = [];
        $sections = [];
        foreach ($quelle['sections'] as $sec) {
            $items = [];
            foreach ($sec['items'] as $it) {
                $recipe = $recipes->get($it['recipe_id']);
                if ($recipe === null) {
                    continue;
                }
                if (! isset($gerichte[$it['key']])) {
                    $dar = !empty($it['presentation_id']) ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::visibleToTeam($team)->where('recipe_id', $recipe->id)->find($it['presentation_id']) : null;
                    $karte = $this->gerichtKarte($team, $recipe, $it['label'], true, $dar);
                    $karte['codes'] = $it['codes'];
                    $gerichte[$it['key']] = $karte;
                    $fehlt = $this->fehlendeAbschnitte($karte);
                    if ($fehlt !== []) {
                        $luecken[] = ['gericht' => $karte['name'], 'fehlt' => $fehlt];
                    }
                }
                $items[] = ['key' => $it['key'], 'label' => $it['label'], 'indent' => $it['indent'] ?? 0]
                    + array_intersect_key($it, array_flip(['menge_pro_gast', 'einheit', 'portionen_pro_gast']));
            }
            if ($items !== []) {
                $gruppen = [];
                foreach ($sec['groups'] ?? [] as $gruppe) {
                    $index = array_column($items, null, 'key');
                    $gueltig = array_values(array_filter(array_map(fn ($it) => $index[$it['key']] ?? null, $gruppe['items'])));
                    if ($gueltig !== []) {
                        $gruppen[] = ['title' => $gruppe['title'], 'items' => $gueltig, 'nodes' => $this->navigationsKnoten($gruppe['nodes'] ?? [], $index)];
                    }
                }
                $sections[] = ['title' => $sec['title'], 'text' => $sec['text'] ?? null, 'items' => $items, 'groups' => $gruppen];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'type' => 'handbuch',
            'quelle' => $type,
            'title' => $quelle['title'],
            'subtitle' => $quelle['subtitle'],
            'freigabe' => ['at' => now()->toIso8601String(), 'by' => auth()->id(), 'datum' => now()->format('d.m.Y')],
            'sections' => $sections,
            'gerichte' => $gerichte,
            'basisrezepte' => $this->zutaten->basisrezepte(),
            'freie_kapitel' => $kapitel,
            'personen' => isset($quelle['personen']) && (int) $quelle['personen'] > 0 ? (int) $quelle['personen'] : null,
            'legende' => $quelle['legende'] ?? null,
            'luecken' => $luecken,
        ];
    }

    /**
     * Allowlist-Karte eines Gerichts. Keine Mengen, Preise, Lieferanten, KI-Felder.
     *
     * @return array<string,mixed>
     */
    public function gerichtKarte(Team $team, FoodAlchemistRecipe $recipe, string $label, bool $mitZutaten = true, ?\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung $dar = null): array
    {
        $dar ??= $recipe->standardPresentation;
        $regen = $this->regeneration->fuerRezept($recipe);
        $regenZeilen = [];
        foreach ([...$regen['gesamt'], ...$regen['komponenten']] as $z) {
            if (($z['herkunft'] ?? null) === \Platform\FoodAlchemist\Services\Produktion\RegenerationCascadeService::HERKUNFT_FEHLT) {
                continue;
            }
            $kalt = (bool) ($z['ist_kalt'] ?? false);
            if (! $kalt && $z['device'] === null && $z['temp_c'] === null && $z['duration_min'] === null) {
                continue;
            }
            $regenZeilen[] = [
                'komponente' => (string) $z['label'],
                'kalt' => $kalt,
                'geraet' => $z['device'],
                'temp_c' => $z['temp_c'],
                'dauer_min' => $z['duration_min'],
                'kern_c' => $z['core_temp_c'],
                'hinweis' => $z['note'],
            ];
        }

        return [
            'name' => $label !== '' ? $label : (string) $recipe->name,
            'foto' => self::rezeptFotoSnapshot($recipe),
            'portion' => [
                'gramm' => $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null,
                'form' => $dar?->bezeichnung(),
                'geschirr' => $dar?->vessel?->name,
                'vehikel' => $dar?->serving_vehicle_vocab_id !== null
                    ? \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_serving_vehicles')->where('id', $dar->serving_vehicle_vocab_id)->value('name')
                    : null,
            ],
            'bilder' => self::rezeptBilderSnapshot($recipe),
            'behaelter' => $this->behaelter($recipe),
            // #1003 Handy-Detail: zeigt die Zutaten selbst (aus dem Rezept) und spart den Report-Lauf
            'zutaten' => $mitZutaten ? $this->zutaten->fuerGericht($team, $recipe, $dar) : null,
            'zubereitung' => self::schritteSnapshot($recipe->steps),
            'anrichten' => self::schritteSnapshot($recipe->platingSteps),
            'regeneration' => $regenZeilen,
            // 20.11.2026 (Dominique): Reiter „Gefäß“ statt „Bestandteile“ — Gefäß + Zubehör je Portion dieser Darreichung, ohne Preise
            // (gleiche Quelle wie Kosten und Logistik). Leere Liste = kein Gefäß hinterlegt.
            'gefaess' => $dar !== null ? app(\Platform\FoodAlchemist\Services\DarreichungService::class)->gefaessZeilenJePortion($dar, $team) : [],
            'codes' => [],
        ];
    }

    /** Kennzeichnung genau dieses Rezepts; niemals die Angaben des übergeordneten Gerichts übernehmen. */
    public static function kennzeichnungSnapshot(FoodAlchemistRecipe $recipe): array
    {
        $agg = app(\Platform\FoodAlchemist\Services\ConcepterAggregateService::class);
        $daten = $agg->kennzeichnungFromGerichte(collect([$recipe]));
        $katalog = $agg->kennzeichnungKatalog()['allergene'];
        $out = ['enthalten' => [], 'spuren' => [], 'offen' => false];
        foreach ($daten['allergene'] as $a) {
            if (in_array($a['status'], ['enthalten', 'spuren'], true)) {
                $out[$a['status']][] = ['code' => $katalog[$a['slug']]['code'], 'label' => $a['label']];
            } elseif ($a['status'] === 'unbekannt') { $out['offen'] = true; }
        }
        return $out;
    }

    /** Nur bekannte Karten und öffentliche Mengenangaben in den Navigationsbaum übernehmen. */
    private function navigationsKnoten(array $nodes, array $index): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (($node['kind'] ?? '') === 'gericht' && isset($index[$node['key']])) {
                $out[] = ['kind' => 'gericht'] + $index[$node['key']];
            } elseif (($node['kind'] ?? '') === 'paket') {
                $children = $this->navigationsKnoten($node['children'], $index);
                if (array_filter($children, fn ($child) => $child['kind'] !== 'header') !== []) { $out[] = ['kind' => 'paket', 'label' => $node['label'], 'children' => $children]; }
            } elseif (($node['kind'] ?? '') === 'header') {
                $out[] = ['kind' => 'header', 'label' => $node['label']];
            }
        }
        return $out;
    }

    /** @return list<array{zweck:string, name:string}> Behälter zum Regenerieren und zur Ausgabe (Eigene Zeilen des Gerichts). */
    private function behaelter(FoodAlchemistRecipe $recipe): array
    {
        $labels = ['regenerieren' => 'Regenerieren', 'ausgabe' => 'Ausgabe'];
        $out = [];
        foreach ($recipe->containers as $c) {
            if (isset($labels[$c->zweck]) && $c->container?->name) {
                $out[] = ['zweck' => $labels[$c->zweck], 'name' => (string) $c->container->name];
            }
        }

        return $out;
    }

    /** @return list<array{nr:int, text:string, fotos:list<array{context_file_id:?int, path:?string, caption:?string}>}> */
    public static function schritteSnapshot(iterable $steps): array
    {
        $out = [];
        $nr = 0;
        foreach ($steps as $s) {
            $nr++;
            $fotos = [];
            foreach ($s->photos as $p) {
                $fotos[] = ['context_file_id' => $p->context_file_id ?? null, 'path' => $p->pfad, 'caption' => $p->caption];
            }
            $out[] = ['nr' => $nr, 'text' => (string) $s->text, 'fotos' => $fotos];
        }

        return $out;
    }

    /** Allgemeine Bilder einschließlich des Ergebnisbilds; Schrittfotos bleiben beim jeweiligen Schritt. */
    private static function allgemeineBilder(FoodAlchemistRecipe $recipe): \Illuminate\Support\Collection
    {
        $recipe->loadMissing('stepPhotos.steps');
        return $recipe->stepPhotos->filter(fn ($p) => $p->is_result || $p->steps->isEmpty())->sortByDesc('is_result')->values();
    }

    public static function rezeptFotoSnapshot(FoodAlchemistRecipe $recipe): ?array
    {
        if ($recipe->image_context_file_id || $recipe->image_path) {
            return ['context_file_id' => $recipe->image_context_file_id, 'path' => $recipe->image_path];
        }
        $p = self::allgemeineBilder($recipe)->first();
        return $p ? ['context_file_id' => $p->context_file_id, 'path' => $p->pfad, 'caption' => $p->caption] : null;
    }

    public static function rezeptBilderSnapshot(FoodAlchemistRecipe $recipe): array
    {
        $hero = self::rezeptFotoSnapshot($recipe);
        return self::allgemeineBilder($recipe)
            ->reject(fn ($p) => ($p->context_file_id && $p->context_file_id == ($hero['context_file_id'] ?? null))
                || ($p->pfad && $p->pfad === ($hero['path'] ?? null)))
            ->map(fn ($p) => ['context_file_id' => $p->context_file_id, 'path' => $p->pfad, 'caption' => $p->caption])
            ->values()->all();
    }

    /** @return list<string> */
    private function fehlendeAbschnitte(array $karte): array
    {
        $fehlt = [];
        foreach (['zutaten' => 'Zutaten', 'zubereitung' => 'Zubereitung', 'anrichten' => 'Anrichten', 'regeneration' => 'Regeneration'] as $k => $l) {
            if (($k === 'zutaten' ? $karte[$k]['zeilen'] : $karte[$k]) === []) {
                $fehlt[] = $l;
            }
        }

        return $fehlt;
    }

    // ── Bilder ─────────────────────────────────────────────────────────────

    /** Identifier → frisch signierte URL (Snapshot speichert nur Identifier). */
    public function hydrateImages(array $snapshot): array
    {
        foreach ($snapshot['gerichte'] ?? [] as $key => $g) {
            $snapshot['gerichte'][$key] = $this->hydrateKarte($g);
        }

        foreach ($snapshot['basisrezepte'] ?? [] as $key => $b) {
            $snapshot['basisrezepte'][$key] = $this->hydrateKarte($b);
        }

        return $snapshot;
    }

    /** Bilder EINER Gericht-Karte (Foto, Schritt-Fotos) → frisch signierte URLs. */
    public function hydrateKarte(array $g): array
    {
        $url = fn (?array $img) => (is_array($img) && (($img['context_file_id'] ?? null) || ($img['path'] ?? null)))
            ? $this->media->url($img['context_file_id'] ?? null, $img['path'] ?? null) : null;
        $g['foto_url'] = $url($g['foto'] ?? null);
        foreach ($g['bilder'] ?? [] as $i => $bild) {
            $g['bilder'][$i]['url'] = $url($bild);
        }
        foreach (['zubereitung', 'anrichten'] as $abschnitt) {
            foreach ($g[$abschnitt] ?? [] as $i => $s) {
                foreach ($s['fotos'] ?? [] as $j => $f) {
                    $g[$abschnitt][$i]['fotos'][$j]['url'] = $url($f);
                }
            }
        }

        return $g;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function resolveEntity(Team $team, string $type, int $id, bool $forWrite): Model
    {
        $class = match ($type) {
            self::TYPE_FOODBOOK => FoodAlchemistFoodbook::class,
            self::TYPE_SPEISEKARTE => FoodAlchemistSpeisekarte::class,
            self::TYPE_SPEISEPLAN => FoodAlchemistSpeiseplan::class,
            self::TYPE_ANGEBOT => FoodAlchemistAngebot::class,
            default => throw new \InvalidArgumentException("Unbekannter Handbuch-Typ '{$type}'."),
        };
        $entity = $class::visibleToTeam($team)->findOrFail($id);
        if ($forWrite && ! $entity->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Ausgabe gehört einem anderen Team und kann nicht veröffentlicht werden.');
        }

        return $entity;
    }

    private function neuerToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (FoodAlchemistHandbuch::withTrashed()->where('token', $token)->exists());

        return $token;
    }

    private function publicUrl(string $token): string
    {
        return route('foodalchemist.presentation.handbuch', ['token' => $token]);
    }

    private function parseExpiry(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
