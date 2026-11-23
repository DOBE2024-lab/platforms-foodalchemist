{{-- x-fa::diaet-zeile — Diät-Spalte einer Gericht-Zeile (Konzepter-Deklaration). Diät-Fix 98: unbekannte Eignung als eigenes
     Badge, dann entfällt „nichts Besonderes“. --}}
@props(['diaet' => [], 'offen' => []])
@forelse($diaet as $d)<x-fa::badge tone="ok">{{ $d }}</x-fa::badge>@empty @if($offen === [])<span class="text-[var(--fa-ink-3)]">nichts Besonderes</span>@endif @endforelse
@if($offen !== [])<x-fa::badge tone="neutral" data-deklaration-zeile-offen title="{{ implode(', ', $offen) }}">Diät unbekannt</x-fa::badge>@endif
