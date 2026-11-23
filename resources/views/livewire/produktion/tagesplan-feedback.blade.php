{{-- Wandmonitor: Feedbackbogen zum Rezept der offenen Anleitung (Livewire\Produktion\TagesplanFeedback, 98 · 3.3 S6). --}}
@php
    $wKarte = 'rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-surface)]';   // wie im Tagesplan
    $fbAchsen = [
        'score' => 'Wie lief es?',
        'machbarkeit' => 'Machbarkeit',
        'aufwand' => 'Aufwand',
        'geschmack' => 'Geschmack',
    ];
    $fbGruende = (array) config('foodalchemist.feedback_gruende', []);
    $fbGewaehlt = (array) ($feedbackForm['gruende'] ?? []);
@endphp
{{-- Am Ende, wo es hingehoert: bewertet wird, was gekocht wurde. Landet ueber
     FeedbackService in derselben Ablage wie der Feedback-Tab des Rezepts. --}}
{{-- Ohne Zeile (Overlay zu) bleibt der Bogen gemountet, aber leer und versteckt: der Zustand überlebt das Schließen. --}}
<section class="{{ $wKarte }} px-3 py-3" @if($lineId === null) hidden @else data-tagesplan-wall-feedback @endif>
    @if($lineId !== null)
    <h3 class="text-lg font-bold">Feedback zum Rezept</h3>
    @if($fehler)<x-fa::notice tone="crit" class="mt-2" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($feedbackGespeichert)
        <p class="mt-1 inline-flex items-center gap-1 text-sm font-medium text-[var(--fa-ok)]" data-tagesplan-wall-feedback-ok>@svg('heroicon-m-check', 'w-4 h-4') Gespeichert, steht jetzt am Rezept.</p>
    @else
        <p class="mt-0.5 text-sm text-[var(--fa-ink-3)]">Die Küche, die es kocht, ist die ehrlichste Quelle.</p>
    @endif

    <div class="mt-3 space-y-3">
        @foreach($fbAchsen as $fbKey => $fbLabel)
            <div wire:key="fb-{{ $fbKey }}">
                <span class="block text-sm text-[var(--fa-ink-2)]">{{ $fbLabel }}</span>
                <div class="mt-1 flex gap-1.5" role="group" aria-label="{{ $fbLabel }}" data-tagesplan-wall-feedback-achse="{{ $fbKey }}">
                    @for($fbN = 1; $fbN <= 5; $fbN++)
                        @php
                            $fbAktiv = (int) ($feedbackForm[$fbKey] ?? 0) === $fbN;
                        @endphp
                        <button type="button"
                                wire:click="feedbackSetzen('{{ $fbKey }}', {{ $fbN }})"
                                aria-pressed="{{ $fbAktiv ? 'true' : 'false' }}"
                                class="h-11 min-w-11 flex-1 rounded-lg border text-base font-bold tabular-nums transition-colors {{ $fbAktiv ? 'border-[var(--fa-accent)] bg-[var(--fa-accent)] text-[var(--fa-on-accent)]' : 'border-[var(--fa-line)] bg-[var(--fa-ground)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                @if($fbAktiv) data-tagesplan-wall-feedback-aktiv @endif>{{ $fbN }}</button>
                    @endfor
                </div>
            </div>
        @endforeach

        {{-- Antippbare Gruende: der haeufige Fall wird zaehlbar, ohne Tastatur. --}}
        @if($fbGruende !== [])
            <div>
                <span class="block text-sm text-[var(--fa-ink-2)]">Was war los? Mehrfachauswahl möglich.</span>
                <div class="mt-1 flex flex-wrap gap-1.5" data-tagesplan-wall-feedback-gruende>
                    @foreach($fbGruende as $fbSlug => $fbText)
                        @php
                            $fbAn = in_array($fbSlug, $fbGewaehlt, true);
                        @endphp
                        <button type="button" wire:key="fbg-{{ $fbSlug }}"
                                wire:click="feedbackGrundUmschalten('{{ $fbSlug }}')"
                                aria-pressed="{{ $fbAn ? 'true' : 'false' }}"
                                class="h-10 rounded-lg border px-3 text-sm font-medium transition-colors {{ $fbAn ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'border-[var(--fa-line)] bg-[var(--fa-ground)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                @if($fbAn) data-tagesplan-wall-feedback-grund-aktiv="{{ $fbSlug }}" @endif>{{ $fbText }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        <div>
            <div class="flex items-center justify-between gap-2">
                <label for="tp-wall-feedback-kommentar" class="text-sm text-[var(--fa-ink-2)]">Sonst noch etwas?</label>
                {{-- Derselbe Diktat-Knopf wie Planungsstelle und Step-Editor: am Pass
                     bedienen nasse Hände keine Bildschirmtastatur. --}}
                @include('foodalchemist::livewire.recipes.partials.diktat-knopf', [
                    'audio' => 'feedbackAudio',
                    'marker' => 'wall-feedback',
                    'label' => 'Sprechen',
                    'btnAi' => 'inline-flex items-center gap-1.5 h-10 rounded-lg border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 text-sm font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]',
                ])
            </div>
            <textarea id="tp-wall-feedback-kommentar" wire:model="feedbackForm.comment" rows="2"
                      class="fa-control mt-1 w-full py-2 text-base leading-relaxed"
                      placeholder="Sprechen oder tippen, was keine der Kacheln trifft …"
                      data-tagesplan-wall-feedback-kommentar></textarea>
        </div>

        <button type="button" wire:click="feedbackSpeichern"
                class="h-11 w-full rounded-lg bg-[var(--fa-accent)] text-base font-semibold text-[var(--fa-on-accent)] transition-colors hover:bg-[var(--fa-accent-hover)]"
                data-tagesplan-wall-feedback-speichern>Feedback speichern</button>
    </div>
    @endif
</section>
