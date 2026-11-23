{{-- Ein Verwendungs-Ort der Gericht-Verwaltung, anklickbar wie früher „Wo verwendet?“ (Kurator/Dominique 23.11.2026).
     Erwartet: $ort (Text) · $ziel (['typ','id'] oder null — null = fremd/nicht sichtbar → nur Text).
     Gericht/Basisrezept öffnen per Event (Panel bzw. Rezept-Fenster), alles andere über die Index-Seite mit Auswahl. --}}
@php
    $url = $ziel === null ? null : match ($ziel['typ']) {
        'konzept' => route('foodalchemist.concepter.index', ['edit' => $ziel['id']]),
        'angebot' => route('foodalchemist.angebote.index', ['sel' => $ziel['id']]),
        'speiseplan' => route('foodalchemist.speiseplan.index', ['sp' => $ziel['id']]),
        'speisekarte' => route('foodalchemist.speisekarte.index', ['sk' => $ziel['id']]),
        'foodbook' => route('foodalchemist.foodbooks.index', ['fb' => $ziel['id']]),
        default => null,
    };
@endphp
@if($ziel !== null && $ziel['typ'] === 'gericht')
    <a href="#" role="button" wire:click.prevent="$dispatch('vk-recipe-selected', { id: {{ $ziel['id'] }} })" class="min-w-0 break-words text-[var(--fa-accent)] hover:underline" data-gericht-ort="gericht">{{ $ort }}</a>
@elseif($ziel !== null && $ziel['typ'] === 'rezept')
    <a href="#" role="button" wire:click.prevent="$dispatch('recipe-modal.oeffnen', { id: {{ $ziel['id'] }} })" class="min-w-0 break-words text-[var(--fa-accent)] hover:underline" data-gericht-ort="rezept">{{ $ort }}</a>
@elseif($url !== null)
    <a href="{{ $url }}" wire:navigate class="min-w-0 break-words text-[var(--fa-accent)] hover:underline" data-gericht-ort="{{ $ziel['typ'] }}">{{ $ort }}</a>
@else
    <span class="min-w-0 break-words">{{ $ort }}</span>
@endif
