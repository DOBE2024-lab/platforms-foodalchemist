<?php

use Platform\FoodAlchemist\Support\AngabeKennzeichen;

/**
 * Regel 91 v3 (Entscheid Dominique N-G2, 14.11.2026): ein offenes GP geht in das EINE belegte GP derselben Ware auf; ein belegtes GP nie in ein offenes.
 */
it('Regel 91 v3: offen → belegt ja, belegt → offen nein, gleiche Lage ja', function () {
    expect(AngabeKennzeichen::zusammenfuehrbar('offen', null))->toBeTrue()            // offener Verlierer in belegten Sieger
        ->and(AngabeKennzeichen::zusammenfuehrbar('offen', 'angenommen'))->toBeTrue()
        ->and(AngabeKennzeichen::zusammenfuehrbar(null, 'offen'))->toBeFalse()        // belegter Verlierer nie in offenen Sieger
        ->and(AngabeKennzeichen::zusammenfuehrbar('angenommen', 'offen'))->toBeFalse()
        ->and(AngabeKennzeichen::zusammenfuehrbar('offen', 'offen'))->toBeTrue()
        ->and(AngabeKennzeichen::zusammenfuehrbar(null, null))->toBeTrue();
});
