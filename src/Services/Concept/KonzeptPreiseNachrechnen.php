<?php

namespace Platform\FoodAlchemist\Services\Concept;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPreisService;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * Bestand nachrechnen (CQ price_incomplete, Befehl `foodalchemist:konzept-preise-nachrechnen`): den Preis-Cache aller Konzepte
 * und Pakete neu schreiben, damit `price_incomplete` gesetzt ist. Gerechnet wird ausschließlich über
 * {@see ConceptService::recomputeCache} (eine Formel-Stelle); eingebettete Pakete vor den Konzepten, die sie enthalten.
 *
 * Fassungen haben keinen eigenen Cache (Team-Kalkulation zur Laufzeit) und bleiben außen vor.
 */
final class KonzeptPreiseNachrechnen
{
    public function __construct(private ConceptService $concepts) {}

    /**
     * Konzept-IDs in Rechen-Reihenfolge: eingebettete Konzepte vor ihren Eltern (Tiefensuche, zyklensicher).
     *
     * @return list<int>
     */
    public function reihenfolge(?int $teamId = null): array
    {
        $ids = FoodAlchemistConcept::query()->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $menge = array_flip($ids);
        $kinder = [];
        foreach (DB::table('foodalchemist_concept_slots')->whereNull('deleted_at')->whereNotNull('embedded_concept_id')
            ->whereIn('concept_id', $ids)->get(['concept_id', 'embedded_concept_id']) as $s) {
            if (isset($menge[(int) $s->embedded_concept_id])) {
                $kinder[(int) $s->concept_id][] = (int) $s->embedded_concept_id;
            }
        }

        $out = [];
        $gesehen = [];
        $besuche = function (int $id) use (&$besuche, &$out, &$gesehen, $kinder): void {
            if (isset($gesehen[$id])) {
                return;
            }
            $gesehen[$id] = true;
            foreach ($kinder[$id] ?? [] as $kind) {
                $besuche($kind);
            }
            $out[] = $id;
        };
        foreach ($ids as $id) {
            $besuche($id);
        }

        return $out;
    }

    /**
     * Trockenlauf (schreibt nichts) oder Nachrechnen. Der Trockenlauf rechnet mit dem gespeicherten Stand eingebetteter Pakete;
     * erst `--apply` zieht deren neues Flag in die Eltern.
     *
     * @return array{anzahl: int, unvollstaendig: int, flag_neu: int, preis_aendert: int, beispiele: list<array>, fehler: list<array>}
     */
    public function lauf(?int $teamId, bool $schreiben): array
    {
        $r = ['anzahl' => 0, 'unvollstaendig' => 0, 'flag_neu' => 0, 'preis_aendert' => 0, 'beispiele' => [], 'fehler' => []];
        foreach ($this->reihenfolge($teamId) as $id) {
            $c = FoodAlchemistConcept::query()->find($id);
            if ($c === null) {
                continue;
            }
            $r['anzahl']++;
            $altPreis = $c->price_per_person_cache !== null ? round((float) $c->price_per_person_cache, 2) : null;
            $altFlag = $c->price_incomplete;
            try {
                if ($schreiben) {
                    $c = DB::transaction(fn () => $this->concepts->recomputeCache($c));
                    [$neuPreis, $neuFlag] = [$c->price_per_person_cache !== null ? round((float) $c->price_per_person_cache, 2) : null, (bool) $c->price_incomplete];
                } else {
                    $cockpit = $this->concepts->preisCockpit($c);
                    [$neuPreis, $neuFlag] = [$cockpit['price_per_person'] !== null ? round((float) $cockpit['price_per_person'], 2) : null,
                        ConceptPreisService::unvollstaendig($cockpit)];
                }
            } catch (\Throwable $e) {
                $r['fehler'][] = ['concept_id' => $id, 'name' => $c->name, 'fehler' => $e->getMessage()];

                continue;
            }
            $r['unvollstaendig'] += $neuFlag ? 1 : 0;
            $r['flag_neu'] += $altFlag !== $neuFlag ? 1 : 0;
            $preisAendert = $altPreis !== $neuPreis;
            $r['preis_aendert'] += $preisAendert ? 1 : 0;
            if (($neuFlag || $preisAendert) && count($r['beispiele']) < 10) {
                $r['beispiele'][] = ['concept_id' => $id, 'name' => $c->name, 'kind' => $c->kind, 'preis_alt' => $altPreis, 'preis_neu' => $neuPreis,
                    'unvollstaendig' => $neuFlag];
            }
        }

        return $r;
    }
}
