{{-- x-fa::segmented — Umschalter aus 2–5 Optionen (Ansicht, Zeitraum, Quelle …) als Spur mit x-fa::segment-Knöpfen (2.4a).
     <x-fa::segmented label="Ansicht">
         @foreach($ansichten as $k => $l)
             <x-fa::segment :an="$ansicht === $k" wire:click="$set('ansicht', '{{ $k }}')">{{ $l }}</x-fa::segment>
         @endforeach
     </x-fa::segmented>
     label: aria-label der Gruppe. breit: Spur als Block (flex) statt inline-flex. Weitere Klassen (self-start, mb-3 …) über class.
     Für Filter-Chips mit Mehrfachauswahl x-fa::choice nehmen; für Formularwerte mit wire:model ebenfalls x-fa::choice. --}}
@props(['label' => null, 'breit' => false])
<div role="group" @if($label) aria-label="{{ $label }}" @endif {{ $attributes->class([$breit ? 'flex' : 'inline-flex', 'p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]']) }}>{{ $slot }}</div>
