{{-- #1003 Handy-Liste als Karten (Rezepte, Gerichte): Suche oben, je Eintrag eine Karte; Antippen öffnet das
     Detail (wire:click auf die Auswahl-Methode des Browsers), nie den Editor. Keine Anlegen-, Status- oder
     Sammel-Aktionen: am Handy wird nur gelesen. Filter liegen in der linken Schublade (page-sidebar).
     Parameter: $titel, $eintraege (Paginator), $aktivId, $wahl (Methode), $scope (Store der Detail-Leiste),
     $suchHinweis, $unterzeile (fn($r): string), $wert (fn($r): ?string), $leer.
     Optional: $reiter (Schlüssel => Text), $reiterAktiv, $reiterWahl (Methode) — Umschalter über der Suche (Concepter: Concepts | Pakete);
     $suchFeld (Eigenschaft der Suche, Standard search), $titelVon (fn($r): string, Standard $r->name). --}}
@php
    $suchFeld ??= 'search';
    $titelVon ??= fn ($r) => $r->name;
@endphp
<div class="flex-1 min-w-0 h-full overflow-y-auto px-4 py-4 space-y-3" data-fa-handy-liste>
    <div class="flex items-baseline justify-between gap-3">
        <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">{{ $titel }}</h1>
        <span class="fa-t-muted tabular-nums">{{ number_format($eintraege->total(), 0, ',', '.') }} Treffer</span>
    </div>
    @if(($reiter ?? []) !== [])
        <x-fa::segmented label="Ansicht" :breit="true" data-fa-handy-reiter="">
            @foreach($reiter as $rk => $rt)
                <x-fa::segment :an="$reiterAktiv === $rk" size="handy" wire:click="{{ $reiterWahl }}('{{ $rk }}')">{{ $rt }}</x-fa::segment>
            @endforeach
        </x-fa::segmented>
    @endif
    <input type="search" wire:model.live.debounce.300ms="{{ $suchFeld }}" placeholder="{{ $suchHinweis }}" aria-label="{{ $titel }} durchsuchen"
           class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>

    <x-fa::card as="ul" class="flex flex-col divide-y divide-[var(--fa-line)]">
        @forelse($eintraege as $r)
            <li wire:key="hk-{{ $r->id }}">
                <button type="button" wire:click="{{ $wahl }}({{ $r->id }})" x-on:click="$store.ui?.mSet('{{ $scope }}', 'open', true)"
                        class="flex w-full items-center gap-3 px-4 py-3 min-h-[60px] text-left {{ $aktivId === $r->id ? 'bg-[var(--fa-accent-soft)]' : '' }}" data-fa-handy-karte="{{ $r->id }}">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $titelVon($r) }}</span>
                        <span class="block truncate fa-t-muted">{{ $unterzeile($r) }}</span>
                    </span>
                    @if(($w = $wert($r)) !== null)
                        <span class="shrink-0 text-[length:var(--fa-text-md)] font-medium tabular-nums text-[var(--fa-ink-2)]">{{ $w }}</span>
                    @endif
                    @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                </button>
            </li>
        @empty
            <li class="p-4"><x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Nichts gefunden">{{ $leer }}</x-fa::empty></li>
        @endforelse
    </x-fa::card>
    <div>{{ $eintraege->links('foodalchemist::components.fa.pagination') }}</div>
</div>
