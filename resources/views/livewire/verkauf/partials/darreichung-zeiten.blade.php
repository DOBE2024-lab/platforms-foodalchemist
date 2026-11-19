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
                    @foreach(\Platform\FoodAlchemist\Services\DarreichungsZeitService::TAETIGKEITEN as $key => $label)<option value="{{ $key }}" @selected($key === $zeit['use_case'])>{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Bezugsmenge" for="dar-zeit-basis-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-basis-{{ $d->id }}-{{ $index }}" wire:model.live="darZeitForm.{{ $d->id }}.{{ $index }}.basis">
                    @foreach(\Platform\FoodAlchemist\Services\DarreichungsZeitService::BASIS as $key => $label)<option value="{{ $key }}" @selected($key === $zeit['basis'])>{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field :label="str_ends_with(\Platform\FoodAlchemist\Services\DarreichungsZeitService::BASIS[$zeit['basis']], '(s)') ? 'Zeit (Sekunden)' : 'Zeit (Minuten)'" for="dar-zeit-wert-{{ $d->id }}-{{ $index }}">
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
    <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" aria-live="polite" data-dar-zeitvorschau>
        <span class="font-semibold text-[length:var(--fa-text-lg)]">Gespeicherte Zeitvorschau · 100 Portionen</span>
        <div class="grid gap-4 sm:grid-cols-2 py-2">
            <div><span class="{{ $hinweis }}">Küchenarbeit</span><strong class="block text-[length:var(--fa-text-2xl)] font-medium tabular-nums">{{ number_format((float) ($av['minuten_gesamt'] ?? 0), 1, ',', '.') }} min</strong><span class="{{ $hinweis }}">Produktionsplanung &amp; Küchenlohn</span></div>
            <div><span class="{{ $hinweis }}">Arbeit vor Ort</span><strong class="block text-[length:var(--fa-text-2xl)] font-medium tabular-nums">{{ number_format((float) ($av['vor_ort_minuten'] ?? 0), 1, ',', '.') }} min</strong><span class="{{ $hinweis }}">Serviceplanung &amp; Servicelohn</span></div>
        </div>
        @if(($av['vollstaendig'] ?? true) === false)<x-fa::signal tone="warn">Menge fehlt. Zeit nicht vollständig berechnet.</x-fa::signal>@endif
        @foreach($av['posten'] ?? [] as $zp)
            <span class="{{ $hinweis }}">{{ \Platform\FoodAlchemist\Services\DarreichungsZeitService::TAETIGKEITEN[$zp['use_case']] }} · {{ $zp['menge'] ?? 'Menge fehlt' }} × {{ $zp['wert'] }} {{ in_array($zp['basis'], ['portion', 'einheit', 'stueck']) ? 's' : 'min' }} · {{ $zp['ort'] === 'kueche' ? 'Küche' : 'Vor Ort' }}</span>
        @endforeach
        @if(($av['vor_ort_minuten'] ?? 0) > 0)<span>Service-Grundlohn für 100 Portionen: <x-fa::money :value="$av['service_kosten']['lohn_eur'] ?? null" /> bei <x-fa::money :value="$av['service_kosten']['stundensatz'] ?? null" /> / h</span>@endif
        <span class="{{ $hinweis }}">Service rechnet im Auftrag mit dem allgemeinen Team-Stundensatz. Lohnnebenkosten und Aufschläge ergänzt die Auftragskalkulation. Regeneration wird zusätzlich berechnet.</span>
    </div>
</section>
