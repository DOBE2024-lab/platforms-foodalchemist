{{-- x-fa::table — Tabelle in der Optik .fa-table (Kopfzeile, Zeilenlinien, Hover, .num rechtsbündig) (2.4a).
     <x-fa::table>…<thead>…</thead><tbody>…</tbody></x-fa::table>
     <x-fa::table compact class="min-w-[760px]" data-…>    compact: engere Zeilen (.fa-table--compact).
     Die Scroll-Hülle (overflow-x-auto, -mx-4, max-h …) bleibt beim Aufrufer, weil sie je Ort verschieden ist.
     Zahlen-Spalten: <th class="num"> / <td class="num">. Ausgewählte Zeile: <tr aria-selected="true">. --}}
@props(['compact' => false])
<table {{ $attributes->class(['fa-table', 'fa-table--compact' => $compact]) }}>{{ $slot }}</table>
