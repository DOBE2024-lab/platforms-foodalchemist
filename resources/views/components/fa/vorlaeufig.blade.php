{{-- x-fa::vorlaeufig — Hinweis an einem Konzept-Preis, der nur die Summe der bekannten Positionen ist (Merker price_incomplete,
     CQ price_incomplete). Eine Wortwahl für alle Anzeigestellen; die Zahl daneben bleibt sichtbar. --}}
<x-fa::badge tone="warn" {{ $attributes->merge(['class' => 'ml-1']) }} data-preis-vorlaeufig title="Summe der bekannten Preise: mindestens eine Position hat noch keinen Verkaufspreis">vorläufig</x-fa::badge>
