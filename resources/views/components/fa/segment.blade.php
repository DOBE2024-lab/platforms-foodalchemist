{{-- x-fa::segment — Option in x-fa::segmented (2.4a). an: aktiv (weiße Fläche, Schatten, aria-pressed="true").
     an-alpine="ausdruck": Zustand lebt in Alpine (x-bind:class / x-bind:aria-pressed statt Server-Wert).
     size: sm (Standard, 28px) · md (32px) · handy (44px, füllt die Spur). Ui::maps()['segmentGroesse'].
     Klick über wire:click / x-on:click; weitere Klassen (flex-1, truncate …) über class. --}}
@props(['an' => false, 'anAlpine' => null, 'size' => 'sm'])
@php
    $groessen = \Platform\FoodAlchemist\Support\Ui::maps()['segmentGroesse'];
    if (! isset($groessen[$size])) {
        throw new \InvalidArgumentException("x-fa::segment size=\"{$size}\" gibt es nicht (" . implode(' · ', array_keys($groessen)) . ').');
    }
    $anKlasse = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $ausKlasse = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $basis = $groessen[$size] . ' rounded-[5px] font-medium transition-colors';
@endphp
@if($anAlpine !== null)
<button type="button" {{ $attributes->class([$basis])->merge([
    'x-bind:class' => "({$anAlpine}) ? '{$anKlasse}' : '{$ausKlasse}'",
    'x-bind:aria-pressed' => "({$anAlpine}) ? 'true' : 'false'",
]) }}>{{ $slot }}</button>
@else
<button type="button" aria-pressed="{{ $an ? 'true' : 'false' }}" {{ $attributes->class([$basis, $anKlasse => $an, $ausKlasse => ! $an]) }}>{{ $slot }}</button>
@endif
