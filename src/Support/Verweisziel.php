<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

/**
 * Code-Qualität 1.1c/1.1j: Darf ein Team auf diesen Datensatz VERWEISEN (Slot, Block, Paket-Zeile)?
 *
 * Maßstab ist allein die bestehende Sicht-Regel {@see \Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy::sichtBedingung}
 * — globaler Seed, Master-Kette (Vererbung), Spec-77d-Freigaben, Abo-Zweig — und zwar EINSCHLIESSLICH Fassungen:
 * Mandanten abonnieren Inhalte, ein Verweis auf eine Abo-Fassung oder einen eingefrorenen Stand ist gewollte Fachlogik.
 * Nie „gehört dem eigenen Team“ (isOwnedBy gilt nur für den Datensatz, der geändert wird, nicht für das Verweisziel).
 */
final class Verweisziel
{
    /** @param  class-string  $klasse  Modell mit BelongsToTeamHierarchy */
    public static function sichtbar(string $klasse, Team $team): Builder
    {
        $q = method_exists($klasse, 'mitFassungen') ? $klasse::mitFassungen() : $klasse::query();
        $q->withoutGlobalScope(TeamSicht::class);   // die ausdrückliche Sicht unten ersetzt den automatischen Filter

        return $klasse::sichtBedingung($q, $team, true);
    }
}
