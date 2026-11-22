{{-- x-fa::card — Karte: Fläche mit Rand und Radius (.fa-surface: --fa-surface, 1px --fa-line, --fa-radius-surface) (2.4a).
     <x-fa::card class="p-4 flex flex-col gap-3">…</x-fa::card>
     <x-fa::card as="section" class="overflow-hidden divide-y divide-[var(--fa-line)]">…</x-fa::card>
     as: div (Standard) · section · article · aside · ul · ol · li · details · header · nav · form.
     Innenabstand, Layout, Schatten, Randfarbe (z. B. border-[var(--fa-line-strong)]) über class; sie schlagen .fa-surface.
     Klickbare Karten (a, button, label) bleiben vorerst roh und sind in der Sperrklinke rohe_karte als Bestand erfasst. --}}
@props(['as' => 'div'])
@php
    $erlaubt = ['div', 'section', 'article', 'aside', 'ul', 'ol', 'li', 'details', 'header', 'nav', 'form'];
    if (! in_array($as, $erlaubt, true)) {
        throw new \InvalidArgumentException("x-fa::card as=\"{$as}\" gibt es nicht (" . implode(' · ', $erlaubt) . ').');
    }
@endphp
<{{ $as }} {{ $attributes->class(['fa-surface']) }}>{{ $slot }}</{{ $as }}>
