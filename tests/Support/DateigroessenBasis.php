<?php

// Spec 85 §4 · Bestand des Größen-Wächters (DateigroessenWaechterTest). Darf nur schrumpfen (Sperrklinke).
// Pfad (relativ zum Modul) => [Zeilen, Grund/Ziel]. Zeilen = Zeilenumbrüche wie `wc -l`.
// Stand 2026-10-19 (main 9e1d3fc6, nach Stufe 4 #514, Sweep #516, C1 PR-1 #515, D-2-Lösch-PRs): 19 Dateien über 1.500 Zeilen.

return [
    'src/Services/Pairing/PairingService.php' => [2567, 'Lesen / Bewerten / Pflege (Spec 85 §9)'],
    'src/Services/Pruefung/DataQualityService.php' => [2021, 'Metriken je Bereich (Spec 85 §9)'],
    'src/Livewire/Angebote/Editor.php' => [1751, 'C4 Komposition (Spec 85 §8); −1 98 · 3.6 PR6'],
    'src/Livewire/Foodbooks/Index.php' => [1622, 'C4 Komposition (Spec 85 §8); +1 Handy-Weiche #1003 PR6, −1 98 · 3.6 PR6'],
];
