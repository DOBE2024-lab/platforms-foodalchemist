<details class="border-t border-[var(--fa-line)] pt-3">
<summary class="font-medium cursor-pointer">Gefäß, Zubehör &amp; Verpackung</summary>
@php $gi = $darGefaess[$d->id] ?? null; @endphp
@if($gi)
    <div>id }}" data-dar-gefaess-info="{{ $d->id }}">
        <div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                <span class="font-medium">Servier-Typ</span>
                <label class="inline-flex items-center gap-1">
                    <select wire:model="darForm.{{ $d->id }}.serving_vehicle_vocab_id" wire:change="darreichungSpeichern({{ $d->id }})" aria-label="Servier-Typ" class="{{ $feldKlein }} fa-select pr-8 w-44" data-dar-typ>
                        <option value="">{{ $gi['gefaess_typ_name'] ? 'aus Gefäß: '.$gi['gefaess_typ_name'] : 'nicht gesetzt' }}</option>
                        @foreach($vehikel as $v)<option value="{{ $v->id }}" @if($v->is_inactive && ($darForm[$d->id]['serving_vehicle_vocab_id'] ?? null) != $v->id) hidden @endif>{{ $v->name }}</option>@endforeach
                    </select>
                </label>
                @if($gi['uebersteuert'])<x-fa::badge tone="warn" data-dar-uebersteuert title="Der Typ wurde ausdrücklich gesetzt und weicht vom Typ des Gefäßes ab">übersteuert (Gefäß sagt {{ $gi['gefaess_typ_name'] }})</x-fa::badge>
                @elseif($gi['typ_name'] && $gi['gefaess_id'])<span class="{{ $hinweis }}" data-dar-typ-aus-gefaess>{{ $gi['typ_name'] }} (aus dem Gefäß)</span>@endif
                @if($gi['gefaess_id'])
                    {{-- Art des Gefäßes (17.11.2026): leer = Eintrag des Teams am Grundprodukt (Art + Preis), sonst Automatik (Verleih → extern, sonst Einweg) --}}
                    <label class="inline-flex items-center gap-1"><span class="font-medium">Art</span>
                        <select wire:model="darForm.{{ $d->id }}.vessel_cost_type" wire:change="darreichungSpeichern({{ $d->id }})" aria-label="Art des Gefäßes" class="{{ $feldKlein }} fa-select pr-8 w-52" data-dar-kostenart>
                            <option value="">{{ 'Vorgabe: '.(\Platform\FoodAlchemist\Services\GefaessKostenartService::ARTEN[$gi['kostenart_vorgabe']] ?? '–') }}</option>
                            @foreach(\Platform\FoodAlchemist\Services\GefaessKostenartService::ARTEN as $artKey => $artLabel)<option value="{{ $artKey }}">{{ $artLabel }}</option>@endforeach
                        </select>
                    </label>
                @endif
                @if($gi['kosten'] !== null)<span class="tabular-nums" data-dar-gefaess-kosten>Gefäß {{ $euro($gi['kosten']) }} je Einheit{{ $gi['preis_quelle'] === 'gp' ? ' (Preis am Grundprodukt)' : ($gi['miete'] ? ' (Mietpreis des Artikels)' : '') }}</span>@endif
                @if($gi['preis_fehlt'])<x-fa::badge tone="warn" title="Kein Preis je Stück: bei Einweg und Extern am Grundprodukt (Lager › Art als Gefäß) oder am Hauptartikel, bei Mehrweg am Grundprodukt eintragen" data-dar-gefaess-preis-fehlt>Gefäß-Preis fehlt</x-fa::badge>@endif
                @if($gi['warnung'])<x-fa::badge tone="warn" data-dar-fuellmenge-warnung>{{ $gi['warnung'] }}</x-fa::badge>@endif
            </div>
        </div>
    </div>
@endif
{{-- Zubehör & Verpackung dieser Form (Non-Food, Menge je Einheit): wirkt NUR auf den EK, nie auf Allergene, Nährwerte oder Profil --}}
@php $zub = $darZubehoer[$d->id] ?? ['zeilen' => [], 'summe' => 0.0, 'vollstaendig' => true]; @endphp
<div>id }}" data-dar-zubehoer="{{ $d->id }}">
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
