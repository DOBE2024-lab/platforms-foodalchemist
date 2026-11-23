{{-- Tagesplanung + Küchenmonitor — fa-pass (2026-10-05), Bausteine <x-fa::…> und --fa-*-Tokens.

     Häufigste Aufgaben:
       Tagesplanung (Küchenleitung): Tageshorizont lesen, Engpässe je Posten erkennen, einen Tag öffnen.
       Küchenmonitor (Koch am Pass): Aufgabe antippen, Anleitung lesen, abhaken.

     Grundanordnung wie vorher (Seitenleiste links Zeitraum/Posten, rechts Tagesdetail, Mitte Dashboard;
     Editor mit drei Spalten; Monitor mit Kopf + Posten-Spalten). Neu ist die Passform auf kleinen
     Bildschirmen (Laptop): Raster füllen nach verfügbarer Breite statt nach festen Bildschirm-Stufen,
     Posten-Spalten liegen nebeneinander und scrollen seitlich, statt sich in halbe Höhen zu teilen,
     und der Monitor-Kopf wird erst auf hohen Bildschirmen groß.

     Der Küchenmonitor läuft im Kiosk-Rahmen und schaltet über data-fa-theme="dark" die Werkbank-Tokens.
     Dort gelten Fernsicht-Größen (Tailwind-Skala), Farben trotzdem nur über Tokens.
     Alle wire:-Bindungen, wire:keys, Event-Namen und data-Marker unverändert. --}}
@php
    $istWall = $display === 'wall';

    // Auslastungs-Stufe → Zustandston (ein Ort für alle Ansichten).
    $stufeTon = \Platform\FoodAlchemist\Support\Produktion\WallStil::stufeTon(...);
    $stufeText = \Platform\FoodAlchemist\Support\Produktion\WallStil::stufeText(...);
    $tonFlaeche = \Platform\FoodAlchemist\Support\Produktion\WallStil::TON_FLAECHE;
    $tonBalken = \Platform\FoodAlchemist\Support\Produktion\WallStil::TON_BALKEN;
    $tonRand = \Platform\FoodAlchemist\Support\Produktion\WallStil::TON_RAND;

    // Seitenlokale Klassen (nur Tokens).
    $eyebrow = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]';
    $chip = 'inline-flex items-center h-7 px-2.5 rounded-full border text-[length:var(--fa-text-md)] transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';
@endphp

<div class="{{ $istWall ? '' : 'h-full min-h-0' }}">
@if($istWall)
    <livewire:foodalchemist.produktion.tagesplan-wall :von="$von" :bis="$bis" :tage="$tage" :posten-filter="$postenFilter" key="tagesplan-wall" />
@else
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar :title="$istWall ? 'Küchenmonitor' : 'Tagesplan'" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    @php
        $blattUrl = route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht]));
    @endphp

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => $istWall ? 'Küchenmonitor' : 'Tagesplanung'],
        ]">
            <div class="flex flex-wrap items-center gap-2">
                <x-fa::button size="sm" variant="secondary" icon="heroicon-o-tv" :href="route('foodalchemist.produktion.wandmonitor', ['von' => $von, 'tage' => 1])"
                              wire:navigate data-tagesplan-wall-toggle>Küchenmonitor öffnen</x-fa::button>
                <x-fa::menu size="sm" breite="60">
                    <x-fa::menu-item icon="heroicon-o-printer" :href="$blattUrl" abstand="weit" target="_blank" data-tagesplan-drucken>Produktionsblatt drucken</x-fa::menu-item>
                    <x-fa::menu-item icon="heroicon-o-arrow-down-tray" :href="$blattUrl . (str_contains($blattUrl, '?') ? '&' : '?') . 'pdf=1'" abstand="weit" data-tagesplan-pdf>Produktionsblatt als PDF</x-fa::menu-item>
                </x-fa::menu>
                @if(! $istWall)
                    <x-fa::button size="sm" variant="primary" icon="heroicon-o-pencil-square"
                                  :href="route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage, 'ansicht' => $ansicht])"
                                  wire:navigate x-data x-on:click="$dispatch('modal.open', { name: 'tagesplan-editor' })"
                                  data-tagesplan-editor-link>Im Editor öffnen</x-fa::button>
                @endif
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    @if(! $istWall)
        <x-slot name="sidebar">
            <x-fa::page-sidebar title="Zeitraum und Posten" width="w-72">
                <div class="p-3 space-y-5" data-tagesplanung-sidebar>
                    <div class="space-y-2" data-tagesplan-steuerung>
                        <p class="{{ $eyebrow }}">Zeitraum</p>
                        <div class="flex items-center gap-1">
                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-left" label="Eine Woche zurück" wire:click="dashboardTagVerschieben(-7)" />
                            <x-fa::button size="sm" variant="secondary" wire:click="dashboardHeute" class="flex-1" data-tagesplan-heute>Heute</x-fa::button>
                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-right" label="Eine Woche vor" wire:click="dashboardTagVerschieben(7)" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <x-fa::field label="Von" for="tp-von">
                                <x-fa::input type="date" id="tp-von" size="sm" wire:model.live="von" data-tagesplan-von />
                            </x-fa::field>
                            <x-fa::field label="Bis" for="tp-bis">
                                <x-fa::input type="date" id="tp-bis" size="sm" wire:model.live="bis" data-tagesplan-bis />
                            </x-fa::field>
                        </div>
                        <div class="flex flex-wrap gap-1" role="group" aria-label="Zeitfenster" data-tagesplanung-dashboard-fenster>
                            @foreach([3 => '3 Tage', 7 => '7 Tage', 14 => '14 Tage', 30 => 'Monat'] as $n => $lbl)
                                <button type="button" wire:click="waehleDashboardFenster({{ $n }})" aria-pressed="{{ $dashboard['fenster'] === $n ? 'true' : 'false' }}"
                                        class="{{ $chip }} {{ $dashboard['fenster'] === $n ? $chipAn : $chipAus }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-2" data-tagesplan-postenfilter>
                        <p class="{{ $eyebrow }}">Posten</p>
                        <div class="space-y-1">
                            <x-fa::filter-row wire:click="postenWaehlen(null)" :active="$postenFilter === null">
                                <span class="font-medium">Alle Posten</span>
                            </x-fa::filter-row>
                            <x-fa::filter-ast>
                                @foreach($postenListe as $p)
                                    <x-fa::filter-row level="child" wire:key="tps-{{ $p->id }}"
                                        wire:click="postenWaehlen({{ $p->id }})"
                                        :active="$postenFilter === $p->id">{{ $p->name }}</x-fa::filter-row>
                                @endforeach
                            </x-fa::filter-ast>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <x-fa::button variant="secondary" icon="heroicon-o-arrows-right-left" wire:click="vorschlagen" class="w-full" data-tagesplan-vorschlagen>
                            Verteilung vorschlagen
                        </x-fa::button>
                        <p class="fa-t-muted">Rechnet Vorlauftage so, dass kein Posten über Last läuft. Übernommen wird erst nach Bestätigung.</p>
                    </div>
                </div>
            </x-fa::page-sidebar>
        </x-slot>

        <x-slot name="activity">
            <x-fa::detail-sidebar title="Tagesdetail" width="w-80" :maxWidth="760"
                                             scope="activity_tagesplan" side="right">
                {{-- Anatomie Detail-Panels (DESIGN.md): Kopf (Tag, Last, „Im Editor öffnen") · Kennzahlen · offene Punkte
                     (Posten über Last) · Inhalt (Posten mit ihren Positionen). Ohne gewählten Tag: Übersicht des Zeitraums. --}}
                <x-fa::page data-tagesplanung-activity="">
                    @if($selectedDay && $tagDetail)
                        @php
                            $tagPosten = collect($tagDetail['auslastung'])->filter(function ($b) use ($tagDetail) {
                                $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                return $tagDetail['posten']->get($schluessel, collect())->isNotEmpty();
                            });
                            $tagUeberlast = $tagPosten->filter(fn ($b) => ($b['stufe'] ?? null) === 'ueberlast');
                            $tagEng = $tagPosten->filter(fn ($b) => ($b['stufe'] ?? null) === 'eng');
                            $tagOhneZeit = $tagDetail['zeilen']->filter(fn ($z) => $z->arbeitszeit_min === null)->count();
                            $tagDatum = \Illuminate\Support\Carbon::parse($tagDetail['tag']);
                        @endphp
                        <div class="flex flex-col gap-5" data-tagesplanung-tagdetail>
                            <x-fa::detail-kopf :title="$tagDatum->locale('de')->isoFormat('dddd, DD.MM.')"
                                :subtitle="$tagDetail['zeilen']->count() . ' ' . ($tagDetail['zeilen']->count() === 1 ? 'Position' : 'Positionen') . ' · ' . $tagDetail['minuten'] . ' min geplant'">
                                <x-slot:badges>
                                    @if($tagUeberlast->isNotEmpty())
                                        <x-fa::badge tone="crit">{{ $stufeText('ueberlast') }}</x-fa::badge>
                                    @elseif($tagEng->isNotEmpty())
                                        <x-fa::badge tone="warn">{{ $stufeText('eng') }}</x-fa::badge>
                                    @elseif($tagPosten->contains(fn ($b) => ($b['stufe'] ?? null) === 'ok'))
                                        <x-fa::badge tone="ok">{{ $stufeText('ok') }}</x-fa::badge>
                                    @elseif($tagPosten->isNotEmpty())
                                        <x-fa::badge :tone="$stufeTon($tagPosten->first()['stufe'] ?? null)">{{ $stufeText($tagPosten->first()['stufe'] ?? null) }}</x-fa::badge>
                                    @endif
                                    @if($tagDatum->isToday())<x-fa::badge tone="info">Heute</x-fa::badge>@endif
                                </x-slot:badges>
                                @if($modus === 'dashboard' && ! $istWall)
                                    <x-slot:aktion>
                                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-pencil-square"
                                            :href="route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage, 'ansicht' => $ansicht, 'tag' => $tagDetail['tag']])"
                                            wire:navigate x-data x-on:click="$dispatch('modal.open', { name: 'tagesplan-editor' })">Im Editor öffnen</x-fa::button>
                                    </x-slot:aktion>
                                @endif
                                <x-slot:menue>
                                    <x-fa::menu-item icon="heroicon-m-squares-2x2" wire:click="waehleTag(null)">Zur Übersicht</x-fa::menu-item>
                                </x-slot:menue>
                            </x-fa::detail-kopf>

                            <x-fa::kpis :items="[
                                ['label' => 'Geplant', 'value' => $tagDetail['minuten'] . ' min', 'primary' => true, 'kpi' => 'tag-minuten'],
                                ['label' => 'Positionen', 'value' => (string) $tagDetail['zeilen']->count(), 'kpi' => 'tag-positionen'],
                                ['label' => 'Posten', 'value' => (string) $tagPosten->count(), 'kpi' => 'tag-posten'],
                            ]" />

                            @if($tagUeberlast->isNotEmpty() || $tagEng->isNotEmpty() || $tagOhneZeit > 0)
                                <div class="flex flex-col gap-1">
                                    @foreach($tagUeberlast as $b)
                                        <x-fa::signal tone="crit">{{ $b['station'] }}: {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min, über Last.</x-fa::signal>
                                    @endforeach
                                    @foreach($tagEng as $b)
                                        <x-fa::signal tone="warn">{{ $b['station'] }}: {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min, eng.</x-fa::signal>
                                    @endforeach
                                    @if($tagOhneZeit > 0)
                                        <x-fa::signal tone="warn">{{ $tagOhneZeit }} {{ $tagOhneZeit === 1 ? 'Position' : 'Positionen' }} ohne Arbeitszeit.</x-fa::signal>
                                    @endif
                                </div>
                            @endif

                            <div class="flex flex-col">
                                @foreach($tagPosten as $b)
                                    @php
                                        $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                        $postenZeilen = $tagDetail['posten']->get($schluessel, collect());
                                        $ton = $stufeTon($b['stufe'] ?? null);
                                    @endphp
                                    <x-fa::section variant="plain" :title="$b['station']" icon="heroicon-o-users"
                                        :meta="$b['geplant_min'] . ($b['kapazitaet_min'] !== null ? ' von ' . $b['kapazitaet_min'] : '') . ' min'" data-tagesplanung-tagdetail-posten>
                                        <x-slot:actions>
                                            <x-fa::badge :tone="$ton">{{ $stufeText($b['stufe'] ?? null) }}</x-fa::badge>
                                        </x-slot:actions>
                                        <ul class="flex flex-col">
                                            @foreach($postenZeilen as $z)
                                                <li class="py-1.5 border-b border-[var(--fa-line)] last:border-0">
                                                    <p class="fa-t-strong break-words">{{ $z->name }}</p>
                                                    <p class="fa-t-muted break-words">{{ $z->auftrag }} · {{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'keine Zeit' }} · für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </x-fa::section>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="flex flex-col">
                            <x-fa::section variant="plain" title="Als Nächstes" icon="heroicon-o-queue-list" data-tagesplanung-dashboard-next>
                                @if($dashboard['naechstes']->isEmpty())
                                    <x-fa::empty compact icon="heroicon-o-check-circle" title="Nichts offen im Zeitraum" />
                                @else
                                    <ul class="flex flex-col">
                                        @foreach($dashboard['naechstes'] as $z)
                                            <li class="py-1.5 border-b border-[var(--fa-line)] last:border-0">
                                                <p class="fa-t-strong break-words">{{ $z->name }}</p>
                                                <p class="fa-t-muted break-words">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->station ?: 'ohne Posten' }} · {{ $z->auftrag }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-fa::section>

                            <x-fa::section variant="plain" title="Engpässe" icon="heroicon-o-chart-bar" description="Auslastung je Posten im Zeitraum." data-tagesplanung-dashboard-performance>
                                @if($dashboard['performance']->isEmpty())
                                    <x-fa::empty compact icon="heroicon-o-chart-bar" title="Noch keine Auslastung">Sobald Aufträge eingeplant sind, steht hier die Last je Posten.</x-fa::empty>
                                @else
                                    <div class="flex flex-col gap-2.5">
                                        @foreach($dashboard['performance']->take(8) as $p)
                                            @php
                                                $breite = $p['prozent'] !== null ? min(100, max(4, $p['prozent'])) : 12;
                                                $balkenTon = ($p['kritisch'] ?? 0) > 0 ? 'crit' : (($p['eng'] ?? 0) > 0 ? 'warn' : 'info');
                                            @endphp
                                            <div>
                                                <div class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]">
                                                    <span class="min-w-0 truncate font-medium text-[var(--fa-ink-2)]" title="{{ $p['station'] }}">{{ $p['station'] }}</span>
                                                    <span class="shrink-0 tabular-nums text-[var(--fa-ink-3)]">{{ $p['prozent'] !== null ? $p['prozent'] . ' %' : (int) $p['minuten'] . ' min' }}</span>
                                                </div>
                                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-[var(--fa-neutral-soft)]">
                                                    <div class="h-full rounded-full {{ $tonBalken[$balkenTon] }}" style="width: {{ $breite }}%"></div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </x-fa::section>
                        </div>
                    @endif
                </x-fa::page>
            </x-fa::detail-sidebar>
        </x-slot>

        <livewire:foodalchemist.produktion.editor key="produktion-tagesplan--produktion.editor" />

        <x-fa::modal name="tagesplan-editor" fullscreen dark-canvas title="Tagesplan"
                                :title-name="$ansicht === 'gericht' ? 'Nach Gerichten' : 'Nach Posten'"
                                :close-via="'editorSchliessen'">
            <x-slot:actions>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <x-fa::menu breite="60">
                        <x-fa::menu-item icon="heroicon-o-printer" :href="$blattUrl" abstand="weit" target="_blank" data-tagesplan-drucken>Produktionsblatt drucken</x-fa::menu-item>
                        <x-fa::menu-item icon="heroicon-o-arrow-down-tray" :href="$blattUrl . (str_contains($blattUrl, '?') ? '&' : '?') . 'pdf=1'" abstand="weit" data-tagesplan-pdf>Produktionsblatt als PDF</x-fa::menu-item>
                    </x-fa::menu>
                    <x-fa::button variant="primary" icon="heroicon-o-arrows-right-left" wire:click="vorschlagen" wire:loading.attr="disabled" wire:target="vorschlagen" data-tagesplan-vorschlagen>Verteilung vorschlagen</x-fa::button>
                </div>
            </x-slot:actions>

            <x-slot:kpiHeader>
                <x-fa::kpi-tiles marker="tagesplan-editor" :tiles="[
                    ['kpi' => 'offen', 'label' => 'Offen', 'tone' => 'accent', 'value' => (string) $dashboard['kpis']['offen']],
                    ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $dashboard['kpis']['minuten'] . ' min'],
                    ['kpi' => 'manntage', 'label' => 'Manntage', 'value' => number_format($dashboard['kpis']['minuten'] / 480, 1, ',', '.'), 'title' => '1 Manntag = 480 Minuten'],
                    ['kpi' => 'ueberlast', 'label' => 'Überlast', 'tone' => $dashboard['kpis']['ueberlast'] > 0 ? 'bad' : 'neutral', 'value' => (string) $dashboard['kpis']['ueberlast'], 'title' => 'Posten-Tage über der Kapazität'],
                    ['kpi' => 'posten', 'label' => 'Posten belegt', 'value' => (string) $dashboard['kpis']['posten']],
                ]" />
            </x-slot:kpiHeader>

            <section data-tagesplan-editor>
                @if($fehler)<x-fa::notice tone="crit" class="mb-4" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif

                @if($vorschlag !== null)
                    {{-- Vorschlag steht OBEN: er ist die offene Entscheidung, nicht ein Nachtrag unter der Liste. --}}
                    <x-fa::notice tone="info" title="Vorschlag zur Verteilung" class="mb-4" data-tagesplan-vorschlag>
                        {{ $vorschlag['aenderungen'] }} {{ (int) $vorschlag['aenderungen'] === 1 ? 'Änderung' : 'Änderungen' }} am Vorlauf, damit die Posten nicht über Last laufen.
                        <x-slot:actions>
                            <x-fa::button size="sm" variant="ghost" wire:click="vorschlagVerwerfen">Verwerfen</x-fa::button>
                            <x-fa::button size="sm" variant="secondary" icon="heroicon-m-check" wire:click="vorschlagUebernehmen">Vorschlag übernehmen</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                @endif

                {{-- Drei Spalten wie gehabt. Auf dem Laptop schmalere Seitenspalten; die Mitte scrollt mit dem
                     Editor statt in einem eigenen 70-%-Kasten, die Seitenspalten bleiben dabei stehen. --}}
                <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[13rem_minmax(0,1fr)_15rem] 2xl:grid-cols-[16rem_minmax(0,1fr)_20rem]">
                    <x-fa::card as="aside" class="space-y-4 p-3 xl:sticky xl:top-0" data-tagesplan-postenfilter="">
                        <div class="space-y-2" data-tagesplan-steuerung>
                            <p class="{{ $eyebrow }}">Zeitraum</p>
                            <div class="flex items-center gap-1">
                                <x-fa::icon-button size="sm" icon="heroicon-m-chevron-left" label="Eine Woche zurück" wire:click="verschiebe(-7)" />
                                <x-fa::button size="sm" variant="secondary" wire:click="heute" class="flex-1" data-tagesplan-heute>Heute</x-fa::button>
                                <x-fa::icon-button size="sm" icon="heroicon-m-chevron-right" label="Eine Woche vor" wire:click="verschiebe(7)" />
                            </div>
                            <x-fa::field label="Von" for="tp-ed-von"><x-fa::input type="date" id="tp-ed-von" size="sm" wire:model.live="von" /></x-fa::field>
                            <x-fa::field label="Bis" for="tp-ed-bis"><x-fa::input type="date" id="tp-ed-bis" size="sm" wire:model.live="bis" /></x-fa::field>
                        </div>

                        <div class="space-y-2">
                            <p class="{{ $eyebrow }}">Posten</p>
                            <div class="flex flex-wrap gap-1">
                                @foreach($postenListe as $p)
                                    <button type="button" wire:click="postenWaehlen({{ $p->id }})" wire:key="tpf-modal-{{ $p->id }}" aria-pressed="{{ $postenFilter === $p->id ? 'true' : 'false' }}"
                                            class="{{ $chip }} {{ $postenFilter === $p->id ? $chipAn : $chipAus }}">{{ $p->name }}</button>
                                @endforeach
                            </div>
                            @if($postenFilter !== null)
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="postenWaehlen(null)">Alle Posten zeigen</x-fa::button>
                            @endif
                        </div>
                    </x-fa::card>

                    <main class="min-w-0 space-y-4">
                        @forelse($zeilenNachTag as $tag => $zeilen)
                            @php
                                $tagC = \Illuminate\Support\Carbon::parse($tag);
                                $nachPosten = $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id);
                            @endphp
                            <x-fa::card as="section" data-modal-zone="section" class="overflow-hidden" data-tagesplan-tag="{{ $tag }}">
                                <div class="flex flex-wrap items-baseline gap-2 border-b border-[var(--fa-line)] px-4 py-3">
                                    <h3 class="fa-t-h2">{{ $tagC->locale('de')->isoFormat('dd, DD.MM.') }}</h3>
                                    @if($tagC->isToday())<x-fa::badge tone="accent">Heute</x-fa::badge>@endif
                                    <span class="fa-t-muted tabular-nums">{{ $zeilen->count() }} {{ $zeilen->count() === 1 ? 'Position' : 'Positionen' }}</span>
                                </div>
                                @foreach($auslastung[$tag] ?? [] as $b)
                                    @php
                                        $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                        $blockZeilen = $nachPosten[$schluessel] ?? collect();
                                    @endphp
                                    @continue($blockZeilen->isEmpty())
                                    @php
                                        $ton = $stufeTon($b['stufe'] ?? null);
                                    @endphp
                                    <div class="border-b border-[var(--fa-line)] px-4 py-3 last:border-b-0" data-tagesplan-auslastung>
                                        {{-- Kopfzeile je Posten bricht um statt feste Breiten zu erzwingen (Laptop) --}}
                                        <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                            <span class="min-w-0 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $b['station'] }}</span>
                                            <span class="fa-t-muted tabular-nums">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) von {{ $b['kapazitaet_min'] }}@endif min</span>
                                            <span class="h-1.5 min-w-16 flex-1 overflow-hidden rounded-full bg-[var(--fa-neutral-soft)]">
                                                @if($b['kapazitaet_min'] !== null)
                                                    <span class="block h-full {{ $tonBalken[$ton] }}" style="width: {{ min(100, (int) ($b['prozent'] ?? 0)) }}%"></span>
                                                @endif
                                            </span>
                                            @if($b['stufe'] === 'ueberlast')
                                                <x-fa::badge tone="crit">{{ $b['prozent'] }} % Überlast</x-fa::badge>
                                            @elseif($b['stufe'] === 'eng')
                                                <x-fa::badge tone="warn">{{ $b['prozent'] }} % Eng</x-fa::badge>
                                            @endif
                                            @if($b['ohne_zeit'] > 0)<x-fa::signal tone="warn">{{ $b['ohne_zeit'] }} ohne Zeit</x-fa::signal>@endif
                                        </div>
                                        <div class="overflow-x-auto">
                                            <x-fa::table compact>
                                                <thead>
                                                    <tr>
                                                        <th class="w-px"><span class="sr-only">Erledigt</span></th>
                                                        <th>Rezept</th>
                                                        <th class="num">Ansätze</th>
                                                        <th class="num">Zeit</th>
                                                        <th>Auftrag</th>
                                                        <th class="num" title="Tage Vorlauf vor dem Liefertag">Vorlauf (Tage)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($blockZeilen as $z)
                                                        @php
                                                            $erledigt = $z->line_status === 'done';
                                                            $laeuft = $z->auftrag_status === 'in_progress';
                                                        @endphp
                                                        <tr class="{{ $erledigt ? 'opacity-60' : '' }}" wire:key="tpz-modal-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}">
                                                            <td class="w-px">
                                                                @if($laeuft)
                                                                    <button type="button" wire:click="abhaken({{ $z->id }})"
                                                                            class="grid h-6 w-6 place-items-center rounded-[var(--fa-radius-control)] border {{ $erledigt ? 'border-[var(--fa-ok)] bg-[var(--fa-ok)] text-[var(--fa-surface)]' : 'border-[var(--fa-line-strong)] hover:border-[var(--fa-accent)]' }}"
                                                                            title="{{ $erledigt ? 'Haken zurücknehmen' : 'Als erledigt abhaken' }}"
                                                                            aria-label="{{ $erledigt ? 'Haken zurücknehmen' : 'Als erledigt abhaken' }}" data-tagesplan-abhaken>@if($erledigt)@svg('heroicon-m-check', 'w-4 h-4')@endif</button>
                                                                @else
                                                                    <span class="inline-block h-6 w-6 rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line-strong)]" title="Abhaken geht erst, wenn der Auftrag in Arbeit ist."></span>
                                                                @endif
                                                            </td>
                                                            <td class="min-w-48 {{ $erledigt ? 'line-through' : '' }}">
                                                                <span class="font-medium text-[var(--fa-ink)] break-words">{{ $z->name }}</span>@if($z->assignee)<span class="ml-1 fa-t-muted">· {{ $z->assignee }}</span>@endif
                                                            </td>
                                                            <td class="num">{{ rtrim(rtrim(number_format($z->ansaetze_effektiv, 2, ',', '.'), '0'), ',') }}</td>
                                                            <td class="num">@if($z->arbeitszeit_min !== null){{ $z->arbeitszeit_min }} min @else<x-fa::signal tone="warn">fehlt</x-fa::signal>@endif</td>
                                                            <td class="whitespace-nowrap">
                                                                <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $z->order_id }} })" class="text-[var(--fa-accent)] hover:underline" title="Auftrag öffnen" data-tagesplan-auftrag>{{ $z->auftrag }}</button>
                                                                <span class="ml-1 fa-t-muted">für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span>
                                                            </td>
                                                            <td class="num w-px">
                                                                <x-fa::input size="sm" numeric inputmode="numeric" value="{{ $z->vorlauf_tage }}" wire:change="vorlaufSetzen({{ $z->id }}, $event.target.value)"
                                                                             class="w-16" title="Tage Vorlauf vor dem Liefertag" aria-label="Vorlauf in Tagen für {{ $z->name }}" data-tagesplan-vorlauf />
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </x-fa::table>
                                        </div>
                                    </div>
                                @endforeach
                            </x-fa::card>
                        @empty
                            <x-fa::card data-modal-zone="section" data-tagesplan-leer="">
                                <x-fa::empty icon="heroicon-o-calendar" title="In diesem Zeitraum steht nichts an.">
                                    Der Tagesplan zeigt Positionen aus geplanten und laufenden Aufträgen. Zeitraum erweitern oder einen Auftrag einplanen.
                                </x-fa::empty>
                            </x-fa::card>
                        @endforelse
                    </main>

                    <x-fa::card as="aside" class="p-3 xl:sticky xl:top-0" data-tagesplan-next="">
                        <p class="{{ $eyebrow }}">Als Nächstes</p>
                        @if($dashboard['naechstes']->isEmpty())
                            <x-fa::empty compact icon="heroicon-o-check-circle" title="Nichts offen" />
                        @else
                            <ul class="mt-2 divide-y divide-[var(--fa-line)]">
                                @foreach($dashboard['naechstes'] as $z)
                                    <li data-modal-zone="section" class="py-2">
                                        <p class="fa-t-strong break-words">{{ $z->name }}</p>
                                        <p class="fa-t-muted">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->station ?: 'ohne Posten' }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-fa::card>
                </div>
            </section>
        </x-fa::modal>

        <x-ui-page-container padding="px-4 pb-6 lg:px-6" spacing="space-y-4">
            @if($fehler)<x-fa::notice tone="crit" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif

            @if($vorschlag !== null)
                <x-fa::notice tone="info" title="Vorschlag zur Verteilung" data-tagesplan-vorschlag>
                    {{ $vorschlag['aenderungen'] }} {{ (int) $vorschlag['aenderungen'] === 1 ? 'Änderung' : 'Änderungen' }} am Vorlauf, damit die Posten nicht über Last laufen.
                    <x-slot:actions>
                        <x-fa::button size="sm" variant="ghost" wire:click="vorschlagVerwerfen">Verwerfen</x-fa::button>
                        <x-fa::button size="sm" variant="secondary" icon="heroicon-m-check" wire:click="vorschlagUebernehmen">Vorschlag übernehmen</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            @endif

            <section class="space-y-3" data-tagesplanung-dashboard>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        {{-- 98 · 2.4f B: ein Name je Seite (Navbar „Tagesplan“) — Eyebrow entfällt, die Zeile ist Abschnittsüberschrift --}}
                        <h2 class="fa-t-h">Auslastung und Tageshorizont</h2>
                        <p class="mt-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] tabular-nums">
                            {{ \Illuminate\Support\Carbon::parse($dashboard['von'])->locale('de')->isoFormat('dd, DD.MM.YYYY') }}
                            bis {{ \Illuminate\Support\Carbon::parse($dashboard['bis'])->locale('de')->isoFormat('dd, DD.MM.YYYY') }}
                        </p>
                    </div>
                </div>

                <x-fa::kpis data-tagesplanung-dashboard-kpis :items="[
                    ['label' => 'Offen', 'value' => number_format($dashboard['kpis']['offen'], 0, ',', '.'), 'primary' => true, 'title' => 'Noch zu produzieren'],
                    ['label' => 'Positionen', 'value' => number_format($dashboard['kpis']['speisen'], 0, ',', '.'), 'title' => 'Alle Positionen im Zeitraum'],
                    ['label' => 'Arbeitszeit', 'value' => number_format($dashboard['kpis']['minuten'], 0, ',', '.') . ' min', 'title' => 'Geplante Minuten'],
                    ['label' => 'Überlast', 'value' => number_format($dashboard['kpis']['ueberlast'], 0, ',', '.'), 'tone' => $dashboard['kpis']['ueberlast'] > 0 ? 'crit' : null, 'title' => 'Posten-Tage über der Kapazität'],
                    ['label' => 'Posten belegt', 'value' => number_format($dashboard['kpis']['posten'], 0, ',', '.')],
                ]" />
            </section>

            <section class="space-y-4">
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,24rem),1fr))] gap-4">
                        <x-fa::section title="Planung in Manntagen" icon="heroicon-o-users" meta="1 Manntag = 480 min" data-tagesplanung-dashboard-manntage>
                            <div class="flex min-h-36 items-end gap-1.5 overflow-x-auto pb-1">
                                @foreach($dashboard['manntage'] as $tag)
                                    @php
                                        $hoehe = max(8, (int) round(($tag['wert'] / $dashboard['maxManntage']) * 112));
                                    @endphp
                                    <div class="min-w-9 flex-1 text-center" title="{{ \Illuminate\Support\Carbon::parse($tag['tag'])->locale('de')->isoFormat('dddd, DD.MM.') }}: {{ number_format($tag['wert'], 1, ',', '.') }} Manntage">
                                        <p class="text-[length:var(--fa-text-sm)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ number_format($tag['wert'], 1, ',', '.') }}</p>
                                        <div class="mx-auto mt-1 rounded-t-[var(--fa-radius-control)] bg-[var(--fa-info)]" style="height: {{ $hoehe }}px"></div>
                                        <p class="mt-1 fa-t-muted tabular-nums">{{ \Illuminate\Support\Carbon::parse($tag['tag'])->format('d.m.') }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-fa::section>

                        <x-fa::section title="Produktion" icon="heroicon-o-signal" meta="Posten-Tage im Zeitraum" data-tagesplanung-dashboard-produktion>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach([
                                    ['label' => 'Pünktlich', 'wert' => $dashboard['produktionAmpeln']['puenktlich'], 'ton' => 'ok'],
                                    ['label' => 'Eng', 'wert' => $dashboard['produktionAmpeln']['verspaetet'], 'ton' => 'warn'],
                                    ['label' => 'Kritisch', 'wert' => $dashboard['produktionAmpeln']['kritisch'], 'ton' => 'crit'],
                                ] as $ampel)
                                    <div class="rounded-[var(--fa-radius-surface)] px-3 py-4 text-center {{ $tonFlaeche[$ampel['ton']] }}">
                                        <p class="text-[length:var(--fa-text-2xl)] font-semibold tabular-nums leading-tight">{{ $ampel['wert'] }}</p>
                                        <p class="mt-1 text-[length:var(--fa-text-sm)] font-medium">{{ $ampel['label'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-fa::section>
                    </div>

                    <x-fa::section title="Tageshorizont" icon="heroicon-o-calendar-days" description="Tag antippen, um rechts die Posten und Positionen zu sehen." data-tagesplanung-dashboard-horizont>
                        {{-- Füllt nach verfügbarer Breite (Seitenleisten offen oder zu, Laptop oder großer Monitor) --}}
                        <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,14rem),1fr))] gap-3">
                            @foreach($dashboard['tage'] as $tag)
                                @php
                                    $tagZeilen = $dashboard['zeilenNachTag']->get($tag, collect());
                                    $tagBuckets = collect($dashboard['auslastung'][$tag] ?? []);
                                    $tagUeberlast = $tagBuckets->where('stufe', 'ueberlast')->count();
                                    $tagEng = $tagBuckets->where('stufe', 'eng')->count();
                                    [$tagTon, $tagText] = $tagUeberlast > 0 ? ['crit', 'Überlast'] : ($tagEng > 0 ? ['warn', 'Eng'] : ($tagZeilen->isNotEmpty() ? ['ok', 'Im Plan'] : ['neutral', 'Frei']));
                                    $tagGewaehlt = $selectedDay === $tag;
                                    $tagCarbon = \Illuminate\Support\Carbon::parse($tag)->locale('de');
                                @endphp
                                <x-fa::card as="article" wire:click="waehleTag('{{ $tag }}')" x-data x-on:click="$store.ui?.mSet('activity_tagesplan', 'open', true)" role="button" tabindex="0" aria-pressed="{{ $tagGewaehlt ? 'true' : 'false' }}" class="flex cursor-pointer flex-col gap-3 border-t-4 p-3 transition-colors hover:bg-[var(--fa-hover)] {{ $tonRand[$tagTon] }} {{ $tagGewaehlt ? 'ring-2 ring-[var(--fa-accent)]' : 'border-x-[var(--fa-line)] border-b-[var(--fa-line)]' }}" data-tagesplanung-dashboard-tag="{{ $tag }}" data-tagesplanung-dashboard-tag-klickbar="">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="fa-t-muted">{{ $tagCarbon->isoFormat('dddd') }}</p>
                                            <h4 class="text-[length:var(--fa-text-2xl)] font-semibold leading-tight tracking-tight text-[var(--fa-ink)] tabular-nums">{{ $tagCarbon->format('d.m.') }}</h4>
                                        </div>
                                        <x-fa::badge :tone="$tagTon">{{ $tagText }}</x-fa::badge>
                                    </div>
                                    <dl class="grid grid-cols-3 gap-1.5 text-center">
                                        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-1 py-1.5"><dd class="fa-t-h2 tabular-nums">{{ $tagZeilen->count() }}</dd><dt class="fa-t-muted">Positionen</dt></div>
                                        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-1 py-1.5"><dd class="fa-t-h2 tabular-nums">{{ (int) $tagZeilen->sum('arbeitszeit_min') }}</dd><dt class="fa-t-muted">Minuten</dt></div>
                                        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-1 py-1.5"><dd class="fa-t-h2 tabular-nums">{{ $tagBuckets->count() }}</dd><dt class="fa-t-muted">Posten</dt></div>
                                    </dl>
                                    <ul class="space-y-0.5">
                                        @foreach($tagZeilen->take(4) as $z)
                                            <li class="truncate text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="{{ $z->name }} · {{ $z->station ?: 'ohne Posten' }}">{{ $z->name }} <span class="text-[var(--fa-ink-3)]">· {{ $z->station ?: 'ohne Posten' }}</span></li>
                                        @endforeach
                                        @if($tagZeilen->count() > 4)
                                            <li class="fa-t-muted">und {{ $tagZeilen->count() - 4 }} weitere</li>
                                        @elseif($tagZeilen->isEmpty())
                                            <li class="fa-t-muted">Keine Produktion geplant.</li>
                                        @endif
                                    </ul>
                                </x-fa::card>
                            @endforeach
                        </div>
                    </x-fa::section>

                    <x-fa::section title="Auslastung nach Posten" icon="heroicon-o-table-cells" description="Kapazität aus Posten und Besetzung." data-tagesplanung-dashboard-auslastung>
                        @if(empty($dashboard['matrix']))
                            <x-fa::empty icon="heroicon-o-table-cells" title="Noch keine Tagesproduktion geplant">Sobald Aufträge im gewählten Zeitraum eingeplant sind, steht hier die Last je Posten und Tag.</x-fa::empty>
                        @else
                            <div class="-mx-4 overflow-x-auto border-t border-[var(--fa-line)]">
                                <x-fa::table compact>
                                    <thead>
                                        <tr>
                                            <th class="sticky left-0 z-10 bg-[var(--fa-surface)]">Posten</th>
                                            @foreach($dashboard['tage'] as $tag)
                                                <th class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tag)->locale('de')->isoFormat('dd DD.MM.') }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dashboard['matrix'] as $station => $zellen)
                                            <tr>
                                                <td class="sticky left-0 z-10 whitespace-nowrap bg-[var(--fa-surface)] font-medium">{{ $station }}</td>
                                                @foreach($dashboard['tage'] as $tag)
                                                    @php
                                                        $bucket = $zellen[$tag] ?? null;
                                                        $zellTon = $bucket ? $stufeTon($bucket['stufe'] ?? null) : 'neutral';
                                                    @endphp
                                                    <td class="min-w-28">
                                                        <div class="rounded-[var(--fa-radius-control)] px-2.5 py-1.5 {{ $tonFlaeche[$zellTon] }}" @if($bucket) title="{{ $stufeText($bucket['stufe'] ?? null) }}" @endif>
                                                            @if($bucket)
                                                                <p class="font-semibold tabular-nums whitespace-nowrap">{{ (int) $bucket['geplant_min'] }} min @if($bucket['prozent'] !== null)<span class="font-normal">· {{ $bucket['prozent'] }} %</span>@endif</p>
                                                                <p class="text-[length:var(--fa-text-sm)] opacity-80 whitespace-nowrap">{{ (int) $bucket['zeilen'] }} {{ (int) $bucket['zeilen'] === 1 ? 'Position' : 'Positionen' }}@if($bucket['ohne_zeit']) · {{ (int) $bucket['ohne_zeit'] }} ohne Zeit @endif</p>
                                                            @else
                                                                <p class="font-medium">Frei</p>
                                                            @endif
                                                        </div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </x-fa::table>
                            </div>
                        @endif
                    </x-fa::section>
            </section>
        </x-ui-page-container>
    @endif
</x-ui-page>
@endif
{{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (echter Root ist das aeussere <div>, nicht das bedingte <x-ui-page> — Include gehoert deshalb hier, nicht neben das andere </x-ui-page>). --}}
</div>
