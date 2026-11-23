<?php

// Spec 85 §4 · Bestand des Größen-Wächters (DateigroessenWaechterTest). Darf nur schrumpfen (Sperrklinke).
// Pfad (relativ zum Modul) => [Zeilen, Grund/Ziel]. Zeilen = Zeilenumbrüche wie `wc -l`.
// Stand 2026-10-19 (main 9e1d3fc6, nach Stufe 4 #514, Sweep #516, C1 PR-1 #515, D-2-Lösch-PRs): 19 Dateien über 1.500 Zeilen.

return [
    'src/Services/OrderService.php' => [1877, 'C2 Bestell-Cluster (Spec 85 §6); Zerlegen nach Services/Bestellung (98 · 3.3 S5)'],
    'src/Services/PairingService.php' => [2588, 'Lesen / Bewerten / Pflege (Spec 85 §9)'],
    'src/Services/Ai/KnowledgeContextService.php' => [2170, 'Routing / Budget / Prompt-Bau (Spec 85 §9)'],
    'src/Services/DataQualityService.php' => [2040, 'Metriken je Bereich (Spec 85 §9)'],
    'src/Livewire/Angebote/Editor.php' => [1754, 'C4 Komposition (Spec 85 §8)'],
    'src/Livewire/Concepter/Editor.php' => [1598, 'C6 Slots / Paket-Positionen (Spec 85 §9); −34 Positionen anlegen → Concerns\FuelltConceptPositionen (CQ 1.1j)'],
    'src/Livewire/Foodbooks/Index.php' => [1625, 'C4 Komposition (Spec 85 §8); +1 Handy-Weiche #1003 PR6'],
    'resources/views/livewire/concepter/editor.blade.php' => [1584, 'C6 mit Concepter/Editor (Spec 85 §9)'],
    'src/Services/RecipeGeneratorService.php' => [1528, 'Nicht-Kernlogik auslagern (Spec 85 §9)'],
];
