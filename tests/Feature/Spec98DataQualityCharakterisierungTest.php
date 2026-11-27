<?php

use Carbon\Carbon;
use Platform\FoodAlchemist\Services\Pruefung\DataQualityService;
use Platform\FoodAlchemist\Tests\Support\BestellWelt;
use Platform\FoodAlchemist\Tests\Support\Golden;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, BestellWelt::class);

/**
 * 98 · 3.3 (DataQualityService unter 1.500, PR0): Charakterisierung VOR dem Zerlegen. Hält den vollständigen Metrik-Katalog
 * fest — je Ebene und Metrik Schlüssel, Art, Label, Wert, Ampel und Signal-Deskriptor (Typ, dedup, Beschreibung, Schweregrad) —
 * sowie für jede Metrik Label, countFor und die Objekt-Liste aus betroffene() und trifftObjekt(). Die Register (Rezeptqualität,
 * Konzept, Foodbook) wandern beim Schnitt in eigene Klassen; dieser Stand muss danach Byte für Byte gleich bleiben.
 * Welt: BestellWelt (Kuchen, Vanillesauce, drei GPs mit Lead-LA und Preis). Golden: tests/Fixtures/golden/dataquality_charakter.json.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 09:00'));
    $this->bestellWeltAnlegen();
    $this->dq = app(DataQualityService::class);
    $this->golden = function (string $schluessel, mixed $ist) {
        $g = Golden::vergleich('dataquality_charakter', $schluessel, $ist);
        expect($g['ist'])->toBe($g['soll'], "Golden {$schluessel}");
    };
});

afterEach(fn () => Carbon::setTestNow());

/** Signal-Deskriptor mit Enums als Werte (Golden-tauglich). */
function dqCharakterMetrik(array $m): array
{
    $s = $m['signal'];
    if ($s !== null) {
        $s = ['typ' => $s['typ']?->value, 'dedup' => $s['dedup'], 'desc' => $s['desc'], 'sev' => $s['sev']?->value];
    }

    return ['key' => $m['key'], 'kind' => $m['kind'], 'label' => $m['label'], 'wert' => $m['wert'], 'severity' => $m['severity'], 'signal' => $s];
}

it('Katalog: alle Ebenen mit Metriken, Werten, Ampel und Signal-Deskriptoren', function () {
    $ebenen = collect($this->dq->messeAlleEbenen($this->rootTeam))
        ->map(fn ($e) => ['label' => $e['label'], 'metriken' => array_map('dqCharakterMetrik', $e['metriken'])])->all();

    expect(array_keys($ebenen))->toBe(['la', 'gp', 'basisrezept', 'gericht', 'rezeptqualitaet', 'konzept', 'foodbook', 'quer']);
    ($this->golden)('katalog', $ebenen);
});

it('je Metrik: Label, countFor, betroffene und trifftObjekt aus derselben Abfrage', function () {
    $keys = collect($this->dq->messeAlleEbenen($this->rootTeam))->flatMap(fn ($e) => array_column($e['metriken'], 'key'))->all();
    $je = [];
    foreach ($keys as $k) {
        $liste = $this->dq->betroffene($this->rootTeam, $k, 10);
        $je[$k] = [
            'label' => $this->dq->metrikLabel($k),
            'count' => $this->dq->countFor($this->rootTeam, $k),
            'betroffene' => array_map(fn ($o) => ['kind' => $o['kind'], 'name' => $o['name'], 'vk' => $o['is_sales_recipe'] ?? null], $liste),
            'trifft_erstes' => $liste === [] ? null : $this->dq->trifftObjekt($this->rootTeam, $k, $liste[0]['kind'], (int) $liste[0]['id']),
        ];
    }
    $je['_unbekannt'] = ['label' => $this->dq->metrikLabel('gibtsnicht'), 'count' => $this->dq->countFor($this->rootTeam, 'gibtsnicht'),
        'betroffene' => $this->dq->betroffene($this->rootTeam, 'gibtsnicht')];

    ($this->golden)('je_metrik', $je);
});

it('Naming-Befunde je Rezept und Emission (Anzahl emittiert/geschlossen) bleiben gleich', function () {
    $this->kuchen->update(['name' => 'DES:  Kuchen 120g ']);   // Leerraum, Grammatur
    ($this->golden)('naming_und_emission', [
        'naming_kuchen' => $this->dq->namingBefundeFuer($this->rootTeam, $this->kuchen->id),
        'naming_sauce' => $this->dq->namingBefundeFuer($this->rootTeam, $this->sauce->id),
        'emission' => $this->dq->emittiereUndSchliesse($this->rootTeam),
        'emission_nochmal' => $this->dq->emittiereSignale($this->rootTeam),
    ]);
});
