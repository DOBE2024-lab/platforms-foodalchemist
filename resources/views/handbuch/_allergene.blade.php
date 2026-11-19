@if(isset($kennzeichnung))
    <div class="basis-allergene" aria-label="Allergene dieses Basisrezepts">
        <div class="allergene" aria-label="Enthaltene Allergene">
            @foreach($kennzeichnung['enthalten'] as $a)<span class="chip">{{ $a['code'] }} · {{ $a['label'] }}</span>@endforeach
        </div>
        @if($kennzeichnung['enthalten'] === [] && $kennzeichnung['spuren'] === [] && !$kennzeichnung['offen'])<p class="hint">Keine Allergene gekennzeichnet.</p>@endif
        @if($kennzeichnung['spuren'] !== [])
            <details class="spuren"><summary>Spuren möglich ({{ count($kennzeichnung['spuren']) }})</summary>
                @foreach($kennzeichnung['spuren'] as $a)<span class="chip">{{ $a['code'] }} · {{ $a['label'] }} (Spuren)</span>@endforeach
            </details>
        @endif
        @if($kennzeichnung['offen'])<p class="hint">Allergenangaben noch unvollständig.</p>@endif
    </div>
@endif
