<div class="meta">Kurzvorschau · <span data-vorschau-modus>Originalansatz</span></div>
<h4><a class="basis-link vorschau-titel" data-faktor="{{ $bedarfFaktor ?? '' }}" href="#basis-{{ $basisKey }}">{{ $b['name'] }}</a></h4>
@include('foodalchemist::handbuch._allergene', ['kennzeichnung' => $b['kennzeichnung'] ?? null])
@if(!empty($b['ansatz_kg']))<p class="meta">Originalansatz: {{ $fmtMit($b['ansatz_kg'], 'kg') }}</p>@endif
<p class="hint" data-bedarf-fehlt hidden>Bedarf nicht berechenbar; angezeigt wird der Originalansatz.</p>
@if(!empty($b['ansatz_kg']))<p>Rezeptmenge: <b data-bedarf-menge data-ansatz="{{ $b['ansatz_kg'] * 1000 }}" data-einheit="g">{{ $fmtMit($b['ansatz_kg'] * 1000, 'g') }}</b></p>@elseif(!empty($b['ansatz_stueck']))<p>Rezeptmenge: <b data-bedarf-menge data-ansatz="{{ $b['ansatz_stueck'] }}" data-einheit="Stück">{{ $fmtMenge($b['ansatz_stueck']) }} Stück</b></p>@endif
<table data-basis-werte>
    @foreach($b['zeilen'] ?? [] as $zi)
        <tr><td>{{ $zi['name'] }}</td><td class="r" @if($zi['menge'] !== null) data-original="{{ $zi['menge'] }}" data-u="{{ $zi['einheit'] }}" @endif>{{ $fmtMit($zi['menge'], $zi['einheit']) }}</td></tr>
    @endforeach
</table>
<p><a class="basis-link" data-faktor="{{ $bedarfFaktor ?? '' }}" href="#basis-{{ $basisKey }}">Vollständiges Basisrezept mit Schritten und Bildern öffnen →</a></p>
