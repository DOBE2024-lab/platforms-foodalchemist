{{-- Planungs-Leitstelle · Reiter „Fortschritt“ (Spec 85 C1 PR-3, reine Verschiebung aus planung/index.blade.php):
     Lauf-Status und Poll, Ergebnis (Baum · Cluster · Rezept) und Freigabe (Gate 2). Der Wrapper mit x-show und wire:key bleibt im Parent. --}}
@php
    $handy = \Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy();
@endphp
<div class="flex flex-col gap-4">
    {{-- Spec 53 / Paket C: $pollAktiv wird JEDES Render frisch aus DB-Wahrheit abgeleitet
         (Lauf-Status + Step-Phasen), kein gespeichertes Flag. --}}
    @if($pollAktiv)
        @php $kiOffenGesamt = collect($kiWartend ?? [])->sum('anzahl'); @endphp
        {{-- 2.1d: Poll in der schlanken LaufStatus-Komponente; die Fläche rendert nur bei geändertem Stand. --}}
        <livewire:foodalchemist.planung.lauf-status :lauf-ids="$laufId !== null ? [$laufId] : []" :key="'planung-lauf-status-'.($laufId ?? 0)" />
        <div class="flex items-center gap-2 rounded-[var(--fa-radius-surface)] bg-[var(--fa-info-soft)] px-3.5 py-2.5 text-[length:var(--fa-text-md)] text-[var(--fa-info)]">
            @svg($kiOffenGesamt > 0 ? 'heroicon-o-clock' : 'heroicon-o-arrow-path', 'w-4 h-4 shrink-0 ' . ($kiOffenGesamt > 0 ? '' : 'animate-spin'))
            <span @if($kiOffenGesamt > 0) data-planung-wartet-ki @endif>
                @if($kiOffenGesamt > 0)
                    {{-- Per Schnittstelle (MCP) gestartet: die KI-Antwort kommt von außen, die Hintergrund-Erstellung ist nicht gestört. --}}
                    Wartet auf {{ $kiOffenGesamt === 1 ? 'eine KI-Antwort' : $kiOffenGesamt . ' KI-Antworten' }} von außen. Sobald {{ $kiOffenGesamt === 1 ? 'sie' : 'sie alle' }} da {{ $kiOffenGesamt === 1 ? 'ist' : 'sind' }}, geht es automatisch weiter.
                @elseif($laeuft)
                    Läuft. Die Hintergrund-Erstellung arbeitet die Schritte ab …
                @elseif($anreicherungLaeuft)
                    Freigegeben. Beschreibung, Kalkulation und Allergene werden ergänzt …
                @else
                    Ein Schritt im Hintergrund läuft noch …
                @endif
            </span>
        </div>
        @if($hinweis !== null)
            <x-fa::notice tone="warn" data-planung-watchdog>
                {{ $hinweis }}
                <x-slot:actions>
                    {{-- Wiederaufnahme: verwaiste Schritte freiräumen → Lauf wieder handlungsfähig --}}
                    <x-fa::button size="sm" icon="heroicon-o-arrow-path" wire:click="laufFortsetzen" wire:loading.attr="disabled" data-planung-fortsetzen>Abgebrochene Schritte freiräumen</x-fa::button>
                </x-slot:actions>
            </x-fa::notice>
        @endif
    @endif

    {{-- Ergebnis: Status + Baum + Freigabe (Gate 2) --}}
    @if($lauf)
        {{-- Spec 80 Teil D: Baum · Cluster · Rezept. --}}
        @include('foodalchemist::livewire.planung.partials.fortschritt')
    @else
        <x-fa::modal-section icon="heroicon-o-queue-list" title="Fortschritt">
            <x-fa::empty icon="heroicon-o-queue-list" title="Noch nichts gestartet">Starte in „Basisrezept", „Gericht" oder „Konzept" eine Erstellung. Der Fortschritt erscheint dann hier.</x-fa::empty>
        </x-fa::modal-section>
    @endif
</div>
