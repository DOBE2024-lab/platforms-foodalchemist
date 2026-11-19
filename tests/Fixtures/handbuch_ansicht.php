<?php

$soße = [
    'name' => 'Sauce: Tomate-Mascarpone', 'ansatz_kg' => 1.0,
    'kennzeichnung' => ['enthalten' => [['code' => 'G', 'label' => 'Milch']], 'spuren' => [['code' => 'F', 'label' => 'Soja']], 'offen' => false],
    'zeilen' => [
        ['name' => 'Tomaten', 'menge' => 800, 'einheit' => 'Gramm', 'sub' => null],
        ['name' => 'Mascarpone', 'menge' => 200, 'einheit' => 'Gramm', 'sub' => null],
    ],
    'schritte' => ['Tomaten garen, Mascarpone einrühren.'],
    'zubereitung' => [['text' => 'Tomaten garen, Mascarpone einrühren.', 'fotos' => [['url' => 'http://handbuch.test/bild.png', 'caption' => 'Soße glatt rühren']]]],
    'anrichten' => [],
];
$gericht = [
    'name' => 'Linguine mit Tomaten-Mascarpone-Soße und Zucchini', 'foto_url' => 'http://handbuch.test/teller.png',
    'portion' => ['gramm' => 246, 'form' => 'Buffet'], 'codes' => ['A', 'G', 'B*'],
    'zutaten' => ['basis' => 'portion', 'zeilen' => [
        ['name' => 'Pasta: Linguine', 'menge' => 120, 'einheit' => 'Gramm', 'sub' => 'b1', 'sub_faktor' => .12],
        ['name' => 'Sauce: Tomate-Mascarpone', 'menge' => 80, 'einheit' => 'Gramm', 'sub' => 'b2', 'sub_faktor' => .08],
        ['name' => 'Zucchini', 'menge' => 40, 'einheit' => 'Gramm', 'sub' => null],
        ['name' => 'Pinienkerne', 'menge' => 5, 'einheit' => 'Gramm', 'sub' => null],
        ['name' => 'Rucola', 'menge' => 1, 'einheit' => 'Gramm', 'sub' => null],
    ]],
    'zubereitung' => [], 'anrichten' => [['nr' => 1, 'text' => 'Linguine als Nest anrichten.', 'fotos' => [['url' => 'http://handbuch.test/bild.png', 'caption' => 'Fertiger Teller']]]],
    'regeneration' => [], 'bestandteile' => [],
];
$pudding = $gericht;
$pudding['name'] = 'Nuss-Nougat-Pudding';
$pudding['zutaten']['zeilen'] = [['name' => 'Pudding', 'menge' => 100, 'einheit' => 'Gramm', 'sub' => null]];
return [
    'title' => 'Neues Foodbook', 'quelle' => 'foodbook', 'personen' => 1,
    'sections' => [['title' => 'Menü Test and Fly', 'items' => [['key' => 'r1', 'label' => $gericht['name']], ['key' => 'r2', 'label' => $pudding['name']]],
        'groups' => [['title' => 'Italien', 'items' => [['key' => 'r1', 'label' => $gericht['name']]]], ['title' => 'Dessert', 'items' => [['key' => 'r2', 'label' => $pudding['name']]]]]]],
    'gerichte' => ['r1' => $gericht, 'r2' => $pudding],
    'basisrezepte' => ['b1' => ['name' => 'Pasta: Linguine', 'ansatz_kg' => 1, 'zeilen' => [['name' => 'Linguine', 'menge' => 1000, 'einheit' => 'Gramm', 'sub' => null]], 'schritte' => ['Linguine bissfest kochen.']], 'b2' => $soße],
    'legende' => ['allergene' => [['code' => 'A', 'label' => 'Gluten'], ['code' => 'G', 'label' => 'Milch'], ['code' => 'B', 'label' => 'Krebstiere']]],
];
