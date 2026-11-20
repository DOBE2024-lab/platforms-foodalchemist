<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Code-Qualität 1.1b · Die Kosten-Folge hat Rezepte nicht neu rechnen können (Fehler je Rezept aus `recomputeMany`). Der Job schlägt damit fehl,
 * die Queue wiederholt ihn; die übrigen Rezepte sind bereits gerechnet.
 */
final class KostenFolgeFehlgeschlagenException extends \RuntimeException
{
    /** @param array<int, string> $fehler recipe_id => erste Fehlermeldung */
    public function __construct(public readonly int $teamId, public readonly array $fehler)
    {
        $beispiel = array_slice($fehler, 0, 3, true);
        parent::__construct('Kosten-Folge (Team ' . $teamId . '): ' . count($fehler) . ' Rezept(e) nicht neu berechnet — '
            . implode('; ', array_map(fn ($id, $m) => "#{$id}: {$m}", array_keys($beispiel), $beispiel)));
    }
}
