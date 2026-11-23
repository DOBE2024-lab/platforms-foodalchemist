{{-- Preisverlauf des Grundprodukts als Linie: eine Linie je Lieferantenartikel, Lead hervorgehoben. Eine Quelle für beide Render-Stellen:
     GP-Detailspalte allein (gps/detail-panel, section null) und GP-Dialog Reiter „price“ (gp-modal, x-slot:frei). Anzeige-Insel:
     Zeitraum, von–bis, „Alle Artikel“ und die Linien-Auswahl ändern nur Anzeige-State (Preise\Verlauf) und bleiben im Lesemodus bedienbar. --}}
<x-fa::section variant="plain" title="Preisverlauf" icon="heroicon-o-presentation-chart-line" data-sektion="preisverlauf" data-fa-anzeige>
    <livewire:foodalchemist.preise.verlauf modus="gp" :objekt-id="$gp->id" :key="'pv-gp-'.$gp->id" />
</x-fa::section>
