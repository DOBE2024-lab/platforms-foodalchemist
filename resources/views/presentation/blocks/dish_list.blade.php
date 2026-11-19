{{-- Gericht-Liste (Struktur-Builder, freistehend) — alle Zeilen flach, ohne Kapitel-Köpfe.
     2026-11-19: „Gerichte nebeneinander“ wirkt jetzt auch hier (vorher nur im Kapitel-Block). --}}
@php
    $sections = $content['sections'] ?? [];
    $cols = min(max((int) ($style['dish_columns'] ?? 1), 1), 2);
@endphp
@if(!empty($sections))
    <section class="pt-section pt-reveal">
        <div class="{{ $cols > 1 ? 'pt-wide' : 'pt-measure' }}">
            <div class="pt-blocks pt-cols-{{ $cols }}">
                @foreach($sections as $s)
                    @foreach(($s['blocks'] ?? []) as $b)
                        @include('foodalchemist::presentation.blocks._block_body', ['b' => $b, 'style' => $style])
                    @endforeach
                @endforeach
            </div>
        </div>
    </section>
@endif
