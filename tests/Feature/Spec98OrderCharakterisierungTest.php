<?php

use Carbon\Carbon;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Services\OrderMailService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Tests\Support\BestellWelt;
use Platform\FoodAlchemist\Tests\Support\Golden;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, BestellWelt::class);

/**
 * 98 · 3.3 (S5): Charakterisierung von OrderService VOR dem Zerlegen. Hält das HEUTIGE Verhalten fest, auch wo es
 * merkwürdig ist — eine Änderung hier ist eine Verhaltensänderung und gehört begründet in den PR. Schwerpunkt auf den dünn
 * getesteten Bereichen aus Spec 85 §6: Reklamation/Kontingent, Herkunft, Mail/Dokument; dazu Bedarf und Pflege im Entwurf.
 * Vollständiger Zustand je Szenario in tests/Fixtures/golden/order_charakter.json (Golden), Kernzahlen zusätzlich explizit.
 * Die Laufzeit-Schleife Wareneingang → Delivered deckt #458 ab (WareneingangKurzweg/OrderService-Tests), hier nicht doppelt.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 09:00'));   // Montag; Liefertage und Fristen stabil
    $this->bestellWeltAnlegen();
    $this->actingAs($this->makeUser($this->rootTeam, 'Einkauf'));
    $this->svc = app(OrderService::class);
    $this->golden = function (string $schluessel, mixed $ist) {
        $g = Golden::vergleich('order_charakter', $schluessel, $ist);
        expect($g['ist'])->toBe($g['soll'], "Golden {$schluessel}");
    };
    $this->zeile = fn (string $lieferant, $gp) => FoodAlchemistOrderLine::where('gp_id', $gp->id)
        ->whereIn('order_id', FoodAlchemistOrder::where('supplier_id', ($lieferant === 'Chefs' ? $this->chefs : $this->hanos)->id)->select('id'))->first();
    $this->versendet = function (FoodAlchemistOrder $o) {
        $this->svc->setStatus($this->rootTeam, $o->id, OrderStatus::Sent);

        return $o->refresh();
    };
});

afterEach(fn () => Carbon::setTestNow());

// ── Bedarf ─────────────────────────────────────────────────────────────────

it('Bedarf: zwei Quellen addieren sich je Zeile, gleiche Quelle erneut ersetzt statt zu verdoppeln', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett A');
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 50], 'event:Bankett B');
    expect((float) ($this->zeile)('Chefs', $this->mehl)->qty_packs)->toBe(15.0);
    ($this->golden)('bedarf_zwei_quellen', $this->bestellZustand());

    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 20], 'event:Bankett A');   // Re-Import
    expect((float) ($this->zeile)('Chefs', $this->mehl)->qty_packs)->toBe(7.0);
    ($this->golden)('bedarf_reimport_ersetzt', $this->bestellZustand());
});

it('Bedarf: Vorschau und Erzeugen aus Quellen mit Runde und Liefertag', function () {
    $quellen = [['type' => 'recipe', 'id' => $this->kuchen->id, 'qty' => 100, 'unit' => 'portions', 'delivery_date' => '2026-11-26', 'reference' => 'Weihnachtsfeier']];
    $vorschau = $this->svc->previewFromSources($this->rootTeam, $quellen);
    expect(FoodAlchemistOrder::count())->toBe(0)->and($vorschau['totals']['total_net'])->toBe(33.0);
    ($this->golden)('bedarf_vorschau', ['totals' => $vorschau['totals'], 'gruppen' => collect($vorschau['orders_preview'])
        ->map(fn ($g) => ['supplier' => $g['supplier'], 'delivery_date' => $g['delivery_date'], 'total_net' => $g['total_net']])->sortBy('supplier')->values()->all()]);

    $this->svc->generateDraftsFromSources($this->rootTeam, $quellen, null, auth()->id(), [], ['name' => 'Runde KW 48']);
    ($this->golden)('bedarf_erzeugt', $this->bestellZustand());
});

// ── Pflege im Entwurf ──────────────────────────────────────────────────────

it('Pflege: manuelle Menge, Re-Import, zurück auf Auto, Zeile entfernen, leere Entwürfe löschen', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $mehl = ($this->zeile)('Chefs', $this->mehl);

    $this->svc->updateLine($this->rootTeam, $mehl->id, ['qty_packs' => 12, 'note' => 'Reserve']);
    expect((float) $mehl->fresh()->line_total)->toBe(24.0)->and($mehl->fresh()->is_manual_qty)->toBeTrue();
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 50], 'event:Bankett');
    ($this->golden)('pflege_manuell_nach_reimport', $this->bestellZustand());

    $this->svc->updateLine($this->rootTeam, $mehl->id, ['reset_qty' => true]);
    ($this->golden)('pflege_zurueck_auf_auto', $this->bestellZustand());

    $butter = ($this->zeile)('Hanos', $this->butter);
    $this->svc->removeLine($this->rootTeam, $butter->id);
    $geloescht = $this->svc->deleteEmptyDrafts($this->rootTeam);
    ($this->golden)('pflege_entfernt_und_aufgeraeumt', ['geloescht' => $geloescht, 'zustand' => $this->bestellZustand()]);
});

it('Pflege: Direktbestellung, Kopfdaten, Artikelwechsel zum anderen Lieferanten, Neuquellen nach Preisstrategie', function () {
    $zeile = $this->svc->addManualLine($this->rootTeam, $this->laOf['Mehl']->id, 3, 'Direkt', auth()->id(), '2026-11-26');
    $this->svc->updateHeader($this->rootTeam, $zeile->order_id, ['reference' => 'Vorrat', 'note' => 'Hintereingang']);
    ($this->golden)('pflege_direktbestellung', $this->bestellZustand());

    $wechsel = $this->svc->switchLineArticle($this->rootTeam, $zeile->id, $this->ausweichMehl()->id, auth()->id());
    ($this->golden)('pflege_artikelwechsel', ['ergebnis_schluessel' => array_keys((array) $wechsel), 'zustand' => $this->bestellZustand()]);

    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->whereNull('desired_delivery_date')->first();
    $vorschau = $this->svc->resourceOrder($this->rootTeam, $chefs->id, \Platform\FoodAlchemist\Enums\LeadLaStrategie::GuenstigsterPreis, false);
    ($this->golden)('pflege_neuquellen_vorschau', $vorschau);
});

it('Pflege: Preisänderung zieht im Entwurf nach (E11), nach dem Versand nicht mehr', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    FoodAlchemistPrice::where('supplier_item_id', $this->laOf['Mehl']->id)->update(['price' => 2.5]);

    $this->svc->recomputeOrder($chefs->fresh());
    $entwurf = (float) $chefs->fresh()->total_net;

    ($this->versendet)($chefs);
    FoodAlchemistPrice::where('supplier_item_id', $this->laOf['Mehl']->id)->update(['price' => 3.0]);
    $this->svc->recomputeOrder($chefs->fresh());

    ($this->golden)('pflege_preis_entwurf_vs_versendet', ['entwurf' => $entwurf, 'versendet' => (float) $chefs->fresh()->total_net,
        'zustand' => $this->bestellZustand()]);
});

it('Status: erlaubte und verbotene Wechsel mit Meldung', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $o = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
    $wege = [];
    foreach ([OrderStatus::Delivered, OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::Cancelled, OrderStatus::Sent] as $ziel) {
        try {
            $wege[] = [$ziel->value, $this->svc->setStatus($this->rootTeam, $o->id, $ziel)->status->value];
        } catch (\RuntimeException $e) {
            $wege[] = [$ziel->value, 'abgelehnt: '.$e->getMessage()];
        }
    }
    ($this->golden)('status_wechsel', $wege);
});

// ── Reklamation / Kontingent (dünn, Spec 85 §6) ────────────────────────────

it('Reklamation: erst nach Versand, Status-Automatik, Werte und Zusammenfassung', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $mehl = ($this->zeile)('Chefs', $this->mehl);

    expect(fn () => $this->svc->updateClaimLine($this->rootTeam, $mehl->id, ['claim_qty_packs' => 2]))
        ->toThrow(RuntimeException::class, 'Rechnungsprüfung ist erst nach dem Absenden möglich.');

    $order = ($this->versendet)($mehl->order);
    $this->svc->updateClaimLine($this->rootTeam, $mehl->id, ['claim_qty_packs' => 2, 'credit_expected_net' => 4, 'claim_note' => 'Sack gerissen']);
    expect($mehl->fresh()->claim_status)->toBe('open');   // Automatik: Werte ohne Status → open
    expect(fn () => $this->svc->updateClaimLine($this->rootTeam, $mehl->id, ['claim_status' => 'verloren']))
        ->toThrow(RuntimeException::class, 'Unbekannter Reklamationsstatus');
    $this->svc->updateClaimLine($this->rootTeam, $mehl->id, ['claim_status' => 'credit_expected', 'claim_qty_packs' => -3]);
    ($this->golden)('reklamation_offen', ['zeile' => $this->bestellZustand(), 'summe' => $this->svc->claimSummary($order->fresh(['lines']))]);

    $this->svc->updateClaimLine($this->rootTeam, $mehl->id, ['claim_status' => 'credited']);
    $this->svc->updateClaimLine($this->rootTeam, $mehl->id, ['claim_status' => '', 'claim_qty_packs' => '', 'credit_expected_net' => '', 'claim_note' => '']);
    ($this->golden)('reklamation_geloescht', ['zeile' => ($this->zeile)('Chefs', $this->mehl)->only(['claim_status', 'claim_qty_packs', 'credit_expected_net', 'claim_note']),
        'summe' => $this->svc->claimSummary($order->fresh(['lines']))]);
});

it('Kontingent: pflegen am Lieferantenartikel, Verbrauch über Wareneingang idempotent, Zusammenfassung', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $mehl = ($this->zeile)('Chefs', $this->mehl);
    $this->svc->updateLineQuota($this->rootTeam, $mehl->id, ['quota_qty_packs' => 50, 'quota_used_packs' => 5,
        'quota_valid_from' => '2026-11-01', 'quota_valid_to' => '2026-12-31', 'quota_note' => 'Rahmenvertrag 2026']);
    $order = ($this->versendet)($mehl->order);

    $this->svc->updateReceiptLine($this->rootTeam, $mehl->id, 8);
    $this->svc->updateReceiptLine($this->rootTeam, $mehl->id, 10);   // Korrektur: nur Differenz
    $this->svc->updateReceiptLine($this->rootTeam, $mehl->id, 10);   // gleich: nichts
    $la = $this->laOf['Mehl']->fresh();
    ($this->golden)('kontingent', ['la' => $la->only(['quota_qty_packs', 'quota_used_packs', 'quota_valid_from', 'quota_valid_to', 'quota_note']),
        'zeile' => $mehl->fresh()->only(['quota_consumed_packs']), 'summe' => $this->svc->quotaSummary($order->fresh(['lines']))]);

    expect(fn () => $this->svc->updateLineQuota($this->rootTeam, ($this->zeile)('Hanos', $this->butter)->id + 999, []))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

// ── Herkunft (dünn) ───────────────────────────────────────────────────────

it('Herkunft: Parser, Aggregat je Quelle, Produktionsnamen und Anzeige im Detail', function () {
    $prod = FoodAlchemistProductionOrder::create(['team_id' => $this->rootTeam->id, 'name' => 'Hochzeit Meier', 'production_date' => '2026-11-28', 'status' => 'planned']);
    $refs = ["produktion:{$prod->id}:kuchen", "produktion:{$prod->id}:sauce", 'concept:menue-a@100', 'recipe:kuchen@50', 'event:Bankett', 'frei', ''];

    $aggregat = $this->svc->herkunftAggregat($refs);
    // Produktions-Id stabil machen (nur an ihren Stellen, nicht jede Ziffer)
    $p = fn ($v) => json_decode(preg_replace(['/(produktion:|#)'.$prod->id.'(?=\\D)/', '/"production_order_id":'.$prod->id.'(?=\\D)/'],
        ['${1}P', '"production_order_id":"P"'], json_encode($v, JSON_UNESCAPED_UNICODE)), true);
    ($this->golden)('herkunft_parser', $p(['parse' => $this->svc->parseHerkunft($refs), 'aggregat' => $aggregat,
        'mit_namen' => $this->svc->herkunftMitProduktionsnamen($this->rootTeam, $aggregat)]));

    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], "produktion:{$prod->id}:kuchen");
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 10], 'event:Bankett');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $detail = $this->svc->detail($this->rootTeam, $chefs->id);
    ($this->golden)('herkunft_im_detail', $p(['herkunft' => $detail['herkunft'], 'zeilen_herkunft' => collect($detail['zeilen'])
        ->map(fn ($l) => $l['herkunft'] ?? null)->all()]));
});

// ── Mail / Dokument (dünn) ──────────────────────────────────────────────────

it('Dokument und Mail-Inhalte (Bestellung, Storno) mit Standardtexten; Versand-Vorprüfung', function () {
    $this->hanos->update(['email_order' => 'bestellung@hanos.test']);
    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'event:Bankett');
    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mail = app(OrderMailService::class);
    $ohneNummern = fn (mixed $v) => json_decode(preg_replace('/ord-\d+|#\d+/', 'ord-N', json_encode($v, JSON_UNESCAPED_UNICODE)), true);

    $dok = $this->svc->dokument($this->rootTeam, $hanos->id);
    ($this->golden)('dokument_hanos', $ohneNummern(collect($dok)->except(['erstellt_at', 'generated_at'])->all()));
    ($this->golden)('mail_bestellung', $ohneNummern($mail->inhalt($this->rootTeam, $hanos->id, 'bestellung', 'Einkauf')));
    ($this->golden)('mail_storno', $ohneNummern($mail->inhalt($this->rootTeam, $hanos->id, 'storno', 'Einkauf')));
    ($this->golden)('mailto_bestellung', $ohneNummern($this->svc->mailtoData($this->rootTeam, $hanos->id)));
    ($this->golden)('mailto_storno', $ohneNummern($this->svc->cancellationMailtoData($this->rootTeam, $hanos->id)));

    // Server-Versand: ohne Bestell-E-Mail beim Lieferanten wird der Wechsel verweigert, mit Mail geht er durch
    app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->update($this->rootTeam, ['bestellversand' => 'server']);
    $ergebnis = [];
    foreach ([$chefs, $hanos] as $o) {
        try {
            $mail->pruefeVersandbar($this->rootTeam, $o->fresh('supplier'));
            $ergebnis[] = 'ok';
        } catch (\RuntimeException $e) {
            $ergebnis[] = $e->getMessage();
        }
    }
    ($this->golden)('mail_versandpruefung_server', $ergebnis);
});
