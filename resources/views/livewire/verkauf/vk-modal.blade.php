{{-- Gericht-Editor (VK-Editor, M6-04 · D-6 §4.2–4.5) — EIN Voll-Editor im Werkbank-Modus
     (darkCanvas → data-fa-theme="dark"). fa-pass: nur --fa-*-Tokens und x-fa-Bausteine, damit hell
     UND dunkel stimmen.

     Anatomie: Kopf = «Gericht» + Name + Status · rechts KI-Assistent (Menü) · Weitere Aktionen
     (Menü, Löschen ganz unten) · Speichern (die eine Hauptaktion). Kennzahlen fix im Kopf.

     Hauptzahl = VK netto. Begründung: ein Gericht ist das, was verkauft wird — der Preis ist die
     Zahl, mit der Küche und Vertrieb arbeiten (Angebot, Speisekarte, Concepter). Der Wareneinsatz
     ist das Urteil ÜBER diesen Preis und trägt deshalb die Ampelfarbe (Zustand), nie den Akzent.

     Häufigste Arbeit: Komponenten und Mengen → Reiter «Aufbau» zuerst, direkt dahinter
     «Kalkulation» (Preisklasse, Verkaufseinheit, Darreichungen in EINEM Reiter, weil der Preis je
     Darreichung entsteht). Danach Stammdaten und die drei Anleitungs-Ebenen in Prozess-Reihenfolge
     (regenerieren → fertigstellen → anrichten, Regelwerk Verkaufsgerichte §3). --}}
@php
    $euro = fn ($wert) => $wert === null ? null : number_format((float) $wert, 2, ',', '.') . ' €';
    $prozent = fn ($wert, int $dez = 1) => $wert === null ? null : number_format((float) $wert, $dez, ',', '.') . ' %';
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $feld = 'fa-control h-9 text-[length:var(--fa-text-md)]';
    $feldKlein = 'fa-control h-7 text-[length:var(--fa-text-sm)]';
    $knopfKi = 'inline-flex items-center gap-1.5 h-7 px-2.5 whitespace-nowrap rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border border-[var(--fa-accent-line)] hover:bg-[var(--fa-accent-soft-hover)] transition-colors duration-150';
    $rollenText = \Platform\FoodAlchemist\Support\TellerRolle::labels();
    $diaetText = ['fleisch' => 'Fleisch', 'fisch' => 'Fisch', 'vegi' => 'vegetarisch', 'vegetarisch' => 'vegetarisch', 'vegan' => 'vegan', 'neutral' => 'neutral', 'omnivor' => 'omnivor', 'allergie' => 'Allergie'];
    $zweckText = ['abfuellen' => 'Abfüllen', 'regenerieren' => 'Regenerieren', 'ausgabe' => 'Ausgabe', 'transport' => 'Transport'];
    $herkunftText = [
        'override' => ['abweichend', 'info', 'An diesem Gericht bewusst abweichend'],
        'geerbt' => ['vom Basisrezept', 'neutral', null],
        'regel' => ['aus Regel', 'neutral', 'Aus dem Zustand des Grundprodukts abgeleitet'],
        'fehlt' => ['fehlt', 'warn', 'Weder am Basisrezept noch hier gepflegt'],
    ];
    $angelegtText = ['fa_ui' => 'im Editor angelegt', 'mcp' => 'vom Assistenten angelegt', 'one_shot' => 'aus der Anreicherung', 'import' => 'importiert', 'pricing_v2_migration' => 'aus der Preis-Übernahme'];
    $preisQuelleText = ['kostenstruktur' => 'aus der Kostenstruktur', 'ziel_we_fallback' => 'aus dem Ziel-Wareneinsatz'];
    $regenText = fn (array $z) => implode(' · ', array_filter([
        $z['device'] ?? 'kalt servieren',
        $z['temp_c'] !== null ? $z['temp_c'] . ' °C' : null,
        $z['duration_min'] !== null ? $z['duration_min'] . ' min' : null,
        $z['core_temp_c'] !== null ? 'Kerntemperatur ' . $z['core_temp_c'] . ' °C' : null,
        $z['note'] ?: null,
    ]));
@endphp

{{-- R5 (Dominique): Gericht-Editor nimmt wie der Basis-Editor den ganzen Bildschirm --}}
<x-fa::modal name="vk-modal" title="{{ $rezept !== null ? 'Gericht' : 'Neues Gericht' }}"
    :title-name="$rezept?->name" size="max-w-3xl" :fullscreen="$rezept !== null" :dark-canvas="true">

    @if($rezept !== null)
        <x-slot:titleExtra>
            <x-fa::status :value="$rezept->status ?? 'draft'" />
        </x-slot:titleExtra>
    @endif

    <x-slot:actions>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if($rezept !== null)
                {{-- KI-Assistent: die gerichtweiten KI-Funktionen in EINEM Menü. Feld- und
                     Abschnitts-KI (Wording, Regeneration, Plating …) bleibt am jeweiligen Abschnitt. --}}
                <x-fa::menu breite="72">
                    <x-slot:ausloeser>
                        <x-fa::button variant="ai" icon="heroicon-m-sparkles" icon-right="heroicon-m-chevron-down"
                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" data-vk-ki-assistent>KI-Assistent</x-fa::button>
                    </x-slot:ausloeser>
                    {{-- „Rezeptur erstellen" für ein Gericht im Entwurf ohne Komponenten (eigene Komponente, öffnet die Planung). --}}
                    <livewire:foodalchemist.verkauf.rezeptur-erstellen :recipe-id="$rezept->id" :key="'vk-rezeptur-'.$rezept->id" />
                    {{-- Spec 03 L1b: Alles anreichern — ki-action, damit Fortschritt und Erfolg im Menü sichtbar sind. --}}
                    <div class="px-3 py-2 flex flex-col gap-1">
                        <x-fa::ki-action action="allesAnreichern" variant="ai" icon="heroicon-o-sparkles" label="Alles anreichern"
                            title="Verkaufstext, Eigenschaften, Produktionsplanung, Equipment, Schritte, Aromen, Pairings, Eignung, Sensorik, Wirtschaftlichkeit und Stimmigkeit in einem Lauf. KI-Fotos laufen separat, Ersatz bleibt Handarbeit."
                            class="w-full justify-center" data-vk-alles-anreichern busy="Wird gestartet …" flash="Gestartet" />
                        <span class="{{ $hinweis }}">Füllt alle leeren Felder in einem Lauf. Von Hand Gepflegtes bleibt stehen.</span>
                    </div>
                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                    {{-- Spec 03 L1a: freie Anweisung → Vorschau → Übernehmen --}}
                    <x-fa::menu-item icon="heroicon-o-pencil-square" abstand="weit" wire:click="$toggle('ueberarbeitenOffen')" title="Freie Anweisung: die KI überarbeitet Komponenten, Mengen, Beschreibung, Anrichten und Verkaufstext. Speisen-Klasse, Diät, Darreichungen und Verkaufseinheit bleiben unangetastet." data-vk-ki-ueberarbeiten>Mit Anweisung überarbeiten</x-fa::menu-item>
                    {{-- Spec 03 L6b: Copilot — Prüf-Pass statt Neu-Schreiben (Befunde einzeln annehmen) --}}
                    <x-fa::menu-item icon="heroicon-o-clipboard-document-check" abstand="weit" wire:click="$toggle('copilotOffen')" title="Die KI prüft Mengen, Einheiten, überflüssige und fehlende Komponenten am Maßstab des Verkaufs. Jeder Befund lässt sich einzeln übernehmen." data-vk-copilot>Gericht prüfen lassen</x-fa::menu-item>
                </x-fa::menu>

                {{-- Weitere Aktionen: Komponenten-Editor · Drucken · Löschen (ganz unten, rot — nie neben Speichern) --}}
                <x-fa::menu breite="64">
                    <x-fa::menu-item icon="heroicon-o-arrows-pointing-out" abstand="weit" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" data-vk-zutaten>Komponenten bearbeiten</x-fa::menu-item>
                    <x-fa::menu-item icon="heroicon-o-printer" :href="route('foodalchemist.rezepte.dokument', ['id' => $rezept->id, 'profil' => 'produktion'])" abstand="weit" target="_blank" title="Druck- und PDF-Bericht mit Profilen und Filtern" data-vk-druck>Gericht drucken</x-fa::menu-item>
                    <x-fa::menu-item icon="heroicon-o-tag" :href="route('foodalchemist.etiketten.index', ['quelle' => 'recipe', 'id' => $rezept->id])" abstand="weit" target="_blank" data-vk-etikett>Etikett drucken</x-fa::menu-item>
                    {{-- Spec 82 §13 B6: Gericht samt Komponenten als Fassung an die Abonnenten geben (Bedingung an EINER Stelle: FassungMenue) --}}
                    @if(\Platform\FoodAlchemist\Support\FassungMenue::sichtbar(auth()->user()?->currentTeamRelation, $rezept))
                        <x-fa::menu-item icon="heroicon-o-paper-airplane" abstand="weit" x-on:click="$dispatch('fassung-modal.oeffnen', { recipeId: {{ (int) $rezept->id }} })" title="Stand als neue Fassung an die Abonnenten geben" data-fassung-menue>Veröffentlichen …</x-fa::menu-item>
                    @endif
                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                    <x-fa::menu-item icon="heroicon-o-trash" danger :trenner="false" abstand="weit" wire:click="loeschen" wire:confirm="Gericht wirklich löschen? Nur das Gericht wird entfernt, Basisrezepte und Grundprodukte bleiben bestehen." data-vk-loeschen>Gericht löschen</x-fa::menu-item>
                </x-fa::menu>

                {{-- #1b: EIN Speichern-Weg, sequenziert — erst die Gericht-Daten (`speichern`), dann bei
                     Erfolg adressiert das Zutaten-Speichern der eingebetteten Komponenten (MVP-046).
                     Der Editor meldet `zutaten-persistiert` zurück → beiZutatenPersistiert schließt. --}}
                {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung --}}
                <x-fa::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check"
                        x-on:click="$wire.speichern().then(() => { if (! $wire.fehler && $wire.recipeId) $dispatch('zutaten-speichern', { recipeId: $wire.recipeId }) })"
                        data-vk-speichern>Speichern</x-fa::button>
                </x-fa::bearbeiten-leiste>
            @else
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="anlegen" data-vk-anlegen>Gericht anlegen</x-fa::button>
            @endif
        </div>
    </x-slot:actions>

    {{-- Kennzahlen fix im Modal-Kopf (scrollen nie weg). EIN Hauptwert: VK netto (s. Kopf). Der
         Wareneinsatz ampelt gegen die Ziel-Quote des Teams (Spec 28 §6.1: grün ≤ Ziel · rot > Ziel × 1,5
         · sonst gelb, Leiter im MargeService). Ohne Ziel oder ohne VK bleibt er ungewertet, nie geraten. --}}
    @if($rezept !== null)
        @php
            $vkNetto = $cockpit['vk']['sales_net'] ?? null;
            $wePct = $cockpit['marge']['wareneinsatz_pct'] ?? null;
            $zielPct = $cockpit['ziel_pct'] ?? null;
            $weTon = ['gruen' => 'ok', 'gelb' => 'warn', 'rot' => 'crit'][$cockpit['ampel'] ?? ''] ?? null;
            $standardDar = $darreichungen->firstWhere('is_standard', true);
            $ekEinheit = $standardDar?->ek_portion;
            $nPreis = (int) ($rezept->ek_n_ingredients_priced ?? 0);
            $nZutat = (int) ($rezept->ek_n_ingredients_total ?? 0);
            [$konfText, $konfTon] = ['high' => ['hoch', 'ok'], 'medium' => ['mittel', 'warn'], 'low' => ['niedrig', 'crit']][$rezept->allergens_confidence ?? ''] ?? ['nicht bewertet', 'warn'];
            $kennzahlen = [
                ['kpi' => 'vk-netto', 'label' => 'VK netto', 'value' => $euro($vkNetto) ?? 'Preis fehlt', 'primary' => $vkNetto !== null, 'tone' => $vkNetto === null ? 'crit' : null,
                 'title' => 'Katalogpreis der Standard-Darreichung'],
                ['kpi' => 'wareneinsatz', 'label' => 'Wareneinsatz', 'value' => $prozent($wePct) ?? ($vkNetto === null ? 'ohne VK' : 'fehlt'), 'tone' => $weTon,
                 'title' => $zielPct !== null
                    ? 'Ziel des Teams: ' . number_format((float) $zielPct, 1, ',', '.') . ' %. Grün bis zum Ziel, rot ab dem 1,5-Fachen (Einstellungen, Herstellkosten).'
                    : 'Keine Ziel-Wareneinsatzquote hinterlegt, deshalb keine Ampel.'],
                $ekEinheit !== null
                    ? ['kpi' => 'ek', 'label' => 'EK je Einheit', 'value' => $euro($ekEinheit), 'title' => 'Wareneinsatz der Standard-Darreichung je Verkaufseinheit']
                    : ['kpi' => 'ek', 'label' => 'EK gesamt', 'value' => $euro($rezept->ek_total_eur) ?? 'Preis fehlt', 'tone' => $rezept->ek_total_eur === null ? 'crit' : null, 'title' => 'Wareneinsatz des ganzen Ansatzes'],
                ['kpi' => 'priced', 'label' => 'Preise', 'value' => $nZutat === 0 ? 'keine Komponenten' : $nPreis . ' von ' . $nZutat,
                 'tone' => $nZutat > 0 && $nPreis >= $nZutat ? 'ok' : 'warn', 'title' => 'Komponenten mit Preis von allen Komponenten'],
                ['kpi' => 'allergen', 'label' => 'Allergen-Konfidenz', 'value' => $konfText, 'tone' => $konfTon],
            ];
        @endphp
        <x-slot:kpiHeader>
            <p class="px-4 pt-2 {{ $hinweis }}" data-vk-standard-kontext>Verkaufs-Kennzahlen: {{ $standardDar?->name ?: $standardDar?->servingForm?->label }} · Standard-Darreichung</p>
            <x-fa::kpis :items="$kennzahlen" data-vk-editor-kpis />
        </x-slot:kpiHeader>
    @endif

    @if($fehler !== null)
        <x-fa::notice tone="crit" data-vk-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    {{-- Älterer Anreicherungslauf (falls noch offen); der Knopf nutzt inzwischen den Einzel-Lauf. --}}
    @if($bulkRun !== null)
        <div @if($bulkRun->laeuft()) wire:poll.2s @endif data-vk-anreichern-status>
            <x-fa::notice tone="info">
                @if($bulkRun->laeuft())
                    Anreicherung läuft …
                @else
                    <span>{{ $bulkOffen }} {{ $bulkOffen === 1 ? 'Vorschlag' : 'Vorschläge' }} offen</span>@if($bulkRun->failed > 0)<span>, {{ $bulkRun->failed }} Fehler</span>@endif
                @endif
                @if(! $bulkRun->laeuft())
                    <x-slot:actions>
                        <x-fa::button size="sm" icon="heroicon-m-check" wire:click="bulkAlleUebernehmen" data-vk-anreichern-uebernehmen>Alle übernehmen</x-fa::button>
                    </x-slot:actions>
                @endif
            </x-fa::notice>
        </div>
    @endif

    @if($anreicherungLaeuft)
        <p wire:poll.3s="pruefeAnreicherung" class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]" data-anreicherung-laeuft>
            @svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Anreicherung läuft im Hintergrund. Das Ergebnis erscheint hier, sobald alles durch ist …
        </p>
    @endif

    <x-fa::oneshot-ergebnis :anreicherung="$anreicherung" />

    @if($rezept === null)
        {{-- Anlage-Modus (DoD: Gericht aus Basisrezept manuell) — die Hauptaktion «Gericht anlegen» steht im Kopf. --}}
        <x-fa::section title="Gericht anlegen" icon="heroicon-o-plus-circle">
            <div class="flex flex-col gap-4" data-vk-anlage>
                <x-fa::field label="Name" for="vk-neu-name" required hint="Hauptkomponente zuerst, weitere Komponenten mit | trennen.">
                    <x-fa::input id="vk-neu-name" wire:model="neuName" placeholder="HG: Rinderfilet | Rotwein-Jus | Kartoffelgratin" data-vk-neu-name />
                </x-fa::field>
                <x-fa::field label="Basisrezept als erste Komponente" for="vk-basis-suche" optional
                    hint="Mit Basisrezept wird dessen ganze Charge die erste Komponente (Menge = Ertrag). Ohne entsteht ein leeres Gericht, die Komponenten kommen danach im Editor dazu.">
                    <x-fa::input id="vk-basis-suche" type="search" wire:model.live.debounce.300ms="basisSuche" placeholder="Basisrezept suchen …" data-vk-basis-suche />
                </x-fa::field>
                @if($basisTreffer->isNotEmpty())
                    <ul class="flex flex-col rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] divide-y divide-[var(--fa-line)] overflow-hidden" role="listbox">
                        @foreach($basisTreffer as $b)
                            <li>
                                <button type="button" wire:key="bt-{{ $b->id }}" wire:click="$set('basisId', {{ $b->id }})" role="option" aria-selected="{{ $basisId === $b->id ? 'true' : 'false' }}"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[length:var(--fa-text-md)] {{ $basisId === $b->id ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                                        data-vk-basis-treffer="{{ $b->id }}">
                                    <span class="min-w-0 truncate">{{ $b->name }}</span>
                                    <span class="shrink-0 flex items-center gap-3 {{ $hinweis }}">
                                        @if($b->yield_kg !== null)<x-fa::menge :value="$b->yield_kg" unit="kg" :decimals="2" />@endif
                                        <span>EK <x-fa::money :value="$b->ek_total_eur" /></span>
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-fa::section>
    @else
        {{-- KI-Überarbeiten (aus dem KI-Assistent): gerichtweit, deshalb über den Reitern — sichtbar,
             egal welcher Reiter gerade offen ist. --}}
        @if($ueberarbeitenOffen)
            <x-fa::section title="Mit Anweisung überarbeiten" icon="heroicon-o-pencil-square" data-vk-ueberarbeiten-box>
                <x-slot:actions>
                    <x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" wire:click="$toggle('ueberarbeitenOffen')" />
                </x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::input wire:model="anweisung" wire:keydown.enter="kiUeberarbeiten"
                        placeholder="z. B. «mach das Gericht vegan und ersetze die Sauce»" class="flex-1 min-w-[16rem]" data-vk-anweisung />
                    <x-fa::ki-action action="kiUeberarbeiten" variant="ai" icon="heroicon-o-sparkles" label="Vorschlag holen"
                        data-vk-ueberarbeiten-start busy="Denkt nach …" flash="Vorschlag da" />
                </div>
                @if($ueberarbeitung !== null)
                    <div class="flex flex-col gap-2 max-h-72 overflow-y-auto rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-3 py-2.5 text-[length:var(--fa-text-md)]" data-vk-ueberarbeiten-vorschau>
                        @if(is_string($ueberarbeitung['werte']['aenderungs_notiz'] ?? null))
                            <p class="font-medium text-[var(--fa-ink)]">{{ $ueberarbeitung['werte']['aenderungs_notiz'] }}</p>
                        @endif
                        @if(!empty($ueberarbeitung['werte']['zutaten']))
                            <p class="fa-t-label">Komponenten nach der Überarbeitung</p>
                            <ul class="flex flex-col gap-1">
                                @foreach($ueberarbeitung['werte']['zutaten'] as $z)
                                    @if(is_array($z))
                                        @php $mv = $ueberarbeitung['match_vorschau'][$loop->index] ?? null; @endphp
                                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[var(--fa-ink-2)]" wire:key="vkuz-{{ $loop->index }}">
                                            <span class="text-[var(--fa-ink)]"><span class="tabular-nums">{{ $z['quantity'] ?? '?' }} {{ $z['einheit_slug'] ?? '' }}</span> {{ $z['text'] ?? 'ohne Text' }}</span>
                                            <x-fa::badge>{{ isset($z['id']) ? 'bestehende Zeile' : 'neue Zeile' }}</x-fa::badge>
                                            @if($mv)
                                                @php $zielArt = $mv['kind'] === 'gp' ? 'Grundprodukt' : 'Rezept'; @endphp
                                                @if($mv['status'] === 'matched')
                                                    <x-fa::signal tone="ok" title="Bestehende Verknüpfung bleibt">{{ $zielArt }}: {{ $mv['ziel'] ?? 'ohne Namen' }}</x-fa::signal>
                                                @elseif($mv['status'] === 'grounded')
                                                    <x-fa::signal tone="ok" icon="heroicon-m-link" title="Wird beim Übernehmen automatisch verknüpft">{{ $zielArt }}: {{ $mv['ziel'] ?? 'ohne Namen' }}</x-fa::signal>
                                                @else
                                                    <x-fa::signal tone="warn" title="Kein Treffer im Bestand, nach dem Übernehmen anlegen">{{ $mv['primaer'] === 'basisrezept_anlegen' ? 'Basisrezept anlegen' : 'Grundprodukt anlegen' }}@if(($mv['shortlist'] ?? 0) > 0)<span>, {{ $mv['shortlist'] }} {{ $mv['shortlist'] === 1 ? 'Kandidat' : 'Kandidaten' }}</span>@endif</x-fa::signal>
                                                @endif
                                            @endif
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                            @php $vkHardstops = collect($ueberarbeitung['match_vorschau'] ?? [])->where('status', 'hardstop')->count(); @endphp
                            @if($vkHardstops > 0)
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-vk-ueberarbeiten-hardstops>
                                    {{ $vkHardstops }} {{ $vkHardstops === 1 ? 'Komponente hat' : 'Komponenten haben' }} keinen Treffer im Bestand. Nach dem Übernehmen als Grundprodukt oder Basisrezept anlegen, alle anderen werden automatisch verknüpft.
                                </p>
                            @endif
                        @endif
                        @foreach(['sales_wording_standard' => 'Neuer Verkaufstext', 'description' => 'Neue Beschreibung', 'plating_text' => 'Neues Anrichten'] as $feldName => $titel)
                            @if(is_string($ueberarbeitung['werte'][$feldName] ?? null) && trim($ueberarbeitung['werte'][$feldName]) !== '')
                                <p class="fa-t-label">{{ $titel }}</p>
                                <p class="text-[var(--fa-ink)] whitespace-pre-line" wire:key="vkut-{{ $feldName }}">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte'][$feldName], 400) }}</p>
                            @endif
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-fa::button size="sm" icon="heroicon-m-check" wire:click="ueberarbeitungUebernehmen" data-vk-ueberarbeiten-uebernehmen>Vorschlag übernehmen ({{ round($ueberarbeitung['confidence'] * 100) }} %)</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="ueberarbeitungVerwerfen" data-vk-ueberarbeiten-verwerfen>Verwerfen</x-fa::button>
                        <span class="{{ $hinweis }}">Übernehmen schreibt Komponenten und Texte. Von Hand Gepflegtes und die Verkaufsangaben bleiben stehen.</span>
                    </div>
                @endif
            </x-fa::section>
        @endif

        @if($copilotOffen)
            <x-fa::copilot-box :copilot="$copilot" :status="$copilotStatus" prefix="vk-" zeilen-wort="Komponente" />
        @endif

        {{-- R7 (Dominique 2026-06-14): Reiter statt langem Scroll. Alpine x-show: alle Panels bleiben im
             DOM (Marker/Tests), der eingebettete Zutaten-Editor wird NICHT neu gemountet, ungespeicherte
             Eingaben bleiben, Umschalten ohne Server-Roundtrip. Leiste + Scope + wire:key + Reset beim
             Öffnen kommen aus dem Baustein `editor-tabs` (Spec 28 / E2.1).
             'allergene'-Key bleibt stabil, Label «Deklaration» (Allergene · Zusatzstoffe · Nährwerte · Anteile).
             fa-pass: «Darreichungen» ist in «Kalkulation» aufgegangen — Preisklasse, MwSt und VK entstehen
             je Darreichung, getrennte Reiter zwangen zum Hin- und Herspringen. --}}
        <x-fa::editor-tabs marker="vk" wire-key="vk-tabs-{{ $rezept->id }}" :init="'aufbau'" visit-action="tabLaden" :gesperrt="in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true)"
            :tabs="[
                'aufbau' => 'Aufbau',
                'kalkulation' => 'Kalkulation',
                'stammdaten' => 'Stammdaten',
                'regeneration' => 'Regeneration',
                'preparation' => 'Fertigstellen',
                'plating' => 'Anrichten',
                'allergene' => 'Deklaration',
                'sensorik' => 'Sensorik und Pairing',
                'feedback' => 'Feedback',
                'notes' => 'Notizen',
            ]">

        {{-- ── Reiter: AUFBAU (nur Komponenten) ─────────────────────────────── --}}
        <div x-show="tab === 'aufbau'" x-cloak class="pt-4 flex flex-col gap-4">
            <x-fa::section title="Komponenten" icon="heroicon-o-list-bullet" :meta="(string) $rezept->ingredients->count()">
                <x-slot:actions>
                    {{-- Zwei Komponenten-KI-Funktionen → EIN Menü. Das Menü bleibt beim Klick offen, damit
                         Fortschritt und Ergebnis der Knöpfe sichtbar bleiben (Klick daneben schließt). --}}
                    <x-fa::popover rolle="menu" label="KI-Assistent" breite="72">
                        <x-slot:ausloeser>
                            <x-fa::button size="sm" variant="ai" icon="heroicon-m-sparkles" icon-right="heroicon-m-chevron-down"
                                x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">KI-Assistent</x-fa::button>
                        </x-slot:ausloeser>
                          <div class="flex flex-col gap-3">
                            <div class="flex flex-col gap-1">
                                <x-fa::ki-action action="ai_rollen" variant="ai" icon="heroicon-o-user-group" label="Rollen verteilen"
                                    title="Die KI ordnet jeder Komponente eine Rolle im Gericht zu: Aromaträger, Komponente, Beilage oder Garnitur."
                                    class="w-full justify-center" data-vk-editor-rollen busy="Wird verteilt …" flash="Rollen verteilt" />
                                <span class="{{ $hinweis }}">Vorschlag erscheint unter den Komponenten, Übernahme mit einem Klick.</span>
                            </div>
                            {{-- Garverluste: feuert ins eingebettete zutaten-kern (Alpine garverluste() via Window-Event) —
                                 lebt in einem ANDEREN x-data-Scope als der $wire-Call selbst, darum kein
                                 <x-fa::ki-action> (das ruft $wire.<action> direkt); die Rückmeldung
                                 kommt hier stattdessen über garverluste-fertig/-fehler (s. ingredient-editor.blade.php). --}}
                            <div class="flex flex-col gap-1">
                                <button type="button" x-data="{ pending: false, ok: false, err: null }"
                                        x-on:garverluste-fertig.window="pending = false; ok = true; err = null; setTimeout(() => ok = false, 1600)"
                                        x-on:garverluste-fehler.window="pending = false; ok = false; err = $event.detail?.message || 'Fehler, bitte erneut versuchen.'"
                                        x-on:click="pending = true; ok = false; err = null; $dispatch('garverluste-vorschlagen')"
                                        :class="{ 'opacity-50 cursor-wait': pending }" :disabled="pending"
                                        class="{{ $knopfKi }} w-full justify-center" :title="err || 'Die KI schätzt den Garverlust je Komponente. Gespeichert wird erst mit Speichern.'" data-vk-garverlust-ki>
                                    <template x-if="pending"><span class="inline-flex items-center gap-1">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')<span>Schätzt …</span></span></template>
                                    <template x-if="!pending && ok"><span class="inline-flex items-center gap-1 text-[var(--fa-ok)]">@svg('heroicon-o-check', 'w-3.5 h-3.5')<span>Übernommen</span></span></template>
                                    <template x-if="!pending && !ok && err"><span class="inline-flex items-center gap-1 text-[var(--fa-crit)]">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5')<span x-text="err"></span></span></template>
                                    <template x-if="!pending && !ok && !err"><span class="inline-flex items-center gap-1">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')<span>Garverluste schätzen</span></span></template>
                                </button>
                                <span class="{{ $hinweis }}">Trägt die Schätzung in die Zeilen ein, gespeichert wird mit Speichern.</span>
                            </div>
                          </div>
                    </x-fa::popover>
                </x-slot:actions>

                @if($rollenVorschlag !== null)
                    <x-fa::notice tone="info" title="Vorschlag: Rollen der Komponenten ({{ round($rollenVorschlag['confidence'] * 100) }} %)" data-vk-editor-rollen-vorschlag>
                        @if($rollenVorschlag['rollen'] === [])
                            <p>Die KI hat keinen gültigen Vorschlag geliefert. Mögliche Rollen sind Aromaträger, Komponente, Beilage und Garnitur.</p>
                        @else
                            <ul class="flex flex-col gap-0.5 mt-1">
                                @foreach($rollenVorschlag['rollen'] as $zeileId => $role)
                                    @php $zeile = $rezept->ingredients->firstWhere('id', $zeileId); @endphp
                                    <li wire:key="vkmr-{{ $zeileId }}">{{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }}: <span class="font-medium">{{ $rollenText[$role] ?? $role }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                        <x-slot:actions>
                            @if($rollenVorschlag['rollen'] !== [])
                                <x-fa::button size="sm" icon="heroicon-m-check" wire:click="accept_rollen" data-vk-rollen-accept>Rollen übernehmen</x-fa::button>
                            @endif
                            <x-fa::button size="sm" variant="ghost" wire:click="reject_rollen">Verwerfen</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                @endif

                {{-- Altbestand (Entscheid Dominique 17.11.2026): ein Gericht enthält nur Basisrezepte. Zeilen mit GP direkt bleiben, bis
                     sie hier umgebaut werden — Basisrezept (Entwurf) mit genau dieser Zutat und Menge, Zeile hängt danach darauf. --}}
                @if(($gpDirekt ?? []) !== [] && ! in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true))
                    <x-fa::notice tone="warn" title="GP direkt im Gericht — Basisrezept anlegen" data-gp-im-gericht>
                        <p>Ein Gericht enthält nur Basisrezepte: Fertigware als Zukauf-Basisrezept, Garnitur als Garnitur-Basisrezept. Name prüfen, dann anlegen.</p>
                        <ul class="flex flex-col gap-2 mt-2">
                            @foreach($gpDirekt as $g)
                                <li wire:key="gp-direkt-{{ $g['id'] }}" class="flex flex-wrap items-center gap-2" data-gp-im-gericht-zeile="{{ $g['id'] }}">
                                    <span class="min-w-[10rem] font-medium">{{ $g['gp'] }}</span>
                                    <input type="text" wire:model="gpUmbauNamen.{{ $g['id'] }}" aria-label="Name des Basisrezepts für {{ $g['gp'] }}"
                                           class="fa-control h-8 flex-1 min-w-[14rem] text-[length:var(--fa-text-md)]"
                                           @unless($g['typ_sicher']) title="Typ nur vorgeschlagen — bitte prüfen" @endunless />
                                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="gpZeileAlsBasisrezept({{ $g['id'] }})" data-gp-im-gericht-anlegen>Basisrezept anlegen</x-fa::button>
                                </li>
                            @endforeach
                        </ul>
                    </x-fa::notice>
                @endif

                <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="vk-zutaten-{{ $recipeId }}-v{{ $zutatenVersion }}" />
            </x-fa::section>
        </div>{{-- /Reiter AUFBAU --}}

        {{-- ── Reiter: KALKULATION (Überblick · Verkaufseinheit · Preis · Darreichungen) ───────── --}}
        <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 flex flex-col gap-4">
            {{-- Verkaufseinheit des Gerichts. Preisklasse, Preis und MwSt stehen NUR an der Darreichung darunter (13.11.2026):
                 das frühere zweite Preisklassen-Feld hier überschrieb beim Speichern, was in der Zeile gewählt war. --}}
            @include('foodalchemist::livewire.verkauf.partials.darreichungen')
            @include('foodalchemist::livewire.verkauf.partials.gemeinsame-zeiten')
        </div>{{-- /Reiter KALKULATION --}}

        {{-- ── Reiter: STAMMDATEN (Benennung · Einordnung · Fertigstellung) ────────────────
             Spec 28 / E6: aus «Aufbau» herausgelöst — Master-Parität (Basisrezept-Editor). --}}
        <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 flex flex-col gap-4">
            <x-fa::section title="Stammdaten" icon="heroicon-o-identification">
                {{-- M9-01i: KI-Vorschlag in die Form-Felder (Speichern = Übernehmen). Marketing-Text lebt am Foodbook-Block. --}}
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ai" icon="heroicon-o-sparkles" wire:click="ki('wording')"
                        title="Neutraler Verkaufstext, unabhängig vom Konzept-Stil" data-ki-wording>Verkaufstext vorschlagen</x-fa::button>
                </x-slot:actions>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-fa::field label="Name" for="vk-name" required class="sm:col-span-2">
                        <x-fa::input id="vk-name" wire:model="form.name" data-vk-name />
                    </x-fa::field>
                    <x-fa::field label="Verkaufstext" for="vk-wording" class="sm:col-span-2"
                        hint="Neutraler Standard für Konzepter und Foodbook. Vorrang haben Foodbook und Konzept, danach dieser Text, zuletzt der interne Name.">
                        <x-fa::input id="vk-wording" wire:model="form.sales_wording_standard" data-vk-wording />
                    </x-fa::field>
                    <x-fa::field label="Beschreibung" for="vk-beschreibung" class="sm:col-span-2" hint="Drei bis fünf sachliche Sätze.">
                        <x-fa::textarea id="vk-beschreibung" wire:model="form.description" rows="3" data-vk-description />
                    </x-fa::field>
                    {{-- Spec 43 (Bild-Epic): Gericht-Foto — dasselbe Bild wie am Basisrezept (recipes.image_*). --}}
                    <x-fa::field label="Gericht-Foto" optional class="sm:col-span-2" hint="Für Präsentation und Detailansicht." data-vk-bild>
                        <div class="flex flex-wrap items-center gap-3">
                            @if(!empty($dishImageUrl))
                                <img src="{{ $dishImageUrl }}" alt="Foto des Gerichts" class="h-14 w-24 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-trash" wire:click="dishImageEntfernen">Foto entfernen</x-fa::button>
                            @endif
                            <x-fa::upload wire:model="dishImageUpload" accept="image/*" aria-label="Gericht-Foto hochladen" data-vk-bild-upload="" />
                            <span wire:loading wire:target="dishImageUpload" class="{{ $hinweis }}">lädt …</span>
                        </div>
                        @error('dishImageUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                    </x-fa::field>
                </div>
            </x-fa::section>

            <x-fa::section title="Einordnung" icon="heroicon-o-tag">
                <x-slot:actions>
                    {{-- Dominique 2026-10-12: Klasse + Hauptgruppe (SpeisenKlassenService), Geschmack, Fertigungstiefe — nur leere Felder --}}
                    <x-fa::button size="sm" variant="ai" icon="heroicon-o-sparkles" wire:click="ki('einordnung')" wire:loading.attr="disabled" wire:target="ki('einordnung')"
                        title="Speisen-Klasse, Hauptgruppe, Geschmack und Fertigungstiefe vorschlagen — gesetzte Felder bleiben" data-ki-einordnung>Einordnung vorschlagen</x-fa::button>
                </x-slot:actions>
                @if($kiMeldung !== null)<x-fa::notice tone="ok" data-vk-ki-meldung>{{ $kiMeldung }}</x-fa::notice>@endif
                <div class="grid gap-4 sm:grid-cols-2" data-vk-klassifikation>
                    {{-- Modell A (MVP-049): Hauptgruppe ist ein normales Formularfeld am Gericht, keine
                         Kaskaden-Steuerung — deshalb `form.dish_main_group_id` und kein `.live`. --}}
                    <x-fa::field label="Speisen-Hauptgruppe" for="vk-hg">
                        <x-fa::select id="vk-hg" wire:model="form.dish_main_group_id" placeholder="nicht gesetzt" data-vk-hg>
                            @foreach($hauptgruppen as $hg)
                                <option value="{{ $hg->id }}">{{ $hg->label }} ({{ $hg->code }})</option>
                            @endforeach
                        </x-fa::select>
                    </x-fa::field>
                    {{-- Unabhängige Achse: die vier Diätformen, nie von der Hauptgruppe abhängig. --}}
                    <x-fa::field label="Speisen-Klasse (Diätform)" for="vk-klasse">
                        <x-fa::select id="vk-klasse" wire:model="form.dish_class_id" placeholder="nicht gesetzt" data-vk-klasse>
                            @foreach($klassen as $k)
                                <option value="{{ $k->id }}">{{ $k->label }} ({{ $diaetText[$k->diet_form] ?? $k->diet_form }})</option>
                            @endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Geschmack" for="vk-geschmack">
                        <x-fa::select id="vk-geschmack" wire:model="form.taste_direction" placeholder="nicht gesetzt"
                            :options="['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral']" />
                    </x-fa::field>
                    {{-- Leitplanken-Vorgabe (29.10.): aus der Planung übernommen, hier änderbar — entscheidet bei Basiskomponenten selbst oder gekauft --}}
                    <x-fa::field label="Vorgabe Eigenleistung" for="vk-vorgabe-convenience">
                        <x-fa::select id="vk-vorgabe-convenience" wire:model="form.vorgabe_convenience" data-vorgabe-convenience
                            :options="collect(\Platform\FoodAlchemist\Livewire\Planung\Index::RICHTUNGEN)->firstWhere('field', 'convenience')['optionen']" />
                    </x-fa::field>
                    <x-fa::field label="Vorgabe Niveau" for="vk-vorgabe-niveau">
                        <x-fa::select id="vk-vorgabe-niveau" wire:model="form.vorgabe_niveau" data-vorgabe-niveau
                            :options="collect(\Platform\FoodAlchemist\Livewire\Planung\Index::RICHTUNGEN)->firstWhere('field', 'level')['optionen']" />
                    </x-fa::field>
                    <x-fa::field label="Fertigungstiefe" for="vk-tiefe">
                        <x-fa::select id="vk-tiefe" wire:model="form.production_depth" placeholder="unbestimmt"
                            :options="['from_scratch' => 'from scratch', 'teilfertig' => 'teilfertig', 'convenience' => 'Convenience']" />
                    </x-fa::field>
                </div>
            </x-fa::section>

        </div>{{-- /Reiter STAMMDATEN --}}

        {{-- ── Reiter: REGENERATION (§3.2 — finaler Garprozess am Einsatztag, oft am Satelliten;
             dazu die Behälter, weil sie Transport und Warmhalten tragen, §3.4) ───────────── --}}
        <div x-show="tab === 'regeneration'" x-cloak class="pt-4 flex flex-col gap-4">
            <x-fa::section title="Regeneration je Komponente" icon="heroicon-o-fire"
                description="Die Liste kommt aus den Komponenten. Gespeichert wird nur, was an diesem Gericht bewusst abweicht.">
                <x-slot:actions>
                    <x-fa::ki-action action="kiRegeneration" variant="ai" icon="heroicon-o-sparkles" label="Programme vorschlagen"
                        title="Ein Programm je Komponente als Vorschlag, Übernahme je Zeile" data-ki-regeneration
                        busy="Wird ermittelt …" flash="Vorschläge da" />
                </x-slot:actions>

                @if($regenVorschlaege !== [])
                    <x-fa::notice tone="info" title="Vorschläge der KI, je Zeile übernehmen" data-regen-vorschlaege>
                        <ul class="flex flex-col gap-1 mt-1">
                            @foreach($regenVorschlaege as $idx => $rv)
                                <li class="flex items-center justify-between gap-2" wire:key="rvz-{{ $idx }}">
                                    <span class="min-w-0">{{ $rv['component_label'] }}{{ $rv['temp_c'] !== null ? ' · ' . $rv['temp_c'] . ' °C' : '' }}{{ $rv['duration_min'] !== null ? ' · ' . $rv['duration_min'] . ' min' : '' }}{{ $rv['core_temp_c'] !== null ? ' · Kerntemperatur ' . $rv['core_temp_c'] . ' °C' : '' }}</span>
                                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="regenVorschlagUebernehmen({{ $idx }})" class="shrink-0" data-regen-uebernehmen>Übernehmen</x-fa::button>
                                </li>
                            @endforeach
                        </ul>
                    </x-fa::notice>
                @endif

                <div class="flex flex-col" data-vk-regen>
                    {{-- Spec 51: Die Liste ist ABGELEITET. Was hier steht, kommt aus den Komponenten —
                         gespeichert ist nur, was jemand an DIESEM Gericht bewusst übersteuert hat. --}}
                    @foreach($kaskade['gesamt'] as $g)
                        <div wire:key="rg-{{ $g['regeneration_id'] }}" class="flex flex-wrap items-center gap-2 py-2 border-b border-[var(--fa-line)] text-[length:var(--fa-text-md)]" data-regen-zeile="{{ $g['regeneration_id'] }}">
                            <x-fa::badge tone="info" title="Das Gericht wird als Ganzes regeneriert">Ganzes Gericht</x-fa::badge>
                            <span class="flex-1 min-w-0 text-[var(--fa-ink-2)]">{{ $regenText($g) }}</span>
                            <span class="flex items-center gap-0.5 shrink-0">
                                <x-fa::icon-button icon="heroicon-m-chevron-up" label="Nach oben" size="sm" wire:click="regenSchieben({{ $g['regeneration_id'] }}, -1)" />
                                <x-fa::icon-button icon="heroicon-m-chevron-down" label="Nach unten" size="sm" wire:click="regenSchieben({{ $g['regeneration_id'] }}, 1)" />
                                <x-fa::icon-button icon="heroicon-o-pencil" label="Ändern" size="sm" wire:click="regenBearbeiten({{ $g['regeneration_id'] }})" />
                                <x-fa::icon-button icon="heroicon-o-trash" label="Löschen" size="sm" tone="danger" wire:click="regenLoeschen({{ $g['regeneration_id'] }})" />
                            </span>
                        </div>
                    @endforeach

                    @foreach($kaskade['komponenten'] as $z)
                        @php [$herkunftLabel, $herkunftTon, $herkunftTitel] = $herkunftText[$z['herkunft']] ?? [$z['herkunft'], 'neutral', null]; @endphp
                        <div wire:key="rk-{{ $z['ingredient_id'] }}" class="flex flex-wrap items-center gap-2 py-2 border-b border-[var(--fa-line)] text-[length:var(--fa-text-md)]" data-regen-komponente="{{ $z['ingredient_id'] }}">
                            <span class="flex-1 min-w-0">
                                <span class="font-medium text-[var(--fa-ink)]">{{ $z['label'] }}</span>
                                @if($z['herkunft'] === 'fehlt')
                                    <span class="text-[var(--fa-warn)]">· keine Regeneration hinterlegt</span>
                                @else
                                    <span class="text-[var(--fa-ink-2)]">· {{ $regenText($z) }}</span>
                                @endif
                            </span>
                            <x-fa::badge :tone="$herkunftTon" class="shrink-0" data-regen-herkunft="{{ $z['herkunft'] }}"
                                title="{{ $z['herkunft'] === 'geerbt' ? 'Kommt aus «' . $z['von_recipe_name'] . '». Dort ändern wirkt in allen Gerichten.' : $herkunftTitel }}">{{ $herkunftLabel }}</x-fa::badge>
                            <span class="flex items-center gap-0.5 shrink-0">
                                <x-fa::icon-button icon="heroicon-o-pencil" label="Für dieses Gericht anpassen" size="sm" wire:click="regenKomponenteBearbeiten({{ $z['ingredient_id'] }})" />
                                @if($z['herkunft'] === 'override')
                                    <x-fa::icon-button icon="heroicon-o-arrow-uturn-left" label="Zurück auf den Stand der Komponente" size="sm"
                                        wire:click="regenOverrideZuruecksetzen({{ $z['regeneration_id'] }})" data-regen-reset />
                                @endif
                            </span>
                        </div>
                    @endforeach

                    @if($kaskade['luecken'] > 0)
                        <x-fa::signal tone="warn" class="mt-2" data-regen-luecken>
                            {{ $kaskade['luecken'] }} {{ $kaskade['luecken'] === 1 ? 'Komponente' : 'Komponenten' }} ohne Regeneration. Das gehört ans jeweilige Basisrezept.
                        </x-fa::signal>
                    @endif
                    @foreach($kaskade['verwaist'] as $w)
                        <div class="flex flex-wrap items-center gap-2 mt-2" wire:key="rw-{{ $w['regeneration_id'] }}" data-regen-verwaist>
                            <x-fa::signal tone="crit">«{{ $w['label'] }}»: Abweichung ohne Komponente, die Zutat wurde entfernt oder getauscht.</x-fa::signal>
                            <x-fa::button size="sm" variant="ghost" wire:click="regenOverrideZuruecksetzen({{ $w['regeneration_id'] }})">Aufräumen</x-fa::button>
                        </div>
                    @endforeach

                    <div class="mt-3 pt-3 border-t border-[var(--fa-line)] flex flex-col gap-2" data-regen-form>
                        <p class="fa-t-label">{{ $regenEditId !== null ? 'Programm bearbeiten' : 'Programm hinzufügen' }}</p>
                        <div class="grid gap-2 grid-cols-2 sm:grid-cols-6">
                            <input type="text" wire:model="regenForm.component_label" class="{{ $feld }} col-span-2" placeholder="Komponente (z. B. Gesamt)" aria-label="Komponente" />
                            <select wire:model="regenForm.device_vocab_id" class="{{ $feld }} fa-select pr-8 col-span-2 sm:col-span-1" aria-label="Gerät">
                                <option value="">kalt</option>
                                @foreach($geraete as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                            </select>
                            <input type="number" wire:model="regenForm.temp_c" class="{{ $feld }} text-right tabular-nums" placeholder="°C" aria-label="Temperatur in °C" />
                            <input type="number" wire:model="regenForm.duration_min" class="{{ $feld }} text-right tabular-nums" placeholder="min" aria-label="Dauer in Minuten" />
                            <input type="number" wire:model="regenForm.core_temp_c" class="{{ $feld }} text-right tabular-nums" placeholder="Kern °C" aria-label="Kerntemperatur in °C" />
                            <input type="text" wire:model="regenForm.note" class="{{ $feld }} col-span-2 sm:col-span-5" placeholder="Hinweis (z. B. abgedeckt, nach 8 min schwenken)" aria-label="Hinweis" />
                            <x-fa::button :icon="$regenEditId !== null ? 'heroicon-m-check' : 'heroicon-m-plus'" wire:click="regenSpeichern" class="col-span-2 sm:col-span-1" data-regen-speichern>{{ $regenEditId !== null ? 'Aktualisieren' : 'Hinzufügen' }}</x-fa::button>
                        </div>
                    </div>
                </div>
            </x-fa::section>

            {{-- Spec 51: früher zwei Skalare (warm/kalt) und ein getipptes «n». Die ANZAHL ist eine Rechnung
                 aus der produzierten Menge, keine Eingabe. --}}
            <x-fa::section title="Behälter je Zweck" icon="heroicon-o-archive-box"
                description="Leer heißt: es gilt der Behälter der jeweiligen Komponente. Ein Eintrag hier weicht für dieses Gericht bewusst ab. Die Anzahl rechnet die Produktion aus der Menge.">
                <x-slot:actions>
                    {{-- Dominique 2026-10-12: Behälter je Zweck aus dem Katalog + Füllmengen-Dossier, nie die Anzahl --}}
                    <x-fa::ki-action action="kiBehaelter" variant="ai" icon="heroicon-o-sparkles" label="Behälter vorschlagen"
                        title="Behälter je Zweck, Skalierung und Referenzmenge aus Katalog und Füllmengen-Dossier — nur leere Felder, Speichern übernimmt"
                        data-vk-behaelter-ki busy="Wird vorgeschlagen …" flash="Vorgeschlagen" />
                </x-slot:actions>
                @if($kiMeldung !== null)<x-fa::notice tone="ok" data-vk-ki-meldung-behaelter>{{ $kiMeldung }}</x-fa::notice>@endif
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-x-6 gap-y-3" data-vk-container>
                    @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::ZWECKE as $zweck)
                        @php $lagen = ($behaelterForm[$zweck]['skalierung'] ?? '') === 'lagenware'; @endphp
                        <div class="flex flex-wrap items-center gap-2" wire:key="vkbh-{{ $zweck }}" data-behaelter-zweck="{{ $zweck }}">
                            <span class="w-24 shrink-0 fa-t-label">{{ $zweckText[$zweck] ?? ucfirst($zweck) }}</span>
                            <select wire:model="behaelterForm.{{ $zweck }}.container_vocab_id" class="{{ $feldKlein }} fa-select pr-8 w-48" aria-label="Behälter für {{ $zweckText[$zweck] ?? $zweck }}">
                                <option value="">wie Komponente</option>
                                @foreach($behaelter as $b)
                                    <option value="{{ $b->id }}" @if($b->is_inactive) hidden @endif>{{ $b->name }}</option>
                                @endforeach
                            </select>
                            <input type="text" inputmode="decimal" wire:model="behaelterForm.{{ $zweck }}.{{ $lagen ? 'stueck_je_behaelter' : 'referenz_menge_kg' }}"
                                   placeholder="{{ $lagen ? 'Stück' : 'passt kg' }}" aria-label="{{ $lagen ? 'Stück je Behälter' : 'Füllmenge in kg' }}" class="{{ $feldKlein }} w-24 text-right tabular-nums" />
                            <select wire:model.live="behaelterForm.{{ $zweck }}.skalierung" class="{{ $feldKlein }} fa-select pr-8 w-40" aria-label="Skalierung">
                                <option value="">Skalierung …</option>
                                <option value="tiefer_fuellbar">tiefer füllbar</option>
                                <option value="hoehe_gebunden">höhengebunden</option>
                                <option value="lagenware">Lagenware</option>
                            </select>
                        </div>
                    @endforeach
                </div>

                @if($behaelterVorschau !== [])
                    <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-1.5">
                        <p class="{{ $hinweis }}">Bedarf für {{ $behaelterVorschauPax }} Portionen, gerechnet aus der Menge:</p>
                        <div class="flex flex-wrap gap-1.5" data-vk-behaelter-vorschau>
                            @foreach($behaelterVorschau as $v)
                                <x-fa::badge>{{ $v }}</x-fa::badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-fa::section>
        </div>{{-- /Reiter REGENERATION --}}

        {{-- ── Reiter: FERTIGSTELLEN (Step-by-Step, 2026-08-03) ───────────────────
             Gleiche Schrittfolge wie im Basisrezept-Editor: der eingebettete StepEditor ist typ-agnostisch
             und schreibt in `foodalchemist_recipe_steps` (Master); `recipes.preparation` ist der gerenderte
             Lese-Spiegel (Produktionsdruck, Suche und Prozessanker lesen ihn). --}}
        <div x-show="tab === 'preparation'" x-cloak class="pt-4 flex flex-col gap-4">
            <x-fa::section title="Fertigstellen am Einsatztag" icon="heroicon-o-queue-list"
                description="Alles zwischen regeneriert und angerichtet: bereitstellen, portionieren, tranchieren, montieren, abschmecken. Die Herstellung der Komponenten steht in deren Basisrezepten, das Regenerations-Programm im Reiter Regeneration, der Teller-Aufbau im Reiter Anrichten.">
                <livewire:foodalchemist.recipes.step-editor :recipe-id="$rezept->id" ebene="produktion"
                    wire:key="schritt-editor-vk-produktion-{{ $rezept->id }}" />
            </x-fa::section>
        </div>{{-- /Reiter FERTIGSTELLEN --}}

        {{-- ── Reiter: ANRICHTEN (§3.3 — Teller-Aufbau und Ausgabe; dazu das Servier-Vehikel, §3.4) ── --}}
        <div x-show="tab === 'plating'" x-cloak class="pt-4 flex flex-col gap-4">
            {{-- Teller-Aufbau als bebilderte Schrittfolge (User-Entscheid 2026-09-04) über dieselben
                 `recipe_steps` mit `ebene='anrichten'`; `plating_text` bleibt der gerenderte Spiegel
                 (Foodbook, Angebot und Report lesen unverändert das Textfeld). --}}
            <x-fa::section title="Anrichten und Ausgabe" icon="heroicon-o-sparkles"
                description="Teller-Aufbau am Pass: Reihenfolge, Mengen je Teller, Geometrie, Garnitur. Schritt für Schritt, mit Fotos.">
                <x-slot:actions>
                    @php $bildkosten = config('foodalchemist.ai.bildkosten_usd.models')['gpt-image-1.5'] ?? null; @endphp
                    <x-fa::menu breite="72">
                        <x-slot:ausloeser>
                            <x-fa::button size="sm" variant="ai" icon="heroicon-m-sparkles" icon-right="heroicon-m-chevron-down"
                                x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">KI-Assistent</x-fa::button>
                        </x-slot:ausloeser>
                        <x-fa::menu-item icon="heroicon-o-sparkles" abstand="weit" wire:click="ki('plating')" title="Die KI schlägt den Teller-Aufbau vor und legt ihn als Anrichte-Schritte an. Bestehende Schritte werden ersetzt." data-ki-plating>Anrichten vorschlagen</x-fa::menu-item>
                        {{-- Spec 53: Produktfoto (Hero) erzeugen/ersetzen — läuft async (EnrichRecipeJob,
                             nurProduktfoto), gepollt über pruefeProduktfotoErgebnis. --}}
                        <div class="px-3 py-2 flex flex-col gap-1 border-t border-[var(--fa-line)]">
                            <x-fa::ki-action action="kiProduktfoto" variant="ai" icon="heroicon-o-photo" label="Produktfoto erzeugen"
                                busy="Malt …" flash="Foto erzeugt" class="w-full justify-center"
                                title="{{ 'Erzeugt oder ersetzt das Foto des fertigen Gerichts' . ($bildkosten !== null ? ', ca. ' . number_format($bildkosten, 3, ',', '.') . ' $ je Bild' : '') . '.' }}"
                                data-ki-produktfoto />
                        </div>
                    </x-fa::menu>
                </x-slot:actions>
                @if($produktfotoLaeuft)
                    <div wire:poll.2s="pruefeProduktfotoErgebnis" class="flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-info)]" data-produktfoto-laeuft>
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') KI-Produktfoto wird erzeugt …
                    </div>
                @endif
                @if($produktfotoFehler)
                    <x-fa::notice tone="crit" data-produktfoto-fehler>{{ $produktfotoFehler }}</x-fa::notice>
                @endif
                <div data-vk-plating>
                    <livewire:foodalchemist.recipes.step-editor :recipe-id="$rezept->id" ebene="anrichten"
                        wire:key="schritt-editor-vk-anrichten-{{ $rezept->id }}-v{{ $fotoVersion }}" />
                </div>
            </x-fa::section>

            <x-fa::section title="Servier-Vehikel" icon="heroicon-o-square-3-stack-3d" description="Worauf angerichtet wird, also was der Gast sieht.">
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ai" icon="heroicon-o-sparkles" wire:click="ki('vehikel')"
                        title="Die KI schlägt vor, worauf angerichtet wird" data-ki-vehikel>Vehikel vorschlagen</x-fa::button>
                </x-slot:actions>
                {{-- Eine Stelle (Entscheid Dominique 18.11.2026): Typ (neutral) und konkretes Gefäß stehen je Darreichung im Reiter Kalkulation.
                     Hier nur der wirksame Typ der Standard-Darreichung; „Vehikel vorschlagen“ schreibt dorthin. --}}
                @php $stdGi = ($std = $darreichungen->firstWhere('is_standard', true)) ? ($darGefaess[$std->id] ?? null) : null; @endphp
                <p class="text-[length:var(--fa-text-sm)]" data-vk-vehikel-standard>
                    <span class="font-medium">{{ $stdGi['typ_name'] ?? 'kein Typ gesetzt' }}</span>@if($stdGi['gefaess_name'] ?? null)<span class="text-[var(--fa-ink-2)]"> · Gefäß {{ $stdGi['gefaess_name'] }}</span>@endif
                    <span class="{{ $hinweis }} block">Typ und Gefäß stellst du je Darreichung im Reiter Kalkulation ein (Standard-Darreichung).</span>
                </p>
            </x-fa::section>
        </div>{{-- /Reiter ANRICHTEN --}}

        {{-- ── Reiter: DEKLARATION (Allergene · Zusatzstoffe · Nährwerte · Anteile) ── --}}
        <div x-show="tab === 'allergene'" x-cloak class="pt-4 flex flex-col gap-4">
            {{-- M9-01c: Allergene · Zusatzstoffe · Diät (geteiltes Partial → x-fa::deklaration) --}}
            <x-fa::section title="Allergene und Diät" icon="heroicon-o-beaker">
                @include('foodalchemist::livewire.recipes.partials.deklaration', ['rezept' => $rezept])
            </x-fa::section>

            <div class="grid gap-4 lg:grid-cols-3">
                {{-- M9-01d: Nährwerte (GL-08-Aggregate — pro 100 g + pro Stück) --}}
                <x-fa::section title="Nährwerte" icon="heroicon-o-chart-bar" class="lg:col-span-2">
                    @if($rezept->nutri_kcal_per_100g === null)
                        <x-fa::empty compact icon="heroicon-o-chart-bar" title="Noch nicht berechnet" data-vk-naehrwerte-leer>Die Nährwerte entstehen beim nächsten Speichern der Komponenten.</x-fa::empty>
                    @else
                        <div class="overflow-x-auto">
                            <x-fa::table compact data-vk-naehrwerte="">
                                <thead><tr>
                                    <th>Nährwert</th>
                                    <th class="text-right">je 100 g</th>
                                    <th class="text-right">je Einheit{{ $gProStueck !== null ? ' (≈ ' . number_format($gProStueck, 0, ',', '.') . ' g)' : '' }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach([
                                        ['Brennwert', $rezept->nutri_kcal_per_100g, 'kcal', 0, false],
                                        ['Eiweiß', $rezept->nutri_protein_g_per_100g, 'g', 1, false],
                                        ['Fett', $rezept->nutri_fat_g_per_100g, 'g', 1, false],
                                        ['davon gesättigte Fettsäuren', $rezept->nutri_saturated_fat_g_per_100g, 'g', 1, true],
                                        ['Kohlenhydrate', $rezept->nutri_carbs_g_per_100g, 'g', 1, false],
                                        ['davon Zucker', $rezept->nutri_sugar_g_per_100g, 'g', 1, true],
                                        ['Salz', $rezept->nutri_salt_g_per_100g, 'g', 2, false],
                                    ] as [$lbl, $wert, $unit, $dez, $eingerueckt])
                                        <tr wire:key="vkn-{{ $lbl }}">
                                            <td class="{{ $eingerueckt ? 'pl-7 text-[var(--fa-ink-2)]' : '' }} {{ $lbl === 'Brennwert' ? 'font-medium' : '' }}">{{ $lbl }}</td>
                                            <td class="num">{{ $wert !== null ? number_format((float) $wert, $dez, ',', '.') . ' ' . $unit : '–' }}</td>
                                            <td class="num">{{ $wert !== null && $gProStueck !== null ? number_format((float) $wert * $gProStueck / 100, $dez, ',', '.') . ' ' . $unit : '–' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </x-fa::table>
                        </div>
                        @php [$nutriText, $nutriTon] = ['high' => ['vollständig belegt', 'ok'], 'medium' => ['teilweise belegt', 'warn'], 'low' => ['unsicher', 'crit']][$rezept->nutri_confidence ?? ''] ?? ['nicht bewertet', 'warn']; @endphp
                        <div class="flex flex-col gap-1">
                            <x-fa::signal :tone="$nutriTon">Nährwerte {{ $nutriText }}: {{ $rezept->nutri_n_ingredients_mapped ?? 0 }} von {{ $rezept->nutri_n_ingredients_total ?? 0 }} Komponenten mit Nährwert-Daten</x-fa::signal>
                            <p class="{{ $hinweis }}">
                                Je 100 g Ausbeute (nach Gar- und Putzverlust), Kochmedium nur anteilig. „Je Einheit“ nach der Grammatur der Standard-Darreichung. Stück-Zutaten ohne Gramm- oder Milliliter-Angabe tragen nichts bei.@if($rezept->nutri_aggregated_at !== null)<span> Berechnet am {{ $rezept->nutri_aggregated_at->format('d.m.Y, H:i') }} Uhr.</span>@endif
                            </p>
                        </div>
                    @endif
                </x-fa::section>

                {{-- M9-01e: Anteile (Bio/Regional, Gramm-gewichtet über Grundprodukt-Merkmale) --}}
                <x-fa::section title="Anteile" icon="heroicon-o-globe-europe-africa" description="Nach Gewicht der Komponenten.">
                    <dl class="grid grid-cols-2 gap-3" data-vk-spezifikation>
                        <div>
                            <dt class="fa-t-label">Bio</dt>
                            <dd class="fa-t-h2 tabular-nums">{{ $prozent($anteile['bio']) ?? '–' }}</dd>
                        </div>
                        <div>
                            <dt class="fa-t-label">Regional (DE)</dt>
                            <dd class="fa-t-h2 tabular-nums">{{ $anteile['regional'] !== null && $anteile['regional'] > 0 ? $prozent($anteile['regional']) : '–' }}</dd>
                        </div>
                    </dl>
                </x-fa::section>
            </div>
        </div>{{-- /Reiter DEKLARATION --}}

        {{-- ── Reiter: SENSORIK UND PAIRING (Geschmacks-Balance + Textur + Aroma-Stimmigkeit über die Grundprodukte) ── --}}
        <div x-show="tab === 'sensorik'" x-cloak class="pt-4 flex flex-col gap-4">
            <x-fa::section title="Sensorik" icon="heroicon-o-eye-dropper" description="Gegartes Profil. Die KI liest Komponenten und Zubereitung.">
                <x-slot:actions>
                    <x-fa::ki-action action="sensorikBewerten" variant="ai" icon="heroicon-o-sparkles" label="Sensorik neu bewerten"
                        busy="Bewertet …" flash="Sensorik bewertet" />
                </x-slot:actions>
                @if(($komposition ?? null) && ! ($komposition['leer'] ?? true))
                    @include('foodalchemist::livewire.concepter.partials.sensorik_komposition')
                @else
                    @include('foodalchemist::livewire.concepter.partials.sensorik')
                @endif
            </x-fa::section>
            <x-fa::section title="Pairing" icon="heroicon-o-link">
                {{-- Spec 60: Pairing erst beim Öffnen des Tabs laden (tabLaden) --}}
                @if($pairingGeladen)
                    @include('foodalchemist::livewire.concepter.partials.pairing')
                @else
                    <p class="py-6 text-center fa-t-muted" data-vk-pairing-laedt>Pairing wird geladen …</p>
                @endif
            </x-fa::section>
        </div>

        {{-- ── Reiter: FEEDBACK (R2.6 — Praxis-Feedback Küche/Kunde/Event) ── --}}

        {{-- ── Reiter: NOTIZEN (Notizen + Verwendungsnachweise) ───────────── --}}
        <div x-show="tab === 'notes'" x-cloak class="pt-4 flex flex-col gap-4">
            {{-- M9-01h: Notizen (§9.1 — manuelle Insel) --}}
            <x-fa::section title="Notizen" icon="heroicon-o-pencil-square" description="Bleibt bei jeder KI-Anreicherung erhalten.">
                <x-fa::textarea wire:model="form.notes_manual" rows="4" aria-label="Notizen" data-vk-notes />
            </x-fa::section>

            <x-fa::section title="Verwendungsnachweise" icon="heroicon-o-building-storefront" :meta="(string) $kunden->count()"
                description="Bei welchem Kunden das Gericht unter welchem Namen läuft.">
                <div class="flex flex-col" data-vk-kunden>
                    @foreach($kunden as $k)
                        <div wire:key="kn-{{ $k->id }}" class="flex items-center gap-2 py-2 border-b border-[var(--fa-line)] text-[length:var(--fa-text-md)]" data-kunde-zeile="{{ $k->id }}">
                            <span class="flex-1 min-w-0 truncate"><span class="font-medium text-[var(--fa-ink)]">{{ $k->customer_name }}</span> <span class="text-[var(--fa-ink-2)]">· {{ $k->marketing_name }}</span></span>
                            <x-fa::icon-button icon="heroicon-o-trash" label="Nachweis löschen" size="sm" tone="danger" wire:click="kundeLoeschen({{ $k->id }})" />
                        </div>
                    @endforeach
                    <div class="grid gap-2 sm:grid-cols-5 pt-3">
                        <x-fa::input wire:model="kundeName" class="sm:col-span-2" placeholder="Kunde" aria-label="Kunde" data-kunde-name />
                        <x-fa::input wire:model="kundeMarketing" class="sm:col-span-2" placeholder="Name beim Kunden" aria-label="Name beim Kunden" data-kunde-marketing />
                        <x-fa::button icon="heroicon-m-plus" wire:click="kundeHinzufuegen" data-kunde-hinzufuegen>Nachweis anlegen</x-fa::button>
                    </div>
                </div>
            </x-fa::section>
        </div>{{-- /Reiter NOTIZEN --}}

            {{-- Spec 65: KI-Feedback auch ohne „Bearbeiten“ (Dominique 2026-10-07) --}}
            <x-slot:frei>
        <div x-show="tab === 'feedback'" x-cloak class="pt-4">
            @livewire('foodalchemist.recipes.feedback-panel', ['recipeId' => $rezept->id], key('feedback-vk-'.$rezept->id))
        </div>
            </x-slot:frei>
        </x-fa::editor-tabs>
    @endif
</x-fa::modal>
