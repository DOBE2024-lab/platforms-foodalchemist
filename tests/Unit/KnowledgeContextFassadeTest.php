<?php

use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/**
 * 98 · 3.3 (KI-Strang a): KnowledgeContextService wird in Teile unter Services/Ai zerlegt und bleibt Fassade. Dieser Test friert
 * die öffentliche Schnittstelle ein (Name, Parameter mit Typ/Default, Rückgabetyp, öffentliche Konstanten mit Wert): Gateway,
 * Generatoren, MCP-Tools und Berichte rufen weiter die Fassade, ein verlorener oder veränderter Delegator fällt hier auf.
 * Muster wie FoodbookFassadeTest (S4-0).
 *
 * Golden neu schreiben (nur bei gewollter Schnittstellen-Änderung, im PR begründen): KNOWLEDGE_CONTEXT_FASSADE_SCHREIBEN=1.
 */
const KNOWLEDGE_CONTEXT_FASSADE_GOLDEN = __DIR__.'/../Fixtures/knowledge_context_fassade.json';

function knowledgeContextFassadeIst(): array
{
    $klasse = new ReflectionClass(KnowledgeContextService::class);
    $typ = fn (?ReflectionType $t) => $t === null ? '' : (string) $t;
    $methoden = [];
    foreach ($klasse->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== KnowledgeContextService::class || $m->isConstructor()) {
            continue;
        }
        $parameter = array_map(fn (ReflectionParameter $p) => trim($typ($p->getType()).' '.($p->isPassedByReference() ? '&' : '').($p->isVariadic() ? '...' : '').'$'.$p->getName()
            .($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : '')), $m->getParameters());
        $methoden[$m->getName()] = ($m->isStatic() ? 'static ' : '').'('.implode(', ', $parameter).'): '.$typ($m->getReturnType());
    }
    ksort($methoden);
    $oeffentlich = $klasse->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC);   // private Konstanten ziehen mit ihrem Teil um
    $konstanten = array_combine(array_map(fn ($c) => $c->getName(), $oeffentlich), array_map(fn ($c) => $c->getValue(), $oeffentlich));
    ksort($konstanten);

    return ['methoden' => $methoden, 'konstanten' => $konstanten];
}

it('die öffentliche Schnittstelle der KnowledgeContextService-Fassade bleibt beim Zerlegen gleich', function () {
    $ist = knowledgeContextFassadeIst();
    if (getenv('KNOWLEDGE_CONTEXT_FASSADE_SCHREIBEN')) {
        file_put_contents(KNOWLEDGE_CONTEXT_FASSADE_GOLDEN, json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Golden neu geschrieben: '.KNOWLEDGE_CONTEXT_FASSADE_GOLDEN);
    }
    $soll = json_decode((string) file_get_contents(KNOWLEDGE_CONTEXT_FASSADE_GOLDEN), true);

    expect(array_keys($ist['methoden']))->toBe(array_keys($soll['methoden']))
        ->and($ist['methoden'])->toBe($soll['methoden'])
        ->and($ist['konstanten'])->toBe($soll['konstanten']);
});

it('die Fassade bleibt ohne Konstruktor-Argumente baubar (KnowledgeEmbeddingService ruft new KnowledgeContextService())', function () {
    expect((new KnowledgeContextService())->truncate('abc', 10))->toBe('abc');
});
