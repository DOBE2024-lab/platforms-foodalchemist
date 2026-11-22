<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * CQ 2.6b · Nach dem Umzug Alt-Paket → Concept sind die alten Pakete nur noch lesbar (Schalter foodalchemist.paket_alt_nur_lesen).
 * Erbt von RuntimeException, damit bestehende `catch (\RuntimeException)` in UI und Tools die Meldung anzeigen.
 */
final class PaketAltNurLesenException extends \RuntimeException
{
    public function __construct(public readonly string $was = 'Alt-Paket', ?string $meldung = null)
    {
        parent::__construct($meldung ?? "{$was}: Alte Pakete sind nur noch lesbar, sie sind ins Concept-Modell umgezogen. "
            .'Bitte das Paket unter Concepts (Art Paket) bearbeiten.');
    }
}
