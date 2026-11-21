{{-- GP-Detail. fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt.
     Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel): Kopf mit EINER Hauptaktion
     („Im Editor öffnen", Drucken/PDF/Löschen im Menü) · Kennzahlen · offene Punkte · KI-Schätzung zur Prüfung ·
     Deklaration (Allergene, Zusatzstoffe, Eigenschaften) · Inhalt (Lieferantenartikel) ·
     Fachabschnitte (Stammdaten, Einheit, Nährwerte, Aroma, Ersatz) · Verwendung (Rezepte, Verwaltung).
     Die Editor-Karteien im GP-Dialog (embedded, section=las|allergene|zusatzstoffe|naehrwerte|ersatz)
     laufen über dieselben @if($section)-Guards wie vorher. --}}
@php
    // #1003: am Handy öffnet eine Artikel-Zeile die Lese-Artikelkarte der Lieferanten-Seite statt des Desktop-Dialogs „Artikel bearbeiten“
    $handyArtikelLink = \Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy();
    $tagLabels = [
        'is_vegan' => 'vegan', 'is_vegetarian' => 'vegetarisch', 'is_halal' => 'halal',
        'contains_pork' => 'enthält Schwein', 'contains_beef' => 'enthält Rind',
        'is_organic' => 'Bio', 'is_regional' => 'regional', 'is_staple_food' => 'Grundnahrungsmittel',
        'is_convenience' => 'Convenience', 'is_lactose_free' => 'laktosefrei', 'is_gluten_free' => 'glutenfrei',
    ];
    $konfTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit', 'none' => 'warn', 'unknown' => 'warn'];   // 98 · 1.1d: unbekannt nie neutral (liest sich sonst wie allergenfrei)
    $konfSatz = [
        'high' => 'Allergene vollständig durch Artikel belegt',
        'medium' => 'Allergene teilweise belegt, stichprobenartig prüfen',
        'low' => 'Allergene unsicher, vor Verwendung prüfen',
        'none' => 'Allergene noch nicht durch Artikel belegt',
    ];
    $zahl = fn ($wert, $stellen = 2) => number_format((float) $wert, $stellen, ',', '.');
    $proEinheit = fn (?string $unit) => $unit !== null ? \Illuminate\Support\Str::after($unit, '€/') : null;
    $titelKlein = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $listenKnopf = 'flex w-full items-center gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';

    if ($gp !== null && $section === null) {
        $leadWert = $preisBand['lead'] ?? null;
        $kennzahlen = [
            [
                'label' => 'Preis (Lead)',
                'value' => $leadWert !== null ? $zahl($leadWert) . ' ' . $preisBand['unit'] : (! $gp->requires_la ? 'entfällt' : 'Preis fehlt'),
                'primary' => $leadWert !== null,
                'tone' => $leadWert === null && $gp->requires_la ? 'crit' : null,
                'kpi' => 'lead-preis',
            ],
            [
                'label' => 'Artikel',
                'value' => (string) $gp->n_las_total,
                'tone' => $gp->n_las_total === 0 && $gp->requires_la ? 'warn' : null,
                'kpi' => 'artikel',
            ],
        ];
        if ($allergenKonfidenz !== null) {
            $kennzahlen[] = [
                'label' => 'Allergenangaben',
                'value' => \Platform\FoodAlchemist\Support\Labels::konfidenz($allergenKonfidenz['confidence'] ?? null),
                'tone' => $konfTon[$allergenKonfidenz['confidence'] ?? ''] ?? null,
                'kpi' => 'allergen-konfidenz',
                'title' => ($allergenKonfidenz['quelle'] ?? null) === 'manuell' ? 'Platzhalter: Allergene von Hand gepflegt' : 'Wie gut die Allergene durch Lieferantenartikel belegt sind',
            ];
        }

        // Offene Punkte: was vor der Verwendung im Rezept noch fehlt (nur aus vorhandenen Daten abgeleitet).
        $offen = [];
        if ($gp->requires_la && $gp->n_las_total === 0) {
            $offen[] = ['crit', 'Kein Lieferantenartikel verknüpft, Preis und Allergene fehlen.'];
        } elseif ($gp->requires_la && $leadWert === null) {
            $offen[] = ['crit', 'Lead-Artikel ohne Preis je Einheit, Preis oder Menge im Artikel prüfen.'];
        }
        if (! empty($allergenKonfidenz['needs_review'])) {
            $offen[] = ['crit', 'Artikel widersprechen sich bei den Allergenen: ' . implode(', ', $allergenKonfidenz['konflikt_felder']) . '.'];
        }
        $nUnbekannt = $allergene !== null ? collect($allergene)->filter(fn ($a) => $a['value']->value === 'unbekannt')->count() : 0;
        if ($nUnbekannt > 0) {
            $offen[] = ['warn', $nUnbekannt . ' von 14 Allergenen ohne Angabe.'];
        }
        if ($naehrwerte !== null && $naehrwerte['energy_kcal']['avg'] === null) {
            $offen[] = ['warn', 'Nährwerte fehlen.'];
        }
        if ($gp->preferredCountUnit !== null && $gp->piece_default_g === null) {
            $offen[] = ['warn', 'Stückgewicht fehlt, Umrechnung in Gramm nicht möglich.'];
        }

        $einordnung = implode(' · ', array_filter([$gp->commodity_group?->name ?? $gp->commodity_group_code, $gp->sub_category]));
        $darfLoeschen = $kannKuratieren && ! $gp->is_platzhalter && $referenzen !== null && $referenzen['summe'] === 0;
        $loeschGrund = $referenzen === null || $referenzen['summe'] === 0 ? '' : implode(', ', array_filter([
            $referenzen['las'] > 0 ? $referenzen['las'] . ' Lieferantenartikel (erst ersetzen)' : null,
            $referenzen['rezept_zeilen'] > 0 ? $referenzen['rezept_zeilen'] . ' Rezept-Zeile(n) (erst ersetzen)' : null,
            $referenzen['derivate'] > 0 ? $referenzen['derivate'] . ' Derivat(e)' : null,
            $referenzen['merge_quellen'] > 0 ? $referenzen['merge_quellen'] . ' Merge-Verweis(e)' : null,
            $referenzen['ersatz'] > 0 ? $referenzen['ersatz'] . ' Ersatz-Verknüpfung(en)' : null,
        ]));
    }
@endphp

<div class="{{ $embedded ? 'flex flex-col gap-3' : 'p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]' }}" data-gp-panel>
    @if($gp === null)
        <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Grundprodukt gewählt">Grundprodukt in der Tabelle anklicken, dann erscheinen hier Preis, Artikel und Allergene.</x-fa::empty>
    @else
        {{-- Kopf (Anatomie Detail-Panels): Name, Einordnung, eine Hauptaktion, Weiteres im Menü --}}
        @if($section === null)
        <x-fa::detail-kopf :title="$gp->name" :subtitle="$einordnung !== '' ? $einordnung : null">
            <x-slot:badges>
                <x-fa::status :value="$gp->status" />
                @if($gp->main_ingredient_slug !== null)
                    <span class="inline-flex items-center h-[22px] px-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="Hauptzutat" data-gp-slug>{{ $gp->main_ingredient_slug }}</span>
                @endif
                @if($gp->is_derivat)<x-fa::badge tone="info">Derivat{{ $gp->derivedFrom ? ' von ' . $gp->derivedFrom->name : '' }}</x-fa::badge>@endif
                @if($gp->is_platzhalter)<x-fa::badge>Platzhalter</x-fa::badge>@endif
            </x-slot:badges>
            <x-slot:aktion>
                @if($kannKuratieren)
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Spec 65: Änderungen in der Spalte erst nach „Bearbeiten" (gleiche Sperre wie der Editor), „Fertig" gibt frei --}}
                        <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" sofort />
                        <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-top-right-on-square"
                            wire:click="$dispatch('gp-modal.oeffnen', { id: {{ $gp->id }} })" data-gp-bearbeiten>Im Editor öffnen</x-fa::button>
                    </div>
                @else
                    <x-fa::button variant="primary" size="sm" icon="heroicon-m-arrow-top-right-on-square"
                        wire:click="$dispatch('gp-modal.oeffnen', { id: {{ $gp->id }} })" data-gp-ansehen>Im Editor ansehen</x-fa::button>
                @endif
            </x-slot:aktion>
            <x-slot:menue>
                    <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.gps.dokument', ['id' => $gp->id, 'profil' => 'kalkulation'])" target="_blank"
                        title="Blatt zum Grundprodukt im neuen Fenster" data-gp-panel-druck>Blatt drucken</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-arrow-down-tray" :href="route('foodalchemist.gps.dokument', ['id' => $gp->id, 'profil' => 'kalkulation', 'pdf' => 1])"
                    title="Blatt als PDF herunterladen" data-gp-panel-pdf>PDF herunterladen</x-fa::menu-item>
                @if($darfLoeschen)
                    <x-fa::menu-item danger icon="heroicon-m-trash" wire:click="gpLoeschen" wire:confirm="„{{ $gp->name }}“ löschen? Es gibt keine Verweise darauf." data-gp-loeschen>Grundprodukt löschen</x-fa::menu-item>
                @endif
            </x-slot:menue>
        </x-fa::detail-kopf>
        @unless($kannKuratieren)
            <p class="fa-t-muted" data-gp-geerbt>Schreibgeschütztes Katalog-Grundprodukt. Stammdaten pflegt das Besitzer-Team.</p>
        @endunless
        @endif

        {{-- Spec 65: Sidebar — Änderungen erst nach „Bearbeiten". Eingebettet im GP-Dialog sperrt dessen
             editor-tabs-fieldset (diese Instanz rendert beim Klick auf „Bearbeiten" im Dialog nicht neu). --}}
        @php $lesemodus = ! $embedded && in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
        <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">

        @if($fehler !== null)<x-fa::notice tone="crit" data-la-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis !== null)<x-fa::notice tone="ok" data-hinweis>{{ $hinweis }}</x-fa::notice>@endif

        {{-- Kennzahlen: Preis des Lead-Artikels · Zahl der Artikel · Sicherheit der Allergenangaben --}}
        @if($section === null)
            <div class="flex flex-col gap-3" data-gp-cockpit>
                <x-fa::kpis :items="$kennzahlen" />
                @if($offen !== [])
                    {{-- Offene Punkte: was vor der Verwendung fehlt --}}
                    <div class="flex flex-col gap-1" data-gp-offen>
                        @foreach($offen as [$ton, $text])
                            <x-fa::signal :tone="$ton">{{ $text }}</x-fa::signal>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- KI-Schätzung (Allergene/Nährwerte) zur Prüfung --}}
        @if($kiVorschlag !== null && ($section === null || $section === $kiVorschlag['type']))
            <div class="flex flex-col gap-2 p-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]" data-gp-ki-vorschlag>
                <p class="flex flex-wrap items-center gap-1.5 fa-t-strong">
                    @svg('heroicon-m-sparkles', 'w-4 h-4 text-[var(--fa-accent)]')
                    {{ $kiVorschlag['type'] === 'allergene' ? 'Allergen-Schätzung' : 'Nährwert-Schätzung' }}
                    <span class="font-normal fa-t-muted">{{ round($kiVorschlag['confidence'] * 100) }} % sicher · Übernehmen überschreibt die Werte von Hand</span>
                </p>
                <div class="flex flex-wrap gap-1">
                    @foreach($kiVorschlag['werte'] as $feld => $wert)
                        <x-fa::badge :tone="$kiVorschlag['type'] === 'allergene' ? (['enthalten' => 'crit', 'spuren' => 'warn'][$wert] ?? 'neutral') : 'info'" wire:key="kiw-{{ $feld }}">{{ $feld }}: {{ $wert }}</x-fa::badge>
                    @endforeach
                </div>
                <div class="flex items-center justify-end gap-1.5">
                    <x-fa::button size="sm" variant="ghost" wire:click="kiVerwerfen" data-gp-ki-verwerfen>Verwerfen</x-fa::button>
                    <x-fa::button size="sm" variant="primary" wire:click="kiUebernehmen" data-gp-ki-uebernehmen>Schätzung übernehmen</x-fa::button>
                </div>
            </div>
        @endif

        <div class="flex flex-col">
        {{-- PLATZHALTER: Allergene, Zusatzstoffe & Nährwerte von Hand (Entscheid Dominique 19.11.2026, nur Platzhalter; Dienst lehnt alle anderen ab) --}}
        @if($platzhalterStand !== null)
        <x-fa::section variant="plain" title="Allergene, Zusatzstoffe & Nährwerte (manuell, Platzhalter)" icon="heroicon-o-pencil-square" data-sektion="platzhalter-deklaration">
            <p class="fa-t-muted">Platzhalter haben keine Lieferantenartikel, Werte werden hier gepflegt. Sie gelten in Rezepten wie Artikelwerte.</p>
            @if($kannKuratieren)
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ghost" wire:click="platzhalterAllesFreiVorbelegen" data-platzhalter-alles-frei
                        title="Alle 14 Allergene „nicht enthalten“, keine Zusatzstoffe — z. B. Wasser. Erst Speichern schreibt.">Alles frei vorbelegen</x-fa::button>
                </x-slot:actions>
                @php
                    $phLabels = \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE;
                    $phStoffe = \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE + \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::FREIWILLIG;
                @endphp
                <details class="flex flex-col gap-2" @if(! $platzhalterStand['allergene_vollstaendig']) open @endif data-platzhalter-allergene>
                    <summary class="cursor-pointer {{ $titelKlein }}">Allergene ({{ collect($platzhalterStand['allergene'])->filter()->count() }} von 14 gesetzt)</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1 pt-1">
                        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS as $feld)
                            <label class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]" wire:key="ph-a-{{ $feld }}">
                                <span class="min-w-0 truncate">{{ $phLabels[$feld] ?? $feld }}</span>
                                <x-fa::select size="sm" wire:model="platzhalterForm.allergene.{{ $feld }}" class="w-40" data-platzhalter-allergen="{{ $feld }}">
                                    <option value="">ohne Angabe</option>
                                    <option value="nicht_enthalten">nicht enthalten</option>
                                    <option value="spuren">Spuren</option>
                                    <option value="enthalten">enthalten</option>
                                </x-fa::select>
                            </label>
                        @endforeach
                    </div>
                </details>
                <details class="flex flex-col gap-2" @if(! $platzhalterStand['zusatzstoffe_vollstaendig']) open @endif data-platzhalter-zusatzstoffe>
                    <summary class="cursor-pointer {{ $titelKlein }}">Zusatzstoffe ({{ collect($platzhalterStand['zusatzstoffe'])->filter()->count() }} von {{ count($phStoffe) }} gesetzt)</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1 pt-1">
                        @foreach($phStoffe as $stoff => $stoffLabel)
                            <label class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]" wire:key="ph-z-{{ $stoff }}">
                                <span class="min-w-0 truncate" title="{{ $stoffLabel }}">{{ $stoffLabel }}</span>
                                <x-fa::select size="sm" wire:model="platzhalterForm.zusatzstoffe.{{ $stoff }}" class="w-32" data-platzhalter-zusatz="{{ $stoff }}">
                                    <option value="">ohne Angabe</option>
                                    <option value="nein">nein</option>
                                    <option value="ja">ja</option>
                                </x-fa::select>
                            </label>
                        @endforeach
                    </div>
                </details>
                <div class="flex flex-col gap-1" data-platzhalter-naehrwerte>
                    <p class="{{ $titelKlein }}">Nährwerte je 100 g</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1">
                        @foreach(\Platform\FoodAlchemist\Services\PlatzhalterDeklarationService::NAEHRWERT_LABELS as $key => $naehrLabel)
                            <label class="flex items-center justify-between gap-2 text-[length:var(--fa-text-sm)]" wire:key="ph-n-{{ $key }}">
                                <span class="min-w-0 truncate">{{ $naehrLabel }}</span>
                                <x-fa::input size="sm" inputmode="decimal" wire:model="platzhalterForm.naehrwerte.{{ $key }}" class="w-24" placeholder="–" data-platzhalter-naehrwert="{{ $key }}" />
                            </label>
                        @endforeach
                    </div>
                </div>
                @if($platzhalterFehler)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" data-platzhalter-fehler>{{ $platzhalterFehler }}</p>@endif
                <div><x-fa::button size="sm" variant="primary" wire:click="platzhalterDeklarationSpeichern" data-platzhalter-speichern>Speichern</x-fa::button></div>
            @endif
        </x-fa::section>
        @endif

        {{-- Deklaration --}}
        {{-- ALLERGENE (effektiv) --}}
        @if($section === null || $section === 'allergene')
        @php
            $allergKiConf = $gp->allergens_confidence;
            $platzhalterManuell = ($allergenKonfidenz['quelle'] ?? null) === 'manuell';   // Platzhalter, 14 Allergene von Hand (19.11.2026)
            $nurKiOverride = ! $platzhalterManuell && $allergene !== null && ($allergenKonfidenz['n_las_mit_daten'] ?? 0) === 0 && ($gp->allergens_source === 'ki' || $gp->allergens_confidence !== null) && collect($allergene)->contains(fn ($a) => $a['source'] === 'override');
        @endphp
        <x-fa::section variant="plain" title="Allergene" icon="heroicon-o-shield-exclamation" data-sektion="allergene">
            {{-- Dominique 2026-10-13: kein KI-Schätzknopf für Allergene — sie kommen ausschließlich aus den Lieferantenartikeln
                 (Kaskade LA → GP → Rezept). Fehlen Artikeldaten, ist das eine Lücke zum Pflegen, keine Schätzaufgabe. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[length:var(--fa-text-sm)]">
                @if($nurKiOverride)
                    <x-fa::badge tone="accent" icon="heroicon-m-sparkles" title="Von der KI geschätzt, keine Artikeldaten{{ $allergKiConf !== null ? ' · ' . round($allergKiConf * 100) . ' %' : '' }}" data-allergene-ki-marker>KI</x-fa::badge>
                    <span class="text-[var(--fa-ink-3)]">aus 0/{{ $gp->n_las_total }} Artikeln</span>
                @elseif($platzhalterManuell)
                    <x-fa::signal tone="ok" data-allergen-konfidenz="high" data-allergene-platzhalter-manuell>Allergene von Hand gepflegt (Platzhalter)</x-fa::signal>
                @elseif($allergenKonfidenz !== null)
                    <x-fa::signal :tone="$konfTon[$allergenKonfidenz['confidence']] ?? 'warn'" data-allergen-konfidenz="{{ $allergenKonfidenz['confidence'] }}">{{ $konfSatz[$allergenKonfidenz['confidence']] ?? $konfSatz['none'] }}</x-fa::signal>
                    <span class="text-[var(--fa-ink-3)]">Belegt: {{ \Platform\FoodAlchemist\Support\Labels::konfidenz($allergenKonfidenz['confidence']) }}, aus {{ $allergenKonfidenz['n_las_mit_daten'] }}/{{ $gp->n_las_total }} Artikeln</span>
                    @if($allergenKonfidenz['needs_review'])<x-fa::badge tone="crit" title="Artikel widersprechen sich (enthalten gegen nicht enthalten): {{ implode(', ', $allergenKonfidenz['konflikt_felder']) }}">Prüfen nötig</x-fa::badge>@endif
                @endif
            </div>
            @if($allergene !== null)
                @php
                    $labels = \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE;
                    $marker = fn ($q) => $q === 'override' ? ' (von Hand)' : ($q === 'mutter' ? ' (geerbt)' : '');
                    $herkunft = fn ($q) => $q === 'override' ? ' · von Hand gesetzt' : ($q === 'mutter' ? ' · vom Ausgangsprodukt geerbt' : '');
                    $enthalten = collect($allergene)->filter(fn ($a) => $a['value']->value === 'enthalten');
                    $spuren = collect($allergene)->filter(fn ($a) => $a['value']->value === 'spuren');
                    $unbekannt = collect($allergene)->filter(fn ($a) => $a['value']->value === 'unbekannt');
                    $freiAnzahl = collect($allergene)->filter(fn ($a) => $a['value']->value === 'nicht_enthalten')->count();
                    // Spec 85: Positiv-Lieferant — „nicht enthalten“ nur angenommen (Lieferant liefert nur Positivangaben)
                    $angenommenAnzahl = collect($allergene)->filter(fn ($a) => $a['source'] === \Platform\FoodAlchemist\Support\AllergenAnnahme::QUELLE)->count();
                    $angenommenSatz = $angenommenAnzahl > 0 ? 'davon ' . $angenommenAnzahl . ' ' . \Platform\FoodAlchemist\Support\AllergenAnnahme::ANZEIGE : null;   // Dominique 23.10.
                @endphp
                @if($enthalten->isEmpty() && $spuren->isEmpty() && $unbekannt->isEmpty())
                    <p class="flex flex-wrap items-center gap-2" data-allergene-frei>
                        <x-fa::signal tone="ok">Keines der 14 Hauptallergene deklariert</x-fa::signal>
                        @if($angenommenSatz !== null)<span class="fa-t-muted" data-allergene-angenommen title="{{ \Platform\FoodAlchemist\Support\AllergenAnnahme::ERKLAERUNG }}">{{ $angenommenSatz }}</span>@endif
                        @if($nurKiOverride)<x-fa::signal tone="info" icon="heroicon-m-sparkles" title="Schätzung ohne Beleg durch Lieferantenartikel. Für die Deklaration Artikeldaten ergänzen.">KI-geschätzt, nicht belegt</x-fa::signal>@endif
                    </p>
                @else
                    <div class="flex flex-col gap-2" data-allergen-grid>
                        @if($enthalten->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1.5"><span class="w-[84px] shrink-0 {{ $titelKlein }}">Enthält</span>
                                @foreach($enthalten as $feld => $a)<x-fa::badge tone="crit" title="{{ $labels[$feld] ?? $feld }}{{ $herkunft($a['source']) }}">{{ $labels[$feld] ?? $feld }}{{ $marker($a['source']) }}</x-fa::badge>@endforeach
                            </div>
                        @endif
                        @if($spuren->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1.5"><span class="w-[84px] shrink-0 {{ $titelKlein }}">Spuren von</span>
                                @foreach($spuren as $feld => $a)<x-fa::badge tone="warn" title="{{ $labels[$feld] ?? $feld }}{{ $herkunft($a['source']) }}">{{ $labels[$feld] ?? $feld }}{{ $marker($a['source']) }}</x-fa::badge>@endforeach
                            </div>
                        @endif
                        @if($unbekannt->isNotEmpty())
                            <x-fa::signal tone="warn" data-allergene-unbekannt title="Ohne Angabe im Artikel. Unbekannt heißt nicht frei von.">{{ $unbekannt->count() }} von 14 ohne Angabe (unbekannt)</x-fa::signal>
                        @endif
                        @if($freiAnzahl > 0)<p class="fa-t-muted">Frei von den übrigen {{ $freiAnzahl }} {{ $freiAnzahl === 1 ? 'Hauptallergen' : 'Hauptallergenen' }}@if($angenommenSatz !== null)<span data-allergene-angenommen title="{{ \Platform\FoodAlchemist\Support\AllergenAnnahme::ERKLAERUNG }}">, {{ $angenommenSatz }}</span>@endif</p>@endif
                    </div>
                @endif
            @endif
        </x-fa::section>
        @endif

        {{-- ZUSATZSTOFFE --}}
        @if($section === null || $section === 'zusatzstoffe')
        <x-fa::section variant="plain" title="Zusatzstoffe" icon="heroicon-o-beaker" :meta="$gp->is_platzhalter ? 'von Hand (Platzhalter)' : 'aus den Artikeln'" data-sektion="zusatzstoffe">
            @if($zusatzstoffe !== null)
                @php
                    $stoffLabels = \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE;
                    $zsJa = collect($zusatzstoffe)->filter(fn ($v) => $v === 3);
                    $zsUnbekannt = collect($zusatzstoffe)->filter(fn ($v) => $v === 0 || $v === null);
                    $zsFrei = collect($zusatzstoffe)->filter(fn ($v) => $v === 1)->count();
                @endphp
                @if($zsJa->isEmpty() && $zsUnbekannt->isEmpty())
                    <x-fa::signal tone="ok" data-zusatz-frei>Keine Zusatzstoffe deklariert</x-fa::signal>
                @else
                    <div class="flex flex-col gap-2">
                        @if($zsJa->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1.5" data-zusatz-ja><span class="w-[84px] shrink-0 {{ $titelKlein }}">Enthält</span>
                                @foreach($zsJa as $stoff => $v)<x-fa::badge tone="warn">{{ $stoffLabels[$stoff] ?? $stoff }}</x-fa::badge>@endforeach
                            </div>
                        @endif
                        @if($zsUnbekannt->isNotEmpty())<x-fa::signal tone="warn" data-zusatz-unbekannt title="Ohne Angabe im Artikel. Nicht als frei werten.">{{ $zsUnbekannt->count() }} ohne Angabe</x-fa::signal>@endif
                        @if($zsFrei > 0)<p class="fa-t-muted">Frei von {{ $zsFrei }} weiteren</p>@endif
                    </div>
                @endif
            @endif
        </x-fa::section>
        @endif

        {{-- Eigenschaften: zutreffende zuerst, Rest als Text --}}
        @if($section === null)
        @php
            $tagJa = $tagNein = $tagOffen = [];
            foreach (\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag) {
                $wert = $gp->getAttribute("tag_{$tag}");
                $text = $tagLabels[$tag] ?? str_replace('_', ' ', $tag);
                if ($wert === true) { $tagJa[$tag] = $text; } elseif ($wert === false) { $tagNein[$tag] = $text; } else { $tagOffen[$tag] = $text; }
            }
        @endphp
        <x-fa::section variant="plain" title="Eigenschaften" icon="heroicon-o-tag" data-tags>
            @if($tagJa !== [])
                <div class="flex flex-wrap gap-1.5">
                    @foreach($tagJa as $tag => $text)<x-fa::badge tone="ok" icon="heroicon-m-check" title="trifft zu">{{ $text }}</x-fa::badge>@endforeach
                </div>
            @endif
            @if($tagNein !== [])<p class="fa-t-muted"><span class="font-medium text-[var(--fa-ink-2)]">Nicht:</span> {{ implode(' · ', $tagNein) }}</p>@endif
            @if($tagOffen !== [])<p class="fa-t-muted" data-tags-offen><span class="font-medium text-[var(--fa-ink-2)]">Noch nicht bewertet ({{ count($tagOffen) }} von {{ count(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS) }}):</span> {{ implode(' · ', $tagOffen) }}</p>@endif
        </x-fa::section>
        @endif

        {{-- Inhalt --}}
        {{-- LIEFERANTENARTIKEL --}}
        @if($section === null || $section === 'las')
        <x-fa::section variant="plain" title="Lieferantenartikel" icon="heroicon-o-building-storefront" :meta="$gp->n_las_total" data-sektion="las">
            @if($kannKuratieren)
                <x-slot:actions>
                    <x-fa::ki-action action="laVorschlaege" variant="ai" icon="heroicon-o-sparkles" label="Artikel vorschlagen"
                        title="Nicht verknüpfte Artikel finden, die zum Namen passen" data-la-ki-vorschlag
                        busy="Wird gesucht …" flash="Vorschläge da" />
                </x-slot:actions>
            @endif

            @if(($preisBand ?? null) !== null && $preisBand['max'] > 0)
                <div class="fa-surface flex flex-col gap-2 px-3 py-2.5" data-preis-band>
                    <div class="flex items-baseline justify-between gap-2 text-[length:var(--fa-text-sm)]">
                        <span class="text-[var(--fa-ink-2)]">Preisspanne <span class="text-[var(--fa-ink-3)]">aus {{ $preisBand['n'] }} {{ $preisBand['n'] === 1 ? 'Artikel' : 'Artikeln' }}</span></span>
                        <span class="tabular-nums text-[var(--fa-ink)]">{{ $zahl($preisBand['min']) }} bis {{ $zahl($preisBand['max']) }} {{ $preisBand['unit'] }}</span>
                    </div>
                    @if($preisBand['lead'] !== null && $preisBand['max'] > $preisBand['min'])
                        @php
                            $pos = min(100, max(0, ($preisBand['lead'] - $preisBand['min']) / ($preisBand['max'] - $preisBand['min']) * 100));
                        @endphp
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1 h-1.5 rounded-full bg-[var(--fa-line-strong)]" role="img" aria-label="Lead liegt bei {{ round($pos) }} Prozent der Spanne">
                                <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 w-3 h-3 rounded-full bg-[var(--fa-accent)] ring-2 ring-[var(--fa-surface)]" style="left: {{ round($pos) }}%" title="Lead in der Preisspanne"></span>
                            </div>
                            <span class="shrink-0 text-[length:var(--fa-text-sm)] font-medium tabular-nums text-[var(--fa-accent)]">Lead {{ $zahl($preisBand['lead']) }}</span>
                        </div>
                    @endif
                </div>
            @endif

            @if(($leadSteuerung ?? null) !== null && $gp->n_las_total > 0)
                <div class="flex flex-col gap-1.5 text-[length:var(--fa-text-sm)]" data-lead-steuerung>
                    @if($leadSteuerung['override_reason'])
                        <p class="text-[var(--fa-ink-2)]" data-lead-reason>Lead von Hand gesetzt. Grund: <span class="text-[var(--fa-ink)]">{{ $leadSteuerung['override_reason'] }}</span></p>
                    @elseif($leadSteuerung['lead_gesetzt_la_id'] === null && $leadSteuerung['vorschlag_la_id'] !== null)
                        <p class="text-[var(--fa-ink-3)]">Lead automatisch gewählt (günstigster oder Stammlieferant).</p>
                    @endif
                    @if(count($leadSteuerung['ausweichquellen']) > 0)
                        <p class="text-[var(--fa-ink-3)]" data-ausweichquellen>{{ count($leadSteuerung['ausweichquellen']) }} {{ count($leadSteuerung['ausweichquellen']) === 1 ? 'Ausweichquelle' : 'Ausweichquellen' }}: {{ collect($leadSteuerung['ausweichquellen'])->pluck('supplier')->filter()->take(3)->implode(', ') ?: '–' }}</p>
                    @endif
                    @if($kannKuratieren)
                        <div {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('gp.lead-grund') }}>
                            <x-fa::input size="sm" wire:model="leadReason" maxlength="255" placeholder="Grund für einen Lead von Hand (optional)" aria-label="Grund für einen Lead von Hand" data-lead-reason-input />
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex flex-col gap-1.5">
                @forelse($kette ?? [] as $rang => $la)
                    @php
                        $istLead = $la->id === $effektiverLeadId;
                    @endphp
                    <div wire:key="la-{{ $la->id }}" class="group rounded-[var(--fa-radius-control)] border px-2.5 py-2 {{ $istLead ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent-line)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }} {{ $la->locked ? 'opacity-60' : '' }}" data-la-zeile="{{ $la->id }}">
                        <div class="flex items-start gap-2">
                            <button type="button" wire:click="leadSetzen({{ $la->id }})"
                                    class="shrink-0 mt-px transition-colors {{ $istLead ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)]' }}"
                                    aria-label="{{ $istLead ? 'Lead-Artikel' : 'Als Lead setzen' }}"
                                    title="{{ $istLead ? 'Lead-Artikel für dein Team' : 'Als Lead setzen (nur Kurations-Team)' }}" data-lead-stern {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('gp.lead-setzen') }}>
                                @svg($istLead ? 'heroicon-s-star' : 'heroicon-o-star', 'w-[18px] h-[18px]')
                            </button>
                            <div class="min-w-0 flex-1 cursor-pointer" @if($handyArtikelLink) x-on:click="Livewire.navigate('{{ route('foodalchemist.suppliers.index', ['artikel' => $la->id]) }}')" title="Artikel ansehen" @else wire:click="$dispatch('item-modal.oeffnen', { id: {{ $la->id }} })" title="Artikel öffnen, Allergene und Preise dort pflegen" @endif data-la-oeffnen>
                                <p class="fa-t-strong leading-snug hover:text-[var(--fa-accent)] hover:underline">{{ $la->designation }}</p>
                                <p class="mt-0.5 fa-t-muted">{{ $la->supplier_name ?? 'Lieferant unbekannt' }}@if($la->qty !== null && $la->unit_code !== null) · <x-fa::menge :value="$la->qty" :unit="$la->unit_code" />@endif @if(filled($la->article_number)) · <span data-la-artnr>Art.-Nr. {{ $la->article_number }}</span>@endif</p>
                                @if($gp->lead_la_supplier_item_id === $la->id || $la->ist_stamm || $la->is_discontinued || $la->gepinnt || $la->locked || ($la->lieferant_inaktiv ?? false))
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if($gp->lead_la_supplier_item_id === $la->id)<x-fa::badge tone="accent" title="Lead für alle Teams">Lead global</x-fa::badge>@endif
                                        @if($la->ist_stamm)<x-fa::badge>Stammlieferant</x-fa::badge>@endif
                                        @if($la->is_discontinued)<x-fa::badge tone="crit">ausgelistet</x-fa::badge>@endif
                                        @if($la->lieferant_inaktiv ?? false)<x-fa::badge tone="warn" title="Lieferant inaktiv oder gesperrt: nur Ausweich, nie Lead, solange ein aktiver Lieferant liefert" data-lieferant-inaktiv>Lieferant inaktiv</x-fa::badge>@endif
                                        @if($la->gepinnt)<x-fa::badge tone="info">angeheftet</x-fa::badge>@endif
                                        @if($la->locked)<x-fa::badge tone="crit">gesperrt</x-fa::badge>@endif
                                    </div>
                                @endif
                            </div>
                            <div class="shrink-0 text-right" data-la-preis>
                                @if($la->aktiver_preis !== null || ($la->hat_letzten_preis ?? false))
                                    @if($la->aktiver_preis === null)
                                        {{-- Rückfall 27.10.: letzter bekannter Preis eines eingestellten Artikels, immer gekennzeichnet --}}
                                        @php $lpk = \Platform\FoodAlchemist\Services\PriceService::letzterPreisKennzeichen($la->letzter_preis_ab, $la->lieferstatus_seit); @endphp
                                        <x-fa::badge :tone="$lpk['warnung'] ? 'warn' : 'neutral'" title="{{ $lpk['text'] }}" data-letzter-preis>letzter Preis</x-fa::badge>
                                    @endif
                                    <p class="flex items-center justify-end gap-1 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
                                        <x-fa::money :value="$la->aktiver_preis ?? $la->preis_effektiv" />
                                        @isset($preisTrend[$la->id])
                                            @if($preisTrend[$la->id]['plausibel'])
                                                @php
                                                    $d = $preisTrend[$la->id]['delta_pct'];
                                                @endphp
                                                <span class="inline-flex items-center text-[length:var(--fa-text-sm)] font-medium tabular-nums {{ $d > 0 ? 'text-[var(--fa-crit)]' : ($d < 0 ? 'text-[var(--fa-ok)]' : 'text-[var(--fa-ink-3)]') }}" title="vorher {{ $zahl($preisTrend[$la->id]['vorher']) }} €">@if($d > 0)@svg('heroicon-m-arrow-up', 'w-3.5 h-3.5')@elseif($d < 0)@svg('heroicon-m-arrow-down', 'w-3.5 h-3.5')@endif{{ $zahl(abs($d), 1) }} %</span>
                                            @else
                                                <span class="text-[var(--fa-warn)] cursor-help" title="Vorpreis unplausibel, Preisverlauf prüfen" data-preis-trend-warnung>@svg('heroicon-m-exclamation-triangle', 'w-4 h-4')</span>
                                            @endif
                                        @endisset
                                    </p>
                                    @if($la->vergleichspreis !== null)
                                        <p class="fa-t-muted"><x-fa::money :value="$la->vergleichspreis['value']" :per="$proEinheit($la->vergleichspreis['unit'])" /></p>
                                    @else
                                        <x-fa::signal tone="warn" title="Ohne Menge kein Preis je Kilo oder Liter">ohne Preis je kg</x-fa::signal>
                                    @endif
                                @else
                                    <x-fa::money :value="null" />
                                @endif
                            </div>
                        </div>
                        <div class="hidden group-hover:flex group-focus-within:flex flex-wrap items-center gap-1 mt-2 ml-7" data-la-aktionen {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('gp.artikel-aktionen') }}>
                            <x-fa::button size="sm" variant="ghost" wire:click="pinToggle({{ $la->id }}, {{ $la->gepinnt ? 'false' : 'true' }})">{{ $la->gepinnt ? 'Nicht mehr anheften' : 'Anheften' }}</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" wire:click="sperreToggle({{ $la->id }}, {{ $la->locked ? 'false' : 'true' }})">{{ $la->locked ? 'Entsperren' : 'Sperren' }}</x-fa::button>
                            @if($kannKuratieren)
                                <x-fa::button size="sm" variant="danger" class="ml-auto" wire:click="loesen({{ $la->id }})" wire:confirm="Artikel vom Grundprodukt lösen? War er Lead, wird sofort ein neuer gewählt.">Verknüpfung lösen</x-fa::button>
                            @endif
                        </div>
                    </div>
                @empty
                    @if(! $gp->requires_la)
                        <p class="fa-t-muted">Braucht keinen Lieferantenartikel.</p>
                    @else
                        <x-fa::empty compact icon="heroicon-o-building-storefront" title="Noch kein Lieferantenartikel verknüpft">Ohne Artikel fehlen Preis und Allergene. Unten nach der Bezeichnung suchen oder Artikel vorschlagen lassen.</x-fa::empty>
                    @endif
                @endforelse

                @if($laKandidaten !== null)
                    <div class="flex flex-col gap-1 p-2.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]" data-la-kandidaten {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('gp.artikel-verknuepfen') }}>
                        <p class="flex items-center gap-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink)]">@svg('heroicon-m-sparkles', 'w-4 h-4 text-[var(--fa-accent)]') Passende Artikel ohne Zuordnung <span class="font-normal text-[var(--fa-ink-3)]">Klick verknüpft</span></p>
                        @foreach($laKandidaten as $kandidat)
                            <button type="button" wire:key="lakand-{{ $kandidat['id'] }}" wire:click="verknuepfe({{ $kandidat['id'] }})" class="{{ $listenKnopf }} hover:bg-[var(--fa-surface)]">
                                <span class="min-w-0 flex-1">{{ $kandidat['designation'] }} <span class="text-[var(--fa-ink-3)]">· {{ $kandidat['supplier'] ?? 'Lieferant unbekannt' }}</span></span>
                                <span class="shrink-0 tabular-nums text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]">{{ round($kandidat['score'] * 100) }} %</span>
                            </button>
                        @endforeach
                        <div><x-fa::button size="sm" variant="ghost" wire:click="laVorschlaegeVerwerfen" data-la-kandidaten-verwerfen>Vorschläge verwerfen</x-fa::button></div>
                    </div>
                @endif

                @if($kannKuratieren)
                    <div class="flex flex-col gap-1 pt-1" data-la-verknuepfen {{ \Platform\FoodAlchemist\Support\HandyLesemodus::attr('gp.artikel-verknuepfen') }}>
                        <div class="relative">
                            @svg('heroicon-m-plus', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input size="sm" type="search" wire:model.live.debounce.300ms="laSuche" placeholder="Artikel verknüpfen: Bezeichnung, Artikelnummer oder Marke" aria-label="Lieferantenartikel suchen und verknüpfen" class="pl-8" />
                        </div>
                        @foreach($verknuepfSuche['treffer'] as $kandidat)
                            <button type="button" wire:key="vk-{{ $kandidat->id }}" wire:click="verknuepfe({{ $kandidat->id }})" class="{{ $listenKnopf }}" data-la-treffer="{{ $kandidat->id }}">{{ $kandidat->designation }} <span class="text-[var(--fa-ink-3)]">· {{ $kandidat->supplier_name ?? 'Lieferant unbekannt' }}@if($kandidat->gebinde_text) · {{ $kandidat->gebinde_text }}@endif · {{ $kandidat->aktiver_preis !== null ? $zahl($kandidat->aktiver_preis) . ' €' : 'kein Preis' }}</span></button>
                        @endforeach
                        @include('foodalchemist::livewire.gps.partials.verknuepf-suche-hinweis', ['suche' => $verknuepfSuche])
                        @if($laSuche !== '' && $verknuepfSuche['treffer']->isEmpty())
                            <p class="px-2 py-1 fa-t-muted" data-la-suche-leer>Kein freier Artikel zu „{{ $laSuche }}". Ein Artikel gehört zu genau einem Grundprodukt, vielleicht ist er schon zugeordnet.</p>
                        @endif
                    </div>
                @endif
            </div>
        </x-fa::section>
        {{-- Preisverlauf als Linie: eine Linie je Lieferantenartikel, Lead hervorgehoben (Detail UND GP-Modal, das dieses Panel einbettet) --}}
        @if($gp->n_las_total > 0)
            <x-fa::section variant="plain" title="Preisverlauf" icon="heroicon-o-presentation-chart-line" data-sektion="preisverlauf">
                <livewire:foodalchemist.preise.verlauf modus="gp" :objekt-id="$gp->id" :key="'pv-gp-'.$gp->id" />
            </x-fa::section>
        @endif
        @endif

        {{-- Fachabschnitte --}}
        {{-- Stammdaten --}}
        @if($section === null)
        <x-fa::section variant="plain" title="Stammdaten" icon="heroicon-o-information-circle" data-stammdaten>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                @foreach([
                    ['Warengruppe', $gp->commodity_group?->name ?? $gp->commodity_group_code],
                    ['Unterkategorie', $gp->sub_category],
                    ['Zustand', $gp->condition],
                    ['Garverlust', $gp->cooking_loss_default_pct !== null ? $zahl($gp->cooking_loss_default_pct, 1) . ' %' : null],
                ] as [$lbl, $wert])
                    <div class="min-w-0">
                        <dt class="{{ $titelKlein }}">{{ $lbl }}</dt>
                        <dd class="text-[length:var(--fa-text-md)] {{ $wert !== null ? 'text-[var(--fa-ink)]' : 'italic text-[var(--fa-ink-3)]' }}">{{ $wert ?? 'nicht gepflegt' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-fa::section>
        @endif

        {{-- Natürliche Einheit & Gewicht (auch in der Nährwerte-Kartei) --}}
        @if($section === null || $section === 'naehrwerte')
        <x-fa::section variant="plain" title="Natürliche Einheit und Gewicht" icon="heroicon-o-cube" data-unit-gewicht>
            @if($gp->preferredCountUnit !== null || $gp->piece_default_g !== null)
                <p class="fa-t-body">1 {{ $gp->preferredCountUnit?->name ?? 'Stück' }}
                    @if($gp->piece_default_g !== null)≈ <span class="font-semibold tabular-nums">{{ $zahl($gp->piece_default_g, 0) }} g</span>
                    @else<span class="text-[var(--fa-warn)]">, Gewicht fehlt</span>@endif
                </p>
            @else
                <p class="text-[length:var(--fa-text-md)] italic text-[var(--fa-ink-3)]">Keine Zähleinheit hinterlegt</p>
            @endif
        </x-fa::section>
        @endif

        {{-- NÄHRWERTE --}}
        @if($section === null || $section === 'naehrwerte')
        <x-fa::section variant="plain" title="Nährwerte" icon="heroicon-o-chart-bar"
            :meta="($naehrwerte['source'] ?? 'la') === 'la' ? 'je 100 g, Mittel der Artikel' : (($naehrwerte['source'] ?? '') === 'ki' ? 'je 100 g, KI-Schätzung' : (($naehrwerte['source'] ?? '') === 'manual' ? 'je 100 g, von Hand' : 'je 100 g'))" data-sektion="naehrwerte">
            @if($kannKuratieren && $naehrwerte !== null && $naehrwerte['energy_kcal']['avg'] === null)
                <x-slot:actions>
                    <x-fa::ki-action action="kiNaehrwerte" variant="ai" icon="heroicon-o-sparkles" label="Mit KI schätzen"
                        title="Ohne Artikeldaten per KI schätzen" data-ki-naehrwerte
                        busy="Wird geschätzt …" flash="Geschätzt" />
                </x-slot:actions>
            @endif
            @if(($naehrwerte['source'] ?? null) === 'ki')
                <div><x-fa::badge tone="accent" icon="heroicon-m-sparkles" title="Von der KI geschätzt, keine Artikeldaten{{ $gp->nutri_ai_confidence !== null ? ' · ' . round($gp->nutri_ai_confidence * 100) . ' %' : '' }}" data-naehrwerte-ki-marker>KI-geschätzt</x-fa::badge></div>
            @endif
            @if($naehrwerte !== null && $naehrwerte['energy_kcal']['avg'] !== null)
                <table class="fa-table fa-table--compact">
                    <caption class="sr-only">Nährwerte je 100 g</caption>
                    <tbody>
                        @foreach([
                            ['energy_kcal', 'Energie', 'kcal', 1, false], ['protein', 'Eiweiß', 'g', 2, false], ['fat', 'Fett', 'g', 2, false],
                            ['saturated_fat', 'davon gesättigte Fettsäuren', 'g', 2, true], ['carbs_absorbable', 'Kohlenhydrate', 'g', 2, false],
                            ['sugar', 'davon Zucker', 'g', 2, true], ['salt_g', 'Salz', 'g', 3, false],
                        ] as [$key, $label, $unit, $stellen, $eingerueckt])
                            <tr>
                                <td class="{{ $eingerueckt ? 'pl-6 text-[var(--fa-ink-2)]' : '' }}">{{ $label }}</td>
                                <td class="num">
                                    @if($naehrwerte[$key]['avg'] !== null)<x-fa::menge :value="$naehrwerte[$key]['avg']" :unit="$unit" :decimals="$stellen" />
                                        <span class="ml-1 fa-t-muted" title="Median aus {{ $naehrwerte[$key]['n'] }} {{ $naehrwerte[$key]['n'] === 1 ? 'Artikel' : 'Artikeln' }}">({{ $naehrwerte[$key]['n'] }} Artikel)</span>
                                    @else<span class="text-[var(--fa-ink-3)]">–</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="fa-t-muted" data-naehrwerte-leer>Keine Nährwerte in den Artikeln.</p>
            @endif
        </x-fa::section>
        @endif

        {{-- 27.10.2026 (Dominique): Geschmacksprofil + KI-Knöpfe auch hier, nicht nur im GP-Dialog. Derselbe Weg (HatGpAromaKi). --}}
        @if($section === null && $gp !== null)
        <x-fa::section variant="plain" title="Geschmack" icon="heroicon-o-beaker" data-gp-panel-geschmack>
            <x-slot:actions>
                @if($kannKuratieren)
                    <x-fa::ki-action action="sensorikKiSchaetzen" variant="ai" icon="heroicon-o-sparkles" label="Mit KI schätzen"
                        title="Schätzt sauer, bitter, umami, scharf und die Achsen ohne Messwert. Vorschau, gespeichert wird erst mit Übernehmen."
                        data-gp-sensorik-ki busy="Wird geschätzt …" />
                @endif
            </x-slot:actions>
            @include('foodalchemist::livewire.gps.partials.aroma-vorschau')
            {{-- Dominique 27.10.: im GP-Detail nur das Radar (Erklärtext, Messwerte, dominant, Textur gehören in den GP-Dialog) --}}
            @include('foodalchemist::livewire.concepter.partials.sensorik', ['nurRadar' => true])
        </x-fa::section>
        @endif

        {{-- Spec 53 Paket J: Aroma-Anker als Chip, Klick zeigt weitere Grundprodukte mit diesem Anker.
             Seit 27.10.2026 immer sichtbar (auch ohne Anker) mit „Anker vorschlagen" (Geschwister zuerst, dann KI). --}}
        @if($section === null && $gp !== null)
        <x-fa::section variant="plain" title="Aroma-Anker" icon="heroicon-o-sparkles" data-gp-panel-anker>
            <x-slot:actions>
                <x-fa::ki-action action="ankerKiVorschlagen" variant="ai" icon="heroicon-o-sparkles" label="Anker vorschlagen"
                    title="Übernimmt die Anker einiger Grundprodukte derselben Hauptzutat, sonst bestimmt die KI den Haupt-Anker aus dem Vokabular"
                    data-gp-anker-ki busy="Wird bestimmt …" flash="Anker gesetzt" />
            </x-slot:actions>
            @if($gpAnker->isEmpty())
                <p class="fa-t-muted" data-gp-anker-leer>Noch kein Aroma-Anker.</p>
            @endif
            <div class="flex flex-wrap gap-1.5">
                @foreach($gpAnker as $a)
                    <a href="#" role="button" wire:key="pa-{{ $a->id }}" wire:click.prevent="ankerNetzUmschalten({{ $a->id }})"
                            aria-pressed="{{ $ankerNetzOffenId === $a->id ? 'true' : 'false' }}"
                            class="inline-flex items-center gap-1 h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $ankerNetzOffenId === $a->id ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : ($a->role === 'kern' ? 'border-[var(--fa-accent-line)] text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)]') }}"
                            title="{{ $a->role === 'kern' ? 'Kern-Aroma' : 'Begleit-Aroma' }} · weitere Grundprodukte mit diesem Anker zeigen" data-gp-anker-chip>
                        @if($a->role === 'kern')@svg('heroicon-s-star', 'w-3.5 h-3.5')@endif{{ $a->display_de }}
                    </a>{{-- Spec 65: <a> statt <button> — bleibt im gesperrten Lesemodus (fieldset) bedienbar --}}
                @endforeach
            </div>
            @if($ankerNetzOffenId !== null)
                <div class="fa-surface flex flex-col gap-1.5 px-3 py-2.5" data-gp-anker-netz>
                    @if($ankerNetz->isEmpty())
                        <p class="fa-t-muted">Kein weiteres Grundprodukt trägt diesen Anker als Kern.</p>
                    @else
                        <p class="{{ $titelKlein }}">Weitere Grundprodukte mit diesem Anker</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($ankerNetz as $verwandt)
                                <a href="{{ route('foodalchemist.gps.index', ['gp' => $verwandt->id]) }}" wire:navigate
                                   class="inline-flex items-center h-7 px-2.5 rounded-full border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] hover:border-[var(--fa-accent)] hover:text-[var(--fa-accent)]">{{ $verwandt->name }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </x-fa::section>
        @endif

        {{-- ERSATZ --}}
        @if($section === null || $section === 'ersatz')
        <x-fa::section variant="plain" title="Ersatzprodukte" icon="heroicon-o-scale" meta="selbst machen oder kaufen" data-sektion="ersatz">
            @if($kannKuratieren)
                <x-slot:actions>
                    <x-fa::ki-action action="ersatzKiRelevant" variant="ai" icon="heroicon-o-sparkles" label="Ersatz vorschlagen"
                        data-ersatz-ki-relevant busy="Prüft …" flash="Geprüft" />
                </x-slot:actions>
            @endif
            <div class="flex flex-col gap-1">
                @forelse($ersatz as $e)
                    <div class="flex items-center gap-2 py-0.5 text-[length:var(--fa-text-md)]" wire:key="equiv-{{ $e->id }}">
                        <x-fa::badge :tone="$e->gegen_kind === 'recipe' ? 'info' : 'neutral'" class="shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'Grundprodukt' }}</x-fa::badge>
                        <span class="min-w-0 flex-1 text-[var(--fa-ink)]">{{ $e->gegen_name }}</span>
                        @if((float) $e->umrechnungsfaktor !== 1.0)<span class="shrink-0 tabular-nums fa-t-muted" title="Umrechnungsfaktor">×{{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                        @if($kannKuratieren)<x-fa::icon-button size="sm" tone="danger" icon="heroicon-m-x-mark" label="Ersatz-Verknüpfung lösen" wire:click="ersatzLoesen({{ $e->id }})" />@endif
                    </div>
                @empty
                    <p class="fa-t-muted" data-ersatz-leer>Kein Ersatz hinterlegt. Unten suchen oder vorschlagen lassen.</p>
                @endforelse
                @if($ersatzKiVorschlaege !== null)
                    <div class="flex flex-col gap-1.5 p-2.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]" data-ersatz-ki-vorschlaege>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink)]">Passende Alternativen aus dem Bestand</span>
                            <x-fa::button size="sm" variant="ghost" wire:click="ersatzKiVerwerfen">Verwerfen</x-fa::button>
                        </div>
                        @foreach($ersatzKiVorschlaege as $k)
                            <div class="flex items-start gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-surface)]" wire:key="ersatz-ki-{{ $k['kind'] }}-{{ $k['id'] }}" data-ersatz-ki-zeile>
                                <x-fa::badge :tone="$k['kind'] === 'recipe' ? 'info' : ($k['kind'] === 'supplier_item' ? 'warn' : 'neutral')" class="shrink-0">
                                    {{ $k['kind'] === 'recipe' ? 'Rezept' : ($k['kind'] === 'supplier_item' ? 'Artikel ohne Grundprodukt' : 'Grundprodukt') }}
                                </x-fa::badge>
                                <div class="min-w-0 flex-1">
                                    <p class="fa-t-strong">{{ $k['name'] }}</p>
                                    <p class="fa-t-muted">{{ $k['reason'] }}@if($k['supplier']) · {{ $k['supplier'] }}@endif · {{ round($k['score'] * 100) }} %</p>
                                </div>
                                @if($k['kind'] === 'supplier_item')
                                    <x-fa::button size="sm" class="shrink-0" wire:click="ersatzLaAlsGpAnlegen({{ $k['id'] }})">Als Grundprodukt anlegen</x-fa::button>
                                @else
                                    <x-fa::button size="sm" class="shrink-0" wire:click="ersatzVerknuepfen('{{ $k['kind'] }}', {{ $k['id'] }})">Verknüpfen</x-fa::button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @if($kannKuratieren)
                    <div class="flex flex-col gap-1 pt-1" data-ersatz-verknuepfen>
                        <div class="relative">
                            @svg('heroicon-m-plus', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input size="sm" type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="Ersatz verknüpfen: Grundprodukt oder Rezept suchen" aria-label="Ersatz suchen und verknüpfen" class="pl-8" />
                        </div>
                        @foreach($ersatzKandidaten as $k)
                            <button type="button" wire:key="ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})" class="{{ $listenKnopf }}">
                                <x-fa::badge :tone="$k->kind === 'recipe' ? 'info' : 'neutral'">{{ $k->kind === 'recipe' ? 'Rezept' : 'Grundprodukt' }}</x-fa::badge>
                                <span class="min-w-0 flex-1">{{ $k->name }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-fa::section>
        @endif

        {{-- LAGER (Spec 67): das Grundprodukt trägt sein Lager — Bestand + Stammplatz je Lagerort --}}
        @if($section === 'lager')
        <x-fa::section variant="plain" title="Lager" icon="heroicon-o-archive-box" data-sektion="lager">
            @if($lagerOrte === [])
                <p class="fa-t-muted">Noch kein Lagerort angelegt (Einstellungen → Einkauf).</p>
            @else
                <p class="fa-t-muted">Der Stellplatz gilt für dieses Team. Der Wareneingang bucht in den Lagerort mit Stellplatz; ohne Stellplatz ins Standardlager und schlägt beim ersten Eingang einen Platz vor.</p>
                <table class="fa-table fa-table--compact mt-2" data-gp-lager>
                    <thead><tr><th>Lagerort</th><th class="text-right">Bestand</th><th>Stellplatz</th></tr></thead>
                    <tbody>
                        @foreach($lagerOrte as $o)
                            @php $vorschlag = $o['bin_id'] === null && $o['vorschlag'] !== null ? $o['stellplaetze']->firstWhere('id', $o['vorschlag']) : null; @endphp
                            <tr wire:key="gpl-{{ $o['id'] }}">
                                <td class="font-medium">{{ $o['name'] }}@if($o['standard']) <span class="fa-t-muted">· Standard</span>@endif</td>
                                <td class="text-right tabular-nums">{{ $o['bestand'] ?? '–' }}</td>
                                <td>
                                    @if($o['stellplaetze']->isEmpty())
                                        <span class="fa-t-muted">keine Stellplätze</span>
                                    @else
                                        <span class="inline-flex items-center gap-2">
                                            <select wire:change="stammplatzSetzen({{ $o['id'] }}, $event.target.value)" class="fa-control fa-select h-7 pr-8 text-[length:var(--fa-text-sm)] w-48" aria-label="Stellplatz in {{ $o['name'] }}" data-gp-stammplatz="{{ $o['id'] }}">
                                                <option value="">– kein Stellplatz –</option>
                                                @foreach($o['stellplaetze'] as $p)<option value="{{ $p->id }}" @selected($o['bin_id'] === $p->id)>{{ $p->name }}</option>@endforeach
                                            </select>
                                            @if($vorschlag)<button type="button" wire:click="stammplatzSetzen({{ $o['id'] }}, {{ $vorschlag->id }})" class="text-[length:var(--fa-text-sm)] text-[var(--fa-accent)] hover:underline">Vorschlag: {{ $vorschlag->name }}</button>@endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($lagerBewegungen->isNotEmpty())
                    <div class="{{ $titelKlein }} mt-3">Letzte Bewegungen</div>
                    <ul class="flex flex-col">
                        @foreach($lagerBewegungen as $m)
                            <li wire:key="gplm-{{ $m->id }}" class="flex items-center gap-2 py-0.5 text-[length:var(--fa-text-sm)]">
                                <span class="tabular-nums text-[var(--fa-ink-3)] w-20">{{ $m->moved_at?->format('d.m.Y') }}</span>
                                <span class="w-28">{{ ['wareneingang' => 'Wareneingang', 'inventur' => 'Inventur', 'zugang' => 'Zugang', 'abgang' => 'Abgang', 'umlagerung' => 'Umlagerung', 'storno' => 'Storno', 'produktion' => 'Produktion'][$m->source] ?? $m->source }}</span>
                                <span class="tabular-nums {{ $m->direction === 'out' ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">{{ $m->direction === 'out' ? '−' : '+' }}{{ rtrim(rtrim(number_format((float) app(\Platform\FoodAlchemist\Services\InventurService::class)->anzeigeMenge((float) $m->qty_base, $m->base_unit), 3, ',', '.'), '0'), ',') }} {{ app(\Platform\FoodAlchemist\Services\InventurService::class)->anzeigeEinheit($m->base_unit) }}</span>
                                <span class="text-[var(--fa-ink-3)] truncate">{{ $m->location?->name }}@if($m->reason) · {{ (\Platform\FoodAlchemist\Services\LagerBewegungService::GRUENDE['zugang'] + \Platform\FoodAlchemist\Services\LagerBewegungService::GRUENDE['abgang'])[$m->reason] ?? $m->reason }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
            {{-- Spec 74: Lagerartikel (Grundvorrat) — nicht über den Rezeptbedarf bestellen, sondern nachfüllen --}}
            @if($lagerartikelInfo !== null)
                <div class="mt-3 flex flex-col gap-1.5" data-gp-lagerartikel>
                    <x-fa::checkbox class="inline-flex items-center gap-1.5 fa-t-body" wire:model.live="lagerartikelForm.ist">Lagerartikel (Grundvorrat, z. B. Gewürze, Öle)</x-fa::checkbox>
                    @if($lagerartikelForm['ist'])
                        <div class="flex flex-wrap items-end gap-2">
                            <x-fa::field label="Mindestbestand" for="gpla-min"><span class="inline-flex items-center gap-1"><x-fa::input id="gpla-min" size="sm" wire:model="lagerartikelForm.min" inputmode="decimal" class="w-20" /><span class="fa-t-muted">{{ $lagerartikelInfo['einheit'] }}</span></span></x-fa::field>
                            <x-fa::field label="Auffüllen auf" for="gpla-soll"><span class="inline-flex items-center gap-1"><x-fa::input id="gpla-soll" size="sm" wire:model="lagerartikelForm.soll" inputmode="decimal" class="w-20" /><span class="fa-t-muted">{{ $lagerartikelInfo['einheit'] }}</span></span></x-fa::field>
                        </div>
                    @endif
                    <div><x-fa::button size="sm" wire:click="lagerartikelSetzen" data-gp-lagerartikel-speichern>Speichern</x-fa::button></div>
                    @if($lagerartikelFehler)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]">{{ $lagerartikelFehler }}</p>@endif
                    <p class="fa-t-muted">Lagerartikel gehen nicht mit jedem Rezept in die Bestellung. Unter dem Mindestbestand schlägt die Bestellrunde vor, auf den Sollbestand nachzufüllen.</p>
                </div>
            @endif
            {{-- Art + Preis als Gefäß (17.11.2026): je Team; jede Darreichung mit diesem Gefäß rechnet danach --}}
            @if($gefaessKostenartInfo !== null)
                <div class="mt-3 flex flex-col gap-1.5" data-gp-gefaess-kostenart>
                    <x-fa::field label="Art als Gefäß (dieses Team)" for="gp-gef-kostenart">
                        <span class="inline-flex flex-wrap items-center gap-2">
                            <x-fa::select id="gp-gef-kostenart" size="sm" wire:model="gefaessKostenart" class="w-60" data-gp-gefaess-kostenart-wahl>
                                <option value="">{{ $gefaessKostenartInfo['geerbt'] ? 'wie '.$gefaessKostenartInfo['geerbt_von'].': '.$gefaessKostenartInfo['geerbt'] : 'Automatik (Verleih: extern, sonst Einweg)' }}</option>
                                @foreach(\Platform\FoodAlchemist\Services\GefaessKostenartService::ARTEN as $artKey => $artLabel)<option value="{{ $artKey }}">{{ $artLabel }}</option>@endforeach
                            </x-fa::select>
                            <span class="inline-flex items-center gap-1"><x-fa::input id="gp-gef-preis" size="sm" wire:model="gefaessPreis" inputmode="decimal" placeholder="Preis" class="w-20" aria-label="Preis je Stück" data-gp-gefaess-preis /><span class="fa-t-muted">€/Stk</span></span>
                            <x-fa::button size="sm" wire:click="gefaessKostenartSetzen" data-gp-gefaess-kostenart-speichern>Speichern</x-fa::button>
                        </span>
                    </x-fa::field>
                    @if($gefaessKostenartFehler)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]">{{ $gefaessKostenartFehler }}</p>@endif
                    <p class="fa-t-muted">Einweg und Extern (Miete / Spülservice): der Preis hier, sonst der Artikelpreis je Stück. Mehrweg (Eigenbestand, z. B. Spülkosten): nur der Preis hier. Ohne Preis gilt die Darreichung als unvollständig. Die Kosten gehen je Stück in den Wareneinsatz.</p>
                </div>
            @endif
            {{-- Zubehör am Gefäß (20.11.2026, „Weg 2“): gehört immer dazu (Deckel, Etikett, Löffel) — jede Darreichung mit diesem Gefäß rechnet es mit,
                 jedes Teil zu seinem Preis und seiner Art. Eine Ebene: Zubehör hat kein eigenes Zubehör. --}}
            @if($gefaessZubehoerInfo !== null)
                @php $gz = $gefaessZubehoerInfo; @endphp
                <div class="mt-3 flex flex-col gap-1.5" data-gp-gefaess-zubehoer>
                    <span class="fa-t-label">Gehört dazu (Zubehör je Gefäß, dieses Team)</span>
                    @if($gz['geerbt_von'])<p class="fa-t-muted" data-gp-gefaess-zubehoer-geerbt>Liste von {{ $gz['geerbt_von'] }} — eine Änderung übernimmt sie für dieses Team.</p>@endif
                    @forelse($gz['zeilen'] as $z)
                        <div class="flex flex-wrap items-center gap-2" wire:key="gp-gef-zub-{{ $z['gp_id'] }}" data-gp-gefaess-zubehoer-zeile="{{ $z['gp_id'] }}">
                            <x-fa::input size="sm" inputmode="decimal" value="{{ rtrim(rtrim(number_format($z['menge'], 3, ',', ''), '0'), ',') }}" aria-label="Stück je Gefäß"
                                wire:change="gefaessZubehoerMengeSetzen({{ $z['gp_id'] }}, $event.target.value)" class="w-16 text-right tabular-nums" />
                            <span class="fa-t-body">× {{ $z['name'] }}</span>
                            <span class="fa-t-muted">{{ \Platform\FoodAlchemist\Services\GefaessKostenartService::ARTEN[$z['kostenart']] ?? '' }}</span>
                            @if($z['kosten'] !== null)<span class="tabular-nums" data-gp-gefaess-zubehoer-kosten>{{ number_format($z['kosten'], 2, ',', '.') }} €</span>
                            @else<x-fa::badge tone="warn" title="Kein Preis: am Zubehör-Grundprodukt Art und Preis eintragen oder einen bepreisten Hauptartikel wählen" data-gp-gefaess-zubehoer-preis-fehlt>kein Preis</x-fa::badge>@endif
                            <x-fa::icon-button icon="heroicon-o-x-mark" label="Zubehör entfernen" size="sm" wire:click="gefaessZubehoerWeg({{ $z['gp_id'] }})" />
                        </div>
                    @empty
                        <p class="fa-t-muted">Kein Zubehör — das Gefäß kostet allein.</p>
                    @endforelse
                    @if($gz['zeilen'] !== [])<p class="fa-t-label" data-gp-gefaess-zubehoer-summe>Zubehör je Gefäß {{ number_format($gz['summe'], 2, ',', '.') }} €{{ $gz['preis_fehlt'] ? ' (ohne Teile ohne Preis)' : '' }}</p>@endif
                    <span class="inline-flex flex-wrap items-center gap-2">
                        <x-fa::input size="sm" inputmode="decimal" wire:model="gefaessZubehoerMenge" class="w-16 text-right" aria-label="Stück je Gefäß für das neue Zubehör" />
                        <x-fa::input size="sm" type="search" wire:model.live.debounce.300ms="gefaessZubehoerSuche" placeholder="+ Zubehör suchen (z. B. Deckel)" class="w-64" aria-label="Zubehör suchen" data-gp-gefaess-zubehoer-suche />
                    </span>
                    @if($gz['treffer']->isNotEmpty())
                        <div class="flex flex-wrap gap-1.5" data-gp-gefaess-zubehoer-treffer>
                            @foreach($gz['treffer'] as $t)<x-fa::button size="sm" variant="ghost" wire:click="gefaessZubehoerHinzu({{ $t->id }})" wire:key="gp-gef-zub-t-{{ $t->id }}">{{ $t->name }}</x-fa::button>@endforeach
                        </div>
                    @elseif(trim($gefaessZubehoerSuche) !== '')<p class="fa-t-muted">Nichts gefunden. Zubehör als Non-Food-Grundprodukt (Warengruppe 16) anlegen.</p>@endif
                    @if($gefaessZubehoerFehler)<p class="fa-t-muted text-[var(--fa-crit)]" data-gp-gefaess-zubehoer-fehler>{{ $gefaessZubehoerFehler }}</p>@endif
                </div>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('foodalchemist.lager.index'))
                <a href="{{ route('foodalchemist.lager.index', ['reiter' => 'einrichten']) }}" wire:navigate class="fa-t-muted hover:underline mt-2 inline-block">Lager einrichten →</a>
            @endif
        </x-fa::section>
        @endif

        {{-- EINKAUF (Spec 66 §4): was wurde eingekauft, wann, bei wem — und was liegt am Lager --}}
        @if(($section === null || $section === 'einkauf') && $einkauf !== null)
        @php
            $eMax = max(array_map(fn ($m) => $section === 'einkauf' ? $m['menge'] : $m['eur'], $einkauf['monate']) ?: [0]) ?: 1;
            $eEinheit = $einkauf['einheit'] ?? '';
            $mengeTxt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
        @endphp
        <x-fa::section variant="plain" title="Einkauf (12 Monate)" icon="heroicon-o-shopping-cart" data-sektion="einkauf">
            @if($einkauf['positionen'] === 0)
                <p class="fa-t-muted" data-einkauf-leer>In den letzten 12 Monaten nicht eingekauft.@if($bestandJetzt !== []) Am Lager: @foreach($bestandJetzt as $b){{ $mengeTxt($b['menge']) }} {{ $b['einheit'] }}@if(! $loop->last), @endif @endforeach.@endif</p>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3" data-einkauf-kpis>
                    @foreach([
                        ['Menge', $mengeTxt($einkauf['summe_menge']) . ' ' . $eEinheit],
                        ['Ausgaben', $zahl($einkauf['summe_eur']) . ' €'],
                        ['Ø Preis', $einkauf['summe_menge'] > 0 ? $zahl($einkauf['summe_eur'] / $einkauf['summe_menge']) . ' €/' . $eEinheit : '—'],
                        ['Am Lager', $bestandJetzt === [] ? '—' : collect($bestandJetzt)->map(fn ($b) => $mengeTxt($b['menge']) . ' ' . $b['einheit'])->implode(', ')],
                    ] as [$lbl, $wert])
                        <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-2.5 py-1.5">
                            <div class="fa-t-muted">{{ $lbl }}</div>
                            <div class="fa-t-strong tabular-nums">{{ $wert }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Monatsbalken: Menge in der Hauptansicht, € im Seitenpanel (dort keine Einheit nötig) --}}
                <div class="flex items-end gap-1 h-28" data-einkauf-monate>
                    @foreach($einkauf['monate'] as $m)
                        @php $v = $section === 'einkauf' ? $m['menge'] : $m['eur']; @endphp
                        <div class="flex-1 min-w-0 flex flex-col items-center justify-end h-full" wire:key="ek-m-{{ $m['monat'] }}"
                             title="{{ $m['label'] }}: {{ $mengeTxt($m['menge']) }} {{ $eEinheit }} · {{ $zahl($m['eur']) }} €">
                            <div class="w-full rounded-t {{ $v > 0 ? 'bg-[var(--fa-accent)]' : 'bg-[var(--fa-line)]' }}" style="height: {{ $v > 0 ? max(4, round($v / $eMax * 100)) : 2 }}%"></div>
                            <div class="mt-1 fa-t-muted leading-none truncate w-full text-center">{{ $m['label'] }}</div>
                        </div>
                    @endforeach
                </div>
                @if($einkauf['gemischte_einheiten'])
                    <p class="fa-t-muted mt-1">Gemischte Einheiten im Einkauf — Mengen zeigen nur {{ $eEinheit }}, € enthält alles.</p>
                @endif

                <table class="fa-table fa-table--compact mt-3" data-einkauf-lieferanten>
                    <thead><tr><th>Lieferant</th><th class="text-right">Menge</th><th class="text-right">€</th><th class="text-right">Ø €/{{ $eEinheit }}</th><th class="text-right">Zuletzt</th></tr></thead>
                    <tbody>
                        @foreach($einkauf['lieferanten'] as $l)
                            <tr wire:key="ek-l-{{ md5($l['lieferant']) }}">
                                <td>{{ $l['lieferant'] }}</td>
                                <td class="text-right tabular-nums">{{ $mengeTxt($l['menge']) }} {{ $eEinheit }}</td>
                                <td class="text-right tabular-nums">{{ $zahl($l['eur']) }}</td>
                                <td class="text-right tabular-nums">{{ $l['preis_je_einheit'] !== null ? $zahl($l['preis_je_einheit']) : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $l['zuletzt'] ? \Illuminate\Support\Carbon::parse($l['zuletzt'])->format('d.m.Y') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('foodalchemist.lager.index'))
                <a href="{{ route('foodalchemist.lager.index', ['reiter' => 'bestand']) }}" wire:navigate class="fa-t-muted hover:underline mt-2 inline-block">Zum Lager →</a>
            @endif
        </x-fa::section>
        @endif

        {{-- Zukauf-Basisrezept (Dominique 13.11.): nur bei Fertigware — Link aufs vorhandene oder Knopf zum Anlegen --}}
        @if($section === null)
            <livewire:foodalchemist.gps.zukauf-rezept :gp-id="$gp->id" :key="'gp-zukauf-panel-'.$gp->id" />
        @endif

        {{-- Verwendung --}}
        {{-- VERWENDET IN REZEPTEN --}}
        @if($section === null || $section === 'las')
        <x-fa::section variant="plain" :title="'Verwendet in Rezepten (' . $verwendungen->count() . ($verwendungen->count() === 30 ? '+' : '') . ')'" icon="heroicon-o-link" data-sektion="verwendungen">
            <div class="flex flex-col">
                @forelse($verwendungen as $v)
                    <a href="#" role="button" wire:key="verw-{{ $v->id }}" wire:click.prevent="$dispatch('{{ $v->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $v->id }} })"
                            class="{{ $listenKnopf }} text-[var(--fa-accent)]" title="{{ $v->is_sales_recipe ? 'Gericht' : 'Basisrezept' }} öffnen" data-verwendung-link>
                        @svg($v->is_sales_recipe ? 'heroicon-o-banknotes' : 'heroicon-o-book-open', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                        <span class="min-w-0 flex-1 hover:underline">{{ $v->name }}</span>
                    </a>
                @empty
                    <p class="fa-t-muted" data-verwendungen-leer>In keinem Rezept eingesetzt.</p>
                @endforelse
            </div>
        </x-fa::section>
        @endif

        {{-- VERWALTUNG --}}
        @if($section === null && $kannKuratieren && ! $gp->is_platzhalter && $referenzen !== null)
        <x-fa::section variant="plain" title="Verwaltung" icon="heroicon-o-cog-6-tooth" data-sektion="verwaltung">
            @if($referenzen['summe'] === 0)
                <p class="fa-t-muted">Nirgends verwendet, Löschen ist möglich.</p>
            @else
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-ref-zusammenfassung>Löschen nicht möglich, noch verwendet in:
                    {{ implode(' · ', array_filter([
                        $referenzen['las'] > 0 ? $referenzen['las'] . ' ' . ($referenzen['las'] === 1 ? 'Artikel' : 'Artikeln') : null,
                        $referenzen['rezept_zeilen'] > 0 ? $referenzen['rezept_zeilen'] . ' ' . ($referenzen['rezept_zeilen'] === 1 ? 'Zeile' : 'Zeilen') . ' in ' . $referenzen['rezepte'] . ' ' . ($referenzen['rezepte'] === 1 ? 'Rezept' : 'Rezepten') : null,
                        $referenzen['derivate'] > 0 ? $referenzen['derivate'] . ' ' . ($referenzen['derivate'] === 1 ? 'Derivat' : 'Derivaten') : null,
                        $referenzen['merge_quellen'] > 0 ? $referenzen['merge_quellen'] . ' zusammengeführten Grundprodukten' : null,
                        $referenzen['ersatz'] > 0 ? $referenzen['ersatz'] . ' ' . ($referenzen['ersatz'] === 1 ? 'Ersatz-Verknüpfung' : 'Ersatz-Verknüpfungen') : null,
                    ])) }}
                </p>
                @if($referenzen['rezept_zeilen'] > 0 || $referenzen['las'] > 0)
                    <div class="flex flex-col gap-1 pt-1" data-gp-tausch>
                        <x-fa::field label="Ersetzen durch (Artikel und Rezepte)" for="gp-tausch-suche">
                            <x-fa::input id="gp-tausch-suche" size="sm" type="search" wire:model.live.debounce.300ms="tauschSuche" placeholder="Grundprodukt oder Platzhalter suchen" data-tausch-suche />
                        </x-fa::field>
                        @foreach($tauschKandidaten as $k)
                            <button type="button" wire:key="tausch-{{ $k->id }}" wire:click="gpErsetzen({{ $k->id }})"
                                wire:confirm="„{{ $gp->name }}“ durch „{{ $k->name }}“ ersetzen? {{ $k->is_platzhalter ? 'Lieferantenartikel bleiben am bisherigen Grundprodukt. ' : ($referenzen['las'] > 0 ? $referenzen['las'] . ' Artikel werden umgehängt (Allergen-Prüfung je Artikel, abgelehnte bleiben stehen). ' : '') }}{{ $referenzen['rezept_zeilen'] > 0 ? 'Die Zeilen in ' . $referenzen['rezepte'] . ' Rezept(en) werden umgehängt und neu berechnet.' : '' }}" class="{{ $listenKnopf }}">
                                <x-fa::status :value="$k->status" class="shrink-0" />
                                <span class="min-w-0 flex-1">{{ $k->name }}</span>
                                @if($k->is_platzhalter)<x-fa::badge>Platzhalter</x-fa::badge>@endif
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif
            @if($abgelehnteArtikel !== [])
                <x-fa::notice tone="warn" data-abgelehnte-artikel>
                    Diese Artikel blieben am Grundprodukt, weil die Allergen-Prüfung am Ziel sie ablehnt:
                    <ul class="list-disc pl-5">
                        @foreach($abgelehnteArtikel as $a)<li wire:key="abg-{{ $a['id'] }}">{{ $a['bezeichnung'] }} — {{ $a['grund'] }}</li>@endforeach
                    </ul>
                </x-fa::notice>
            @endif
            <div class="pt-2" data-gp-loeschen-bereich>
                @if($darfLoeschen)
                    <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="gpLoeschen" wire:confirm="„{{ $gp->name }}“ löschen? Es gibt keine Verweise darauf." data-gp-loeschen-knopf>Grundprodukt löschen</x-fa::button>
                @else
                    <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" disabled title="Löschen blockiert: {{ $loeschGrund }}" data-gp-loeschen-gesperrt>Grundprodukt löschen</x-fa::button>
                    <p class="fa-t-muted pt-1" data-gp-loeschen-grund>Gesperrt: {{ $loeschGrund }}</p>
                @endif
            </div>
        </x-fa::section>
        @endif
        </div>

        @if($section === null)
        <p class="pt-3 border-t border-[var(--fa-line)] fa-t-muted"><span class="font-mono">{{ $gp->uuid }}</span>@if($gp->team_id === null)<span> · zentral gepflegt, für alle Teams</span>@endif</p>
        @endif
        </fieldset>
    @endif
</div>
