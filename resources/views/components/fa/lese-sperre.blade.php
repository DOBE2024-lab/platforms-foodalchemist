{{-- x-fa::lese-sperre — Spec 65 Lesemodus: sperrt Eingaben und Knöpfe darunter, solange :an wahr ist (<fieldset disabled>).
     Gehört nur um Bearbeiten-Stellen. Reine Anzeige-Bedienung (Zeitraum, Filter, Auf-/Zuklapper, Reiter, „x anzeigen“, Sortierung)
     steht außerhalb, als Insel mit data-fa-anzeige; die Sperre wird dafür auf oberster Ebene des Abschnitts geteilt, nie innerhalb
     von @if/@foreach, die nach dem Öffnen begonnen haben. display:contents = kein eigener Kasten, Teilen ändert das Layout nicht.
     Ein fieldset im fieldset hebt die Sperre nicht auf; verschachtelte Livewire-Kinder werden mitgesperrt.
     Server-Guards der Bearbeiten-Methoden bleiben die eigentliche Sicherung. --}}
@props(['an' => false])
<fieldset @disabled($an) {{ $attributes->merge(['class' => 'contents']) }} data-fa-lesemodus="{{ $an ? '1' : '0' }}">
    {{ $slot }}
</fieldset>
