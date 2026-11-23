{{-- „Rezept in allen Verwendungen tauschen" + „Rezept löschen" — EIN Partial für Detail-Panel
     UND Editor (Pendant zum GP-Verwaltungsblock, 2026-09-04). Bewusst geteilt: der GP-Block
     wurde 2026-08 in den Editor KOPIERT und lief seitdem auseinander (roher Status-String,
     unfindbarer Reiter). Hier gibt es nur eine Quelle.

     Erwartet aus der Komponente: $tauschBilanz · $tauschKandidaten · $tauschReferenzen ·
     $fehlerTausch · $hinweisTausch (Trait TauschtRezept) sowie $tauschSuche.
     Parameter: $rezeptName (für die Rückfragen) · $kompakt (Panel-Typo statt Editor-Typo).
     Gericht-Verwaltung (23.11.2026): ist $gerichtVerwaltung gesetzt (Gericht statt Basisrezept), zeigt der Block den
     Gericht-Weg — Bilanz aller aktiven Verwendungen, Vorschau je Bereich, „Ersetzen“, danach Löschen (weich).
     fa-pass: nur Tokens und x-fa-Bausteine (hell + Werkbank-Modus). --}}
@php
    $tt = $kompakt ? 'text-[length:var(--fa-text-sm)]' : 'text-[length:var(--fa-text-md)]';
    $mehrzahl = fn (int $n, string $eins, string $mehr) => $n . ' ' . ($n === 1 ? $eins : $mehr);
    $statusTon = ['approved' => 'ok', 'review' => 'warn', 'tentative' => 'warn', 'rejected' => 'crit', 'deprecated' => 'crit', 'archived' => 'neutral'];
@endphp

<div class="flex flex-col gap-3" data-rezept-verwaltung>
    @if($fehlerTausch !== null)
        <x-fa::notice tone="crit" data-rezept-tausch-fehler>{{ $fehlerTausch }}</x-fa::notice>
    @endif
    @if($hinweisTausch !== null)
        <x-fa::notice tone="ok" data-rezept-tausch-hinweis>{{ $hinweisTausch }}</x-fa::notice>
    @endif

    @if(($gerichtVerwaltung ?? null) !== null)
        @php
            $gv = $gerichtVerwaltung;
            $vs = $gv['vorschau'];
            $bereichText = ['konzepte' => 'Konzepte und Pakete', 'komponenten' => 'Als Komponente in Gerichten', 'speiseplan' => 'Speiseplan ab heute',
                'speisekarte' => 'Gültige Speisekarten', 'angebote' => 'Offene Angebote', 'foodbooks' => 'Foodbooks', 'ersatz' => 'Ersatz-Verknüpfungen'];
            $geld = fn ($w) => $w === null ? '–' : number_format((float) $w, 2, ',', '.') . ' €';
        @endphp
        {{-- 1. Bilanz: aktive Verwendungen mit Namen, historische als Zahl --}}
        <div class="flex flex-col gap-1.5" data-gericht-bilanz>
            @if($gv['loesch']['aktiv'] === [])
                <p class="fa-t-body">Keine aktive Verwendung.</p>
            @else
                <p class="fa-t-body">Aktiv verwendet: {{ $mehrzahl(count($gv['loesch']['aktiv']), 'Stelle', 'Stellen') }}.</p>
                <ul class="{{ $tt }} flex flex-col gap-1" data-gericht-aktiv>
                    @foreach(array_slice($gv['loesch']['aktiv'], 0, 12) as $i => $a)
                        <li class="flex items-start gap-2 min-w-0" wire:key="gv-aktiv-{{ $i }}">
                            @include('foodalchemist::livewire.recipes.partials.verwaltung-ort', ['ort' => $a['ort'], 'ziel' => $a['ziel'] ?? null])
                            @if($a['grund'])<span class="shrink-0 fa-t-muted">{{ $a['grund'] }}</span>@endif
                        </li>
                    @endforeach
                    @if(count($gv['loesch']['aktiv']) > 12)<li class="fa-t-muted">… und {{ count($gv['loesch']['aktiv']) - 12 }} weitere</li>@endif
                </ul>
            @endif
            @if($gv['loesch']['historisch'] > 0)
                <p class="fa-t-muted" data-gericht-historisch>{{ $mehrzahl((int) $gv['loesch']['historisch'], 'historischer Eintrag', 'historische Einträge') }} (vergangene Tage, versendete Angebote, archivierte Foodbooks …) behalten das Gericht als Verweis.</p>
            @endif
        </div>

        {{-- 2. Ersetzen: Ziel wählen → Vorschau je Bereich → Ersetzen --}}
        @if($vs === null)
            <div class="flex flex-col gap-1.5" data-gericht-tausch>
                <label for="gericht-tausch-suche-{{ $kompakt ? 'panel' : 'editor' }}" class="fa-t-label">In allen Verwendungen ersetzen durch <span class="fa-t-muted font-normal">(ein Gericht; Menge und Einheit bleiben)</span></label>
                <x-fa::input type="search" id="gericht-tausch-suche-{{ $kompakt ? 'panel' : 'editor' }}" wire:model.live.debounce.300ms="tauschSuche"
                    placeholder="Ersatz-Gericht suchen …" :size="$kompakt ? 'sm' : 'md'" data-gericht-tausch-suche />
                @if($tauschKandidaten->isNotEmpty())
                    <div class="flex flex-col gap-0.5">
                        @foreach($tauschKandidaten as $k)
                            <a href="#" role="button" wire:key="gv-kand-{{ $k->id }}" wire:click.prevent="gerichtTauschVorbereiten({{ $k->id }})"
                                    class="w-full text-left px-2 py-1.5 rounded-[var(--fa-radius-control)] fa-t-body hover:bg-[var(--fa-hover)] flex items-center gap-2" data-gericht-tausch-kandidat>
                                <x-fa::badge :tone="$statusTon[$k->status->value] ?? 'neutral'" class="shrink-0">{{ $k->status->label() }}</x-fa::badge>
                                <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                                @svg('heroicon-m-arrows-right-left', 'w-4 h-4 shrink-0 fa-t-muted')
                            </a>
                        @endforeach
                    </div>
                @elseif(trim($tauschSuche) !== '')
                    <p class="fa-t-muted">Kein passendes Gericht gefunden.</p>
                @endif
            </div>
        @else
            <div class="flex flex-col gap-2 p-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)]" data-gericht-vorschau>
                <p class="{{ $tt }} fa-t-strong">„{{ $vs['von']['name'] }}“ ersetzen durch „{{ $vs['nach']['name'] }}“</p>
                @if($vs['ek_delta_je_portion'] !== null)
                    <p class="fa-t-muted">EK je Portion {{ $vs['ek_delta_je_portion'] >= 0 ? '+' : '' }}{{ number_format((float) $vs['ek_delta_je_portion'], 2, ',', '.') }} € (Standard-Darreichung, Schätzung je Position).</p>
                @endif
                @foreach($gv['bereiche'] as $b)
                    @php $info = $vs['bereiche'][$b]; @endphp
                    @if($info['anzahl'] > 0 || $info['bleibt'] !== [] || $info['haken'] !== [])
                        <div class="flex flex-col gap-0.5" wire:key="gv-bereich-{{ $b }}" data-gericht-bereich="{{ $b }}">
                            <x-fa::checkbox wire:model.live="tauschBereiche" value="{{ $b }}">{{ $bereichText[$b] }}: {{ $info['anzahl'] }}</x-fa::checkbox>
                            @foreach(array_slice($info['eintraege'], 0, 5) as $i => $e)
                                <p class="fa-t-muted pl-6" wire:key="gv-{{ $b }}-{{ $i }}">@include('foodalchemist::livewire.recipes.partials.verwaltung-ort', ['ort' => $e['ort'], 'ziel' => $e['ziel'] ?? null])@if($e['hinweis']) · <span data-gericht-hinweis>{{ $e['hinweis'] }}</span>@endif</p>
                            @endforeach
                            @if(count($info['eintraege']) > 5)<p class="fa-t-muted pl-6">… und {{ count($info['eintraege']) - 5 }} weitere</p>@endif
                            @foreach($info['bleibt'] as $i => $bl)
                                <p class="fa-t-muted pl-6" wire:key="gv-{{ $b }}-bleibt-{{ $i }}">Bleibt: @include('foodalchemist::livewire.recipes.partials.verwaltung-ort', ['ort' => $bl['ort'], 'ziel' => $bl['ziel'] ?? null])@if($bl['grund']) ({{ $bl['grund'] }})@endif</p>
                            @endforeach
                            @if($b === 'angebote')
                                @foreach($info['preise'] ?? [] as $p)
                                    <p class="fa-t-body pl-6" wire:key="gv-preis-{{ $p['id'] }}" data-gericht-angebotspreis>„{{ $p['name'] }}“: @if($p['preis_manuell'] ?? false)Preis manuell – bitte prüfen (Kalkulation {{ $geld($p['preis_vorher']) }} → {{ $geld($p['preis_nachher']) }})@else{{ $geld($p['preis_vorher']) }} → {{ $geld($p['preis_nachher']) }}@endif</p>
                                @endforeach
                            @endif
                        </div>
                    @endif
                @endforeach
                @if($vs['haken_noetig'])
                    <x-fa::checkbox wire:model.live="tauschAuchAktiveFoodbooks" hinweis="Live-Inhalt beim Kunden ändert sich; eingefrorene Snapshots bleiben." data-gericht-haken>Auch aktive Foodbooks</x-fa::checkbox>
                @endif
                <div class="flex flex-wrap gap-2 pt-1">
                    <x-fa::button :size="$kompakt ? 'sm' : 'md'" icon="heroicon-m-arrows-right-left" wire:click="gerichtErsetzen"
                        wire:confirm="„{{ $vs['von']['name'] }}“ in den gewählten Bereichen durch „{{ $vs['nach']['name'] }}“ ersetzen? Danach greifen die üblichen Neuberechnungen." data-gericht-ersetzen>Ersetzen</x-fa::button>
                    <x-fa::button :size="$kompakt ? 'sm' : 'md'" variant="ghost" wire:click="gerichtTauschAbbrechen">Abbrechen</x-fa::button>
                </div>
            </div>
        @endif

        {{-- 3. Löschen: frei, sobald keine aktive Verwendung mehr besteht (weich; historische Verweise bleiben lesbar) --}}
        @if($gv['loesch']['eigen'])
            <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-1.5" data-gericht-loeschen-block>
                @if($gv['loesch']['frei'])
                    <div>
                        <x-fa::button :size="$kompakt ? 'sm' : 'md'" variant="danger" icon="heroicon-m-trash" wire:click="rezeptLoeschen"
                            wire:confirm="„{{ $rezeptName }}“ löschen? Es verschwindet aus den Listen; historische Einträge zeigen es weiter als Verweis." data-gericht-loeschen>Gericht löschen</x-fa::button>
                    </div>
                    <p class="fa-t-muted">Keine aktive Verwendung mehr.@if($gv['loesch']['historisch'] > 0) Bleibt in {{ $mehrzahl((int) $gv['loesch']['historisch'], 'historischem Eintrag', 'historischen Einträgen') }} als Verweis sichtbar.@endif</p>
                @else
                    <p class="fa-t-body" data-gericht-loeschsperre><x-fa::signal tone="warn">Löschen nicht möglich</x-fa::signal> Erst oben ersetzen — die aktiven Verwendungen stehen in der Liste.</p>
                @endif
            </div>
        @endif
    @else
    {{-- 1. Wo hängt das Rezept? MIT Namen (2026-09-04, Dominique: „es wird nicht angezeigt
         wo es drin ist") — eine Menge ohne Adresse hilft beim Umhängen nicht weiter. --}}
    @if($tauschBilanz !== null && ($tauschBilanz['zeilen'] > 0 || $tauschBilanz['fremd_zeilen'] > 0))
        <div class="flex flex-col gap-1.5">
            <p class="{{ $tt }} text-[var(--fa-ink)]" data-rezept-tausch-bilanz>
                Als Komponente eingesetzt: {{ $mehrzahl((int) $tauschBilanz['zeilen'], 'Zeile', 'Zeilen') }} in {{ $mehrzahl((int) $tauschBilanz['rezepte'], 'eigenem Rezept', 'eigenen Rezepten') }}.
                @if($tauschBilanz['fremd_zeilen'] > 0)<span class="text-[var(--fa-ink-2)]">{{ $mehrzahl((int) $tauschBilanz['fremd_rezepte'], 'geerbtes Rezept bleibt', 'geerbte Rezepte bleiben') }} unberührt, weil sie nur gelesen werden können.</span>@endif
            </p>
            @if(($tauschBilanz['eltern_namen'] ?? []) !== [])
                <ul class="{{ $tt }} flex flex-col gap-1" data-rezept-tausch-eltern>
                    @foreach($tauschBilanz['eltern_namen'] as $e)
                        <li class="flex items-center gap-2 min-w-0" wire:key="rvw-eltern-{{ $e['id'] }}">
                            <x-fa::badge :tone="$e['ist_gericht'] ? 'info' : 'neutral'" class="shrink-0">{{ $e['ist_gericht'] ? 'Gericht' : 'Basisrezept' }}</x-fa::badge>
                            <span class="min-w-0 truncate text-[var(--fa-ink)]">{{ $e['name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if(($tauschBilanz['fremd_namen'] ?? []) !== [])
                <p class="fa-t-muted" data-rezept-tausch-fremd>Geerbt und unberührt: {{ implode(' · ', array_column($tauschBilanz['fremd_namen'], 'name')) }}</p>
            @endif
        </div>
    @endif

    {{-- 2. Tauschen — der Ausweg aus einer blockierten Löschung --}}
    @if($tauschBilanz !== null && $tauschBilanz['zeilen'] > 0)
        <div class="flex flex-col gap-1.5" data-rezept-tausch>
            <label for="rezept-tausch-suche-{{ $kompakt ? 'panel' : 'editor' }}" class="fa-t-label">
                In allen Verwendungen ersetzen durch <span class="font-normal text-[var(--fa-ink-3)]">(Menge und Einheit bleiben)</span>
            </label>
            <x-fa::input type="search" id="rezept-tausch-suche-{{ $kompakt ? 'panel' : 'editor' }}" wire:model.live.debounce.300ms="tauschSuche"
                placeholder="Ersatz-Rezept suchen …" :size="$kompakt ? 'sm' : 'md'" data-rezept-tausch-suche />
            @if($tauschKandidaten->isNotEmpty())
                <div class="flex flex-col gap-0.5">
                    @foreach($tauschKandidaten as $k)
                        <button type="button" wire:key="rvw-tausch-{{ $k->id }}" wire:click="rezeptErsetzen({{ $k->id }})"
                                wire:confirm="„{{ $rezeptName }}“ in {{ $mehrzahl((int) $tauschBilanz['rezepte'], 'Rezept', 'Rezepten') }} durch „{{ $k->name }}“ ersetzen? Menge und Einheit der Zeilen bleiben stehen, die Rezepte werden neu berechnet."
                                class="w-full text-left px-2 py-1.5 rounded-[var(--fa-radius-control)] {{ $tt }} text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] flex items-center gap-2" data-rezept-tausch-kandidat>
                            <x-fa::badge :tone="$statusTon[$k->status->value] ?? 'neutral'" class="shrink-0">{{ $k->status->label() }}</x-fa::badge>
                            @if($k->is_sales_recipe)<x-fa::badge tone="info" class="shrink-0">Gericht</x-fa::badge>@endif
                            <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                            @svg('heroicon-m-arrows-right-left', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                        </button>
                    @endforeach
                </div>
            @elseif(trim($tauschSuche) !== '')
                <p class="fa-t-muted">Kein passendes Rezept gefunden.</p>
            @endif
        </div>
    @endif

    {{-- 3. Löschen — nur für eigene Basisrezepte; $tauschReferenzen ist sonst null --}}
    @if($tauschReferenzen !== null)
        <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-1.5" data-rezept-loeschen-block>
            @if($tauschReferenzen['blocker'] === 0)
                <div>
                    <x-fa::button :size="$kompakt ? 'sm' : 'md'" variant="danger" icon="heroicon-m-trash" wire:click="rezeptLoeschen"
                        wire:confirm="„{{ $rezeptName }}“ löschen? Nichts verweist darauf, das Rezept verschwindet aus den Listen." data-rezept-loeschen>Rezept löschen</x-fa::button>
                </div>
                <p class="fa-t-muted">Nichts verweist auf dieses Rezept. Gelöschte Rezepte lassen sich wiederherstellen.</p>
            @else
                <p class="{{ $tt }} text-[var(--fa-ink)]" data-rezept-ref-zusammenfassung>
                    <x-fa::signal tone="warn">Löschen nicht möglich</x-fa::signal>
                    Wird verwendet: {{ implode(' · ', $tauschReferenzen['blocker_teile']) }}.@if($tauschBilanz !== null && $tauschBilanz['zeilen'] > 0)<span> Erst oben umhängen, dann löschen.</span>@endif
                </p>
                @if(($tauschReferenzen['eltern_namen'] ?? []) !== [])
                    {{-- Adresse statt Menge: die Eltern-Rezepte, die das Löschen blockieren.
                         Hier stehen ALLE (auch geerbte) — sie blockieren ebenfalls, tauchen aber
                         in der Tausch-Bilanz oben bewusst nur als „unberührt" auf. --}}
                    <p class="fa-t-muted" data-rezept-ref-eltern>Verwendet in: {{ implode(' · ', array_column($tauschReferenzen['eltern_namen'], 'name')) }}</p>
                @endif
            @endif
            @php
                $refInfo = array_filter([
                    $tauschReferenzen['produktion_historie'] > 0 ? $mehrzahl((int) $tauschReferenzen['produktion_historie'], 'Zeile', 'Zeilen') . ' in abgeschlossenen Produktionsaufträgen' : null,
                    $tauschReferenzen['instanzen'] > 0 ? $mehrzahl((int) $tauschReferenzen['instanzen'], 'daraus abgeleitetes Rezept', 'daraus abgeleitete Rezepte') : null,
                ]);
            @endphp
            @if($refInfo !== [])
                <p class="fa-t-muted" data-rezept-ref-info>Nur zur Info, blockiert nicht: {{ implode(' · ', $refInfo) }}</p>
            @endif
        </div>
    @endif
    @endif
</div>
