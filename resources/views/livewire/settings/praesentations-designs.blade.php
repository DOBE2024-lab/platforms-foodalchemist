{{-- Präsentations-Designs (Spec 43): Struktur-Builder fürs digitale Kundenbuch.
     Grundanordnung: links Designs + Bausteine · Mitte Kopf + Live-Vorschau · rechts Struktur, Block-Einstellungen, Farben/Schrift, eigenes CSS.
     Häufigste Aufgabe: ein Design wählen, anpassen, in der Vorschau prüfen, speichern.
     Farben und Schriften sind KUNDEN-BRANDING (Daten): Farbwähler + gespeicherte Werte bleiben unverändert, nur ihre Darstellung ist neu.
     Spalten richten sich nach der verfügbaren Breite (Container-Abfragen), nicht nach dem Fenster. --}}
@php
    $hakenZeile = 'flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer';
    $hilfe = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $menuPunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuGefahr = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $formen = ['foodbook' => 'Foodbook', 'angebot' => 'Angebot', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'konfigurator' => 'Konfigurator'];
    $farbFelder = ['primary' => 'Hauptfarbe', 'accent' => 'Akzentfarbe', 'bg' => 'Hintergrund', 'text' => 'Schriftfarbe'];
@endphp

<div class="@container flex flex-col gap-4 min-w-0" data-fa-designs-builder>
    {{-- Feedback --}}
    {{-- Spec 65: „Bearbeiten" (am Speichern-Knopf) sperrt den Bereich für das Team; Design wählen, Vorschau und
         Block anklicken bleiben im Lesemodus bedienbar, alles Ändernde liegt in gesperrten <fieldset>s. --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @if($status)
        <x-fa::notice tone="ok" data-fa-designs-status>{{ $status }}</x-fa::notice>
    @endif
    @if($fehler)
        <x-fa::notice tone="crit" data-fa-designs-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    {{-- Drei Spalten schon ab Laptop-Breite mit Sidebar (Container ab 56rem): Einstellungen links und rechts, Vorschau in der
         Mitte schmaler und klebend (Dominique 2026-11-03; vorher erst ab 72rem, darunter wanderte die rechte Spalte unter die Vorschau). --}}
    <div class="grid grid-cols-1 gap-4 items-start @2xl:grid-cols-[13rem_minmax(0,1fr)] @4xl:grid-cols-[12rem_minmax(0,1fr)_18rem] @6xl:grid-cols-[15rem_minmax(0,1fr)_minmax(0,23rem)]">
        {{-- ── Links: Designs + Bausteine ─────────────────────────────── --}}
        <aside class="flex flex-col gap-4 min-w-0">
            <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
            <x-fa::section title="Meine Designs" icon="heroicon-o-swatch">
                <div class="flex flex-col gap-0.5 -mx-1.5">
                    @forelse($designs as $d)
                        <div class="flex items-center gap-1 min-w-0" wire:key="design-{{ $d['id'] }}">
                            <a href="#" role="button" wire:click.prevent="waehlen({{ $d['id'] }})" aria-pressed="{{ $selectedId === $d['id'] ? 'true' : 'false' }}"
                                class="flex-1 min-w-0 flex items-center gap-1.5 text-left text-[length:var(--fa-text-md)] px-2 py-1.5 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $selectedId === $d['id'] ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}">
                                <span class="truncate">{{ $d['name'] }}</span>
                                @unless($d['owned'])<x-fa::badge class="shrink-0">Geerbt</x-fa::badge>@endunless
                            </a>
                            <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $d['name'] }}" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                <div class="hidden w-48 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="duplizieren({{ $d['id'] }})" class="{{ $menuPunkt }}">
                                        @svg('heroicon-m-document-duplicate', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                        Design duplizieren
                                    </button>
                                    @if($d['owned'])
                                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                        <button type="button" role="menuitem" x-on:click="offen = false" wire:click="loeschen({{ $d['id'] }})" wire:confirm="Design {{ $d['name'] }} löschen?" class="{{ $menuGefahr }}">
                                            @svg('heroicon-m-trash', 'w-4 h-4')
                                            Design löschen
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-fa::empty icon="heroicon-o-swatch" title="Noch kein eigenes Design" compact>Unten eine Vorlage wählen und daraus starten.</x-fa::empty>
                    @endforelse
                </div>
                <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                    <p class="fa-t-label">Neu aus Vorlage</p>
                    <div class="flex flex-wrap gap-1.5">
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('editorial')">Editorial</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('angebot')">Angebot</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('menu')">Speisekarte</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('kiosk')">Kiosk</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('konfigurator')">Konfigurator</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('konfigurator_dunkel')">Konfigurator dunkel</x-fa::button>
                    </div>
                </div>
            </x-fa::section>

            <x-fa::section title="Block hinzufügen" icon="heroicon-o-plus-circle">
                <div class="grid gap-1.5 grid-cols-[repeat(auto-fill,minmax(min(100%,8.5rem),1fr))]">
                    @foreach($blockTypen as $t)
                        <button type="button" wire:click="blockHinzufuegen('{{ $t }}')" wire:key="add-{{ $t }}"
                            class="flex items-center gap-1.5 min-w-0 text-left text-[length:var(--fa-text-sm)] px-2 py-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] text-[var(--fa-ink)] hover:bg-[var(--fa-accent-soft)] hover:border-[var(--fa-accent-line)] transition-colors duration-150" data-fa-add-block="{{ $t }}">
                            @svg('heroicon-m-plus', 'w-3.5 h-3.5 shrink-0 text-[var(--fa-ink-3)]')
                            <span class="truncate">{{ $blockLabels[$t] ?? $t }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="{{ $hilfe }}">Alle Blöcke zeigen nur Daten für den Gast. Einkaufspreise und Interna erscheinen nie.</p>
            </x-fa::section>
            </fieldset>
        </aside>

        {{-- ── Mitte: Kopf + Live-Vorschau ──────────────────────────── --}}
        <section class="flex flex-col gap-3 min-w-0 @4xl:sticky @4xl:top-4">
            <div class="flex flex-wrap items-end gap-2">
                <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
                <x-fa::field label="Name des Designs" for="design-name" class="flex-1 min-w-[12rem]">
                    <x-fa::input id="design-name" wire:model="name" placeholder="z. B. Hochzeit Sommer" data-fa-design-name />
                </x-fa::field>
                </fieldset>
                @if($ungespeichert)
                    <x-fa::badge tone="warn" icon="heroicon-m-pencil" class="mb-2">Ungespeichert</x-fa::badge>
                @endif
                {{-- Spec 65: erst „Bearbeiten", dann Abbrechen/Speichern; Speichern gibt den Bereich frei --}}
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-fa-design-save>
                        {{ $selectedId ? 'Design speichern' : 'Design anlegen' }}
                    </x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>

            {{-- Bug-Runde 2026-09-17 #2: Die Vorschau zeigt den Editor-Zustand, der Kundenlink den
                 gespeicherten + veröffentlichten. Ungespeichert sah man die Änderung also nur hier. --}}
            @if($ungespeichert)
                <x-fa::notice tone="warn" title="Ungespeicherte Änderungen" data-fa-design-dirty>
                    Die Vorschau zeigt sie bereits. Im Kundenlink erscheinen sie erst nach dem Speichern und einem erneuten Veröffentlichen der Ausgabe.
                </x-fa::notice>
            @endif

            {{-- Form-Scoping: für welche Ausgabeformen dieses Design im Picker auftaucht (leer = alle). --}}
            <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
            <div class="flex flex-col gap-1" data-fa-output-types>
                <x-fa::choice name="outputTypes" multiple label="Gilt für" :options="$formen" />
                <p class="{{ $hilfe }}">Nichts gewählt heißt: für alle Formen.</p>
            </div>
            </fieldset>

            <div class="flex flex-wrap items-end gap-3">
                <x-fa::choice name="previewType" label="Vorschau als" :options="$formen" />
                @if(in_array($previewType, ['foodbook', 'angebot'], true))
                    <x-fa::checkbox class="{{ $hakenZeile }} pb-2" title="Gespeichert im Design: Foodbook und Angebot bekommen im veröffentlichten Link den Knopf „EK einblenden“. Jeder mit dem Link kann umschalten. Ohne Haken kein Knopf. Wirkt nach dem nächsten Veröffentlichen." wire:model.live="tokens.ek_im_link" data-fa-vorschau-ek>EK-Ansicht im Link</x-fa::checkbox>
                @endif
                <x-fa::field label="Quelle" for="design-vorschau-quelle" class="flex-1 min-w-[12rem]">
                    <x-fa::select id="design-vorschau-quelle" wire:model.live="previewSourceId" placeholder="Quelle wählen">
                        @foreach($quellenOptionen as $opt)
                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
            </div>

            <div class="fa-surface overflow-hidden">
                @if($vorschauHtml !== null)
                    <div class="flex items-center gap-2 px-3 py-2 border-b border-[var(--fa-line)] text-[length:var(--fa-text-sm)] {{ $ungespeichert ? 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]' : 'text-[var(--fa-ink-3)]' }}">
                        @svg($ungespeichert ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-eye', 'w-4 h-4 shrink-0')
                        {{ $ungespeichert ? 'Vorschau des ungespeicherten Stands, noch nicht im Kundenlink.' : 'Vorschau des gespeicherten Stands.' }}
                    </div>
                    <iframe class="block w-full h-[min(640px,calc(100dvh-12rem))] min-h-[24rem] border-0 bg-[var(--fa-surface)]" srcdoc="{{ $vorschauHtml }}" title="Live-Vorschau" data-fa-preview-frame></iframe>
                @else
                    <x-fa::empty icon="heroicon-o-eye" title="Vorschau erscheint hier" class="h-[min(640px,calc(100dvh-12rem))] min-h-[24rem] justify-center">
                        Oben Form und Quelle wählen, dann zeigt die Vorschau das Design mit echten Daten.
                    </x-fa::empty>
                @endif
            </div>
        </section>

        {{-- ── Rechts: Struktur + Block-Einstellungen + Farben/Schrift ────────────────────── --}}
        <aside class="grid gap-4 items-start min-w-0 @2xl:col-span-2 @2xl:grid-cols-2 @4xl:col-span-1 @4xl:grid-cols-1">
            <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
            <x-fa::section title="Aufbau" icon="heroicon-o-queue-list" :meta="count($layout) . ' ' . (count($layout) === 1 ? 'Block' : 'Blöcke')" x-data="{ from: null }">
                <div class="flex flex-col gap-1" data-fa-structure>
                    @forelse($layout as $i => $b)
                        <div draggable="true"
                             @dragstart="from = {{ $i }}"
                             @dragover.prevent
                             @drop.prevent="$wire.bloeckeNachDrop(from, {{ $i }}); from = null"
                             wire:key="block-{{ $i }}-{{ $b['block_type'] }}"
                             class="flex items-center gap-1 min-w-0 pl-1.5 pr-1 py-0.5 rounded-[var(--fa-radius-control)] border cursor-move {{ $selectedBlockIndex === $i ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                            @svg('heroicon-m-bars-3', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                            <a href="#" role="button" wire:click.prevent="blockWaehlen({{ $i }})" class="flex-1 min-w-0 truncate text-left text-[length:var(--fa-text-md)] py-1 {{ $selectedBlockIndex === $i ? 'font-medium text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}" data-fa-block="{{ $b['block_type'] }}">
                                {{ $aufbauTitel[$i] ?? ($blockLabels[$b['block_type']] ?? $b['block_type']) }}
                            </a>
                            <x-fa::icon-button icon="heroicon-m-chevron-up" label="Block nach oben" size="sm" wire:click="blockVerschieben({{ $i }}, -1)" :disabled="$i === 0" class="disabled:opacity-40" />
                            <x-fa::icon-button icon="heroicon-m-chevron-down" label="Block nach unten" size="sm" wire:click="blockVerschieben({{ $i }}, 1)" :disabled="$i === count($layout) - 1" class="disabled:opacity-40" />
                            <x-fa::icon-button icon="heroicon-m-x-mark" label="Block entfernen" size="sm" tone="danger" wire:click="blockEntfernen({{ $i }})" />
                        </div>
                    @empty
                        <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Blöcke" compact>Links unter „Block hinzufügen“ den ersten Block wählen.</x-fa::empty>
                    @endforelse
                </div>
                @if(count($layout) > 1)
                    <p class="{{ $hilfe }}">Reihenfolge per Ziehen oder mit den Pfeilen ändern. Block anklicken zeigt seine Einstellungen.</p>
                @endif
            </x-fa::section>

            {{-- Einstellungen für den gewählten Block --}}
            @if($selectedBlockIndex !== null && isset($layout[$selectedBlockIndex]))
                @php
                    $sb = $layout[$selectedBlockIndex];
                    $bt = $sb['block_type'];
                    $i = $selectedBlockIndex;
                @endphp
                <x-fa::section :title="'Block: ' . ($aufbauTitel[$i] ?? ($blockLabels[$bt] ?? $bt))" icon="heroicon-o-adjustments-horizontal" data-fa-style-panel>
                    @if(in_array($bt, ['chapter_loop', 'dish_list'], true))
                        @if($bt === 'chapter_loop')
                            {{-- Kapitel-Auswahl (2026-11-19): alle, ein Bereich oder die übrigen. Gilt für Foodbook, Angebot und Speisekarte. --}}
                            @php $auswahl = $sb['style']['kapitel_auswahl'] ?? 'alle'; @endphp
                            <x-fa::choice name="layout.{{ $i }}.style.kapitel_auswahl" label="Welche Kapitel" :options="['alle' => 'Alle', 'bereich' => 'Bestimmte', 'rest' => 'Übrige']" />
                            @if($auswahl === 'bereich')
                                <div class="flex items-end gap-2" data-fa-kapitel-bereich>
                                    <x-fa::field label="Von Kapitel" for="design-kapitel-von">
                                        <x-fa::input id="design-kapitel-von" type="number" min="1" max="99" wire:model.blur="layout.{{ $i }}.style.kapitel_von" numeric class="max-w-[6rem]" />
                                    </x-fa::field>
                                    <x-fa::field label="Bis Kapitel" for="design-kapitel-bis">
                                        <x-fa::input id="design-kapitel-bis" type="number" min="1" max="99" wire:model.blur="layout.{{ $i }}.style.kapitel_bis" numeric placeholder="Ende" class="max-w-[6rem]" />
                                    </x-fa::field>
                                </div>
                                <p class="{{ $hilfe }}">Gezählt werden die Hauptkapitel ab 1, Unterkapitel gehören dazu. Für ein einzelnes Kapitel „von“ und „bis“ gleich setzen, „bis“ leer heißt bis zum letzten Kapitel. Das Design gilt für viele Dokumente, deshalb zählt die Position, nicht der Name.</p>
                            @elseif($auswahl === 'rest')
                                <p class="{{ $hilfe }}">Zeigt alle Kapitel, die kein anderer Kapitel-Block mit „Bestimmte“ zeigt.</p>
                            @endif
                        @endif
                        <div class="flex flex-col gap-2">
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_price">Preise zeigen</x-fa::checkbox>
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_codes">Allergen-Kennzeichen zeigen</x-fa::checkbox>
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_dish_photos">Gericht-Fotos zeigen</x-fa::checkbox>
                            @if($bt === 'chapter_loop')
                                <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_chapter_image">Kapitel-Bilder zeigen</x-fa::checkbox>
                                <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_kapitel_preis" data-fa-kapitel-preis>Preis je Kapitel im Kapitelkopf</x-fa::checkbox>
                            @endif
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_rules">Trennlinien zwischen den Gerichten</x-fa::checkbox>
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_block_header">Konzeptname über der Gerichtsliste</x-fa::checkbox>
                            @if($bt === 'chapter_loop')
                                <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_kicker">Kleine Zeile über dem Kapiteltitel</x-fa::checkbox>
                            @endif
                            @if($bt === 'chapter_loop' && ($sb['style']['show_kapitel_preis'] ?? false))
                                <x-fa::field label="Text hinter dem Kapitelpreis" for="design-kapitel-preis-label" hint="Leer heißt „pro Person“. Der Preis ist netto, bei Formaten mit Alternativen die Spanne von bis. Er erscheint nur, wenn das Dokument mit Preisen veröffentlicht wird.">
                                    <x-fa::input id="design-kapitel-preis-label" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kapitel_preis_label" placeholder="pro Person" />
                                </x-fa::field>
                            @endif
                        </div>
                        <x-fa::choice name="layout.{{ $i }}.style.konzept_preis" label="Preis am Konzept oder Paket" :options="['zeile' => 'In der Zeile (Standard)', 'betont' => 'Groß unter dem Namen', 'ende' => 'Groß nach den Gerichten']" />
                        @if(in_array($sb['style']['konzept_preis'] ?? 'zeile', ['betont', 'ende'], true))
                            <x-fa::field label="Text hinter dem Konzeptpreis" for="design-konzept-preis-label" hint="Leer heißt „pro Person“. Gilt nur für Preise pro Gast.">
                                <x-fa::input id="design-konzept-preis-label" wire:model.live.debounce.400ms="layout.{{ $i }}.style.konzept_preis_label" placeholder="pro Person" />
                            </x-fa::field>
                        @endif
                        <x-fa::choice name="layout.{{ $i }}.style.dish_columns" label="Gerichte nebeneinander" :options="['1' => '1 Spalte (Standard)', '2' => '2 Spalten']" />
                        @if($bt === 'chapter_loop')
                            <x-fa::field label="Bezeichnung Kapitel" for="design-kicker-haupt">
                                <x-fa::input id="design-kicker-haupt" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_haupt" placeholder="Kapitel" />
                            </x-fa::field>
                            <x-fa::field label="Bezeichnung Unterkapitel" for="design-kicker-unter" hint="Die kleine Zeile über dem Titel. Leer heißt „Kapitel“ bzw. „Abschnitt“. Für Angebote zum Beispiel „Leistung“.">
                                <x-fa::input id="design-kicker-unter" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_unter" placeholder="Abschnitt" />
                            </x-fa::field>
                            <x-fa::choice name="layout.{{ $i }}.style.chapter_image_fit" label="Kapitelbild"
                                :options="['auto' => 'Automatisch', 'fotoband' => 'Fotoband', 'ganz' => 'Ganz zeigen']" />
                            <p class="fa-t-muted">Fotoband füllt die Breite und schneidet zu. Ganz zeigen lässt das Bild unbeschnitten an der linken Textkante, gut für Logos und Grafiken. Automatisch zeigt transparente Bilder ganz, Fotos als Fotoband.</p>
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_chapter_logo">Logo je Kapitel zeigen</x-fa::checkbox>
                            <x-fa::choice name="layout.{{ $i }}.style.chapter_logo_position" label="Kapitel-Logo"
                                :options="['unten' => 'Unten im Kapitel', 'oben' => 'Über dem Kapiteltitel']" />
                            <p class="fa-t-muted">Das Logo lädst du im Kapitel selbst hoch: im Foodbook, im Angebot oder in der Speisekarten-Rubrik.</p>
                        @endif
                    @elseif($bt === 'cover')
                        <div class="flex flex-col gap-2">
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_cover_image">Titelbild zeigen</x-fa::checkbox>
                            <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="layout.{{ $i }}.style.show_logo">Logo zeigen</x-fa::checkbox>
                        </div>
                        <x-fa::field label="Zeile über dem Titel" for="design-cover-kicker" hint="Zum Beispiel Anlass, Datum oder Gästezahl. Leer heißt „Kulinarisches Angebot“.">
                            <x-fa::input id="design-cover-kicker" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_text" placeholder="Kulinarisches Angebot" />
                        </x-fa::field>
                        @php $ovVal = isset($sb['style']['overlay']) && is_numeric($sb['style']['overlay']) ? (int) $sb['style']['overlay'] : null; @endphp
                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="fa-t-label">Titelbild abdunkeln</span>
                                <span class="fa-t-muted tabular-nums">{{ $ovVal === null ? 'Standard' : $ovVal . ' %' }}</span>
                            </div>
                            <input type="range" min="0" max="60" step="5" value="{{ $ovVal ?? 35 }}"
                                   @change="$wire.stilSetzen({{ $i }}, 'overlay', parseInt($event.target.value))"
                                   class="accent-[var(--fa-accent)]" aria-label="Titelbild abdunkeln in Prozent" data-fa-cover-overlay>
                            <p class="{{ $hilfe }}">Macht Schrift und Logo auf hellen oder unruhigen Titelbildern lesbar.</p>
                        </div>
                        <x-fa::choice name="layout.{{ $i }}.style.cover_fit" label="Titelbild-Darstellung" :options="['cover' => 'Füllen (Ausschnitt)', 'contain' => 'Ganz zeigen']" />
                        {{-- Bug-Runde 2026-09-17: frei einstellbar statt drei fester Stufen.
                             Der Wert ist % der Fensterhöhe — Alt-Designs mit klein/mittel/groß
                             werden beim Anzeigen auf ihren Zahlenwert gespiegelt. --}}
                        @php
                            $chVal = $sb['style']['cover_height'] ?? 'gross';
                            $chVh = is_numeric($chVal) ? (int) $chVal : (['klein' => 46, 'mittel' => 64, 'gross' => 88][$chVal] ?? 88);
                        @endphp
                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="fa-t-label">Titelbild-Höhe</span>
                                <span class="fa-t-muted tabular-nums">{{ $chVh }} % der Bildschirmhöhe</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="range" min="10" max="100" step="1" value="{{ $chVh }}"
                                       @change="$wire.stilSetzen({{ $i }}, 'cover_height', parseInt($event.target.value))"
                                       class="flex-1 accent-[var(--fa-accent)]" aria-label="Titelbild-Höhe in Prozent" data-fa-cover-height-range>
                                <input type="number" min="10" max="100" step="1" value="{{ $chVh }}"
                                       @change="$wire.stilSetzen({{ $i }}, 'cover_height', parseInt($event.target.value))"
                                       class="fa-control h-9 w-20 text-right tabular-nums text-[length:var(--fa-text-md)]" aria-label="Titelbild-Höhe in Prozent" data-fa-cover-height-num>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 mt-1">
                                <label for="design-cover-max" class="fa-t-label">Höchstens</label>
                                <input id="design-cover-max" type="number" min="120" max="2000" step="10" placeholder="ohne Grenze"
                                       value="{{ $sb['style']['cover_height_max_px'] ?? '' }}"
                                       @change="$wire.stilSetzen({{ $i }}, 'cover_height_max_px', $event.target.value === '' ? null : parseInt($event.target.value))"
                                       class="fa-control h-9 w-32 text-right tabular-nums text-[length:var(--fa-text-md)]" data-fa-cover-height-max>
                                <span class="{{ $hilfe }}">Pixel, begrenzt die Höhe auf großen Bildschirmen</span>
                            </div>
                        </div>
                    @elseif(in_array($bt, ['text', 'heading'], true))
                        <x-fa::field label="Text" for="design-block-text">
                            <x-fa::textarea id="design-block-text" wire:model.blur="layout.{{ $i }}.style.text" rows="4" />
                        </x-fa::field>
                    @elseif($bt === 'image')
                        <div class="flex flex-col gap-1.5">
                            <span class="fa-t-label">Bild</span>
                            <div class="flex items-center gap-3 flex-wrap">
                                @if($blockImageUrl)
                                    <img src="{{ $blockImageUrl }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                    <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="blockBildEntfernen({{ $i }})">Bild entfernen</x-fa::button>
                                @endif
                                <input type="file" wire:model="blockImageUpload" accept="image/*" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] min-w-0">
                                <x-fa::signal tone="info" icon="heroicon-m-arrow-path" wire:loading wire:target="blockImageUpload">Wird hochgeladen</x-fa::signal>
                            </div>
                            @error('blockImageUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                            <p class="{{ $hilfe }}">Freie Bildstrecke, unabhängig von Konzept und Kapitel.</p>
                        </div>
                        <x-fa::choice name="layout.{{ $i }}.style.quelle" label="Bild kommt aus" :options="['design' => 'Diesem Design', 'angebot_1' => 'Angebot: Bild 1', 'angebot_2' => 'Angebot: Bild 2', 'angebot_3' => 'Angebot: Bild 3']" />
                        <p class="{{ $hilfe }}">„Angebot: Bild 1–3“ zeigt das Bild, das im jeweiligen Angebot unter Branding und Präsentation hinterlegt ist. Fehlt es dort, bleibt der Block leer.</p>
                        <x-fa::choice name="layout.{{ $i }}.style.img_fit" label="Darstellung" :options="['cover' => 'Füllen (Ausschnitt)', 'contain' => 'Ganz zeigen', 'auto' => 'Natürliche Höhe']" />
                        <x-fa::choice name="layout.{{ $i }}.style.img_height" label="Höhe" :options="['klein' => 'Klein (Panorama)', 'mittel' => 'Mittel', 'gross' => 'Groß']" />
                        <x-fa::choice name="layout.{{ $i }}.style.img_width" label="Breite" :options="['voll' => 'Breit', 'schmal' => 'Schmal (Lesebreite)', 'bleed' => 'Randlos']" />
                    @elseif($bt === 'spacer')
                        <x-fa::field label="Höhe in Pixel" for="design-spacer-hoehe">
                            <x-fa::input id="design-spacer-hoehe" type="number" min="0" wire:model.blur="layout.{{ $i }}.style.height" numeric class="max-w-[8rem]" />
                        </x-fa::field>
                    @elseif($bt === 'price_summary')
                        <x-fa::choice name="layout.{{ $i }}.style.preis_anzeige" label="Preis pro Person" :options="['netto' => 'Nur netto', 'brutto' => 'Nur brutto', 'beide' => 'Netto und brutto']" />
                        <p class="{{ $hilfe }}">Gilt für Foodbook und Angebot, sobald das Dokument mit Preisen veröffentlicht wird. Brutto rechnet mit dem MwSt.-Standardsatz des Teams.</p>
                    @elseif($bt === 'preis_aufschluesselung')
                        <x-fa::field label="Überschrift" for="design-preis-titel">
                            <x-fa::input id="design-preis-titel" wire:model.live.debounce.400ms="layout.{{ $i }}.style.titel" placeholder="Preis-Übersicht" />
                        </x-fa::field>
                        <x-fa::choice name="layout.{{ $i }}.style.preis_anzeige" label="Summen" :options="['netto' => 'Nur netto', 'brutto' => 'Nur brutto', 'beide' => 'Netto, MwSt. und brutto']" />
                        <p class="{{ $hilfe }}">Eine Zeile je Hauptkapitel mit Preis pro Person, immer netto. Diese Wahl steuert die Summenzeilen darunter. Gilt für Angebot und Foodbook. Hat das Foodbook keine Gästezahl, zeigt der Block nur die Preise pro Person.</p>
                    @elseif($bt === 'cta')
                        <p class="{{ $hilfe }}">Text und Link des Kontakt-Aufrufs trägst du beim Veröffentlichen des Dokuments ein, nicht im Design. Ohne Text bleibt der Block leer.</p>
                    @else
                        <p class="{{ $hilfe }}">Für diesen Block gibt es keine Einstellungen.</p>
                    @endif
                </x-fa::section>
            @endif

            {{-- Globale Design-Werte (Kunden-Branding) --}}
            <x-fa::section title="Farben und Schrift" icon="heroicon-o-paint-brush">
                <div class="grid grid-cols-2 gap-3">
                    @foreach($farbFelder as $feld => $farbLabel)
                        <x-fa::field :label="$farbLabel" for="design-farbe-{{ $feld }}">
                            <div class="flex items-center gap-2">
                                <input id="design-farbe-{{ $feld }}" type="color" wire:model.live="tokens.palette.{{ $feld }}"
                                       class="h-9 w-12 shrink-0 cursor-pointer rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] p-0.5">
                                <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] uppercase truncate">{{ $tokens['palette'][$feld] ?? '' }}</span>
                            </div>
                        </x-fa::field>
                    @endforeach
                </div>
                @foreach(['heading' => 'Schrift der Überschriften', 'body' => 'Schrift des Fließtexts'] as $schriftFeld => $schriftLabel)
                    <x-fa::field :label="$schriftLabel" for="design-schrift-{{ $schriftFeld }}" class="min-w-[14rem]">
                        <x-fa::select id="design-schrift-{{ $schriftFeld }}" wire:model.live="tokens.typography.{{ $schriftFeld }}" data-fa-schrift="{{ $schriftFeld }}">
                            <optgroup label="Standard">
                                @foreach($schriftOptionen['standard'] as $wert => $text)<option value="{{ $wert }}">{{ $text }}</option>@endforeach
                            </optgroup>
                            @if($schriftOptionen['team'] !== [])
                                <optgroup label="Eigene Schriften">
                                    @foreach($schriftOptionen['team'] as $wert => $text)<option value="{{ $wert }}">{{ $text }}</option>@endforeach
                                </optgroup>
                            @endif
                            <optgroup label="Google Fonts">
                                @foreach($schriftOptionen['google'] as $wert => $text)<option value="{{ $wert }}">{{ $text }}</option>@endforeach
                            </optgroup>
                        </x-fa::select>
                    </x-fa::field>
                @endforeach
                <x-fa::field label="Schriftgröße" for="design-schrift-skala" hint="1 ist Standard, 1,2 macht alles 20 % größer.">
                    <x-fa::input id="design-schrift-skala" type="number" step="0.05" min="0.7" max="2" wire:model.blur="tokens.typography.scale" numeric class="max-w-[8rem]" />
                </x-fa::field>
                <x-fa::choice name="tokens.typography.heading_size" label="Größe der Überschriften" :options="['zurueckhaltend' => 'Zurückhaltend', 'normal' => 'Normal', 'gross' => 'Groß']" />
                <x-fa::choice name="tokens.typography.heading_weight" label="Schnitt der Überschriften" :options="['leicht' => 'Leicht', 'normal' => 'Normal', 'kraeftig' => 'Kräftig']" />
                <x-fa::choice name="tokens.typography.heading_tracking" label="Laufweite der Überschriften" :options="['eng' => 'Eng', 'normal' => 'Normal', 'weit' => 'Weit']" />
                <x-fa::choice name="tokens.typography.konzept_text" label="Hinweistexte im Konzept" :options="['normal' => 'Normal', 'kursiv' => 'Kursiv', 'kursiv_leise' => 'Kursiv und leiser']" />
                <p class="{{ $hilfe }}">Texte zwischen den Gerichten eines Konzepts oder Pakets, zum Beispiel „serviert im Schraubglas“ unter „Vorspeise“.</p>
                <x-fa::choice name="tokens.spacing" label="Abstände" :options="['compact' => 'Kompakt', 'comfortable' => 'Normal', 'roomy' => 'Großzügig']" />
                <div class="flex flex-col gap-1">
                    <x-fa::choice name="tokens.page_width" label="Seitenbreite" :options="['schmal' => 'Schmal', 'normal' => 'Normal', 'breit' => 'Breit']" />
                    <x-fa::choice name="tokens.text_width" label="Lesebreite des Texts" :options="['schmal' => 'Schmal', 'normal' => 'Normal', 'breit' => 'Breit']" />
                    <x-fa::choice name="tokens.text_align" label="Text im Raster" :options="['links' => 'Linksbündig', 'zentriert' => 'Zentriert']" />
                    <p class="{{ $hilfe }}">Alle Blöcke stehen auf einer gemeinsamen Kante. Am Handy nutzt die Seite automatisch die volle Breite.</p>
                </div>
                <x-fa::choice name="tokens.nav" label="Navigation" :options="['none' => 'Keine', 'anchor' => 'Sprungmenü (schwebend)', 'sidebar' => 'Seitenleiste links']" />
                <div class="flex flex-col gap-1">
                    <x-fa::choice name="tokens.nav_umfang" label="Im Menü" :options="['kapitel' => 'Nur Kapitel', 'pakete' => 'Kapitel und Pakete']" />
                    <p class="{{ $hilfe }}">Mit Paketen springt das Menü auch zu „Italien“, „Deutschland“ usw. innerhalb eines Kapitels.</p>
                </div>
                <x-fa::checkbox class="{{ $hakenZeile }}" wire:model.live="tokens.lightbox">
                    Bilder per Klick vergrößern
                </x-fa::checkbox>
                <x-fa::choice name="tokens.band_style" label="Bild-Band" :options="['grid' => 'Raster (Standard)', 'rondell' => 'Karussell']" />
                <div class="flex flex-col gap-1">
                    <x-fa::choice name="tokens.speiseplan_layout" label="Speiseplan-Ausgabe" :options="['grid' => 'Wochen-Tabelle (Standard)', 'liste' => 'Liste, Tag für Tag']" />
                    <p class="{{ $hilfe }}">Wirkt nur bei Speiseplänen.</p>
                </div>
            </x-fa::section>

            {{-- Eigene Schriften (Hausschrift/Kunden-CI): je Datei ein Schnitt, im Team für alle Designs nutzbar. --}}
            <x-fa::section title="Eigene Schriften" icon="heroicon-o-language"
                           description="Schriftdatei hochladen (woff2, woff, ttf oder otf, bis 5 MB). Danach steht sie oben bei der Schriftwahl unter „Eigene Schriften“. Je Datei ein Schnitt, z. B. Regular und Bold einzeln hochladen. Nur Schriften nutzen, für die eine Web-Lizenz besteht.">
                <div class="flex flex-wrap items-end gap-3">
                    <x-fa::field label="Name der Schrift" for="design-font-familie" class="min-w-[12rem] flex-1">
                        <x-fa::input id="design-font-familie" wire:model="fontFamilie" placeholder="z. B. Green Tasty Sans" data-fa-font-familie />
                    </x-fa::field>
                    <x-fa::field label="Stärke" for="design-font-weight">
                        <x-fa::select id="design-font-weight" wire:model="fontWeight" :options="['300' => 'Light 300', '400' => 'Regular 400', '500' => 'Medium 500', '600' => 'Semibold 600', '700' => 'Bold 700', '900' => 'Black 900']" />
                    </x-fa::field>
                    <x-fa::field label="Stil" for="design-font-style">
                        <x-fa::select id="design-font-style" wire:model="fontStyle" :options="['normal' => 'Normal', 'italic' => 'Kursiv']" />
                    </x-fa::field>
                    <div class="flex flex-col gap-1">
                        <input type="file" wire:model="fontUpload" accept=".woff2,.woff,.ttf,.otf" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] min-w-0" data-fa-font-datei>
                        <x-fa::signal tone="info" icon="heroicon-m-arrow-path" wire:loading wire:target="fontUpload">Wird hochgeladen</x-fa::signal>
                    </div>
                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-arrow-up-tray" wire:click="schriftHochladen" data-fa-font-hochladen>Schrift hinzufügen</x-fa::button>
                </div>
                @error('fontUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                @if($schriften !== [])
                    <ul class="mt-3 divide-y divide-[var(--fa-line)] text-[length:var(--fa-text-md)]">
                        @foreach($schriften as $sf)
                            <li class="flex items-center justify-between gap-3 py-1.5" wire:key="font-{{ $sf['id'] }}">
                                <span>{{ $sf['family'] }} <span class="text-[var(--fa-ink-3)]">· {{ $sf['weight'] }}{{ $sf['style'] === 'italic' ? ' kursiv' : '' }} · {{ $sf['format'] }}</span></span>
                                @if($sf['eigen'])
                                    <x-fa::icon-button icon="heroicon-m-trash" label="Schrift {{ $sf['family'] }} entfernen" size="sm" tone="danger" wire:click="schriftLoeschen({{ $sf['id'] }})" wire:confirm="Schrift entfernen? Designs, die sie nutzen, fallen auf die Standardschrift zurück." />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>

            {{-- Stufe 2 „Leinwand via Code": eigenes, sandboxed CSS auf die Blöcke (kein HTML/JS/@import). --}}
            <x-fa::section title="Eigenes CSS" icon="heroicon-o-code-bracket"
                           description="Für Fortgeschrittene. Look beschreiben und die KI schreibt das CSS, oder direkt selbst schreiben. Nur Gestaltung, keine Skripte. Wirkt in der Vorschau nach Verlassen des Felds und wird beim Speichern übernommen.">
                <div class="flex flex-col gap-2">
                    <x-fa::field label="Gewünschter Look" for="design-css-brief">
                        <x-fa::input id="design-css-brief" wire:model="cssBrief" placeholder="z. B. modernes Catering-Design wie eine Website" data-fa-css-brief />
                    </x-fa::field>
                    <div class="flex justify-end">
                        <x-fa::button variant="ai" icon="heroicon-m-sparkles" wire:click="cssGenerieren" wire:loading.attr="disabled" wire:target="cssGenerieren" data-fa-css-generate>
                            <span wire:loading.remove wire:target="cssGenerieren">CSS mit KI erzeugen</span>
                            <span wire:loading wire:target="cssGenerieren">KI schreibt</span>
                        </x-fa::button>
                    </div>
                </div>
                <x-fa::field label="CSS" for="design-css" hint="Danach lässt sich das CSS von Hand feinjustieren. Farben und Schrift oben gelten weiter.">
                    <textarea id="design-css" wire:model.blur="customCss" rows="7" class="fa-control py-2 font-mono text-[length:var(--fa-text-sm)] leading-relaxed" placeholder=".pt-hero-title { letter-spacing: .04em; }
.pt-section-title { text-transform: uppercase; }" data-fa-design-css></textarea>
                </x-fa::field>
            </x-fa::section>
            </fieldset>
        </aside>
    </div>
</div>
