<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\Core\Models\Team;

/**
 * Kosten eines Rezepts für ein bestimmtes Team (Spec 82 §12 C / Stufe 3b), rekursiv über die Unter-Rezepte. Implementiert von
 * {@see \Platform\FoodAlchemist\Services\RecipeRecomputeService::kostenFuerTeam}, gebunden im FoodAlchemistServiceProvider.
 *
 * Plan 98 · 3.3 S7 P3 (Muster K3, wie SpeiseplanDokumentQuelle): KostenCacheService kennt nur diese Schnittstelle statt der ganzen
 * Neuberechnung. Die Rekursion bleibt erhalten: Unterrezept-Fassung im Recompute → KostenCacheService::fuer → rechne →
 * kostenFuerTeam über diese Schnittstelle. Nur die Klassen-Kante KostenCacheService → RecipeRecomputeService entfällt, und die
 * hielt die große Kreis-Komponente zusammen.
 */
interface TeamKostenRechner
{
    /**
     * @param  array<int, array<string, mixed>>  $memo  Ergebnis je Rezept-Id (geteilte Unter-Rezepte einmal), wird gefüllt
     * @param  array<int, true>  $pfad  Zyklus-Schutz
     * @return array<string, mixed>
     */
    public function kostenFuerTeam(int $recipeId, Team $team, array &$memo = [], array $pfad = []): array;
}
