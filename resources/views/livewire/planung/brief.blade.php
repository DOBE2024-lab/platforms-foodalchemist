{{-- Planungs-Leitstelle · Erstell-Reiter Basisrezept, Gericht und Konzept (Spec 85 C1 PR-4, reine Verschiebung aus
     planung/index.blade.php). EINE Komponente für alle drei Scopes; die drei Reiter-Wrapper mit x-show liegen hier, der
     Alpine-Zustand `tab` kommt vom Modal des Parents. `contents`: die Wrapper bleiben im Layout direkte Kinder wie vorher. --}}
@php
    $handy = \Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy();
    $hinweisText = 'fa-t-muted';   // gleicher Wert wie der Parent-Hinweistext (Token statt Einzelwert)
    $modeLabel = \Platform\FoodAlchemist\Support\Planung\PlanungOptionen::MODUS_LABEL;
    $modeHint = \Platform\FoodAlchemist\Support\Planung\PlanungOptionen::MODUS_HINWEIS;
@endphp
<div class="contents">
        {{-- BASISREZEPT: eigener Reiter mit seinen Leitplanken --}}
        <div wire:key="planung-tab-basisrezept" x-show="tab==='basisrezept'">
            <livewire:foodalchemist.planung.zukauf-ware :session-id="$sessionId" :key="'planung-zukauf-'.($sessionId ?? 'neu')" />
            @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'rezept', 'vk' => false, 'goLabel' => 'Basisrezept', 'goIcon' => 'heroicon-o-beaker'])
        </div>

        {{-- GERICHT: Leitplanken inkl. Verkaufs-Achsen --}}
        <div wire:key="planung-tab-gericht" x-show="tab==='gericht'">
            @include('foodalchemist::livewire.planung.partials.gericht-rezeptur-ziel')
            @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'gericht', 'vk' => true, 'goLabel' => 'Gericht', 'goIcon' => 'heroicon-o-cake'])
        </div>

        {{-- CONCEPT (= das „Menü"): Briefing → KI füllt Leitidee und co. → Zusammenstellung (Pakete/Buffet)
             nach den Leitplanken; braucht Gerichte → kaskadiert nach unten. Läuft NICHT über
             erstellen-tab.blade.php (eigene Plan-Karte), darum hier dieselbe Bauart von Hand.
             Genau EINE Hauptaktion: ohne Plan ist „Plan ausarbeiten lassen" primär, mit Plan das Erstellen. --}}
        <div wire:key="planung-tab-concept" x-show="tab==='concept'" class="flex flex-col gap-4 max-w-7xl mx-auto">
            @php $conceptTitel = trim((string) ($eingabe['concept']['titel'] ?? '')); @endphp
            <x-fa::modal-section icon="heroicon-o-pencil-square" title="Was für ein Konzept soll entstehen?">
                @if($conceptTitel !== '')
                    <x-slot:actions>
                        <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit($conceptTitel, 32) }}</x-fa::badge>
                    </x-slot:actions>
                @endif
                <div class="flex flex-col gap-4">
                    @include('foodalchemist::livewire.planung.partials.schnellstart-chips', ['scope' => 'concept'])
                    <x-fa::field label="Titel" optional>
                        <x-fa::input wire:model="eingabe.concept.titel" placeholder="zum Beispiel CHEFS.CORNER Sommermenü" data-planung-titel />
                    </x-fa::field>
                    <div class="flex flex-col gap-1.5">
                        <x-fa::field label="Briefing" hint="Geht wörtlich in die Erstellung.">
                            <x-fa::textarea wire:model="eingabe.concept.brief" rows="4" placeholder="Anlass, Zielgruppe, Richtung, Pakete oder Buffet, Gänge …" />
                        </x-fa::field>
                        @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'eingabe.concept.brief', 'mitLeitplanken' => 'concept', 'mitRecorder' => false])
                    </div>
                    {{-- Agent-am-Brief: Concept nutzt nicht die geteilte erstellen-tab-Partial, darum das
                         Panel-Mount hier direkt. Kurskorrektur (2026-09-19): Recorder hier aus, das Panel ist
                         die einzige Diktierfunktion (siehe Kommentar in erstellen-tab.blade.php). --}}
                    @if($agentPanelSichtbar)
                        @livewire('foodalchemist.voice-modal', [
                            'planungsSessionId' => $sessionId,
                            'planungScope' => 'concept',
                            'formularRegler' => array_intersect_key($regler['concept'] ?? [], array_flip(\Platform\FoodAlchemist\Livewire\Planung\Index::AGENT_SCHREIBBARE_REGLER)),
                            'formularBrief' => (string) ($eingabe['concept']['brief'] ?? ''),
                        ], key('voice-panel-concept-' . ($sessionId ?? 'keine')))
                    @endif
                    <div class="flex flex-col gap-1.5">
                        <x-fa::choice name="eingabe.concept.creative_mode" :options="$modeLabel" label="Kreativ-Modus" id-prefix="planung-concept" />
                        <p class="{{ $hinweisText }} max-w-2xl">{{ $modeHint[$eingabe['concept']['creative_mode'] ?? 'voll_kreativ'] ?? '' }}</p>
                    </div>
                </div>
            </x-fa::modal-section>

            @include('foodalchemist::livewire.planung.partials.concept-bestehend')

            {{-- KI-Plan (Etappe 2b, geplanter Pfad): arbeitet den Plan vorab aus (Leitidee, Vorteil,
                 Inszenierung, Geschmackswelten + Gänge-Gerüst) und zeigt ihn zur Prüfung, NOCH ohne
                 Gerichte. DF-2 (Spec 41, Entscheid 2026-08-21): der EMPFOHLENE Weg fürs Concepting. --}}
            <x-fa::modal-section icon="heroicon-o-light-bulb" title="Plan ausarbeiten lassen">
                <x-slot:actions>
                    <x-fa::badge tone="accent">empfohlen</x-fa::badge>
                </x-slot:actions>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                    <p class="flex-1 min-w-[16rem] {{ $hinweisText }} max-w-2xl">Die KI arbeitet aus dem Briefing einen vollständigen Entwurf aus: Leitidee, Vorteil, Inszenierung, Geschmackswelten und den Aufbau der Gänge. Du prüfst und korrigierst ihn, <span class="font-semibold text-[var(--fa-ink-2)]">bevor</span> Gerichte entstehen.</p>
                    <x-fa::button :variant="$planConceptId ? 'secondary' : 'primary'" icon="heroicon-o-sparkles" wire:click="kiKopf" :disabled="$laeuft"
                        wire:loading.attr="disabled" wire:target="kiKopf" data-planung-kikopf>
                        <span wire:loading.remove wire:target="kiKopf">{{ $planConceptId ? 'Plan neu ausarbeiten' : 'Plan ausarbeiten lassen' }}</span>
                        <span wire:loading wire:target="kiKopf">Plan wird ausgearbeitet …</span>
                    </x-fa::button>
                </div>
            </x-fa::modal-section>

            {{-- A0/A1: der ausgearbeitete Plan bleibt SICHTBAR + bearbeitbar (Semantik + Menü-Aufbau), kein
                 Wegsprung in den Conceptor. Steht im normalen Fluss vor der Erstell-Leiste (vorher IN der
                 klebenden Leiste und überdeckte so auf dem Laptop fast den ganzen Bildschirm). --}}
            @if($planConceptId)
                @include('foodalchemist::livewire.planung.partials.concept-plan')
            @endif

            @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => 'concept'])

            @include('foodalchemist::livewire.planung.partials.schnellstart-speichern', ['scope' => 'concept'])

            {{-- Erstell-Leiste: klebt unten (Befund „Knopf unter dem Fold"), als EINE niedrige Zeile. --}}
            @if($handy)@include('foodalchemist::livewire.planung.partials.worker-praesenz')@endif
            <x-fa::card class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 border-[var(--fa-line-strong)] px-4 py-3 shadow-lg shadow-black/20" data-planung-erstellen-leiste="">
                <div class="flex-1 min-w-[16rem] flex flex-col gap-1">
                    <p class="flex flex-wrap items-center gap-2 fa-t-h3">
                        @svg('heroicon-o-squares-2x2', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Konzept erstellen
                        @if($conceptTitel !== '')<x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit($conceptTitel, 32) }}</x-fa::badge>@endif
                    </p>
                    @if($planConceptId)
                        {{-- Geplanter Pfad (Etappe 2b): der Plan ist vorbereitet, das Erstellen verwendet ihn statt neu
                             zu erzeugen. „Plan verwerfen" wechselt zurück auf den direkten Weg. --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1" data-planung-plan-bereit>
                            <x-fa::signal tone="ok" icon="heroicon-m-check-badge">Geprüfter Plan liegt vor, die Erstellung verwendet ihn.</x-fa::signal>
                            <button type="button" wire:click="planVerwerfen" @disabled($laeuft)
                                    class="fa-t-label underline underline-offset-2 hover:text-[var(--fa-ink)] disabled:opacity-50">Plan verwerfen</button>
                        </div>
                    @else
                        {{-- DF-2: direkter Weg (sekundär), ohne vorab ausgearbeiteten Plan. --}}
                        <p class="{{ $hinweisText }} {{ $handy ? 'hidden' : '' }}">Direkter Weg ohne Plan: die KI stellt Pakete oder Buffet sofort nach den Leitplanken zusammen, die Gerichte folgen nach der Freigabe. Den Stand siehst du im Reiter „Fortschritt".</p>
                    @endif
                    @unless($handy)@include('foodalchemist::livewire.planung.partials.worker-praesenz')@endunless
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($planConceptId)
                        <x-fa::ki-action action="goKaskade('concept')" variant="primary" icon="heroicon-o-squares-2x2"
                            label="Konzept aus Plan erstellen" busy="Wird gestartet …" flash="Gestartet"
                            :disabled="$laeuft" before="tab='worker'" />
                    @else
                        <x-fa::ki-action action="goKaskade('concept')" variant="ghost" icon="heroicon-o-squares-2x2"
                            label="Ohne Plan direkt erstellen" busy="Wird gestartet …" flash="Gestartet"
                            :disabled="$laeuft" before="tab='worker'" />
                    @endif
                </div>
            </x-fa::card>
        </div>
</div>
