{{--
    Code-Qualität 1.1f · Freigabe-Meldung: welche Komponenten noch nicht freigegeben sind, getrennt nach „wird mitfreigegeben“ und
    „blockiert weiter“ (Platzhalter, Veraltet, geerbt/fremd), dazu der Knopf „Alles mitfreigeben“ (nur Rolle Kuratieren).
    Gegenstück: {@see \Platform\FoodAlchemist\Livewire\Concerns\HatFreigabeSperre}.
--}}
@props(['sperre' => null])

@if(is_array($sperre))
    @php $label = fn ($k) => $k['name'] . ' (' . \Platform\FoodAlchemist\Exceptions\FreigabeGesperrtException::statusLabel($k) . ')'; @endphp
    <x-fa::notice tone="warn" title="Freigabe gesperrt" class="m-3" data-freigabe-sperre>
        @if(($sperre['freigebbar'] ?? []) === [] && ($sperre['blockiert'] ?? []) === [])
            {{ $sperre['meldung'] ?? '' }}
        @endif
        @if(($sperre['freigebbar'] ?? []) !== [])
            <p>Wird mitfreigegeben: {{ implode(', ', array_map($label, $sperre['freigebbar'])) }}</p>
        @endif
        @if(($sperre['blockiert'] ?? []) !== [])
            <p data-freigabe-blockiert>Blockiert weiter (Platzhalter, Veraltet oder geerbt): {{ implode(', ', array_map($label, $sperre['blockiert'])) }}</p>
        @endif
        <x-slot:actions>
            @if(($sperre['darf'] ?? false) && ($sperre['freigebbar'] ?? []) !== [] && ($sperre['blockiert'] ?? []) === [])
                <x-fa::button size="sm" variant="primary" icon="heroicon-o-check-badge" wire:click="allesMitfreigeben"
                    wire:loading.attr="disabled" wire:target="allesMitfreigeben" data-alles-mitfreigeben>Alles mitfreigeben</x-fa::button>
            @endif
            <x-fa::button size="sm" variant="ghost" wire:click="freigabeSperreSchliessen">Schließen</x-fa::button>
        </x-slot:actions>
    </x-fa::notice>
@endif
