{{-- x-fa::upload — Datei-Auswahl (input type=file) mit Wählknopf in der Optik von x-fa::button secondary (2.4a).
     <x-fa::upload wire:model="logoUpload" accept="image/*" aria-label="Logo hochladen" />
     <x-fa::upload size="md" wire:model="datei" accept=".csv" id="…" class="w-full" multiple="" />
     size: sm (Standard, Knopf 28px) · md (36px, in Formularen) · handy (44px, Touch). Ui::maps()['uploadGroesse'].
     Layout (block, w-full, min-w-0 …) über class. Ladehinweis bleibt beim Aufrufer (wire:loading wire:target="…").
     Der Kamera-Knopf am Handy (label mit verstecktem input, sr-only) ist kein Upload-Feld und bleibt roh. --}}
@props(['size' => 'sm'])
@php
    $groessen = \Platform\FoodAlchemist\Support\Ui::maps()['uploadGroesse'];
    if (! isset($groessen[$size])) {
        throw new \InvalidArgumentException("x-fa::upload size=\"{$size}\" gibt es nicht (" . implode(' · ', array_keys($groessen)) . ').');
    }
    $klasse = $groessen[$size] . ' text-[var(--fa-ink-2)] cursor-pointer file:rounded-[var(--fa-radius-control)] file:border file:border-solid'
        . ' file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:font-medium file:cursor-pointer hover:file:bg-[var(--fa-hover)]';
@endphp
<input type="file" {{ $attributes->class([$klasse]) }}>
