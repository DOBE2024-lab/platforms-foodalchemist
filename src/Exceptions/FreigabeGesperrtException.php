<?php

namespace Platform\FoodAlchemist\Exceptions;

use Platform\FoodAlchemist\Enums\RecipeStatus;

/**
 * Code-Qualität 1.1f · Freigabe gesperrt: mindestens eine Komponente (rekursiv) ist noch nicht freigegeben
 * ({@see \Platform\FoodAlchemist\Services\Rezept\FreigabeSperre}). Trägt die Liste der offenen Komponenten für Meldung, UI-Knopf
 * „Alles mitfreigeben“ und MCP-Antwort. Erbt von RuntimeException: bestehende `catch (\RuntimeException)` zeigen die Meldung weiter an.
 */
final class FreigabeGesperrtException extends \RuntimeException
{
    /**
     * @param  list<array{art:string, id:int, name:string, status:string, team_id:?int}>  $offen
     * @param  bool  $mitfreigebbar  mindestens eine Komponente ließe sich per „Alles mitfreigeben“ freigeben
     */
    public function __construct(public readonly array $offen, public readonly bool $mitfreigebbar = false)
    {
        $liste = array_map(fn ($k) => $k['name'] . ' (' . self::statusLabel($k) . ')', array_slice($offen, 0, 8));
        $rest = count($offen) > 8 ? ' und ' . (count($offen) - 8) . ' weitere' : '';
        parent::__construct('Freigabe gesperrt: ' . count($offen) . ' Komponente(n) noch nicht freigegeben — ' . implode(', ', $liste) . $rest . '.');
    }

    public static function statusLabel(array $k): string
    {
        return $k['art'] === 'rezept'
            ? (RecipeStatus::tryFrom($k['status'])?->label() ?? $k['status'])
            : match ($k['status']) { 'draft' => 'Entwurf', 'archiviert' => 'Archiviert', default => $k['status'] };
    }
}
