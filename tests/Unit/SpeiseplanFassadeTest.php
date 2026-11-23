<?php

use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;

/**
 * 98 · 3.3 (S3): SpeiseplanService wird in Teile unter Services/Speiseplan zerlegt und bleibt Fassade. Dieser Test friert die
 * öffentliche Schnittstelle ein (Name, Parameter mit Typ/Default, Rückgabetyp, Konstanten): Tools, Livewire und Dienste
 * rufen weiter die Fassade, ein verlorener oder veränderter Delegator fällt hier auf. Abbau der Fassade erst in 3.8.
 *
 * Golden neu schreiben (nur bei gewollter Schnittstellen-Änderung, im PR begründen): SPEISEPLAN_FASSADE_SCHREIBEN=1.
 */
const SPEISEPLAN_FASSADE_GOLDEN = __DIR__.'/../Fixtures/speiseplan_fassade.json';

function speiseplanFassadeIst(): array
{
    $klasse = new ReflectionClass(SpeiseplanService::class);
    $typ = fn (?ReflectionType $t) => $t === null ? '' : (string) $t;
    $methoden = [];
    foreach ($klasse->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== SpeiseplanService::class || $m->isConstructor()) {
            continue;
        }
        $parameter = array_map(fn (ReflectionParameter $p) => trim($typ($p->getType()).' '.($p->isVariadic() ? '...' : '').'$'.$p->getName()
            .($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : '')), $m->getParameters());
        $methoden[$m->getName()] = ($m->isStatic() ? 'static ' : '').'('.implode(', ', $parameter).'): '.$typ($m->getReturnType());
    }
    ksort($methoden);
    $oeffentlich = $klasse->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC);   // private Konstanten ziehen mit ihrem Teil um
    $konstanten = array_combine(array_map(fn ($c) => $c->getName(), $oeffentlich), array_map(fn ($c) => $c->getValue(), $oeffentlich));
    ksort($konstanten);

    return ['methoden' => $methoden, 'konstanten' => $konstanten];
}

it('die öffentliche Schnittstelle der SpeiseplanService-Fassade bleibt beim Zerlegen gleich', function () {
    $ist = speiseplanFassadeIst();
    if (getenv('SPEISEPLAN_FASSADE_SCHREIBEN')) {
        file_put_contents(SPEISEPLAN_FASSADE_GOLDEN, json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Golden neu geschrieben: '.SPEISEPLAN_FASSADE_GOLDEN);
    }
    $soll = json_decode((string) file_get_contents(SPEISEPLAN_FASSADE_GOLDEN), true);

    expect(array_keys($ist['methoden']))->toBe(array_keys($soll['methoden']))
        ->and($ist['methoden'])->toBe($soll['methoden'])
        ->and($ist['konstanten'])->toBe($soll['konstanten']);
});
