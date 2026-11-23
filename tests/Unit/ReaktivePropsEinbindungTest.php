<?php

/**
 * Wächter (Charge 7, io-Ausfall durch #982): Livewire 4 hydriert #[Reactive]-Props bei JEDER Parent-Anfrage aus den Props,
 * die der Parent beim Rendern übergibt — eine nicht übergebene Prop kommt als null an. Ist sie nicht nullbar typisiert,
 * wirft das einen TypeError und JEDE Anfrage des Parents endet in 500. Livewire::test rendert Kinder nur als Platzhalter
 * und fängt das nicht.
 *
 * Regel: Jede #[Reactive]-Prop mit nicht-nullbarem Typ muss in JEDER Einbindung (`<livewire:alias …>` / `@livewire('alias', …)`)
 * gesetzt werden. Sonst: nullbar machen oder übergeben.
 */

use Illuminate\Support\Str;
use Livewire\Attributes\Reactive;

function reaktiveKomponenten(): array
{
    $basis = dirname(__DIR__, 2) . '/src/Livewire';
    $treffer = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->getExtension() !== 'php' || ! str_contains((string) file_get_contents($datei->getPathname()), '#[Reactive]')) {
            continue;
        }
        $relativ = substr($datei->getPathname(), strlen($basis) + 1, -4);
        $klasse = 'Platform\\FoodAlchemist\\Livewire\\' . str_replace('/', '\\', $relativ);
        $alias = 'foodalchemist.' . implode('.', array_map(fn ($teil) => Str::kebab($teil), explode('/', $relativ)));
        $pflicht = [];
        foreach ((new ReflectionClass($klasse))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getAttributes(Reactive::class) === []) {
                continue;
            }
            $typ = $prop->getType();
            if ($typ !== null && ! $typ->allowsNull()) {
                $pflicht[] = $prop->getName();
            }
        }
        $treffer[$alias] = ['klasse' => $klasse, 'pflicht' => $pflicht];
    }

    return $treffer;
}

/** @return list<array{datei: string, zeile: int, tag: string}> */
function einbindungen(string $alias): array
{
    $views = dirname(__DIR__, 2) . '/resources/views';
    $gefunden = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));
    $muster = [
        '/<livewire:' . preg_quote($alias, '/') . '(?![\w.-]).*?\/>/s',   // bis „/>“ — ein „>“ steckt auch in `$item->id`
        '/@livewire\(\s*[\'"]' . preg_quote($alias, '/') . '[\'"].*?\)\s*(?:\)|$)/ms',
    ];
    foreach ($it as $datei) {
        if (! str_ends_with($datei->getFilename(), '.blade.php')) {
            continue;
        }
        $inhalt = (string) file_get_contents($datei->getPathname());
        foreach ($muster as $m) {
            preg_match_all($m, $inhalt, $treffer, PREG_OFFSET_CAPTURE);
            foreach ($treffer[0] as [$tag, $pos]) {
                $gefunden[] = ['datei' => substr($datei->getPathname(), strlen($views) + 1), 'zeile' => substr_count(substr($inhalt, 0, $pos), "\n") + 1, 'tag' => $tag];
            }
        }
    }

    return $gefunden;
}

function setztProp(string $tag, string $prop): bool
{
    $kebab = Str::kebab($prop);

    return (bool) preg_match('/(?<![\w-]):?(' . preg_quote($kebab, '/') . '|' . preg_quote($prop, '/') . ')\s*=|[\'"]' . preg_quote($prop, '/') . '[\'"]\s*=>/', $tag);
}

it('findet die reaktiven Kinder und ihre Einbindungen (Wächter läuft nicht ins Leere)', function () {
    $komponenten = reaktiveKomponenten();
    expect($komponenten)->toHaveKey('foodalchemist.planung.aus-brief');
    foreach (array_keys($komponenten) as $alias) {
        expect(einbindungen($alias))->not->toBeEmpty("{$alias}: keine Einbindung gefunden — Alias-Ableitung prüfen");
    }
});

it('jede Einbindung setzt alle nicht-nullbaren #[Reactive]-Props (sonst null → TypeError → 500 auf jeder Parent-Anfrage)', function () {
    $fehlt = [];
    foreach (reaktiveKomponenten() as $alias => ['pflicht' => $pflicht]) {
        foreach (einbindungen($alias) as $e) {
            foreach ($pflicht as $prop) {
                if (! setztProp($e['tag'], $prop)) {
                    $fehlt[] = "{$e['datei']}:{$e['zeile']} {$alias} ohne {$prop}";
                }
            }
        }
    }

    expect($fehlt)->toBe([]);
});
