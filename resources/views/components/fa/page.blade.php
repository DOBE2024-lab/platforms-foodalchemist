{{-- x-fa::page — Inhaltsrahmen einer Seite oder eines Detail-Panels: Grund (--fa-ground), volle Höhe, Abstand 16px, Abschnitte mit 20px Luft (2.4a).
     <x-fa::page data-…>…</x-fa::page>                         Standard: p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]
     <x-fa::page :eingebettet="$embedded">                     im Editor eingebettet: nur flex flex-col gap-3 (kein eigener Grund)
     <x-fa::page :abstand="false" class="flex flex-col gap-4"> Handy-Seiten mit eigenem Abstand: nur Grund + volle Höhe
     Seitenkopf: x-fa::page-header bzw. x-foodalchemist::shell.page-navbar bleiben eigene Bausteine. --}}
@props(['eingebettet' => false, 'abstand' => true])
<div {{ $attributes->class([
    'flex flex-col gap-3' => $eingebettet,
    'p-4 flex flex-col gap-5' => ! $eingebettet && $abstand,
    'min-h-full bg-[var(--fa-ground)]' => ! $eingebettet,
]) }}>{{ $slot }}</div>
