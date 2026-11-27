<?php

// Spec 85 §4 · Bestand des Größen-Wächters (DateigroessenWaechterTest). Darf nur schrumpfen (Sperrklinke).
// Pfad (relativ zum Modul) => [Zeilen, Grund/Ziel]. Zeilen = Zeilenumbrüche wie `wc -l`.
// Stand 2026-10-19 (main 9e1d3fc6, nach Stufe 4 #514, Sweep #516, C1 PR-1 #515, D-2-Lösch-PRs): 19 Dateien über 1.500 Zeilen.

return [
    'src/Services/Pairing/PairingService.php' => [2273, 'Lesen / Bewerten / Pflege (Spec 85 §9)'],
];
