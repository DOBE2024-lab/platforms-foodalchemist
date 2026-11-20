<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;

/**
 * 98 · 2.3a — VK-Taxonomie (Einstellungen › VK-Taxonomie): Speisen-Hauptgruppe aktiv/inaktiv schalten (V-06:
 * referenziert ⇒ nur deaktivieren). Anlegen/Umbenennen/Löschen/Sortieren liegen schon im VocabularyService;
 * dieselbe Eigentumsregel gilt hier: sichtbar ist nicht eigen — nur das Besitzer-Team (bzw. der Master beim
 * globalen Basis-Katalog, isOwnedBy) schaltet.
 */
class VkTaxonomieService
{
    public function hauptgruppeAktivUmschalten(Team $team, int $id): FoodAlchemistDishMainGroup
    {
        $hg = FoodAlchemistDishMainGroup::visibleToTeam($team)->find($id);
        if ($hg === null) {
            throw new NichtGefundenException('Diese Speisen-Hauptgruppe gibt es nicht oder sie ist für dein Team nicht sichtbar.');
        }
        if (! $hg->isOwnedBy($team)) {
            throw new GeerbtNurLesenException('Speisen-Hauptgruppe', 'Geerbte Speisen-Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        $hg->update(['is_inactive' => ! $hg->is_inactive]);

        return $hg;
    }
}
