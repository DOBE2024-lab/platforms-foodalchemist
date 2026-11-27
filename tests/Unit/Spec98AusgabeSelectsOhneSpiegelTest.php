<?php

/**
 * 98 · 3.5 Schritt 6a C (Ausgabe): Nach Dominiques „fest 1“ (27.11.) braucht der EK je Portion (DarreichungResolver::ekProPortion,
 * PositionPreisService::blockPreis) die Spalte sales_unit_count nicht mehr. Die Eager-Loads der Ausgabe-Dienste laden sie deshalb nicht
 * mehr mit. Der Wächter hält das fest: Kommt das Spiegelfeld in einen dieser Selects zurück, ist es ein neuer Leser vor dem
 * Spalten-Drop (6c).
 */
it('Ausgabe-Dienste laden das Spiegelfeld sales_unit_count nicht mehr', function () {
    $modul = dirname(__DIR__, 2);
    $dateien = [
        'src/Services/Ausgabe/OfferCompositionService.php',
        'src/Services/Foodbook/FoodbookBasis.php',
        'src/Services/Foodbook/FoodbookDokument.php',
        'src/Services/Foodbook/FoodbookAggregat.php',
        'src/Services/Ausgabe/SpeisekarteService.php',
        'src/Services/Planung/LeitstelleService.php',
        'src/Services/Ausgabe/MenuEngineeringService.php',
    ];
    $treffer = [];
    foreach ($dateien as $d) {
        foreach (explode("\n", (string) file_get_contents("{$modul}/{$d}")) as $i => $zeile) {
            if (str_contains($zeile, 'sales_unit_count')) {
                $treffer[] = "{$d}:".($i + 1);
            }
        }
    }

    expect($treffer)->toBe([]);
});
