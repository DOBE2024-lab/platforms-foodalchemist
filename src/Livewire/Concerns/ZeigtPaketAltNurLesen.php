<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Exceptions\PaketAltNurLesenException;

/**
 * CQ 2.6b · Alte Paket-Oberflächen (/pakete, alter /concepts-Zweig) bis zu ihrem Abbau in 2.6c: Ist der Schreibschutz an
 * (foodalchemist.paket_alt_nur_lesen), zeigt ein Schreibversuch die Meldung als Toast statt eines Serverfehlers.
 */
trait ZeigtPaketAltNurLesen
{
    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof PaketAltNurLesenException) {
            $this->dispatch('toast', text: $e->getMessage());
            $stopPropagation();
        }
    }
}
