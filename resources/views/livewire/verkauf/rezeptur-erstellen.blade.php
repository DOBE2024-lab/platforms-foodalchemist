{{-- KI-Assistent am Gericht: „Rezeptur erstellen" für ein Gericht im Entwurf ohne Komponenten. Öffnet die Planung
     (Reiter Gericht) mit Name und Beschreibung als Briefing; gebaut wird dort nach Freigabe des Bauplans, in dieses Gericht. --}}
<div data-vk-rezeptur-erstellen>
    @if($angeboten)
        <x-fa::menu-item icon="heroicon-o-beaker" abstand="weit" :schliessen="false" wire:click="planen" wire:loading.attr="disabled" title="Öffnet die Planung mit Name und Beschreibung dieses Gerichts als Briefing. Dort entsteht zuerst ein Bauplan zur Freigabe, dann Komponenten und Basisrezepte in diesem Gericht. Name, Beschreibung und Einordnung bleiben stehen." data-vk-rezeptur-knopf>Rezeptur erstellen</x-fa::menu-item>
        <p class="px-3 pb-2 fa-t-muted">Dieses Gericht hat noch keine Komponenten. Die Planung baut sie aus Name und Beschreibung.</p>
        @if($fehler !== null)
            <div class="px-3 pb-2"><x-fa::notice tone="crit" data-vk-rezeptur-fehler>{{ $fehler }}</x-fa::notice></div>
        @endif
        <div class="my-1 border-t border-[var(--fa-line)]"></div>
    @endif
</div>
