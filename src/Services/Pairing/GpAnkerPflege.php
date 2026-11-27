<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\AnkerQuelle;

/**
 * 98 · 3.3 (KI-Strang c): Pflege der GP-Aroma-Anker (Tabelle gp_anchor_mappings, Inv. 1/3) — wer pflegen darf (Spec 84 B2),
 * setzen, lösen, leeren, KI-Inferenz, dazu die Lesewege gpAnkers/gpAnkerAlle/gpsForAnkerIds. Aus {@see PairingService}
 * geschnitten; Rümpfe unverändert. Blatt: kennt weder PairingService noch Kombinationslogik oder RezeptProfil.
 * setGpAnkerStaerke bleibt an der Fassade, weil es den RezeptProfil-Speicher leert (sonst Kante Pflege → RezeptProfil).
 * GpAromaService ruft die Pflege direkt, nicht über die Fassade.
 */
class GpAnkerPflege
{
    /**
     * GP-Aroma-Anker setzen/aktualisieren (Tabelle gp_anchor_mappings,
     * je Rolle gedeckelt ({@see \Platform\FoodAlchemist\Support\AnkerGrenze}) — mehrere Anker je GP erlaubt, s. Altdaten: 433 GPs mit >1 Anker, z. B. Ratatouille →
     * eggplant/tomato/zucchini). `role` unterscheidet Haupt- von Nebenträger (kern|neben, Default
     * kern); `source`/`ai_confidence`/`ai_reasoning` sind offen für den MCP-Import (Spec 53 Paket J:
     * `bridge_alt_neu`, `exact_name`, … — nicht nur `manual`).
     */
    /**
     * Spaltenbreite `foodalchemist_gp_anchor_mappings.source` (Migration
     * `2026_09_19_000001_widen_gp_anchor_mapping_source`). Hier statt eines rohen SQLSTATE
     * 22001 geprüft — 506 Zeilen des Vault-Bridge-Imports (`bridge_alt_neu+exact`, 20 Zeichen)
     * fielen genau darauf herein, bevor die Spalte 16 Zeichen hatte.
     */
    public const GP_ANKER_SOURCE_MAX = 32;

    /**
     * Spec 84 B2 · Wer darf die Anker eines GP pflegen? Die Mapping-Tabelle hat keinen Team-Schlüssel — eine Änderung
     * wirkt für ALLE, die das GP sehen.
     *  · eigenes GP bzw. Master an globaler Stamm-Zeile (isOwnedBy): ja
     *  · GP der eigenen Team-Kette (Unter-Team pflegt das Oberteam-GP): ja — bewusste frühere Entscheidung
     *    „Anker-Pflege ist ein geteiltes kuratorisches Anliegen" (McpGpAnchorsTest)
     *  · globales GP ohne Master-Recht, GP eines anderen Mandanten: nein (wirkte über Mandantengrenzen)
     */
    public function darfAnkerPflegen(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, Team $team): bool
    {
        if ($gp->isOwnedBy($team)) {
            return true;
        }

        return $gp->team_id !== null && in_array((int) $gp->team_id, \Platform\FoodAlchemist\Models\FoodAlchemistGp::teamAncestryIds($team), true);
    }

    public function pruefeAnkerPflege(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, Team $team): void
    {
        if (! $this->darfAnkerPflegen($gp, $team)) {
            throw new \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException('GP-Anker');
        }
    }

    public function setGpAnker(
        Team $team, int $gpId, int $ankerId, string $role = 'kern', string $source = 'manual',
        ?float $aiConfidence = null, ?string $aiReasoning = null,
    ): void {
        $role = in_array($role, ['kern', 'neben'], true) ? $role : 'kern';
        if (mb_strlen($source) > self::GP_ANKER_SOURCE_MAX) {
            throw new \RuntimeException(
                'source zu lang (max. ' . self::GP_ANKER_SOURCE_MAX . ' Zeichen): "' . $source . '" hat ' . mb_strlen($source) . '.'
            );
        }
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        $this->pruefeAnkerPflege($gp, $team);   // Spec 84 B2: Mapping hat keinen Team-Schlüssel
        \Platform\FoodAlchemist\Support\AnkerGrenze::pruefe($gp->id, $ankerId, $role);   // je Rolle; Rollenwechsel nur mit Platz
        DB::table('foodalchemist_gp_anchor_mappings')->updateOrInsert(
            ['gp_id' => $gp->id, 'anchor_id' => $ankerId],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id, 'role' => $role,
                'source' => $source, 'ai_confidence' => $aiConfidence, 'ai_reasoning' => $aiReasoning,
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Löst einen einzelnen GP-Anker (soft-delete). */
    public function removeGpAnker(Team $team, int $gpId, int $ankerId): void
    {
        $this->pruefeAnkerPflege(\Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId), $team);
        DB::table('foodalchemist_gp_anchor_mappings')
            ->where('gp_id', $gpId)->where('anchor_id', $ankerId)->update(['deleted_at' => now()]);
    }

    /**
     * Alle Anker eines GP löschen (soft-delete) — für den MCP-Import mit `ersetze_alle=true`
     * (Spec 53 Paket J): die gelieferte Anker-Liste ersetzt den bisherigen Bestand vollständig.
     */
    public function clearGpAnker(Team $team, int $gpId): void
    {
        $this->pruefeAnkerPflege(\Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId), $team);
        DB::table('foodalchemist_gp_anchor_mappings')
            ->where('gp_id', $gpId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
    }

    /**
     * Spec 50 · B-7: KI-Inferenz des GP-Kern-Ankers (Bulk-Schritt `anker`). Manuelle Mappings
     * werden nie ersetzt (Inv. 3); ältere KI-Mappings ({@see AnkerQuelle::KI_QUELLEN}) desselben GPs weichen, damit
     * der Bulk-Lauf EINEN Kern-Anker setzt und die Kern-Grenze nicht mit KI-Resten füllt. Neutral: {@see GpAromaService::merkeAnkerNeutral()}.
     * `neben` (17.11.2026) ergänzt, verdrängt nichts; Rolle eines verknüpften Ankers bleibt. false = Handarbeit, nichts geschrieben.
     */
    public function setGpAnkerInference(Team $team, int $gpId, int $ankerId, float $confidence, string $reasoning = 'Bulk-Anreicherung', string $quelle = AnkerQuelle::KI, string $role = 'kern'): bool
    {
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        $this->pruefeAnkerPflege($gp, $team);
        $manual = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('anchor_id', $ankerId)->where('source', 'manual')->exists();
        if ($manual) {
            return false;
        }
        $role = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('anchor_id', $ankerId)->whereNull('deleted_at')->value('role') ?? ($role === 'neben' ? 'neben' : 'kern');
        DB::transaction(function () use ($gp, $team, $ankerId, $confidence, $reasoning, $quelle, $role) {
            if ($role === 'kern') {                                   // nur ältere KI-KERN-Anker weichen
                DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('role', 'kern')
                    ->where('anchor_id', '!=', $ankerId)->whereIn('source', AnkerQuelle::KI_QUELLEN)->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
            \Platform\FoodAlchemist\Support\AnkerGrenze::pruefe($gp->id, $ankerId, $role);
            DB::table('foodalchemist_gp_anchor_mappings')->updateOrInsert(
                ['gp_id' => $gp->id, 'anchor_id' => $ankerId],
                ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                    'role' => $role, 'source' => $quelle === AnkerQuelle::PATE ? $quelle : AnkerQuelle::KI, 'ai_confidence' => max(0, min(1, $confidence)),
                    'ai_reasoning' => mb_strimwidth($reasoning, 0, 500), 'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        });

        return true;
    }

    /** Kern-Aroma-Anker eines GP inkl. Slug/Quelle (GP-Pairing-Panel, Aroma-Ähnlichkeit/Ersatz-Logik). */
    public function gpAnkers(int $gpId): Collection
    {
        return DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('m.gp_id', $gpId)->where('m.role', 'kern')->whereNull('m.deleted_at')
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')->orderBy('m.id')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.source', 'm.ai_confidence']);
    }

    /**
     * ALLE Anker eines GP (kern UND neben), inkl. `role` — für den Editor-Block und das Detail-Panel
     * (Spec 53 Paket J). Anders als {@see gpAnkers}, das bewusst nur `kern` liefert (Aroma-Ähnlichkeit/
     * Ersatz-Logik nutzt nur den Haupt-Aromaträger) — hier soll der Kurator BEIDE Rollen sehen/pflegen.
     */
    public function gpAnkerAlle(int $gpId): Collection
    {
        return DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('m.gp_id', $gpId)->whereNull('m.deleted_at')
            ->orderByRaw("CASE m.role WHEN 'kern' THEN 1 ELSE 2 END")->orderBy('a.display_de')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.role', 'm.staerke', 'm.source', 'm.ai_confidence']);
    }

    /**
     * Spec 19 E9.2: Anker → GP Reverse-Lookup („welche echten GPs tragen Aroma X als Kern").
     * Gegenrichtung zu gpAnkers(); zuvor nur privat in aromaTrueSubstitutes eingebettet
     * (Doc-TODO D-7_pairing.md §GL-10 3.4). Team-scoped, ohne Derivate/Platzhalter (die kauft
     * man nicht als Aromaträger). Read-only.
     *
     * @param  list<int>  $ankerIds  Kern-Anker-IDs
     * @return \Illuminate\Support\Collection<int, object>  GP-Zeilen {id, name, lead_la_supplier_item_id, is_favorite, status, requires_la, anchor_id}
     */
    public function gpsForAnkerIds(Team $team, array $ankerIds): Collection
    {
        $ankerIds = array_values(array_unique(array_map('intval', $ankerIds)));
        if ($ankerIds === []) {
            return collect();
        }
        // Anker → gp_id-Zuordnung (kern), damit der Aufrufer weiß, welcher GP welches Aroma trägt.
        $mapping = DB::table('foodalchemist_gp_anchor_mappings')
            ->whereIn('anchor_id', $ankerIds)->where('role', 'kern')->whereNull('deleted_at')
            ->get(['gp_id', 'anchor_id']);
        $gpIds = $mapping->pluck('gp_id')->map(fn ($i) => (int) $i)->unique()->all();
        if ($gpIds === []) {
            return collect();
        }
        $gps = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('id', $gpIds)
            ->where('is_derivat', false)->where('is_platzhalter', false)
            ->get(['id', 'name', 'lead_la_supplier_item_id', 'status', 'requires_la'])
            ->keyBy('id');

        $favoriten = \Platform\FoodAlchemist\Support\FavoritenOverlay::fuer($team);   // Spec 85 FAV-1 · Eine Zeile pro (GP, Anker) — der Aufrufer gruppiert nach Anker (Inspiration) bzw. GP (Erdung).
        return $mapping
            ->filter(fn ($m) => $gps->has((int) $m->gp_id))
            ->map(function ($m) use ($gps, $favoriten) {
                $gp = $gps->get((int) $m->gp_id);

                return (object) [
                    'id' => (int) $gp->id, 'name' => (string) $gp->name,
                    'lead_la_supplier_item_id' => $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null,
                    'is_favorite' => isset($favoriten[(int) $gp->id]),
                    'status' => is_object($gp->status) ? $gp->status->value : (string) $gp->status,
                    'requires_la' => (bool) $gp->requires_la,
                    'anchor_id' => (int) $m->anchor_id,
                ];
            })->values();
    }
}
