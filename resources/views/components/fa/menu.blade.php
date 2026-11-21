{{-- x-fa::menu — Aufklapp-Menü auf dem faMenu-Helfer (Welle 1, 1.4c). Einträge sind x-fa::menu-item.
     <x-fa::menu>…</x-fa::menu>                                     Auslöser „…“ (Weitere Aktionen), Breite 56
     <x-fa::menu label="Status ändern" icon="heroicon-m-tag" size="sm" breite="64" scroll>…</x-fa::menu>
     <x-fa::menu>
         <x-slot:ausloeser><x-fa::button icon-right="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Neu</x-fa::button></x-slot:ausloeser>
         …
     </x-fa::menu>
     Eigener Auslöser: braucht x-on:click="toggle($event)" (misst die Position), aria-haspopup="menu" und x-bind:aria-expanded="offen".
     breite: 40 · 44 · 48 · 52 · 56 · 60 · 64 · 72 (Ui::maps()['menueBreite']). scroll: höchstens 80 hoch, dann scrollen.
     Attribute gehen an die Hülle (class, wire:key, data-* …). Mit Präfix ausloeser: an den Standard-Auslöser,
     mit Präfix menue: an den Menükasten (z. B. menue:data-frei-menu). --}}
@props(['label' => 'Weitere Aktionen', 'icon' => 'heroicon-m-ellipsis-horizontal', 'size' => 'md', 'breite' => 56, 'scroll' => false])
@php
    $breiten = \Platform\FoodAlchemist\Support\Ui::maps()['menueBreite'];
    if (! isset($breiten[(int) $breite])) {
        throw new \InvalidArgumentException('x-fa::menu breite muss eine von ' . implode(', ', array_keys($breiten)) . ' sein, nicht ' . $breite);
    }
    $mitPraefix = function (string $praefix) use ($attributes) {
        $neu = [];
        foreach ($attributes->getAttributes() as $name => $wert) {
            if (str_starts_with($name, $praefix)) {
                $neu[substr($name, strlen($praefix))] = $wert;
            }
        }

        return new \Illuminate\View\ComponentAttributeBag($neu);
    };
    $ausloeserAttr = $mitPraefix('ausloeser:');
    $menueAttr = $mitPraefix('menue:');
    $huelle = $attributes->filter(fn ($wert, $name) => ! str_starts_with($name, 'ausloeser:') && ! str_starts_with($name, 'menue:'));
@endphp
<div {{ $huelle->class(['relative']) }} x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
    @isset($ausloeser)
        {{ $ausloeser }}
    @else
        <x-fa::icon-button :icon="$icon" :label="$label" :size="$size" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" :attributes="$ausloeserAttr" />
    @endisset
    <div {{ $menueAttr->class(['hidden', $breiten[(int) $breite], 'max-h-80 overflow-y-auto' => $scroll, 'fa-surface shadow-lg py-1']) }} x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
        {{ $slot }}
    </div>
</div>
