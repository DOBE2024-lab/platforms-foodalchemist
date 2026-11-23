<?php

namespace Platform\FoodAlchemist\Services\Position;

/**
 * 98 · 3.6 Positions-Kern: Ablehnung einer Positions-Eingabe (Typ, Preisbasis, Referenz, Staffel). Bleibt eine
 * RuntimeException — vorhandene Fänger in Livewire und Diensten greifen unverändert —, trägt aber den MCP-Fehlercode,
 * damit die Tools dieselbe Prüfung nutzen und trotzdem NOT_FOUND bzw. VALIDATION_ERROR melden.
 */
final class PositionFehler extends \RuntimeException
{
    public function __construct(string $meldung, public readonly string $fehlerCode = 'VALIDATION_ERROR')
    {
        parent::__construct($meldung);
    }
}
