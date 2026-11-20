<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 2.3a — Einsatzorte (ALTLAST #469, `foodalchemist_knowledge_layers`) umbenennen und aktiv/inaktiv schalten.
 * Schreibrecht: eigene Zeile immer, globale (team_id NULL) nur als Master, fremde nie (TeamScope::mayWrite) —
 * ein Layer ist ein Bindungs-Ziel, ein Kind-Team darf ihn nicht für alle abschalten.
 *
 * Fehler: NichtGefundenException / GeerbtNurLesenException (RuntimeException) und \InvalidArgumentException —
 * die Meldungen sind für Menschen geschrieben und werden so angezeigt.
 */
class EinsatzorteService
{
    public function aktualisieren(Team $team, int $id, array $eingabe): void
    {
        $this->eigene($team, $id, 'Geerbter/globaler Einsatzort — umbenennen kann nur das Besitzer-Team.');
        $label = trim((string) ($eingabe['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('Bezeichnung ist Pflicht.');
        }
        DB::table('foodalchemist_knowledge_layers')->where('id', $id)->update([
            'label' => $label,
            'description' => ($eingabe['description'] ?? '') !== '' ? trim((string) $eingabe['description']) : null,
            'updated_at' => now(),
        ]);
    }

    public function aktivUmschalten(Team $team, int $id): void
    {
        $z = $this->eigene($team, $id, 'Geerbter/globaler Einsatzort — aktiv/inaktiv setzt nur das Besitzer-Team.');
        DB::table('foodalchemist_knowledge_layers')->where('id', $id)
            ->update(['active' => ! $z->active, 'updated_at' => now()]);
    }

    /** Zeile, die das Team schreiben darf — sonst wirft es mit der Meldung der jeweiligen Aktion. */
    private function eigene(Team $team, int $id, string $meldung): object
    {
        $z = DB::table('foodalchemist_knowledge_layers')->where('id', $id)->first(['active', 'team_id']);
        if ($z === null) {
            throw new NichtGefundenException($meldung);
        }
        if (! TeamScope::mayWrite($z->team_id, $team)) {
            throw new GeerbtNurLesenException('Einsatzort', $meldung);
        }

        return $z;
    }
}
