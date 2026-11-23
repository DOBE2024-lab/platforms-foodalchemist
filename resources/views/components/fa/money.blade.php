{{-- x-fa::money — Geldbetrag, deutsch formatiert, gleiche Ziffernbreite.
     value null → Signal „Preis fehlt" (der FA schätzt nie). per: Einheit-Suffix, z. B. "kg".
     vorlaeufig: der Betrag ist nur die Summe der bekannten Positionen (Konzept-Merker price_incomplete) → Badge „vorläufig“. --}}
@props(['value' => null, 'per' => null, 'decimals' => 2, 'missing' => 'Preis fehlt', 'vorlaeufig' => false])
@if($value === null || $value === '')
    <x-fa::badge tone="crit" {{ $attributes }}>{{ $missing }}</x-fa::badge>
@else
    <span {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }}>{{ number_format((float) $value, $decimals, ',', '.') }}&nbsp;€@if($per)<span class="text-[var(--fa-ink-3)]">/{{ $per }}</span>@endif</span>@if($vorlaeufig)<x-fa::vorlaeufig />@endif
@endif
