{{-- x-fa::menu-item — Eintrag in einem faMenu-Menü („Weitere Aktionen", Status …).
     href → Link (target etc. über Attribute), sonst Knopf (wire:click, wire:confirm, :disabled über Attribute).
     danger → rot, mit Trennlinie davor (:trenner="false" lässt die Linie weg; trenner geht auch ohne danger).
     abstand: eng (Standard) · weit (lockere Zeile, Ui::maps()['menuePunktAbstand']).
     :gewaehlt → Auswahl-Eintrag (Status, Fortschritt): Text links, beim aktuellen Wert fett, Akzentfarbe und Haken rechts.
     gesperrt → nicht klickbarer Eintrag (span, aria-disabled), Grund über title.
     Schließt das Menü beim Klick (1.4c); ein eigenes x-on:click läuft vorher. Ausweg für Einträge, die offen bleiben sollen: :schliessen="false". --}}
@props(['icon' => null, 'href' => null, 'danger' => false, 'trenner' => null, 'abstand' => 'eng', 'gewaehlt' => null, 'gesperrt' => false, 'schliessen' => true])
@php
    $abstaende = \Platform\FoodAlchemist\Support\Ui::maps()['menuePunktAbstand'];
    if (! isset($abstaende[$abstand])) {
        throw new \InvalidArgumentException("x-fa::menu-item: abstand=\"{$abstand}\" gibt es nicht (" . implode(' · ', array_keys($abstaende)) . ').');
    }
    $trenner ??= (bool) $danger;
    $farbe = match (true) {
        (bool) $gesperrt => 'text-[var(--fa-ink-3)] cursor-not-allowed',
        (bool) $danger => 'text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]',
        $gewaehlt === true => 'font-semibold text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]',
        default => 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]',
    };
    $klasse = 'flex w-full items-center gap-2 px-3 ' . $abstaende[$abstand] . ' text-left text-[length:var(--fa-text-md)] '
        . ($gewaehlt !== null ? 'justify-between ' : '')
        . $farbe . ' disabled:opacity-50 disabled:pointer-events-none'
        . ($trenner ? ' border-t border-[var(--fa-line)] mt-1 pt-2' : '');
    if ($schliessen && ! $gesperrt) {
        $eigenerKlick = trim((string) $attributes->get('x-on:click', ''), " ;");
        $attributes = $attributes->except('x-on:click')->merge(['x-on:click' => ($eigenerKlick !== '' ? $eigenerKlick . '; ' : '') . 'offen = false']);
    }
    $iconKlasse = 'w-4 h-4 shrink-0 ' . ($danger ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ink-3)]');
@endphp
@if($gesperrt)
    <span role="menuitem" aria-disabled="true" {{ $attributes->merge(['class' => $klasse]) }}>@if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}</span>
@elseif($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->merge(['class' => $klasse]) }}>@if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}@if($gewaehlt)@svg('heroicon-m-check', 'w-4 h-4 shrink-0')@endif</a>
@else
    <button type="button" role="menuitem" {{ $attributes->merge(['class' => $klasse]) }}>@if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}@if($gewaehlt)@svg('heroicon-m-check', 'w-4 h-4 shrink-0')@endif</button>
@endif
