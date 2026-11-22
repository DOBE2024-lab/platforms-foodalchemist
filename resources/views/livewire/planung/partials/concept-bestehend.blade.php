{{-- Leitstelle, Reiter Concept: ein BESTEHENDES Concept in die Planung nehmen (Trait PlanBestehendesConcept).
     Spiegelt die Auswahl „Bestehendes Format/Foodbook/Angebot". Gewählt wird derselbe Zeiger wie beim
     KI-Kopf ($planConceptId): das Plan-Panel darunter zeigt Leitidee und Menü-Aufbau, das Erstellen füllt
     nur die leeren Positionen. Leere Auswahl löst den Plan wieder. --}}
@php
    $bestehende = $this->bestehendeConcepts();
    $gewaehltBestehend = $planConceptId && $bestehende->contains('id', (int) $planConceptId);
@endphp
<x-fa::modal-section icon="heroicon-o-squares-2x2" title="Konzept wählen" data-planung-concept-bestehend>
    <x-slot:actions>
        <x-fa::badge :tone="$gewaehltBestehend ? 'accent' : 'neutral'">{{ $gewaehltBestehend ? 'Bestehendes Konzept' : 'Neu aus Brief' }}</x-fa::badge>
    </x-slot:actions>
    <x-fa::field label="Bestehendes Konzept" hint="Gewählt: Leitidee und Positionen dieses Konzepts sind der Plan, erstellt werden nur die leeren Positionen. Leer: ein neues Konzept aus dem Brief.">
        <x-fa::select wire:change="bestehendesConceptWaehlen($event.target.value)" :disabled="$laeuft" data-planung-concept-auswahl>
            <option value="">Neues Konzept aus Brief</option>
            @foreach($bestehende as $bc)
                <option value="{{ $bc->id }}" @selected((int) $planConceptId === (int) $bc->id)>{{ $bc->name }}</option>
            @endforeach
        </x-fa::select>
    </x-fa::field>
</x-fa::modal-section>
