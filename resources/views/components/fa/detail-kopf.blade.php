{{-- x-fa::detail-kopf — Kopf jedes Detail-Panels (Anatomie Detail-Panels, DESIGN.md).
     Name + Untertitel, Zustands-Chips, GENAU EINE Hauptaktion (Slot „aktion"), alles Weitere im Menü „Weitere Aktionen"
     (Slot „menue", Einträge mit <x-fa::menu-item>; Löschen als letzter Eintrag mit danger).
     Slots: badges · aktion · menue · default (Zusatzzeile unter dem Titel, z. B. „Für Gäste: …"). --}}
@props(['title', 'subtitle' => null])
<header {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} data-fa-detail-kopf>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-[length:var(--fa-text-lg)] font-semibold leading-snug text-[var(--fa-ink)] break-words">{{ $title }}</h2>
            @if($subtitle)<p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $subtitle }}</p>@endif
            {{ $slot }}
        </div>
        @isset($menue)
            <x-fa::menu class="shrink-0" size="sm" breite="60" data-fa-aendern="kopf.menue" :data-fa-aendern-frei="\Platform\FoodAlchemist\Support\HandyLesemodus::frei('kopf.menue')">
                {{ $menue }}
            </x-fa::menu>
        @endisset
    </div>
    @isset($badges)
        <div class="flex flex-wrap items-center gap-1.5">{{ $badges }}</div>
    @endisset
    @isset($aktion)
        <div class="flex flex-wrap items-center gap-2" {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('kopf.aktion') }}>{{ $aktion }}</div>
    @endisset
    @if(isset($aktion) || isset($menue))
        {{-- Handy-Lesemodus (#1003): nur am Handy in Lese-Bereichen sichtbar, dort sind die Aktionen oben aus --}}
        <p class="items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-fa-handy-hinweis>
            @svg('heroicon-m-computer-desktop', 'w-4 h-4 shrink-0') Am Computer bearbeiten
        </p>
    @endif
</header>
