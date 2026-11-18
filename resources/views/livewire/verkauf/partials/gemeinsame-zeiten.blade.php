<x-fa::section title="Gemeinsame Planung des Gerichts" icon="heroicon-o-clock" description="Diese Angaben gelten gemeinsam für alle Darreichungen. Die aktive Arbeitszeit wird oben je Darreichung gepflegt." data-vk-eigenschaften>
    <div class="grid gap-4 sm:grid-cols-2" data-vk-produktion>
        <x-fa::field label="Passive Standzeit (min)" for="vk-standzeit" hint="Für die Durchlaufzeit, ohne Personenlohn">
            <x-fa::input id="vk-standzeit" type="number" min="0" wire:model="form.standzeit_min" numeric />
        </x-fa::field>
        <x-fa::field :label="($rezept->sales_unit_count ?? 1) > 1 ? 'Nebenkosten (€ je Ansatz, Altbestand)' : 'Nebenkosten (€ je Portion)'" for="vk-nebenkosten" hint="Gemeinsame Zusatzkosten des Gerichts">
            <x-fa::input id="vk-nebenkosten" type="number" min="0" step="0.01" wire:model="form.additional_costs_eur" numeric data-vk-nebenkosten />
        </x-fa::field>
    </div>
    @if($beteiligtePosten->isNotEmpty())<p class="{{ $hinweis }}" data-vk-beteiligte-posten>Beteiligt aus den Basisrezepten: {{ $beteiligtePosten->map(fn ($p) => $p['name'])->implode(' · ') }}</p>@endif
</x-fa::section>
