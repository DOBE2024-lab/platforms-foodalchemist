{{-- Bestellungen: Übersicht (Filter links, Liste Mitte, Detail rechts). Bearbeiten im Werkbank-Editor.
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Häufigste Aufgabe: offene Bestellungen finden,
     versandfähige auswählen und gesammelt auslösen. Deshalb oben Kennzahlen + Auswahlleiste, darunter EINE
     Tabelle für die Sichten Bestellungen · Liefertage · Lieferanten (gleiche Spalten, Gruppenkopf je Sicht).
     Funktion, wire:-Bindungen, Event-Namen und data-Marker unverändert. --}}
@php
    $statusLabels = ['draft' => 'Entwurf', 'sent' => 'Versendet', 'confirmed' => 'Bestätigt', 'delivered' => 'Geliefert', 'cancelled' => 'Storniert'];
    $zeitraeume = ['' => 'Alle', 'heute' => 'Heute', 'woche' => 'Diese Woche', 'naechste' => 'Nächste Woche'];
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'success' => 'ok', 'danger' => 'crit', 'warning' => 'warn', 'primary' => 'accent'];
    $datum = fn ($wert) => $wert ? \Carbon\Carbon::parse($wert)->format('d.m.Y') : null;
    $zahl = fn ($wert) => number_format((float) $wert, 0, ',', '.');
    $segment = 'h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors';
    $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';

    $sichten = ['bestellungen' => 'Bestellungen', 'liefertage' => 'Liefertage', 'lieferanten' => 'Lieferanten', 'runden' => 'Runden', 'bedarfe' => 'Bedarfe'];
    $sichtTitel = ['bestellungen' => 'Bestellungen finden', 'liefertage' => 'Nach Liefertag planen', 'lieferanten' => 'Nach Lieferant bündeln', 'runden' => 'Bestellrunden', 'bedarfe' => 'Freigegebene Materialbedarfe'];
    $trefferZahl = $sicht === 'runden' ? $runden->count() : ($sicht === 'bedarfe' ? $bedarfe->count() : $liste->count());

    // EINE Tabelle für drei Sichten: Gruppenkopf + Zeilen. Spalten je Sicht ausgeblendet, wo sie doppelt wären.
    $tabellenGruppen = collect();
    if ($sicht === 'bestellungen') {
        foreach ($gruppen as $tag => $zeilen) {
            $tabellenGruppen->push([
                'label' => $gruppiert ? ($tag === '' ? 'Ohne Liefertag' : \Carbon\Carbon::parse($tag)->locale('de')->isoFormat('dddd, DD.MM.YYYY')) : null,
                'meta' => $zahl($zeilen->count()) . ' ' . ($zeilen->count() === 1 ? 'Bestellung' : 'Bestellungen'),
                'total' => null,
                'orders' => $zeilen,
                'key' => fn ($o) => 'ord-' . $o['id'],
            ]);
        }
    } elseif ($sicht === 'liefertage') {
        foreach ($liefertagGruppen as $gruppe) {
            $tabellenGruppen->push([
                'label' => $gruppe['label'],
                'meta' => $gruppe['suppliers'] . ' Lieferanten · ' . $gruppe['orders']->count() . ' Bestellungen · ' . $gruppe['line_count'] . ' Positionen',
                'total' => $gruppe['total_net'],
                'orders' => $gruppe['orders'],
                'key' => fn ($o) => 'day-' . md5($gruppe['key']) . '-' . $o['id'],
            ]);
        }
    } elseif ($sicht === 'lieferanten') {
        foreach ($lieferantGruppen as $gruppe) {
            $tabellenGruppen->push([
                'label' => $gruppe['supplier'],
                'meta' => $gruppe['dates'] . ' Liefertage · ' . $gruppe['orders']->count() . ' Bestellungen · ' . $gruppe['line_count'] . ' Positionen',
                'total' => $gruppe['total_net'],
                'orders' => $gruppe['orders'],
                'key' => fn ($o) => 'supplier-' . md5($gruppe['supplier']) . '-' . $o['id'],
            ]);
        }
    }
    $mitAuswahl = $sicht === 'bestellungen';
    $mitBestelldatum = $sicht === 'bestellungen';
    $mitLiefertag = $sicht !== 'liefertage';
    $mitLieferant = $sicht !== 'lieferanten';
    $spaltenZahl = 6 + ($standortSpalte ? 1 : 0) + ($mitAuswahl ? 1 : 0) + ($mitBestelldatum ? 1 : 0) + ($mitLiefertag ? 1 : 0) + ($mitLieferant ? 1 : 0);
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Bestellungen" icon="heroicon-o-shopping-cart" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Bestellungen'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-fa::page-sidebar title="Filter" width="w-72">
            <div class="p-3 flex flex-col gap-4">
                <div class="relative">
                    <label for="orders-suche" class="sr-only">Bestellungen durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="orders-suche" type="search" wire:model.live.debounce.300ms="suche" class="pl-8"
                        placeholder="{{ $sicht === 'bedarfe' ? 'Produktion suchen' : 'Beleg, Artikel, Produktion' }}" />
                </div>

                @if($sicht !== 'bedarfe')
                    <div class="flex flex-col gap-1.5">
                        <span class="fa-t-label">Datum bezieht sich auf</span>
                        <div role="group" aria-label="Datumsbasis" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                            <button type="button" wire:click="$set('datumsbasis','liefertag')" aria-pressed="{{ $datumsbasis === 'liefertag' ? 'true' : 'false' }}" class="flex-1 {{ $segment }} {{ $datumsbasis === 'liefertag' ? $segmentAn : $segmentAus }}">Liefertag</button>
                            <button type="button" wire:click="$set('datumsbasis','bestelldatum')" aria-pressed="{{ $datumsbasis === 'bestelldatum' ? 'true' : 'false' }}" class="flex-1 {{ $segment }} {{ $datumsbasis === 'bestelldatum' ? $segmentAn : $segmentAus }}">Bestelldatum</button>
                        </div>
                    </div>
                @endif

                <div class="flex flex-col gap-1.5">
                    <span class="fa-t-label">Zeitraum</span>
                    <div class="flex flex-wrap gap-1">
                        @foreach($zeitraeume as $key => $lbl)
                            <button type="button" wire:click="waehleZeitraum('{{ $key }}')" aria-pressed="{{ $zeitraum === $key ? 'true' : 'false' }}"
                                class="h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $zeitraum === $key ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]' : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <x-fa::input size="sm" type="date" wire:model.live="von" title="Von" aria-label="Von" />
                        <x-fa::input size="sm" type="date" wire:model.live="bis" title="Bis" aria-label="Bis" />
                    </div>
                </div>

                @if($sicht !== 'bedarfe')
                    <div class="flex flex-col gap-0.5">
                        <span class="mb-1 fa-t-label">Status</span>
                        <x-fa::filter-row wire:click="$set('statusFilter','')" :active="$statusFilter === ''"><span>Alle Status</span></x-fa::filter-row>
                        @foreach(['draft','sent','confirmed','delivered','cancelled'] as $s)
                            <x-fa::filter-row wire:key="order-status-{{ $s }}" wire:click="$set('statusFilter','{{ $s }}')" :active="$statusFilter === $s">{{ $statusLabels[$s] }}</x-fa::filter-row>
                        @endforeach
                    </div>

                    <x-fa::field label="Lieferant" for="orders-lieferant">
                        <x-fa::select id="orders-lieferant" size="sm" wire:model.live="supplierFilter">
                            <option value="">Alle Lieferanten</option>
                            @foreach($lieferanten as $l)<option value="{{ $l['id'] }}">{{ $l['name'] }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>

                    <div class="flex flex-col gap-1.5">
                        <x-fa::checkbox class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" wire:model.live="nurMitPositionen">Nur mit Positionen</x-fa::checkbox>
                        <x-fa::checkbox class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" wire:model.live="nurMitKlaerung">Nur mit Klärpunkten</x-fa::checkbox>
                    </div>
                @endif
            </div>
        </x-fa::page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-fa::detail-sidebar title="Bestellung" width="w-96" :maxWidth="760" scope="activity_orders" side="right" :auswahl="(string) $selectedOrderId">
            <livewire:foodalchemist.orders.detail-panel :order-id="$selectedOrderId" :key="'order-detail-'.($selectedOrderId ?? 'empty')" />
        </x-fa::detail-sidebar>
    </x-slot>

    {{-- Werkbank-Editor (pro Bestellung und für Bestellrunden), geöffnet per orders-editor.* --}}
    <livewire:foodalchemist.orders.editor key="orders-editor-shell" />

    <x-fa::modal name="orders-batch" title="Bestellungen auslösen">
        <div class="flex flex-col gap-4" data-orders-batch>
            @if($batchResult)
                <x-fa::notice tone="ok" title="{{ $batchResult['sent'] }} {{ (int) $batchResult['sent'] === 1 ? 'Bestellung' : 'Bestellungen' }} ausgelöst">
                    Versandzeitpunkt {{ $batchResult['sent_at'] }}
                </x-fa::notice>
                @if(!empty($batchResult['sent_ids']))
                    <div class="flex flex-wrap gap-2">
                        <x-fa::button variant="primary" icon="heroicon-o-printer" :href="route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $batchResult['sent_ids'])])" target="_blank">Versandprotokoll drucken</x-fa::button>
                        <x-fa::button icon="heroicon-o-arrow-down-tray" :href="route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $batchResult['sent_ids']), 'pdf' => 1])">PDF</x-fa::button>
                    </div>
                @endif
                @if($batchResult['blocked'] > 0)
                    <x-fa::notice tone="warn">{{ $batchResult['blocked'] }} {{ (int) $batchResult['blocked'] === 1 ? 'Bestellung blieb' : 'Bestellungen blieben' }} wegen Klärpunkten offen.</x-fa::notice>
                @endif
            @elseif($batchPreview)
                <x-fa::kpis :items="[
                    ['label' => 'Ausgewählt', 'value' => $zahl($batchPreview['selected'])],
                    ['label' => 'Versandfähig', 'value' => $zahl($batchPreview['ready']), 'tone' => $batchPreview['ready'] > 0 ? 'ok' : null],
                    ['label' => 'Mit Klärpunkten', 'value' => $zahl($batchPreview['blocked']), 'tone' => $batchPreview['blocked'] > 0 ? 'warn' : null],
                ]" />
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-1">
                        <x-fa::button size="sm" variant="ghost" wire:click="batchAlleWaehlen">Alle auswählen</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="batchAuswahlLeeren">Auswahl aufheben</x-fa::button>
                    </div>
                    @if(count($selectedOrderIds) > 0)
                        <div class="flex items-center gap-1">
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-printer" :href="route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds)])" target="_blank" title="Auswahl gebündelt drucken">Drucken</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-down-tray" :href="route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds), 'pdf' => 1])" title="Auswahl als PDF herunterladen">PDF</x-fa::button>
                        </div>
                    @endif
                </div>
                <div class="max-h-[44vh] overflow-auto fa-surface divide-y divide-[var(--fa-line)]">
                    @foreach(($batchCandidates['orders'] ?? []) as $order)
                        <div wire:key="batch-order-{{ $order['id'] }}" class="px-3 py-2.5 flex items-start justify-between gap-4" data-orders-batch-row="{{ $order['id'] }}">
                            <div class="min-w-0 flex items-start gap-3">
                                <x-fa::checkbox wire:click="batchBestellungUmschalten({{ $order['id'] }})" :checked="in_array((int) $order['id'], array_map('intval', $selectedOrderIds), true)" aria-label="Bestellung ord-{{ $order['id'] }} von {{ $order['supplier'] }} auswählen" />
                                <div class="min-w-0">
                                    <p class="fa-t-strong">{{ $order['supplier'] }} <span class="font-normal text-[var(--fa-ink-3)]">· ord-{{ $order['id'] }}</span></p>
                                    <p class="fa-t-muted flex flex-wrap gap-x-3 tabular-nums">
                                        <span>{{ $order['positions'] }} Positionen</span>
                                        <span>Bestelldatum: {{ $datum($order['created_at']) ?? '–' }}</span>
                                        <span>Liefertag: {{ $datum($order['desired_delivery_date']) ?? '–' }}</span>
                                    </p>
                                    @if(!$order['sendable'])<x-fa::signal tone="warn" class="mt-1">{{ implode(' · ', $order['blockers']) }}</x-fa::signal>@endif
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <x-fa::money :value="$order['total_net']" class="text-[length:var(--fa-text-md)] font-semibold" />
                                <x-fa::badge :tone="$order['sendable'] ? 'ok' : 'warn'">{{ $order['sendable'] ? 'Bereit' : 'Klärung' }}</x-fa::badge>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-fa::button variant="danger" wire:click="auswahlStornieren" wire:confirm="Ausgewählte Entwürfe wirklich stornieren?">Auswahl stornieren</x-fa::button>
                    <div class="flex items-center gap-3">
                        <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Versandfähig <x-fa::money :value="$batchPreview['total_net']" class="font-semibold text-[var(--fa-ink)]" /></span>
                        <x-fa::button variant="primary" icon="heroicon-m-paper-airplane" wire:click="auswahlAusloesen" wire:confirm="{{ $batchPreview['ready'] }} versandfähige Bestellungen jetzt auslösen?" :disabled="$batchPreview['ready'] === 0">{{ $batchPreview['ready'] }} {{ (int) $batchPreview['ready'] === 1 ? 'Bestellung' : 'Bestellungen' }} auslösen</x-fa::button>
                    </div>
                </div>
            @else
                <x-fa::empty compact icon="heroicon-o-paper-airplane" title="Keine Entwürfe ausgewählt">In der Liste Entwürfe ankreuzen und erneut prüfen.</x-fa::empty>
            @endif
        </div>
    </x-fa::modal>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Bestellungen" :subtitle="$zahl($trefferZahl) . ' Treffer'">
            <x-slot:actions>
                <x-fa::menu class="inline-block">
                    <x-slot:ausloeser>
                        <x-fa::button variant="ghost" icon="heroicon-m-ellipsis-horizontal" iconRight="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Weitere Aktionen</x-fa::button>
                    </x-slot:ausloeser>
                    <x-fa::menu-item icon="heroicon-o-archive-box" :href="route('foodalchemist.einstellungen', ['tab' => 'einkauf', 'bereich' => 'lagerorte'])" :schliessen="false">Lagerorte verwalten</x-fa::menu-item>
                    @if($sicht !== 'bedarfe')
                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                        <x-fa::menu-item icon="heroicon-o-trash" danger :trenner="false" wire:click="leereEntwuerfeLoeschen" wire:confirm="Alle leeren Entwürfe ohne Positionen löschen?">Leere Entwürfe löschen</x-fa::menu-item>
                    @endif
                </x-fa::menu>

                {{-- Neue Bestellrunde: Liefertag und Strategie optional vorgeben, Lieferanten entstehen erst aus Artikeln/Bedarf. --}}
                <x-fa::popover label="Neue Bestellrunde" class="inline-block">
                    <x-slot:ausloeser>
                        <x-fa::button variant="primary" icon="heroicon-m-plus" iconRight="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="dialog" x-bind:aria-expanded="offen">Neue Bestellrunde</x-fa::button>
                    </x-slot:ausloeser>
                    <div class="flex flex-col gap-3">
                        <x-fa::field label="Liefertag" for="orders-neu-liefertag" optional>
                            <x-fa::input id="orders-neu-liefertag" type="date" wire:model="neuerLiefertag" />
                        </x-fa::field>
                        <x-fa::field label="Einkaufsstrategie" for="orders-neu-strategie">
                            <x-fa::select id="orders-neu-strategie" wire:model="neueStrategie">
                                <option value="">Team-Standard</option>
                                @foreach($strategieOptionen as $s)
                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                @endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <p class="fa-t-muted">Beim Speichern entsteht je Lieferant und Liefertag eine eigene Bestellung.</p>
                        <x-fa::button variant="primary" x-on:click="offen = false" wire:click="neueBestellung" data-orders-neu>Bestellrunde öffnen</x-fa::button>
                    </div>
                </x-fa::popover>
            </x-slot:actions>
        </x-fa::page-header>

        @if($hinweis)<x-fa::notice tone="ok">{{ $hinweis }}</x-fa::notice>@endif
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif

        <div data-orders-overview-kpis>
            <x-fa::kpis :items="[
                ['kpi' => 'netto', 'label' => 'Netto gesamt', 'primary' => true, 'value' => number_format($kpis['total_net'], 2, ',', '.') . ' €'],
                ['kpi' => 'orders', 'label' => 'Bestellungen', 'value' => $zahl($kpis['orders'])],
                ['kpi' => 'ready', 'label' => 'Versandfähig', 'tone' => $kpis['ready'] > 0 ? 'ok' : null, 'value' => $zahl($kpis['ready'])],
                ['kpi' => 'clarifications', 'label' => 'Mit Klärpunkten', 'tone' => $kpis['clarifications'] > 0 ? 'warn' : null, 'value' => $zahl($kpis['clarifications'])],
                ['kpi' => 'positions', 'label' => 'Positionen', 'value' => $zahl($kpis['positions'])],
                ['kpi' => 'suppliers', 'label' => 'Lieferanten', 'value' => $zahl($kpis['suppliers'])],
            ]" />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div role="group" aria-label="Sicht" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                @foreach($sichten as $key => $lbl)
                    <button type="button" wire:click="$set('sicht','{{ $key }}')" aria-pressed="{{ $sicht === $key ? 'true' : 'false' }}"
                        class="{{ $segment }} {{ $sicht === $key ? $segmentAn : $segmentAus }}">{{ $lbl }}</button>
                @endforeach
            </div>

            {{-- Auswahlleiste für den Sammelversand (nur Entwürfe sind auswählbar) --}}
            @if($sicht === 'bestellungen')
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::button size="sm" variant="ghost" wire:click="alleVersandfaehigenWaehlen" :disabled="$kpis['ready'] === 0">Alle versandfähigen auswählen</x-fa::button>
                    @if(count($selectedOrderIds) > 0)
                        <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-accent)] tabular-nums">{{ count($selectedOrderIds) }} ausgewählt</span>
                        <x-fa::icon-button size="sm" icon="heroicon-m-x-mark" label="Auswahl aufheben" wire:click="auswahlLeeren" />
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-printer" :href="route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds)])" target="_blank" title="Ausgewählte Bestellungen gebündelt drucken">Drucken</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-down-tray" :href="route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds), 'pdf' => 1])" title="Ausgewählte Bestellungen als PDF herunterladen">PDF</x-fa::button>
                    @endif
                    <x-fa::button size="sm" :variant="count($selectedOrderIds) > 0 ? 'primary' : 'secondary'" icon="heroicon-m-paper-airplane" wire:click="sammelversandPruefen" :disabled="count($selectedOrderIds) === 0">Auswahl prüfen</x-fa::button>
                </div>
            @endif
        </div>

        <div class="fa-surface overflow-hidden" data-orders-tabelle>
            <div class="px-4 pt-4 pb-3 flex items-start justify-between gap-3 border-b border-[var(--fa-line)]">
                <div class="min-w-0">
                    <h2 class="fa-t-h2">{{ $sichtTitel[$sicht] ?? 'Bestellungen' }}</h2>
                    @if($sicht === 'bestellungen')
                        <p class="mt-0.5 fa-t-muted">Jede Zeile ist eine Bestellung bei einem Lieferanten. Die Suche findet Beleg, Anlass, Artikel, Produktion und Lieferant.</p>
                    @endif
                </div>
            </div>

            <div class="max-h-[70vh] overflow-auto">
                @if($sicht === 'runden')
                    <div class="grid min-h-[420px] lg:grid-cols-[minmax(0,1fr)_340px]">
                        <div class="divide-y divide-[var(--fa-line)]">
                            @forelse($runden as $runde)
                                <button type="button" wire:click="rundeWaehlen({{ $runde['id'] }})" wire:key="round-{{ $runde['id'] }}"
                                    aria-pressed="{{ $selectedRoundId === $runde['id'] ? 'true' : 'false' }}"
                                    class="w-full px-4 py-3 text-left transition-colors {{ $selectedRoundId === $runde['id'] ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)] truncate">{{ $runde['label'] }}</p>
                                            <p class="mt-0.5 fa-t-muted tabular-nums">{{ $runde['supplier_count'] }} Lieferanten · {{ $runde['order_count'] }} Bestellungen · {{ $runde['position_count'] }} Positionen</p>
                                        </div>
                                        <div class="flex flex-col items-end gap-1 shrink-0">
                                            <x-fa::money :value="$runde['total_net']" class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]" />
                                            <x-fa::badge :tone="$runde['sendable'] ? 'ok' : 'neutral'">{{ $runde['draft_count'] }} offen</x-fa::badge>
                                        </div>
                                    </div>
                                </button>
                            @empty
                                <x-fa::empty icon="heroicon-o-rectangle-stack" title="Noch keine gespeicherte Bestellrunde">Über „Neue Bestellrunde" Artikel, Rezepte oder Produktionen sammeln und speichern.</x-fa::empty>
                            @endforelse
                        </div>
                        <aside class="border-t lg:border-t-0 lg:border-l border-[var(--fa-line)] bg-[var(--fa-ground)] p-4">
                            @if($selectedRound)
                                <div class="flex items-start justify-between gap-2 mb-3">
                                    <div class="min-w-0">
                                        <h3 class="fa-t-h3">{{ $selectedRound['label'] }}</h3>
                                        <p class="fa-t-muted tabular-nums">{{ $selectedRound['supplier_count'] }} Lieferanten · {{ $selectedRound['position_count'] }} Positionen</p>
                                    </div>
                                    <x-fa::money :value="$selectedRound['total_net']" class="text-[length:var(--fa-text-md)] font-semibold" />
                                </div>
                                <div class="fa-surface divide-y divide-[var(--fa-line)]">
                                    @foreach($selectedRound['orders'] as $order)
                                        <button type="button" wire:click="oeffnen({{ $order['id'] }})" wire:key="round-order-{{ $order['id'] }}" class="w-full px-3 py-2 text-left flex items-center justify-between gap-2 hover:bg-[var(--fa-hover)]">
                                            <span class="fa-t-body truncate">{{ $order['supplier'] }}</span>
                                            <span class="shrink-0 fa-t-muted tabular-nums">{{ $order['positions'] }} Pos. · {{ number_format($order['total_net'], 2, ',', '.') }} €</span>
                                        </button>
                                    @endforeach
                                </div>
                                @if(!empty($selectedRound['blockers']))
                                    <x-fa::signal tone="warn" class="mt-3">{{ implode(' · ', $selectedRound['blockers']) }}</x-fa::signal>
                                @endif
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <x-fa::button wire:click="rundeBearbeiten" :disabled="!$selectedRound['editable']" data-orders-round-edit>Runde bearbeiten</x-fa::button>
                                    <x-fa::button variant="danger" icon="heroicon-m-trash" wire:click="rundeLoeschen" :disabled="!$selectedRound['editable']" wire:confirm="Bestellrunde löschen? Ihre Entwürfe bzw. ihre Beiträge in gemeinsamen Entwürfen werden entfernt." data-orders-round-delete>Löschen</x-fa::button>
                                    <x-fa::button variant="primary" icon="heroicon-m-paper-airplane" wire:click="rundeVersenden" :disabled="!$selectedRound['sendable']">Runde versenden</x-fa::button>
                                </div>
                                @if(!$selectedRound['editable'])
                                    <p class="mt-2 fa-t-muted">Ausgelöste Runden sind eingefroren. Korrekturen laufen über die einzelnen Bestellungen.</p>
                                @endif
                            @else
                                <x-fa::empty compact icon="heroicon-o-cursor-arrow-rays" title="Bestellrunde auswählen">Links eine Runde anklicken, dann erscheinen hier ihre Bestellungen.</x-fa::empty>
                            @endif
                        </aside>
                    </div>
                @elseif($sicht === 'bedarfe')
                    @if(count($selectedDemandIds) > 0)
                        <div class="px-4 py-2.5 border-b border-[var(--fa-line)] bg-[var(--fa-accent-soft)] flex items-center justify-between gap-3">
                            <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-accent)] tabular-nums">{{ count($selectedDemandIds) }} Produktionen ausgewählt</span>
                            <x-fa::button size="sm" variant="primary" wire:click="ausgewaehlteBedarfePlanen">Gemeinsam planen</x-fa::button>
                        </div>
                    @endif
                    <div class="divide-y divide-[var(--fa-line)]">
                        @forelse($bedarfe as $bedarf)
                            <div class="px-4 py-3 flex items-center justify-between gap-4" wire:key="demand-{{ $bedarf['id'] }}">
                                <div class="min-w-0 flex items-start gap-3">
                                    <x-fa::checkbox wire:model.live="selectedDemandIds" value="{{ $bedarf['id'] }}" :disabled="$bedarf['stale'] || $bedarf['triggered']" aria-label="{{ $bedarf['name'] }} auswählen" />
                                    <div class="min-w-0">
                                        <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)] truncate">{{ $bedarf['name'] }}</p>
                                        <p class="mt-0.5 fa-t-muted tabular-nums">{{ $datum($bedarf['production_date']) ?? 'ohne Datum' }} · {{ $bedarf['targets'] }} Ziele · {{ $bedarf['orders'] }} Bestellungen</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <x-fa::badge :tone="$bedarf['stale'] ? 'warn' : ($bedarf['status'] === 'geplant' ? 'ok' : 'info')">{{ $bedarf['status'] }}</x-fa::badge>
                                    <x-fa::button size="sm" wire:click="$dispatch('orders-editor.production', { id: {{ $bedarf['id'] }}, roundId: {{ $bedarf['round_id'] ?? 'null' }} })" :disabled="$bedarf['stale'] || $bedarf['triggered']">{{ $bedarf['triggered'] ? 'Ausgelöst' : ($bedarf['round_id'] ? 'Planung öffnen' : 'Planen') }}</x-fa::button>
                                </div>
                            </div>
                        @empty
                            <x-fa::empty icon="heroicon-o-clipboard-document-list" title="Keine freigegebenen Materialbedarfe">In der Produktion den Materialbedarf freigeben, dann erscheint er hier zum Planen.</x-fa::empty>
                        @endforelse
                    </div>
                @else
                    <table class="fa-table">
                        <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                            <tr>
                                @if($mitAuswahl)
                                    <th class="w-10">
                                        <x-fa::checkbox wire:click="versandfaehigeAuswahlUmschalten" :checked="$kpis['ready'] > 0 && count($selectedOrderIds) === $kpis['ready']" :disabled="$kpis['ready'] === 0" aria-label="Alle versandfähigen Bestellungen auswählen" />
                                    </th>
                                @endif
                                @if($standortSpalte)<th>Standort</th>@endif
                                <th>Beleg</th>
                                @if($mitBestelldatum)<th>Bestelldatum</th>@endif
                                @if($mitLiefertag)<th>Liefertag</th>@endif
                                @if($mitLieferant)<th>Lieferant</th>@endif
                                <th class="w-full">Produktion / Anlass</th>
                                <th class="num">Pos.</th>
                                <th class="num">Netto</th>
                                <th>Status</th>
                                <th>Hinweise</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if($tabellenGruppen->isEmpty() || $liste->isEmpty())
                                <tr>
                                    <td colspan="{{ $spaltenZahl }}">
                                        <x-fa::empty icon="heroicon-o-shopping-cart" title="{{ $sicht === 'lieferanten' ? 'Keine Lieferanten im Filter' : ($sicht === 'liefertage' ? 'Keine Liefertage im Filter' : 'Keine Bestellungen im Filter') }}">Filter lockern, eine neue Bestellrunde öffnen oder freigegebenen Bedarf aus der Produktion planen.</x-fa::empty>
                                    </td>
                                </tr>
                            @else
                                @foreach($tabellenGruppen as $gruppe)
                                    @if($gruppe['label'] !== null)
                                        <tr class="bg-[var(--fa-ground)]">
                                            <td colspan="{{ $spaltenZahl }}" class="py-2">
                                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                    <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $gruppe['label'] }} <span class="ml-1 font-normal fa-t-muted tabular-nums">{{ $gruppe['meta'] }}</span></span>
                                                    @if($gruppe['total'] !== null)<x-fa::money :value="$gruppe['total']" class="text-[length:var(--fa-text-md)] font-semibold" />@endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                    @foreach($gruppe['orders'] as $o)
                                        <x-fa::table-row :active="$selectedOrderId === $o['id']" wire:key="{{ ($gruppe['key'])($o) }}" wire:click="oeffnen({{ $o['id'] }})" data-orders-zeile="{{ $o['id'] }}">
                                            @if($mitAuswahl)
                                                <td onclick="event.stopPropagation()">
                                                    <x-fa::checkbox wire:model.live="selectedOrderIds" value="{{ $o['id'] }}" :disabled="$o['status'] !== \Platform\FoodAlchemist\Enums\OrderStatus::Draft" aria-label="ord-{{ $o['id'] }} auswählen" />
                                                </td>
                                            @endif
                                            @if($standortSpalte)<td class="whitespace-nowrap" data-standort>{{ $standortNamen[$o['team_id'] ?? 0] ?? '—' }}</td>@endif
                                            <td class="whitespace-nowrap">
                                                <div class="font-medium text-[var(--fa-ink)]">{{ $o['order_label'] }}</div>
                                                @if($o['supplier_order_number'])<div class="fa-t-muted" title="Auftragsbestätigung des Lieferanten">Bestätigung {{ $o['supplier_order_number'] }}</div>@endif
                                                @if($o['invoice_number'])<div class="fa-t-muted">Rechnung {{ $o['invoice_number'] }}</div>@endif
                                                @if($o['invoice_due_date'])<div class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">fällig {{ $datum($o['invoice_due_date']) }}</div>@endif
                                                @if(($o['payment']['status'] ?? null))<div class="text-[length:var(--fa-text-sm)] {{ ($o['payment']['state'] ?? '') === 'paid' ? 'text-[var(--fa-ok)]' : ((($o['payment']['state'] ?? '') === 'overdue') ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink-3)]') }}">Zahlung {{ $o['payment']['label'] }}</div>@endif
                                                @if(($o['approval']['status'] ?? null))<div class="text-[length:var(--fa-text-sm)] {{ ($o['approval']['state'] ?? '') === 'approved' ? 'text-[var(--fa-ok)]' : ((($o['approval']['state'] ?? '') === 'rejected') ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]') }}">Freigabe {{ $o['approval']['label'] }}</div>@endif
                                                @if($o['strategy'] !== '')<x-fa::badge tone="info" class="mt-1" title="Einkaufsstrategie dieser Bestellung">{{ $o['strategy_label'] }}</x-fa::badge>@endif
                                            </td>
                                            @if($mitBestelldatum)<td class="whitespace-nowrap tabular-nums text-[var(--fa-ink-2)]">{{ $datum($o['bestelldatum']) ?? '–' }}</td>@endif
                                            @if($mitLiefertag)<td class="whitespace-nowrap tabular-nums text-[var(--fa-ink-2)]">{{ $datum($o['liefertag']) ?? '–' }}</td>@endif
                                            @if($mitLieferant)<td class="whitespace-nowrap font-medium text-[var(--fa-ink)]">{{ $o['supplier'] }}</td>@endif
                                            <td class="text-[var(--fa-ink-2)]">
                                                @if(!empty($o['herkunft']))
                                                    <div class="flex flex-wrap gap-1">
                                                        @foreach($o['herkunft'] as $h)
                                                            @if(($h['production_order_id'] ?? null) !== null)
                                                                <a href="{{ route('foodalchemist.produktion.index', ['auftrag' => $h['production_order_id']]) }}"
                                                                   onclick="event.stopPropagation()"
                                                                   class="inline-flex items-center gap-1 h-[22px] px-2 rounded-full bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:underline"
                                                                   title="Produktion öffnen">{{ $h['label'] }}@svg('heroicon-m-arrow-top-right-on-square', 'w-3.5 h-3.5')</a>
                                                            @else
                                                                <x-fa::badge :tone="$h['type'] === 'concept' ? 'info' : 'neutral'">{{ $h['label'] }}</x-fa::badge>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                    @if($o['reference'])<div class="mt-1 fa-t-muted">{{ $o['reference'] }}</div>@endif
                                                @else
                                                    {{ $o['reference'] ?: '–' }}
                                                @endif
                                            </td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $o['line_count'] }}</td>
                                            <td class="num">
                                                <x-fa::money :value="$o['total_net']" />
                                                @if($o['line_count'] === 0)
                                                    <div><x-fa::signal tone="warn">leer</x-fa::signal></div>
                                                @elseif((float) $o['total_net'] === 0.0)
                                                    <div><x-fa::signal tone="warn">Preis fehlt</x-fa::signal></div>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap"><x-fa::badge :tone="$statusTon[$o['status']->badgeVariant()] ?? 'neutral'" data-status="{{ $o['status']->value }}">{{ ucfirst($o['status']->label()) }}</x-fa::badge></td>
                                            <td>
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($o['warnings'] as $w)<x-fa::badge tone="warn">{{ $w }}</x-fa::badge>@endforeach
                                                </div>
                                            </td>
                                        </x-fa::table-row>
                                    @endforeach
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
