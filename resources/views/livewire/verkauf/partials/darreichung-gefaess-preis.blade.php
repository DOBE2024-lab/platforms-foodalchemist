{{-- Preis direkt unter dem Gefäß (20.11.2026, Dominique): Gefäß je Einheit, darunter das Zubehör am Gefäß-GP (Menge × Teil, Preis, Art),
     dann die Summe „Gefäß + Zubehör je Portion“. Fehlt ein Preis, steht es da („Deckel: kein Preis“) — nie still 0. --}}
@php $gi = $darGefaess[$d->id] ?? null; $proPortion = (float) ($d->unit_count ?: 1); @endphp
@if($gi && $gi['gefaess_id'])
    <div class="flex flex-col gap-0.5 mt-1 fa-t-muted" data-dar-gefaess-preis="{{ $d->id }}">
        @if($gi['kosten'] !== null)
            <span class="tabular-nums" data-dar-gefaess-kosten>Gefäß {{ $euro($gi['kosten']) }} je Einheit{{ $gi['preis_quelle'] === 'gp' ? ' (Preis am Grundprodukt)' : ($gi['miete'] ? ' (Preis je Einsatz (Miete/Spülservice))' : '') }}</span>
        @else
            <span><x-fa::badge tone="warn" title="Kein Preis je Stück: bei Einweg und Extern am Grundprodukt (Lager › Art als Gefäß) oder am Hauptartikel, bei Mehrweg am Grundprodukt eintragen" data-dar-gefaess-preis-fehlt>Gefäß-Preis fehlt</x-fa::badge></span>
        @endif
        @foreach($gi['zubehoer'] as $z)
            <span class="tabular-nums" wire:key="dar-gef-zub-{{ $d->id }}-{{ $z['gp_id'] }}" data-dar-gefaess-zubehoer="{{ $z['gp_id'] }}">+ {{ rtrim(rtrim(number_format($z['menge'], 3, ',', ''), '0'), ',') }} × {{ $z['name'] }}:
                @if($z['kosten'] !== null)
                    {{ $euro($z['kosten']) }}{{ $z['kostenart'] && $z['kostenart'] !== 'einweg' ? ' ('.(\Platform\FoodAlchemist\Services\GefaessKostenartService::ARTEN[$z['kostenart']] ?? '').')' : '' }}
                @else
                    <x-fa::badge tone="warn" title="Kein Preis am Zubehör: am Grundprodukt Art und Preis eintragen oder einen bepreisten Hauptartikel wählen" data-dar-gefaess-zubehoer-preis-fehlt>kein Preis</x-fa::badge>
                @endif
            </span>
        @endforeach
        @if($gi['zubehoer'] !== [])
            <span class="fa-t-label tabular-nums" data-dar-gefaess-summe>Gefäß + Zubehör je Portion {{ $euro($gi['gesamt'] * $proPortion) }}{{ ($gi['preis_fehlt'] || $gi['zubehoer_preis_fehlt']) ? ' (unvollständig)' : '' }}</span>
        @endif
    </div>
@endif
