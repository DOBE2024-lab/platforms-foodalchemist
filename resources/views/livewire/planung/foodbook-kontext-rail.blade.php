{{-- Spec-42-Vollzug S3a — Buch-Ebenen-Planung (Leitplanken + Briefing + Leitidee-Canvas) in der
     Leitstelle. Markup portiert aus dem Foodbook-Kontext-Tab. Blade-Regeln: heroicons inline, keine
     Direktiven-Tokens in Kommentaren, wire:key.
     fa-pass: Bausteine (Tokens, hell + Werkbank), beschriftete Felder, KI-Vorschlag als eigene Fläche
     mit klarer Übernehmen-Aktion. Bindings und Marker unverändert. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
@endphp
<div class="flex flex-col gap-4" data-planung-fbkontext>
    @if(! $fb)
        <x-fa::empty compact icon="heroicon-o-book-open" title="Noch kein Foodbook gewählt">Erst ein Foodbook wählen oder aus einem Brief erstellen.</x-fa::empty>
    @else
        {{-- Leitplanken (Schreibstil · Kundentyp · Niveau): steuern die Erstellung. --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3" wire:key="fbkontext-{{ $fb->id }}" data-fbkontext-leitplanken>
            <x-fa::field label="Schreibstil">
                <x-fa::select wire:change="tonalitaetSetzen($event.target.value)" data-fbkontext-schreibstil>
                    <option value="">Keine eigene Vorgabe</option>
                    @foreach($schreibstile as $s)
                        <option value="{{ $s->id }}" @selected((int) ($fb->writing_style_id ?? 0) === (int) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Kundentyp">
                <x-fa::select wire:change="leitplankeSetzen('kundentyp', $event.target.value)" data-fbkontext-kundentyp>
                    <option value="">Keine eigene Vorgabe</option>
                    @foreach($kundentypen as $wert => $lbl)
                        <option value="{{ $wert }}" @selected(($fb->kundentyp ?? '') === $wert)>{{ $lbl }}</option>
                    @endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Niveau">
                <x-fa::select wire:change="leitplankeSetzen('default_niveau', $event.target.value)" data-fbkontext-niveau>
                    <option value="">Wie im Segment</option>
                    @foreach($niveauLabels as $wert => $lbl)
                        <option value="{{ $wert }}" @selected(($fb->default_niveau ?? '') === $wert)>{{ $lbl }}</option>
                    @endforeach
                </x-fa::select>
            </x-fa::field>
        </div>

        {{-- Einleitung (Kundentext): wird beim Verlassen des Feldes gespeichert; der KI-Vorschlag landet in der Vorschau. --}}
        <div class="flex flex-col gap-1.5" data-fbkontext-briefing>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <label for="fbkontext-einleitung-{{ $fb->id }}" class="fa-t-label">Einleitung für den Kunden</label>
                <x-fa::ki-action action="kiEinleitung" variant="ai" icon="heroicon-o-sparkles" label="Einleitung vorschlagen"
                        busy="Text wird geschrieben …" data-fbkontext-ki />
            </div>
            <x-fa::textarea id="fbkontext-einleitung-{{ $fb->id }}" wire:model.blur="beschreibung" rows="3" class="min-h-[4.5rem]"
                      placeholder="Begrüßung und Einleitung, wie sie der Kunde im Foodbook liest" />
            <p class="fa-t-muted">Wird beim Verlassen des Feldes gespeichert.</p>

            @if($kiVorschau !== null)
                <div class="mt-1 flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2.5" data-fbkontext-ki-vorschau>
                    <p class="text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-accent)]">Vorschlag der KI, noch nicht übernommen{{ $kiConfidence !== null ? ' · Sicherheit '.number_format($kiConfidence * 100, 0).' %' : '' }}</p>
                    <p class="fa-t-body whitespace-pre-line">{{ $kiVorschau }}</p>
                    <div class="flex items-center gap-2">
                        <x-fa::button variant="primary" size="sm" wire:click="kiUebernehmen" data-fbkontext-ki-uebernehmen>{{ trim($beschreibung) !== '' ? 'Text ersetzen' : 'Text übernehmen' }}</x-fa::button>
                        <x-fa::button variant="ghost" size="sm" wire:click="kiVerwerfen">Vorschlag verwerfen</x-fa::button>
                    </div>
                </div>
            @endif
            @if($kiHinweis !== null)<x-fa::signal tone="warn" data-fbkontext-ki-hinweis>{{ $kiHinweis }}</x-fa::signal>@endif
        </div>

        {{-- Foodbook-Leitidee (Canvas), inline, owner=foodbook (ManagesCanvas). --}}
        <div class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-4" wire:key="fbcanvas-{{ $fb->id }}" data-fbkontext-canvas>
            <p class="mb-1 fa-t-h3">Leitidee</p>
            <p class="mb-3 fa-t-muted">Was muss hinein, welche Konzepte gehören dazu, was muss das Foodbook leisten.</p>
            @include('foodalchemist::livewire.canvas.partials.board')
        </div>
    @endif
</div>
