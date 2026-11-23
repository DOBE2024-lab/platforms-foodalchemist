{{-- x-fa::input — Textfeld. numeric: rechtsbündig + gleiche Ziffernbreite. Alle Attribute (wire:model …) gehen durch.
     fehler (98 · 2.4c Nachtrag): Meldungstext oder true → aria-invalid + rote Linie; mit id verweist aria-describedby auf die
     Meldung von x-fa::field (id „<id>-fehler“). --}}
@props(['numeric' => false, 'size' => 'md', 'fehler' => null])
@php($ungueltig = $fehler !== null && $fehler !== false && $fehler !== '')
<input {{ $attributes->merge(['type' => 'text', 'class' => 'fa-control ' . ($size === 'sm' ? 'h-7 text-[length:var(--fa-text-sm)]' : 'h-9 text-[length:var(--fa-text-md)]') . ($numeric ? ' text-right tabular-nums' : '')]) }}@if($ungueltig) aria-invalid="true"@if($attributes->get('id')) aria-describedby="{{ $attributes->get('id') }}-fehler"@endif @endif>
