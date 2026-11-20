<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpGefaessZubehoer;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

/**
 * Zubehör am Gefäß-Grundprodukt (Entscheid Dominique 20.11.2026, „Weg 2“): ein Schraubglas braucht einen Deckel, der oft getrennt eingekauft wird
 * und eine andere Kostenart hat (Mietglas + gekaufter Einweg-Deckel). Am Gefäß-GP hängt „gehört dazu: n × <Non-Food-GP>“; jede Darreichung mit
 * diesem Gefäß rechnet das Zubehör mit, jedes Teil mit SEINEM Preis und SEINER Kostenart ({@see GefaessKostenartService}, gleiche Logik wie das Gefäß;
 * die Kosten rechnet {@see DarreichungService::gefaessZubehoerKosten}). Kein Zubehör = Verhalten exakt wie bisher.
 *
 *  - Team-Scope wie die Kostenart: es gilt die Liste des eigenen Teams, sonst die des nächsten Oberteams bis zur Kundengrenze. Ändert ein
 *    Unter-Team eine geerbte Liste, wird sie zuerst ins eigene Team übernommen (Kopie), dann geändert. Gelöschte Zeilen bleiben als Markierung:
 *    wer alles entfernt, hat eine eigene leere Liste und erbt nicht wieder. {@see zuruecksetzen} holt die Vorgabe des Oberteams zurück.
 *  - Eine Ebene: Zubehör hat kein eigenes Zubehör und ein Gefäß mit Zubehör ist nicht selbst Zubehör (keine Zyklen, kein Selbstbezug).
 *  - Nur Non-Food (Warengruppe 16), Menge > 0 (Stück je Gefäß).
 */
class GefaessZubehoerService
{
    private const TABELLE = 'foodalchemist_gp_gefaess_zubehoer';

    /**
     * Wirksame Zubehör-Liste eines Gefäßes für ein Team: die des eigenen Teams, sonst die des nächsten Oberteams (eine eigene leere Liste zählt).
     *
     * @return array{team_id: ?int, zeilen: list<array{gp_id: int, menge: float, name: string}>}
     */
    public function liste(int $gefaessGpId, ?Team $team): array
    {
        if ($team === null) {
            return ['team_id' => null, 'zeilen' => []];
        }
        $listen = $this->listen([$gefaessGpId], $team);

        return $listen[$gefaessGpId] ?? ['team_id' => null, 'zeilen' => []];
    }

    /**
     * Wie {@see liste} für mehrere Gefäße in einer Abfrage.
     *
     * @param  list<int>  $gefaessGpIds
     * @return array<int, array{team_id: int, zeilen: list<array{gp_id: int, menge: float, name: string}>}>  nur Gefäße mit (auch leerer) Liste
     */
    public function listen(array $gefaessGpIds, Team $team): array
    {
        $gefaessGpIds = array_values(array_unique(array_map('intval', $gefaessGpIds)));
        if ($gefaessGpIds === []) {
            return [];
        }
        $kette = TeamSicht::gekappt(FoodAlchemistGp::teamAncestryIds($team));
        $zeilen = DB::table(self::TABELLE.' as z')->leftJoin('foodalchemist_gps as g', 'g.id', '=', 'z.zubehoer_gp_id')
            ->whereIn('z.gefaess_gp_id', $gefaessGpIds)->whereIn('z.team_id', $kette)
            ->orderBy('z.position')->orderBy('z.id')
            ->get(['z.team_id', 'z.gefaess_gp_id', 'z.zubehoer_gp_id', 'z.menge', 'z.deleted_at', 'g.name'])
            ->groupBy('gefaess_gp_id');
        $ergebnis = [];
        foreach ($zeilen as $gefaessId => $alle) {
            $jeTeam = $alle->groupBy('team_id');
            foreach ($kette as $tid) {
                if (($eigene = $jeTeam->get($tid)) === null) {
                    continue;
                }
                $ergebnis[(int) $gefaessId] = ['team_id' => (int) $tid, 'zeilen' => $eigene->whereNull('deleted_at')->map(fn ($z) => [
                    'gp_id' => (int) $z->zubehoer_gp_id, 'menge' => (float) $z->menge, 'name' => (string) ($z->name ?? '–'),
                ])->values()->all()];
                break;
            }
        }

        return $ergebnis;
    }

    /**
     * Zubehör am Gefäß für das eigene Team setzen oder die Menge ändern (Menge ≤ 0 wirft; Entfernen über {@see entfernen}).
     * Danach rechnen die Darreichungen mit diesem Gefäß neu.
     *
     * @return int Anzahl neu gerechneter Darreichungen
     */
    public function setzen(Team $team, int $gefaessGpId, int $zubehoerGpId, mixed $menge = 1): int
    {
        $menge = $this->pruefeMenge($menge);
        [$gefaess, $zubehoer] = $this->pruefePaar($team, $gefaessGpId, $zubehoerGpId);
        DB::transaction(function () use ($team, $gefaess, $zubehoer, $menge) {
            $this->eigeneListeSicherstellen($team, (int) $gefaess->id);
            $zeile = FoodAlchemistGpGefaessZubehoer::query()->ohneTeamSicht('eigene-zeile')->withTrashed()
                ->where('team_id', $team->id)->where('gefaess_gp_id', $gefaess->id)->where('zubehoer_gp_id', $zubehoer->id)->first();
            if ($zeile !== null) {
                $zeile->trashed() && $zeile->restore();
                $zeile->update(['menge' => $menge]);
            } else {
                $pos = (int) DB::table(self::TABELLE)->where('team_id', $team->id)->where('gefaess_gp_id', $gefaess->id)->max('position');
                FoodAlchemistGpGefaessZubehoer::create(['team_id' => $team->id, 'gefaess_gp_id' => $gefaess->id, 'zubehoer_gp_id' => $zubehoer->id,
                    'menge' => $menge, 'position' => $pos + 1]);
            }
        });

        return app(DarreichungService::class)->recomputeFuerZubehoerGps([(int) $gefaess->id]);
    }

    /**
     * Zubehör aus der Liste des eigenen Teams entfernen (eine geerbte Liste wird vorher übernommen, damit die übrigen Teile bleiben).
     *
     * @return int Anzahl neu gerechneter Darreichungen
     */
    public function entfernen(Team $team, int $gefaessGpId, int $zubehoerGpId): int
    {
        $gefaess = $this->sichtbaresGefaess($team, $gefaessGpId);
        DB::transaction(function () use ($team, $gefaess, $zubehoerGpId) {
            $this->eigeneListeSicherstellen($team, (int) $gefaess->id);
            FoodAlchemistGpGefaessZubehoer::query()->ohneTeamSicht('eigene-zeile')->where('team_id', $team->id)
                ->where('gefaess_gp_id', $gefaess->id)->where('zubehoer_gp_id', $zubehoerGpId)->get()->each->delete();
        });

        return app(DarreichungService::class)->recomputeFuerZubehoerGps([(int) $gefaess->id]);
    }

    /**
     * Eigene Liste des Teams verwerfen (auch die Leer-Markierung): danach gilt wieder die Liste des Oberteams bzw. kein Zubehör.
     *
     * @return int Anzahl neu gerechneter Darreichungen
     */
    public function zuruecksetzen(Team $team, int $gefaessGpId): int
    {
        $gefaess = $this->sichtbaresGefaess($team, $gefaessGpId);
        FoodAlchemistGpGefaessZubehoer::query()->ohneTeamSicht('eigene-zeile')->withTrashed()->where('team_id', $team->id)
            ->where('gefaess_gp_id', $gefaess->id)->get()->each->forceDelete();

        return app(DarreichungService::class)->recomputeFuerZubehoerGps([(int) $gefaess->id]);
    }

    /**
     * Gefäße, die eines dieser GPs als Zubehör führen (alle Teams) — für die Kosten-Kaskade: ändert sich der Preis/die Art des Deckels,
     * rechnen die Darreichungen mit dem Glas neu.
     *
     * @param  list<int>  $zubehoerGpIds
     * @return list<int>
     */
    public function gefaesseMitZubehoer(array $zubehoerGpIds): array
    {
        $gefaesse = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $zubehoerGpIds))), 500) as $teil) {
            foreach (DB::table(self::TABELLE)->whereIn('zubehoer_gp_id', $teil)->whereNull('deleted_at')->distinct()->pluck('gefaess_gp_id') as $id) {
                $gefaesse[(int) $id] = true;
            }
        }

        return array_keys($gefaesse);
    }

    /** Menge normalisieren: Zahl (Komma erlaubt) > 0, sonst Fehler. */
    public static function pruefeMenge(mixed $menge): float
    {
        $text = str_replace(',', '.', trim((string) ($menge ?? '')));
        if ($text === '' || ! is_numeric($text) || (float) $text <= 0) {
            throw new \RuntimeException('Die Menge des Zubehörs muss eine Zahl größer als 0 sein (Stück je Gefäß).');
        }

        return round((float) $text, 3);
    }

    /** @return array{0: FoodAlchemistGp, 1: FoodAlchemistGp} */
    private function pruefePaar(Team $team, int $gefaessGpId, int $zubehoerGpId): array
    {
        $gefaess = $this->sichtbaresGefaess($team, $gefaessGpId);
        if ($zubehoerGpId === (int) $gefaess->id) {
            throw new \RuntimeException('Ein Gefäß kann nicht sein eigenes Zubehör sein.');
        }
        $zubehoer = FoodAlchemistGp::visibleToTeam($team)->find($zubehoerGpId);
        if ($zubehoer === null) {
            throw new \RuntimeException('Zubehör (Grundprodukt) nicht sichtbar oder nicht vorhanden.');
        }
        if (! $zubehoer->istNonFood()) {
            throw new \RuntimeException('Als Zubehör am Gefäß gehen nur Non-Food-Grundprodukte (Warengruppe 16).');
        }
        // eine Ebene: das Zubehör hat kein eigenes Zubehör, das Gefäß ist nicht Zubehör eines anderen Gefäßes (in der Sicht dieses Teams)
        if ($this->liste((int) $zubehoer->id, $team)['zeilen'] !== []) {
            throw new \RuntimeException('„'.$zubehoer->name.'“ hat selbst Zubehör und kann deshalb kein Zubehör sein (nur eine Ebene).');
        }
        $kette = TeamSicht::gekappt(FoodAlchemistGp::teamAncestryIds($team));
        if (DB::table(self::TABELLE)->where('zubehoer_gp_id', $gefaess->id)->whereIn('team_id', $kette)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('„'.$gefaess->name.'“ ist selbst Zubehör eines anderen Gefäßes und bekommt kein eigenes Zubehör (nur eine Ebene).');
        }

        return [$gefaess, $zubehoer];
    }

    private function sichtbaresGefaess(Team $team, int $gpId): FoodAlchemistGp
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new \RuntimeException('Gefäß (Grundprodukt) nicht sichtbar oder nicht vorhanden.');
        }
        if (! $gp->istNonFood()) {
            throw new \RuntimeException('Zubehör haben nur Gefäße aus Non-Food (Warengruppe 16).');
        }

        return $gp;
    }

    /** Hat das Team noch keine eigene Liste, die geerbte (falls vorhanden) ins eigene Team kopieren — Änderungen wirken dann nur hier. */
    private function eigeneListeSicherstellen(Team $team, int $gefaessGpId): void
    {
        $wirksam = $this->liste($gefaessGpId, $team);
        if ($wirksam['team_id'] === null || $wirksam['team_id'] === (int) $team->id) {
            return;
        }
        foreach ($wirksam['zeilen'] as $i => $z) {
            FoodAlchemistGpGefaessZubehoer::create(['team_id' => $team->id, 'gefaess_gp_id' => $gefaessGpId, 'zubehoer_gp_id' => $z['gp_id'],
                'menge' => $z['menge'], 'position' => $i + 1]);
        }
    }
}
