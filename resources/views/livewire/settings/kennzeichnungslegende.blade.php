<div class="flex flex-col gap-4" data-settings-kennzeichnungslegende>
    @if($meldung)<x-fa::signal tone="ok">{{ $meldung }}</x-fa::signal>@endif
    @error('codes')<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
    <form wire:submit="speichern" class="flex flex-col gap-4">
        <x-fa::checkbox wire:model="aktiv">Eigene Teamlegende verwenden</x-fa::checkbox>
        <p class="fa-t-muted">Wähle ein Kürzel, um es mit dem bisherigen Eintrag zu tauschen. Ein Stern kennzeichnet weiterhin Spuren.</p>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach(['allergene' => 'Allergene', 'zusatzstoffe' => 'Zusatzstoffe'] as $gruppe => $titel)
                <x-fa::section variant="plain" :title="$titel">
                    <x-fa::table compact>
                        <thead><tr><th>{{ $titel }}</th><th>Standard</th><th>Team</th></tr></thead>
                        <tbody>
                            @foreach($standardCodes[$gruppe] as $slug => $standard)
                                <tr wire:key="legende-{{ $gruppe }}-{{ $slug }}">
                                    <td>{{ $grundlegende[$gruppe][$slug]['label'] }}</td>
                                    <td class="font-mono">{{ $standard }}</td>
                                    <td>
                                        <x-fa::select size="sm" aria-label="Teamkürzel für {{ $grundlegende[$gruppe][$slug]['label'] }}"
                                            wire:change="tauschen('{{ $gruppe }}', '{{ $slug }}', $event.target.value)">
                                            @foreach($standardCodes[$gruppe] as $code)
                                                <option value="{{ $code }}" @selected(($codes[$gruppe][$slug] ?? $standard) === $code)>{{ $code }}</option>
                                            @endforeach
                                        </x-fa::select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-fa::table>
                </x-fa::section>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <x-fa::button type="submit" variant="primary">Legende speichern</x-fa::button>
            <x-fa::button type="button" variant="ghost" wire:click="grundlegendeLaden">Standardlegende laden</x-fa::button>
        </div>
    </form>
</div>
