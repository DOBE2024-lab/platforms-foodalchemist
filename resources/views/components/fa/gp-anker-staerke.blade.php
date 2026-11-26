@props(['anker'])

<x-fa::select size="sm" aria-label="Aromastärke von {{ $anker->display_de }}"
    wire:change="gpAnkerStaerkeSetzen({{ $anker->id }}, $event.target.value)">
    <option value="" @selected($anker->staerke === null)>Offen</option>
    @foreach(\Platform\FoodAlchemist\Services\Pairing\PairingService::GP_ANKER_STAERKEN as $staerke)
        <option value="{{ $staerke }}" @selected($anker->staerke === $staerke)>{{ ucfirst($staerke) }}</option>
    @endforeach
</x-fa::select>
