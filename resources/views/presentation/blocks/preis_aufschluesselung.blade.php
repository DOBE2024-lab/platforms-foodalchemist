{{-- #380 Q2 — Kundensichere Preis-Aufschlüsselung: Kapitel · Pax · €/Person · Zwischensumme + netto/MwSt/brutto.
     KEIN EK/Marge (interna-frei). Datenquelle: content.preis_aufschluesselung (Angebot und Foodbook).
     2026-11-19: pt-Klassen statt Inline-Styles (Design-Schrift, Farben und eigenes CSS greifen), Titel einstellbar;
     ohne Gästezahl (Foodbook ohne Personen) nur €/Person je Kapitel und Gesamt pro Person. --}}
@php
    $pa = $content['preis_aufschluesselung'] ?? null;
    $nf = fn ($v) => number_format((float) $v, 2, ',', '.');
    $modus = (string) ($style['preis_anzeige'] ?? 'beide');
    $modus = in_array($modus, ['netto', 'brutto', 'beide'], true) ? $modus : 'beide';
    $titel = trim((string) ($style['titel'] ?? '')) ?: 'Preis-Übersicht';
    $mitSumme = $pa !== null && ($pa['netto'] ?? null) !== null;
    $satzTxt = ($pa['mwst_satz'] ?? null) !== null ? rtrim(rtrim(number_format((float) $pa['mwst_satz'], 1, ',', '.'), '0'), ',') : '';
    $hatMwst = $satzTxt !== '';
    $gesamtVorlaeufig = ! empty($pa['vorlaeufig']);   // CQ price_incomplete: die Aufschlüsselung wird nicht eingefroren, ihr Merker ist live wie ihre Beträge
@endphp
@if($pa && count($pa['zeilen'] ?? []))
    <div class="pt-measure pt-reveal">
        <div class="pt-preis-tabelle{{ $mitSumme ? '' : ' pt-preis-tabelle--ohne-summe' }}" data-pt-preis-aufschluesselung>
            <h3 class="pt-preis-tabelle-titel">{{ $titel }}</h3>
            <div class="pt-preis-zeile pt-preis-kopf">
                <span class="pt-preis-titel">Leistung</span>
                @if($mitSumme)<span class="pt-preis-pax">Gäste</span>@endif
                <span class="pt-preis-pp">€/Person</span>
                @if($mitSumme)<span class="pt-preis-summe">Summe</span>@endif
            </div>
            @foreach($pa['zeilen'] as $z)
                <div class="pt-preis-zeile">
                    <span class="pt-preis-titel">{{ $z['titel'] }}</span>
                    @if($mitSumme)<span class="pt-preis-pax">{{ $z['pax'] ?: '—' }}</span>@endif
                    <span class="pt-preis-pp">
                        @if(($z['alternativen'] ?? false) && ($z['preis_range'] ?? null))
                            {{ $z['preis_range']['min'] !== null ? $nf($z['preis_range']['min']) : '—' }}–{{ $z['preis_range']['max'] !== null ? $nf($z['preis_range']['max']) : '—' }} €
                        @elseif(($z['wahl_spanne'] ?? null) !== null && $z['wahl_spanne']['von'] != $z['wahl_spanne']['bis'])
                            <span data-preis-wahl-spanne>{{ $nf($z['wahl_spanne']['von']) }}–{{ $nf($z['wahl_spanne']['bis']) }} €</span>@if($z['vorlaeufig'] ?? false) <span class="pt-vorlaeufig" data-preis-vorlaeufig>vorläufig</span>@endif
                        @elseif(($z['vk_pro_person'] ?? null) !== null)
                            {{ $nf($z['vk_pro_person']) }} €@if($z['vorlaeufig'] ?? false) <span class="pt-vorlaeufig" data-preis-vorlaeufig>vorläufig</span>@endif
                        @else — @endif
                    </span>
                    @if($mitSumme)<span class="pt-preis-summe">{{ ($z['gesamt'] ?? null) !== null ? $nf($z['gesamt']) . ' €' : '—' }}</span>@endif
                </div>
            @endforeach

            @if(! $mitSumme)
                {{-- Ohne Gästezahl: Gesamt pro Person (netto, auf Wunsch brutto) statt Summen. --}}
                @if(($pa['pro_person'] ?? null) !== null && (float) $pa['pro_person'] > 0)
                    @if($modus !== 'brutto' || ! $hatMwst)
                        <div class="pt-preis-zeile pt-preis-gesamt">
                            <span class="pt-preis-titel">Gesamt pro Person{{ $hatMwst ? ' (netto)' : '' }}</span><span class="pt-preis-pp">{{ $nf($pa['pro_person']) }} €@if($gesamtVorlaeufig) <span class="pt-vorlaeufig" data-preis-vorlaeufig>vorläufig</span>@endif</span>
                        </div>
                    @endif
                    @if($hatMwst && $modus !== 'netto')
                        <div class="pt-preis-zeile {{ $modus === 'brutto' ? 'pt-preis-gesamt' : 'pt-preis-brutto' }}">
                            <span class="pt-preis-titel">Gesamt pro Person (brutto, inkl. {{ $satzTxt }} % MwSt)</span><span class="pt-preis-pp">{{ $nf(round((float) $pa['pro_person'] * (1 + (float) $pa['mwst_satz'] / 100), 2)) }} €</span>
                        </div>
                    @endif
                @endif
            @else
                @if($modus !== 'brutto')
                    <div class="pt-preis-zeile pt-preis-gesamt">
                        <span class="pt-preis-titel">Gesamt (netto)</span><span class="pt-preis-pax pt-preis-pax--schwach">Ø {{ $pa['pax'] ?: '—' }}</span><span class="pt-preis-pp"></span><span class="pt-preis-summe">{{ $nf($pa['netto']) }} €@if($gesamtVorlaeufig) <span class="pt-vorlaeufig" data-preis-vorlaeufig>vorläufig</span>@endif</span>
                    </div>
                @endif
                @if($modus === 'beide' && $hatMwst)
                    <div class="pt-preis-zeile pt-preis-mwst">
                        <span class="pt-preis-titel">zzgl. MwSt ({{ $satzTxt }} %)</span><span class="pt-preis-pax"></span><span class="pt-preis-pp"></span><span class="pt-preis-summe">{{ $nf($pa['mwst_betrag']) }} €</span>
                    </div>
                @endif
                @if($modus !== 'netto' && $hatMwst)
                    <div class="pt-preis-zeile {{ $modus === 'brutto' ? 'pt-preis-gesamt' : 'pt-preis-brutto' }}">
                        <span class="pt-preis-titel">Gesamt (brutto)</span><span class="pt-preis-pax pt-preis-pax--schwach">@if($modus === 'brutto')Ø {{ $pa['pax'] ?: '—' }}@endif</span><span class="pt-preis-pp"></span><span class="pt-preis-summe">{{ $nf($pa['brutto']) }} €@if($gesamtVorlaeufig) <span class="pt-vorlaeufig" data-preis-vorlaeufig>vorläufig</span>@endif</span>
                    </div>
                    @if($modus === 'brutto')
                        <div class="pt-preis-zeile pt-preis-mwst"><span class="pt-preis-titel">inkl. {{ $satzTxt }} % MwSt ({{ $nf($pa['mwst_betrag']) }} €)</span></div>
                    @endif
                @endif
            @endif

            @if(! empty($pa['zzgl']))
                {{-- Spec 94b: zzgl. nach Aufwand (geschätzt, nicht im Gesamt) und voraussichtliche Gesamtsumme --}}
                <div class="pt-zzgl" data-pt-zzgl>
                    <div class="pt-zzgl-titel">Zzgl. nach Aufwand{{ collect($pa['zzgl'])->contains(fn ($z) => $z['verbrauch'] ?? false) ? ' bzw. Verbrauch' : '' }} (geschätzt)</div>
                    @foreach($pa['zzgl'] as $z)
                        <div class="pt-zzgl-zeile"><span>{{ $z['titel'] }}@if($z['verbrauch'] ?? false) nach Verbrauch @endif · ca. {{ $z['menge'] }} {{ $z['einheit'] }} à {{ $nf($z['satz']) }} €@if(! empty($z['deckel'])) · höchstens {{ $nf($z['deckel']) }} €@endif</span><span>≈ {{ $nf($z['betrag']) }} €</span></div>
                    @endforeach
                    <div class="pt-zzgl-zeile pt-zzgl-summe" data-pt-voraussichtlich><span>Voraussichtlich gesamt inkl. Aufwand (netto)</span><span>ca. {{ $nf($pa['voraussichtlich_netto']) }} €</span></div>
                    <div class="pt-zzgl-hinweis">Der Aufwand ist geschätzt und nicht im Gesamtpreis enthalten. Abgerechnet wird nach tatsächlichem Aufwand bzw. Verbrauch, höchstens bis zum genannten Höchstbetrag.</div>
                </div>
            @endif
        </div>
    </div>
@endif
