{{-- Code-Qualität 2.1d: schlanker Poll der Planung. Meldet nur Änderungen, die Planung rendert dann neu. Der Takt folgt dem Stillstand
     (3 s → 15 s → 60 s); der Schlüssel am Poll-Element erzwingt beim Stufenwechsel ein neues Element, damit Livewire den neuen Takt übernimmt. --}}
<div class="contents" data-planung-lauf-status data-takt="{{ $aktuellerTakt }}">
    <div wire:key="planung-lauf-status-poll-{{ $aktuellerTakt }}" wire:poll.{{ $aktuellerTakt }}="pruefen" hidden></div>
    @foreach($haengend as $h)
        <x-fa::notice tone="warn" data-planung-lauf-haengt="{{ $h['run_id'] }}">
            „{{ $h['titel'] }}“ hängt seit {{ $h['minuten'] }} min.
            @if($h['session_id'] !== null)
                <a href="{{ route('foodalchemist.planung.index', ['session' => $h['session_id'], 'open' => 1]) }}" class="underline" wire:navigate>Fortsetzen</a>
            @endif
        </x-fa::notice>
    @endforeach
</div>
