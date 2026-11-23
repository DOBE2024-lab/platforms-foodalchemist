{{-- Planungs-Leitstelle · Reiter „Trendradar“ (Spec 79; Spec 85 C1 PR-2, reine Verschiebung aus planung/index.blade.php).
     Trends, Hypes und Fundstücke kombinieren → ein Briefing → normaler Kaskadenweg. Der Wrapper mit x-show und wire:key bleibt im Parent. --}}
@php
    $hinweisText = 'fa-t-muted';   // gleicher Wert wie der Parent-Hinweistext (Token statt Einzelwert)
    $trV = \Platform\FoodAlchemist\Support\TrendVokabular::class;
    $trGewaehlt = count($trendWahl['trends'] ?? []) + count($trendWahl['inspirationen'] ?? []);
    $trZeile = 'flex items-start gap-2.5 px-2.5 py-2 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)] cursor-pointer';
@endphp
<div class="grid grid-cols-1 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] gap-4 items-start">
    <div class="space-y-4 min-w-0">
        <x-fa::modal-section icon="heroicon-o-signal" title="Impulse aus dem Trendradar wählen">
            <x-slot:actions>
                <x-fa::badge :tone="$trGewaehlt > 0 ? 'accent' : 'neutral'">{{ $trGewaehlt }} von {{ \Platform\FoodAlchemist\Services\Planung\PlanningSessionService::TRENDRADAR_MAX }}</x-fa::badge>
            </x-slot:actions>
            <div class="flex flex-col gap-3">
                <p class="{{ $hinweisText }} max-w-2xl">Einen oder mehrere Impulse wählen, zum Beispiel einen Trend mit einem Hype und zwei Fundstücken. Daraus entsteht ein Briefing für Basisrezept, Gericht oder Konzept.</p>
                <x-fa::input type="search" wire:model.live.debounce.300ms="trendSuche" placeholder="Trends und Fundstücke durchsuchen" aria-label="Trendradar durchsuchen" />
                <p class="fa-t-label">Trends und Hypes</p>
                <div class="flex flex-col max-h-[40vh] overflow-y-auto -mx-1 px-1" data-trend-kandidaten>
                    @forelse($trendKandidaten as $t)
                        <x-fa::checkbox class="{{ $trZeile }}" wire:key="ptk-{{ $t->id }}" value="{{ $t->id }}" wire:model.live="trendWahl.trends" data-trend-wahl="{{ $t->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <span class="font-medium text-[var(--fa-ink)]">{{ $t->name }}</span>
                                    @if($t->typ)<x-fa::badge :tone="$t->typ === 'hype' ? 'warn' : 'accent'">{{ $trV::TYPEN[$t->typ] }}</x-fa::badge>@endif
                                    @if($t->ebene)<x-fa::badge>{{ $trV::EBENEN[$t->ebene] }}</x-fa::badge>@endif
                                    @unless(in_array($t->status, ['auf_radar', 'in_umsetzung'], true))<x-fa::badge>{{ $trV::STATUS[$t->status] }}</x-fa::badge>@endunless
                                </span>
                                @if($t->definition)<span class="block {{ $hinweisText }} line-clamp-1">{{ $t->definition }}</span>@endif
                            </span>
                        </x-fa::checkbox>
                    @empty
                        <p class="{{ $hinweisText }}">Keine Trends gefunden.</p>
                    @endforelse
                </div>
                <p class="pt-2 fa-t-label">Inspirationen</p>
                <div class="grid gap-2 grid-cols-[repeat(auto-fill,minmax(min(100%,12rem),1fr))] max-h-[40vh] overflow-y-auto" data-fund-kandidaten>
                    @forelse($fundKandidaten as $eintrag)
                        @php $insp = $eintrag['i']; @endphp
                        <x-fa::checkbox class="fa-surface p-2 flex gap-2 cursor-pointer min-w-0" wire:key="pik-{{ $insp->id }}" value="{{ $insp->id }}" wire:model.live="trendWahl.inspirationen" data-inspiration-wahl="{{ $insp->id }}">
                            @if($eintrag['url'])<img src="{{ $eintrag['url'] }}" alt="" class="w-12 h-12 object-cover rounded-[var(--fa-radius-control)] shrink-0" loading="lazy" />@endif
                            <span class="min-w-0">
                                <span class="block text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink)] line-clamp-2">{{ $insp->titel }}</span>
                                <span class="block {{ $hinweisText }}">{{ $insp->quellen->count() }} {{ $insp->quellen->count() === 1 ? 'Quelle' : 'Quellen' }}@if($insp->trend) · {{ $insp->trend->name }}@endif</span>
                            </span>
                        </x-fa::checkbox>
                    @empty
                        <p class="{{ $hinweisText }}">Die Inspirations-Pinnwand ist leer.</p>
                    @endforelse
                </div>
            </div>
        </x-fa::modal-section>
    </div>
    <div class="space-y-4 min-w-0 lg:sticky lg:top-0">
        <x-fa::modal-section icon="heroicon-o-document-text" title="Briefing aus der Auswahl">
            <div class="flex flex-col gap-3">
                @if($trendMeldung)<x-fa::signal tone="crit" data-trend-meldung>{{ $trendMeldung }}</x-fa::signal>@endif
                @if($trendVorschau)
                    <x-fa::card class="whitespace-pre-line fa-t-body leading-relaxed p-3" data-trend-vorschau="">{{ $trendVorschau }}</x-fa::card>
                    <p class="{{ $hinweisText }}">Übernehmen schreibt das Briefing in den gewählten Reiter. Dort Leitplanken setzen und wie gewohnt starten.</p>
                    <div class="flex flex-wrap gap-2">
                        <x-fa::button variant="primary" icon="heroicon-m-arrow-right" wire:click="trendradarUebernehmen('gericht')" data-trend-uebernehmen="gericht">Als Gericht</x-fa::button>
                        <x-fa::button variant="secondary" icon="heroicon-m-arrow-right" wire:click="trendradarUebernehmen('concept')" data-trend-uebernehmen="concept">Als Konzept</x-fa::button>
                        <x-fa::button variant="secondary" icon="heroicon-m-arrow-right" wire:click="trendradarUebernehmen('rezept')" data-trend-uebernehmen="rezept">Als Basisrezept</x-fa::button>
                    </div>
                @else
                    <p class="{{ $hinweisText }}">Links Impulse wählen. Hier erscheint das Briefing, das daraus entsteht.</p>
                @endif
            </div>
        </x-fa::modal-section>
    </div>
</div>
