<?php

namespace Platform\FoodAlchemist\Services\PaketUmzug;

use Platform\FoodAlchemist\Exceptions\PaketAltNurLesenException;

/**
 * CQ 2.6b · Schreibschutz für die alte Paket-Persistenz (foodalchemist_packages, foodalchemist_package_dishes) und für neue
 * Verweise per `package_id`/`paket_id`. Schalter `foodalchemist.paket_alt_nur_lesen` (Standard aus; auf io nach dem Umzug an,
 * Rückweg = aus). Greift zentral über Model-Hooks (FoodAlchemistPaket, FoodAlchemistPaketGericht) und fachlich dort, wo ein
 * neuer Verweis gesetzt wird (Concept-Slot, Speiseplan, Bestellvorlage). Automatische Nachrechnungen überspringen die alten
 * Pakete. Abgebaut in 2.6c (Code) und 3.1 (Spalten).
 */
final class PaketAltNurLesen
{
    public static function aktiv(): bool
    {
        return (bool) config('foodalchemist.paket_alt_nur_lesen', false);
    }

    /** @throws PaketAltNurLesenException wenn der Schutz an ist */
    public static function pruefe(string $was = 'Alt-Paket', ?string $meldung = null): void
    {
        if (self::aktiv()) {
            throw new PaketAltNurLesenException($was, $meldung);
        }
    }
}
