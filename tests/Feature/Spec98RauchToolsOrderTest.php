<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Livewire\Orders\Editor as OrdersEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderRound;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Tests\Support\BestellWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools;

uses(TestCase::class, SeedsTeamHierarchy::class, BestellWelt::class);

/**
 * 98 · 3.3 (S5): Rauchtest vor dem Zerlegen von OrderService. Jedes MCP-Tool, das OrderService nutzt, läuft einmal mit gültigen
 * Eingaben und liefert success — so fällt ein beim Schnitt verlorener Fassaden-Delegator sofort auf. Dazu die Wege des
 * Hauptkunden Orders/Editor (≈ 47 Service-Aufrufe) einmal durch: Cockpit, Pflege, Versand, Eingang, Rechnung, Reklamation, Zahlung.
 * Kein Fachtest: Inhalte prüfen OrderServiceTest und Spec98OrderCharakterisierungTest.
 */
beforeEach(function () {
    $this->bestellWeltAnlegen();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->svc = app(OrderService::class);
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $this->chefsOrder = FoodAlchemistOrder::where('supplier_id', $this->chefs->id)->first();
    $this->hanosOrder = FoodAlchemistOrder::where('supplier_id', $this->hanos->id)->first();
    $this->mehlZeile = FoodAlchemistOrderLine::where('order_id', $this->chefsOrder->id)->where('gp_id', $this->mehl->id)->first();
    $this->rauch = function (string $tool, array $args) {
        $r = app($tool)->execute($args, $this->ctx);
        expect($r->success)->toBeTrue(class_basename($tool).': '.($r->error ?? json_encode($r->errorCode)));

        return $r;
    };
});

it('Order-Tools im Entwurf: lesen, anlegen, Zeilen pflegen, neu quellen, Bedarf', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\OrdersGetTool::class, []);
    $r(Tools\OrdersGetTool::class, ['order_id' => $this->chefsOrder->id]);
    $r(Tools\OrdersCreateTool::class, ['supplier_id' => $this->hanos->id, 'desired_delivery_date' => '2026-12-01', 'reference' => 'Rauch']);
    $neu = $r(Tools\OrdersAddLineTool::class, ['supplier_item_id' => $this->laOf['Zucker']->id, 'qty_packs' => 2]);
    $r(Tools\OrdersUpdateTool::class, ['order_id' => $this->chefsOrder->id, 'reference' => 'Rauch', 'note' => 'Hof']);
    $r(Tools\OrdersUpdateLineTool::class, ['line_id' => $this->mehlZeile->id, 'qty_packs' => 11]);
    $r(Tools\OrdersResourceTool::class, ['order_id' => $this->chefsOrder->id, 'preview' => true]);
    $r(Tools\OrdersSwitchArticleTool::class, ['line_id' => $this->mehlZeile->id, 'new_la_id' => $this->ausweichMehl()->id]);
    $zucker = FoodAlchemistOrderLine::where('gp_id', $this->zucker->id)->latest('id')->first();
    $r(Tools\OrdersRemoveLineTool::class, ['line_id' => $zucker->id, 'confirm' => true]);
    $r(Tools\OrdersAddNeedTool::class, ['recipe_id' => $this->kuchen->id, 'portions' => 20, 'source_ref' => 'event:Rauch']);
    expect($neu->data)->not->toBeEmpty();
});

it('Order-Tools nach dem Versand: Status, Bestätigung, Eingang, Rechnung, Reklamation, Zahlung, Freigabe', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $id = $this->hanosOrder->id;
    $butter = FoodAlchemistOrderLine::where('order_id', $id)->first();
    $r(Tools\OrdersUpdateApprovalTool::class, ['order_id' => $id, 'approval_status' => 'approved']);
    $r(Tools\OrdersSetStatusTool::class, ['order_id' => $id, 'status' => 'sent']);
    $r(Tools\OrdersConfirmSupplierTool::class, ['order_id' => $id, 'supplier_order_number' => 'AB-1', 'confirmed_delivery_date' => '2026-12-02']);
    $r(Tools\OrdersReceiptTool::class, ['line_id' => $butter->id, 'received_qty_packs' => 1]);
    $r(Tools\OrdersUpdateInvoiceTool::class, ['order_id' => $id, 'invoice_number' => 'R-1', 'invoice_date' => '2026-12-03']);
    $r(Tools\OrdersClaimTool::class, ['line_id' => $butter->id, 'claim_qty_packs' => 1, 'claim_note' => 'Rauch']);
    $r(Tools\OrdersUpdatePaymentTool::class, ['order_id' => $id, 'payment_status' => 'paid', 'invoice_paid_at' => '2026-12-10']);
});

it('Order-Tools Versand und Runde: Dispatch der Entwürfe, Runde löschen', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\OrdersDispatchTool::class, ['order_ids' => [$this->chefsOrder->id], 'confirm' => true]);

    $res = $this->svc->generateDraftsFromSources($this->rootTeam, [['type' => 'recipe', 'id' => $this->kuchen->id, 'qty' => 10, 'unit' => 'portions',
        'delivery_date' => '2026-12-04']], null, $this->user->id, [], ['name' => 'Rauch-Runde']);
    $runde = FoodAlchemistOrderRound::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($runde)->not->toBeNull('Runde angelegt: '.json_encode(array_keys($res)));
    $r(Tools\OrderRoundsDeleteTool::class, ['round_id' => $runde->id]);
});

it('Alle Tools, die OrderService nutzen, sind hier abgedeckt', function () {
    $abgedeckt = collect(file(__FILE__))->flatMap(fn ($z) => preg_match_all('/Tools\\\\(\w+Tool)::class/', $z, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    $nutzen = collect(glob(dirname(__DIR__, 2).'/src/Tools/*Tool.php'))
        ->filter(fn ($f) => preg_match('/\bOrderService\b/', (string) file_get_contents($f)))
        ->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();

    expect(array_values(array_diff($nutzen, $abgedeckt)))->toBe([]);
});

it('Orders/Editor: Wege des Hauptkunden laufen ohne Fehler (Bearbeiten, Pflege, Versand, Eingang, Rechnung, Reklamation, Zahlung)', function () {
    $hanosZeile = FoodAlchemistOrderLine::where('order_id', $this->hanosOrder->id)->first();
    $ed = Livewire::test(OrdersEditor::class)->call('oeffnenBearbeiten', $this->chefsOrder->id)->assertSet('fehler', null);
    foreach ([
        ['updateLineQty', $this->mehlZeile->id, 12], ['resetLineQty', $this->mehlZeile->id], ['updateLineNote', $this->mehlZeile->id, 'bio'],
        ['alternativenUmschalten', $this->mehlZeile->id], ['neuQuellenVorschau'], ['neuQuellenAbbrechen'], ['saveHeader'],
    ] as $schritt) {
        $ed->call(...$schritt)->assertSet('fehler', null);
    }

    $ed = Livewire::test(OrdersEditor::class)->call('oeffnenBearbeiten', $this->hanosOrder->id);
    foreach ([
        ['freigabeAnfragen'], ['setStatus', OrderStatus::Sent->value], ['saveSupplierConfirmation'],
        ['updateReceiptLine', $hanosZeile->id, 1, 'ok'], ['updateReceiptNote', $hanosZeile->id, 'Rampe 2'],
        ['updateInvoiceLine', $hanosZeile->id, 1, 12, null], ['updateClaimLine', $hanosZeile->id, 'open', 1, 5, 'Delle'],
        ['updateClaimStatus', $hanosZeile->id, 'credited'], ['completeReceipt'], ['completeInvoiceFromReceipt'],
    ] as $schritt) {
        $ed->call(...$schritt)->assertSet('fehler', null);
    }
    // Rechnungskopf und Zahlung wie im Formular: erst Felder, dann speichern (Zahlung braucht einen Rechnungskopf)
    $ed->set('formInvoiceNumber', 'R-1')->set('formInvoiceDate', '2026-12-03')->call('saveInvoiceHeader')->assertSet('fehler', null)
        ->set('formPaymentStatus', 'paid')->set('formInvoicePaidAt', '2026-12-10')->call('savePayment')->assertSet('fehler', null);

    $neu = Livewire::test(OrdersEditor::class)->call('oeffnenNeu', '2026-12-05')
        ->call('cockpitRezeptEinfuegen', $this->kuchen->id)->call('cockpitVorschau')->assertSet('fehler', null)
        ->call('cockpitSpeichern')->assertSet('fehler', null);
    expect(FoodAlchemistOrder::whereDate('desired_delivery_date', '2026-12-05')->count())->toBeGreaterThan(0);
});
