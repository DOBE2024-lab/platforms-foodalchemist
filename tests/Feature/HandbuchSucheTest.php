<?php

use Platform\FoodAlchemist\Support\HandbuchSuchindex;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

function handbuchSucheDom(array $snapshot): DOMXPath
{
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($dom);
}

it('zeigt ein Suchfeld mit Label, Leeren-Knopf und aria-live-Trefferzahl, ohne JS verborgen', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $x = handbuchSucheDom($snapshot);

    $block = $x->query('//nav//div[@id="handbuch-suche-block"][@role="search"]');
    expect($block->length)->toBe(1)
        ->and($block->item(0)->hasAttribute('hidden'))->toBeTrue()
        ->and($x->query('//label[@for="handbuch-suche"]')->item(0)?->textContent)->toBe('Im Handbuch suchen')
        ->and($x->query('//input[@id="handbuch-suche"][@type="search"][@aria-describedby="handbuch-suche-status"]')->length)->toBe(1)
        ->and($x->query('//*[@id="handbuch-suche-status"][@aria-live="polite"][@role="status"]')->length)->toBe(1)
        ->and($x->query('//button[@id="handbuch-suche-leeren"][@aria-label="Suche schließen"]')->length)->toBe(1)
        // Basisrezepte als eigene, nur bei Suche sichtbare Gruppe
        ->and($x->query('//nav//details[@data-nur-suche][@hidden]//a[@data-key="basis-b2"]')->length)->toBe(1);
});

it('hängt an jede Karte einen normalisierten Suchindex mit Zutaten, Schritten, Basisrezepten und Allergenen', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $snapshot['gerichte']['r1']['zubereitung'] = [['nr' => 1, 'text' => 'Zucchini in Würfel schneiden.', 'fotos' => []]];
    $snapshot['gerichte']['r1']['regeneration'] = [['komponente' => 'Soße', 'kalt' => false, 'geraet' => 'Kombidämpfer', 'temp_c' => 90, 'dauer_min' => 8, 'kern_c' => 72, 'hinweis' => 'abgedeckt']];
    $snapshot['gerichte']['r1']['bestandteile'] = [['name' => 'Basilikum', 'garnitur' => true]];
    $snapshot['freie_kapitel'] = [['title' => 'Hygiene', 'text' => 'Hände waschen vor Schichtbeginn.']];
    $x = handbuchSucheDom($snapshot);

    $r1 = $x->query('//article[@data-key="r1"]')->item(0)->getAttribute('data-suche');
    expect($r1)->toContain(
        'linguine mit tomaten mascarpone sosse und zucchini', // Name, ß→ss, Bindestrich→Leerzeichen
        'pinienkerne', 'rucola',                              // Zutaten
        'tomaten garen mascarpone einruhren',                 // Schritt eines Basisrezepts, ü→u
        'zucchini in wurfel schneiden',                       // Zubereitung
        'linguine als nest anrichten',                        // Anrichten
        'kombidampfer', 'abgedeckt',                          // Regeneration
        'basilikum',                                          // Bestandteile
        'gluten', 'milch', 'krebstiere',                      // Allergen-Namen aus der Legende
    )->and($r1)->toBe(mb_strtolower($r1));

    expect($x->query('//article[@data-key="basis-b2"]')->item(0)->getAttribute('data-suche'))->toContain('sauce tomate mascarpone', 'soja')
        ->and($x->query('//article[@data-key="k0"]')->item(0)->getAttribute('data-suche'))->toBe('hygiene hande waschen vor schichtbeginn');
});

it('nimmt keine Mengen, Preise oder sonstigen Snapshot-Felder in den Suchindex', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $snapshot['gerichte']['r1']['preis'] = 'VK-PREIS-GEHEIM 12,34';
    $snapshot['gerichte']['r1']['ek_portion'] = 'EK-GEHEIM';
    $snapshot['gerichte']['r1']['zutaten']['zeilen'][2]['ek'] = 'EK-ZEILE-GEHEIM';
    $snapshot['basisrezepte']['b2']['preis'] = 'BASIS-PREIS-GEHEIM';
    $x = handbuchSucheDom($snapshot);

    foreach ($x->query('//*[@data-suche]') as $el) {
        $index = $el->getAttribute('data-suche');
        expect($index)->not->toContain('geheim', 'gramm', '120', '1000', '12 34', 'http');
    }
});

it('normalisiert Umlaute, ß, Akzente und Satzzeichen', function () {
    expect(HandbuchSuchindex::normalisiere('Crème brûlée – Süße Soße, GROß!'))->toBe('creme brulee susse sosse gross')
        ->and(HandbuchSuchindex::normalisiere(null))->toBe('');
});

it('bringt das Such-Script mit Normalisierung, Escape-Leeren und Hervorhebung mit', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    expect($html)->toContain("normalize('NFD')", "e.key === 'Escape'", "mark.treffer", 'Keine Treffer für');
    if ($pfad = getenv('FA_HANDBUCH_SUCHE_PREVIEW_PATH')) {
        $snapshot['freie_kapitel'] = [['title' => 'Hygiene', 'text' => 'Hände waschen vor Schichtbeginn.']];
        file_put_contents($pfad, view('foodalchemist::handbuch.show', compact('snapshot'))->render());
    }
});

it('blendet die Suche standardmäßig aus und öffnet sie über einen Lupen-Knopf im Kopf', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $x = handbuchSucheDom($snapshot);

    $knopf = $x->query('//div[contains(@class,"top")]//button[@id="handbuch-suche-knopf"]');
    expect($knopf->length)->toBe(1)
        ->and($knopf->item(0)->getAttribute('aria-label'))->toBe('Suche')
        ->and($knopf->item(0)->getAttribute('aria-expanded'))->toBe('false')
        ->and($knopf->item(0)->getAttribute('aria-controls'))->toBe('handbuch-suche-block')
        ->and($knopf->item(0)->hasAttribute('hidden'))->toBeTrue()          // ohne JS verborgen
        ->and(str_contains($knopf->item(0)->getAttribute('class'), 'ico'))->toBeTrue() // Optik wie der Kontrast-Knopf
        ->and($x->query('//div[@id="handbuch-suche-block"]')->item(0)->hasAttribute('hidden'))->toBeTrue();
});

it('sucht Kapitel-, Paket- und Abschnittstitel mit und markiert sie', function () {
    $snapshot = require __DIR__ . '/../Fixtures/handbuch_ansicht.php';
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    expect($html)->toContain(
        "details > summary > span:first-of-type, .kap-text, .nav-abschnitt",   // Titel werden markiert
        "e.tagName === 'DETAILS'",                                              // Titel der Vorfahren zählen für jedes Gericht darunter
        "v.classList.contains('nav-abschnitt')",                                // Abschnitts-Überschrift im Paket
        "function sucheSchliessen()",
    );
});
