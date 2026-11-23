<?php

use Platform\FoodAlchemist\Services\Preis\MargeService;
use Platform\FoodAlchemist\Support\Masse;

/**
 * Code-Qualität 2.2a: die Geld-Helfer, auf die die Handrechnungen umgestellt sind. Die Semantik ist die der
 * bisherigen Stellen: VK fehlt oder ≤ 0 → keine Quote; EK fehlt → keine Quote; EK 0 bei gültigem VK → 0 %.
 */
it('wareneinsatzPct: EK ÷ VK × 100, gerundet auf die gewünschten Stellen', function () {
    expect(MargeService::wareneinsatzPct(3.2, 12.5))->toBe(25.6)
        ->and(MargeService::wareneinsatzPct(1.0, 3.0))->toBe(33.3)
        ->and(MargeService::wareneinsatzPct(1.0, 3.0, 2))->toBe(33.33)
        ->and(MargeService::wareneinsatzPct(1.0, 3.0, 0))->toBe(33.0)
        ->and(MargeService::wareneinsatzPct(1.0, 3.0, null))->toBe(1.0 / 3.0 * 100);
});

it('wareneinsatzPct: VK null, 0 oder negativ → null', function () {
    expect(MargeService::wareneinsatzPct(2.0, null))->toBeNull()
        ->and(MargeService::wareneinsatzPct(2.0, 0))->toBeNull()
        ->and(MargeService::wareneinsatzPct(2.0, 0.0))->toBeNull()
        ->and(MargeService::wareneinsatzPct(2.0, -4.0))->toBeNull()
        ->and(MargeService::wareneinsatzPct(2.0, -4.0, null))->toBeNull();
});

it('wareneinsatzPct: EK null → null, EK 0 → 0 %, negativer EK wird nicht geschönt', function () {
    expect(MargeService::wareneinsatzPct(null, 10.0))->toBeNull()
        ->and(MargeService::wareneinsatzPct(0.0, 10.0))->toBe(0.0)
        ->and(MargeService::wareneinsatzPct(-1.0, 10.0))->toBe(-10.0);
});

it('wareneinsatzPct: Zahlen als String (DB-Spalten) und int rechnen wie float', function () {
    expect(MargeService::wareneinsatzPct('1.20', '4.00'))->toBe(30.0)
        ->and(MargeService::wareneinsatzPct(3, 4))->toBe(75.0);
});

it('marge() rechnet den Wareneinsatz über denselben Helfer', function () {
    expect((new MargeService)->marge(12.5, 3.2)['wareneinsatz_pct'])->toBe(MargeService::wareneinsatzPct(3.2, 12.5));
});

it('Masse::preisJeG: Preis je kg → Preis je g, null bleibt null', function () {
    expect(Masse::preisJeG(12.5))->toBe(0.0125)
        ->and(Masse::preisJeG('8.40'))->toBe(8.4 / 1000)
        ->and(Masse::preisJeG(0))->toBe(0.0)
        ->and(Masse::preisJeG(null))->toBeNull();
});
