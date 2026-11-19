<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Allergene, Zusatzstoffe und Nährwerte von Hand am Grundprodukt gibt es NUR bei Platzhaltern (Entscheid Dominique 19.11.2026). Alle anderen GPs
 * bekommen ihre Werte aus den Lieferantenartikeln (Kaskade LA → GP → Rezept). Eigener Typ statt Satz-Vergleich: Aufrufer (MCP-Code
 * NOT_PLACEHOLDER) unterscheiden am Typ, nicht am Wortlaut. Erbt von RuntimeException, damit `catch (\RuntimeException)` die Meldung zeigt.
 */
final class KeinPlatzhalterException extends \RuntimeException
{
    public function __construct(public readonly string $gpName = '')
    {
        parent::__construct('Allergene, Zusatzstoffe und Nährwerte von Hand nur bei Platzhaltern'
            . ($gpName !== '' ? " — „{$gpName}“ bekommt sie aus den Lieferantenartikeln." : '.'));
    }
}
