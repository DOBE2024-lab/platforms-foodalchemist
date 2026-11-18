<x-fa::section title="Darreichungen" icon="heroicon-o-squares-2x2"
    description="Eine Darreichung entsteht automatisch. Der Standard bestimmt die Verkaufs-Kennzahlen des Gerichts. Weitere Varianten haben eigene Mengen, Preise und Arbeitszeiten."
    data-vk-darreichungen>
    @php $belegte = $darreichungen->pluck('serving_form_id')->all(); @endphp
    <div class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <aside class="flex flex-col gap-2" aria-label="Darreichung auswählen">
            @foreach($darreichungen as $variante)
                <button type="button" wire:key="dar-auswahl-{{ $variante->id }}" wire:click="$set('darAktiv', {{ $variante->id }})"
                    aria-pressed="{{ $darAktiv === $variante->id ? 'true' : 'false' }}"
                    class="w-full text-left p-3 rounded-[var(--fa-radius-control)] border {{ $darAktiv === $variante->id ? 'border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                    <span class="block font-medium">{{ $variante->name ?: $variante->servingForm?->label }}</span>
                    <span class="block {{ $hinweis }}">{{ $variante->servingForm?->label }} · {{ $variante->quantity_per_unit_g !== null ? number_format($variante->quantity_per_unit_g, 0, ',', '.').(($variante->unit_count ?? 1) > 1 ? ' g je Einheit (Altbestand)' : ' g je Portion') : 'Menge fehlt' }}</span>
                    @if($variante->is_standard)<x-fa::badge tone="info" class="mt-2">Standard · Kennzahlen</x-fa::badge>@endif
                </button>
            @endforeach
            <div class="flex flex-col gap-2 mt-2" data-dar-anlegen>
                <x-fa::field label="Weitere Darreichung" for="dar-neue-form">
                    <x-fa::select id="dar-neue-form" wire:model="darNeueForm" aria-label="Servierform für die neue Darreichung">
                        <option value="">Servierform wählen …</option>
                        @foreach($servierformenAlle as $sf)
                            @continue($sf->is_inactive)
                            <option value="{{ $sf->id }}">{{ $sf->label }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::button icon="heroicon-m-plus" wire:click="darreichungNeu">Darreichung hinzufügen</x-fa::button>
            </div>
        </aside>
        <div class="min-w-0">
            @php $d = $darreichungen->firstWhere('id', $darAktiv) ?? $darreichungen->first(); @endphp
            @if($d)
                @php
                    $hatDeltas = $d->deltas->isNotEmpty();
                    $deltaMap = $d->deltas->keyBy('recipe_ingredient_id');
                    $fixiert = in_array(($darForm[$d->id]['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true);
                    $darFestFehlt = $fixiert ? \Platform\FoodAlchemist\Livewire\Verkauf\VkModal::darFestpreisFehlt($darForm[$d->id] ?? []) : null;
                    $av = $anrichtenVorschau[$d->id] ?? [];
                @endphp
                <div wire:key="dar-{{ $d->id }}" class="fa-surface p-4 flex flex-col gap-5" data-dar-editor="{{ $d->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-medium">{{ $d->name ?: $d->servingForm?->label }}</h3>
                        @if($d->is_standard)
                            <x-fa::badge tone="info">Standard-Darreichung</x-fa::badge>
                        @else
                            <x-fa::button size="sm" variant="secondary" wire:click="darreichungStandard({{ $d->id }})">Als Standard festlegen</x-fa::button>
                        @endif
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <x-fa::field label="Bezeichnung" for="dar-name-{{ $d->id }}">
                            <x-fa::input id="dar-name-{{ $d->id }}" wire:model.blur="darForm.{{ $d->id }}.name" wire:change="darreichungSpeichern({{ $d->id }})" maxlength="120" placeholder="{{ $d->servingForm?->label }}" />
                        </x-fa::field>
                        <x-fa::field label="Servierform" for="dar-form-{{ $d->id }}">
                            <x-fa::select id="dar-form-{{ $d->id }}" wire:change="darreichungForm({{ $d->id }}, $event.target.value)" data-dar-form="{{ $d->id }}">
                                @foreach($servierformenAlle as $sf)
                                    <option value="{{ $sf->id }}" @selected($sf->id === $d->serving_form_id)>{{ $sf->label }}</option>
                                @endforeach
                            </x-fa::select>
                            @if($d->servingForm?->code === 'unbestimmt')<span class="text-[var(--fa-warn)] {{ $hinweis }}" data-dar-form-waehlen>Servierform wählen</span>@endif
                        </x-fa::field>
                        <x-fa::field :label="($d->unit_count ?? 1) > 1 ? 'Gramm je Einheit (Altbestand)' : 'Gramm je Portion'" for="dar-gramm-{{ $d->id }}" :hint="$hatDeltas ? 'Summe der angepassten Komponenten' : ($d->menge_vom_standard ? 'Folgt der Standard-Darreichung' : 'Eigene Portionsmenge')">
                            <x-fa::input id="dar-gramm-{{ $d->id }}" inputmode="decimal" wire:model.blur="darForm.{{ $d->id }}.quantity_per_unit_g" wire:change="darreichungSpeichern({{ $d->id }})" :readonly="$hatDeltas || $d->menge_vom_standard" numeric />
                        </x-fa::field>
                        <x-fa::field label="Gefäß" for="dar-gefaess-{{ $d->id }}">
                            <x-fa::select id="dar-gefaess-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.vessel_gp_id" wire:change="darreichungSpeichern({{ $d->id }})" data-dar-gefaess>
                                <option value="">kein Gefäß</option>
                                @foreach($gefaessGps as $gg)<option value="{{ $gg->id }}">{{ $gg->name }}</option>@endforeach
                                @if(($darForm[$d->id]['vessel_gp_id'] ?? null) && ! $gefaessGps->contains('id', $darForm[$d->id]['vessel_gp_id']))
                                    <option value="{{ $darForm[$d->id]['vessel_gp_id'] }}">{{ $darGefaess[$d->id]['gefaess_name'] ?? 'Gefäß' }}</option>
                                @endif
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Preisklasse" for="dar-klasse-{{ $d->id }}">
                            <x-fa::select id="dar-klasse-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.markup_class_id" wire:change="darreichungSpeichern({{ $d->id }})">
                                <option value="">keine</option>
                                @foreach($aufschlagsklassen as $ak)<option value="{{ $ak->id }}">{{ $ak->code }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                    </div>
                    @unless($d->is_standard)
                        <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]"><input type="checkbox" wire:model="darForm.{{ $d->id }}.menge_vom_standard" wire:change="darreichungSpeichern({{ $d->id }})" class="accent-[var(--fa-accent)]" /> Portionsmenge vom Standard übernehmen</label>
                    @endunless
                    @if(($d->unit_count ?? 1) > 1 || ($rezept->sales_unit_count ?? 1) > 1)<x-fa::notice tone="warn">Altbestand mit abweichender Zählweise. Mengen und Zeiten bleiben bis zu einer geprüften Umstellung erhalten.</x-fa::notice>@endif
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <x-fa::field label="Preis">
                            <div class="flex flex-wrap gap-2">
                                <x-fa::button size="sm" :variant="!$fixiert ? 'primary' : 'secondary'" wire:click="darreichungPreisModusGeaendert({{ $d->id }}, 'auto')" aria-pressed="{{ $fixiert ? 'false' : 'true' }}">automatisch</x-fa::button>
                                <x-fa::button size="sm" :variant="$fixiert ? 'primary' : 'secondary'" wire:click="darreichungPreisModusGeaendert({{ $d->id }}, 'fixed')" aria-pressed="{{ $fixiert ? 'true' : 'false' }}">fest</x-fa::button>
                            </div>
                        </x-fa::field>
                        <x-fa::field label="MwSt" for="dar-mwst-{{ $d->id }}">
                            <x-fa::select id="dar-mwst-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.vat_profile_key" wire:change="darreichungSpeichern({{ $d->id }})">
                                <option value="">aus Preisklasse{{ isset($darMwstVorgabe[$d->id]) ? ' (' . (['regulaer' => 'regulär', 'ermaessigt' => 'ermäßigt'][$darMwstVorgabe[$d->id]] ?? $darMwstVorgabe[$d->id]) . ')' : '' }}</option><option value="ermaessigt">ermäßigt</option><option value="regulaer">regulär</option>
                            </x-fa::select>
                        </x-fa::field>
                        @if($fixiert)
                            <x-fa::field label="Fester VK netto" for="dar-festpreis-{{ $d->id }}">
                                <x-fa::input id="dar-festpreis-{{ $d->id }}" inputmode="decimal" wire:model.blur="darForm.{{ $d->id }}.sales_net" wire:change="darreichungFestpreisFeld({{ $d->id }})" numeric />
                            </x-fa::field>
                            <x-fa::field label="Begründung" for="dar-grund-{{ $d->id }}" class="sm:col-span-2">
                                <x-fa::input id="dar-grund-{{ $d->id }}" wire:model.blur="darForm.{{ $d->id }}.price_override_reason" wire:change="darreichungFestpreisFeld({{ $d->id }})" />
                                @if($darFestFehlt)<span class="text-[var(--fa-warn)]" data-dar-fest-fehlt>{{ $darFestFehlt }}</span>@endif
                            </x-fa::field>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-x-6 gap-y-2 border-y border-[var(--fa-line)] py-3" aria-label="Kennzahlen dieser Darreichung">
                        <span>EK je Portion <x-fa::money :value="$d->ek_portion" /></span>
                        <span>VK netto <x-fa::money :value="$d->sales_net" /></span>
                        <span>VK brutto <x-fa::money :value="$d->sales_gross" /></span>
                    </div>
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-komponenten')
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-zeiten')
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-zubehoer')
                    @unless($d->is_standard)
                        <div class="border-t border-[var(--fa-line)] pt-3"><x-fa::button size="sm" variant="danger" icon="heroicon-o-trash" wire:click="darreichungLoeschen({{ $d->id }})" wire:confirm="Diese Darreichung löschen?">Darreichung löschen</x-fa::button></div>
                    @endunless
                </div>
            @else
                <x-fa::empty compact title="Noch keine Darreichung">Beim Speichern entsteht automatisch die Standard-Form.</x-fa::empty>
            @endif
        </div>
    </div>
</x-fa::section>
