{{-- Wandmonitor: Anleitung einer Zeile im Vollbild-Overlay (Livewire\Produktion\TagesplanAnleitung, 98 · 3.3 S6).
     Bleibt im Wandmodus gemountet; pollt selbst, solange eine Anleitung offen ist. --}}
@php
    $kg = \Platform\FoodAlchemist\Support\Produktion\WallStil::kg(...);
    $tonFlaeche = \Platform\FoodAlchemist\Support\Produktion\WallStil::TON_FLAECHE;
    $wKnopf = \Platform\FoodAlchemist\Support\Produktion\WallStil::KNOPF;
    $wEyebrow = \Platform\FoodAlchemist\Support\Produktion\WallStil::EYEBROW;
    $wKarte = \Platform\FoodAlchemist\Support\Produktion\WallStil::KARTE;
    $wSicher = \Platform\FoodAlchemist\Support\Produktion\WallStil::SICHER;
    $wHakenOffen = \Platform\FoodAlchemist\Support\Produktion\WallStil::HAKEN_OFFEN;
    $wHakenFertig = \Platform\FoodAlchemist\Support\Produktion\WallStil::HAKEN_FERTIG;
@endphp
<div @if($lineId !== null) wire:poll.30s @endif>
    <x-fa::modal name="wall-anleitung" fullscreen dark-canvas title="Anleitung" :title-name="$anleitung['name'] ?? null" :close-via="'anleitungSchliessen'">
        @if($fehler)<x-fa::notice tone="crit" class="mb-3" data-tagesplan-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($anleitung && (collect($anleitung['sicherheit']['allergene'] ?? [])->isNotEmpty() || collect($anleitung['sicherheit']['warnungen'] ?? [])->isNotEmpty() || collect($anleitung['sicherheit']['diaet'] ?? [])->isNotEmpty()))
            <x-slot:titleExtra>
                <span class="flex min-w-0 flex-wrap items-center gap-1.5" data-tagesplan-wall-sicherheitsblock>
                    @foreach(collect($anleitung['sicherheit']['warnungen'] ?? [])->take(2) as $warnung)
                        <span class="{{ $wSicher }} {{ $tonFlaeche['warn'] }}" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                    @endforeach
                    @foreach(collect($anleitung['sicherheit']['allergene'] ?? [])->take(5) as $a)
                        <span class="{{ $wSicher }} {{ $tonFlaeche['crit'] }}" data-tagesplan-wall-allergen>{{ $a['label'] }}{{ ($a['wert'] ?? '') === 'spuren' ? ' · Spuren' : '' }}</span>
                    @endforeach
                    @foreach(collect($anleitung['sicherheit']['diaet'] ?? [])->take(3) as $d)
                        <span class="{{ $wSicher }} {{ $tonFlaeche['ok'] }}" data-tagesplan-wall-diaet>{{ $d }}</span>
                    @endforeach
                </span>
            </x-slot:titleExtra>
        @endif
        <x-slot:actions>
            <button type="button" x-data @click="$wire.anleitungSchliessen(); close()"
                    class="{{ $wKnopf }}"
                    data-tagesplan-wall-anleitung-zurueck>
                @svg('heroicon-m-chevron-left', 'w-5 h-5') Zurück zum Monitor
            </button>
        </x-slot:actions>
        @if($anleitung)
            @php
                $wallStepKeys = array_keys($anleitung['arbeitsschritte'] ?? []);
                $wallErledigteSteps = collect($anleitung['step_erledigt'] ?? [])->map(fn ($i) => (int) $i)->intersect($wallStepKeys);
                $wallAlleStepsErledigt = $wallStepKeys !== [] && $wallErledigteSteps->count() === count($wallStepKeys);
                $wallLineErledigt = ($anleitung['line_status'] ?? null) === 'done';
                $wallLineLaeuft = ($anleitung['line_status'] ?? null) === 'in_progress';
                $wallGesamtKg = $anleitung['gesamt_kg'] ?? null;
                $wallGesamtKgText = $wallGesamtKg !== null ? $kg($wallGesamtKg) . ' kg' : null;
                $wallArbeitszeit = $anleitung['arbeitszeit_min'] ?? null;
                $wallStandzeit = $anleitung['standzeit_min'] ?? null;
                $wallDurchlaufzeit = $anleitung['durchlaufzeit_min'] ?? $wallArbeitszeit;
                $wallStartedAt = $anleitung['started_at'] ?? null;
            @endphp
            @endif
            {{-- Grid steht immer (98 · 3.3 S6): der Feedback-Bogen darin bleibt gemountet, ein halb ausgefüllter Bogen
                 überlebt das Schließen des Overlays. Ohne offene Anleitung ist das Grid versteckt und trägt keinen Marker. --}}
            {{-- Linke Spalte scrollt auf niedrigen Bildschirmen selbst — sonst lag das Feedback unten
                 außer Reichweite, solange die Schritte rechts länger waren. --}}
            <div class="grid gap-3 xl:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] 2xl:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]" @if($anleitung) data-tagesplan-wall-anleitung @else hidden @endif>
                <aside class="space-y-3 xl:sticky xl:top-0 xl:max-h-[calc(100dvh-9rem)] xl:self-start xl:overflow-y-auto xl:overscroll-contain xl:pr-1">
                    @if($anleitung)
                    <div class="{{ $wKarte }} px-3 py-2">
                        <p class="{{ $wEyebrow }}">Auftrag</p>
                        <p class="mt-0.5 text-base">für {{ $anleitung['auftrag'] }}</p>
                    </div>

                    <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-timer>
                        <div class="grid grid-cols-2 gap-2">
                            @if($wallGesamtKgText !== null)
                                <div data-tagesplan-wall-gesamtmenge>
                                    <p class="{{ $wEyebrow }}">Gesamtmenge</p>
                                    <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ $wallGesamtKgText }}</p>
                                </div>
                            @endif
                            <div>
                                <p class="{{ $wEyebrow }}">Zeit</p>
                                @if($wallArbeitszeit !== null || $wallStandzeit !== null)
                                    <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ $wallDurchlaufzeit }} min<span class="text-sm font-normal text-[var(--fa-ink-3)]"> gesamt</span></p>
                                    <p class="text-sm tabular-nums text-[var(--fa-ink-3)]">{{ (int) ($wallArbeitszeit ?? 0) }} min aktiv @if($wallStandzeit)· {{ $wallStandzeit }} min Garzeit @endif</p>
                                @else
                                    <p class="mt-0.5"><x-fa::badge tone="warn">Keine Zeit hinterlegt</x-fa::badge></p>
                                @endif
                            </div>
                        </div>
                        @if($wallLineLaeuft && $wallStartedAt !== null)
                            <div class="mt-3 rounded-xl {{ $tonFlaeche['info'] }} px-3 py-2"
                                 x-data="{ started: Date.parse(@js($wallStartedAt)), total: {{ (int) ($wallDurchlaufzeit ?? 0) }}, now: Date.now(), tick: null, init(){ this.tick = setInterval(() => this.now = Date.now(), 1000) }, elapsed(){ return Math.max(0, Math.floor((this.now - this.started) / 60000)) }, remaining(){ return this.total > 0 ? Math.max(0, this.total - this.elapsed()) : null } }"
                                 data-tagesplan-wall-laufzeit>
                                <p class="text-sm font-semibold">Läuft</p>
                                <p class="mt-0.5 text-base font-semibold tabular-nums text-[var(--fa-ink)]">
                                    <span x-text="elapsed()"></span> min gelaufen
                                    <template x-if="remaining() !== null"><span> · noch <span x-text="remaining()"></span> min</span></template>
                                </p>
                            </div>
                        @elseif(! $wallLineErledigt)
                            <button type="button" wire:click="anleitungStarten"
                                    class="mt-3 flex h-12 w-full items-center justify-between rounded-xl bg-[var(--fa-accent)] px-4 text-left text-base font-semibold text-[var(--fa-on-accent)] transition-colors hover:bg-[var(--fa-accent-hover)]"
                                    data-tagesplan-wall-start>
                                <span>Zubereitung starten</span>
                                @svg('heroicon-m-play', 'w-6 h-6')
                            </button>
                        @else
                            <div class="mt-3 flex items-center gap-2 rounded-xl {{ $tonFlaeche['ok'] }} px-3 py-2 text-base font-semibold">@svg('heroicon-m-check-circle', 'w-5 h-5') Erledigt</div>
                        @endif
                        @if($wallStepKeys !== [])
                            <button type="button" wire:click="anleitungAlleStepsUmschalten"
                                    class="mt-2 flex h-12 w-full items-center justify-between rounded-xl border px-4 text-left text-base font-semibold transition-colors {{ $wallAlleStepsErledigt || $wallLineErledigt ? 'border-transparent ' . $tonFlaeche['ok'] : 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                                    data-tagesplan-wall-anleitung-alle-steps>
                                <span>{{ $wallAlleStepsErledigt || $wallLineErledigt ? 'Alle Schritte erledigt' : 'Alle Schritte abhaken' }}</span>
                                @svg($wallAlleStepsErledigt || $wallLineErledigt ? 'heroicon-m-check-circle' : 'heroicon-o-check-circle', 'w-6 h-6')
                            </button>
                            <p class="mt-1.5 text-sm text-[var(--fa-ink-3)] tabular-nums" data-tagesplan-wall-step-fortschritt>{{ $wallErledigteSteps->count() }} von {{ count($wallStepKeys) }} Schritten erledigt</p>
                        @endif
                        {{-- Spec 76b: Etikett direkt aus der Küche — Anzahl + Menge wählt die Küche; Druckdialog im Monitor (kein neuer Tab) --}}
                        @if(! empty($anleitung['etikett']))
                            @php $et = $anleitung['etikett']; @endphp
                            <x-fa::card class="mt-2 rounded-xl border-[var(--fa-line-strong)] p-2.5" data-tagesplan-wall-etikett="" x-data="{ anzahl: {{ (int) $et['anzahl'] }}, gesamt: {{ $et['gesamt_kg'] !== null ? (float) $et['gesamt_kg'] : 'null' }}, menge: '', hand: false, druckt: false,
                                    init() { this.rechne() },
                                    rechne() { if (! this.hand) { this.menge = this.gesamt ? (Math.round(this.gesamt / this.anzahl * 1000) / 1000).toString().replace('.', ',') : '' } },
                                    plus(d) { this.anzahl = Math.max(1, Math.min(200, this.anzahl + d)); this.rechne() },
                                    drucke() { this.druckt = true; let url = {{ \Illuminate\Support\Js::from($et['url']) }}; url = url.replace(/([?&])anzahl=\d+/, '$1anzahl=' + this.anzahl); if (this.menge.trim() !== '') { url += '&' + encodeURIComponent('e[menge]') + '=' + encodeURIComponent(this.menge.trim() + ' kg') }
                                        const f = document.createElement('iframe'); f.style.position = 'fixed'; f.style.width = '0'; f.style.height = '0'; f.style.border = '0'; f.src = url;
                                        f.onload = () => { try { f.contentWindow.focus(); f.contentWindow.print() } catch (e) { window.location.assign(url) } this.druckt = false; setTimeout(() => f.remove(), 60000) }; document.body.appendChild(f) } }">
                                <p class="text-sm font-semibold text-[var(--fa-ink-2)]">Etiketten</p>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <button type="button" x-on:click="plus(-1)" class="h-12 w-12 rounded-xl border border-[var(--fa-line-strong)] text-xl font-semibold hover:bg-[var(--fa-hover)]" aria-label="Ein Etikett weniger">−</button>
                                    <span class="w-12 text-center text-xl font-semibold tabular-nums" x-text="anzahl" data-tagesplan-wall-etikett-anzahl></span>
                                    <button type="button" x-on:click="plus(1)" class="h-12 w-12 rounded-xl border border-[var(--fa-line-strong)] text-xl font-semibold hover:bg-[var(--fa-hover)]" aria-label="Ein Etikett mehr">+</button>
                                    <label class="ml-auto inline-flex items-center gap-1.5 text-sm text-[var(--fa-ink-3)]">je
                                        <input type="text" inputmode="decimal" x-model="menge" x-on:input="hand = true" class="fa-control h-12 w-20 text-right text-base tabular-nums" aria-label="Menge je Etikett in kg" data-tagesplan-wall-etikett-menge /> kg</label>
                                </div>
                                <button type="button" x-on:click="drucke()" x-bind:disabled="druckt"
                                        class="mt-2 flex h-12 w-full items-center justify-between rounded-xl bg-[var(--fa-accent)] px-4 text-left text-base font-semibold text-[var(--fa-on-accent)] transition-colors hover:bg-[var(--fa-accent-hover)]" data-tagesplan-wall-etikett-drucken>
                                    <span x-text="druckt ? 'Druck wird vorbereitet …' : 'Etiketten drucken'">Etiketten drucken</span>
                                    @svg('heroicon-o-printer', 'w-6 h-6')
                                </button>
                            </x-fa::card>
                        @endif
                    </section>

                    @if(!empty($anleitung['sub_rezepte']))
                        <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-subrezepte>
                            <h3 class="text-lg font-bold">Enthaltene Rezepte</h3>
                            <div class="mt-2 space-y-2">
                                @foreach($anleitung['sub_rezepte'] as $sub)
                                    @if($sub['line_id'] !== null)
                                        <button type="button" wire:click="oeffneAnleitung({{ $sub['line_id'] }})"
                                                class="flex w-full items-start gap-3 rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2 text-left transition-colors hover:border-[var(--fa-accent)]"
                                                data-tagesplan-wall-subrezept>
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border {{ $sub['erledigt'] ? $wHakenFertig : 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-3)]' }}">@svg($sub['erledigt'] ? 'heroicon-m-check' : 'heroicon-m-chevron-right', 'w-5 h-5')</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block whitespace-normal break-words text-base font-semibold leading-snug">{{ $sub['name'] }}</span>
                                                <span class="mt-0.5 block text-sm font-medium text-[var(--fa-ok)]">{{ $sub['typ'] }}</span>
                                            </span>
                                            <span class="shrink-0 text-right text-sm font-semibold text-[var(--fa-ink-3)] tabular-nums">{{ collect([$sub['menge'], $sub['zeit']])->filter()->implode(' · ') }}</span>
                                        </button>
                                    @else
                                        <div class="flex items-start gap-3 rounded-xl border border-dashed border-[var(--fa-line-strong)] px-3 py-2" data-tagesplan-wall-subrezept-fehlt>
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg {{ $tonFlaeche['warn'] }}">@svg('heroicon-m-exclamation-triangle', 'w-5 h-5')</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block whitespace-normal break-words text-base font-semibold leading-snug">{{ $sub['name'] }}</span>
                                                <span class="mt-0.5 block text-sm text-[var(--fa-warn)]">Für diesen Tag ist keine Produktion dafür geplant.</span>
                                            </span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if(!empty($anleitung['zutaten']))
                        <section class="{{ $wKarte }} px-3 py-3">
                            <h3 class="text-lg font-bold">Zutaten</h3>
                            <div class="mt-1.5 divide-y divide-[var(--fa-line)]" data-tagesplan-wall-zutatenliste>
                                @foreach($anleitung['zutaten'] as $zt)
                                    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 py-2 text-base" data-tagesplan-wall-zutat>
                                        <span class="min-w-0 whitespace-normal leading-snug">{{ $zt['name'] ?? ($zt['bezeichnung'] ?? 'Zutat ohne Namen') }}</span>
                                        <span class="shrink-0 text-right font-semibold tabular-nums">{{ $zt['menge'] ?? ($zt['quantity'] ?? '') }} {{ $zt['einheit'] ?? ($zt['unit'] ?? '') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if(!empty($anleitung['behaelter']))
                        <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-behaelter>
                            <h3 class="text-lg font-bold">Behälter</h3>
                            <div class="mt-1.5 divide-y divide-[var(--fa-line)]">
                                @foreach($anleitung['behaelter'] as $bh)
                                    <div class="py-2 text-base" data-tagesplan-wall-behaelter-item>
                                        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                                            <span class="min-w-0 whitespace-normal leading-snug text-[var(--fa-ink-2)]">{{ $bh['zweck'] }}</span>
                                            @if($bh['wert'])
                                                <span class="shrink-0 text-right font-semibold tabular-nums">{{ $bh['wert'] }}</span>
                                            @else
                                                <x-fa::badge tone="warn">fehlt</x-fa::badge>
                                            @endif
                                        </div>
                                        @if($bh['zusatz'])
                                            <p class="mt-0.5 text-sm text-[var(--fa-ink-3)]" data-tagesplan-wall-behaelter-alt>{{ $bh['zusatz'] }}</p>
                                        @endif
                                        @if($bh['hinweis'])
                                            <p class="mt-0.5 text-sm {{ $bh['wert'] ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-warn)]' }}">{{ $bh['hinweis'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if(!empty($anleitung['equipment']))
                        <section class="{{ $wKarte }} px-3 py-3" data-tagesplan-wall-equipment>
                            <h3 class="text-lg font-bold">Geräte</h3>
                            <div class="mt-1.5 divide-y divide-[var(--fa-line)]">
                                @foreach($anleitung['equipment'] as $eq)
                                    <div class="py-2 text-base" data-tagesplan-wall-equipment-item>
                                        <div class="flex items-start justify-between gap-3">
                                            <span class="min-w-0 whitespace-normal leading-snug">{{ $eq['name'] ?? 'Gerät' }}</span>
                                            @if($eq['gruppe'] ?? null)<span class="shrink-0 text-right text-sm text-[var(--fa-ink-3)]">{{ $eq['gruppe'] }}</span>@endif
                                        </div>
                                        @if($eq['notiz'] ?? null)<p class="mt-0.5 text-sm text-[var(--fa-ink-3)]">{{ $eq['notiz'] }}</p>@endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    {{-- Am Ende, wo es hingehoert: bewertet wird, was gekocht wurde. Landet ueber
                         FeedbackService in derselben Ablage wie der Feedback-Tab des Rezepts. --}}
                    @endif
                    <livewire:foodalchemist.produktion.tagesplan-feedback :line-id="$anleitung ? (int) $anleitung['line_id'] : null" key="tagesplan-feedback" />
                    @if($anleitung)

                    <div class="h-20 shrink-0" aria-hidden="true"></div>{{-- Auslauf: letzter Knopf (Feedback speichern) bleibt über dem Rand erreichbar, auch im Vollbild --}}
                    @endif
                </aside>

                @if($anleitung)
                <section class="{{ $wKarte }} p-3 md:p-4" data-tagesplan-wall-media>
                    <h3 class="text-lg font-bold">Schritte und Medien</h3>
                    @if(!empty($anleitung['schritte']))
                        <div class="mt-3 space-y-2">
                            @foreach($anleitung['schritte'] as $s)
                                @php
                                    $stepIndex = (int) $loop->index;
                                    $stepErledigt = in_array($stepIndex, $anleitung['step_erledigt'] ?? [], true) || $wallLineErledigt;
                                    $fotos = collect($s['fotos'] ?? $s['photos'] ?? [])->filter(fn ($f) => ($f['url'] ?? $f['src'] ?? null));
                                    $medien = collect($s['medien'] ?? $s['media'] ?? [])->filter(fn ($m) => ($m['url'] ?? $m['src'] ?? null));
                                @endphp
                                <article class="rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2.5 {{ $stepErledigt ? 'opacity-70' : '' }}" data-tagesplan-wall-schritt>
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="anleitungStepUmschalten({{ $stepIndex }})"
                                                class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border-2 text-xl font-bold tabular-nums {{ $stepErledigt ? $wHakenFertig : $wHakenOffen }}"
                                                title="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                aria-label="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                data-tagesplan-wall-step-abhaken>@if($stepErledigt)@svg('heroicon-m-check', 'w-7 h-7')@else{{ $s['nr'] ?? $loop->iteration }}@endif</button>
                                        <div class="min-w-0 flex-1">
                                            @if($s['phase'] ?? null)<p class="{{ $wEyebrow }}">{{ $s['phase'] }}</p>@endif
                                            <p class="text-lg leading-snug {{ $stepErledigt ? 'line-through' : '' }}">{{ $s['text'] ?? '' }}</p>
                                        </div>
                                    </div>
                                    @if($fotos->isNotEmpty())
                                        <div class="mt-4 grid grid-cols-[repeat(auto-fill,minmax(min(100%,16rem),1fr))] gap-3" data-tagesplan-wall-bilder>
                                            @foreach($fotos as $f)
                                                @php
                                                    $src = $f['url'] ?? $f['src'] ?? null;
                                                @endphp
                                                <figure class="overflow-hidden rounded-xl border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                                                    <img src="{{ $src }}" alt="{{ $f['caption'] ?? ('Schritt ' . ($s['nr'] ?? $loop->parent->iteration)) }}" class="h-56 w-full object-cover [@media(min-height:56rem)]:h-64" loading="lazy" />
                                                    @if($f['caption'] ?? null)<figcaption class="px-3 py-2 text-sm text-[var(--fa-ink-2)]">{{ $f['caption'] }}</figcaption>@endif
                                                </figure>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if(($s['video'] ?? null) || ($s['audio'] ?? null) || $medien->isNotEmpty())
                                        <div class="mt-4 space-y-3" data-tagesplan-wall-player>
                                            @if($s['video'] ?? null)<video src="{{ $s['video'] }}" controls playsinline class="max-h-[60vh] w-full rounded-xl bg-[var(--fa-rail)]"></video>@endif
                                            @if($s['audio'] ?? null)<audio src="{{ $s['audio'] }}" controls class="w-full"></audio>@endif
                                            @foreach($medien as $m)
                                                @php
                                                    $src = $m['url'] ?? $m['src'] ?? null;
                                                    $typ = $m['type'] ?? $m['typ'] ?? '';
                                                @endphp
                                                @if(str_contains((string) $typ, 'video'))
                                                    <video src="{{ $src }}" controls playsinline class="max-h-[60vh] w-full rounded-xl bg-[var(--fa-rail)]"></video>
                                                @elseif(str_contains((string) $typ, 'audio'))
                                                    <audio src="{{ $src }}" controls class="w-full"></audio>
                                                @elseif($src)
                                                    <a href="{{ $src }}" target="_blank" class="{{ $wKnopf }}">@svg('heroicon-m-arrow-top-right-on-square', 'w-5 h-5') Medium öffnen</a>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @elseif(!empty($anleitung['zubereitung']))
                        @php
                            $fallbackZeilen = collect(preg_split('/\R+/', (string) $anleitung['zubereitung']))
                                ->map(fn ($line) => trim($line))
                                ->filter()
                                ->values();
                            $fallbackStepIndex = -1;
                        @endphp
                        <div class="mt-3 space-y-2" data-tagesplan-wall-fallback-schritte>
                            @foreach($fallbackZeilen as $line)
                                @php
                                    $istHeading = str_starts_with($line, '##');
                                    $text = trim(preg_replace('/^#+\s*/', '', $line));
                                @endphp
                                @if($istHeading)
                                    <h4 class="pt-1 text-lg font-bold text-[var(--fa-ink-2)]">{{ $text }}</h4>
                                @else
                                    @php
                                        $fallbackStepIndex++;
                                        $stepErledigt = in_array($fallbackStepIndex, $anleitung['step_erledigt'] ?? [], true) || $wallLineErledigt;
                                    @endphp
                                    <div class="flex gap-3 rounded-xl border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2 text-lg leading-snug {{ $stepErledigt ? 'opacity-70' : '' }}" data-tagesplan-wall-fallback-zeile>
                                        <button type="button" wire:click="anleitungStepUmschalten({{ $fallbackStepIndex }})"
                                                class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border-2 text-xl font-bold tabular-nums {{ $stepErledigt ? $wHakenFertig : $wHakenOffen }}"
                                                title="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                aria-label="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt als erledigt abhaken' }}"
                                                data-tagesplan-wall-step-abhaken>@if($stepErledigt)@svg('heroicon-m-check', 'w-7 h-7')@else{{ $fallbackStepIndex + 1 }}@endif</button>
                                        <span class="min-w-0 flex-1 {{ $stepErledigt ? 'line-through' : '' }}">{{ $text }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="mt-4 rounded-xl border border-dashed border-[var(--fa-line-strong)] p-8 text-center" data-tagesplan-wall-anleitung-leer>
                            @svg('heroicon-o-document-text', 'mx-auto w-12 h-12 text-[var(--fa-ink-3)]')
                            <p class="mt-3 text-2xl font-bold">Keine Anleitung hinterlegt.</p>
                            <p class="mt-2 text-base text-[var(--fa-ink-3)]">Für dieses Rezept fehlen noch Zutaten, Schritte, Bilder oder Medien.</p>
                        </div>
                    @endif
                </section>
                @endif
            </div>
            @if(! $anleitung)
            <div class="grid min-h-[60vh] place-items-center text-center" data-tagesplan-wall-anleitung-leer>
                <div>
                    @svg('heroicon-o-question-mark-circle', 'mx-auto w-14 h-14 text-[var(--fa-ink-3)]')
                    <p class="mt-3 text-3xl font-bold">Keine Anleitung gefunden.</p>
                    <p class="mt-2 text-lg text-[var(--fa-ink-3)]">Die Aufgabe gehört nicht mehr zum gezeigten Tag oder wurde gerade neu eingeplant.</p>
                </div>
            </div>
        @endif
    </x-fa::modal>
</div>
