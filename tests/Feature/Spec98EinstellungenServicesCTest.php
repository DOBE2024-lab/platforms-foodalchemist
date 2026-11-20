<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\Einstellungen\KuechenRollenService;
use Platform\FoodAlchemist\Services\Einstellungen\LagerorteService;
use Platform\FoodAlchemist\Services\ProductionStationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3a (C) — Einstellungen Einkauf (Lagerorte), Posten und Rollen schreiben über Services.
 * Je Service: Happy Path, Besitzer-Guard (Kind-Team schreibt keine Zeile des Root-Teams), eine Eingabe-Meldung.
 * setzeSammelposten ist in Spec98HeadlessSchreibwegeTest abgedeckt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('LagerorteService: erster Lagerort wird Standard, speichern/standardSetzen/entfernen, Kind-Team kommt nicht ran', function () {
    $svc = app(LagerorteService::class);
    $a = $svc->anlegen($this->rootTeam, ['name' => ' Kühlhaus ', 'type' => 'cooling', 'code' => '']);
    $b = $svc->anlegen($this->rootTeam, ['name' => 'Alpha', 'type' => 'quatsch']);
    expect($a->name)->toBe('Kühlhaus')->and((bool) $a->is_default)->toBeTrue()->and($a->code)->toBeNull()
        ->and((bool) $b->is_default)->toBeFalse()->and($b->type)->toBe('warehouse');

    $svc->speichern($this->rootTeam, $b->id, ['name' => 'Beta', 'type' => 'dry', 'is_active' => true, 'note' => 'n']);
    expect($b->fresh()->name)->toBe('Beta')->and($b->fresh()->type)->toBe('dry');

    $svc->standardSetzen($this->rootTeam, $b->id);
    expect((bool) $b->fresh()->is_default)->toBeTrue()->and((bool) $a->fresh()->is_default)->toBeFalse();

    // Standard mit Bestand entfernen → nur deaktiviert, der erste aktive Lagerort rückt als Standard nach
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    FoodAlchemistInventoryStock::create(['team_id' => $this->rootTeam->id, 'inventory_location_id' => $b->id, 'gp_id' => $gp->id, 'qty_base' => 5, 'base_unit' => 'g']);
    expect($svc->entfernen($this->rootTeam, $b->id))->toBe(['deaktiviert' => true, 'neuerStandardId' => $a->id]);
    expect((bool) $b->fresh()->is_active)->toBeFalse()->and((bool) $a->fresh()->is_default)->toBeTrue();

    expect(fn () => $svc->anlegen($this->rootTeam, ['name' => '  ']))
        ->toThrow(InvalidArgumentException::class, 'Bitte einen Lagernamen eingeben.');
    expect(fn () => $svc->speichern($this->childA, $a->id, ['name' => 'Fremd']))->toThrow(ModelNotFoundException::class);
    expect(fn () => $svc->entfernen($this->childA, $a->id))->toThrow(ModelNotFoundException::class);
    expect(FoodAlchemistInventoryLocation::find($a->id)->name)->toBe('Kühlhaus');
});

it('ProductionStationService: anlegen, Felder, Wochentag, Besetzung, stilllegen; geerbter Posten bleibt Vorlage', function () {
    $svc = app(ProductionStationService::class);
    $p = $svc->anlegen($this->rootTeam, ['name' => 'Garde Manger', 'group_name' => '', 'kapazitaet' => '480']);
    expect($p->slug)->toBe('garde_manger')->and($p->kapazitaet_min_pro_tag)->toBe(480)->and($p->group_name)->toBeNull();

    $svc->feldSetzen($this->rootTeam, $p->id, 'batch_max_kg', '12,5');
    $svc->wochentagSetzen($this->rootTeam, $p->id, 6, '120');
    $svc->besetzungSetzen($this->rootTeam, $p->id, 7, '2');
    $p->refresh();
    expect((float) $p->batch_max_kg)->toBe(12.5)
        ->and($p->kapazitaet_wochentag)->toBe(['6' => 120])
        ->and($p->besetzung)->toBe(['7' => 2]);
    expect($svc->aktivUmschalten($this->rootTeam, $p->id)->is_inactive)->toBeTrue();

    expect(fn () => $svc->anlegen($this->rootTeam, ['name' => 'Garde manger']))->toThrow(InvalidArgumentException::class, '«Garde manger» gibt es schon.');
    expect(fn () => $svc->feldSetzen($this->rootTeam, $p->id, 'name', ' '))->toThrow(InvalidArgumentException::class, 'Name darf nicht leer sein.');
    expect(fn () => $svc->feldSetzen($this->childA, $p->id, 'name', 'Fremd'))->toThrow(GeerbtNurLesenException::class, 'Posten nicht im Schreibzugriff (D1).');
    expect(FoodAlchemistProductionStation::find($p->id)->name)->toBe('Garde Manger');
});

it('KuechenRollenService: anlegen, Satz mit Komma, stilllegen; geerbte Rolle bleibt Vorlage', function () {
    $svc = app(KuechenRollenService::class);
    $r = $svc->anlegen($this->rootTeam, ['name' => 'Koch', 'satz' => '35']);
    expect($r->slug)->toBe('koch')->and((float) $r->stundensatz_eur)->toBe(35.0);

    $svc->feldSetzen($this->rootTeam, $r->id, 'satz', '18,50');
    expect((float) $r->fresh()->stundensatz_eur)->toBe(18.5);
    expect($svc->aktivUmschalten($this->rootTeam, $r->id)->is_inactive)->toBeTrue();

    expect(fn () => $svc->anlegen($this->rootTeam, ['name' => '']))->toThrow(InvalidArgumentException::class, 'Name ist Pflicht.');
    expect(fn () => $svc->aktivUmschalten($this->childA, $r->id))->toThrow(GeerbtNurLesenException::class, 'Rolle nicht im Schreibzugriff.');
    expect((bool) FoodAlchemistKitchenRole::find($r->id)->is_inactive)->toBeTrue();
});
