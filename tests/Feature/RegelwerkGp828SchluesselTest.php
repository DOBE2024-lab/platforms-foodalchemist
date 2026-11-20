<?php

use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.10.0 (13.11.2026) für Getränke, nachgezogen nach dem GP-Lauf 14.11.:
 * 828 R1: Schlüssel = Marke + Produkt + Gebinde + Füllmenge, 829 R6: TK/frisch sichtbar, 71a: Gebinde-Wörter + Beutel/Kanister, MW/EW kein Material.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->n = app(GpNamingService::class);
});

it('828 R1: Füllmenge und Gebinde trennen Getränke am Schlüssel, andere WG unverändert', function () {
    $basis = ['hauptzutat' => 'Coca-Cola', 'commodity_group_code' => '15'];
    $klein = $this->n->gpKeyAusFeldern('coca_cola', $basis + ['pflichtangabe' => '0,2 l']);
    $gross = $this->n->gpKeyAusFeldern('coca_cola', $basis + ['pflichtangabe' => 'PET-Flasche 1 l']);
    $ohne = $this->n->gpKeyAusFeldern('coca_cola', $basis);

    expect($klein)->not->toBe($gross)
        ->and($klein)->not->toBe($ohne)
        ->and($ohne)->toBe('coca_cola||')
        // gleiche Pflichtangabe = gleicher Schlüssel (Zusammenführen bleibt möglich)
        ->and($this->n->gpKeyAusFeldern('coca_cola', $basis + ['pflichtangabe' => '0,2 l']))->toBe($klein)
        // außerhalb WG 15 trennt die Pflichtangabe nur einen degenerierten Schlüssel (20.11.2026, gp-key-merkmale); mit Verarbeitung bleibt er unverändert
        ->and($this->n->gpKeyAusFeldern('reis', ['hauptzutat' => 'Reis', 'commodity_group_code' => '07', 'pflichtangabe' => 'Langkorn']))->toBe('reis|||m-langkorn')
        ->and($this->n->gpKeyAusFeldern('reis', ['hauptzutat' => 'Reis', 'commodity_group_code' => '07', 'processing' => 'gekocht', 'pflichtangabe' => 'Langkorn']))->toBe('reis|gekocht|');
});

it('829 R6: TK und frisch bleiben bei Getränken im Namen und trennen am Schlüssel, konserviert nicht', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Eiswürfel', 'condition' => 'TK', 'commodity_group_code' => '15']))->toBe('Eiswürfel: TK')
        ->and($this->n->renderGpName(['hauptzutat' => 'Grapefruitsaft', 'condition' => 'frisch', 'pflichtangabe' => 'PET-Flasche 1 l', 'commodity_group_code' => '15']))
        ->toBe('Grapefruitsaft: frisch, PET-Flasche 1 l')
        ->and($this->n->renderGpName(['hauptzutat' => 'Orangensaft', 'condition' => 'konserviert', 'commodity_group_code' => '15']))->toBe('Orangensaft')
        ->and($this->n->renderGpName(['hauptzutat' => 'Kaffee', 'condition' => 'trocken', 'commodity_group_code' => '15']))->toBe('Kaffee: trocken');

    $p = ['hauptzutat' => 'Grapefruitsaft', 'pflichtangabe' => 'PET-Flasche 1 l', 'commodity_group_code' => '15'];
    expect($this->n->gpKeyAusFeldern('grapefruitsaft', $p + ['condition' => 'frisch']))
        ->not->toBe($this->n->gpKeyAusFeldern('grapefruitsaft', $p + ['condition' => 'konserviert']));
});

it('71a: Beutel und Kanister sind Gebinde, Mehrweg/Einweg ist kein Material', function () {
    expect(GpNamingService::gebindeArt('Capri-Sun Orange Btl 0,2l'))->toBe('Beutel')
        ->and(GpNamingService::gebindeArt('Grapefruitsaft frisch Kanister 2 l'))->toBe('Kanister')
        ->and(GpNamingService::gebindeArt('Gerolsteiner Sprudel MW 0,75l'))->toBeNull()
        ->and(GpNamingService::gebindeArt('Bitburger Pils EW 0,33l'))->toBeNull()
        ->and(GpNamingService::gebindeArt('König Ludwig Weißbier 20x0,5l Glas'))->toBe('Glasflasche');
});

it('Portion „300 g pro Stück“ als Eingabe ergibt nur einmal „pro Stück“ (GP-Lauf 14.11.)', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Regenbogenforelle', 'form' => 'ganz', 'condition' => 'frisch', 'portion' => '300 g pro Stück', 'commodity_group_code' => '05']))
        ->toBe('Regenbogenforelle: ganz, frisch, 300 g pro Stück')
        ->and($this->n->normalisierePortion('25 g pro Stueck'))->toBe($this->n->normalisierePortion('25 g'));
});

it('821 Teil 3: Doppelzustand „gepökelt, geräuchert“ rendert und ist im Lauf erlaubt; andere Doppelzustände nicht', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Putenbrust', 'form' => 'Scheiben', 'condition' => 'gepökelt, geräuchert', 'commodity_group_code' => '04']))
        ->toBe('Putenbrust: Scheiben, gepökelt, geräuchert')
        ->and(\Platform\FoodAlchemist\Support\GpLaufFelder::pruefe(['condition' => 'gepökelt, geräuchert'], '04', null))->toBe([])
        ->and(\Platform\FoodAlchemist\Support\GpLaufFelder::pruefe(['condition' => 'luftgetrocknet, frisch'], '04', null))->not->toBe([]);
});

it('Portionsbeutel bei Gerichten: „250 g pro Portion“ rendert als „pro Portion“, nicht „pro Stück“ (Entscheid 14.11.)', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Coq au Vin', 'condition' => 'TK', 'portion' => '250 g pro Portion', 'zusatz' => 'Block Menü', 'commodity_group_code' => '13']))
        ->toBe('Coq au Vin: TK, 250 g pro Portion / (Block Menü)')
        ->and($this->n->renderGpName(['hauptzutat' => 'Berliner', 'condition' => 'TK', 'portion' => '70 g', 'commodity_group_code' => '09']))->toBe('Berliner: TK, 70 g pro Stück');
});
