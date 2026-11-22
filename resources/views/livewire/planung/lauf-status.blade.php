{{-- Code-Qualität 2.1d: schlanker Poll der Planung (unsichtbar). Meldet nur Änderungen, die Planung rendert dann neu. --}}
<div wire:poll.{{ $takt }}="pruefen" hidden data-planung-lauf-status></div>
