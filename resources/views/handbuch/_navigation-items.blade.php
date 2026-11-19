@foreach($items as $it)
    <a href="#{{ $it['key'] }}" data-key="{{ $it['key'] }}" @if(isset($it['portionen_pro_gast'])) data-portionen-pro-gast="{{ $it['portionen_pro_gast'] }}" @endif>
        <span>{{ $it['label'] !== '' ? $it['label'] : ($gerichte[$it['key']]['name'] ?? '') }}</span>
        @if(array_key_exists('menge_pro_gast', $it))
            <span class="nav-mengen">{{ $it['menge_pro_gast'] !== null ? $fmtMenge($it['menge_pro_gast']).' '.($it['einheit'] === 'Teile' && (float) $it['menge_pro_gast'] === 1.0 ? 'Teil' : $it['einheit']).' je Gast' : 'Menge je Gast fehlt' }}
                @if(isset($it['menge_pro_gast']))<span data-menge-pro-gast="{{ $it['menge_pro_gast'] }}" data-u="{{ $it['einheit'] }}"></span>@endif
            </span>
        @endif
    </a>
@endforeach
