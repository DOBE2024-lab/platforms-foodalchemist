{{-- x-fa::checkbox — Checkbox in der einen Haken-Optik aus Ui::maps()['haken'] (Welle 1, 1.4c).
     <x-fa::checkbox wire:model.live="nurAktive">Nur aktive</x-fa::checkbox>
     <x-fa::checkbox wire:model="bio" hinweis="Zählt nur zertifizierte Ware">Bio</x-fa::checkbox>
     <x-fa::checkbox wire:model="auswahl" value="{{ $id }}" aria-label="Zeile auswählen" />   (ohne Text: nacktes input)
     Mit Text: class, wire:key, x-show, x-cloak und title gehen an das <label>, alles andere (wire:model, x-model,
     value, name, id, disabled, checked, data-*, @change …) an das <input>. Ohne Text geht alles an das <input>,
     dann ist aria-label, aria-labelledby oder id (für ein <label for>) Pflicht. --}}
@props(['hinweis' => null])
@php
    $haken = \Platform\FoodAlchemist\Support\Ui::maps()['haken'];
    $mitText = trim((string) $slot) !== '' || $hinweis !== null;
    $anLabel = ['class', 'wire:key', 'x-show', 'x-cloak', 'title'];
    // Layout-Standard nur dort, wo der Aufrufer nichts eigenes mitgibt (sonst kollidieren z. B. flex/inline-flex, gap-1.5/gap-2).
    $eigen = ' ' . $attributes->get('class', '') . ' ';
    $fehlt = fn (string $muster) => ! preg_match('/\s(' . $muster . ')(?=\s)/', $eigen);
    $oben = $hinweis !== null || ! $fehlt('items-start');
    $labelStandard = array_values(array_filter([
        $fehlt('flex|inline-flex|grid|inline-grid|block|inline-block|hidden') ? 'inline-flex' : null,
        $fehlt('gap-\S+') ? 'gap-2' : null,
        $fehlt('items-\S+') ? ($oben ? 'items-start' : 'items-center') : null,
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
            <span>{{ $slot }}</span>
        @endif
    </label>
@else
    <input type="checkbox" {{ $attributes->class([$haken]) }}>
@endif
