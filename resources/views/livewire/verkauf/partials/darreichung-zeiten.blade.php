<section class="flex flex-col gap-3" data-dar-anrichten="{{ $d->id }}">
    <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="font-medium">Arbeitsschritte &amp; Zeiten</h4><x-fa::button size="sm" variant="ai" icon="heroicon-o-sparkles" wire:click="darZeitVorschlagen({{ $d->id }})" data-ki-eigenschaften>Zeiten vorschlagen</x-fa::button></div>
    <p class="{{ $hinweis }}">{{ $d->arbeitszeiten !== null ? 'Eigene Werte dieser Darreichung' : 'Bisherige Berechnung · noch nicht übernommen' }}</p>
    <x-fa::field label="Ausgabe-Posten dieser Darreichung" for="dar-posten-{{ $d->id }}">
        <x-fa::select id="dar-posten-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.ausgabe_station_id" wire:change="darreichungSpeichern({{ $d->id }})" data-vk-default-station>
            <option value="">Aus Komponenten / bisherigem Gerichts-Posten</option>
            @foreach($posten as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
        </x-fa::select>
    </x-fa::field>
    @foreach($darZeitForm[$d->id] ?? [] as $index => $zeit)
        <div wire:key="dar-zeit-{{ $d->id }}-{{ $index }}" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
            @if(($zeit['quelle'] ?? null) === 'ki_vorschlag')<span class="sm:col-span-2 xl:col-span-5"><x-fa::badge tone="warn">KI-Vorschlag · ungeprüft</x-fa::badge></span>@endif
            <x-fa::field label="Tätigkeit" for="dar-zeit-art-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-art-{{ $d->id }}-{{ $index }}" wire:model.live="darZeitForm.{{ $d->id }}.{{ $index }}.use_case">
                    @foreach(\Platform\FoodAlchemist\Services\DarreichungsZeitService::TAETIGKEITEN as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Bezugsmenge" for="dar-zeit-basis-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-basis-{{ $d->id }}-{{ $index }}" wire:model.live="darZeitForm.{{ $d->id }}.{{ $index }}.basis">
                    @foreach(\Platform\FoodAlchemist\Services\DarreichungsZeitService::BASIS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Zeit" for="dar-zeit-wert-{{ $d->id }}-{{ $index }}">
                <x-fa::input id="dar-zeit-wert-{{ $d->id }}-{{ $index }}" inputmode="decimal" wire:model="darZeitForm.{{ $d->id }}.{{ $index }}.wert" numeric />
            </x-fa::field>
            <x-fa::field label="Arbeitsort" for="dar-zeit-ort-{{ $d->id }}-{{ $index }}">
                <x-fa::select id="dar-zeit-ort-{{ $d->id }}-{{ $index }}" wire:model="darZeitForm.{{ $d->id }}.{{ $index }}.ort"><option value="kueche">Küche</option><option value="vor_ort">Vor Ort</option></x-fa::select>
            </x-fa::field>
            <div class="flex items-end pb-1"><x-fa::icon-button icon="heroicon-o-trash" label="Arbeitsschritt entfernen" wire:click="darZeitEntfernen({{ $d->id }}, {{ $index }})" /></div>
        </div>
    @endforeach
    <div class="flex flex-wrap gap-2">
        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="darZeitHinzu({{ $d->id }})">Arbeitsschritt hinzufügen</x-fa::button>
        <x-fa::button size="sm" wire:click="darZeitenSpeichern({{ $d->id }})">Zeiten übernehmen</x-fa::button>
    </div>
    <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" aria-live="polite" data-dar-zeitvorschau>
        <span class="font-medium">Gespeicherte Zeitvorschau · 100 Portionen</span>
        <span>Küchenarbeit: {{ number_format((float) ($av['minuten_gesamt'] ?? 0), 1, ',', '.') }} min · Vor Ort: {{ number_format((float) ($av['vor_ort_minuten'] ?? 0), 1, ',', '.') }} min</span>
        @if(($av['vollstaendig'] ?? true) === false)<x-fa::signal tone="warn">Menge fehlt. Zeit nicht vollständig berechnet.</x-fa::signal>@endif
        @foreach($av['posten'] ?? [] as $zp)
            <span class="{{ $hinweis }}">{{ \Platform\FoodAlchemist\Services\DarreichungsZeitService::TAETIGKEITEN[$zp['use_case']] }} · {{ $zp['menge'] ?? 'Menge fehlt' }} × {{ $zp['wert'] }} {{ in_array($zp['basis'], ['portion', 'einheit', 'stueck']) ? 's' : 'min' }} · {{ $zp['ort'] === 'kueche' ? 'Küche' : 'Vor Ort' }}</span>
        @endforeach
        @if(($av['vor_ort_minuten'] ?? 0) > 0)<span>Service-Grundlohn für 100 Portionen: <x-fa::money :value="$av['service_kosten']['lohn_eur'] ?? null" /> bei <x-fa::money :value="$av['service_kosten']['stundensatz'] ?? null" /> / h</span>@endif
        <span class="{{ $hinweis }}">Service rechnet im Auftrag mit dem allgemeinen Team-Stundensatz. Lohnnebenkosten und Aufschläge ergänzt die Auftragskalkulation. Regeneration wird zusätzlich berechnet.</span>
    </div>
</section>
