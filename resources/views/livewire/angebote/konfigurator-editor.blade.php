{{-- Spec 94 · Konfigurator-Editor (Vollbild), aufgebaut wie der Angebots-Editor: Kopf mit Bearbeiten/Speichern und weiteren
     Aktionen, Kennzahlen-Leiste, Reiter. Zahlen kommen nur aus dem KonfiguratorPricingService (matrix). Reiter-Inhalte in
     partials/konfigurator/*. --}}
@php
    $gesperrt = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $euro = fn ($w) => $w !== null && $w !== '' ? number_format((float) $w, 2, ',', '.') . ' €' : '–';
@endphp

<x-fa::modal name="konfigurator-editor" fullscreen dark-canvas title="Konfigurator" :title-name="$k->name ?? null">
    <x-slot:actions>
        @if($k)
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-fa::menu breite="72">
                    <x-fa::menu-item icon="heroicon-o-wrench-screwdriver" :href="route('foodalchemist.angebote.leistung_vorlagen')" abstand="weit" :schliessen="false" target="_blank">Leistungs-Vorlagen öffnen</x-fa::menu-item>
                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                    <fieldset @disabled($gesperrt) class="contents">
                        <x-fa::menu-item icon="heroicon-o-trash" danger :trenner="false" abstand="weit" wire:click="loeschen" wire:confirm="Konfigurator „{{ $k->name }}“ löschen? Konzepte, Leistungen und Pakete gehen mit." data-konfigurator-loeschen>Konfigurator löschen</x-fa::menu-item>
                    </fieldset>
                </x-fa::menu>
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-konfigurator-speichern>Speichern</x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>
        @endif
    </x-slot:actions>

    @if($k)
        <x-slot:kpiHeader>
            @php $kz = $kennzahlen; @endphp
            <x-foodalchemist::kpi-tiles marker="konfigurator-kpis" :tiles="[
                ['kpi' => 'ab', 'label' => 'All-in ab', 'tone' => 'accent', 'value' => $kz['ab'] !== null ? $euro($kz['ab']) : 'Noch kein Preis',
                 'title' => 'Günstigstes Konzept im größten Paket, pro Person'],
                ['kpi' => 'konzepte', 'label' => 'Konzepte', 'value' => $kz['haupt'] . ($kz['addons'] ? ' + ' . $kz['addons'] . ' Add-ons' : '') . ($kz['getraenke'] ? ' + ' . $kz['getraenke'] . ' Getränke' : '')],
                ['kpi' => 'pakete', 'label' => 'Pax-Pakete', 'value' => (string) $kz['pakete']],
                ['kpi' => 'db', 'label' => 'Schwächster DB', 'tone' => $kz['schwach'] !== null && $kz['schwach']['db'] < 0 ? 'bad' : null,
                 'value' => $kz['schwach'] !== null ? $euro($kz['schwach']['db']) : '–', 'title' => $kz['schwach']['wo'] ?? null],
                ['kpi' => 'warnungen', 'label' => 'Warnungen', 'tone' => $kz['warnungen'] > 0 ? 'warn' : 'good', 'value' => (string) $kz['warnungen']],
                ['kpi' => 'status', 'label' => 'Status', 'value' => ['entwurf' => 'Entwurf', 'live' => 'Live', 'archiv' => 'Archiv'][$k->status] ?? $k->status],
                ['kpi' => 'link', 'label' => 'Link', 'tone' => $k->presentation_enabled ? 'good' : null, 'value' => $k->presentation_enabled ? 'Veröffentlicht' : 'Nicht veröffentlicht'],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($k === null)
        <x-fa::empty icon="heroicon-o-adjustments-horizontal" title="Kein Konfigurator geladen">In der Liste einen Konfigurator wählen.</x-fa::empty>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-konfigurator-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info" data-konfigurator-hinweis>{{ $hinweis }}</x-fa::notice>@endif

        <div wire:key="kfcockpit-{{ $k->id }}" class="space-y-4">
            <x-foodalchemist::editor-tabs marker="konfigurator" wire-key="konfigurator-tabs-{{ $k->id }}" :init="$k->konzepte->isEmpty() ? 'uebersicht' : 'kalkulation'" :gesperrt="$gesperrt"
                :tabs="[
                    'uebersicht' => 'Übersicht',
                    'konzepte' => 'Konzepte',
                    'getraenke' => 'Getränke',
                    'leistungen' => 'Leistungen',
                    'pakete' => 'Pax-Pakete',
                    'kalkulation' => 'Kalkulation',
                    'praesentation' => 'Branding und Präsentation',
                ]">
                <div x-show="tab === 'uebersicht'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="uebersicht">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.uebersicht')
                </div>
                <div x-show="tab === 'konzepte'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="konzepte">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.konzepte')
                </div>
                <div x-show="tab === 'getraenke'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="getraenke">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.getraenke')
                </div>
                <div x-show="tab === 'leistungen'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="leistungen">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.leistungen')
                </div>
                <div x-show="tab === 'pakete'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="pakete">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.pakete')
                </div>
                <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="kalkulation">
                    @include('foodalchemist::livewire.angebote.partials.konfigurator.kalkulation')
                </div>
                <div x-show="tab === 'praesentation'" x-cloak class="pt-4 flex flex-col gap-4" data-konfigurator-panel="praesentation">
                    <livewire:foodalchemist.angebote.konfigurator-praesentation :konfigurator-id="$k->id" :wire:key="'kfpraes-' . $k->id" />
                </div>
            </x-foodalchemist::editor-tabs>
        </div>
    @endif
</x-fa::modal>
