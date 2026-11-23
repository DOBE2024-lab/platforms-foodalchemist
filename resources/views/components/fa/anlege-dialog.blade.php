{{-- x-fa::anlege-dialog — 98 · 2.4c (U3): erst der Name, dann der Datensatz. Gegenstück zum Trait
     Livewire\Concerns\LegtMitNamenAn (neu() öffnet, neuAnlegen() legt an). Ein Dialog je Seite (Modal fa-anlegen).
     title: Dialogtitel · label: Feldbeschriftung · knopf: Text der Hauptaktion · hint: optionaler Satz unter dem Feld ·
     fehler: $neuFehler der Komponente. --}}
@props(['title', 'label' => 'Name', 'knopf' => 'Anlegen', 'hint' => null, 'fehler' => null])
<x-fa::modal name="fa-anlegen" :title="$title" size="max-w-md">
    <div data-anlege-dialog="">
        <x-fa::field :label="$label" for="fa-anlegen-name" required :hint="$hint" :error="$fehler">
            <x-fa::input id="fa-anlegen-name" :fehler="$fehler" wire:model="neuName" wire:keydown.enter="neuAnlegen" autocomplete="off" data-anlege-name="" />
        </x-fa::field>
    </div>
    <x-slot:footer>
        <x-fa::button variant="ghost" x-on:click="$dispatch('modal.close', { name: 'fa-anlegen' })">Abbrechen</x-fa::button>
        <x-fa::button variant="primary" wire:click="neuAnlegen" data-anlege-knopf="">{{ $knopf }}</x-fa::button>
    </x-slot:footer>
</x-fa::modal>
