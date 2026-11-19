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
            <span class="relative inline-block">
                <input type="search" wire:model.live.debounce.300ms="darZubehoerSuche.{{ $d->id }}" placeholder="+ Zubehör suchen" aria-label="Zubehör oder Verpackung suchen"
                       class="{{ $feldKlein }} w-44" data-zubehoer-suche />
                @if(! empty($darZubehoerTreffer[$d->id] ?? null) && count($darZubehoerTreffer[$d->id]) > 0)
                    <ul class="absolute z-10 mt-1 w-64 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] shadow-lg" data-zubehoer-treffer>
                        @foreach($darZubehoerTreffer[$d->id] as $t)
                            <li><button type="button" wire:click="darZubehoerHinzu({{ $d->id }}, {{ $t->id }})" class="block w-full text-left px-2.5 py-1.5 hover:bg-[var(--fa-hover)]">{{ $t->name }}</button></li>
                        @endforeach
                    </ul>
                @endif
            </span>
        </div>
    </div>
</div>

</details>
