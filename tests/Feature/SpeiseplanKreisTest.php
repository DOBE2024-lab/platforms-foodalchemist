<?php

use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanDokumentQuelle;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\AbhaengigkeitsGraph;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * 98 · 3.3 S3-0: PresentationService erreicht den Speiseplan-Aushang nur über SpeiseplanDokumentQuelle (Muster K3).
 * Ohne Provider-Bindung bräche der Aushang in der Präsentation; mit direkter Kante säße SpeiseplanService wieder im Kreis
 * und jedes Teil unter Services/Speiseplan mit ihm.
 */
it('die Dokument-Quelle ist an SpeiseplanService gebunden', function () {
    expect(app(SpeiseplanDokumentQuelle::class))->toBeInstanceOf(SpeiseplanService::class);
});

it('SpeiseplanService und seine Teile liegen in keinem Kreis; PresentationService nutzt nur die Quelle', function () {
    $kanten = AbhaengigkeitsGraph::kanten(dirname(__DIR__, 2).'/src');

    foreach (AbhaengigkeitsGraph::kreise($kanten) as $kreis) {
        $speiseplan = array_values(array_filter($kreis, fn ($k) => str_starts_with($k, 'Speiseplan')));
        expect($speiseplan)->toBe([], 'im Kreis: '.implode(', ', $speiseplan));
    }
    expect($kanten['PresentationService'])->not->toContain('SpeiseplanService');
});
