{{-- Spec 18 — Produktion: Editor-Modal (Stammdaten / Ziele / Vorschau / Positionen / Status und Einkauf).
     fa-pass (2026-10-05): Werkbank-Modus (darkCanvas → data-fa-theme="dark") auf Bausteinen <x-fa::…>.
     Anatomie: Kopf = Titel + Name + Status · rechts „Weitere Aktionen" (Produktionsschein, Löschen ganz
     unten, rot) · Speichern als einzige Hauptaktion. Kennzahlen fix im Kopf, Ansätze als Hauptzahl.
     Reiter in der bisherigen Reihenfolge. Tabellen scrollen waagerecht (Laptop), nichts wird abgeschnitten.
     Alle wire:-Bindungen, Event-Namen und data-Marker unverändert. --}}
@php
    $pzRezepte = $vorschau['rezepte'] ?? [];
    $pzAnsaetze = collect($pzRezepte)->sum('ansaetze');
    $pzZeit = collect($pzRezepte)->sum(fn ($r) => (int) ($r['arbeitszeit_min'] ?? 0));
    $pzOhneZeit = collect($pzRezepte)->filter(fn ($r) => ($r['arbeitszeit_min'] ?? null) === null)->count();

    // Gemeinsame Helfer für die Reiter-Partials (werden per @include vererbt).
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'success' => 'ok', 'danger' => 'crit', 'warning' => 'warn', 'primary' => 'accent'];
    $menge = fn ($wert, int $stellen = 2) => rtrim(rtrim(number_format((float) $wert, $stellen, ',', '.'), '0'), ',') ?: '0';
    $opsStatus = $ops !== null ? \Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($ops['status']) : null;
    $darfLoeschen = $ops !== null && $ops['is_owned'] && in_array($ops['status'], ['planned', 'cancelled'], true);
    $hatDokument = $ops !== null && \Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument');
    // Spec 65: Lesemodus (keine eigene Sperre) — Eingaben/Knöpfe der Reiter sind aus, Löschen erst nach „Bearbeiten".
    $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $darfLoeschen = $darfLoeschen && ! $sperrLesen;
@endphp

{{-- Spec 29-Rollout: Produktion-Editor auf Editor-Page-Muster (fullscreen · dark · editor-tabs · KPI). --}}
<div>
<x-fa::modal name="produktion-editor" fullscreen dark-canvas
    :title="$orderId === null ? 'Neuer Produktionsauftrag' : 'Produktionsauftrag'"
    :title-name="$orderId === null ? null : ($name ?: null)">

    @if($opsStatus !== null)
        <x-slot:titleExtra>
            <x-fa::badge :tone="$statusTon[$opsStatus->badgeVariant()] ?? 'neutral'">{{ ucfirst($opsStatus->label()) }}</x-fa::badge>
        </x-slot:titleExtra>
    @endif

    <x-slot:actions>
        @if($fehler)<x-fa::signal tone="crit" class="min-w-0" data-produktion-fehler>{{ $fehler }}</x-fa::signal>@endif
        <div class="ml-auto flex flex-wrap items-center gap-2">
            {{-- Spec 76: Etiketten für alle Basisrezepte des Auftrags (Sammeldruck); je Zeile gibt es zusätzlich ein Einzel-Etikett --}}
            @if($etikettenVorschlag !== [])
                <x-fa::button icon="heroicon-m-tag" x-on:click="$dispatch('modal.open', { name: 'produktion-etiketten' })" data-produktion-etiketten>Etiketten</x-fa::button>
            @endif
            @if($hatDokument || $darfLoeschen || $etikettenVorschlag !== [])
                {{-- Weitere Aktionen: Produktionsschein · Löschen (ganz unten, rot — nie neben Speichern) --}}
                <x-fa::menu breite="64">
                    @if($hatDokument)
                        <x-fa::menu-item icon="heroicon-o-printer" :href="route('foodalchemist.produktion.auftraege.dokument', ['order' => $ops['id']])" abstand="weit" target="_blank" title="Produktionsschein mit Einkauf, zum Drucken oder als PDF">Produktionsschein drucken</x-fa::menu-item>
                    @endif
                    {{-- Spec 76: Etiketten auch im Menü auffindbar (Sammeldruck aller Basisrezepte) --}}
                    @if($etikettenVorschlag !== [])
                        <x-fa::menu-item icon="heroicon-o-tag" abstand="weit" x-on:click="$dispatch('modal.open', { name: 'produktion-etiketten' })" data-produktion-etiketten-menue>Etiketten drucken</x-fa::menu-item>
                    @endif
                    {{-- Spec 30 E7: Löschen nur geplant/storniert — ein laufender Auftrag wird storniert,
                         ein fertiger ist Protokoll. --}}
                    @if($darfLoeschen)
                        @if($hatDokument)<div class="my-1 border-t border-[var(--fa-line)]"></div>@endif
                        <x-fa::menu-item icon="heroicon-o-trash" danger :trenner="false" abstand="weit" wire:click="auftragLoeschen" wire:confirm="Produktionsauftrag samt Positionen löschen?" data-produktion-loeschen>Auftrag löschen</x-fa::menu-item>
                    @endif
                </x-fa::menu>
            @endif
            {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung --}}
            <x-fa::bearbeiten-leiste :zustand="$sperr">
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" wire:loading.attr="disabled" wire:target="speichern" data-produktion-speichern>{{ $orderId === null ? 'Auftrag anlegen' : 'Speichern' }}</x-fa::button>
            </x-fa::bearbeiten-leiste>
        </div>
    </x-slot:actions>

    {{-- Kennzahlen fix im Kopf: Ziele · Rezepte · Ansätze (Hauptzahl) · Arbeitszeit aus der Vorschau --}}
    <x-slot:kpiHeader>
        <x-fa::kpis data-produktion-kpis :items="[
            ['kpi' => 'ziele', 'label' => 'Ziele', 'value' => count($targets) > 0 ? (string) count($targets) : 'Keine', 'tone' => count($targets) === 0 ? 'warn' : null],
            ['kpi' => 'rezepte', 'label' => 'Rezepte', 'value' => (string) count($pzRezepte)],
            ['kpi' => 'ansaetze', 'label' => 'Ansätze', 'primary' => $pzAnsaetze > 0, 'value' => $pzAnsaetze > 0 ? $menge($pzAnsaetze) : '–'],
            ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $pzZeit > 0 ? number_format($pzZeit, 0, ',', '.') . ' min' : '–',
             'hint' => $pzOhneZeit > 0 && $pzZeit > 0 ? 'unvollständig' : null,
             'hint_title' => $pzOhneZeit > 0 ? $pzOhneZeit . ' Rezept(e) ohne Arbeitszeit' : null],
        ]" />
    </x-slot:kpiHeader>

    <x-fa::editor-tabs marker="produktion" wire-key="produktion-tabs-{{ $orderId ?? 'neu' }}" :init="'stammdaten'" :gesperrt="$sperrLesen"
        :tabs="['stammdaten' => 'Stammdaten', 'ziele' => 'Ziele', 'vorschau' => 'Vorschau', 'zeilen' => $orderId ? 'Positionen' : null, 'einkauf' => $orderId ? 'Status und Einkauf' : null]">

    @include('foodalchemist::livewire.produktion.partials.editor-stammdaten')

    @include('foodalchemist::livewire.produktion.partials.editor-ziele')

    @include('foodalchemist::livewire.produktion.partials.editor-vorschau')

    {{-- ── Reiter POSITIONEN (Spec 30 E2) — der Auftrag als Arbeitsdokument ──────────
         Die Vorschau zeigt, was gerechnet WURDE. Hier greift der Mensch ein: Ansätze
         überschreiben, Zeilen streichen, freie Positionen ergänzen. Was hier gesetzt wird,
         überlebt jede Neuberechnung (Overlay), die berechnete Zahl bleibt als Referenz stehen. --}}
    @include('foodalchemist::livewire.produktion.partials.editor-zeilen')

    {{-- ═══ Reiter STATUS UND EINKAUF (aus DetailPanel gemergt — nur bestehender Auftrag) ═══ --}}
    @include('foodalchemist::livewire.produktion.partials.editor-einkauf')
    </x-fa::editor-tabs>
</x-fa::modal>
@if($etikettenVorschlag !== [])
<x-fa::modal name="produktion-etiketten" title="Etiketten für diesen Auftrag" size="max-w-3xl">
    <form method="GET" action="{{ route('foodalchemist.etiketten.produktion', ['order' => $ops['id']]) }}" target="_blank" class="flex flex-col gap-3" data-produktion-etiketten-form>
        <p class="fa-t-muted">Alle Basisrezepte des Auftrags, je Ansatz ein Etikett mit der Menge eines Ansatzes. Hergestellt am = Produktionstag, Lagerart = Standard des Rezepts. Haken raus oder Anzahl ändern, dann drucken.</p>
        <x-fa::table compact>
            <thead><tr><th></th><th>Basisrezept</th><th class="text-right">Anzahl</th><th>Menge je Etikett</th></tr></thead>
            <tbody>
                @foreach($etikettenVorschlag as $ev)
                    <tr wire:key="pe-{{ $ev['line_id'] }}">
                        <td><x-fa::checkbox name="zeilen[{{ $ev['line_id'] }}][an]" value="1" checked aria-label="{{ $ev['name'] }} drucken" /></td>
                        <td class="font-medium">{{ $ev['name'] }}</td>
                        <td class="text-right"><input type="number" min="1" max="200" name="zeilen[{{ $ev['line_id'] }}][anzahl]" value="{{ $ev['vorschlag']['anzahl'] }}" class="fa-control h-7 w-16 text-right tabular-nums text-[length:var(--fa-text-sm)]" aria-label="Anzahl" /></td>
                        <td><input type="text" name="zeilen[{{ $ev['line_id'] }}][menge]" value="{{ $ev['vorschlag']['menge'] }}" placeholder="leer = Schreiblinie" class="fa-control h-7 w-32 text-[length:var(--fa-text-sm)]" aria-label="Menge je Etikett" /></td>
                    </tr>
                @endforeach
            </tbody>
        </x-fa::table>
        <div class="flex flex-wrap items-end gap-2">
            <x-fa::field label="Vorlage" for="pe-vorlage"><x-fa::select id="pe-vorlage" name="vorlage" size="sm" :options="$etikettVorlagen" /></x-fa::field>
            <x-fa::button type="submit" variant="primary" icon="heroicon-m-printer">Etiketten drucken</x-fa::button>
        </div>
    </form>
</x-fa::modal>
@endif
</div>
