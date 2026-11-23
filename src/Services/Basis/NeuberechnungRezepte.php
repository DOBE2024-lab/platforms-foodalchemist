<?php

namespace Platform\FoodAlchemist\Services\Basis;

/**
 * Löst die Neuberechnung von Rezepten aus (EK, Allergene, Nährwerte, Yield …), synchron und mit denselben Rückgabewerten wie
 * {@see \Platform\FoodAlchemist\Services\RecipeRecomputeService}, das diese Schnittstelle implementiert; gebunden im
 * FoodAlchemistServiceProvider.
 *
 * Plan 98 · 3.3 S7 P4 (Muster K3): Stamm- und Pflege-Dienste unterhalb der Rezept-Schicht (GP, Leitartikel, Anreicherung, Fassung,
 * Komponenten-Äquivalente) stoßen die Neuberechnung über diese Schnittstelle an statt über die Klasse. Kein Event, weil die Aufrufer
 * den Rückgabewert brauchen: LeadLaService::recomputeNutzerFuerGps meldet die Zahl der neu gerechneten Rezepte an Oberfläche
 * und Protokolle. Der Name trägt bewusst kein Präfix Rezept/Recipe, damit die Schichtzuordnung (K2) die Schnittstelle der Basis
 * zuordnen kann.
 */
interface NeuberechnungRezepte
{
    /** Pipeline für EIN Rezept (eine Transaktion). */
    public function recomputePipeline(int $recipeId, bool $cascadePrices = true): void;

    /** @return array<int> betroffene recipe_ids (Kind + transitive Eltern) */
    public function recomputeAndPropagate(int $recipeId): array;

    /**
     * @param  array<int|string>  $recipeIds
     * @return array<int> betroffene recipe_ids (Start-Menge zuerst, dann transitive Eltern)
     */
    public function recomputeMany(array $recipeIds, ?array &$fehler = null, bool $mitEltern = true): array;
}
