<section class="flex flex-col gap-4 border-t border-[var(--fa-line)] p-4" data-dar-anrichten="{{ $d->id }}">
    <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="font-semibold text-[length:var(--fa-text-lg)]">Arbeitsschritte &amp; Zeiten</h4><x-fa::button size="sm" variant="ai" icon="heroicon-o-sparkles" wire:click="darZeitVorschlagen({{ $d->id }})" data-ki-eigenschaften>Zeiten vorschlagen</x-fa::button></div>
    <p class="{{ $hinweis }}">{{ $d->arbeitszeiten !== null ? 'Eigene Werte dieser Darreichung' : 'Bisherige Berechnung · noch nicht übernommen' }}</p>
    @if(($darZeitForm[$d->id] ?? []) != ($darZeitStart[$d->id] ?? []))<x-fa::badge tone="warn">Noch nicht gespeichert</x-fa::badge>@endif
    <x-fa::field label="Ausgabe-Posten dieser Darreichung" for="dar-posten-{{ $d->id }}" hint="Für Küchenarbeit; Service wird separat gerechnet">
        <x-fa::select id="dar-posten-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.ausgabe_station_id" wire:change="darreichungSpeichern({{ $d->id }})" data-vk-default-station>
            <option value="">Aus Komponenten / bisherigem Gerichts-Posten</option>
            @foreach($posten as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
        </x-fa::select>
    </x-fa::field>
    @foreach($darZeitForm[$d->id] ?? [] as $index => $zeit)
        <div wire:key="dar-zeit-{{ $d->id }}-{{ $index }}" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_2rem]">
            @if(($zeit['quelle'] ?? null) === 'ki_vorschlag')<span class="sm:col-span-2 xl:col-span-5"><x-fa::badge tone="warn">KI-Vorschlag · ungeprüft</x-fa::badge></span>@endif
            @if(($zeit['quelle'] ?? null) === 'bestand')<span class="sm:col-span-2 xl:col-span-5"><x-fa::badge tone="info">Aus bisherigen Zeiten übernommen</x-fa::badge></span>@endif
            <x-fa::field label="Tätigkeit" for="dar-zeit-art-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-art-{{ $d->id }}-{{ $index }}" wire:model.live="darZeitForm.{{ $d->id }}.{{ $index }}.use_case">
                    @foreach(\Platform\FoodAlchemist\Services\Rezept\DarreichungsZeitService::TAETIGKEITEN as $key => $label)<option value="{{ $key }}" @selected($key === $zeit['use_case'])>{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Bezugsmenge" for="dar-zeit-basis-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-basis-{{ $d->id }}-{{ $index }}" wire:model.live="darZeitForm.{{ $d->id }}.{{ $index }}.basis">
                    @foreach(\Platform\FoodAlchemist\Services\Rezept\DarreichungsZeitService::BASIS as $key => $label)<option value="{{ $key }}" @selected($key === $zeit['basis'])>{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field :label="str_ends_with(\Platform\FoodAlchemist\Services\Rezept\DarreichungsZeitService::BASIS[$zeit['basis']], '(s)') ? 'Zeit (Sekunden)' : 'Zeit (Minuten)'" for="dar-zeit-wert-{{ $d->id }}-{{ $index }}">
                <x-fa::input id="dar-zeit-wert-{{ $d->id }}-{{ $index }}" inputmode="decimal" wire:model="darZeitForm.{{ $d->id }}.{{ $index }}.wert" numeric />
            </x-fa::field>
            <x-fa::field label="Arbeitsort" for="dar-zeit-ort-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-ort-{{ $d->id }}-{{ $index }}" wire:model="darZeitForm.{{ $d->id }}.{{ $index }}.ort"><option value="kueche" @selected($zeit['ort'] === 'kueche')>Küche</option><option value="vor_ort" @selected($zeit['ort'] === 'vor_ort')>Vor Ort</option></x-fa::select>
            </x-fa::field>
            <div class="flex items-end pb-1"><x-fa::icon-button icon="heroicon-o-trash" label="Arbeitsschritt entfernen" wire:click="darZeitEntfernen({{ $d->id }}, {{ $index }})" /></div>
        </div>
    @endforeach
    <div class="flex flex-wrap gap-2">
        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="darZeitHinzu({{ $d->id }})">Arbeitsschritt hinzufügen</x-fa::button>
        <x-fa::button size="sm" wire:click="darZeitenSpeichern({{ $d->id }})">Zeiten übernehmen</x-fa::button>
    </div>
    @php
        $vn = (float) ($av['portionen'] ?? 100);
        $vnText = number_format($vn, floor($vn) == $vn ? 0 : 1, ',', '.') . ($vn == 1 ? ' Portion' : ' Portionen');
        $vMin = fn ($m) => number_format((float) $m, 1, ',', '.') . ' min';
        $vHerst = (float) ($av['herstellung_minuten'] ?? 0);
        $vFertig = (float) ($av['minuten_gesamt'] ?? 0);
    @endphp
    <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" aria-live="polite" data-dar-zeitvorschau>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <span class="font-semibold text-[length:var(--fa-text-lg)]">Zeitvorschau</span>
            <x-fa::field label="Portionen" for="dar-vorschau-portionen-{{ $d->id }}" hint="Nur Vorschau, wird nicht gespeichert">
                <x-fa::input id="dar-vorschau-portionen-{{ $d->id }}" inputmode="decimal" size="sm" wire:model.blur="darVorschauPortionen" numeric data-dar-vorschau-portionen />
            </x-fa::field>
        </div>
        @if(($darZeitForm[$d->id] ?? []) != ($darZeitStart[$d->id] ?? []))<span class="{{ $hinweis }}">Noch nicht gespeichert · Vorschau rechnet mit den übernommenen Zeiten</span>@endif
        <div class="grid gap-4 sm:grid-cols-3 py-2">
            <div data-zeit="herstellung"><span class="{{ $hinweis }}">Herstellung (Basisrezepte)</span><strong class="block text-[length:var(--fa-text-2xl)] font-medium tabular-nums">{{ $vMin($vHerst) }}</strong><span class="{{ $hinweis }}">Gepflegt am Basisrezept</span></div>
            <div data-zeit="fertigstellen"><span class="{{ $hinweis }}">Fertigstellen in der Küche</span><strong class="block text-[length:var(--fa-text-2xl)] font-medium tabular-nums">{{ $vMin($vFertig) }}</strong><span class="{{ $hinweis }}">Arbeitsschritte dieser Darreichung</span></div>
            <div data-zeit="vor-ort"><span class="{{ $hinweis }}">Service vor Ort</span><strong class="block text-[length:var(--fa-text-2xl)] font-medium tabular-nums">{{ $vMin($av['vor_ort_minuten'] ?? 0) }}</strong><span class="{{ $hinweis }}">Serviceplanung &amp; Servicelohn</span></div>
        </div>
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-t border-[var(--fa-line)] pt-2" data-zeit="kueche-gesamt"><span class="font-semibold">Küche gesamt · {{ $vnText }}</span><strong class="tabular-nums">{{ $vMin($vHerst + $vFertig) }}</strong></div>
        @if(($av['passiv_minuten'] ?? 0) > 0)<div class="flex flex-wrap items-baseline justify-between gap-2" data-zeit="passiv"><span class="{{ $hinweis }}">Passiv (Stand- und Gerätezeit) · nicht in den Personenminuten</span><span class="tabular-nums">{{ $vMin($av['passiv_minuten']) }}</span></div>@endif
        @foreach($av['herstellung_rezepte'] ?? [] as $hr)
            <span class="{{ $hinweis }}">{{ $hr['name'] }} (Basisrezept) · {{ $vMin($hr['minuten']) }}</span>
        @endforeach
        @if(($av['vollstaendig'] ?? true) === false)<x-fa::signal tone="warn">Menge fehlt. Zeit nicht vollständig berechnet.</x-fa::signal>@endif
        @foreach($av['posten'] ?? [] as $zp)
            <span class="{{ $hinweis }}">{{ \Platform\FoodAlchemist\Services\Rezept\DarreichungsZeitService::TAETIGKEITEN[$zp['use_case']] }} · {{ $zp['menge'] ?? 'Menge fehlt' }} × {{ $zp['wert'] }} {{ in_array($zp['basis'], ['portion', 'einheit', 'stueck']) ? 's' : 'min' }} · {{ $zp['ort'] === 'kueche' ? 'Küche' : 'Vor Ort' }}</span>
        @endforeach
        @if(($av['vor_ort_minuten'] ?? 0) > 0)<span>Service-Grundlohn für {{ $vnText }}: <x-fa::money :value="$av['service_kosten']['lohn_eur'] ?? null" /> bei <x-fa::money :value="$av['service_kosten']['stundensatz'] ?? null" /> / h</span>@endif
        <span class="{{ $hinweis }}">Service rechnet im Auftrag mit dem allgemeinen Team-Stundensatz. Lohnnebenkosten und Aufschläge ergänzt die Auftragskalkulation. Regeneration wird zusätzlich berechnet.</span>
    </div>
</section>
