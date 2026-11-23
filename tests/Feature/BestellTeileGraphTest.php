<?php

use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * 98 · 3.3 (S5): Die Teile unter Services/Bestellung bilden einen gerichteten Graphen ohne Zyklen. Jeder Teil hat einen
 * Rang; Konstruktor-Abhängigkeiten auf andere Teile zeigen nur auf niedrigere Ränge, nie auf die Fassade OrderService.
 * Ein neuer Teil braucht hier ausdrücklich einen Rang (Entscheid Kurator 23.11.).
 */
const BESTELL_RANG = [
    'BestellSchiene' => 0,
    'BestellSummen' => 0,
    'BestellHerkunft' => 0,
    'BestellAmpeln' => 1,      // nutzt Summen
    'BestellLesen' => 0,
    'BestellDokument' => 2,    // nutzt Summen, Ampeln
    'BestellEingang' => 0,
    'BestellPflege' => 1,      // nutzt Schiene
    'BestellBedarf' => 2,      // nutzt Ampeln
    'BestellRunde' => 3,       // nutzt Ampeln, Bedarf
];

/**
 * 98 · 3.8 Ordnung: Seit dem Umzug liegen im Ordner auch die Fassade und Dienste ÜBER der Fassade (Kurator (c): Order* →
 * Bestellung/). Sie sind keine Teile, bekommen keinen Rang und dürfen die Fassade nutzen; kein Teil darf von ihnen abhängen
 * (sonst Kreis über die Fassade). Neue Nicht-Teile nur mit Grund.
 */
const BESTELL_NICHT_TEILE = [
    'OrderService' => 'Fassade',
    'OrderCostingService' => 'Kosten je Bestellung (eigener Dienst, nutzt keine Teile)',
    'OrderMailService' => 'Versand der Bestellung, nutzt die Fassade',
    'OrderTemplateService' => 'Bestellvorlagen, nutzt die Fassade',
    'BestellListeService' => 'Filterweg für Browser und orders.GET über der Fassade (Spec 91 PR 4)',
];

/** @return array<string, list<string>> Teil => Teile (kurz), von denen er per Konstruktor abhängt */
function bestellTeileKanten(): array
{
    $kanten = [];
    foreach (glob(dirname(__DIR__, 2).'/src/Services/Bestellung/*.php') as $datei) {
        if (array_key_exists(basename($datei, '.php'), BESTELL_NICHT_TEILE)) {
            continue;
        }
        $klasse = 'Platform\\FoodAlchemist\\Services\\Bestellung\\'.basename($datei, '.php');
        $ctor = (new ReflectionClass($klasse))->getConstructor();
        $kanten[basename($datei, '.php')] = array_values(array_map(
            fn (ReflectionParameter $p) => (string) $p->getType(),
            $ctor?->getParameters() ?? [],
        ));
    }
    ksort($kanten);

    return $kanten;
}

it('jeder Teil hat einen Rang und hängt nur von niedrigeren Teilen ab, nie von der Fassade', function () {
    $verstoesse = [];
    foreach (bestellTeileKanten() as $teil => $typen) {
        if (! array_key_exists($teil, BESTELL_RANG)) {
            $verstoesse[] = "{$teil}: kein Rang in BESTELL_RANG";

            continue;
        }
        foreach ($typen as $typ) {
            $kurz = class_basename($typ);
            if ($typ === OrderService::class) {
                $verstoesse[] = "{$teil} → OrderService (Fassade)";
            } elseif (array_key_exists($kurz, BESTELL_NICHT_TEILE) && str_starts_with($typ, 'Platform\\FoodAlchemist\\Services\\Bestellung\\')) {
                $verstoesse[] = "{$teil} → {$kurz} (kein Teil: ".BESTELL_NICHT_TEILE[$kurz].')';
            } elseif (str_starts_with($typ, 'Platform\\FoodAlchemist\\Services\\Bestellung\\') && (BESTELL_RANG[$kurz] ?? PHP_INT_MAX) >= BESTELL_RANG[$teil]) {
                $verstoesse[] = "{$teil} (Rang ".BESTELL_RANG[$teil].") → {$kurz} (Rang ".(BESTELL_RANG[$kurz] ?? '?').')';
            }
        }
    }

    expect($verstoesse)->toBe([]);
});

it('kein Teil greift im Code auf die Fassade zu (auch nicht per app())', function () {
    $treffer = [];
    foreach (glob(dirname(__DIR__, 2).'/src/Services/Bestellung/*.php') as $datei) {
        if (array_key_exists(basename($datei, '.php'), BESTELL_NICHT_TEILE)) {
            continue;   // Fassade und Dienste über der Fassade dürfen sie nutzen
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($datei)) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
        }
        if (preg_match('/\bOrderService\b/', $code)) {
            $treffer[] = basename($datei);
        }
    }

    expect($treffer)->toBe([]);
});

it('Fassade und alle Teile lassen sich aus dem Container auflösen', function () {
    expect(app(OrderService::class))->toBeInstanceOf(OrderService::class);
    foreach (array_keys(BESTELL_RANG) as $teil) {
        expect(app('Platform\\FoodAlchemist\\Services\\Bestellung\\'.$teil))->toBeObject();
    }
});

it('Selbsttest: eine Kante auf gleichen oder höheren Rang wäre rot', function () {
    $rang = ['A' => 0, 'B' => 1];
    $erlaubt = fn (string $von, string $nach) => $rang[$nach] < $rang[$von];

    expect($erlaubt('B', 'A'))->toBeTrue()
        ->and($erlaubt('A', 'B'))->toBeFalse()
        ->and($erlaubt('A', 'A'))->toBeFalse();
});

it('Herkunfts-Format: ProductionOrderService führt Präfix und Basis als Alias auf BestellHerkunft (EIN Ort, P4)', function () {
    $po = \Platform\FoodAlchemist\Services\ProductionOrderService::class;
    $herkunft = \Platform\FoodAlchemist\Services\Bestellung\BestellHerkunft::class;

    expect($po::SOURCE_REF_BASE)->toBe('produktion:')
        ->and($herkunft::PRODUKTION_BASIS)->toBe($po::SOURCE_REF_BASE)
        ->and($po::sourceRefPrefix(1))->toBe('produktion:1:')
        ->and($herkunft::produktionsPraefix(10))->toBe($po::sourceRefPrefix(10))
        ->and($po::sourceRefFor(7, 'x'))->toBe('produktion:7:x');

    $geparst = app($herkunft)->parseHerkunft([$po::sourceRefFor(42, 'ref')]);
    expect($geparst[0]['production_order_id'])->toBe(42);
});

it('Herkunfts-Format: produktionsAuftragId liest nur `produktion:{id}:` und unterscheidet #1 von #10', function () {
    $h = \Platform\FoodAlchemist\Services\Bestellung\BestellHerkunft::class;

    expect($h::produktionsAuftragId('produktion:12:abc'))->toBe(12)
        ->and($h::produktionsAuftragId($h::produktionsPraefix(10).'x'))->toBe(10)
        ->and($h::produktionsAuftragId('produktion:1'))->toBeNull()      // ohne trailing-Doppelpunkt kein Treffer
        ->and($h::produktionsAuftragId('produktion:abc:x'))->toBeNull()
        ->and($h::produktionsAuftragId('concept:5@x'))->toBeNull()
        ->and($h::produktionsAuftragId('vorproduktion:5:'))->toBeNull()
        ->and($h::produktionsAuftragId(''))->toBeNull();
});
