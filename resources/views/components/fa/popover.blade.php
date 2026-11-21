{{-- x-fa::popover — Aufklapp-Kasten mit freiem Inhalt (Felder, KI-Knöpfe) auf dem faMenu-Helfer (2.4a).
     Für Listen von Aktionen x-fa::menu nehmen; popover ist für Inhalt, der beim Klick darin offen bleiben soll.
     <x-fa::popover label="Neue Bestellrunde" breite="80">
         <x-slot:ausloeser><x-fa::button x-on:click="toggle($event)" aria-haspopup="dialog" x-bind:aria-expanded="offen">Neu</x-fa::button></x-slot:ausloeser>
         …
     </x-fa::popover>
     Der Auslöser braucht x-on:click="toggle($event)" (misst die Position) und x-bind:aria-expanded="offen".
     rolle: dialog (Standard, Formular) · menu (Gruppe von Aktionsknöpfen). label wird aria-label des Kastens.
     breite wie x-fa::menu (Ui::maps()['menueBreite']). Der Kasten hat Innenabstand p-3; schließen per Klick daneben,
     Escape oder x-on:click="offen = false" an einem Knopf darin.
     Attribute gehen an die Hülle (class, wire:key, data-* …), mit Präfix kasten: an den Kasten. --}}
@props(['label' => null, 'rolle' => 'dialog', 'breite' => 80])
@php
    $breiten = \Platform\FoodAlchemist\Support\Ui::maps()['menueBreite'];
    if (! isset($breiten[(int) $breite])) {
        throw new \InvalidArgumentException('x-fa::popover breite muss eine von ' . implode(', ', array_keys($breiten)) . ' sein, nicht ' . $breite);
    }
    if (! in_array($rolle, ['dialog', 'menu'], true)) {
        throw new \InvalidArgumentException("x-fa::popover rolle muss dialog oder menu sein, nicht {$rolle}");
    }
    $kastenAttr = [];
    foreach ($attributes->getAttributes() as $name => $wert) {
        if (str_starts_with($name, 'kasten:')) {
            $kastenAttr[substr($name, strlen('kasten:'))] = $wert;
        }
    }
    $kastenAttr = new \Illuminate\View\ComponentAttributeBag($kastenAttr);
    $huelle = $attributes->filter(fn ($wert, $name) => ! str_starts_with($name, 'kasten:'));
@endphp
<div {{ $huelle->class(['relative']) }} x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
    {{ $ausloeser }}
    <div {{ $kastenAttr->class(['hidden', $breiten[(int) $breite], 'fa-surface shadow-lg p-3']) }} x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="{{ $rolle }}" @if($label) aria-label="{{ $label }}" @endif>
        {{ $slot }}
    </div>
</div>
