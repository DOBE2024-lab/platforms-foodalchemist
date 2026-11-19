{{-- x-fa::text — Text in einer der Rollen aus Ui::maps()['textRolle'] (Welle 1, 1.4a).
     <x-fa::text rolle="muted">3 Befunde</x-fa::text>  ·  <x-fa::text rolle="label" as="dt">EK je kg</x-fa::text>
     Element: h1–h3 für Überschriften, p für body, sonst span; mit as= überschreibbar. --}}
@props(['rolle', 'as' => null])
@php
    $rollen = \Platform\FoodAlchemist\Support\Ui::maps()['textRolle'];
    if (! isset($rollen[$rolle])) {
        throw new \InvalidArgumentException("x-fa::text: unbekannte rolle \"{$rolle}\" (erlaubt: " . implode(', ', array_keys($rollen)) . ')');
    }
    $tag = $as ?? (in_array($rolle, ['h1', 'h2', 'h3'], true) ? $rolle : ($rolle === 'body' ? 'p' : 'span'));
    if (! in_array($tag, ['span', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'dt', 'dd', 'label', 'li', 'strong', 'small', 'figcaption', 'legend', 'td', 'th'], true)) {
        throw new \InvalidArgumentException("x-fa::text: Element as=\"{$tag}\" ist nicht erlaubt");
    }
@endphp
<{{ $tag }} {{ $attributes->class([$rollen[$rolle]]) }}>{{ $slot }}</{{ $tag }}>
