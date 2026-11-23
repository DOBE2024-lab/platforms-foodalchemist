{{-- Planungs-Leitstelle · „Ganze Ausgabe aus einem Brief“ (Spec 85 C1 PR-5, 1:1-Verschiebung aus planung/index.blade.php).
     Zwei Flächen derselben Komponente: ort=board (Panels auf dem Board, Alpine-Zustand fbOpen/skOpen/… vom Parent) und
     ort=reiter (Editor-Reiter, Alpine-Zustand `tab` vom Modal). Beide Instanzen teilen den getippten Brief nicht. --}}
@php
    $hinweisText = 'fa-t-muted';   // gleicher Wert wie der Parent-Hinweistext (Token statt Einzelwert)
    $ausgabeRaster = 'grid grid-cols-1 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4 items-start';
@endphp
{{-- EINE Wurzel außerhalb des @if: Livewire 4 setzt um @if Kommentar-Marker (<!--[if BLOCK]>); als erstes Element würde der
     Kind-Tag leer erkannt („Invalid Livewire child tag name“ beim nächsten Render des Parents). --}}
<div class="contents">
@if($ort === 'board')
    {{-- Spec 42 F1: ein ganzes Foodbook aus einem Brief planen. Rahmen (Gerüst/Struktur) + Inhalte
         entstehen HIER in der Leitstelle; das Foodbook ist reine Ausgabe. --}}
    <x-fa::section x-show="fbOpen" x-cloak icon="heroicon-o-book-open" title="Foodbook aus einem Brief planen"
        description="Struktur und Inhalte entstehen hier in der Leitstelle und landen automatisch im Foodbook." data-foodbook-brief-panel>
        <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="fbOpen = false" /></x-slot:actions>
        <div class="flex flex-col gap-3 max-w-3xl">
            @if($fbOwnerId)
                <x-fa::notice tone="info" data-fb-owner-hinweis>Planung für ein bestehendes Foodbook. Brief eingeben, Struktur und Inhalte entstehen hier und landen dort.</x-fa::notice>
            @else
                <x-fa::field label="Name des Foodbooks" optional>
                    <x-fa::input wire:model="fbTitel" placeholder="zum Beispiel Sommerfest Adler" data-fb-titel />
                </x-fa::field>
            @endif
            <x-fa::field label="Brief">
                <x-fa::textarea wire:model="fbBrief" rows="3" placeholder="Anlass, Gäste, Saison, Niveau, Budget …" data-fb-brief />
            </x-fa::field>
            @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fbBrief'])
            @if($fbMeldung)<x-fa::signal tone="crit" data-fb-meldung>{{ $fbMeldung }}</x-fa::signal>@endif
            <div>
                <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="foodbookAusBrief" wire:loading.attr="disabled" wire:target="foodbookAusBrief" data-fb-erzeugen>
                    <span wire:loading.remove wire:target="foodbookAusBrief">Foodbook erstellen</span>
                    <span wire:loading wire:target="foodbookAusBrief">Wird erstellt …</span>
                </x-fa::button>
            </div>
        </div>
    </x-fa::section>

    {{-- Speisekarte aus Brief (gleiche Bauart wie Foodbook) --}}
    <x-fa::section x-show="skOpen" x-cloak icon="heroicon-o-clipboard-document-list" title="Speisekarte aus einem Brief planen"
        description="Je Gang oder Kategorie entsteht eine Rubrik, die Inhalte landen automatisch in der Karte." data-speisekarte-brief-panel>
        <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="skOpen = false" /></x-slot:actions>
        <div class="flex flex-col gap-3 max-w-3xl">
            <x-fa::field label="Name der Speisekarte" optional>
                <x-fa::input wire:model="skTitel" placeholder="zum Beispiel Herbstkarte" data-landing-sk-titel />
            </x-fa::field>
            <x-fa::field label="Brief">
                <x-fa::textarea wire:model="skBrief" rows="3" placeholder="Anlass, Küchenstil, Saison, Niveau, Preisrahmen …" data-landing-sk-brief />
            </x-fa::field>
            @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'skBrief'])
            @if($skMeldung)<x-fa::signal tone="crit" data-landing-sk-meldung>{{ $skMeldung }}</x-fa::signal>@endif
            <div>
                <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speisekarteAusBrief" wire:loading.attr="disabled" wire:target="speisekarteAusBrief" data-landing-sk-erzeugen>
                    <span wire:loading.remove wire:target="speisekarteAusBrief">Speisekarte erstellen</span>
                    <span wire:loading wire:target="speisekarteAusBrief">Wird erstellt …</span>
                </x-fa::button>
            </div>
        </div>
    </x-fa::section>

    {{-- Speiseplan aus Brief (gleiche Bauart wie Foodbook) --}}
    <x-fa::section x-show="spOpen" x-cloak icon="heroicon-o-calendar-days" title="Speiseplan aus einem Brief planen"
        description="Menülinien und Zyklus entstehen als Standard für die Gemeinschaftsverpflegung, die Zellen werden nach dem Brief gefüllt." data-speiseplan-brief-panel>
        <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="spOpen = false" /></x-slot:actions>
        <div class="flex flex-col gap-3 max-w-3xl">
            <x-fa::field label="Name des Speiseplans" optional>
                <x-fa::input wire:model="spTitel" placeholder="zum Beispiel Kantine Herbst" data-landing-sp-titel />
            </x-fa::field>
            <x-fa::field label="Brief">
                <x-fa::textarea wire:model="spBrief" rows="3" placeholder="Anlass, Saison, Küchenstil, Zyklus (zum Beispiel 4 Wochen), Ernährungsschwerpunkt …" data-landing-sp-brief />
            </x-fa::field>
            @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'spBrief'])
            @if($spMeldung)<x-fa::signal tone="crit" data-landing-sp-meldung>{{ $spMeldung }}</x-fa::signal>@endif
            <div>
                <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speiseplanAusBrief" wire:loading.attr="disabled" wire:target="speiseplanAusBrief" data-landing-sp-erzeugen>
                    <span wire:loading.remove wire:target="speiseplanAusBrief">Speiseplan erstellen</span>
                    <span wire:loading wire:target="speiseplanAusBrief">Wird erstellt …</span>
                </x-fa::button>
            </div>
        </div>
    </x-fa::section>

    {{-- #5 (2026-08-28): Angebot aus Brief (gleiche Bauart wie Speisekarte) --}}
    <x-fa::section x-show="offOpen" x-cloak icon="heroicon-o-document-text" title="Angebot aus einem Brief planen"
        description="Je Position entsteht ein Konzept, das automatisch im Angebot landet. Preise folgen im Angebots-Editor." data-angebot-brief-panel>
        <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="offOpen = false" /></x-slot:actions>
        <div class="flex flex-col gap-3 max-w-3xl">
            @if($offerOwnerId)
                <x-fa::notice tone="info" data-offer-owner-hinweis>Planung für ein bestehendes Angebot. Brief eingeben, die Konzepte entstehen hier und landen dort.</x-fa::notice>
            @else
                <x-fa::field label="Name des Angebots" optional>
                    <x-fa::input wire:model="offerTitel" placeholder="zum Beispiel Sommerfest Firma Adler" data-landing-offer-titel />
                </x-fa::field>
            @endif
            <x-fa::field label="Brief">
                <x-fa::textarea wire:model="offerBrief" rows="3" placeholder="Anlass, Personen, Saison, Niveau, Budget, Servierform …" data-landing-offer-brief />
            </x-fa::field>
            @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'offerBrief'])
            @if($offerMeldung)<x-fa::signal tone="crit" data-landing-offer-meldung>{{ $offerMeldung }}</x-fa::signal>@endif
            <div>
                <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="angebotAusBrief" wire:loading.attr="disabled" wire:target="angebotAusBrief" data-landing-offer-erzeugen>
                    <span wire:loading.remove wire:target="angebotAusBrief">Angebot erstellen</span>
                    <span wire:loading wire:target="angebotAusBrief">Wird erstellt …</span>
                </x-fa::button>
            </div>
        </div>
    </x-fa::section>

    {{-- Format aus Brief (gleiche Bauart wie Angebot): gebrandetes Foodkonzept. --}}
    <x-fa::section x-show="fmtOpen" x-cloak icon="heroicon-o-swatch" title="Format aus einem Brief planen"
        description="Marken-Identität und je Position ein Konzept entstehen hier und landen automatisch im Format." data-format-brief-panel>
        <x-slot:actions><x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="fmtOpen = false" /></x-slot:actions>
        <div class="flex flex-col gap-3 max-w-3xl">
            @if($fmtOwnerId)
                <x-fa::notice tone="info" data-fmt-owner-hinweis>Planung für ein bestehendes Format. Brief eingeben, die Konzepte entstehen hier und landen dort.</x-fa::notice>
            @else
                <x-fa::field label="Name des Formats" optional>
                    <x-fa::input wire:model="fmtTitel" placeholder="zum Beispiel Streetfood Markt" data-landing-fmt-titel />
                </x-fa::field>
            @endif
            <x-fa::field label="Brief">
                <x-fa::textarea wire:model="fmtBrief" rows="3" placeholder="Marke, Anlass, Ausrichtung, Zielgruppe, Niveau, Stationen oder Gänge …" data-landing-fmt-brief />
            </x-fa::field>
            @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fmtBrief'])
            @if($fmtMeldung)<x-fa::signal tone="crit" data-landing-fmt-meldung>{{ $fmtMeldung }}</x-fa::signal>@endif
            <div>
                <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="formatAusBrief" wire:loading.attr="disabled" wire:target="formatAusBrief" data-landing-fmt-erzeugen>
                    <span wire:loading.remove wire:target="formatAusBrief">Format erstellen</span>
                    <span wire:loading wire:target="formatAusBrief">Wird erstellt …</span>
                </x-fa::button>
            </div>
        </div>
    </x-fa::section>
@else
    {{-- AUSGABE-FORMEN (Spec-42-Vollzug): aus einem Brief plant die Leitstelle die ganze Ausgabeform
         (owner-getaggte Voll-Kaskade), die Inhalte landen automatisch dort. Gleiche Bauart überall:
         Auswahl links (schmaler), Brief rechts, ab xl nebeneinander. Jede Karte steckt in einem eigenen
         Wrapper, damit modal-section ohne Abstand-Überschreibung oben bündig sitzt. --}}
    <div wire:key="planung-tab-foodbook" x-show="tab==='foodbook'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
        <div class="{{ $ausgabeRaster }}">
            {{-- Stage 2 (Dominique): ein BESTEHENDES Foodbook wählen → Gerüst planen → Kapitel einzeln
                 erstellen. Oder leer lassen = neues Foodbook aus Brief. --}}
            <div>
                <x-fa::modal-section icon="heroicon-o-book-open" title="Foodbook wählen">
                    <x-slot:actions>
                        <x-fa::badge :tone="$fbOwnerId ? 'accent' : 'neutral'">{{ $fbOwnerId ? 'Bestehendes Foodbook' : 'Neu aus Brief' }}</x-fa::badge>
                    </x-slot:actions>
                    <x-fa::field label="Bestehendes Foodbook" hint="Gewählt: Gerüst planen und die Kapitel unten einzeln füllen. Leer: ein neues Foodbook aus dem Brief.">
                        <x-fa::select wire:model.live="fbOwnerId" data-tab-fb-auswahl>
                            <option value="">Neues Foodbook aus Brief</option>
                            @foreach($fbAuswahl as $fbo)<option value="{{ $fbo->id }}">{{ $fbo->label }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </x-fa::modal-section>
            </div>
            <div>
                <x-fa::modal-section icon="heroicon-o-pencil-square" :title="$fbOwnerId ? 'Gerüst aus dem Brief planen' : 'Neues Foodbook aus dem Brief'">
                    @if(trim((string) ($fbTitel ?? '')) !== '')
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($fbTitel), 32) }}</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">Struktur (Kapitel) und Inhalte entstehen hier in der Leitstelle und landen automatisch im Foodbook.</p>
                        @unless($fbOwnerId)
                            <x-fa::field label="Name des Foodbooks" optional>
                                <x-fa::input wire:model="fbTitel" placeholder="zum Beispiel Sommerfest Adler" data-tab-fb-titel />
                            </x-fa::field>
                        @endunless
                        <x-fa::field label="Brief">
                            <x-fa::textarea wire:model="fbBrief" rows="4" placeholder="Anlass, Gäste, Saison, Niveau, Budget …" data-tab-fb-brief />
                        </x-fa::field>
                        @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fbBrief'])
                        {{-- Leerer Anker für Spec 55 Nachtrag 2 (Entscheid 03): heute bleibt das schlichte Diktat
                             darüber die EINZIGE Diktierfunktion dieses Reiters. Der Anker steht schon, damit der
                             Umzug des Agent-Panels später nur ein Einhängen ist. --}}
                        <div data-planung-agent-slot="foodbook"></div>
                        @if($fbMeldung)<x-fa::signal tone="crit" data-tab-fb-meldung>{{ $fbMeldung }}</x-fa::signal>@endif
                        <div class="flex justify-end">
                            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="foodbookAusBrief" wire:loading.attr="disabled" wire:target="foodbookAusBrief" data-tab-fb-erzeugen>
                                <span wire:loading.remove wire:target="foodbookAusBrief">{{ $fbOwnerId ? 'Gerüst planen und füllen' : 'Foodbook erstellen' }}</span>
                                <span wire:loading wire:target="foodbookAusBrief">Wird erstellt …</span>
                            </x-fa::button>
                        </div>
                    </div>
                </x-fa::modal-section>
            </div>
        </div>

        {{-- Buch-Ebene + Kapitel (aus dem Foodbook-Modul verschobene Planung): sobald ein Foodbook
             GEWÄHLT ist (fbOwnerId) ODER die aktive Session ein Foodbook ist. Die Rails brauchen nur
             die foodbook-id; „Kapitel erstellen" verlangt ein Gerüst (sonst Hinweis in der Rail). --}}
        {{-- $fbAktiv kommt aus AusBrief::render() (gewähltes Foodbook oder Foodbook der aktiven Session). --}}
        {{-- Die beiden Rail-Karten bleiben volle Breite: sie hosten eigene Livewire-Komponenten. --}}
        @if($fbAktiv !== null)
            <x-fa::modal-section icon="heroicon-o-adjustments-horizontal" title="Vorgaben für das ganze Foodbook">
                <livewire:foodalchemist.planung.foodbook-kontext-rail
                    :foodbook-id="$fbAktiv"
                    :key="'fbkontext-'.$fbAktiv" />
            </x-fa::modal-section>
            <x-fa::modal-section icon="heroicon-o-list-bullet" title="Kapitel">
                <livewire:foodalchemist.planung.kapitel-rail
                    :foodbook-id="$fbAktiv"
                    :session-id="$sessionId"
                    :key="'kaprail-'.$fbAktiv" />
            </x-fa::modal-section>
        @endif
    </div>

    <div wire:key="planung-tab-speisekarte" x-show="tab==='speisekarte'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
        <div class="{{ $ausgabeRaster }}">
            {{-- Stage 2 (Parität Speisekarte/Speiseplan): bestehende Speisekarte wählen ODER neu aus Brief. --}}
            <div>
                <x-fa::modal-section icon="heroicon-o-clipboard-document-list" title="Speisekarte wählen">
                    <x-slot:actions>
                        <x-fa::badge :tone="$skOwnerId ? 'accent' : 'neutral'">{{ $skOwnerId ? 'Bestehende Karte' : 'Neu aus Brief' }}</x-fa::badge>
                    </x-slot:actions>
                    <x-fa::field label="Bestehende Speisekarte" hint="Gewählt: Rubriken und Inhalte werden für diese Karte geplant. Leer: eine neue Speisekarte.">
                        <x-fa::select wire:model.live="skOwnerId" data-tab-sk-auswahl>
                            <option value="">Neue Speisekarte aus Brief</option>
                            @foreach($skAuswahl as $sko)<option value="{{ $sko->id }}">{{ $sko->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </x-fa::modal-section>
            </div>
            <div>
                <x-fa::modal-section icon="heroicon-o-pencil-square" :title="$skOwnerId ? 'Gewählte Speisekarte aus dem Brief füllen' : 'Neue Speisekarte aus dem Brief'">
                    @if(trim((string) ($skTitel ?? '')) !== '')
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($skTitel), 32) }}</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">Je Gang oder Kategorie entsteht eine Rubrik, die Inhalte landen automatisch als Positionen in der Karte.</p>
                        @unless($skOwnerId)
                            <x-fa::field label="Name der Speisekarte" optional>
                                <x-fa::input wire:model="skTitel" placeholder="zum Beispiel Herbstkarte" data-tab-sk-titel />
                            </x-fa::field>
                        @endunless
                        <x-fa::field label="Brief">
                            <x-fa::textarea wire:model="skBrief" rows="4" placeholder="Anlass, Küchenstil, Saison, Niveau, Preisrahmen …" data-tab-sk-brief />
                        </x-fa::field>
                        @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'skBrief'])
                        <div data-planung-agent-slot="speisekarte"></div>
                        @if($skMeldung)<x-fa::signal tone="crit" data-tab-sk-meldung>{{ $skMeldung }}</x-fa::signal>@endif
                        <div class="flex justify-end">
                            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speisekarteAusBrief" wire:loading.attr="disabled" wire:target="speisekarteAusBrief" data-tab-sk-erzeugen>
                                <span wire:loading.remove wire:target="speisekarteAusBrief">{{ $skOwnerId ? 'Speisekarte planen und füllen' : 'Speisekarte erstellen' }}</span>
                                <span wire:loading wire:target="speisekarteAusBrief">Wird erstellt …</span>
                            </x-fa::button>
                        </div>
                    </div>
                </x-fa::modal-section>
            </div>
        </div>
    </div>

    <div wire:key="planung-tab-speiseplan" x-show="tab==='speiseplan'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
        <div class="{{ $ausgabeRaster }}">
            {{-- Stage 2 (Parität Speisekarte/Speiseplan): bestehenden Speiseplan wählen ODER neu aus Brief. --}}
            <div>
                <x-fa::modal-section icon="heroicon-o-calendar-days" title="Speiseplan wählen">
                    <x-slot:actions>
                        <x-fa::badge :tone="$spOwnerId ? 'accent' : 'neutral'">{{ $spOwnerId ? 'Bestehender Plan' : 'Neu aus Brief' }}</x-fa::badge>
                    </x-slot:actions>
                    <x-fa::field label="Bestehender Speiseplan" hint="Gewählt: die Zellen (Tag, Mahlzeit, Linie) werden für diesen Plan gefüllt. Leer: ein neuer Speiseplan.">
                        <x-fa::select wire:model.live="spOwnerId" data-tab-sp-auswahl>
                            <option value="">Neuer Speiseplan aus Brief</option>
                            @foreach($spAuswahl as $spo)<option value="{{ $spo->id }}">{{ $spo->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </x-fa::modal-section>
            </div>
            <div>
                <x-fa::modal-section icon="heroicon-o-pencil-square" :title="$spOwnerId ? 'Gewählten Speiseplan aus dem Brief füllen' : 'Neuer Speiseplan aus dem Brief'">
                    @if(trim((string) ($spTitel ?? '')) !== '')
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($spTitel), 32) }}</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">
                            @unless($spOwnerId)Menülinien (Menü 1, Vegetarisch, Dessert) und Zyklus entstehen als Standard für die Gemeinschaftsverpflegung und bleiben im Speiseplan-Editor frei änderbar. @endunless
                            Jede Zelle (Tag, Mahlzeit, Linie) wird nach dem Brief gefüllt.
                        </p>
                        @unless($spOwnerId)
                            <x-fa::field label="Name des Speiseplans" optional>
                                <x-fa::input wire:model="spTitel" placeholder="zum Beispiel Kantine Herbst" data-tab-sp-titel />
                            </x-fa::field>
                        @endunless
                        <x-fa::field label="Brief">
                            <x-fa::textarea wire:model="spBrief" rows="4" placeholder="Anlass, Saison, Küchenstil, Zyklus (zum Beispiel 4 Wochen), Ernährungsschwerpunkt …" data-tab-sp-brief />
                        </x-fa::field>
                        @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'spBrief'])
                        <div data-planung-agent-slot="speiseplan"></div>
                        @if($spMeldung)<x-fa::signal tone="crit" data-tab-sp-meldung>{{ $spMeldung }}</x-fa::signal>@endif
                        <div class="flex justify-end">
                            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="speiseplanAusBrief" wire:loading.attr="disabled" wire:target="speiseplanAusBrief" data-tab-sp-erzeugen>
                                <span wire:loading.remove wire:target="speiseplanAusBrief">{{ $spOwnerId ? 'Speiseplan füllen' : 'Speiseplan erstellen' }}</span>
                                <span wire:loading wire:target="speiseplanAusBrief">Wird erstellt …</span>
                            </x-fa::button>
                        </div>
                    </div>
                </x-fa::modal-section>
            </div>
        </div>
    </div>

    {{-- Angebot als Ausgabe-Reiter: 1 Concept je Position → landen im Angebot (owner_type=offer). --}}
    <div wire:key="planung-tab-angebot" x-show="tab==='angebot'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
        <div class="{{ $ausgabeRaster }}">
            <div>
                <x-fa::modal-section icon="heroicon-o-document-currency-euro" title="Angebot wählen">
                    <x-slot:actions>
                        <x-fa::badge :tone="$offerOwnerId ? 'accent' : 'neutral'">{{ $offerOwnerId ? 'Bestehendes Angebot' : 'Neu aus Brief' }}</x-fa::badge>
                    </x-slot:actions>
                    <x-fa::field label="Bestehendes Angebot" hint="Gewählt: die Positionen entstehen für dieses Angebot. Leer: ein neues Angebot.">
                        <x-fa::select wire:model.live="offerOwnerId" data-tab-offer-auswahl>
                            <option value="">Neues Angebot aus Brief</option>
                            @foreach($offerAuswahl as $ao)<option value="{{ $ao->id }}">{{ $ao->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </x-fa::modal-section>
            </div>
            <div>
                <x-fa::modal-section icon="heroicon-o-pencil-square" :title="$offerOwnerId ? 'Gewähltes Angebot aus dem Brief füllen' : 'Neues Angebot aus dem Brief'">
                    @if(trim((string) ($offerTitel ?? '')) !== '')
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($offerTitel), 32) }}</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">Je Position ein Konzept, die Konzepte landen automatisch im Angebot.</p>
                        @unless($offerOwnerId)
                            <x-fa::field label="Name des Angebots" optional>
                                <x-fa::input wire:model="offerTitel" placeholder="zum Beispiel Sommerfest Firma Adler" data-tab-offer-titel />
                            </x-fa::field>
                        @endunless
                        <x-fa::field label="Brief">
                            <x-fa::textarea wire:model="offerBrief" rows="4" placeholder="Anlass, Personen, Saison, Niveau, Budget, Servierform …" data-tab-offer-brief />
                        </x-fa::field>
                        @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'offerBrief'])
                        <div data-planung-agent-slot="angebot"></div>
                        @if($offerMeldung)<x-fa::signal tone="crit" data-tab-offer-meldung>{{ $offerMeldung }}</x-fa::signal>@endif
                        <div class="flex justify-end">
                            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="angebotAusBrief" wire:loading.attr="disabled" wire:target="angebotAusBrief" data-tab-offer-erzeugen>
                                <span wire:loading.remove wire:target="angebotAusBrief">{{ $offerOwnerId ? 'Angebot füllen' : 'Angebot erstellen' }}</span>
                                <span wire:loading wire:target="angebotAusBrief">Wird erstellt …</span>
                            </x-fa::button>
                        </div>
                    </div>
                </x-fa::modal-section>
            </div>
        </div>
    </div>

    {{-- Format als Ausgabe-Reiter: gebrandetes Foodkonzept, 1 Concept je Position → landen im Format (owner_type=format). --}}
    <div wire:key="planung-tab-format" x-show="tab==='format'" x-cloak class="flex flex-col gap-4 max-w-7xl mx-auto">
        <div class="{{ $ausgabeRaster }}">
            <div>
                <x-fa::modal-section icon="heroicon-o-rectangle-stack" title="Format wählen">
                    <x-slot:actions>
                        <x-fa::badge :tone="$fmtOwnerId ? 'accent' : 'neutral'">{{ $fmtOwnerId ? 'Bestehendes Format' : 'Neu aus Brief' }}</x-fa::badge>
                    </x-slot:actions>
                    <x-fa::field label="Bestehendes Format" hint="Gewählt: die Konzepte entstehen für dieses Format. Leer: ein neues, gebrandetes Format mit Name, Claim und Geschichte aus dem Brief.">
                        <x-fa::select wire:model.live="fmtOwnerId" data-tab-fmt-auswahl>
                            <option value="">Neues Format aus Brief</option>
                            @foreach($fmtAuswahl as $fo)<option value="{{ $fo->id }}">{{ $fo->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </x-fa::modal-section>
            </div>
            <div>
                <x-fa::modal-section icon="heroicon-o-pencil-square" :title="$fmtOwnerId ? 'Gewähltes Format aus dem Brief füllen' : 'Neues Format aus dem Brief'">
                    @if(trim((string) ($fmtTitel ?? '')) !== '')
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($fmtTitel), 32) }}</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">Marken-Identität und eigenständige Konzepte je Position entstehen hier und landen automatisch im Format.</p>
                        @unless($fmtOwnerId)
                            <x-fa::field label="Name des Formats" optional>
                                <x-fa::input wire:model="fmtTitel" placeholder="zum Beispiel Streetfood Markt" data-tab-fmt-titel />
                            </x-fa::field>
                        @endunless
                        <x-fa::field label="Brief">
                            <x-fa::textarea wire:model="fmtBrief" rows="4" placeholder="Marke, Anlass, Ausrichtung, Zielgruppe, Niveau, Stationen oder Gänge …" data-tab-fmt-brief />
                        </x-fa::field>
                        @include('foodalchemist::livewire.planung.partials.diktat', ['ziel' => 'fmtBrief'])
                        <div data-planung-agent-slot="format"></div>
                        @if($fmtMeldung)<x-fa::signal tone="crit" data-tab-fmt-meldung>{{ $fmtMeldung }}</x-fa::signal>@endif
                        <div class="flex justify-end">
                            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="formatAusBrief" wire:loading.attr="disabled" wire:target="formatAusBrief" data-tab-fmt-erzeugen>
                                <span wire:loading.remove wire:target="formatAusBrief">{{ $fmtOwnerId ? 'Format füllen' : 'Format erstellen' }}</span>
                                <span wire:loading wire:target="formatAusBrief">Wird erstellt …</span>
                            </x-fa::button>
                        </div>
                    </div>
                </x-fa::modal-section>
            </div>
        </div>
    </div>
@endif
</div>
