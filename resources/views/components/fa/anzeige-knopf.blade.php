{{-- x-fa::anzeige-knopf — Knopf für reine Anzeige-Bedienung (auf/zu, mehr zeigen, Reiter, schließen, Navigation), der im
     Lesemodus bedienbar bleibt. Ein <button> unter <x-fa::lese-sperre> (fieldset disabled) wäre gesperrt; ein <a role="button">
     nicht (Spec-65-Muster). Nur für Aktionen, die NICHTS speichern — Schreibendes bleibt ein x-fa::button unter der Sperre.
     Klick-Handler wie gewohnt (x-on:click / wire:click); das Verhindern der Link-Navigation und Leertaste übernimmt der Baustein.
     Markiert sich als Anzeige-Insel (data-fa-anzeige), damit der DOM-Test (LesemodusDom) ihn findet. --}}
<a href="#" x-on:click.prevent="" x-on:keydown.space.prevent="$el.click()" data-fa-anzeige {{ $attributes->merge(['role' => 'button']) }}>{{ $slot }}</a>
