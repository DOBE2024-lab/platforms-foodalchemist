<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Kantenart;

/**
 * Pairing-Kandidaten fürs Netz und den Composer-Picker: Aroma-Partner außerhalb des
 * Ankersets, gezählt nach bedienten Zutaten (Aromaquellen) bzw. Ankern. Aus
 * {@see PairingService} gelöst (Größenwächter, CQ main-grün 2026-11-26), Verhalten unverändert.
 */
final class NetzKandidaten
{
    public function __construct(private AnkerGraph $graph) {}

    /**
     * Pairing-Kandidaten für die Kern-Anker: alle Aroma-Partner AUSSERHALB des
     * Ankersets, aggregiert je Kandidat (Anzahl bedienter Zutaten, im Composer Anker,
     * primärer Typ = stärkste Kante).
     *
     * @return array{0: list<array>, 1: array<int,array>}  [kandidaten, candMeta je candId]
     */
    public function fuerAnker(array $innerIds, array $quellen = []): array
    {
        if ($innerIds === []) {
            return [[], []];
        }

        // Spec 60: Kandidaten sind nur echtes Food Pairing (3★); 2★ ist Rauschen.
        $kanten = $this->graph->kanten($innerIds, null, AnkerGraph::HARMONIERT, $innerIds);
        $kandIds = $kanten->pluck('zu')->unique()->values()->all();
        $meta = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $kandIds)
            ->get(['id', 'slug', 'display_de', 'grundname', 'verfahren'])->keyBy('id');
        // Rang-Zutaten wie KontrastAbleitung (Spec 60 P4): Klassiker aus dem Dossier, Grundform, Verbreitung
        $klassiker = $kandIds === [] ? [] : $this->graph->beziehungen($kandIds, Kantenart::Kombination, $innerIds)
            ->merge($this->graph->beziehungen($innerIds, Kantenart::Kombination, $kandIds))
            ->flatMap(fn ($k) => [$k->von, $k->zu])->flip()->all();
        $gruppenKlassiker = $this->graph->gruppenKlassikerZwischen($kandIds, $innerIds);
        $verbreitung = $kandIds === [] ? [] : DB::table('foodalchemist_gp_anchor_mappings')->whereNull('deleted_at')
            ->whereIn('anchor_id', $kandIds)->selectRaw('anchor_id, COUNT(*) AS n')->groupBy('anchor_id')->pluck('n', 'anchor_id')->all();

        $agg = [];
        foreach ($kanten as $k) {
            // Bucket = Stern-Stufe; die Kanten sind nur ★★★ (best match), stern2 kommt nicht mehr vor.
            $cid = $k->zu;
            $m = $meta[$cid] ?? null;
            if ($m === null) {
                continue;
            }
            $bucket = 'stern'.$k->stufe;
            $w = AnkerGraph::GEWICHT[$k->stufe];
            if (! isset($agg[$cid])) {
                $agg[$cid] = ['id' => $cid, 'slug' => $m->slug, 'display_de' => $m->display_de,
                    'partner' => [], 'ankerSet' => [], 'best' => ['typ' => $bucket, 'weight' => -1.0, 'level' => $k->stufe]];
            }
            $agg[$cid]['partner'][] = ['anker_id' => $k->von, 'typ' => $bucket, 'level' => $k->stufe, 'weight' => $w];
            $agg[$cid]['ankerSet'][$k->von] = true;
            if ($w > $agg[$cid]['best']['weight']) {
                $agg[$cid]['best'] = ['typ' => $bucket, 'weight' => $w, 'level' => $k->stufe];
            }
        }

        $kandidaten = [];
        $candMeta = [];
        foreach ($agg as $cid => $c) {
            $cover = $this->zahlAromaQuellen(array_keys($c['ankerSet']), $quellen);
            $primaerAnker = $c['partner'][0]['anker_id'];
            foreach ($c['partner'] as $p) {
                if ($p['typ'] === $c['best']['typ']) {
                    $primaerAnker = $p['anker_id'];
                    break;
                }
            }
            $m = $meta[$cid];
            $kandidaten[] = [
                'id' => $cid, 'slug' => $c['slug'], 'display_de' => $c['display_de'],
                'typ' => $c['best']['typ'], 'level' => $c['best']['level'], 'weight' => $c['best']['weight'],
                'cover' => $cover, 'partner' => $c['partner'],
                'klassiker' => isset($klassiker[$cid]), 'gruppen_klassiker' => isset($gruppenKlassiker[$cid]),
                'grundform' => $m->verfahren === null,
                'verbreitung' => (int) ($verbreitung[$cid] ?? 0),
                'grundname' => mb_strtolower(trim((string) ($m->grundname ?: $m->slug))),
            ];
            $candMeta[$cid] = ['typ' => $c['best']['typ'], 'anker_id' => $primaerAnker, 'slug' => $c['slug']];
        }

        return [$kandidaten, $candMeta];
    }

    public function zahlAromaQuellen(array $ids, array $quellen): int
    {
        $getroffen = [];
        foreach ($ids as $id) {
            $getroffen += $quellen[$id] ?? ['anker:'.$id => true];
        }

        return count($getroffen);
    }
}
