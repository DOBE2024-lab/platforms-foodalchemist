<?php

use Platform\FoodAlchemist\Services\Rezept\RecipeGeneratorService;

/**
 * 98 · 3.3 (KI-Strang b): RecipeGeneratorService wird in Teile unter Services/Rezept zerlegt und bleibt Fassade. Friert die
 * öffentliche Schnittstelle ein (Signatur und öffentliche Konstanten), dazu die privaten Methoden, die Tests per Reflection
 * rufen (dedupGate, sortiereNachRolle): sie bleiben als Delegator an der Fassade. Muster wie FoodbookFassadeTest (S4-0).
 *
 * Golden neu schreiben (nur bei gewollter Schnittstellen-Änderung, im PR begründen): RECIPE_GENERATOR_FASSADE_SCHREIBEN=1.
 */
const RECIPE_GENERATOR_FASSADE_GOLDEN = __DIR__.'/../Fixtures/recipe_generator_fassade.json';

function recipeGeneratorFassadeIst(): array
{
    $klasse = new ReflectionClass(RecipeGeneratorService::class);
    $typ = fn (?ReflectionType $t) => $t === null ? '' : (string) $t;
    $signatur = function (ReflectionMethod $m) use ($typ) {
        $parameter = array_map(fn (ReflectionParameter $p) => trim($typ($p->getType()).' '.($p->isPassedByReference() ? '&' : '').($p->isVariadic() ? '...' : '').'$'.$p->getName()
            .($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : '')), $m->getParameters());

        return ($m->isStatic() ? 'static ' : '').'('.implode(', ', $parameter).'): '.$typ($m->getReturnType());
    };
    $methoden = [];
    foreach ($klasse->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() === RecipeGeneratorService::class && ! $m->isConstructor()) {
            $methoden[$m->getName()] = $signatur($m);
        }
    }
    ksort($methoden);
    $perReflection = [];
    foreach (['dedupGate', 'sortiereNachRolle'] as $name) {
        $perReflection[$name] = $signatur($klasse->getMethod($name));
    }
    $oeffentlich = $klasse->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC);
    $konstanten = array_combine(array_map(fn ($c) => $c->getName(), $oeffentlich), array_map(fn ($c) => $c->getValue(), $oeffentlich));
    ksort($konstanten);

    return ['methoden' => $methoden, 'per_reflection' => $perReflection, 'konstanten' => $konstanten];
}

it('die öffentliche Schnittstelle der RecipeGeneratorService-Fassade bleibt beim Zerlegen gleich', function () {
    $ist = recipeGeneratorFassadeIst();
    if (getenv('RECIPE_GENERATOR_FASSADE_SCHREIBEN')) {
        file_put_contents(RECIPE_GENERATOR_FASSADE_GOLDEN, json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Golden neu geschrieben: '.RECIPE_GENERATOR_FASSADE_GOLDEN);
    }
    $soll = json_decode((string) file_get_contents(RECIPE_GENERATOR_FASSADE_GOLDEN), true);

    expect($ist)->toBe($soll);
});
