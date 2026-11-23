<?php

use Platform\FoodAlchemist\Services\OrderService;

/**
 * 98 · 3.3 (S5): OrderService wird in Teile unter Services/Bestellung zerlegt und bleibt Fassade. Dieser Test friert die
 * öffentliche Schnittstelle ein (Name, Parameter mit Typ/Default, Rückgabetyp, Konstanten): Tools, Livewire und Dienste
 * rufen weiter die Fassade, ein verlorener oder veränderter Delegator fällt hier auf. Abbau der Fassade erst in 3.8.
 *
 * Golden neu schreiben (nur bei gewollter Schnittstellen-Änderung, im PR begründen): ORDER_FASSADE_SCHREIBEN=1.
 */
const ORDER_FASSADE_GOLDEN = __DIR__.'/../Fixtures/order_fassade.json';

function orderFassadeIst(): array
{
    $klasse = new ReflectionClass(OrderService::class);
    $typ = fn (?ReflectionType $t) => $t === null ? '' : (string) $t;
    $methoden = [];
    foreach ($klasse->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== OrderService::class || $m->isConstructor()) {
            continue;
        }
        $parameter = array_map(fn (ReflectionParameter $p) => trim($typ($p->getType()).' '.($p->isVariadic() ? '...' : '').'$'.$p->getName()
            .($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : '')), $m->getParameters());
        $methoden[$m->getName()] = ($m->isStatic() ? 'static ' : '').'('.implode(', ', $parameter).'): '.$typ($m->getReturnType());
    }
    ksort($methoden);
    $konstanten = array_map(fn ($w) => $w, $klasse->getConstants());
    ksort($konstanten);

    return ['methoden' => $methoden, 'konstanten' => $konstanten];
}

it('die öffentliche Schnittstelle der OrderService-Fassade bleibt beim Zerlegen gleich', function () {
    $ist = orderFassadeIst();
    if (getenv('ORDER_FASSADE_SCHREIBEN')) {
        file_put_contents(ORDER_FASSADE_GOLDEN, json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Golden neu geschrieben: '.ORDER_FASSADE_GOLDEN);
    }
    $soll = json_decode((string) file_get_contents(ORDER_FASSADE_GOLDEN), true);

    expect(array_keys($ist['methoden']))->toBe(array_keys($soll['methoden']))
        ->and($ist['methoden'])->toBe($soll['methoden'])
        ->and($ist['konstanten'])->toBe($soll['konstanten']);
});
