<details class="border-t border-[var(--fa-line)] pt-3">
<summary class="font-medium cursor-pointer">Zubehör &amp; Verpackung</summary>
{{-- Zubehör & Verpackung dieser Form (Non-Food, Menge je Einheit): wirkt NUR auf den EK, nie auf Allergene, Nährwerte oder Profil --}}
@php $zub = $darZubehoer[$d->id] ?? ['zeilen' => [], 'summe' => 0.0, 'vollstaendig' => true]; @endphp
<div wire:key="dar-zub-{{ $d->id }}" data-dar-zubehoer="{{ $d->id }}">
    <div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
            <span class="font-medium" title="Glas, Box, Deko: kostet mit, hat aber keine Allergene und keine Nährwerte">Zubehör &amp; Verpackung</span>
            @foreach($zub['zeilen'] as $z)
                <span class="inline-flex items-center gap-1" wire:key="zub-{{ $d->id }}-{{ $z['gp_id'] }}" data-zubehoer-zeile="{{ $z['gp_id'] }}">
                    <span class="text-[var(--fa-ink)]">{{ $z['name'] }}</span>
                    ×<input type="text" inputmode="decimal" value="{{ rtrim(rtrim(number_format($z['menge'], 3, ',', ''), '0'), ',') }}" aria-label="Menge je Einheit"
                            wire:change="darZubehoerMenge({{ $d->id }}, {{ $z['gp_id'] }}, $event.target.value)" class="{{ $feldKlein }} w-14 text-right tabular-nums" />
                    @if($z['kosten'] !== null)<span class="tabular-nums" data-zubehoer-kosten>{{ $euro($z['kosten']) }}</span>@else<x-fa::badge tone="warn" title="Kein Preis je Stück am Hauptartikel">Preis fehlt</x-fa::badge>@endif
                    <x-fa::icon-button icon="heroicon-o-x-mark" label="Zubehör entfernen" size="sm" wire:click="darZubehoerWeg({{ $d->id }}, {{ $z['gp_id'] }})" />
                </span>
            @endforeach
            {{-- 19.11.2026: Klick ins Feld öffnet die Liste (Einweg & Verpackung, Deko, Geschirr), Tippen grenzt ein. Reinigung/Kleidung/Büro nie. --}}
            <span class="relative inline-block" x-data x-on:click.outside="if ($wire.darZubehoerOffen === {{ $d->id }}) $wire.set('darZubehoerOffen', null)" x-on:keydown.escape="$wire.set('darZubehoerOffen', null)">
                <input type="search" wire:model.live.debounce.300ms="darZubehoerSuche.{{ $d->id }}" placeholder="+ Zubehör wählen oder suchen" aria-label="Zubehör oder Verpackung suchen"
                       x-on:focus="$wire.set('darZubehoerOffen', {{ $d->id }})" class="{{ $feldKlein }} w-56" data-zubehoer-suche />
                @php $zt = $darZubehoerTreffer[$d->id] ?? null; @endphp
                @if($zt !== null)
                    <ul class="absolute z-10 mt-1 w-80 max-h-72 overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] shadow-lg" data-zubehoer-treffer>
                        @forelse($zt->groupBy('gruppe') as $gruppe => $eintraege)
                            <li class="px-2.5 pt-2 pb-1 text-[length:var(--fa-text-xs)] font-semibold uppercase tracking-wide text-[var(--fa-ink-3)]" data-zubehoer-gruppe>{{ \Platform\FoodAlchemist\Support\ZubehoerAuswahl::gruppenName($gruppe) }}</li>
                            @foreach($eintraege as $t)
                                <li><button type="button" wire:click="darZubehoerHinzu({{ $d->id }}, {{ $t->id }})" class="block w-full text-left px-2.5 py-1.5 hover:bg-[var(--fa-hover)]">{{ $t->name }}</button></li>
                            @endforeach
                        @empty
                            <li class="px-2.5 py-2 text-[var(--fa-ink-3)]" data-zubehoer-leer>Nichts gefunden. Neue Artikel als Non-Food-Grundprodukt (Warengruppe 16) anlegen.</li>
                        @endforelse
                    </ul>
                @endif
            </span>
        </div>
    </div>
</div>

</details>
