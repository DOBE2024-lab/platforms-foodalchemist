<?php

use Platform\FoodAlchemist\Enums\SignalTyp;

/**
 * foodalchemist_signals.type ist varchar(64) (Migration 2026_12_31_100000). SQLite-Tests erzwingen die Länge nicht,
 * MySQL schon: ein zu langer Typ bricht den ganzen Detektor-Lauf ab (io, 15.11.2026: trendradar_mcp_quelle_ueberfaellig, 34 > 32).
 */
it('jeder SignalTyp passt in die Spalte foodalchemist_signals.type (64 Zeichen)', function () {
    $zuLang = array_values(array_filter(array_map(fn (SignalTyp $t) => $t->value, SignalTyp::cases()), fn (string $v) => strlen($v) > 64));

    expect($zuLang)->toBe([]);
});
