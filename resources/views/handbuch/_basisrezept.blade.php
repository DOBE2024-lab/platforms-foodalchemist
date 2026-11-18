{{-- Vollständiges Basisrezept im Originalansatz. Ältere Snapshots mit reinen Schritttexten bleiben lesbar. --}}
<div class="head">
    @if(!empty($b['foto_url']))<a class="foto-link" href="{{ $b['foto_url'] }}" data-bild data-caption="{{ $b['name'] }}" aria-label="Rezeptbild vergrößern"><img class="foto" src="{{ $b['foto_url'] }}" alt="{{ $b['name'] }}" loading="lazy"></a>@endif
    <div><div class="meta">Basisrezept · <span data-rezeptmodus>Originalansatz</span></div>
    <h2>{{ $b['name'] }}</h2>
    @if(!empty($b['ansatz_kg']))<p class="meta">Ausbeute des vollständigen Originalansatzes: {{ $fmtMenge($b['ansatz_kg']) }} kg</p>@endif</div>
</div>
@if(!empty($b['bilder']))<div class="allgemein-fotos">@include('foodalchemist::handbuch._bilder', ['bilder' => $b['bilder']])</div>@endif
<div class="basis-rechner" hidden>
    <div class="stepper"> <span data-basis-einheit>Portionen</span> <button type="button" data-basis-step="-1" aria-label="eine weniger">−</button><input class="basis-px" type="number" min="0" max="1000000" step="any" value="1" aria-label="Portionen für dieses Basisrezept"><button type="button" data-basis-step="1" aria-label="eine mehr">+</button></div>
    <p class="hint" data-basis-quelle></p>
</div>
@if(!empty($b['ansatz_kg']) || !empty($b['ansatz_stueck']))
    <div class="menge-rechner" data-basis-ansatz="{{ !empty($b['ansatz_kg']) ? $b['ansatz_kg'] : $b['ansatz_stueck'] }}">
        <label>Hochrechnen auf <input class="basis-ziel" type="text" inputmode="decimal" autocomplete="off" aria-label="Gewünschte Rezeptmenge" value="{{ $fmtMenge(!empty($b['ansatz_kg']) ? $b['ansatz_kg'] : $b['ansatz_stueck']) }}"></label>
        <select class="basis-ziel-einheit" aria-label="Einheit der gewünschten Rezeptmenge">@if(!empty($b['ansatz_kg']))<option value="kg">kg</option><option value="g">g</option>@else<option value="stueck">Stück</option>@endif</select>
        <p class="hint" data-basis-ziel-fehler hidden>Bitte eine gültige Menge zwischen 0 und 1.000.000 eingeben.</p>
    </div>
@endif
@if(!empty($b['ansatz_kg']))<p>Rezeptmenge: <b data-bedarf-menge data-ansatz="{{ $b['ansatz_kg'] * 1000 }}" data-einheit="g">{{ $fmtMenge($b['ansatz_kg'] * 1000) }} g</b></p>@elseif(!empty($b['ansatz_stueck']))<p>Rezeptmenge: <b data-bedarf-menge data-ansatz="{{ $b['ansatz_stueck'] }}" data-einheit="Stück">{{ $fmtMenge($b['ansatz_stueck']) }} Stück</b></p>@endif
<button type="button" class="original-umschalter" hidden>Originalansatz anzeigen</button>
<p class="hint" data-schritt-mengen hidden>Zutaten gemäß Bedarfsliste abwiegen. Zubereitung laut Originalrezept.</p>
<h3>Zutaten</h3>
@if(!empty($b['zeilen']))
    <table data-basis-werte>
        <thead><tr><th>Zutat</th><th class="r">Menge</th></tr></thead>
        <tbody>
        @foreach($b['zeilen'] as $zi)
            <tr>
                <td>
                    @if(!empty($zi['sub']) && isset($basis[$zi['sub']]))
                        <a class="sub basis-link" data-faktor="{{ $zi['sub_faktor'] ?? '' }}" href="#basis-{{ $zi['sub'] }}">{{ $zi['name'] }}</a> <span class="meta">(Basisrezept)</span>
                    @else {{ $zi['name'] }} @endif
                </td>
                <td class="r" @if($zi['menge'] !== null) data-original="{{ $zi['menge'] }}" data-u="{{ $zi['einheit'] }}" @endif>{{ $zi['menge'] !== null ? $fmtMenge($zi['menge']) . ' ' . $zi['einheit'] : '–' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
@php
    $zubereitung = $b['zubereitung'] ?? array_map(fn ($text) => ['text' => $text, 'fotos' => []], $b['schritte'] ?? []);
@endphp
@foreach(['Zubereitung' => $zubereitung, 'Anrichten' => $b['anrichten'] ?? []] as $abschnitt => $schritte)
    @if($schritte !== [])
        <h3>{{ $abschnitt }}</h3>
        @foreach($schritte as $i => $schritt)
            <div class="step"><div class="nr">{{ $i + 1 }}</div><div class="step-inhalt">{{ $schritt['text'] }}
                @if(!empty($schritt['fotos']))@include('foodalchemist::handbuch._bilder', ['bilder' => $schritt['fotos']])@endif
            </div></div>
        @endforeach
    @elseif($abschnitt === 'Zubereitung')
        <p class="leer">Keine Zubereitung hinterlegt.</p>
    @endif
@endforeach
