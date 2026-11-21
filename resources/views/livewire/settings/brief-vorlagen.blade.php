{{-- Schnellstart-Vorlagen (Brief-Templates) verwalten. Angelegt im Planung-Editor (Snapshot);
     hier: eigene umbenennen/aktiv/löschen + die des Oberteams nur lesen. Auch per MCP (brief_templates.*).
     Spec 84 L1: Vorlagen gehören dem Team — keine globalen, vom Master gepflegten Vorlagen mehr.
     Häufigste Aufgabe: eigene Vorlage umbenennen oder ausblenden. Darum eigene zuerst. --}}
@php
    $zeilenGruppen = [
        ['schluessel' => 'eigene', 'zeilen' => $eigene, 'bearbeitbar' => true, 'key' => 'bt'],
    ];
    if ($geerbte->isNotEmpty()) {   // nur Unter-Teams haben ein Oberteam — sonst kein leerer Abschnitt
        $zeilenGruppen[] = ['schluessel' => 'geerbte', 'zeilen' => $geerbte, 'bearbeitbar' => false, 'key' => 'btg'];
    }
@endphp

<div class="flex flex-col gap-5" data-settings-brief-vorlagen>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)
        <x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>
    @endif

    <x-fa::notice tone="info">
        Vorlagen entstehen in der <strong>Planung</strong> über „Als Vorlage speichern“. Sie nehmen den Auftrag, den Kreativ-Modus
        und alle Leitplanken mit. Hier benennst du sie um, schaltest sie aktiv oder inaktiv oder löschst sie.
    </x-fa::notice>

    @foreach($zeilenGruppen as $gruppe)
        @php
            $istEigene = $gruppe['schluessel'] === 'eigene';
            $zeilen = $gruppe['zeilen'];
            $bearbeitbar = $gruppe['bearbeitbar'];
        @endphp
        <x-fa::section :title="$istEigene ? 'Eigene Vorlagen' : 'Vorlagen des Oberteams'"
                       :meta="$zeilen->count() > 0 ? $zeilen->count() . ' ' . ($zeilen->count() === 1 ? 'Vorlage' : 'Vorlagen') : null"
                       :description="$istEigene ? 'Vorlagen deines Teams.' : 'Nur zum Verwenden. Ändern kann sie nur das Oberteam.'">
            @if($zeilen->isEmpty())
                @if($istEigene)
                    <x-fa::empty icon="heroicon-o-bookmark" title="Noch keine eigene Vorlage" compact>
                        In der Planung einen Auftrag vorbereiten und „Als Vorlage speichern“ wählen. Die Vorlage erscheint dann hier.
                    </x-fa::empty>
                @else
                    <x-fa::empty icon="heroicon-o-bookmark" title="Keine Vorlagen des Oberteams" compact />
                @endif
            @else
                <div class="overflow-x-auto -mx-4">
                    <table class="fa-table min-w-[640px]">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Bereich</th>
                                <th>Auftrag</th>
                                @if($bearbeitbar)
                                    <th>Status</th>
                                    <th><span class="sr-only">Aktionen</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($zeilen as $t)
                                <tr class="{{ $t->active ? '' : 'opacity-60' }}" wire:key="{{ $gruppe['key'] }}-{{ $t->id }}">
                                    <td class="align-top">
                                        @if($bearbeitbar && $editId === $t->id)
                                            <x-fa::input wire:model="editLabel" wire:keydown.enter="save" size="sm" aria-label="Name der Vorlage" class="min-w-[12rem]" />
                                        @else
                                            <span class="font-medium text-[var(--fa-ink)]">{{ $t->label }}</span>
                                        @endif
                                    </td>
                                    <td class="align-top text-[var(--fa-ink-2)] whitespace-nowrap">{{ $scopeLabel[$t->scope] ?? ucfirst((string) $t->scope) }}</td>
                                    <td class="align-top text-[var(--fa-ink-2)] max-w-[22rem]">
                                        <span class="line-clamp-2" title="{{ $t->brief }}">{{ \Illuminate\Support\Str::limit($t->brief, 140) }}</span>
                                    </td>
                                    @if($bearbeitbar)
                                        <td class="align-top">
                                            <x-fa::badge :tone="$t->active ? 'ok' : 'neutral'">{{ $t->active ? 'Aktiv' : 'Inaktiv' }}</x-fa::badge>
                                        </td>
                                        <td class="align-top">
                                            <div class="flex items-center justify-end gap-1">
                                                @if($editId === $t->id)
                                                    <x-fa::button size="sm" variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="save">Namen speichern</x-fa::button>
                                                @else
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="edit({{ $t->id }})">Umbenennen</x-fa::button>
                                                    <x-fa::menu class="inline-block" label="Weitere Aktionen für {{ $t->label }}" size="sm" breite="52">
                                                        <x-fa::menu-item :icon="$t->active ? 'heroicon-m-eye-slash' : 'heroicon-m-eye'" wire:click="toggleActive({{ $t->id }})">{{ $t->active ? 'Vorlage deaktivieren' : 'Vorlage aktivieren' }}</x-fa::menu-item>
                                                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                                        <x-fa::menu-item icon="heroicon-m-trash" danger :trenner="false" wire:click="loeschen({{ $t->id }})" wire:confirm="{{ 'Vorlage „' . $t->label . '“ löschen?' }}">Vorlage löschen</x-fa::menu-item>
                                                    </x-fa::menu>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-fa::section>
    @endforeach
    </fieldset>
</div>
