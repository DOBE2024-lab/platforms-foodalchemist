{{-- Concepter — Concepts und Pakete in einem Screen (M10R-2 / Doc 15 §10.2+§10.4).
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen, Event-Namen
     und data-Marker unverändert. Neu: Umschalter Concepts | Pakete und Hauptaktion im Seitenkopf,
     Filter als Filterzeilen (Einsatz-Dimensionen eingeklappt, offen sobald gewählt), Status als Chip mit Menü
     statt Dropdown je Zeile, fehlender Preis als Signal, Detail-Spalte erst nach Auswahl. --}}
@php
    $istPakete = $tab === 'pakete';
    $statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'];
    $statusTon = ['draft' => 'neutral', 'active' => 'ok', 'archiviert' => 'neutral'];
    $statusIcon = ['draft' => 'heroicon-m-pencil', 'active' => 'heroicon-m-check', 'archiviert' => 'heroicon-m-archive-box'];
    $niveauLabel = ['klassisch' => 'Klassisch', 'gehoben' => 'Gehoben', 'haute' => 'Haute Cuisine'];

    // Einsatz-Dimensionen: [Überschrift, aktueller Wert, Methoden-Feld, Vokabular, Label-Feld, wire:key-Präfix]
    $facetten = [
        ['Eventtyp', $eventtypFilter, 'eventtypFilter', $facetteEventtypen, 'name', 'fev'],
        ['Servierform', $servierformFilter, 'servierformFilter', $facetteServierformen, 'label', 'fsf'],
        ['Einsatzmoment', $momentFilter, 'momentFilter', $facetteMomente, 'name', 'fem'],
        ['Saison', $saisonFilter, 'saisonFilter', $facetteSaisons, 'name', 'fsa'],
    ];
    $filterAktiv = $search !== '' || $klasse !== '' || $statusFilter !== '' || $rolleFilter !== ''
        || $eventtypFilter !== '' || $servierformFilter !== '' || $momentFilter !== '' || $saisonFilter !== '';

    $einheit = $istPakete ? ($items->total() === 1 ? ' Paket' : ' Pakete')
        : ($showVorlagen ? ($items->total() === 1 ? ' Vorlage' : ' Vorlagen') : ' Konzepte');
    $neuText = $istPakete ? 'Neues Paket' : ($showVorlagen ? 'Neue Vorlage' : 'Neues Konzept');
    $spaltenZahl = 6;
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Konzepter" icon="heroicon-o-square-3-stack-3d" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Konzepter'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-fa::page-sidebar title="Filter" width="w-80">
            <div class="p-3 flex flex-col gap-3">
                <div class="relative">
                    <label for="concepter-suche" class="sr-only">{{ $istPakete ? 'Pakete durchsuchen' : 'Konzepte durchsuchen' }}</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="concepter-suche" type="search" wire:model.live.debounce.300ms="search"
                        placeholder="{{ $istPakete ? 'Paket suchen' : 'Konzept suchen' }}" class="pl-8" />
                </div>

                @unless($istPakete)
                    {{-- Vorlagen: eigene Liste, aus der neue Concepts entstehen --}}
                    <x-fa::filter-row wire:click="$set('showVorlagen', {{ $showVorlagen ? 'false' : 'true' }})" :active="$showVorlagen">
                        <span class="inline-flex items-center gap-2">@svg('heroicon-o-square-2-stack', 'w-4 h-4 shrink-0') Nur Vorlagen</span>
                    </x-fa::filter-row>
                @endunless

                {{-- Status (geteilte Dimension, gilt für beide Reiter) --}}
                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Status</p>
                    <x-fa::filter-row wire:click="waehleStatus('')" :active="$statusFilter === ''">Alle Status</x-fa::filter-row>
                    @foreach($statusLabel as $val => $lbl)
                        <x-fa::filter-row wire:key="stf-{{ $val }}" wire:click="waehleStatus('{{ $val }}')" :active="$statusFilter === $val">{{ $lbl }}</x-fa::filter-row>
                    @endforeach
                </div>

                {{-- Klasse (geteilte Dimension §10.3) --}}
                @if(! empty($klassen))
                    <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                        <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Klasse</p>
                        <x-fa::filter-row wire:click="waehleKlasse('')" :active="$klasse === ''">Alle Klassen</x-fa::filter-row>
                        @foreach($klassen as $k)
                            <x-fa::filter-row wire:key="kl-{{ $loop->index }}" wire:click="waehleKlasse({{ \Illuminate\Support\Js::from($k) }})" :active="$klasse === $k">{{ $k }}</x-fa::filter-row>
                        @endforeach
                    </div>
                @endif

                @if($istPakete && ! empty($rollen))
                    <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                        <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Rolle</p>
                        <x-fa::filter-row wire:click="waehleRolle('')" :active="$rolleFilter === ''">Alle Rollen</x-fa::filter-row>
                        @foreach($rollen as $r)
                            <x-fa::filter-row wire:key="ro-{{ $loop->index }}" wire:click="waehleRolle({{ \Illuminate\Support\Js::from($r) }})" :active="$rolleFilter === $r">{{ $r }}</x-fa::filter-row>
                        @endforeach
                    </div>
                @endif

                {{-- Einsatz (Umbau-Spec 4b + Kaskade 2026-08-24): Eventtyp · Servierform · Einsatzmoment · Saison,
                     gelten auch für Pakete. Eingeklappt, offen sobald gewählt. --}}
                <div class="flex flex-col gap-1 pt-2 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Einsatz</p>
                    @foreach($facetten as [$titel, $wert, $feld, $vokabular, $labelFeld, $praefix])
                        @continue($vokabular->isEmpty())
                        @php
                            $gewaehlt = $vokabular->firstWhere('id', (int) $wert);
                        @endphp
                        <details class="group" @if($wert !== '') open @endif wire:key="fgrp-{{ $feld }}">
                            <summary class="flex items-center justify-between gap-2 h-8 px-2.5 rounded-[var(--fa-radius-control)] cursor-pointer select-none text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]">
                                <span class="inline-flex items-center gap-1.5 min-w-0">
                                    @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 transition-transform group-open:rotate-90')
                                    <span class="truncate {{ $gewaehlt ? 'font-semibold text-[var(--fa-ink)]' : '' }}">{{ $titel }}</span>
                                </span>
                                @if($gewaehlt)<x-fa::badge tone="accent" class="max-w-[9rem]"><span class="truncate" title="{{ $gewaehlt->{$labelFeld} }}">{{ $gewaehlt->{$labelFeld} }}</span></x-fa::badge>@endif
                            </summary>
                            <x-fa::filter-ast>
                                <x-fa::filter-row level="child" wire:click="waehleFacette('{{ $feld }}', '')" :active="$wert === ''">Alle</x-fa::filter-row>
                                @foreach($vokabular as $eintrag)
                                    <x-fa::filter-row level="child" wire:key="{{ $praefix }}-{{ $eintrag->id }}"
                                        wire:click="waehleFacette('{{ $feld }}', '{{ $eintrag->id }}')"
                                        :active="$wert === (string) $eintrag->id">{{ $eintrag->{$labelFeld} }}</x-fa::filter-row>
                                @endforeach
                            </x-fa::filter-ast>
                        </details>
                    @endforeach
                </div>

                @if($filterAktiv)
                    <div class="pt-2 border-t border-[var(--fa-line)]">
                        <x-fa::button variant="ghost" size="sm" icon="heroicon-m-x-mark" wire:click="filterZuruecksetzen" class="w-full">Filter zurücksetzen</x-fa::button>
                    </div>
                @endif
            </div>
        </x-fa::page-sidebar>
    </x-slot>

    {{-- Detail-Spalte erst, wenn etwas gewählt ist (vorher nahm der leere Hinweis Platz der Tabelle weg). --}}
    @if($selectedId !== null)
        <x-slot name="activity">
            <x-fa::detail-sidebar title="Detail" width="w-96" :maxWidth="640" scope="activity_concepter" side="right" :auswahl="(string) $selectedId">
                <livewire:foodalchemist.concepter.detail-panel :selected-id="$selectedId" :type="$tab" key="concepter-browser--concepter.detail-panel" />
            </x-fa::detail-sidebar>
        </x-slot>
    @endif

    {{-- #1003: am Handy Karten statt Tabelle (wie Rezepte), Concepts | Pakete als Umschalter; Filter bleiben in der linken Schublade --}}
    @if(\Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy())
        @include('foodalchemist::livewire.handy.karten-liste', [
            'titel' => 'Konzepter', 'eintraege' => $items, 'aktivId' => $selectedId, 'wahl' => 'waehle', 'scope' => 'activity_concepter',
            'suchHinweis' => $istPakete ? 'Paket suchen' : 'Konzept suchen',
            'reiter' => ['concepts' => 'Konzepte', 'pakete' => 'Pakete'], 'reiterAktiv' => $tab, 'reiterWahl' => 'wechselTab',
            'unterzeile' => fn ($it) => implode(' · ', array_filter([$it->class ?: null, $it->eventType?->name, $it->servingForm?->label, $statusLabel[$it->status] ?? $it->status])),
            'wert' => function ($it) use ($vkDisplay) {
                $preis = isset($vkDisplay[$it->id]) && $vkDisplay[$it->id] !== null ? (float) $vkDisplay[$it->id] : ($it->price_per_person_cache !== null ? (float) $it->price_per_person_cache : null);

                return $preis !== null && $preis > 0 ? number_format($preis, 2, ',', '.') . ' €/P.' : null;
            },
            'leer' => $istPakete ? 'Noch keine Pakete oder andere Suche.' : 'Noch keine Konzepte oder andere Suche.',
        ])
    @else
    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Konzepter" :subtitle="number_format($items->total(), 0, ',', '.') . $einheit">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu">{{ $neuText }}</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        {{-- Umschalter Concepts | Pakete (§10.2) --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-fa::segmented label="Ansicht" :breit="true">
                @foreach(['concepts' => 'Konzepte', 'pakete' => 'Pakete'] as $tk => $tl)
                    <x-fa::segment :an="$tab === $tk" size="md" wire:click="wechselTab('{{ $tk }}')">{{ $tl }}</x-fa::segment>
                @endforeach
            </x-fa::segmented>
            <p class="fa-t-muted max-w-[60ch]">
                {{ $istPakete ? 'Ein Paket bündelt mehrere Gerichte zu einem Preis je Person und lässt sich in Konzepte einsetzen.' : 'Ein Konzept ist ein ganzes Menü oder Buffet aus Positionen. Klick auf den Namen öffnet den Editor.' }}
            </p>
        </div>

        <x-fa::card class="overflow-hidden">
            <x-fa::freigabe-sperre :sperre="$freigabeSperre" />{{-- 1.1f --}}
            {{-- Spec 28: eigener Scroll-Kasten, damit der Tabellenkopf kleben kann --}}
            <div class="max-h-[70vh] overflow-auto">
                <x-fa::table>
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full">Name</th>
                            <th>Klasse</th>
                            <th title="Eventtyp · Servierform">Einsatz</th>
                            <th>Status</th>
                            <th class="num" title="Anzahl Positionen (Gänge, Pakete, Gerichte)">Positionen</th>
                            <th class="num">€/Person</th>
                            @if($istPakete)
                                <th class="num" title="Wareneinsatz: Einkauf im Verhältnis zum Verkaufspreis">Wareneinsatz</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $it)
                            @php
                                // Ebene 2: mit Brille der betriebsscharfe €/Gast ($vkDisplay), sonst der Cache.
                                $betriebsPreis = isset($vkDisplay[$it->id]) && $vkDisplay[$it->id] !== null;
                                $preis = $betriebsPreis ? (float) $vkDisplay[$it->id] : ($it->price_per_person_cache !== null ? (float) $it->price_per_person_cache : null);
                                $wareneinsatz = $istPakete ? \Platform\FoodAlchemist\Services\MargeService::wareneinsatzPct($it->ek_per_person_cache, $preis, null) : null;
                                $darfStatus = ! isset($it->team_id) || \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $it);   // seit #800 sind Pakete Concepts (kind=paket) → Status wie bei Concepts
                            @endphp
                            <x-fa::table-row :active="$selectedId === $it->id" wire:key="row-{{ $tab }}-{{ $it->id }}" wire:click="waehle({{ $it->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_concepter', 'open', true)">
                                {{-- Namens-Klick öffnet den Editor, Zeilen-Klick nur das Detail --}}
                                <td class="min-w-[12rem]">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <button type="button" wire:click.stop="bearbeite({{ $it->id }})" title="{{ $it->name }} im Editor öffnen"
                                                class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline">{{ $it->name }}</button>
                                        @if(! $istPakete && $it->is_template)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack">Vorlage</x-fa::badge>@endif
                                        @if($it->level)<x-fa::badge title="Niveau">{{ $niveauLabel[$it->level] ?? $it->level }}</x-fa::badge>@endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $it->class ?: '–' }}</td>
                                <td class="min-w-32 text-[var(--fa-ink-2)]">{{ collect([$it->eventType?->name, $it->servingForm?->label])->filter()->join(' · ') ?: '–' }}</td>
                                {{-- Status als Chip; Kuratoren ändern ihn bei Concepts und Paketen über ein kleines Menü (Server gated canCurate/D1) --}}
                                <td class="whitespace-nowrap" wire:click.stop @click.stop>
                                    @if($darfStatus)
                                        <x-fa::menu class="inline-block" breite="40" wire:key="cst-{{ $it->id }}-{{ $it->status }}">
                                            <x-slot:ausloeser>
                                                <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Status von {{ $it->name }} ändern" data-status-select>
                                                    <x-fa::badge :tone="$statusTon[$it->status] ?? 'neutral'" :icon="$statusIcon[$it->status] ?? null">{{ $statusLabel[$it->status] ?? $it->status }}</x-fa::badge>@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                                </button>
                                            </x-slot:ausloeser>
                                            @foreach($statusLabel as $val => $lbl)
                                                <x-fa::menu-item :gewaehlt="$it->status === $val" wire:click="statusSetzen({{ $it->id }}, '{{ $val }}')">{{ $lbl }}</x-fa::menu-item>
                                            @endforeach
                                        </x-fa::menu>
                                    @else
                                        <x-fa::badge :tone="$statusTon[$it->status] ?? 'neutral'" :icon="$statusIcon[$it->status] ?? null">{{ $statusLabel[$it->status] ?? $it->status }}</x-fa::badge>
                                    @endif
                                </td>
                                <td class="num {{ $it->slots_count === 0 ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-ink-2)]' }}">{{ $it->slots_count }}</td>
                                <td class="num" @if($betriebsPreis) title="€/Gast für {{ $aktiverBetrieb }}" @endif>
                                    @if($preis === null || ($preis <= 0 && $it->slots_count > 0))
                                        {{-- 0,00 € bei vorhandenen Positionen heißt: keine Position bepreist — nicht „kostet nichts". --}}
                                        <x-fa::money :value="null" />
                                    @else
                                        <span class="inline-flex items-center gap-1 {{ $betriebsPreis ? 'font-medium text-[var(--fa-ink)]' : '' }}">
                                            @if($betriebsPreis)@svg('heroicon-m-building-storefront', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')@endif
                                            <x-fa::money :value="$preis" />
                                        </span>
                                    @endif
                                </td>
                                @if($istPakete)
                                    <td class="num text-[var(--fa-ink-2)]">{{ $wareneinsatz !== null ? number_format($wareneinsatz, 1, ',', '.') . ' %' : '–' }}</td>
                                @endif
                            </x-fa::table-row>
                        @empty
                            <tr>
                                <td colspan="{{ $istPakete ? $spaltenZahl + 1 : $spaltenZahl }}">
                                    @if($filterAktiv)
                                        <x-fa::empty icon="heroicon-o-funnel" title="Keine Treffer für diese Filter">Filter zurücksetzen oder die Suche lockern.</x-fa::empty>
                                    @elseif($istPakete)
                                        <x-fa::empty icon="heroicon-o-puzzle-piece" title="Noch keine Pakete">Mit „Neues Paket“ oben rechts ein Bündel aus Gerichten anlegen.</x-fa::empty>
                                    @elseif($showVorlagen)
                                        <x-fa::empty icon="heroicon-o-square-2-stack" title="Noch keine Vorlagen">Ein Konzept im Detail als Vorlage speichern oder mit „Neue Vorlage“ anlegen.</x-fa::empty>
                                    @else
                                        <x-fa::empty icon="heroicon-o-square-3-stack-3d" title="Noch keine Konzepte">Mit „Neues Konzept“ oben rechts ein Menü oder Buffet anlegen.</x-fa::empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-fa::table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $items->links('foodalchemist::components.fa.pagination') }}</div>
        </x-fa::card>
    </x-ui-page-container>
    @endif

    {{-- Voll-Editor-Modal (M10R-3) — auf Seitenebene, öffnet via concepter-editor.oeffnen --}}
    <livewire:foodalchemist.concepter.editor key="concepter-browser--concepter.editor" />

    {{-- Phase 6: Typ-Einsehen — Basisrezept/VK-Gericht als Fenster ÜBER dem Concepter-Editor.
         Nach dem Editor platziert → stapelt obenauf (gleiche z-[100]-Konvention). --}}
    <livewire:foodalchemist.recipes.recipe-modal key="concepter-browser--recipes.recipe-modal" />
    <livewire:foodalchemist.recipes.fassung-modal key="concepter-browser--recipes.fassung-modal" />
    {{-- Editor-Kette (Dominique 2026-10-13): Rezept → Grundprodukt → Lieferantenartikel muss auf jeder Seite mit Rezept-Editor durchklickbar sein; fehlt ein Glied, läuft der Klick still ins Leere. Wächter: EditorKetteTest. --}}
    <livewire:foodalchemist.gps.gp-modal key="concepter-browser--gps.gp-modal" />
    <livewire:foodalchemist.suppliers.item-modal key="concepter-browser--suppliers.item-modal" />
    <livewire:foodalchemist.recipes.pairing-netz-modal key="concepter-browser--recipes.pairing-netz-modal" />{{-- „Netz öffnen" aus dem Rezept-/Gericht-Editor --}}
    <livewire:foodalchemist.verkauf.vk-modal key="concepter-browser--verkauf.vk-modal" />
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
    {{-- 98 · 2.4c: erst der Name, dann der Datensatz (LegtMitNamenAn) --}}
    @unless(\Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy())   {{-- am Handy wird nicht angelegt (#1003) --}}
    <x-fa::anlege-dialog :title="$neuText" :knopf="$istPakete ? 'Paket anlegen' : ($showVorlagen ? 'Vorlage anlegen' : 'Concept anlegen')" :fehler="$neuFehler" />
    @endunless
</x-ui-page>
