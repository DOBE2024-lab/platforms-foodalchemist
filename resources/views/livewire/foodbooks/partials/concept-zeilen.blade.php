{{-- Lese-Ansicht eines Concepts im Foodbook-Editor: Zeilen aus WordingResolver::gerichtZeilen
     (Gang-Überschrift = header, Paket mit Preis pro Person = paket, darunter die Gericht-Texte).
     EINE Darstellung für den concept_ref-Block (#7/C2) und die aufklappbare Edition eines
     Format-Kapitels. Erwartet im Scope: $zeilen, $euro; optional $blockId (nur dann sind
     Gericht-Zeilen mit slot_id inline editierbar, C1) und $editSlotKey. Ohne $blockId: nur lesen. --}}
@php $blockId = $blockId ?? null; @endphp
@foreach($zeilen as $g)
    @php
        $istEditierbar = $blockId !== null && isset($g['slot_id']);
        $slotKey = $istEditierbar ? $blockId . ':' . $g['slot_id'] : null;
    @endphp
    @if(($g['type'] ?? '') === 'header')
        <p class="mt-1.5 first:mt-0 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px" data-fb-zeile-gang>{{ $g['text'] }}</p>
    @elseif(($g['type'] ?? '') === 'paket')
        <div class="mt-1 flex items-center gap-1.5" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px" data-fb-zeile-paket>
            <x-fa::badge tone="info" class="shrink-0">Paket</x-fa::badge>
            <span class="min-w-0 break-words text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $g['text'] }}</span>
            @if(($g['preis'] ?? null) !== null)<span class="ml-auto shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $euro($g['preis']) }} pro Person</span>@endif
        </div>
    @elseif($slotKey !== null && ($editSlotKey ?? null) === $slotKey)
        <div class="flex items-center gap-1" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px" data-fb-slot-editor>
            <x-fa::input size="sm" wire:model="editSlotWording" wire:keydown.enter="slotWordingSpeichern" wire:keydown.escape="slotWordingAbbrechen"
                   class="flex-1 min-w-0" placeholder="Name für Gäste, leer lassen für den Standard" aria-label="Name für Gäste" data-fb-slot-input />
            <x-fa::icon-button icon="heroicon-m-check" label="Name speichern" size="sm" wire:click="slotWordingSpeichern" />
            <x-fa::icon-button icon="heroicon-m-x-mark" label="Abbrechen" size="sm" wire:click="slotWordingAbbrechen" />
        </div>
    @else
        <div class="group/dish flex items-center gap-1.5 text-[length:var(--fa-text-md)] {{ ($g['source'] ?? null) === 'name' ? 'italic text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]' }}" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px" data-fb-zeile-gericht>
            <span class="min-w-0 break-words">{{ $g['text'] }}</span>
            @if(($g['source'] ?? null) === 'name')<span class="shrink-0 not-italic text-[length:var(--fa-text-sm)]">Gästetext fehlt</span>@endif
            {{-- Einzelpreis-Concept: VK je direkter Gericht-Zeile auch in der Editor-Vorschau. --}}
            @if(($g['preis'] ?? null) !== null)<span class="ml-auto shrink-0 not-italic text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $euro($g['preis']) }} pro Person</span>@endif
            @if($istEditierbar)
                <button type="button" wire:click="slotWordingBearbeiten({{ $blockId }}, {{ $g['slot_id'] }}, @js(($g['source'] ?? null) === 'name' ? '' : $g['text']))"
                        class="shrink-0 inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] opacity-0 group-hover/dish:opacity-100 focus:opacity-100 transition-opacity" title="Namen für Gäste bearbeiten" aria-label="Namen für Gäste bearbeiten" data-fb-slot-edit>@svg('heroicon-m-pencil', 'w-3.5 h-3.5')</button>
            @endif
        </div>
    @endif
@endforeach
