@php $gi = $darGefaess[$d->id] ?? null; @endphp
@if($gi)
    <div wire:key="dar-gef-{{ $d->id }}" data-dar-gefaess-info="{{ $d->id }}">
        <div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                {{-- Geschirr-Empfehlung steht seit 19.11.2026 als Feld neben dem Gefäß; hier nur der Hinweis, wenn sie vom Typ des Gefäßes abweicht --}}
                @if($gi['uebersteuert'])<x-fa::badge tone="info" data-dar-uebersteuert title="Die Geschirr-Empfehlung weicht vom Typ des Gefäßes ab">Empfehlung weicht ab (Gefäß: {{ $gi['gefaess_typ_name'] }})</x-fa::badge>
                @endif
                @if($gi['gefaess_id'])
                    {{-- Art des Gefäßes (17.11.2026): leer = Eintrag des Teams am Grundprodukt (Art + Preis), sonst Automatik (Verleih → extern, sonst Einweg) --}}
                    <label class="inline-flex items-center gap-1"><span class="font-medium">Kostenart</span>
                        <select wire:model="darForm.{{ $d->id }}.vessel_cost_type" wire:change="darreichungSpeichern({{ $d->id }})" aria-label="Art des Gefäßes" class="{{ $feldKlein }} fa-select pr-8 w-52" data-dar-kostenart>
                            <option value="">{{ 'Vorgabe: '.(\Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::ARTEN[$gi['kostenart_vorgabe']] ?? '–') }}</option>
                            @foreach(\Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::ARTEN as $artKey => $artLabel)<option value="{{ $artKey }}">{{ $artLabel }}</option>@endforeach
                        </select>
                    </label>
                @endif
                {{-- Preis des Gefäßes und seines Zubehörs steht seit 20.11.2026 direkt unter dem Feld „Gefäß“ (Partial darreichung-gefaess-preis) --}}
                @if($gi['warnung'])<x-fa::badge tone="warn" data-dar-fuellmenge-warnung>{{ $gi['warnung'] }}</x-fa::badge>@endif
            </div>
        </div>
    </div>
@endif
