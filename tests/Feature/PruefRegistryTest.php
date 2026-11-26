<?php

use Platform\FoodAlchemist\Services\Conformance\ConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\GpConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\LaConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\Pruefung\PruefRegistry;
use Platform\FoodAlchemist\Services\Reife\AngebotReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\ConceptReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\FoodbookReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\FormatReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\ReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\ReifeService;
use Platform\FoodAlchemist\Services\Reife\SpeisekarteReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\SpeiseplanReifeAdapter;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * PR11 (Plan 98): Prüf-Registry. Die Adapter-Auswahl von Reife und Conformance kommt aus Container-Tags statt aus einem festen
 * `match`. Der Wächter hält drei Dinge fest: jede Adapter-Klasse unter src ist registriert (sonst fiele ein Artefakt-Typ still
 * aus), kein Schlüssel ist doppelt, und jeder bisherige Schlüssel zeigt auf denselben Adapter wie vorher (Verhalten unverändert).
 */

/** @return list<class-string> konkrete Klassen unter src, die das Interface implementieren */
function pruefAdapterKlassen(string $interface): array
{
    $src = dirname(__DIR__, 2) . '/src';
    $klassen = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $d) {
        if ($d->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($d->getPathname());
        if (! preg_match('/^namespace ([^;]+);/m', $code, $ns) || ! preg_match('/^(?:final\s+)?class\s+(\w+)/m', $code, $k)) {
            continue;
        }
        $fqn = $ns[1] . '\\' . $k[1];
        if (class_exists($fqn) && is_subclass_of($fqn, $interface) && ! (new ReflectionClass($fqn))->isAbstract()) {
            $klassen[] = $fqn;
        }
    }
    sort($klassen);

    return $klassen;
}

it('jede Reife- und Conformance-Adapter-Klasse unter src ist registriert (Liste und Tag)', function () {
    $sortiert = fn (array $l) => collect($l)->sort()->values()->all();
    $getaggt = fn (string $tag) => collect(app()->tagged($tag))->map(fn ($a) => $a::class)->sort()->values()->all();

    $klassen = app(PruefRegistry::class)->klassen();

    expect($sortiert($klassen['reife']))->toBe(pruefAdapterKlassen(ReifeAdapter::class))
        ->and($sortiert($klassen['conformance']))->toBe(pruefAdapterKlassen(ConformanceAdapter::class))
        ->and($getaggt(PruefRegistry::TAG_REIFE))->toBe(pruefAdapterKlassen(ReifeAdapter::class))
        ->and($getaggt(PruefRegistry::TAG_CONFORMANCE))->toBe(pruefAdapterKlassen(ConformanceAdapter::class));
});

it('ein im Container gebundener Ersatz (Test-Mock) wird über den registrierten Namen gefunden', function () {
    $ersatz = Mockery::mock(GpConformanceAdapter::class)->makePartial();
    app()->instance(GpConformanceAdapter::class, $ersatz);

    expect(app(PruefRegistry::class)->conformanceAdapter('grundprodukt'))->toBe($ersatz);
});

it('jeder bisherige Schlüssel zeigt auf denselben Adapter wie das alte match (Verhalten unverändert)', function () {
    $vorher = [
        'reife' => [
            'recipe' => RecipeReifeAdapter::class, 'basisrezept' => RecipeReifeAdapter::class, 'sales_recipe' => RecipeReifeAdapter::class,
            'vk' => RecipeReifeAdapter::class, 'gericht' => RecipeReifeAdapter::class,
            'concept' => ConceptReifeAdapter::class, 'paket' => ConceptReifeAdapter::class,
            'format' => FormatReifeAdapter::class, 'foodbook' => FoodbookReifeAdapter::class,
            'speisekarte' => SpeisekarteReifeAdapter::class, 'speiseplan' => SpeiseplanReifeAdapter::class,
            'angebot' => AngebotReifeAdapter::class, 'offer' => AngebotReifeAdapter::class,
        ],
        'conformance' => [
            'recipe' => RecipeConformanceAdapter::class, 'basisrezept' => RecipeConformanceAdapter::class,
            'vk' => RecipeConformanceAdapter::class, 'gericht' => RecipeConformanceAdapter::class,
            'gp' => GpConformanceAdapter::class, 'grundprodukt' => GpConformanceAdapter::class,
            'la' => LaConformanceAdapter::class, 'lieferantenartikel' => LaConformanceAdapter::class,
        ],
    ];
    ksort($vorher['reife']);
    ksort($vorher['conformance']);
    $registry = app(PruefRegistry::class);

    expect($registry->schluessel())->toBe($vorher)
        ->and(array_keys($vorher['reife']))->toEqualCanonicalizing(ReifeService::KINDS);   // KINDS (Tools, Doku) bleibt deckungsgleich
    foreach ($vorher['reife'] as $kind => $klasse) {
        expect($registry->reifeAdapter($kind))->toBeInstanceOf($klasse);
    }
    foreach ($vorher['conformance'] as $typ => $klasse) {
        expect($registry->conformanceAdapter($typ))->toBeInstanceOf($klasse);
    }
});

it('unbekannter Typ: dieselbe Meldung wie vorher', function () {
    $registry = app(PruefRegistry::class);

    expect(fn () => $registry->reifeAdapter('gibtsnicht'))->toThrow(\InvalidArgumentException::class, 'Kein Reife-Adapter für Artefakt-Typ «gibtsnicht».')
        ->and(fn () => $registry->conformanceAdapter('gibtsnicht'))->toThrow(\InvalidArgumentException::class, 'Kein Conformance-Adapter für Artefakt-Typ «gibtsnicht».');
});

it('ein doppelt beanspruchter Schlüssel ist ein Fehler', function () {
    $doppelt = new class extends RecipeConformanceAdapter
    {
        public function artifactType(): string
        {
            return 'gp';
        }
    };

    expect(fn () => PruefRegistry::karte([GpConformanceAdapter::class => app(GpConformanceAdapter::class), 'Doppelt' => $doppelt], 'Conformance'))
        ->toThrow(\LogicException::class, 'Conformance-Schlüssel «gp» doppelt: ' . GpConformanceAdapter::class . ' und Doppelt');
});
