<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpGefaessKostenart;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

/**
 * Art und Preis des Gefäßes einer Darreichung (Entscheid Dominique 17.11.2026, Pilot TASTE & FLY: „einfach einen Preis an den Artikel
 * setzen und sagen: Einweg, Mehrweg, externer Artikel“). Je Team am Gefäß-GP: Art + Preis je Stück.
 *
 *  - einweg   wird mitgegeben: Preis am GP, sonst Lead-Artikelpreis je Stück (Verhalten bis heute)
 *  - mehrweg  Eigenbestand: der Preis am GP (z. B. Spülkosten), kein Einkaufspreis
 *  - extern   Miete/Fremdartikel: Preis am GP, sonst Lead-Artikelpreis je Stück (wie einweg; seit 18.11.2026)
 * Fehlt der Preis: „Gefäß-Preis fehlt“, Darreichung unvollständig — nie still 0. Die Kosten gehen in den Wareneinsatz (ek_portion).
 *
 * Wirksam: Art an der Darreichung (Übersteuerung) › Eintrag des Teams am GP (eigenes Team, dann Oberteams bis zur Kundengrenze) ›
 * Automatik (Lieferant der Gruppe VERLEIH → extern, sonst einweg). Ein VERLEIH-Gefäß ohne Eintrag rechnet mit dem Preis seines Lead-Artikels;
 * fehlt auch der, meldet die Darreichung „Gefäß-Preis fehlt“ (früher stiller Miet-Hinweis ohne Kosten, Befund Achenbachglas 18.11.2026).
 */
class GefaessKostenartService
{
    public const EINWEG = 'einweg';

    public const MEHRWEG = 'mehrweg';

    public const EXTERN = 'extern';

    public const ARTEN = [
        self::EINWEG => 'Einweg',
        self::MEHRWEG => 'Mehrweg (Eigenbestand)',
        self::EXTERN => 'Extern (Miete)',
    ];

    /** Kurztext für Planungsblatt/Produktionsschein: was die Logistik mit dem Gefäß tut. */
    public const LOGISTIK = [
        self::EINWEG => 'Einweg, wird mitgegeben',
        self::MEHRWEG => 'Mehrweg, kommt zurück in die Spülküche',
        self::EXTERN => 'Extern, geht an den Verleiher zurück',
    ];

    /** Eingabe normalisieren: '' / null = keine Angabe; unbekannt wirft. */
    public static function pruefe(mixed $wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $wert = strtolower(trim((string) $wert));
        if (! array_key_exists($wert, self::ARTEN)) {
            throw new \RuntimeException('Unbekannte Art des Gefäßes. Erlaubt: '.implode(', ', array_keys(self::ARTEN)).'.');
        }

        return $wert;
    }

    /** Preis je Stück normalisieren ('' / null = kein Preis; Komma erlaubt; < 0 wirft). */
    public static function pruefePreis(mixed $wert): ?float
    {
        if ($wert === null || trim((string) $wert) === '') {
            return null;
        }
        $wert = str_replace(',', '.', trim((string) $wert));
        if (! is_numeric($wert) || (float) $wert < 0) {
            throw new \RuntimeException('Der Preis je Stück muss eine Zahl ≥ 0 sein.');
        }

        return round((float) $wert, 4);
    }

    /**
     * Eintrag des Teams am GP: eigener, sonst der des nächsten Oberteams bis zur Kundengrenze (nie der des Masters).
     *
     * @return array{kostenart: ?string, preis: ?float, team_id: ?int}
     */
    public function teamVorgabe(int $gpId, Team $team): array
    {
        $kette = TeamSicht::gekappt(FoodAlchemistGp::teamAncestryIds($team));
        $zeilen = DB::table('foodalchemist_gp_gefaess_kostenarten')->where('gp_id', $gpId)->whereIn('team_id', $kette)->whereNull('deleted_at')
            ->get(['team_id', 'kostenart', 'preis_stk'])->keyBy('team_id');
        foreach ($kette as $tid) {
            if (($z = $zeilen->get($tid)) !== null && array_key_exists((string) $z->kostenart, self::ARTEN)) {
                return ['kostenart' => (string) $z->kostenart, 'preis' => $z->preis_stk !== null ? (float) $z->preis_stk : null, 'team_id' => (int) $tid];
            }
        }

        return ['kostenart' => null, 'preis' => null, 'team_id' => null];
    }

    /**
     * Wirksame Art + Preis am GP für eine Darreichung.
     *
     * @param  callable(): bool  $istVerleih  Automatik (Verleih-Lieferant), nur ausgewertet, wenn das Team nichts gesetzt hat
     * @return array{kostenart: string, quelle: string, vorgabe: string, preis_gp: ?float}  quelle = darreichung | team | automatik
     */
    public function wirksam(FoodAlchemistRecipeDarreichung $d, int $gpId, ?Team $team, callable $istVerleih): array
    {
        $eintrag = $team !== null ? $this->teamVorgabe($gpId, $team) : ['kostenart' => null, 'preis' => null];
        $vorgabe = $eintrag['kostenart'] ?? ($istVerleih() ? self::EXTERN : self::EINWEG);
        $eigene = is_string($d->vessel_cost_type) && array_key_exists($d->vessel_cost_type, self::ARTEN) ? $d->vessel_cost_type : null;

        return ['kostenart' => $eigene ?? $vorgabe, 'quelle' => $eigene !== null ? 'darreichung' : ($eintrag['kostenart'] !== null ? 'team' : 'automatik'),
            'vorgabe' => $vorgabe, 'preis_gp' => $eintrag['preis']];
    }

    /**
     * Art und Preis des eigenen Teams am GP setzen; Art null entfernt den eigenen Eintrag (dann Oberteam bzw. Automatik).
     * Betriebsdatum wie der Lagerartikel: jedes Team pflegt seine Zeile, auch an geerbten/globalen GPs. Danach rechnen die
     * Darreichungen mit diesem Gefäß neu.
     *
     * @return int Anzahl neu gerechneter Darreichungen
     */
    public function setzen(Team $team, int $gpId, mixed $kostenart, mixed $preis = null): int
    {
        $art = self::pruefe($kostenart);
        $preis = self::pruefePreis($preis);
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht sichtbar oder nicht vorhanden.');
        }
        if (! $gp->istNonFood()) {
            throw new \RuntimeException('Art und Preis als Gefäß haben nur Non-Food-Grundprodukte (Warengruppe 16).');
        }
        // auch die gelöschte Zeile: Unique (team_id, gp_id) gilt über SoftDeletes hinweg, neu Setzen stellt sie wieder her
        $eigen = FoodAlchemistGpGefaessKostenart::query()->ohneTeamSicht('eigene-zeile')->withTrashed()->where('team_id', $team->id)->where('gp_id', $gp->id)->first();
        if ($art === null) {
            if ($eigen !== null && ! $eigen->trashed()) {
                $eigen->delete();
            }
        } elseif ($eigen !== null) {
            $eigen->trashed() && $eigen->restore();
            $eigen->update(['kostenart' => $art, 'preis_stk' => $preis]);
        } else {
            FoodAlchemistGpGefaessKostenart::create(['team_id' => $team->id, 'gp_id' => $gp->id, 'kostenart' => $art, 'preis_stk' => $preis]);
        }

        return app(DarreichungService::class)->recomputeFuerZubehoerGps([(int) $gp->id]);
    }
}
