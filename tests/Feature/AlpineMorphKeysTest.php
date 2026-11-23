<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Alpine-Ausnahmen auf io (offen/pos/handy/resizing/breite is not defined, Illegal invocation): Livewires Morph paart
 * Elemente nach Position, Tag und Schlüssel (wire:id > wire:key > id). Trifft ein faMenu- oder Seitenleisten-Wurzel-
 * element auf ein fremdes, schlüsselloses Alpine-Element, übernimmt Alpine.cloneNode dessen Daten und überspringt das
 * eigene x-data — die Bindungen laufen gegen den falschen Scope. Abhilfe: jede dieser Wurzeln trägt einen Schlüssel je
 * Baustein-Typ; fremde Paare werden ausgetauscht statt gepatcht. Ein eigener wire:key des Aufrufers gewinnt.
 * Dazu räumen die Bausteine ihre Fenster- und MediaQuery-Listener in destroy() ab.
 */
/**
 * Popover mit benanntem Slot lässt beim Rendern einen Ausgabepuffer offen (Bestand, auch x-fa::modal; eigener Befund).
 * Hier wieder schließen, damit dieser Test nicht „risky“ wird.
 */
function ohnePufferleck(string $blade): string
{
    $stufe = ob_get_level();
    try {
        return Blade::render($blade);
    } finally {
        while (ob_get_level() > $stufe) {
            ob_end_clean();
        }
    }
}

/** Erstes Tag mit x-data, bis zum schließenden > außerhalb von Anführungszeichen (x-data enthält „=>“). */
function wurzel(string $html): string
{
    $start = strpos($html, '<div');
    while ($start !== false) {
        $zeichen = null;
        for ($i = $start; $i < strlen($html); $i++) {
            $c = $html[$i];
            if ($zeichen !== null) {
                if ($c === $zeichen) {
                    $zeichen = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $zeichen = $c;
            } elseif ($c === '>') {
                break;
            }
        }
        $tag = substr($html, $start, $i - $start + 1);
        if (str_contains($tag, 'x-data=')) {
            return $tag;
        }
        $start = strpos($html, '<div', $i);
    }

    return '';
}

it('Menü und Popover tragen einen Typ-Schlüssel, ein eigener wire:key gewinnt', function () {
    $menu = Blade::render('<x-fa::menu><x-fa::menu-item>Eintrag</x-fa::menu-item></x-fa::menu>');
    $menuEigen = Blade::render('<x-fa::menu wire:key="zeile-5"><x-fa::menu-item>Eintrag</x-fa::menu-item></x-fa::menu>');
    $popover = ohnePufferleck('<x-fa::popover label="Kasten"><x-slot:ausloeser><button type="button">Auf</button></x-slot:ausloeser>Inhalt</x-fa::popover>');
    $popoverEigen = ohnePufferleck('<x-fa::popover label="Kasten" wire:key="kasten-3"><x-slot:ausloeser><button type="button">Auf</button></x-slot:ausloeser>Inhalt</x-fa::popover>');

    expect(wurzel($menu))->toContain('x-data="faMenu()"')->toContain('wire:key="fa-menu"')
        ->and(wurzel($menuEigen))->toContain('wire:key="zeile-5"')->not->toContain('fa-menu')
        ->and(substr_count(wurzel($menuEigen), 'wire:key='))->toBe(1)
        ->and(wurzel($popover))->toContain('wire:key="fa-popover"')
        ->and(wurzel($popoverEigen))->toContain('wire:key="kasten-3"')->not->toContain('fa-popover')
        ->and(substr_count(wurzel($popoverEigen), 'wire:key='))->toBe(1);
});

it('Seitenleisten tragen einen Typ-Schlüssel je Scope, ein eigener wire:key gewinnt', function () {
    $seite = Blade::render('<x-fa::page-sidebar title="Filter">Inhalt</x-fa::page-sidebar>');
    $aktivitaet = Blade::render('<x-fa::page-sidebar title="Aktivität" store-key="activityOpen">Inhalt</x-fa::page-sidebar>');
    $detail = Blade::render('<x-fa::detail-sidebar title="Detail" scope="rezepte">Inhalt</x-fa::detail-sidebar>');
    $detailEigen = Blade::render('<x-fa::detail-sidebar title="Detail" scope="rezepte" wire:key="eigen">Inhalt</x-fa::detail-sidebar>');

    expect(wurzel($seite))->toContain('wire:key="fa-page-sidebar-page_sidebar"')
        ->and(wurzel($aktivitaet))->toContain('wire:key="fa-page-sidebar-activity"')
        ->and(wurzel($detail))->toContain('wire:key="fa-detail-sidebar-rezepte"')
        ->and(wurzel($detailEigen))->toContain('wire:key="eigen"')->not->toContain('fa-detail-sidebar-')
        ->and(substr_count(wurzel($detailEigen), 'wire:key='))->toBe(1);
});

it('Fenster- und MediaQuery-Listener werden in destroy() abgeräumt', function () {
    $seite = Blade::render('<x-fa::page-sidebar title="Filter">Inhalt</x-fa::page-sidebar>');
    $detail = Blade::render('<x-fa::detail-sidebar title="Detail" scope="rezepte">Inhalt</x-fa::detail-sidebar>');
    $menuSkript = view('foodalchemist::components.shell.fa-menu-script')->render();

    foreach (['page-sidebar' => $seite, 'detail-sidebar' => $detail] as $name => $html) {
        expect($html)->toContain('destroy() { this.aufraeumen && this.aufraeumen(); }')
            ->toContain("window.removeEventListener('resize', aufBreite)")
            ->toContain("mqHandy.removeEventListener('change', aufHandy)");
    }
    expect($detail)->toContain("mq.removeEventListener('change', aufWeit)")->toContain('beobachter.disconnect()')
        ->and($menuSkript)->toContain('destroy() { if (this.aufraeumen) this.aufraeumen(); }')
        ->toContain("window.removeEventListener('scroll', zu, true)")
        ->toContain("window.removeEventListener('resize', zu)");
});

it('Wächter: jede faMenu-Wurzel in den Views trägt einen Morph-Schlüssel', function () {
    $verstoesse = [];
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views'));
    foreach ($views as $datei) {
        if (! str_ends_with($datei->getFilename(), '.blade.php')) {
            continue;
        }
        $inhalt = file_get_contents($datei->getPathname());
        // Kommentare zählen nicht (die Skript-Doku nennt x-data="faMenu()" als Nutzungshinweis)
        $ohneKommentare = preg_replace('/\{\{--.*?--\}\}/s', '', $inhalt);
        preg_match_all('/<[a-z][^>]*x-data="faMenu\(\)"[^>]*>/s', $ohneKommentare, $treffer);
        foreach ($treffer[0] as $tag) {
            // Bausteine mischen den Schlüssel über ihre Hülle ein ($huelle->merge(['wire:key' => …]))
            $ueberHuelle = str_contains($tag, '$huelle') && str_contains($ohneKommentare, "->merge(['wire:key' =>");
            if (! str_contains($tag, 'wire:key') && ! $ueberHuelle) {
                $verstoesse[] = str_replace(dirname(__DIR__, 2).'/', '', $datei->getPathname()).': '.mb_substr(trim($tag), 0, 120);
            }
        }
    }

    expect($verstoesse)->toBe([]);
});
