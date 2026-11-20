<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

/** 98 · 1.3c · Schreibwege an Produktions-Posten (Stammdaten), damit Tools nicht selbst speichern. */
class ProductionStationService
{
    /**
     * Spec 83 B3g: Pass = Sammelposten. Nur das Besitzer-Team pflegt den Posten.
     *
     * @throws \RuntimeException bei geerbtem Posten
     */
    public function setzeSammelposten(Team $team, FoodAlchemistProductionStation $posten, bool $sammel): FoodAlchemistProductionStation
    {
        if (! $posten->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Posten ist eine Vorlage — Pflege nur durchs Besitzer-Team.');
        }
        $posten->ist_sammelposten = $sammel;
        $posten->save();

        return $posten;
    }
}
