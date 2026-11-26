<?php

// Spec 85 §4 · Bestand des Größen-Wächters (DateigroessenWaechterTest). Darf nur schrumpfen (Sperrklinke).
// Pfad (relativ zum Modul) => [Zeilen, Grund/Ziel]. Zeilen = Zeilenumbrüche wie `wc -l`.
// Stand 2026-10-19 (main 9e1d3fc6, nach Stufe 4 #514, Sweep #516, C1 PR-1 #515, D-2-Lösch-PRs): 19 Dateien über 1.500 Zeilen.

return [
    'src/Services/Bestellung/OrderService.php' => [1877, 'C2 Bestell-Cluster (Spec 85 §6); Zerlegen nach Services/Bestellung (98 · 3.3 S5)'],
    'src/Services/Pairing/PairingService.php' => [2567, 'Lesen / Bewerten / Pflege (Spec 85 §9)'],
    'src/Services/Ai/KnowledgeContextService.php' => [2170, 'Routing / Budget / Prompt-Bau (Spec 85 §9)'],
    'src/Services/Pruefung/DataQualityService.php' => [2022, 'Metriken je Bereich (Spec 85 §9)'],
    'src/Livewire/Angebote/Editor.php' => [1752, 'C4 Komposition (Spec 85 §8)'],
    'src/Livewire/Foodbooks/Index.php' => [1623, 'C4 Komposition (Spec 85 §8); +1 Handy-Weiche #1003 PR6'],
    'src/Services/Rezept/RecipeGeneratorService.php' => [1528, 'Nicht-Kernlogik auslagern (Spec 85 §9)'],
];
