<?php

use Carbon\Carbon;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Services\Bestellung\OrderService;
use Platform\FoodAlchemist\Tests\Support\BestellWelt;
use Platform\FoodAlchemist\Tests\Support\Golden;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, BestellWelt::class);

/**
 * 98 · 3.3 (OrderService unter 1.500, PR0): Charakterisierung der Pfade, die beim Schnitt in Teilklassen wandern und in
 * Spec98OrderCharakterisierungTest nur dünn abgedeckt sind: Vorschau mit Overrides (Lead-Wechsel, Auslassen, Menge von Hand),
 * Erzeugen über den Override-Pfad, Ausweichquellen (Zeile und GP), Neu quellen mit Anwenden, Sammelversand und -storno.
 * Hält das HEUTIGE Verhalten fest; Ids, uuid, Quell- und Positionsschlüssel sind ausgeblendet (sie hängen an Datenbank-Ids).
 * Vollständiger Zustand in tests/Fixtures/golden/order_schnitt.json.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 09:00'));   // Montag; Liefertage und Fristen stabil
    $this->bestellWeltAnlegen();
    $this->actingAs($this->makeUser($this->rootTeam, 'Einkauf'));
    $this->svc = app(OrderService::class);
    $this->golden = function (string $schluessel, mixed $ist) {
        $g = Golden::vergleich('order_schnitt', $schluessel, orderSchnittOhneIds($ist));
        expect($g['ist'])->toBe($g['soll'], "Golden {$schluessel}");
    };
    $this->quellen = [['type' => 'recipe', 'id' => $this->kuchen->id, 'qty' => 100, 'unit' => 'portions', 'delivery_date' => '2026-11-26', 'reference' => 'Weihnachtsfeier']];
    // Overrides je Position: Mehl auf das Ausweich-Mehl (Hanos), Butter auslassen, Zucker von Hand 3 Gebinde
    $this->overrides = function (): array {
        $roh = $this->svc->previewFromSources($this->rootTeam, $this->quellen);
        $pos = collect($roh['orders_preview'])->flatMap(fn ($g) => $g['positionen'])->keyBy('gp');

        return [
            $pos['Mehl']['override_key'] => $this->ausweichMehl()->id,
            'skip' => [$pos['Butter']['position_key'] => true],
            'menge' => [$pos['Zucker']['position_key'] => 3],
        ];
    };
});

afterEach(fn () => Carbon::setTestNow());

/** Ids und id-tragende Schlüssel rekursiv ausblenden, damit der Golden-Stand nicht an Autoincrement hängt. */
function orderSchnittOhneIds(mixed $wert): mixed
{
    if (! is_array($wert)) {
        return $wert;
    }
    $aus = [];
    foreach ($wert as $k => $v) {
        if (is_string($k) && ($k === 'id' || str_ends_with($k, '_id') || str_ends_with($k, '_ids') || in_array($k, ['uuid', 'override_key', 'position_key', 'source_ref', 'source_refs', 'overrides', 'orders'], true))) {
            continue;
        }
        $aus[$k] = orderSchnittOhneIds($v);
    }

    return $aus;
}

it('Vorschau mit Overrides: Lead-Wechsel zum anderen Lieferanten, Auslassen, Menge von Hand', function () {
    $vorschau = $this->svc->previewFromSources($this->rootTeam, $this->quellen, null, ($this->overrides)());

    expect(FoodAlchemistOrder::count())->toBe(0)
        ->and(collect($vorschau['ausgelassen'])->pluck('gp')->all())->toBe(['Butter']);
    ($this->golden)('vorschau_overrides', $vorschau);
});

it('Erzeugen über den Override-Pfad schreibt die Vorschau als Entwürfe (Menge von Hand bleibt fest)', function () {
    $erg = $this->svc->generateDraftsFromSources($this->rootTeam, $this->quellen, null, auth()->id(), ($this->overrides)(), ['label' => 'Runde KW 48']);

    $zucker = FoodAlchemistOrderLine::where('gp_id', $this->zucker->id)->first();
    expect($zucker->is_manual_qty)->toBeTrue()->and((float) $zucker->qty_packs)->toBe(3.0);
    ($this->golden)('erzeugt_overrides', ['ergebnis' => array_keys($erg), 'runde' => $erg['round'], 'zustand' => $this->bestellZustand()]);
});

it('Ausweichquellen: je Zeile (ohne den gewählten Artikel) und je GP (mit Schienen-Wechsel-Hinweis)', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');   // Mehl bei Chefs
    $this->ausweichMehl();
    $mehl = FoodAlchemistOrderLine::where('gp_id', $this->mehl->id)->first();

    ($this->golden)('alternativen', [
        'zeile' => $this->svc->lineAlternativen($this->rootTeam, $mehl->id),
        'gp_ohne_aktuell' => $this->svc->gpAlternativen($this->rootTeam, $this->mehl->id, $this->chefs->id, $this->laOf['Mehl']->id),
        'gp_alle' => $this->svc->gpAlternativen($this->rootTeam, $this->mehl->id),
        'gp_unbekannt' => $this->svc->gpAlternativen($this->rootTeam, 999999),
    ]);
});

it('Neu quellen anwenden: Beiträge wandern zur günstigeren Schiene, Strategie steht an der Quell-Schiene', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');   // Mehl bei Chefs
    $this->ausweichMehl();
    $chefs = FoodAlchemistOrder::where('supplier_id', $this->chefs->id)->first();

    $erg = $this->svc->resourceOrder($this->rootTeam, $chefs->id, LeadLaStrategie::GuenstigsterPreis, true, auth()->id());

    expect($chefs->fresh()->sourcing_strategy)->toBe(LeadLaStrategie::GuenstigsterPreis->value);
    ($this->golden)('neu_quellen_angewendet', ['ergebnis' => $erg, 'zustand' => $this->bestellZustand()]);

    $hanos = $this->svc->setStatus($this->rootTeam, FoodAlchemistOrder::where('supplier_id', $this->hanos->id)->value('id'), OrderStatus::Sent);
    expect(fn () => $this->svc->resourceOrder($this->rootTeam, $hanos->id))
        ->toThrow(\RuntimeException::class, 'Bezugsquellen lassen sich nur in einem offenen Entwurf neu ermitteln.');
});

it('Sammelversand und Sammelstorno: versandfähige Entwürfe gehen raus, gesperrte bleiben, Storno nur für Entwürfe', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $leer = $this->svc->createDraft($this->rootTeam, $this->hanos->id, ['desired_delivery_date' => '2026-11-30']);
    $ids = FoodAlchemistOrder::where('team_id', $this->rootTeam->id)->pluck('id')->all();

    $versand = $this->svc->sendSelectedDrafts($this->rootTeam, $ids);
    expect($versand['sent'] + $versand['blocked'])->toBe(count($ids));
    $storno = $this->svc->cancelSelectedDrafts($this->rootTeam, [...$ids, $leer->id, 0]);
    $nochmal = $this->svc->sendReadyDrafts($this->rootTeam);

    ($this->golden)('sammelversand', ['versand' => $versand, 'storno' => $storno, 'nochmal' => $nochmal, 'zustand' => $this->bestellZustand()]);
});
