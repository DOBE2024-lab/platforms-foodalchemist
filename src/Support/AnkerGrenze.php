<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Anker-Grenzen je GP und Rolle (Entscheid Dominique 17.11.2026): höchstens 3 Kern-Anker (Aroma-Identität), daneben getrennt höchstens
 * 6 Nebenanker. Vorher zählte EIN Deckel (`PairingService::CAP_GP = 3`) alle Rollen zusammen — ein GP mit 3 Kern-Ankern bekam keinen
 * Nebenanker mehr („Curry: Brokkoli", Kokos als Neben → „Limit erreicht"). Gilt überall gleich: PairingService::setGpAnker und
 * setGpAnkerInference, GP-Paket-Import, MCP gp_anchors.PUT/IMPORT, Knopf „Anker vorschlagen".
 */
final class AnkerGrenze
{
    public const KERN = 3;

    public const NEBEN = 6;

    public static function fuer(string $role): int
    {
        return $role === 'neben' ? self::NEBEN : self::KERN;
    }

    /** EIN Wortlaut für Dialog, Paket-Import und MCP. */
    public static function meldung(string $role): string
    {
        return $role === 'neben' ? 'Limit erreicht: max '.self::NEBEN.' Nebenanker pro GP.' : 'Limit erreicht: max '.self::KERN.' Kern-Anker pro GP.';
    }

    /** Lebende Anker der Rolle am GP — ohne `$ohneAnkerId` (dieselbe Zeile zählt beim Aktualisieren/Rollenwechsel nicht gegen sich selbst). */
    public static function anzahl(int $gpId, string $role, ?int $ohneAnkerId = null): int
    {
        return DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->where('role', $role)->whereNull('deleted_at')
            ->when($ohneAnkerId !== null, fn ($q) => $q->where('anchor_id', '!=', $ohneAnkerId))->count();
    }

    /** Wirft die Meldung der Rolle, wenn ein weiterer Anker dieser Rolle die Grenze überschreiten würde. */
    public static function pruefe(int $gpId, int $ankerId, string $role): void
    {
        if (self::anzahl($gpId, $role, $ankerId) >= self::fuer($role)) {
            throw new \RuntimeException(self::meldung($role));
        }
    }
}
