<?php

namespace Platform\FoodAlchemist\Services\Pairing;

/**
 * 98 · 3.3 (KI-Strang c): schmale Schnittstelle der Kohäsion für die Teile der PairingService-Fassade (Muster wie
 * FoodbookDokumentQuelle). PairingNetz liest die Kohäsion hierüber statt über PairingService — so hat der Teil keine Kante
 * zurück zur Fassade.
 */
interface PairingKohaesionQuelle
{
    /**
     * Kohäsion einer Komponenten-Liste (3.2 — T4/T5/T6/T9), {@see PairingService::cohesionFor()}.
     *
     * @param  list<array{label: string, kern: ?int, prozess: list<int>}>  $komponenten
     */
    public function cohesionFor(array $komponenten): array;
}
