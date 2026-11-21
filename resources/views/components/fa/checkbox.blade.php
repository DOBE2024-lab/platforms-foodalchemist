{{-- x-fa::checkbox — Checkbox in der einen Haken-Optik aus Ui::maps()['haken'] (Welle 1, 1.4c).
     <x-fa::checkbox wire:model.live="nurAktive">Nur aktive</x-fa::checkbox>
     <x-fa::checkbox wire:model="bio" hinweis="Zählt nur zertifizierte Ware">Bio</x-fa::checkbox>
     <x-fa::checkbox wire:model="auswahl" value="{{ $id }}" aria-label="Zeile auswählen" />   (ohne Text: nacktes input)
     Mit Text: class, wire:key, x-show, x-cloak, title und x-bind:title gehen an das <label>, alles andere (wire:model,
     x-model, value, name, id, disabled, checked, data-*, x-on:change …) an das <input>. Ohne Text geht alles an das
     <input>, dann ist aria-label, aria-labelledby oder id (für ein <label for>) Pflicht.
     Alpine-Bindungen immer als x-bind:/x-on: schreiben: ein :attr wertet Blade am Komponenten-Tag als PHP aus. --}}
@props(['hinweis' => null])
@php
    $haken = \Platform\FoodAlchemist\Support\Ui::maps()['haken'];
    $mitText = trim((string) $slot) !== '' || $hinweis !== null;
    $anLabel = ['class', 'wire:key', 'x-show', 'x-cloak', 'title', 'x-bind:title'];
    // Layout-Standard nur dort, wo der Aufrufer nichts eigenes mitgibt (sonst kollidieren z. B. flex/inline-flex, gap-1.5/gap-2).
    $eigen = ' ' . $attributes->get('class', '') . ' ';
    $fehlt = fn (string $muster) => ! preg_match('/\s(' . $muster . ')(?=\s)/', $eigen);
    // Bringt der Aufrufer ein eigenes Display mit, bleibt sein Layout unangetastet (ohne items-* steht der Haken dann oben).
    $eigenesLayout = ! $fehlt('flex|inline-flex|grid|inline-grid|block|inline-block|hidden');
    $oben = $hinweis !== null || ! $fehlt('items-start') || ($eigenesLayout && $fehlt('items-\S+'));
    $labelStandard = array_values(array_filter([
        $eigenesLayout ? null : 'inline-flex',
        $eigenesLayout || ! $fehlt('gap-\S+') ? null : 'gap-2',
        $eigenesLayout || ! $fehlt('items-\S+') ? null : ($oben ? 'items-start' : 'items-center'),
        $fehlt('cursor-\S+') ? 'cursor-pointer' : null,
    ]));
    if (! $mitText && ! $attributes->hasAny(['aria-label', 'aria-labelledby', 'id'])) {
        throw new \InvalidArgumentException('x-fa::checkbox ohne Text braucht aria-label, aria-labelledby oder id');
    }
@endphp
@if($mitText)
    <label {{ $attributes->only($anLabel)->class($labelStandard) }}>
        <input type="checkbox" {{ $attributes->except($anLabel)->class([$haken, 'mt-0.5' => $oben]) }}>
        @if($hinweis !== null)
            <span class="flex flex-col"><span>{{ $slot }}</span><span class="fa-t-muted">{{ $hinweis }}</span></span>
        @else
            {{ $slot }}
        @endif
    </label>
@else
    <input type="checkbox" {{ $attributes->class([$haken]) }}>
@endif
