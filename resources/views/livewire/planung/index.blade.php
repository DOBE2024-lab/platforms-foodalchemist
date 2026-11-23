{{-- Planung (Leitstelle). Haus-Anordnung: links Planungen (Suche, Filter, Liste), Mitte Erstellen + Board,
     rechts Details; „Öffnen" → Vollbild-Editor im Werkbank-Modus (Erstell-Reiter · Ausgabe-Reiter · Fortschritt).
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> und --fa-*-Tokens umgestellt (hell + Werkbank).
     Laptop-Tauglichkeit: Board-Spalten haben eine Mindestbreite und scrollen waagerecht statt zu
     zerquetschen; die Reiterleiste scrollt statt umzubrechen; die klebenden Erstell-Leisten sind
     eine niedrige Zeile (Plan, Wissens-Vorschau und Hinweise stehen davor im normalen Fluss).
     Funktion, wire:-Bindungen, Event-Namen, Diktat-Ziele und data-Marker unverändert. --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets
@assets
{{-- Spec 53/D: gemeinsamer Voice-Recorder fürs Briefing-Diktat (partials/diktat.blade.php). Hotfix: in
     @assets statt als rohes erstes Tag — sonst bekommt das <script> Livewires wire:id und die Komponente zerfällt. --}}
<script src="/_platform/fa-assets/foodalchemist-voice-recorder.iife.js?v={{ config('platform.fa_voice_recorder_hash', '0') }}"></script>
@endassets
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $statusLabel = ['divergenz' => 'Ideen sammeln', 'konvergenz' => 'Auswahl treffen', 'erledigt' => 'Abgeschlossen'];
    $modeLabel = \Platform\FoodAlchemist\Support\Planung\PlanungOptionen::MODUS_LABEL;   // Wirkungs-Hinweise: PlanungOptionen::MODUS_HINWEIS (Brief)
    // Etiketten über Tokens (hell + Werkbank). $chip liefert fertiges HTML für die Closures unten.
    $tonKlasse = [
        'neutral' => 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]',
        'accent' => 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]',
        'ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]',
        'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]',
        'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]',
        'info' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]',
    ];
    $chip = fn ($t, $ton = 'neutral') => '<span class="inline-flex items-center h-[22px] max-w-full px-2 rounded-full text-[length:var(--fa-text-sm)] font-medium whitespace-nowrap truncate ' . ($tonKlasse[$ton] ?? $tonKlasse['neutral']) . '">' . e($t) . '</span>';
    // Board/Liste: Kaskaden-Status je Session aus $kaskaden (jüngster Lauf). Kein Lauf → „Entwurf".
    $kaskaden = $kaskaden ?? [];
    $kaskadeStatusTon = ['entwurf' => 'neutral', 'läuft' => 'info', 'prüfen' => 'warn', 'fertig' => 'ok', 'fehlgeschlagen' => 'crit'];
    $kaskadeBadge = function ($sessionId) use ($kaskaden, $kaskadeStatusTon, $chip) {
        $status = $kaskaden[(int) $sessionId]['status'] ?? 'entwurf';
        return $chip(\Illuminate\Support\Str::ucfirst($status), $kaskadeStatusTon[$status] ?? 'neutral');
    };
    $kaskadeLaeuft = fn ($sessionId) => (bool) ($kaskaden[(int) $sessionId]['running'] ?? false);
    // Stufen-Fortschritt kompakt: „Gerichte 1/1 · Basisrezepte 0/3".
    $kaskadeFortschritt = function ($sessionId) use ($kaskaden) {
        $stufen = $kaskaden[(int) $sessionId]['stufen'] ?? [];
        return collect($stufen)->map(fn ($st) => $st['label'] . ' ' . $st['fertig'] . '/' . $st['total'])->implode(' · ');
    };
    // Anzeige-Titel: Ergebnis-Name (Kaskaden-Artefakt) → Anfang der Analyse → gespeicherter Titel.
    $anzeigeTitel = function ($s) use ($kaskaden) {
        $t = $kaskaden[(int) $s->id]['titel'] ?? null;
        if (is_string($t) && trim($t) !== '') {
            return trim($t);
        }
        $ana = trim((string) ($s->analysis ?? ''));
        return $ana !== '' ? \Illuminate\Support\Str::limit($ana, 42) : $s->title;
    };
    // Typ-Symbol je Session (aus dem Scope des jüngsten Laufs).
    $typIconMap = ['concept' => 'heroicon-o-squares-2x2', 'gericht' => 'heroicon-o-cake', 'rezept' => 'heroicon-o-beaker', 'vollkaskade' => 'heroicon-o-bolt'];
    $typIcon = fn ($s) => $typIconMap[$kaskaden[(int) $s->id]['scope'] ?? ''] ?? 'heroicon-o-light-bulb';
    // Board: Ausgabe-Ziel (Owner). Kein Owner → „Frei".
    $ausgabeZielLabel = ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'offer' => 'Angebot', 'format' => 'Format', 'concept' => 'Konzept'];
    $ausgabeChip = function ($sessionId) use ($kaskaden, $ausgabeZielLabel, $chip) {
        $ot = $kaskaden[(int) $sessionId]['owner_type'] ?? null;
        if ($ot === null) {
            return $chip('Frei');
        }
        $name = trim((string) ($kaskaden[(int) $sessionId]['owner_name'] ?? ''));
        $lbl = ($ausgabeZielLabel[$ot] ?? \Illuminate\Support\Str::ucfirst($ot)) . ($name !== '' ? ' · ' . $name : '');
        return $chip($lbl, 'accent');
    };
    $zustandTon = ['läuft' => 'info', 'prüfen' => 'warn', 'geplant' => 'neutral', 'erledigt' => 'ok', 'fehlgeschlagen' => 'crit'];
    $menueGruppe = 'px-3 pt-2 pb-1 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-3)]';
    $verwerfenFrage = 'Diese Planung verwerfen? Laufende Erstellungen dieser Planung werden ebenfalls gestoppt. Sie wird archiviert, nicht endgültig gelöscht.';
    // Editor-Reiter: Erstellen · Ausgaben · Fortschritt. Optik wie x-fa::editor-tabs; der Baustein selbst
    // passt hier nicht (der Reiter-Zustand `tab` lebt am Modal, tab-init + modal.open-Detail, die Panels liegen im Body).
    // #1003 PR7: am Handy drei Schritte (Auftrag → Ergebnis → Kontext) statt der Reiterleiste; Auftrag nur diese Arten.
    $handy = \Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy();
    $handyArten = ['gericht' => 'Gericht', 'basisrezept' => 'Basisrezept', 'concept' => 'Konzept', 'import' => 'Import (Foto)'];
    $schrittKnopf = 'flex-1 inline-flex items-center justify-center gap-1.5 min-h-11 rounded-[5px] text-[length:var(--fa-text-md)] font-medium';
    $schrittAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $schrittAus = 'text-[var(--fa-ink-2)]';
    $reiterGruppen = [
        ['basisrezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Konzept', 'format' => 'Format', 'composer' => 'Composer', 'trendradar' => 'Trendradar', 'import' => 'Import'],
        ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'angebot' => 'Angebot'],
        ['worker' => 'Fortschritt'],
    ];
    $reiterKnopf = 'inline-flex items-center gap-1.5 h-10 px-3.5 shrink-0 text-[length:var(--fa-text-base)] font-medium border-b-2 -mb-px rounded-t-[var(--fa-radius-control)] transition-colors whitespace-nowrap focus-visible:-outline-offset-2';
    $reiterAn = 'border-[var(--fa-accent)] text-[var(--fa-accent)] font-semibold bg-[var(--fa-accent-soft)]';
    $reiterAus = 'border-transparent text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    // Ausgabe-Reiter: Auswahl links schmaler, Brief rechts breiter (ab xl nebeneinander).
    $ausgabeRaster = 'grid grid-cols-1 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4 items-start';
    $hinweisText = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Planung" icon="heroicon-o-light-bulb" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Planung'],
        ]" />
    </x-slot>

    {{-- LINKS: Neue Planung, Suche, Filter, Liste nach Kategorie --}}
    <x-slot name="sidebar">
        <x-fa::page-sidebar title="Planungen" width="w-80">
            <div class="p-3 flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <x-fa::input wire:model="neuTitel" wire:keydown.enter="neuePlanung" placeholder="Neue Planung benennen" aria-label="Name der neuen Planung" class="flex-1 min-w-0" />
                    <x-fa::button icon="heroicon-m-plus" wire:click="neuePlanung" title="Planung anlegen" aria-label="Planung anlegen" class="px-2.5" />
                </div>

                {{-- Suche + Filter (finale Etappe #17): filtern Liste UND Board. --}}
                <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]">
                    <div class="relative">
                        <label for="planung-suche" class="sr-only">Planungen durchsuchen</label>
                        @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                        <x-fa::input id="planung-suche" type="search" wire:model.live.debounce.300ms="sucheListe" placeholder="Planungen durchsuchen" class="pl-8" data-planung-suche />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <x-fa::select size="sm" wire:model.live="filterStatus" aria-label="Nach Status filtern" data-planung-filter-status>
                            <option value="">Alle Status</option>
                            <option value="entwurf">Entwurf</option>
                            <option value="läuft">Läuft</option>
                            <option value="prüfen">Prüfen</option>
                            <option value="fertig">Fertig</option>
                            <option value="fehlgeschlagen">Fehlgeschlagen</option>
                        </x-fa::select>
                        <x-fa::select size="sm" wire:model.live="filterTyp" aria-label="Nach Typ filtern" data-planung-filter-typ>
                            <option value="">Alle Typen</option>
                            <option value="rezept">Basisrezept</option>
                            <option value="gericht">Gericht</option>
                            <option value="concept">Konzept</option>
                            <option value="foodbook">Foodbook</option>
                            <option value="speisekarte">Speisekarte</option>
                            <option value="speiseplan">Speiseplan</option>
                            <option value="offer">Angebot</option>
                            <option value="format">Format</option>
                        </x-fa::select>
                    </div>
                </div>

                <div class="flex flex-col gap-3 max-h-[calc(100vh-17rem)] min-h-40 overflow-y-auto -mx-1 px-1">
                    @forelse($baum as $ast)
                        <div wire:key="cat-{{ $loop->index }}" class="flex flex-col gap-0.5">
                            <p class="px-2 pb-0.5 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-3)]">{{ $ast['category'] }}</p>
                            @foreach($ast['sessions'] as $s)
                                @php
                                    $istGewaehlt = $active && $active->id === $s->id;
                                    $stat = $kaskaden[$s->id]['status'] ?? 'entwurf';
                                @endphp
                                {{-- Zeile = wählbarer Button + beim Überfahren eingeblendeter Papierkorb (#17-Rest);
                                     kein verschachtelter Button (group-flex-div). --}}
                                <div wire:key="sess-{{ $s->id }}"
                                     class="group flex items-center gap-1 rounded-[var(--fa-radius-control)] {{ $istGewaehlt ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}">
                                    <button type="button" wire:click="waehle({{ $s->id }})" x-on:click="$store.ui?.mSet('activity_planung', 'open', true)" @if($istGewaehlt) aria-current="true" @endif
                                            class="flex-1 min-w-0 flex items-center justify-between gap-2 text-left pl-2 pr-1 py-1.5 text-[length:var(--fa-text-md)] {{ $istGewaehlt ? 'text-[var(--fa-accent)] font-medium' : 'text-[var(--fa-ink)]' }}">
                                        <span class="flex items-center gap-2 min-w-0">
                                            @svg($typIcon($s), 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                            <span class="truncate" title="{{ $anzeigeTitel($s) }}">{{ $anzeigeTitel($s) }}</span>
                                        </span>
                                        <x-fa::badge :tone="$kaskadeStatusTon[$stat] ?? 'neutral'" class="shrink-0" data-planung-status="{{ $stat }}">
                                            @if($kaskadeLaeuft($s->id))<span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse" data-planung-puls></span>@endif
                                            {{ \Illuminate\Support\Str::ucfirst($stat) }}
                                        </x-fa::badge>
                                    </button>
                                    @unless($handy)
                                    <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Planung verwerfen"
                                        wire:click="planungVerwerfen({{ $s->id }})" wire:confirm="{{ $verwerfenFrage }}"
                                        class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity" data-planung-listen-verwerfen="{{ $s->id }}" />
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                    @empty
                        @if($sucheListe !== '' || $filterStatus !== '')
                            <x-fa::empty compact icon="heroicon-o-funnel" title="Keine Planung passt">Suche oder Filter lockern.</x-fa::empty>
                        @else
                            <x-fa::empty compact icon="heroicon-o-light-bulb" title="Noch keine Planungen">Oben eine Planung benennen, „Neu erstellen" wählen oder im Trendradar „In Planung öffnen".</x-fa::empty>
                        @endif
                    @endforelse
                </div>
            </div>
        </x-fa::page-sidebar>
    </x-slot>

    {{-- MITTE: Erstellen + Board --}}
    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        {{-- Leitstelle: freie Erstellung mit einem Klick, die eine KI-Erstell-Fläche. Legt eine leichte
             „Freie Erstellung"-Session (cockpit_frei) an und öffnet den Editor auf dem passenden Reiter.
             Trend bleibt EIN Input, nicht der Rahmen. --}}
        <div class="flex flex-col gap-4" x-data="{ fbOpen: @js($fbPanelAuf), skOpen: false, spOpen: false, offOpen: @js($offerPanelAuf), fmtOpen: false }">
            <x-fa::page-header title="Planung" subtitle="Basisrezepte, Gerichte und Konzepte mit KI entwerfen, ganze Foodbooks, Karten und Angebote aus einem Brief planen.">
                <x-slot:actions>
                    {{-- Ein „Neu erstellen"-Knopf (Dominique 2026-08-23) statt vieler Knöpfe: Menü in drei Gruppen. --}}
                    <x-fa::menu breite="72" menue:data-frei-menu="">
                        <x-slot:ausloeser>
                            <x-fa::button variant="primary" icon="heroicon-m-plus" icon-right="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" data-frei-neu>Neu erstellen</x-fa::button>
                        </x-slot:ausloeser>
                        <p class="{{ $menueGruppe }}">Einzeln mit KI erstellen</p>
                        <x-fa::menu-item icon="heroicon-o-beaker" abstand="weit" wire:click="schnellErstellen('rezept')" data-frei-rezept>Basisrezept</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-cake" abstand="weit" wire:click="schnellErstellen('gericht')" data-frei-gericht>Gericht</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-squares-2x2" abstand="weit" wire:click="schnellErstellen('concept')" data-frei-concept>Konzept</x-fa::menu-item>
                        <div class="my-1 border-t border-[var(--fa-line)]" role="separator"></div>
                        <p class="{{ $menueGruppe }}">Übernehmen und kombinieren</p>
                        <x-fa::menu-item icon="heroicon-o-document-arrow-down" abstand="weit" wire:click="schnellImport" data-frei-import>Rezept importieren</x-fa::menu-item>
                        @unless($handy){{-- #1003 PR7: Composer, Trendradar und Ausgabe-Formen am Computer --}}
                        <x-fa::menu-item icon="heroicon-o-sparkles" abstand="weit" wire:click="schnellComposer" data-frei-composer>Aus Zutaten kombinieren (Composer)</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-signal" abstand="weit" wire:click="schnellTrendradar" data-frei-trendradar>Aus dem Trendradar (Trend, Hype, Inspiration)</x-fa::menu-item>
                        <div class="my-1 border-t border-[var(--fa-line)]" role="separator"></div>
                        <p class="{{ $menueGruppe }}">Ganze Ausgabe aus einem Brief</p>
                        <x-fa::menu-item icon="heroicon-o-book-open" abstand="weit" x-on:click="fbOpen = true; skOpen = false; spOpen = false; offOpen = false; fmtOpen = false" data-frei-foodbook>Foodbook</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-clipboard-document-list" abstand="weit" x-on:click="skOpen = true; fbOpen = false; spOpen = false; offOpen = false; fmtOpen = false" data-frei-speisekarte>Speisekarte</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-calendar-days" abstand="weit" x-on:click="spOpen = true; fbOpen = false; skOpen = false; offOpen = false; fmtOpen = false" data-frei-speiseplan>Speiseplan</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-document-text" abstand="weit" x-on:click="offOpen = true; fbOpen = false; skOpen = false; spOpen = false; fmtOpen = false" data-frei-angebot>Angebot</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-swatch" abstand="weit" x-on:click="fmtOpen = true; fbOpen = false; skOpen = false; spOpen = false; offOpen = false" data-frei-format>Format</x-fa::menu-item>
                        @endunless
                    </x-fa::menu>
                </x-slot:actions>
            </x-fa::page-header>

            @unless($handy){{-- #1003 PR7: Ausgabe-Formen aus dem Brief bleiben am Computer --}}
            <livewire:foodalchemist.planung.aus-brief ort="board" key="planung-ausbrief-board" />   {{-- Spec 85 C1 PR-5 --}}
            @endunless
        </div>

        {{-- Board (Leitstelle-Übersicht). Immer sichtbar, auch bei gewählter Session (Karte-Klick füllt NUR
             die rechte Detail-Spalte + markiert die Karte). Der linke Filter grenzt ein: dieselbe gefilterte
             $sessions-Menge landet in den Status-Spalten. „Öffnen" = Editor. Poll nur, wenn tatsächlich
             etwas läuft (kein Dauer-Poll).
             Laptop: jede Spalte hat eine Mindestbreite; reicht die Breite nicht für fünf, scrollt das Board
             waagerecht, statt die Karten unlesbar schmal zu drücken. --}}
        <div class="flex flex-col gap-3" data-planung-board>
            {{-- 2.1d: der Board-Poll liest nur noch den Stand der laufenden Läufe (LaufStatus); neu gezeichnet wird bei Änderung. --}}
            @if($irgendeinLaeuft)
                <livewire:foodalchemist.planung.lauf-status :lauf-ids="$laufendeIds" :neue-beobachten="true" takt="3s" :key="'planung-board-status-'.implode('-', $laufendeIds)" />
            @endif
            @include('foodalchemist::livewire.planung.partials.board-worker-kopf')

            @php
                $spalten = ['entwurf' => 'Entwurf', 'läuft' => 'Läuft', 'prüfen' => 'Zu prüfen', 'fertig' => 'Fertig', 'fehlgeschlagen' => 'Fehlgeschlagen'];
                $nachStatus = $sessions->groupBy(fn ($s) => $kaskaden[(int) $s->id]['status'] ?? 'entwurf');
                $spaltenPunkt = ['entwurf' => 'bg-[var(--fa-ink-3)]', 'läuft' => 'bg-[var(--fa-info)]', 'prüfen' => 'bg-[var(--fa-warn)]', 'fertig' => 'bg-[var(--fa-ok)]', 'fehlgeschlagen' => 'bg-[var(--fa-crit)]'];
            @endphp

            @if($sessions->count() === 0)
                <x-fa::card data-planung-board-leer="">
                    <x-fa::empty icon="heroicon-o-view-columns" title="Noch keine Planungen">Oben „Neu erstellen" wählen. Jede Planung erscheint hier in der Spalte ihres Stands.</x-fa::empty>
                </x-fa::card>
            @else
                <div class="overflow-x-auto -mx-1 px-1 pb-1">
                    <div class="grid grid-cols-1 gap-3 items-start md:grid-cols-none md:grid-flow-col md:auto-cols-[minmax(14rem,1fr)]" data-planung-board-spalten>
                        @foreach($spalten as $key => $label)
                            @php $spaltenSessions = ($nachStatus[$key] ?? collect())->values(); @endphp
                            <section class="min-w-0 flex flex-col gap-2 rounded-[var(--fa-radius-surface)] bg-[var(--fa-neutral-soft)] p-2" data-planung-spalte="{{ $key }}" aria-label="{{ $label }}">
                                <header class="flex items-center gap-2 px-1 pt-0.5">
                                    <span class="w-2 h-2 rounded-full {{ $spaltenPunkt[$key] }}"></span>
                                    <h3 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $label }}</h3>
                                    <span class="ml-auto text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] tabular-nums" data-planung-spalte-count>{{ $spaltenSessions->count() }}</span>
                                </header>
                                <div class="flex flex-col gap-2">
                                    @forelse($spaltenSessions as $s)
                                        @include('foodalchemist::livewire.planung.partials.board-karte', ['s' => $s])
                                    @empty
                                        <p class="px-1 py-2 fa-t-muted">Keine Planung</p>
                                    @endforelse
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>{{-- /board --}}
    </x-ui-page-container>

    {{-- RECHTS: Details --}}
    <x-slot name="activity">
        <x-fa::detail-sidebar title="Detail" width="w-80" :maxWidth="560" scope="activity_planung" side="right" :auswahl="(string) $active?->id" :lesemodus="false">
            {{-- Anatomie Detail-Panels (DESIGN.md): Kopf (Ergebnis-Name, Stand, „Im Editor öffnen", Verwerfen im Menü)
                 · offene Punkte · Fortschritt je Stufe. --}}
            @include('foodalchemist::livewire.planung.partials.detail-inhalt')
        </x-fa::detail-sidebar>
    </x-slot>

    {{-- VOLLBILD-EDITOR (Werkbank-Modus) --}}
    <x-fa::modal name="planung-editor" fullscreen dark-canvas title="Planung"
                            :title-name="$active ? $anzeigeTitel($active) : null" tab-init="gericht">
        <x-slot:actions>
            {{-- Speichern sichert Titel und Eingaben der Planung. Sekundär: die Hauptaktion jedes Reiters
                 ist das Erstellen in der Leiste unten. --}}
            {{-- Briefing mitspeichern (23.11.2026): Name der Planung wieder editierbar, Leer-Meldung direkt am Feld. --}}
            <x-fa::field for="planung-name" error="form.title" data-planung-name>
                <x-fa::input id="planung-name" size="sm" wire:model.live.debounce.400ms="form.title" placeholder="Name der Planung" aria-label="Name der Planung" :fehler="$errors->has('form.title')" />
            </x-fa::field>
            <x-fa::button icon="heroicon-m-check" wire:click="speichern">Planung speichern</x-fa::button>
            @if($ungespeichert)
                <x-fa::signal tone="warn" data-planung-ungespeichert>ungespeichert</x-fa::signal>
            @endif
            @if($meldung !== null)
                <x-fa::signal tone="ok">{{ $meldung }}</x-fa::signal>
            @endif
            @if($fehler !== null)
                <x-fa::signal tone="crit">{{ $fehler }}</x-fa::signal>
            @endif
            @if($margenWarnung !== null)
                <x-fa::signal tone="warn" data-margen-warnung>{{ $margenWarnung }}</x-fa::signal>
            @endif
            {{-- Was der Lauf NICHT erzeugt hat: eine Warnung, kein Fehler und kein Erfolg. MUSS innerhalb von
                 x-slot:actions stehen; dahinter beginnt die Reiterleiste, dort landete der Hinweis in der
                 falschen Modal-Zone. --}}
            @if($deckelHinweis !== null)
                <x-fa::signal tone="warn" data-deckel-hinweis>{{ $deckelHinweis }}</x-fa::signal>
            @endif
            {{-- Spec 53 / Paket C: globaler KI-Status, sichtbar auf JEDEM Reiter (x-slot:actions
                 überlebt den Reiterwechsel; die Reiterleiste beginnt erst danach). --}}
            <div class="ml-auto">
                @include('foodalchemist::livewire.planung.partials.ki-status-leiste', ['klickbar' => true])
            </div>
        </x-slot:actions>

        <x-slot:tabs>
            @if($handy)
                {{-- #1003 PR7: am Handy drei Schritte statt zwölf Reitern — Auftrag (Gericht, Basisrezept, Concept, Import) →
                     Ergebnis (Fortschritt) → Kontext (Herkunft, Stand je Stufe). --}}
                <div class="flex w-full p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" role="tablist" aria-label="Schritte der Planung" data-planung-handy-schritte>
                    <button type="button" role="tab" x-on:click="if (! ['gericht', 'basisrezept', 'concept', 'import'].includes(tab)) tab = 'gericht'"
                            x-bind:aria-selected="['gericht', 'basisrezept', 'concept', 'import'].includes(tab)"
                            x-bind:class="['gericht', 'basisrezept', 'concept', 'import'].includes(tab) ? '{{ $schrittAn }}' : '{{ $schrittAus }}'"
                            class="{{ $schrittKnopf }}" data-planung-schritt="auftrag">Auftrag</button>
                    <button type="button" role="tab" x-on:click="tab='worker'" x-bind:aria-selected="tab==='worker'"
                            x-bind:class="tab==='worker' ? '{{ $schrittAn }}' : '{{ $schrittAus }}'"
                            class="{{ $schrittKnopf }}" data-planung-schritt="ergebnis"><span>Ergebnis</span>@if($laeuft)<span class="w-2 h-2 rounded-full bg-[var(--fa-info)] animate-pulse" aria-label="läuft"></span>@endif</button>
                    <button type="button" role="tab" x-on:click="tab='kontext'" x-bind:aria-selected="tab==='kontext'"
                            x-bind:class="tab==='kontext' ? '{{ $schrittAn }}' : '{{ $schrittAus }}'"
                            class="{{ $schrittKnopf }}" data-planung-schritt="kontext">Kontext</button>
                </div>
            @else
            {{-- Analyse + Skizzen (Spec-40-E0-Ideations-Einstieg) zurückgezogen (Dominique 2026-08-23):
                 abgelöst durch Composer (geerdet) + Brief-Kaskaden. DishIdea/IdeenService bleibt intern.
                 Gruppen: Erstellen · Ausgabe-Formen (Spec-42-Vollzug) · Fortschritt bewusst am Ende
                 (Dominique 2026-08-24: erst erstellen, dann beobachten).
                 Laptop: die Leiste scrollt waagerecht, statt in zwei Zeilen umzubrechen. --}}
            <div class="flex items-center gap-1 overflow-x-auto -mb-px" role="tablist" aria-label="Bereiche der Planung">
                @foreach($reiterGruppen as $gruppe)
                    @if(! $loop->first)<span class="mx-1.5 h-5 w-px shrink-0 bg-[var(--fa-line-strong)]" aria-hidden="true"></span>@endif
                    @foreach($gruppe as $reiterKey => $reiterLabel)
                        <button type="button" role="tab" x-on:click="tab='{{ $reiterKey }}'"
                                x-bind:aria-selected="tab==='{{ $reiterKey }}'"
                                x-bind:class="tab==='{{ $reiterKey }}' ? '{{ $reiterAn }}' : '{{ $reiterAus }}'"
                                class="{{ $reiterKnopf }}" data-planung-reiter="{{ $reiterKey }}">{{ $reiterLabel }}@if($reiterKey === 'worker' && $laeuft)<span class="w-2 h-2 rounded-full bg-[var(--fa-warn)] animate-pulse" title="läuft gerade"></span>@endif</button>
                    @endforeach
                @endforeach
            </div>
            @endif
        </x-slot:tabs>

        {{-- 2.1d: aktiven Reiter melden — die Planung lädt Composer, Trendradar und Auswahllisten nur für ihn. --}}
        <div hidden x-init="$watch('tab', t => { if ($wire.editorTab !== t) $wire.set('editorTab', t) })"></div>

        @if($active)
            @if(($ownerKontext ?? null))
                {{-- E1b (Spec 40): Owner-Kontext: WOFÜR hier geplant wird + Rückweg ins Ausgabe-Modul. --}}
                <x-fa::notice tone="info" class="mb-3">
                    Planung für {{ $ownerKontext['typ_label'] }} „{{ $ownerKontext['name'] }}". Was hier entsteht, landet automatisch dort.
                    <x-slot:actions>
                        <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-left" :href="route($ownerKontext['route'], $ownerKontext['route_param'])">Zurück zum {{ $ownerKontext['typ_label'] }}</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            @endif
            @unless($handy)
            <livewire:foodalchemist.planung.aus-brief ort="reiter" :session-id="$sessionId" :editor-tab="$editorTab"
                :fb-aus-session="($ownerKontext['owner_type'] ?? null) === 'foodbook' ? (int) $ownerKontext['owner_id'] : null"
                key="planung-ausbrief-reiter" />   {{-- Spec 85 C1 PR-5 --}}
            @endunless

            {{-- #1003 PR7: am Handy die Auftrags-Arten als Daumen-Chips (statt der Reiterleiste). --}}
            @if($handy)
                <div x-show="['gericht', 'basisrezept', 'concept', 'import'].includes(tab)" class="flex flex-wrap gap-1.5 mb-3" role="group" aria-label="Art des Auftrags" data-planung-handy-arten>
                    @foreach($handyArten as $artKey => $artLabel)
                        <button type="button" x-on:click="tab='{{ $artKey }}'" x-bind:aria-pressed="tab==='{{ $artKey }}'"
                                x-bind:class="tab==='{{ $artKey }}' ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]' : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)]'"
                                class="inline-flex items-center min-h-11 px-3.5 rounded-full border text-[length:var(--fa-text-md)] font-medium" data-planung-handy-art="{{ $artKey }}">{{ $artLabel }}</button>
                    @endforeach
                </div>
            @endif
            {{-- IMPORT: bestehende Rezeptur (Text, Web-Kopie, PDF, Foto, Scan) TREU übernehmen und mit Grundprodukten
                 verknüpft anlegen. Getrennt vom Generator (der veredelt): hier 1:1 übernehmen. Foto und Scan gehen seit
                 2026-10-12 als Bild an die KI (OpenAI-Weg, Core-Fix #925). Schritt 2 hat eine klebende Anlegen-Leiste. --}}
            <div wire:key="planung-tab-import" x-show="tab==='import'" class="flex flex-col gap-4 max-w-7xl mx-auto">
                <livewire:foodalchemist.planung.rezept-import :session-id="$sessionId" key="planung-rezept-import" />
            </div>

            {{-- Spec 85 C1 PR-4: Erstell-Reiter Basisrezept, Gericht, Konzept (Briefing, Leitplanken, KI-Plan, Go) --}}
            <livewire:foodalchemist.planung.brief :session-id="$sessionId" :laeuft="$laeuft" :key="'planung-brief-' . ($sessionId ?? 0)" />

            {{-- FORTSCHRITT (Alpine-Tab `worker`): alle Läufe zusammen, Status + Baum + Freigabe.
                 Anzeige, kein Erstell-Knopf, darum keine klebende Leiste. --}}
            {{-- Spec 80 D1: Baum · Cluster · Rezept nutzt die volle Breite. --}}
            <div wire:key="planung-tab-worker" x-show="tab==='worker'" class="flex flex-col gap-4 w-full">
                <livewire:foodalchemist.planung.kaskaden-lauf :lauf-id="$laufId" :key="'planung-lauf-' . ($sessionId ?? 0) . '-' . ($laufId ?? 0)" />   {{-- Spec 85 C1 PR-3 --}}
            </div>

            @unless($handy)
            {{-- COMPOSER: Foodpairing-Fläche. Zutaten zusammenstellen, das Netz zeigt live, was passt.
                 Gezielte Kreation: aus den gewählten Zutaten ein Basisrezept oder Gericht vorbereiten
                 (Zutaten = verbindliche Leit-Aromen). Klick auf einen Kandidaten nimmt ihn auf.
                 Spaltenaufteilung bleibt 5fr/7fr (Auswahl links, Netz rechts). fa-pass: nur die linke Spalte
                 auf Tokens/Bausteine umgestellt; das Netz samt Legende rechts ist unverändert (eigene Welle). --}}
            {{-- Spec 79 · TRENDRADAR: Trends, Hypes und Fundstücke kombinieren → ein Briefing → normaler Kaskadenweg. --}}
            <div wire:key="planung-tab-trendradar" x-show="tab==='trendradar'" class="max-w-7xl mx-auto" data-tab-trendradar>
                <livewire:foodalchemist.planung.trendradar :session-id="$sessionId" :aktiv="$editorTab === 'trendradar'" :key="'planung-trendradar-' . $sessionId" />   {{-- Spec 85 C1 PR-2 --}}
            </div>

            <div wire:key="planung-tab-composer" x-show="tab==='composer'" class="max-w-7xl mx-auto">
                <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] gap-4 items-start">
                {{-- LINKE SPALTE: Auswahl + Zusammenhalt --}}
                <div class="space-y-4 min-w-0">
                <x-fa::modal-section icon="heroicon-o-squares-plus" title="Zutaten zusammenstellen">
                    @if(!empty($composerAnker))
                        <x-slot:actions>
                            <x-fa::badge tone="accent">{{ count($composerAnker) }} gewählt</x-fa::badge>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }} max-w-2xl">Zutaten wählen, das Netz rechts zeigt sofort, was harmoniert (★★★), was einen offenen Bedarf als Kontrast deckt und was sich stört. Unten filtern und suchen oder einen Kandidaten im Netz anklicken.</p>

                        <div class="flex flex-wrap gap-2">
                            <x-fa::select wire:model.live="composerCategory" aria-label="Kategorie" class="sm:w-56">
                                <option value="">Alle Kategorien</option>
                                @foreach($composerBrowse['kategorien'] as $kat)
                                    <option value="{{ $kat }}">{{ $kat }}</option>
                                @endforeach
                            </x-fa::select>
                            <x-fa::input type="search" wire:model.live.debounce.300ms="composerTerm"
                                placeholder="Zutat suchen" aria-label="Zutat suchen" class="flex-1 min-w-[12rem]" />
                        </div>

                        @if(!empty($composerAnker))
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($composerAnker as $a)
                                    <span wire:key="canker-{{ $a['id'] }}"
                                          class="inline-flex items-center gap-1 h-7 pl-2.5 pr-1 rounded-full border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]">
                                        {{ $a['label'] }}
                                        <button type="button" wire:click="composerRemove({{ $a['id'] }})" aria-label="{{ $a['label'] }} entfernen" title="Entfernen"
                                                class="inline-flex items-center justify-center w-5 h-5 rounded-full hover:bg-[var(--fa-accent-soft-hover)]">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-1.5">
                            <p class="{{ $hinweisText }}">
                                @if($composerFocus !== null && $composerFokusLabel)
                                    Punkt zeigt, was zu <span class="font-medium text-[var(--fa-accent)]">{{ $composerFokusLabel }}</span> passt. Klick nimmt die Zutat auf.
                                @else
                                    {{ number_format($composerBrowse['total'], 0, ',', '.') }} Zutaten. Punkt zeigt, wie gut sie zur Auswahl passt. Klick nimmt die Zutat auf.
                                @endif
                            </p>
                            <div class="max-h-64 overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] divide-y divide-[var(--fa-line)]">
                                @forelse($composerBrowse['items'] as $it)
                                    <button type="button" wire:key="cbrowse-{{ $it['id'] }}" wire:click="composerAdd({{ $it['id'] }})"
                                            class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left fa-t-body hover:bg-[var(--fa-hover)]">
                                        @php
                                            // Spec 60: ★★★ harmoniert · Kontrast deckt einen offenen Bedarf · Konflikt stört sich
                                            $punkt = ['stern3' => ['#fcd34d', '★★★ harmoniert'], 'kontrast' => ['#22d3ee', 'Kontrast: deckt einen offenen Bedarf'],
                                                'konflikt' => ['#f43f5e', 'Konflikt: stört sich mit der Auswahl']][$it['typ'] ?? ''] ?? null;
                                        @endphp
                                        @php $auch = ($it['auch_kontrast'] ?? null) ? ' · deckt auch '.$it['auch_kontrast'] : ''; @endphp
                                        <span class="w-2 h-2 rounded-full shrink-0" data-picker-typ="{{ $it['typ'] ?? '' }}" @if($auch) data-picker-auch-kontrast="{{ $it['auch_kontrast'] }}" @endif
                                              style="background: {{ $punkt[0] ?? 'transparent' }}; {{ $punkt ? '' : 'border:1px solid rgba(148,163,184,.35);' }} {{ $auch ? 'box-shadow: 0 0 0 1.5px #22d3ee;' : '' }}"
                                              title="{{ ($punkt[1] ?? 'kein Bezug zur Auswahl').$auch }}"></span>
                                        <span class="flex-1 truncate">{{ $it['label'] }}</span>
                                        @if($it['category'])
                                            <span class="fa-t-muted shrink-0">{{ $it['category'] }}</span>
                                        @endif
                                        @svg('heroicon-m-plus', 'w-4 h-4 shrink-0 text-[var(--fa-accent)]')
                                    </button>
                                @empty
                                    <x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Keine Zutat gefunden">Filter oder Suche anpassen.</x-fa::empty>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </x-fa::modal-section>

                {{-- Aus den gewählten Zutaten in einen Erstell-Reiter springen. Der Brief wird vorbefüllt, die
                     Zutaten reisen als verbindliche Leit-Aromen (seed_anker) mit; dort die Leitplanken setzen
                     und starten. --}}
                <x-fa::modal-section icon="heroicon-o-arrow-right-circle" title="Daraus weiterbauen">
                    <div class="flex flex-col gap-3">
                        <p class="{{ $hinweisText }}">Übernimmt die Zutaten als <span class="font-semibold text-[var(--fa-ink-2)]">verbindliche Leit-Aromen</span> und springt in den Erstell-Reiter. Der Brief ist vorbefüllt, dort die Leitplanken setzen und erstellen.</p>
                        <div class="flex flex-wrap gap-2">
                            <x-fa::button variant="primary" icon-right="heroicon-m-arrow-right" wire:click="composerUebernehmen('rezept')" x-on:click="tab='basisrezept'"
                                :disabled="empty($composerAnker)" data-composer-go-rezept>Als Basisrezept vorbereiten</x-fa::button>
                            <x-fa::button icon-right="heroicon-m-arrow-right" wire:click="composerUebernehmen('gericht')" x-on:click="tab='gericht'"
                                :disabled="empty($composerAnker)" data-composer-go-gericht>Als Gericht vorbereiten</x-fa::button>
                        </div>
                        @if(empty($composerAnker))
                            <p class="{{ $hinweisText }}">Erst mindestens eine Zutat wählen.</p>
                        @endif
                    </div>
                </x-fa::modal-section>

                {{-- „Passt das zusammen?" — Spec 60: dieselbe Kombinationslogik wie im Gericht-Panel
                     (Harmonie nur ★★★, Spannung, offener Bedarf, Konflikt, Klassiker — je mit Grundlage). --}}
                @if($composerKombination !== null)
                    <x-fa::modal-section icon="heroicon-o-link" title="Passt das zusammen?">
                        {{-- Zwei Sichten nebeneinander (Dominique 16.10.): Teller aus Komponenten oder eine Zubereitung. --}}
                        <x-fa::segmented label="Bewerten als" class="mb-3" data-composer-modus="">
                            @foreach(['gericht' => 'als Gericht', 'basisrezept' => 'als Basisrezept'] as $m => $mLabel)
                                <x-fa::segment :an="$composerModus === $m" size="md" data-composer-modus-knopf="{{ $m }}" wire:click="$set('composerModus', '{{ $m }}')">{{ $mLabel }}</x-fa::segment>
                            @endforeach
                        </x-fa::segmented>
                        <div wire:key="composer-kombination-{{ $composerModus }}">
                            <x-fa::kombination :daten="$composerKombination" />
                        </div>
                    </x-fa::modal-section>
                @endif
                </div>{{-- /linke Spalte --}}

                {{-- RECHTE SPALTE: Netz --}}
                <div class="min-w-0">
                {{-- Netz + Filter-Chips in EINER Alpine-Instanz (wie im Detail-Modal) --}}
                <x-fa::modal-section class="!mt-0" icon="heroicon-o-share" title="Netz">
                    @if($composerFocus !== null && $composerFokusLabel)
                        <x-slot:actions>
                            <span class="{{ $pill }} {{ $variantPill['primary'] }}">Fokus: {{ $composerFokusLabel }}</span>
                        </x-slot:actions>
                    @endif
                    @if(empty($composerAnker))
                        <p class="text-[13px] text-gray-500 max-w-2xl">
                            Noch keine Zutat gewählt — oben eine hinzufügen. Dann zeigt das Netz die ★★★-Partner,
                            welche Zutat einen offenen Bedarf als Kontrast deckt und was sich stört.
                        </p>
                    @else
                        @if($composerFocus !== null && $composerFokusLabel)
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-[11px]">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full {{ $variantPill['primary'] }} border border-violet-500/30">
                                    Fokus: {{ $composerFokusLabel }}
                                    <button type="button" wire:click="composerFocus({{ $composerFocus }})" class="text-violet-500 hover:text-violet-700 leading-none" title="Fokus aufheben">&times;</button>
                                </span>
                                <span class="text-gray-500">nur seine Verbindungen · Klick aufs Zentrum oder × hebt auf</span>
                            </div>
                        @else
                            <p class="mb-2 text-[10px] text-gray-500">Tipp: Klick auf einen Anker fokussiert ihn — nur seine Verbindungen + Stärke bleiben sichtbar.</p>
                        @endif
                        <div wire:ignore
                             wire:key="composer-netz-{{ $composerNetz['meta']['sig'] ?? '0' }}-f{{ $composerFocus ?? 0 }}"
                             x-data="pairingNetzGraph({
                                 nodes: @js($composerNetz['nodes']),
                                 edges: @js($composerNetz['edges']),
                                 mode: 'modal',
                                 canvasW: {{ (float) ($composerNetz['meta']['canvas_w'] ?? 1000) }},
                                 canvasH: {{ (float) ($composerNetz['meta']['canvas_h'] ?? 760) }},
                                 typDefault: @js($composerNetz['meta']['typ_default'] ?? ['stern3' => true, 'kontrast' => true]),
                                 focusId: {{ $composerFocus ?? 'null' }},
                                 onKandidatClick: (id) => $wire.composerAdd(id),
                                 onAnkerClick: (id) => $wire.composerFocus(id),
                             })">
                            {{-- Filter-Chips und Legende liegen AUSSERHALB des SVG, also auf der weissen Karte:
                                 Schrift auf Grau-Tokens, Ring-Offset auf Weiss (vorher slate-900 → dunkler
                                 Spalt um den aktiven Chip). Die Stern-Hex bleiben, sie spiegeln die Punktfarben
                                 im Netz. --}}
                            <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px]">
                                <span class="text-gray-500 mr-1">Zeigen:</span>
                                <button type="button" @click="toggleTyp('stern3')"
                                        :class="typAktiv['stern3'] ? 'ring-2 ring-offset-1 ring-offset-white' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-gray-700"
                                        style="border-color:#fcd34d; --tw-ring-color:#fcd34d;">
                                    <span class="w-2 h-2 rounded-full" style="background:#fcd34d"></span> ★★★ harmoniert
                                </button>
                                <button type="button" @click="toggleTyp('kontrast')"
                                        :class="typAktiv['kontrast'] ? 'ring-2 ring-offset-1 ring-offset-white' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-gray-700"
                                        style="border-color:#22d3ee; --tw-ring-color:#22d3ee;">
                                    <span class="w-2 h-2 rounded-full" style="background:#22d3ee"></span> Kontrast
                                </button>
                            </div>
                            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet"
                                 class="w-full h-[calc(100dvh-20rem)] min-h-[320px] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]" data-fa-netz-mount></svg>
                            {{-- Legende (Spec 60): Linien zwischen den gewählten Ankern --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-gray-700">
                                <span class="inline-flex items-center gap-2"><span class="inline-block w-5" style="border-top:3px solid #fcd34d"></span> harmoniert (★★★, gemessen)</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block w-5" style="border-top:2px dashed #f43f5e"></span> Konflikt (Anker-Wissen)</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block w-5" style="border-top:2px dotted #22d3ee"></span> Kontrast: deckt einen offenen Bedarf</span>
                                <span class="text-[10px] text-gray-500">· Hover zeigt, welchen Bedarf ein Kontrast deckt</span>
                            </div>
                        </div>
                    @endif
                </x-fa::modal-section>
                </div>{{-- /rechte Spalte --}}
                </div>{{-- /grid --}}
            </div>
            @endunless
            {{-- #1003 PR7: am Handy gibt es nur Gericht, Basisrezept, Concept und Import; die Ausgabe-Formen aus dem Brief,
                 Composer und Trendradar-Kombinierer bleiben am Computer (Dominique 30.10.). Öffnet eine solche Planung am Handy,
                 steht hier der Hinweis; Ergebnis und Kontext gehen trotzdem. --}}
            @if($handy)
                <div x-show="! ['gericht', 'basisrezept', 'concept', 'import', 'worker', 'kontext'].includes(tab)" x-cloak data-planung-handy-computer>
                    <x-fa::empty icon="heroicon-o-computer-desktop" title="Am Computer bearbeiten">Diese Art der Planung (Foodbook, Speisekarte, Speiseplan, Angebot oder Format aus einem Brief, Composer, Trendradar) wird am Computer bearbeitet. Ergebnis und Kontext gehen auch hier.</x-fa::empty>
                </div>
                <div wire:key="planung-tab-kontext" x-show="tab==='kontext'" x-cloak class="flex flex-col gap-4" data-planung-handy-kontext>
                    @include('foodalchemist::livewire.planung.partials.detail-inhalt', ['imEditor' => true])
                </div>
            @endif
        @endif
    </x-fa::modal>
    {{-- Leitstelle-In-Context: die erzeugten Entwürfe (Basisrezept/Gericht) im Cockpit ansehen,
         statt auf die Listen-Seite zu springen. DOM NACH dem Editor-Modal → z-Stacking (öffnet darüber). --}}
    <livewire:foodalchemist.recipes.recipe-modal key="planung-index--recipes.recipe-modal" />
    <livewire:foodalchemist.recipes.fassung-modal key="planung-index--recipes.fassung-modal" />
    {{-- Editor-Kette (Dominique 2026-10-13): Rezept → Grundprodukt → Lieferantenartikel muss auf jeder Seite mit Rezept-Editor durchklickbar sein; fehlt ein Glied, läuft der Klick still ins Leere. Wächter: EditorKetteTest. --}}
    <livewire:foodalchemist.gps.gp-modal key="planung-index--gps.gp-modal" />
    <livewire:foodalchemist.suppliers.item-modal key="planung-index--suppliers.item-modal" />
    <livewire:foodalchemist.recipes.pairing-netz-modal key="planung-index--recipes.pairing-netz-modal" />{{-- „Netz öffnen" aus dem Rezept-/Gericht-Editor --}}
    <livewire:foodalchemist.verkauf.vk-modal key="planung-index--verkauf.vk-modal" />
    {{-- Vollen Conceptor-Editor inline: ein erzeugtes Concept öffnet mit allen Tabs/KPIs/Score/Kalkulation/
         Geschirr direkt hier (öffnet via concepter-editor.oeffnen aus der step-zeile). Gleiches Muster wie Angebote. --}}
    <livewire:foodalchemist.concepter.editor key="planung-index--concepter.editor" />
    {{-- Agent-am-Brief (Nachtrag, Dominique-Abnahme): das Panel lebt NICHT mehr hier auf der
         Board-Ebene — es sass falsch (oben rechts neben den Kanban-Spalten, nicht am Brief/
         den Leitplanken). Je EIN Panel pro Scope-Tab, direkt im Erstellen-Bereich
         (`erstellen-tab.blade.php` für rezept/gericht, oben im Concept-Block für concept). --}}
</x-ui-page>
