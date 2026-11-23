{{-- x-fa::diaet-quote — Quote einer Diät-Eignung übers Angebot (Konzepter-Deklaration, Zone 1).
     Quote statt Alles-oder-nichts: „3 von 9" ist die Aussage, die ein Kunde hören will. Diät-Fix 98: unbekannte Eignung
     steht daneben, nicht still als „nicht geeignet“. --}}
@props(['schluessel', 'label', 'anzahl', 'n', 'unbekannt' => 0])
@if($anzahl > 0)
    <x-fa::badge :tone="$anzahl === $n ? 'ok' : 'neutral'">{{ $anzahl }} von {{ $n }} {{ $label }}</x-fa::badge>
@endif
@if($unbekannt > 0)
    <x-fa::badge tone="warn" data-deklaration-quote-offen="{{ $schluessel }}">{{ $unbekannt }} {{ $label }} unbekannt</x-fa::badge>
@endif
