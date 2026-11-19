<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * 98 · 1.3c · Kalkulationsprofile (Outlets) anlegen — EIN Weg für Einstellungen (Settings\Betriebe) und MCP
 * (outlets.POST). Vorher legten beide getrennt an; das Tool ohne Sortierung.
 */
class OutletService
{
    /** @throws \RuntimeException bei leerem oder schon vergebenem Namen (Unique ist team_id + name). */
    public function anlegen(Team $team, string $name): FoodAlchemistOutlet
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('name darf nicht leer sein.');
        }
        if (FoodAlchemistOutlet::where('team_id', $team->id)->where('name', $name)->exists()) {
            throw new \RuntimeException('Es gibt bereits ein Kalkulationsprofil mit diesem Namen.');
        }

        return FoodAlchemistOutlet::create([
            'team_id' => $team->id,
            'name' => $name,
            'sort_order' => (int) (FoodAlchemistOutlet::where('team_id', $team->id)->max('sort_order') ?? 0) + 10,
        ]);
    }
}
