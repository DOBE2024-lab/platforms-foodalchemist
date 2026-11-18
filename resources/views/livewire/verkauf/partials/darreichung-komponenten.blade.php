<section data-dar-delta="{{ $d->id }}" class="flex flex-col gap-3 border-t border-[var(--fa-line)] p-5">
    <h4 class="font-medium">Komponenten &amp; Mengen</h4>
    <p class="{{ $hinweis }}">Gramm je Portion eintragen oder weglassen. Leere Felder folgen der angezeigten Referenz. Eigene Mengen bleiben bei einem Standardwechsel erhalten. Neue Zutaten gehören in ein neues Gericht.</p>
    <div class="overflow-x-auto">
        <table class="fa-table fa-table--compact">
            <thead><tr><th>Komponente</th><th class="num">Referenz (g)</th><th class="num">Hier (g)</th><th>Weglassen</th></tr></thead>
            <tbody>
                @foreach($rezept->ingredients as $z)
                    @continue(! isset($darZeilen[$z->id]))
                    @php $delta = $deltaMap->get($z->id); @endphp
                    <tr wire:key="delta-{{ $d->id }}-{{ $z->id }}">
                        <td>{{ $z->display_name ?? $z->gp?->gp_name ?? $z->referencedRecipe?->name ?? $z->raw_text }}<span class="block {{ $hinweis }}">{{ $delta?->omitted ? 'Weggelassen' : ($delta?->quantity_override_g !== null ? 'Eigene Menge' : ($d->is_standard ? 'Aus dem Gericht' : ($d->menge_vom_standard ? 'Vom Standard' : 'Aus eigener Portionsmenge'))) }}</span></td>
                        <td class="num">{{ number_format($darZeilen[$z->id]['masse_g'], 0, ',', '.') }}</td>
                        <td class="num"><input type="text" inputmode="decimal" value="{{ $delta?->quantity_override_g }}" aria-label="Gramm in dieser Form"
                            wire:change="darDeltaMenge({{ $d->id }}, {{ $z->id }}, $event.target.value)" class="{{ $feldKlein }} w-24 text-right tabular-nums" placeholder="Referenz" @disabled($delta?->omitted) /></td>
                        <td class="text-center"><input type="checkbox" @checked($delta?->omitted) class="accent-[var(--fa-accent)]" aria-label="Weglassen" wire:click="darDeltaWeg({{ $d->id }}, {{ $z->id }})" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
