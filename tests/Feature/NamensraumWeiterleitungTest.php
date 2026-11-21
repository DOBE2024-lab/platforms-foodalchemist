<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Plan 98, 2.4a: Bausteine heißen x-fa::…. Die alten Dateien unter components/ bleiben als Weiterleitung, damit fremde
 * Aufrufe (Host food-alchemist.de: layouts/standalone mit handy.kopf, handy.nav, saved-toast) nicht brechen.
 * Ausnahme shell.*: bleibt unter x-foodalchemist, weil der Host components/shell/page-navbar überschreibt.
 */
function weiterleitungen(): array
{
    $basis = realpath(__DIR__ . '/../../resources/views/components');
    $liste = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS)) as $datei) {
        $rel = substr($datei->getPathname(), strlen($basis) + 1);
        if (! str_ends_with($rel, '.blade.php') || str_starts_with($rel, 'fa/') || str_starts_with($rel, 'shell/')) {
            continue;
        }
        $liste[str_replace('/', '.', substr($rel, 0, -strlen('.blade.php')))] = file_get_contents($datei->getPathname());
    }
    ksort($liste);

    return $liste;
}

it('jede Weiterleitung bindet nur die gleichnamige x-fa-Datei ein, und die gibt es', function () {
    $weiter = array_filter(weiterleitungen(), fn ($inhalt) => str_contains($inhalt, 'Weiterleitung (2.4a)'));
    expect($weiter)->not->toBeEmpty();
    foreach ($weiter as $name => $inhalt) {
        $ohneKommentar = trim(preg_replace('/\{\{--.*?--\}\}/s', '', $inhalt));
        expect($ohneKommentar)->toBe("@include('foodalchemist::components.fa.{$name}')", $name)
            ->and(is_file(__DIR__ . '/../../resources/views/components/fa/' . str_replace('.', '/', $name) . '.blade.php'))->toBeTrue($name);
    }
});

it('alter und neuer Name rendern gleich: modal mit Slots und Sektion', function () {
    $markup = <<<'BLADE'
        <x-NS::modal name="gp-edit" title="GP bearbeiten" :z-fest="true" :close-via="'schliessenOderZurueck'" data-test-modal="">
            <x-slot:actions>AKTIONEN-OBEN</x-slot:actions>
            <x-slot:titleExtra>EXTRA</x-slot:titleExtra>
            <x-NS::modal-section title="Stammdaten" icon="heroicon-o-cube" class="extra-klasse">SEKTION-INHALT</x-NS::modal-section>
            <x-slot:footer>FOOTER-AKTIONEN</x-slot:footer>
        </x-NS::modal>
        BLADE;
    $alt = Blade::render(str_replace('NS', 'foodalchemist', $markup));
    $neu = Blade::render(str_replace('NS', 'fa', $markup));

    expect($alt)->toBe($neu)
        ->and($neu)->toContain('AKTIONEN-OBEN')->toContain('EXTRA')->toContain('SEKTION-INHALT')->toContain('FOOTER-AKTIONEN')
        ->toContain('extra-klasse')->toContain('data-test-modal=""');
});

it('alter und neuer Name rendern gleich: ki-action mit Props und freien Attributen', function () {
    $markup = '<x-NS::ki-action action="kiProduktfoto" variant="ai" icon="heroicon-o-sparkles" label="Foto" busy="Läuft …" flash="Fertig" :disabled="false" class="w-full" data-ki-test="" />';
    $alt = Blade::render(str_replace('NS', 'foodalchemist', $markup));
    $neu = Blade::render(str_replace('NS', 'fa', $markup));

    expect($alt)->toBe($neu)
        ->and($neu)->toContain('kiProduktfoto()')->toContain('w-full')->toContain('data-ki-test=""');
});
