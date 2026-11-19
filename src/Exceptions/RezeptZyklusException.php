<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Code-Qualität 1.1e · Ein Unterrezept-Zyklus (oder eine Selbstreferenz) im Rezeptgraph — beim Schreiben abgelehnt oder beim Rechnen
 * entdeckt (eingeschleust an den Schreibwegen vorbei). Erbt von RuntimeException, damit bestehende `catch (\RuntimeException)` die Meldung
 * weiter anzeigen; Aufrufer, die unterscheiden wollen, fangen den Typ.
 */
final class RezeptZyklusException extends \RuntimeException
{
    /** @param list<int> $rezeptIds beteiligte Rezepte (beim Schreiben [parent, sub], beim Rechnen der Zyklus in Pfad-Reihenfolge) */
    public function __construct(public readonly array $rezeptIds, ?string $meldung = null, public readonly string $grund = 'Zyklus')
    {
        parent::__construct($meldung ?? 'Zyklus im Rezeptgraph: Rezepte ' . implode(' → ', $rezeptIds) . '.');
    }
}
