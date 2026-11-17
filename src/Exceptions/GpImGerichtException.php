<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Entscheid Dominique 17.11.2026: ein Gericht (Verkaufsrezept) besteht nur aus Basisrezepten. Ein Grundprodukt darf
 * nicht NEU als Zutat eines Gerichts gesetzt werden — Fertigware kommt als Zukauf-Basisrezept („Typ: Bezeichnung
 * (Zukauf)", Regelwerk Basisrezepte §17), Garnitur als Garnitur-Basisrezept. Bestehende Zeilen (Altbestand) bleiben
 * editier- und löschbar. Eigener Typ statt Satz-Vergleich: Aufrufer (MCP-Fehlercode GP_IM_GERICHT) unterscheiden am
 * Typ, nicht am Wortlaut.
 */
final class GpImGerichtException extends \RuntimeException
{
    public const CODE = 'GP_IM_GERICHT';

    /** @param  list<string>  $namen  die abgelehnten Grundprodukte */
    public function __construct(public readonly array $namen)
    {
        parent::__construct(self::meldung($namen));
    }

    /** @param  list<string>  $namen */
    public static function meldung(array $namen): string
    {
        $liste = implode(', ', array_map(static fn ($n) => '„' . $n . '“', $namen));

        return 'Ein Gericht enthält nur Basisrezepte. Lege für ' . ($liste !== '' ? $liste : 'das Grundprodukt')
            . ' ein Basisrezept an (Zukauf oder Garnitur).';
    }
}
